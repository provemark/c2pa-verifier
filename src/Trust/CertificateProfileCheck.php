<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

use Provemark\C2paVerifier\Cose\CoseException;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;

/**
 * The certificate profile as a list of statuses (SPEC-015; C2PA 2.4 §14.5):
 * is the leaf of the x5chain a C2PA signing certificate? End-entity, v3,
 * within its validity at the signing time (now, until M6 hands over the
 * timestamp), an allowed signature algorithm and key, a KeyUsage that
 * permits signing, an ExtendedKeyUsage from the accepted list — the
 * built-in six plus what the settings add, never fewer (ADR-0003) — and an
 * AuthorityKeyIdentifier. Every fault is its own signingCredential.invalid;
 * validity is signingCredential.expired. The rules are c2pa-rs's
 * certificate_profile.rs, read to the end in step 33, on what ext-openssl
 * reports; unknown critical extensions are the one rule it cannot see.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class CertificateProfileCheck
{
    /** c2pa-rs's valid_eku_oids.cfg: emailProtection, documentSigning, timeStamping, OCSPSigning, MS C2PA Signing, C2PA Signing. */
    public const BUILT_IN_EKUS = ['1.3.6.1.5.5.7.3.4', '1.3.6.1.5.5.7.3.36', '1.3.6.1.5.5.7.3.8', '1.3.6.1.5.5.7.3.9', '1.3.6.1.4.1.311.76.59.1.9', '1.3.6.1.4.1.62558.2.1'];

    private const SIGNATURE_ALGORITHMS = ['sha256WithRSAEncryption', 'sha384WithRSAEncryption', 'sha512WithRSAEncryption', 'ecdsa-with-SHA256', 'ecdsa-with-SHA384', 'ecdsa-with-SHA512', 'ED25519', 'rsassaPss'];

    private const CURVES = ['prime256v1', 'secp384r1', 'secp521r1'];

    private const EKU_TIME_STAMPING = '1.3.6.1.5.5.7.3.8';

    private const EKU_OCSP_SIGNING = '1.3.6.1.5.5.7.3.9';

    /**
     * @param  int|null  $at  the epoch to judge validity at — a trusted timestamp's time (SPEC-017); null = now
     * @param  string|null  $reason  why $at is what it is, for the `.expired` message ("no timestamp", "the timestamp's TSA is not trusted", …)
     * @return list<ValidationStatus>
     */
    public function check(Manifest $manifest, ?TrustSettings $settings = null, ?int $at = null, ?string $reason = null): array
    {
        $url = sprintf('self#jumbf=/c2pa/%s/c2pa.signature', $manifest->label);
        try {
            $chain = CoseSign1::fromBytes($manifest->signatureBytes())->chain;
            if ($chain === []) {
                return [new ValidationStatus(StatusCode::SigningCredentialInvalid, $url, 'x5chain holds no certificate')];
            }
            $leaf = Certificate::fromDer($chain[0]->bytes);
        } catch (CoseException $e) {
            return [new ValidationStatus($e->status, $url, $e->getMessage())];
        } catch (TrustException $e) {
            return [new ValidationStatus(StatusCode::SigningCredentialInvalid, $url, sprintf('the signing certificate could not be read: %s', $e->getMessage()))];
        }

        return $this->checkLeaf($leaf, $settings, $at, $url, reason: $reason);
    }

    /**
     * The profile on one certificate — the seam for the rules no re-signed file can show (SPEC-015 AC4, AC6).
     *
     * @param  list<string>|null  $ekus  non-null replaces the accepted EKU list (built-in + trust_config) — SPEC-017: a TSA needs timeStamping alone
     * @param  string|null  $reason  why $at is what it is, for the `.expired` message
     * @return list<ValidationStatus>
     */
    public function checkLeaf(Certificate $leaf, ?TrustSettings $settings, ?int $at, string $url, ?array $ekus = null, ?string $reason = null): array
    {
        $faults = [];
        $invalid = static fn (string $reason): ValidationStatus => new ValidationStatus(StatusCode::SigningCredentialInvalid, $url, 'signing certificate invalid: '.$reason);

        // 1. an end-entity
        if ($leaf->isCa) {
            $faults[] = $invalid(sprintf('%s is a CA certificate (basicConstraints CA:TRUE); a C2PA signing certificate is an end-entity', $leaf->subjectCn()));
        }
        // 2. X.509 v3
        if ($leaf->version !== 3) {
            $faults[] = $invalid(sprintf('X.509 version %d; a C2PA signing certificate is version 3', $leaf->version));
        }
        // 3. validity at the signing time: a trusted timestamp's time, else now (C2PA 2.4 §14.6.1; SPEC-017)
        $time = $at ?? time();
        if ($time < $leaf->validFrom || $time > $leaf->validTo) {
            $faults[] = new ValidationStatus(StatusCode::SigningCredentialExpired, $url, sprintf(
                'signing certificate %s at %s: valid from %s to %s, checked at %s (%s)',
                $time < $leaf->validFrom ? 'not yet valid' : 'expired',
                gmdate('Y-m-d\TH:i:s\Z', $time),
                gmdate('Y-m-d\TH:i:s\Z', $leaf->validFrom),
                gmdate('Y-m-d\TH:i:s\Z', $leaf->validTo),
                $at === null ? 'now' : "the timestamp's time",
                $reason ?? ($at === null ? 'no timestamp' : 'from a trusted timestamp'),
            ));
        }
        // 4. the signature algorithm
        if (! in_array($leaf->signatureAlgorithm, self::SIGNATURE_ALGORITHMS, true)) {
            $faults[] = $invalid(sprintf('signature algorithm %s is not one of %s (C2PA 2.4 §14.5)', $leaf->signatureAlgorithm, implode(', ', self::SIGNATURE_ALGORITHMS)));
        }
        // 5. the key
        $faults = [...$faults, ...array_map($invalid, $this->keyFaults($leaf))];
        // 6. KeyUsage, as c2pa-rs keeps it
        if ($leaf->keyUsage === null) {
            $faults[] = $invalid('no KeyUsage extension; a C2PA signing certificate carries one with digitalSignature');
        } else {
            $digital = in_array('Digital Signature', $leaf->keyUsage, true);
            $certSign = in_array('Certificate Sign', $leaf->keyUsage, true);
            $nonRepudiation = in_array('Non Repudiation', $leaf->keyUsage, true);
            if ($digital && $certSign && ! $leaf->isCa) {
                $faults[] = $invalid('KeyUsage carries Digital Signature together with Certificate Sign on an end-entity certificate');
            } elseif (! $digital && ! $certSign && ! $nonRepudiation) {
                $faults[] = $invalid(sprintf('KeyUsage (%s) permits no signing: neither Digital Signature nor Non Repudiation', implode(', ', $leaf->keyUsage)));
            }
        }
        // 7. ExtendedKeyUsage
        $faults = [...$faults, ...array_map($invalid, $this->ekuFaults($leaf, $settings, $ekus))];
        // 8. AuthorityKeyIdentifier
        if (! $leaf->hasAuthorityKeyIdentifier) {
            $faults[] = $invalid('no AuthorityKeyIdentifier extension');
        }

        return $faults;
    }

    /** @return list<string> */
    private function keyFaults(Certificate $leaf): array
    {
        return match ($leaf->keyType) {
            'EC' => in_array($leaf->curve, self::CURVES, true) ? [] : [sprintf('EC key on %s; C2PA 2.4 §14.5 allows %s', $leaf->curve ?? '(unknown curve)', implode(', ', self::CURVES))],
            'RSA' => $leaf->keyBits >= 2048 ? [] : [sprintf('RSA key of %d bits; C2PA 2.4 §14.5 requires at least 2048', $leaf->keyBits)],
            'Ed25519' => [],
            default => [sprintf('key of type %s (%d bits); C2PA 2.4 §14.5 allows EC on P-256/384/521, RSA of 2048 bits or more, Ed25519', $leaf->keyType, $leaf->keyBits)],
        };
    }

    /**
     * @param  list<string>|null  $override  the accepted list when given (SPEC-017: the TSA's)
     * @return list<string>
     */
    private function ekuFaults(Certificate $leaf, ?TrustSettings $settings, ?array $override = null): array
    {
        $ekus = $leaf->extendedKeyUsage;
        if ($ekus === null) {
            return $leaf->isCa ? [] : ['no ExtendedKeyUsage extension on an end-entity certificate'];
        }
        if (in_array(Certificate::EKU_ANY, $ekus, true)) {
            return ['ExtendedKeyUsage carries anyExtendedKeyUsage, which C2PA 2.4 §14.5 forbids'];
        }
        $accepted = $override ?? [...self::BUILT_IN_EKUS, ...($settings === null ? [] : $settings->trustConfig)];
        if (array_intersect($ekus, $accepted) === []) {
            return [sprintf('ExtendedKeyUsage (%s) holds none of the accepted values (%s)', implode(', ', $leaf->extendedKeyUsageNames()), implode(', ', $accepted))];
        }
        $timeStamping = in_array(self::EKU_TIME_STAMPING, $ekus, true);
        $ocsp = in_array(self::EKU_OCSP_SIGNING, $ekus, true);
        if ($timeStamping && $ocsp) {
            return ['ExtendedKeyUsage carries both OCSP Signing and Time Stamping'];
        }
        if ($timeStamping || $ocsp) {
            $others = array_values(array_filter($ekus, static fn (string $oid): bool => $oid !== self::EKU_TIME_STAMPING && $oid !== self::EKU_OCSP_SIGNING));
            if ($others !== []) {
                return [sprintf('ExtendedKeyUsage combines %s with %s; a time-stamping or OCSP certificate carries nothing else', $timeStamping ? 'Time Stamping' : 'OCSP Signing', implode(', ', $leaf->extendedKeyUsageNames()))];
            }
        }

        return [];
    }
}
