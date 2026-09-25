<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

use Provemark\C2paVerifier\Asn1\Asn1Exception;
use Provemark\C2paVerifier\Asn1\Der;
use Provemark\C2paVerifier\Asn1\DerReader;
use Provemark\C2paVerifier\Asn1\TagClass;
use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Cose\CoseException;
use Provemark\C2paVerifier\Cose\OpenSsl;
use Provemark\C2paVerifier\Cose\PublicKey;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;

/**
 * Revocation as far as it can be known without a network: the OCSP responses a
 * signer staples into its own signature (SPEC-030; RFC 6960, RFC 5019 §3.2).
 *
 * The header carrying them, `rVals`, sits in the COSE **unprotected** bucket —
 * measured, step 91 — so it is not covered by the signature and anyone holding
 * the file can add one, alter it or strip it out. Four rules follow, and they
 * are why this class is shaped the way it is:
 *
 * 1. A stapled response may never raise trust. `notRevoked` is recorded as a
 *    fact about what was found, and its explanation says where it came from.
 * 2. A response that cannot be verified may never fail the file: otherwise
 *    editing one unsigned byte would deny any valid asset. Everything
 *    unreadable, unverifiable or about another certificate is *skipped*.
 * 3. Only a response that verifies under a responder tied to the signer's own
 *    issuer may lower trust. Then it is evidence no attacker could forge.
 * 4. Absence proves nothing, so absence is said out loud — every file without
 *    an `rVals` gets one `signingCredential.ocsp.skipped`.
 *
 * Online OCSP, AIA and CRLs stay out: there is no network in this verifier's
 * verification path, and that is a rule of the project rather than a milestone.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the ten classes named in the README.
 */
final readonly class OcspCheck
{
    public const DEFAULT_MAX_RESPONSES = 4;

    public const DEFAULT_MAX_RESPONSE_BYTES = 65536;

    /**
     * id-pkix-ocsp-basic, the only response type RFC 6960 defines. Note the last
     * arc: `1.3.6.1.5.5.7.48.1` is id-pkix-ocsp, the access method in an AIA
     * extension, and `…48.1.1` is the response type. SPEC-030 amendment 2.
     */
    private const OID_BASIC = '1.3.6.1.5.5.7.48.1.1';

    private const OID_EKU_OCSP_SIGNING = '1.3.6.1.5.5.7.3.9';

    /** CertID hash algorithms. sha1 is here because responders still use it for the *name* hash, which is not a signature. */
    private const HASHES = [
        '1.3.14.3.2.26' => 'sha1',
        '2.16.840.1.101.3.4.2.1' => 'sha256',
        '2.16.840.1.101.3.4.2.2' => 'sha384',
        '2.16.840.1.101.3.4.2.3' => 'sha512',
    ];

    /**
     * signatureAlgorithm OID => the hash openssl_verify needs. A second, smaller
     * copy of TimestampCheck's table on purpose: the trust layer may not depend on
     * the timestamp layer, and sharing it would invert that.
     */
    private const SIGNATURE_HASHES = [
        '1.2.840.113549.1.1.11' => 'sha256',
        '1.2.840.113549.1.1.12' => 'sha384',
        '1.2.840.113549.1.1.13' => 'sha512',
        '1.2.840.10045.4.3.2' => 'sha256',
        '1.2.840.10045.4.3.3' => 'sha384',
        '1.2.840.10045.4.3.4' => 'sha512',
    ];

    private const OPENSSL_ALGOS = ['sha256' => OPENSSL_ALGO_SHA256, 'sha384' => OPENSSL_ALGO_SHA384, 'sha512' => OPENSSL_ALGO_SHA512];

    /** RFC 5280 §5.3.1. 8 is removeFromCRL, which un-revokes rather than revokes. */
    private const REASONS = [
        0 => 'unspecified', 1 => 'keyCompromise', 2 => 'cACompromise', 3 => 'affiliationChanged',
        4 => 'superseded', 5 => 'cessationOfOperation', 6 => 'certificateHold', 8 => 'removeFromCRL',
        9 => 'privilegeWithdrawn', 10 => 'aACompromise',
    ];

    private const REASON_REMOVE_FROM_CRL = 8;

    public function __construct(
        private DerReader $reader = new DerReader,
        private int $maxResponses = self::DEFAULT_MAX_RESPONSES,
        private int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
    ) {}

    /**
     * Exactly one status, always: what this verifier knows about the signer's
     * revocation, or why it knows nothing.
     *
     * @param  array<int|string, mixed>  $unprotected  the COSE unprotected header
     * @param  list<Certificate>  $chain  the signer's chain, leaf first
     * @param  int|null  $at  the judged time — a trusted timestamp's, else null for now
     * @return list<ValidationStatus>
     */
    public function check(array $unprotected, array $chain, ?int $at, string $url): array
    {
        $time = $at ?? time();
        $skipped = fn (string $why): array => [new ValidationStatus(StatusCode::SigningCredentialOcspSkipped, $url, 'revocation not checked: '.$why)];

        $ders = $this->responseBytes($unprotected);
        if (is_string($ders)) {
            return $skipped($ders);
        }
        if ($ders === []) {
            return $skipped('the signature staples no OCSP response, and this verifier makes no network request (SPEC-014); absence is not evidence that the certificate was never revoked');
        }
        if (count($chain) < 2) {
            return $skipped(sprintf('the signer\'s issuer is not in the x5chain (%d certificate(s)), so no response can be matched to it', count($chain)));
        }
        $leaf = $chain[0];
        $issuer = $chain[1];

        $reasons = [];
        $best = null;
        foreach ($ders as $i => $der) {
            $single = $this->usable($der, $leaf, $issuer, $i);
            if (is_string($single)) {
                $reasons[] = $single;

                continue;
            }
            // a revoked answer wins over any other, whichever order they were stapled in
            if ($single['status'] === 'revoked') {
                $best = $single;
                break;
            }
            $best ??= $single;
        }

        if ($best === null) {
            return $skipped(implode('; ', $reasons));
        }

        return [$this->statusOf($best, $leaf, $time, $url, $skipped)];
    }

    /**
     * `rVals.ocspVals` as a list of DER strings, or the reason there is none.
     *
     * Everything here answers with a reason rather than an exception: the header is
     * unsigned, so a malformed one says nothing about the asset.
     *
     * @param  array<int|string, mixed>  $unprotected
     * @return list<string>|string
     */
    private function responseBytes(array $unprotected): array|string
    {
        $rVals = $unprotected['rVals'] ?? null;
        if ($rVals === null) {
            return [];
        }
        if (! is_array($rVals)) {
            return sprintf('the rVals header is %s, not a map (RFC 6960; C2PA 2.4 §14)', get_debug_type($rVals));
        }
        // an empty CBOR map decodes to the same PHP value as an empty list, so it is
        // read as a header that carries nothing rather than as one of the wrong type
        if ($rVals === []) {
            return 'the rVals header is empty';
        }
        if (array_is_list($rVals)) {
            return 'the rVals header is a list, not a map (RFC 6960; C2PA 2.4 §14)';
        }
        $vals = $rVals['ocspVals'] ?? null;
        if ($vals === null) {
            // crlVals and anything else: named, not refused — the header is unsigned
            return sprintf('the rVals header carries no ocspVals (it has: %s)', implode(', ', array_map('strval', array_keys($rVals))));
        }
        if (! is_array($vals)) {
            return sprintf('ocspVals is %s, not a list', get_debug_type($vals));
        }
        if (! array_is_list($vals)) {
            return 'ocspVals is a map, not a list';
        }
        if (count($vals) > $this->maxResponses) {
            return sprintf('%d stapled responses exceed the limit of %d', count($vals), $this->maxResponses);
        }
        $ders = [];
        foreach ($vals as $i => $value) {
            if (! $value instanceof CborBytes) {
                return sprintf('ocspVals[%d] is %s, not a byte string', $i, get_debug_type($value));
            }
            if (strlen($value->bytes) > $this->maxResponseBytes) {
                return sprintf('ocspVals[%d] is %d bytes, over the limit of %d', $i, strlen($value->bytes), $this->maxResponseBytes);
            }
            $ders[] = $value->bytes;
        }

        return $ders;
    }

    /**
     * One response, parsed, matched to this certificate and verified — or the reason it cannot be used.
     *
     * @return array{status: string, revokedAt: int|null, reason: int|null, thisUpdate: int, nextUpdate: int|null, responder: string, producedAt: int}|string
     */
    private function usable(string $der, Certificate $leaf, Certificate $issuer, int $i): array|string
    {
        try {
            $response = $this->reader->read($der);
            $parts = $response->sequence();
            $statusByte = $parts[0] ?? null;
            if ($statusByte === null || ! $statusByte->is(TagClass::Universal, 10) || $statusByte->contents === '') {
                return sprintf('response %d has no OCSPResponseStatus', $i);
            }
            $responseStatus = ord($statusByte->contents[0]);
            if ($responseStatus !== 0) {
                return sprintf('response %d is not successful: OCSPResponseStatus %d', $i, $responseStatus);
            }
            $bytes = $parts[1] ?? null;
            if ($bytes === null) {
                return sprintf('response %d carries no responseBytes', $i);
            }
            $inner = $bytes->tagged(0)->child(0)->sequence();
            $type = ($inner[0] ?? null)?->oid();
            if ($type !== self::OID_BASIC) {
                return sprintf('response %d is of type %s, not id-pkix-ocsp-basic', $i, $type ?? 'nothing');
            }
            $basic = $this->reader->read(($inner[1] ?? null)?->octets() ?? '')->sequence();
            $tbs = $basic[0] ?? null;
            $algorithm = $basic[1] ?? null;
            $signature = $basic[2] ?? null;
            if ($tbs === null || $algorithm === null || $signature === null) {
                return sprintf('response %d is not a BasicOCSPResponse', $i);
            }

            $data = $tbs->sequence();
            // ResponseData's version is [0] EXPLICIT and DEFAULT v1, so it is usually absent
            $first = $tbs->element(0);
            $offset = $first->class === TagClass::ContextSpecific && $first->tag === 0 ? 1 : 0;
            $responderId = $data[$offset] ?? null;
            $producedAt = ($data[$offset + 1] ?? null)?->time();
            $responses = $data[$offset + 2] ?? null;
            if ($producedAt === null || $responses === null) {
                return sprintf('response %d has no producedAt or no responses', $i);
            }
            $single = $this->matching($responses->sequence(), $leaf, $issuer);
            if ($single === null) {
                return sprintf('response %d answers about no certificate in this chain', $i);
            }

            $responder = $this->responder($basic[3] ?? null, $issuer);
            if (is_string($responder)) {
                return sprintf('response %d: %s', $i, $responder);
            }
            $verified = $this->verify($tbs->encoded(), $signature, $algorithm, $responder);
            if ($verified !== null) {
                return sprintf('response %d: %s', $i, $verified);
            }

            return $single + ['responder' => $responder->subjectCn(), 'producedAt' => $producedAt];
        } catch (Asn1Exception|TrustException|CoseException $e) {
            return sprintf('response %d could not be read: %s', $i, $e->getMessage());
        }
    }

    /**
     * The SingleResponse whose CertID names this certificate, or null.
     *
     * @param  list<Der>  $singles
     * @return array{status: string, revokedAt: int|null, reason: int|null, thisUpdate: int, nextUpdate: int|null}|null
     *
     * @throws Asn1Exception
     */
    private function matching(array $singles, Certificate $leaf, Certificate $issuer): ?array
    {
        foreach ($singles as $single) {
            $fields = $single->sequence();
            $certId = ($fields[0] ?? null)?->sequence();
            $status = $fields[1] ?? null;
            if ($certId === null || $status === null || count($certId) < 4) {
                continue;
            }
            $algorithm = self::HASHES[$certId[0]->child(0)->oid()] ?? null;
            if ($algorithm === null) {
                continue;
            }
            if ($certId[3]->integer() !== $leaf->serialDecimal) {
                continue;
            }
            $nameHash = self::issuerName($issuer);
            $keyHash = self::issuerKey($issuer);
            if ($nameHash === null || $keyHash === null) {
                continue;
            }
            if (! hash_equals(hash($algorithm, $nameHash, true), $certId[1]->octets())
                || ! hash_equals(hash($algorithm, $keyHash, true), $certId[2]->octets())) {
                continue;
            }

            $thisUpdate = ($fields[2] ?? null)?->time();
            if ($thisUpdate === null) {
                continue;
            }
            $nextUpdate = null;
            foreach (array_slice($fields, 3) as $extra) {
                if ($extra->class === TagClass::ContextSpecific && $extra->tag === 0) {
                    $nextUpdate = $extra->child(0)->time();
                }
            }

            return self::certStatus($status) + ['thisUpdate' => $thisUpdate, 'nextUpdate' => $nextUpdate];
        }

        return null;
    }

    /**
     * `good` / `revoked` / `unknown`, with the revocation's time and reason.
     *
     * @return array{status: string, revokedAt: int|null, reason: int|null}
     *
     * @throws Asn1Exception
     */
    private static function certStatus(Der $status): array
    {
        if ($status->class !== TagClass::ContextSpecific) {
            return ['status' => 'unknown', 'revokedAt' => null, 'reason' => null];
        }
        if ($status->tag === 0) {
            return ['status' => 'good', 'revokedAt' => null, 'reason' => null];
        }
        if ($status->tag !== 1) {
            return ['status' => 'unknown', 'revokedAt' => null, 'reason' => null];
        }
        // [1] RevokedInfo, IMPLICIT: its children are revocationTime and [0] CRLReason
        $revokedAt = $status->childCount() > 0 ? $status->child(0)->time() : null;
        $reason = null;
        for ($i = 1; $i < $status->childCount(); $i++) {
            $child = $status->child($i);
            if ($child->class === TagClass::ContextSpecific && $child->tag === 0) {
                $enumerated = $child->childCount() > 0 ? $child->child(0)->contents : $child->contents;
                $reason = $enumerated === '' ? null : ord($enumerated[strlen($enumerated) - 1]);
            }
        }

        return ['status' => 'revoked', 'revokedAt' => $revokedAt, 'reason' => $reason];
    }

    /**
     * Whoever signed this response: the issuer itself, or a delegated responder it issued.
     *
     * RFC 6960 §4.2.2.2 allows exactly these two. A certificate in `certs` that the
     * issuer did not sign, or that lacks id-kp-OCSPSigning, is not a responder —
     * believing one would let anybody answer for anybody.
     *
     *
     * @throws Asn1Exception
     */
    private function responder(?Der $certs, Certificate $issuer): Certificate|string
    {
        if ($certs === null) {
            return $issuer;
        }
        foreach ($certs->tagged(0)->child(0)->sequence() as $element) {
            try {
                $candidate = Certificate::fromDer($element->encoded());
            } catch (TrustException) {
                continue;
            }
            if ($candidate->sameAs($issuer)) {
                return $issuer;
            }
            if (! $candidate->signedBy($issuer)) {
                continue;
            }
            if (! in_array(self::OID_EKU_OCSP_SIGNING, $candidate->extendedKeyUsage ?? [], true)) {
                return sprintf('the responder %s has no id-kp-OCSPSigning (RFC 6960 §4.2.2.2)', $candidate->subjectCn());
            }

            return $candidate;
        }

        return $issuer;
    }

    /** null when the signature verifies, else why it does not. */
    private function verify(string $message, Der $signature, Der $algorithm, Certificate $responder): ?string
    {
        $oid = $algorithm->child(0)->oid();
        $hash = self::SIGNATURE_HASHES[$oid] ?? null;
        if ($hash === null) {
            return sprintf('the response is signed with %s, which this verifier does not implement', $oid);
        }
        // BIT STRING is universal tag 3; Der names the ten tags it reads and this is not one of them
        if (! $signature->is(TagClass::Universal, 3) || strlen($signature->contents) < 2) {
            return 'the response carries no signature';
        }
        try {
            $key = PublicKey::fromCertificateDer($responder->der);
        } catch (CoseException $e) {
            return sprintf('the responder\'s key cannot be read: %s', $e->getMessage());
        }
        $bits = substr($signature->contents, 1);
        $algo = self::OPENSSL_ALGOS[$hash];
        $ok = OpenSsl::quiet(static fn (): int|false => openssl_verify($message, $bits, $key->key, $algo));

        return $ok === 1 ? null : sprintf('the response\'s signature does not verify under %s', $responder->subjectCn());
    }

    /**
     * The one status a usable response yields, freshness included.
     *
     * The asymmetry decided on approval: a stale `good` is no evidence, because an
     * assurance ages; a stale `revoked` still counts, because a revocation does not.
     *
     * @param  array{status: string, revokedAt: int|null, reason: int|null, thisUpdate: int, nextUpdate: int|null, responder: string, producedAt: int}  $single
     * @param  callable(string): list<ValidationStatus>  $skipped
     */
    private function statusOf(array $single, Certificate $leaf, int $time, string $url, callable $skipped): ValidationStatus
    {
        $when = static fn (?int $t): string => $t === null ? 'never' : gmdate('Y-m-d\TH:i:s\Z', $t);
        $source = 'the response comes from the unsigned rVals header, so it is not evidence the certificate was never revoked';

        if ($single['status'] === 'revoked' && $single['reason'] !== self::REASON_REMOVE_FROM_CRL
            && ($single['revokedAt'] === null || $single['revokedAt'] <= $time)) {
            return new ValidationStatus(StatusCode::SigningCredentialOcspRevoked, $url, sprintf(
                'the signing certificate %s (serial %s) was revoked at %s (%s), as %s answered on %s',
                $leaf->subjectCn(),
                $leaf->serialDecimal,
                $when($single['revokedAt']),
                self::reasonName($single['reason'], 'unspecified'),
                $single['responder'],
                $when($single['producedAt']),
            ));
        }
        if ($single['status'] === 'revoked') {
            // removeFromCRL is a re-instatement (RFC 6960 §4.2.1), and a revocation after
            // the signing time says nothing about a signature made before it
            return $skipped(sprintf(
                'the stapled response reports %s at %s for %s, which is not a revocation at the judged time %s',
                self::reasonName($single['reason'], 'a revocation'), $when($single['revokedAt']), $leaf->subjectCn(), $when($time),
            ))[0];
        }
        if ($single['status'] !== 'good') {
            return new ValidationStatus(StatusCode::SigningCredentialOcspUnknown, $url, sprintf(
                '%s does not know the status of %s (serial %s); %s',
                $single['responder'], $leaf->subjectCn(), $leaf->serialDecimal, $source,
            ));
        }
        if ($single['thisUpdate'] > $time || ($single['nextUpdate'] !== null && $single['nextUpdate'] < $time)) {
            return $skipped(sprintf(
                'the stapled response was valid from %s to %s and the judged time is %s, so it is not current (RFC 5019 §3.2); this verifier makes no online query to refresh it',
                $when($single['thisUpdate']), $when($single['nextUpdate']), $when($time),
            ))[0];
        }

        return new ValidationStatus(StatusCode::SigningCredentialOcspNotRevoked, $url, sprintf(
            '%s answered good for %s on %s, valid from %s to %s; %s',
            $single['responder'],
            $leaf->subjectCn(),
            $when($single['producedAt']),
            $when($single['thisUpdate']),
            $when($single['nextUpdate']),
            $source,
        ));
    }

    /** A CRLReason by name; a response need not give one, and one this verifier does not know is not invented. */
    private static function reasonName(?int $reason, string $fallback): string
    {
        return $reason === null ? $fallback : (self::REASONS[$reason] ?? sprintf('reason %d', $reason));
    }

    /** The issuer's subject Name, exactly as encoded — what issuerNameHash hashes. */
    private static function issuerName(Certificate $issuer): ?string
    {
        try {
            $tbs = (new DerReader)->read($issuer->der)->child(0)->sequence();
            $offset = ($tbs[0] ?? null)?->class === TagClass::ContextSpecific ? 1 : 0;

            return ($tbs[$offset + 4] ?? null)?->encoded();
        } catch (Asn1Exception) {
            return null;
        }
    }

    /** The issuer's subjectPublicKey BIT STRING contents — what issuerKeyHash hashes. */
    private static function issuerKey(Certificate $issuer): ?string
    {
        try {
            $key = PublicKey::fromCertificateDer($issuer->der);
            $bits = (new DerReader)->read($key->spki)->child(1);

            return substr($bits->contents, 1);
        } catch (Asn1Exception|CoseException) {
            return null;
        }
    }
}
