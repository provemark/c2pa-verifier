<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

use Provemark\C2paVerifier\Cose\CoseException;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;

/**
 * The trust check as a list of statuses (SPEC-014; C2PA 2.4 §14.4.1,
 * §15.7): the leaf certificate of the COSE x5chain is trusted when its
 * SHA-256 is on the allowed list, or when the chain walks — issuer name
 * matching and openssl_x509_verify() on every link — to a certificate that
 * is an anchor or is signed by one (c2pa-rs's PARTIAL_CHAIN). Everything
 * else is signingCredential.untrusted with the step that failed. Never by
 * name alone: two of the test roots share one subject and differ in key.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class ChainCheck
{
    /** @return list<ValidationStatus> */
    public function check(Manifest $manifest, TrustSettings $settings, ?int $at = null): array
    {
        if (! $settings->verifyTrust) {
            return [];
        }
        $url = sprintf('self#jumbf=/c2pa/%s/c2pa.signature', $manifest->label);

        try {
            $cose = CoseSign1::fromBytes($manifest->signatureBytes());
            $chain = array_map(static fn ($c): Certificate => Certificate::fromDer($c->bytes), $cose->chain);
        } catch (CoseException $e) {
            return [new ValidationStatus($e->status, $url, $e->getMessage())];
        } catch (TrustException $e) {
            return [new ValidationStatus(StatusCode::SigningCredentialInvalid, $url, sprintf('a certificate in x5chain could not be read: %s', $e->getMessage()))];
        }
        if ($chain === []) {
            return [new ValidationStatus(StatusCode::SigningCredentialInvalid, $url, 'x5chain holds no certificate')];
        }

        $statuses = $this->checkCertificates($chain, $settings, $url, $at);
        $note = self::kindNote($settings, TrustAnchorSet::MANIFEST, $chain);
        if ($note === '') {
            return $statuses;
        }

        // an untrusted signer, with entries of another kind configured: say they were not used, and why (SPEC-031 AC6)
        return array_map(static fn (ValidationStatus $s): ValidationStatus => $s->code === StatusCode::SigningCredentialUntrusted
            ? new ValidationStatus($s->code, $s->url, $s->explanation.$note)
            : $s, $statuses);
    }

    /**
     * The allowed list, then the walk, on a chain given as certificates, leaf
     * first — the seam SPEC-017 uses for a TSA's certificates (SPEC-014
     * amendment 2). `verify_trust` is the caller's to honour.
     *
     * Every certificate that issues another in the walk must be allowed to
     * (SPEC-014 amendment 4): an x5chain intermediate and an anchor alike.
     * $at is the time the leaf is judged at (a trusted timestamp's genTime),
     * or null for now.
     *
     * @param  non-empty-list<Certificate>  $chain
     * @return list<ValidationStatus>
     */
    public function checkCertificates(array $chain, TrustSettings $settings, string $url, ?int $at = null): array
    {
        $leaf = $chain[0];

        // the allowed list first: a listed end-entity certificate needs no chain (c2pa-rs: EndEntity)
        $anchors = self::anchorsOf($settings);
        foreach (self::allowedListOf($settings) as $allowed) {
            if (hash_equals($allowed->sha256, $leaf->sha256)) {
                return [new ValidationStatus(StatusCode::SigningCredentialTrusted, $url, sprintf('signing certificate trusted: %s is on the allowed list (sha256 %s)', $leaf->subjectCn(), bin2hex($leaf->sha256)))];
            }
        }
        if ($anchors === []) {
            return [new ValidationStatus(StatusCode::SigningCredentialUntrusted, $url, sprintf('signing certificate untrusted: %s is not on the allowed list and no trust anchors are configured', $leaf->subjectCn()))];
        }

        // the walk, from the leaf, through the chain the signer supplied
        $current = $leaf;
        $anchorFault = null;
        foreach ($chain as $depth => $_) {
            foreach ($anchors as $anchor) {
                // depth = links walked from the leaf to the anchor: the leaf itself an anchor is 0, the leaf signed by one is 1
                if ($current->sameAs($anchor)) {
                    return [new ValidationStatus(StatusCode::SigningCredentialTrusted, $url, sprintf('signing certificate trusted: %s is itself a trust anchor (depth %d)', $current->subjectCn(), $depth))];
                }
                if ($current->issuer === $anchor->subject && $current->signedBy($anchor)) {
                    // the anchor issued $current: $depth intermediates lie between it and the leaf
                    $fault = self::issuerFault($anchor, $current, $depth, null);
                    if ($fault !== null) {
                        $anchorFault ??= $fault;

                        continue;
                    }

                    return [new ValidationStatus(StatusCode::SigningCredentialTrusted, $url, sprintf('signing certificate trusted: the chain reaches the trust anchor %s at depth %d (%s)', $anchor->subjectCn(), $depth + 1, implode(' → ', [...array_map(static fn (Certificate $c): string => $c->subjectCn(), array_slice($chain, 0, $depth + 1)), $anchor->subjectCn()])))];
                }
            }
            $next = $chain[$depth + 1] ?? null;
            if ($next === null) {
                if ($anchorFault !== null) {
                    return [new ValidationStatus(StatusCode::SigningCredentialUntrusted, $url, sprintf('signing certificate untrusted: %s', $anchorFault))];
                }
                $nameMatch = $this->anchorWithSubject($settings, $current->issuer);

                return [new ValidationStatus(StatusCode::SigningCredentialUntrusted, $url, $nameMatch === null
                    ? sprintf('signing certificate untrusted: the chain ends at %s (depth %d), issued by %s, which the chain does not carry and no trust anchor signs', $current->subjectCn(), $depth, $current->issuerCn())
                    : sprintf('signing certificate untrusted: the chain ends at %s (depth %d); a trust anchor carries the issuer name %s but its key did not make the signature — a name is not a proof', $current->subjectCn(), $depth, $current->issuerCn()),
                )];
            }
            if ($current->issuer !== $next->subject) {
                return [new ValidationStatus(StatusCode::SigningCredentialUntrusted, $url, sprintf('signing certificate untrusted: %s (depth %d) is issued by %s, but the next certificate in x5chain is %s', $current->subjectCn(), $depth, $current->issuerCn(), $next->subjectCn()))];
            }
            if (! $current->signedBy($next)) {
                return [new ValidationStatus(StatusCode::SigningCredentialUntrusted, $url, sprintf('signing certificate untrusted: the signature of %s (depth %d) does not verify under %s', $current->subjectCn(), $depth, $next->subjectCn()))];
            }
            $fault = self::issuerFault($next, $current, $depth, $at ?? time());
            if ($fault !== null) {
                return [new ValidationStatus(StatusCode::SigningCredentialUntrusted, $url, sprintf('signing certificate untrusted: %s', $fault))];
            }
            $current = $next;
        }

        return [new ValidationStatus(StatusCode::SigningCredentialUntrusted, $url, sprintf('signing certificate untrusted: %d certificates walked, none an anchor or signed by one', count($chain)))];
    }

    /**
     * The anchors a signer's chain may reach: the legacy list and every
     * "manifest" entry's (SPEC-031 AC6).
     *
     * @return list<Certificate>
     */
    public static function anchorsOf(TrustSettings $settings): array
    {
        return [...$settings->trustAnchors, ...array_merge(...array_map(static fn (TrustAnchorSet $set): array => $set->kind === TrustAnchorSet::MANIFEST ? $set->anchors : [], $settings->anchorSets))];
    }

    /**
     * The end-entity certificates trusted without a chain: the legacy list
     * (reachable only through the constructor since SPEC-031) and every
     * "manifest" entry's.
     *
     * @return list<Certificate>
     */
    public static function allowedListOf(TrustSettings $settings): array
    {
        return [...$settings->allowedList, ...array_merge(...array_map(static fn (TrustAnchorSet $set): array => $set->allowedList, $settings->anchorSets))];
    }

    /**
     * The anchors a time-stamping authority's chain may reach: the legacy
     * list and every "tsa" entry's (C2PA 2.4 §14.4.2; SPEC-031 AC6).
     *
     * @return list<Certificate>
     */
    public static function tsaAnchorsOf(TrustSettings $settings): array
    {
        return [...$settings->trustAnchors, ...array_merge(...array_map(static fn (TrustAnchorSet $set): array => $set->kind === TrustAnchorSet::TSA ? $set->anchors : [], $settings->anchorSets))];
    }

    /**
     * For an untrusted outcome: a sentence naming the entries of another
     * kind that this chain *would* have reached, which were deliberately not
     * used — or '' when no such entry exists (SPEC-031 AC6).
     *
     * @param  non-empty-list<Certificate>  $chain  leaf first
     */
    public static function kindNote(TrustSettings $settings, string $kind, array $chain): string
    {
        $reached = [];
        foreach ($settings->anchorSets as $i => $set) {
            if ($set->kind === $kind) {
                continue;
            }
            $outcome = (new ChainCheck)->checkCertificates($chain, new TrustSettings($set->anchors, $set->allowedList), '');
            if (($outcome[0] ?? null)?->code === StatusCode::SigningCredentialTrusted) {
                $reached[] = sprintf('trust.anchors[%d] ("%s")', $i, $set->kind);
            }
        }
        if ($reached === []) {
            return '';
        }

        return sprintf(
            '; the chain reaches an anchor in %s, which is not used here: every entry counts only for its own trust_kind (C2PA 2.4 §14.4.2)',
            implode(', ', $reached),
        );
    }

    /**
     * Why $issuer may not have issued $issued, or null when it may (SPEC-014
     * amendment 4; RFC 5280 §4.2.1.9, §4.2.1.3, §6.1.4): it must be a CA, carry
     * keyCertSign when keyUsage is present, allow $below intermediates under it,
     * and, when $at is given (an x5chain intermediate, not an anchor), be valid then.
     */
    private static function issuerFault(Certificate $issuer, Certificate $issued, int $below, ?int $at): ?string
    {
        if (! $issuer->isCa) {
            return sprintf('%s issued %s but is not a certificate authority (basicConstraints lacks CA:TRUE)', $issuer->subjectCn(), $issued->subjectCn());
        }
        if ($issuer->keyUsage !== null && ! in_array('Certificate Sign', $issuer->keyUsage, true)) {
            return sprintf('%s issued %s but its keyUsage lacks keyCertSign', $issuer->subjectCn(), $issued->subjectCn());
        }
        if ($issuer->pathLen !== null && $below > $issuer->pathLen) {
            return sprintf('%s allows a path length of %d, but %d intermediate certificate(s) follow it', $issuer->subjectCn(), $issuer->pathLen, $below);
        }
        if ($at !== null && ($at < $issuer->validFrom || $at > $issuer->validTo)) {
            return sprintf('the intermediate %s is not valid at %s (valid from %s to %s)', $issuer->subjectCn(), gmdate('Y-m-d\TH:i:s\Z', $at), gmdate('Y-m-d\TH:i:s\Z', $issuer->validFrom), gmdate('Y-m-d\TH:i:s\Z', $issuer->validTo));
        }

        return null;
    }

    /** @param  array<string, mixed>  $subject */
    private function anchorWithSubject(TrustSettings $settings, array $subject): ?Certificate
    {
        foreach (self::anchorsOf($settings) as $anchor) {
            if ($anchor->subject === $subject) {
                return $anchor;
            }
        }

        return null;
    }
}
