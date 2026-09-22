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
 */
final readonly class ChainCheck
{
    /** @return list<ValidationStatus> */
    public function check(Manifest $manifest, TrustSettings $settings): array
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

        return $this->checkCertificates($chain, $settings, $url);
    }

    /**
     * The allowed list, then the walk, on a chain given as certificates, leaf
     * first — the seam SPEC-017 uses for a TSA's certificates (SPEC-014
     * amendment 2). `verify_trust` is the caller's to honour.
     *
     * @param  non-empty-list<Certificate>  $chain
     * @return list<ValidationStatus>
     */
    public function checkCertificates(array $chain, TrustSettings $settings, string $url): array
    {
        $leaf = $chain[0];

        // the allowed list first: a listed end-entity certificate needs no chain (c2pa-rs: EndEntity)
        foreach ($settings->allowedList as $allowed) {
            if (hash_equals($allowed->sha256, $leaf->sha256)) {
                return [new ValidationStatus(StatusCode::SigningCredentialTrusted, $url, sprintf('signing certificate trusted: %s is on the allowed list (sha256 %s)', $leaf->subjectCn(), bin2hex($leaf->sha256)))];
            }
        }
        if ($settings->trustAnchors === []) {
            return [new ValidationStatus(StatusCode::SigningCredentialUntrusted, $url, sprintf('signing certificate untrusted: %s is not on the allowed list and no trust anchors are configured', $leaf->subjectCn()))];
        }

        // the walk, from the leaf, through the chain the signer supplied
        $current = $leaf;
        foreach ($chain as $depth => $_) {
            foreach ($settings->trustAnchors as $anchor) {
                // depth = links walked from the leaf to the anchor: the leaf itself an anchor is 0, the leaf signed by one is 1
                if ($current->sameAs($anchor)) {
                    return [new ValidationStatus(StatusCode::SigningCredentialTrusted, $url, sprintf('signing certificate trusted: %s is itself a trust anchor (depth %d)', $current->subjectCn(), $depth))];
                }
                if ($current->issuer === $anchor->subject && $current->signedBy($anchor)) {
                    return [new ValidationStatus(StatusCode::SigningCredentialTrusted, $url, sprintf('signing certificate trusted: the chain reaches the trust anchor %s at depth %d (%s)', $anchor->subjectCn(), $depth + 1, implode(' → ', [...array_map(static fn (Certificate $c): string => $c->subjectCn(), array_slice($chain, 0, $depth + 1)), $anchor->subjectCn()])))];
                }
            }
            $next = $chain[$depth + 1] ?? null;
            if ($next === null) {
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
            $current = $next;
        }

        return [new ValidationStatus(StatusCode::SigningCredentialUntrusted, $url, sprintf('signing certificate untrusted: %d certificates walked, none an anchor or signed by one', count($chain)))];
    }

    /** @param  array<string, mixed>  $subject */
    private function anchorWithSubject(TrustSettings $settings, array $subject): ?Certificate
    {
        foreach ($settings->trustAnchors as $anchor) {
            if ($anchor->subject === $subject) {
                return $anchor;
            }
        }

        return null;
    }
}
