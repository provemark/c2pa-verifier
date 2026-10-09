<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Timestamp;

use Provemark\C2paVerifier\Asn1\Asn1Exception;
use Provemark\C2paVerifier\Asn1\DerReader;
use Provemark\C2paVerifier\Cose\CoseException;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Cose\EcdsaSignature;
use Provemark\C2paVerifier\Cose\OpenSsl;
use Provemark\C2paVerifier\Cose\PublicKey;
use Provemark\C2paVerifier\Cose\RsaPss;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Trust\Certificate;
use Provemark\C2paVerifier\Trust\CertificateProfileCheck;
use Provemark\C2paVerifier\Trust\ChainCheck;
use Provemark\C2paVerifier\Trust\TrustAnchorSet;
use Provemark\C2paVerifier\Trust\TrustException;
use Provemark\C2paVerifier\Trust\TrustSettings;

/**
 * The timestamp check (SPEC-017, C2PA 2.4 §15.8, RFC 3161): is the token in
 * the sigTst / sigTst2 header a valid time-stamp over *this* signature, and
 * is its TSA trusted? Seven steps in c2pa-rs's order — parse, the signer by
 * sid, messageDigest, the CMS signature, the TSA certificate's validity at
 * the token's time, the imprint against the countersigned bytes, the TSA's
 * profile and chain — each with §15's code. Every timeStamp.* code is
 * informational; what a timestamp changes is the *time* SPEC-015 judges
 * the signer's validity at, and only a validated, trusted one does that.
 * Never throws: a fault in the token is a status, not an exception.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class TimestampCheck
{
    public const OID_EKU_TIME_STAMPING = '1.3.6.1.5.5.7.3.8';

    /** @var array<string, array{kind: 'rsa'|'rsa-pss'|'ecdsa', hash: ?string, name: string}> signatureAlgorithm OID => how to verify; hash null = from digestAlgorithm */
    public const SIGNATURE_ALGORITHMS = [
        '1.2.840.113549.1.1.1' => ['kind' => 'rsa', 'hash' => null, 'name' => 'rsaEncryption'],
        '1.2.840.113549.1.1.11' => ['kind' => 'rsa', 'hash' => 'sha256', 'name' => 'sha256WithRSAEncryption'],
        '1.2.840.113549.1.1.12' => ['kind' => 'rsa', 'hash' => 'sha384', 'name' => 'sha384WithRSAEncryption'],
        '1.2.840.113549.1.1.13' => ['kind' => 'rsa', 'hash' => 'sha512', 'name' => 'sha512WithRSAEncryption'],
        '1.2.840.113549.1.1.10' => ['kind' => 'rsa-pss', 'hash' => null, 'name' => 'RSASSA-PSS'],
        '1.2.840.10045.4.3.2' => ['kind' => 'ecdsa', 'hash' => 'sha256', 'name' => 'ecdsa-with-SHA256'],
        '1.2.840.10045.4.3.3' => ['kind' => 'ecdsa', 'hash' => 'sha384', 'name' => 'ecdsa-with-SHA384'],
        '1.2.840.10045.4.3.4' => ['kind' => 'ecdsa', 'hash' => 'sha512', 'name' => 'ecdsa-with-SHA512'],
    ];

    private const OPENSSL_ALGOS = ['sha256' => OPENSSL_ALGO_SHA256, 'sha384' => OPENSSL_ALGO_SHA384, 'sha512' => OPENSSL_ALGO_SHA512];

    public function __construct(
        private DerReader $reader = new DerReader,
        private CertificateProfileCheck $profile = new CertificateProfileCheck,
        private ChainCheck $chain = new ChainCheck,
    ) {}

    /** The active manifest's timestamp, judged. No header → `TimestampResult::none()`. */
    public function check(Manifest $manifest, ?TrustSettings $settings): TimestampResult
    {
        $url = sprintf('self#jumbf=/c2pa/%s/c2pa.signature', $manifest->label);
        try {
            $cose = CoseSign1::ofManifest($manifest);
            $header = TimestampHeader::fromUnprotected($cose->unprotected);
        } catch (CoseException $e) {
            return TimestampResult::none();   // the signature check reports this; no header to judge
        } catch (TimestampException $e) {
            return new TimestampResult(true, [$this->status(StatusCode::TimeStampMalformed, $url, 'timestamp header: '.$e->getMessage())], null, false);
        }
        if ($header === null) {
            return TimestampResult::none();
        }

        return $this->checkHeader($header, $cose, $manifest->claimBytes(), $settings, $url, $manifest->claim->version);
    }

    /**
     * A manifest's timestamp in C2PA 2.4 §15.8.1's order (SPEC-064): its own header's token when that passed,
     * validated and trusted; otherwise each token a time-stamp assertion offers for its label, the first that
     * passes giving the time. A token from an assertion is reported with that assertion's url. It is judged
     * over the COSE signature field itself, as c2pa-rs 0.91 writes and reads it; on a mismatch, over the
     * whole COSE_Sign1, as earlier c2pa-rs wrote it, then over the CounterSignature structure a sigTst2
     * header token covers (§18.18.3 reads that way; amendment 1). The
     * malformed time-stamp assertions the manifest itself holds are part of its result.
     */
    public function forManifest(Manifest $manifest, ?TrustSettings $settings, TimestampAssertions $assertions): TimestampResult
    {
        $header = $this->check($manifest, $settings);
        $faults = $assertions->faultsOf($manifest->label);
        $offered = $assertions->tokensFor($manifest->label);
        if ($header->trustedTime() !== null || $offered === []) {
            return $faults === [] ? $header : new TimestampResult(true, [...$header->statuses, ...$faults], $header->time, $header->trusted, $header->timeFraction);
        }
        try {
            $cose = CoseSign1::ofManifest($manifest);
        } catch (CoseException) {
            return $faults === [] ? $header : new TimestampResult(true, [...$header->statuses, ...$faults], $header->time, $header->trusted, $header->timeFraction);
        }
        $tried = [];
        foreach ($offered as [$der, $url]) {
            try {
                $token = TimeStampToken::fromHeaderValue($der, $this->reader);
            } catch (TimestampException $e) {
                $tried[] = $this->status(StatusCode::TimeStampMalformed, $url, sprintf('the token for %s: %s', $manifest->label, $e->getMessage()));

                continue;
            }
            // the three forms a token has been taken over, each binding the same signature (amendment 1): the
            // signature field (c2pa-rs 0.91), the whole COSE_Sign1 (earlier c2pa-rs, Claim::signature_val();
            // c2pa-rs/update_manifest.jpg), the CounterSignature structure of a sigTst2 header (§18.18.3)
            foreach ([$cose->signature, $manifest->signatureBytes(), self::countersignedBytes($cose, 'sigTst2', $manifest->claimBytes())] as $i => $covered) {
                $result = $this->judge($token, $covered, $settings, $url, $manifest->claim->version);
                if ($result->trustedTime() !== null || ! in_array(StatusCode::TimeStampMismatch, array_map(static fn (ValidationStatus $s): StatusCode => $s->code, $result->statuses), true)) {
                    break;
                }
            }
            if ($result->trustedTime() !== null) {
                return new TimestampResult(true, [...$header->statuses, ...$tried, ...$result->statuses, ...$faults], $result->time, true, $result->timeFraction);
            }
            $tried = [...$tried, ...$result->statuses];
        }

        return new TimestampResult(true, [...$header->statuses, ...$tried, ...$faults], $header->time, $header->trusted, $header->timeFraction);
    }

    /**
     * A header's one token judged against the manifest's countersigned
     * bytes. A header with more than one token is malformed and gives no time,
     * as c2pa-rs 0.91.1 drops it (`sigtst.rs`: "only a single timestamp
     * response is allowed") and C2PA 2.4 §15.8.1.1 says; judging the first let an expired signer stay
     * trusted on a second token's company (SPEC-017 amendment 9).
     */
    public function checkHeader(TimestampHeader $header, CoseSign1 $cose, string $claimBytes, ?TrustSettings $settings, string $url, int $claimVersion = 2): TimestampResult
    {
        if (count($header->tokens) > 1) {
            return new TimestampResult(true, [$this->status(StatusCode::TimeStampMalformed, $url, sprintf('the %s header carries %d tokens; a claim signature has one timestamp, so none is used (C2PA 2.4 §15.8.1.1)', $header->header, count($header->tokens)))], null, false);
        }
        try {
            $token = TimeStampToken::fromHeaderValue($header->tokens[0], $this->reader);
        } catch (TimestampException $e) {
            return new TimestampResult(true, [$this->status(StatusCode::TimeStampMalformed, $url, $e->getMessage())], null, false);
        }
        $tbs = self::countersignedBytes($cose, $header->header, $claimBytes);

        return $this->judge($token, $tbs, $settings, $url, $claimVersion);
    }

    /**
     * Steps 2–7 on a parsed token — the seam for the cases a real token
     * cannot show without breaking an earlier step (outsideValidity).
     *
     * @param  string  $tbs  the countersigned bytes the imprint must match
     * @param  int  $claimVersion  the claim's version: for 2 and later a TSA leaf off the profile is also
     *                             signingCredential.invalid, as c2pa-rs logs it (SPEC-017 amendment 8)
     */
    public function judge(TimeStampToken $token, string $tbs, ?TrustSettings $settings, string $url, int $claimVersion = 2): TimestampResult
    {
        $sd = $token->signedData;
        $si = $sd->signerInfo;
        $tst = $token->tstInfo;
        $malformed = fn (string $why): TimestampResult => new TimestampResult(true, [$this->status(StatusCode::TimeStampMalformed, $url, $why)], null, false);

        // 2. the signer certificate the sid names
        try {
            $signerDer = $sd->signerCertificate($this->reader);
        } catch (TimestampException $e) {
            return $malformed($e->getMessage());
        }
        if ($signerDer === null) {
            return $malformed(sprintf(
                'no certificate in the token matches the SignerInfo sid (%s) among its %d certificate(s)',
                $si->sidSubjectKeyId !== null ? 'subjectKeyIdentifier '.bin2hex($si->sidSubjectKeyId) : 'serial '.($si->sidSerial ?? '?'),
                count($sd->certificates),
            ));
        }
        try {
            $signer = Certificate::fromDer($signerDer);
            $key = PublicKey::fromCertificateDer($signerDer);
        } catch (TrustException|CoseException $e) {
            return $malformed('the TSA certificate could not be read: '.$e->getMessage());
        }
        $tsaName = $signer->subjectCn();

        // 3. the signed messageDigest is the digest of the TSTInfo
        $digestName = TstInfo::digestName($si->digestAlgorithm);
        if (! isset(self::OPENSSL_ALGOS[$digestName])) {
            return new TimestampResult(true, [$this->status(StatusCode::TimeStampUntrusted, $url, sprintf('timestamp signature not verified: digest algorithm %s is not supported (%s)', $si->digestAlgorithm, $tsaName))], null, false);
        }
        $digest = hash($digestName, $sd->eContent, true);
        if (! hash_equals($digest, $si->messageDigest)) {
            return new TimestampResult(true, [$this->status(StatusCode::TimeStampMismatch, $url, sprintf('timestamp messageDigest does not match its TSTInfo: signed %s…, computed %s… (%s, %s)', bin2hex(substr($si->messageDigest, 0, 4)), bin2hex(substr($digest, 0, 4)), $digestName, $tsaName))], null, false);
        }

        // 4. the CMS signature over the signed attributes
        $verified = $this->verifySignature($si, $key, $digestName, $why);
        if (! $verified) {
            return new TimestampResult(true, [$this->status(StatusCode::TimeStampUntrusted, $url, sprintf('timestamp signature did not verify: %s (%s)', $why, $tsaName))], null, false);
        }

        // 5. the TSA certificate is valid at the token's time
        if ($tst->genTime < $signer->validFrom || $tst->genTime > $signer->validTo) {
            return new TimestampResult(true, [$this->status(StatusCode::TimeStampOutsideValidity, $url, sprintf(
                'timestamp time %s lies outside the TSA certificate\'s validity, %s to %s (%s)',
                gmdate('Y-m-d\TH:i:s\Z', $tst->genTime),
                gmdate('Y-m-d\TH:i:s\Z', $signer->validFrom),
                gmdate('Y-m-d\TH:i:s\Z', $signer->validTo),
                $tsaName,
            ))], null, false);
        }

        // 6. the imprint is the digest of the countersigned bytes
        $imprintName = TstInfo::digestName($tst->hashAlgorithm);
        $expected = hash($imprintName, $tbs, true);
        if (! hash_equals($expected, $tst->hashedMessage)) {
            return new TimestampResult(true, [$this->status(StatusCode::TimeStampMismatch, $url, sprintf('timestamp imprint does not match the signature: token %s…, computed %s… (%s over %d bytes, %s)', bin2hex(substr($tst->hashedMessage, 0, 4)), bin2hex(substr($expected, 0, 4)), $imprintName, strlen($tbs), $tsaName))], null, false);
        }
        $statuses = [$this->status(StatusCode::TimeStampValidated, $url, sprintf('timestamp message digest matched: %s (%s)', $tsaName, gmdate('c', $tst->genTime)))];

        // 7. the TSA's trust: the profile with timeStamping alone, then the chain to a configured anchor
        $tsaSettings = self::tsaSettings($settings);
        if (! $tsaSettings->verifyTrust) {
            return new TimestampResult(true, $statuses, $tst->genTime, false, $tst->genTimeFraction);
        }
        $trusted = false;
        $faults = $this->profile->checkLeaf($signer, $tsaSettings, $tst->genTime, $url, ekus: [self::OID_EKU_TIME_STAMPING], manifestSigner: false);
        if ($faults !== []) {
            $statuses[] = $this->status(StatusCode::TimeStampUntrusted, $url, sprintf('timestamp cert untrusted: %s — %s', $tsaName, implode('; ', array_map(static fn (ValidationStatus $s): string => $s->explanation, $faults))));
            // c2pa-rs logs a TSA leaf's profile faults as signingCredential.invalid into the manifest's own log, for a
            // version 2 claim (it checks no TSA profile for version 1); kept so that the verdicts compare (amendment 8)
            if ($claimVersion >= 2) {
                foreach ($faults as $fault) {
                    $statuses[] = $this->status(StatusCode::SigningCredentialInvalid, $url, sprintf('the timestamp authority\'s certificate %s fails the certificate profile: %s', $tsaName, preg_replace('/^signing certificate invalid: /', '', $fault->explanation) ?? $fault->explanation));
                }
            }
        } else {
            try {
                $ordered = $this->orderedChain($signerDer, $sd->certificates);
            } catch (TrustException $e) {
                return new TimestampResult(true, [...$statuses, $this->status(StatusCode::TimeStampUntrusted, $url, sprintf('timestamp cert untrusted: %s — a certificate in the token could not be read: %s', $tsaName, $e->getMessage()))], $tst->genTime, false, $tst->genTimeFraction);
            }
            foreach ($this->chain->checkCertificates($ordered, $tsaSettings, $url, $tst->genTime) as $outcome) {
                $trusted = $outcome->code === StatusCode::SigningCredentialTrusted;
                $statuses[] = $this->status(
                    $trusted ? StatusCode::TimeStampTrusted : StatusCode::TimeStampUntrusted,
                    $url,
                    sprintf('timestamp cert %s: %s — %s', $trusted ? 'trusted' : 'untrusted', $tsaName, preg_replace('/^signing certificate (un)?trusted: /', '', $outcome->explanation) ?? $outcome->explanation)
                        .($trusted || $settings === null ? '' : ChainCheck::kindNote($settings, TrustAnchorSet::TSA, $ordered)),
                );
            }
        }

        return new TimestampResult(true, $statuses, $tst->genTime, $trusted, $tst->genTimeFraction);
    }

    /**
     * ["CounterSignature", protected, h'', payload] (RFC 9052 §4.4; c2pa-rs
     * `cose_countersign_data`): the payload is the claim bytes for `sigTst`
     * and the signature as a CBOR byte string for `sigTst2` (C2PA 2.4 §10.3.2.5).
     */
    public static function countersignedBytes(CoseSign1 $cose, string $header, string $claimBytes): string
    {
        $payload = $header === 'sigTst2' ? self::bstr($cose->signature) : $claimBytes;

        return "\x84"."\x70CounterSignature".self::bstr($cose->protectedBytes)."\x40".self::bstr($payload);
    }

    /**
     * The operator's TSA anchors — the legacy list and every "tsa" entry,
     * never a "manifest" one (C2PA 2.4 §14.4.2; SPEC-031 AC6) — with
     * `trust_config` replaced by timeStamping alone; `verify_trust` kept.
     * **No allowed list**, neither an entry's nor the one a caller passes to
     * the constructor: the private credential store *"shall not apply to
     * validating time-stamps"* (§14.4.3, §14.5.1.2; SPEC-017 amendment 5).
     */
    public static function tsaSettings(?TrustSettings $operator): TrustSettings
    {
        if ($operator === null) {
            return new TrustSettings([], [], [self::OID_EKU_TIME_STAMPING], true);
        }

        return new TrustSettings(ChainCheck::tsaAnchorsOf($operator), [], [self::OID_EKU_TIME_STAMPING], $operator->verifyTrust);
    }

    /**
     * Step 4: the signature over the re-tagged signed attributes with the
     * signer's key, by the SignerInfo's algorithm. $why says what failed.
     *
     * @param-out string $why
     */
    private function verifySignature(SignerInfo $si, PublicKey $key, string $digestName, ?string &$why): bool
    {
        $why = '';
        $spec = self::SIGNATURE_ALGORITHMS[$si->signatureAlgorithm] ?? null;
        if ($spec === null) {
            $why = sprintf('signature algorithm %s is not supported', $si->signatureAlgorithm);

            return false;
        }
        $hash = $spec['hash'] ?? $digestName;
        if ($spec['hash'] !== null && $spec['hash'] !== $digestName) {
            $why = sprintf('%s signs with %s but the digestAlgorithm is %s', $spec['name'], $spec['hash'], $digestName);

            return false;
        }
        $tbs = $si->signedAttributesForVerification();
        $why = sprintf('%s signature over %d bytes of signed attributes with a %d-bit %s key', $spec['name'], strlen($tbs), $key->bits, $key->kind);
        switch ($spec['kind']) {
            case 'rsa':
                if ($key->kind !== PublicKey::KIND_RSA) {
                    $why .= ' — the key is not an RSA key';

                    return false;
                }

                return $this->opensslVerify($tbs, $si->signature, $key, self::OPENSSL_ALGOS[$hash]);
            case 'ecdsa':
                if ($key->kind !== PublicKey::KIND_EC) {
                    $why .= ' — the key is not an EC key';

                    return false;
                }

                return $this->opensslVerify($tbs, self::ecdsaDer($si->signature, $key), $key, self::OPENSSL_ALGOS[$hash]);
            default:   // rsa-pss: MGF1 with the same hash, salt = hash length (RFC 8017; as SPEC-009)
                if ($key->kind === PublicKey::KIND_RSA_PSS) {
                    return $this->opensslVerify($tbs, $si->signature, $key, self::OPENSSL_ALGOS[$hash]);
                }
                if ($key->kind !== PublicKey::KIND_RSA) {
                    $why .= ' — the key is not an RSA key';

                    return false;
                }

                return RsaPss::verify($tbs, $si->signature, $key->key, $hash, $key->bits);
        }
    }

    /**
     * A CMS ECDSA signature is DER `ECDSA-Sig-Value` (RFC 3279 §2.2.3), and
     * `c2pa-ts` writes it as raw R‖S (SPEC-017 amendment 3; c2patool accepts
     * it). Raw is taken only when the bytes are not a well-formed DER
     * SEQUENCE of two INTEGERs and are exactly two coordinates long; DER
     * passes through unchanged.
     */
    private static function ecdsaDer(string $signature, PublicKey $key): string
    {
        if (self::isDerEcdsaSignature($signature)) {
            return $signature;
        }
        $curveBytes = intdiv($key->bits + 7, 8);

        return EcdsaSignature::toDer($signature, $curveBytes) ?? $signature;
    }

    private static function isDerEcdsaSignature(string $bytes): bool
    {
        try {
            $seq = (new DerReader(maxDepth: 2, maxElements: 3, maxBytes: 256))->read($bytes);
            $parts = $seq->sequence();

            return count($parts) === 2 && $parts[0]->integerBytes() !== '' && $parts[1]->integerBytes() !== '';
        } catch (Asn1Exception) {
            return false;
        }
    }

    private function opensslVerify(string $message, string $signature, PublicKey $key, int $algo): bool
    {
        $result = OpenSsl::quiet(static fn (): int|false => openssl_verify($message, $signature, $key->key, $algo));

        return $result === 1;
    }

    /**
     * The token's certificates from the signer towards the root, each the
     * issuer of the one before (c2pa-rs `order_certificates_leaf_to_root`);
     * a certificate no link reaches is left out.
     *
     * @param  list<string>  $ders
     * @return non-empty-list<Certificate>
     */
    private function orderedChain(string $signerDer, array $ders): array
    {
        $pool = [];
        foreach ($ders as $der) {
            if ($der !== $signerDer) {
                $pool[] = Certificate::fromDer($der);
            }
        }
        $chain = [Certificate::fromDer($signerDer)];
        $current = $chain[0];
        while ($pool !== []) {
            $next = null;
            foreach ($pool as $i => $candidate) {
                if ($candidate->subject === $current->issuer && ! $candidate->sameAs($current)) {
                    $next = $i;
                    break;
                }
            }
            if ($next === null) {
                break;
            }
            $current = $pool[$next];
            $chain[] = $current;
            unset($pool[$next]);
        }

        return $chain;
    }

    private function status(StatusCode $code, string $url, string $explanation): ValidationStatus
    {
        return new ValidationStatus($code, $url, $explanation);
    }

    private static function bstr(string $bytes): string
    {
        $n = strlen($bytes);
        if ($n < 24) {
            return chr(0x40 + $n).$bytes;
        }
        if ($n < 256) {
            return "\x58".chr($n).$bytes;
        }
        if ($n < 65536) {
            return "\x59".pack('n', $n).$bytes;
        }

        return "\x5a".pack('N', $n).$bytes;
    }
}
