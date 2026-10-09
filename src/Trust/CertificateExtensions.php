<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

use Provemark\C2paVerifier\Asn1\Asn1Exception;
use Provemark\C2paVerifier\Asn1\Der;
use Provemark\C2paVerifier\Asn1\DerReader;
use Provemark\C2paVerifier\Asn1\TagClass;

/**
 * What a certificate says about names and extensions, read from its own DER (SPEC-046): every
 * extension with its critical flag, which openssl_x509_parse() does not report; the subject as a
 * sequence of RDNs, for name constraints (RFC 5280 §7.1); the e-mail addresses the certificate
 * carries; and its own nameConstraints, if any.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class CertificateExtensions
{
    public const OID_NAME_CONSTRAINTS = '2.5.29.30';

    private const OID_SUBJECT_ALT_NAME = '2.5.29.17';

    private const OID_EMAIL_ADDRESS = '1.2.840.113549.1.9.1';

    private const OID_RSASSA_PSS = '1.2.840.113549.1.1.10';

    private const OID_SHA1 = '1.3.14.3.2.26';

    private const OID_MGF1 = '1.2.840.113549.1.1.8';

    /** The hashes an RSASSA-PSS certificate may name, by OID (C2PA 2.4 §14.5), and the others by name for messages. */
    public const PSS_HASHES = [
        '2.16.840.1.101.3.4.2.1' => 'SHA-256',
        '2.16.840.1.101.3.4.2.2' => 'SHA-384',
        '2.16.840.1.101.3.4.2.3' => 'SHA-512',
    ];

    private const OTHER_HASHES = [
        self::OID_SHA1 => 'SHA-1',
        '2.16.840.1.101.3.4.2.4' => 'SHA-224',
        '1.2.840.113549.2.5' => 'MD5',
    ];

    /** Signature algorithms that rest on MD2, MD4, MD5 or SHA-1 (SPEC-048 scope item 1). */
    private const WEAK_SIGNATURES = [
        '1.2.840.113549.1.1.2' => 'md2WithRSAEncryption',
        '1.2.840.113549.1.1.3' => 'md4WithRSAEncryption',
        '1.2.840.113549.1.1.4' => 'md5WithRSAEncryption',
        '1.2.840.113549.1.1.5' => 'sha1WithRSAEncryption',
        '1.3.14.3.2.29' => 'sha1WithRSA',
        '1.2.840.10045.4.1' => 'ecdsa-with-SHA1',
        '1.2.840.10040.4.3' => 'dsa-with-sha1',
    ];

    /** The hashes that make an RSASSA-PSS signature weak. */
    private const WEAK_HASHES = [
        self::OID_SHA1 => 'SHA-1',
        '1.2.840.113549.2.5' => 'MD5',
        '1.2.840.113549.2.2' => 'MD2',
        '1.2.840.113549.2.4' => 'MD4',
    ];

    /**
     * The extensions this verifier understands (SPEC-046 scope item 1): those of RFC 5280 §4.2 that
     * OpenSSL recognises, and the OCSP no-check extension. A critical extension outside this list
     * makes the certificate unusable in a path (§4.2).
     *
     * @var array<string, string>
     */
    public const UNDERSTOOD = [
        '2.5.29.19' => 'basicConstraints',
        '2.5.29.15' => 'keyUsage',
        '2.5.29.37' => 'extKeyUsage',
        '2.5.29.14' => 'subjectKeyIdentifier',
        '2.5.29.35' => 'authorityKeyIdentifier',
        '2.5.29.17' => 'subjectAltName',
        '2.5.29.18' => 'issuerAltName',
        '2.5.29.32' => 'certificatePolicies',
        '2.5.29.33' => 'policyMappings',
        '2.5.29.36' => 'policyConstraints',
        '2.5.29.54' => 'inhibitAnyPolicy',
        '2.5.29.30' => 'nameConstraints',
        '2.5.29.31' => 'cRLDistributionPoints',
        '2.5.29.46' => 'freshestCRL',
        '1.3.6.1.5.5.7.1.1' => 'authorityInfoAccess',
        '1.3.6.1.5.5.7.1.11' => 'subjectInfoAccess',
        '1.3.6.1.5.5.7.48.1.5' => 'ocspNoCheck',
    ];

    /**
     * @param  list<array{oid: string, critical: bool, value: string}>  $extensions  in certificate order
     * @param  list<list<array{0: string, 1: string}>>  $subjectRdns  each RDN's attributes as [OID, normalised value], sorted
     * @param  list<string>  $emails  subjectAltName rfc822Name entries and the subject's emailAddress, lower-cased
     * @param  string  $signatureOid  the certificate's outer signatureAlgorithm (RFC 5280 §4.1.1.2)
     * @param  string|null  $pssHashOid  for RSASSA-PSS, the hash; SHA-1 when the parameter is absent (RFC 4055 §3.1)
     * @param  string|null  $pssMgf1HashOid  for RSASSA-PSS, MGF1's hash; SHA-1 when absent; the mask generation function's own OID when it is not MGF1 (SPEC-015 amendment 8)
     * @param  bool  $algorithmMatchesTbs  the outer signatureAlgorithm is byte-equal to tbsCertificate's signature (RFC 5280 §4.1.1.2)
     */
    private function __construct(
        public array $extensions,
        public array $subjectRdns,
        public array $emails,
        public ?NameConstraints $nameConstraints,
        public string $signatureOid,
        public ?string $pssHashOid,
        public ?string $pssMgf1HashOid = null,
        public bool $algorithmMatchesTbs = true,
    ) {}

    /** @throws TrustException when the DER does not hold a readable tbsCertificate */
    public static function fromDer(string $der): self
    {
        try {
            // TBSCertificate ::= SEQUENCE { [0] version OPTIONAL, serialNumber, signature, issuer, validity,
            //   subject, subjectPublicKeyInfo, [1] issuerUID OPTIONAL, [2] subjectUID OPTIONAL, [3] extensions OPTIONAL }
            $certificate = (new DerReader)->read($der);
            $tbs = $certificate->element(0);
            // Certificate ::= SEQUENCE { tbsCertificate, signatureAlgorithm AlgorithmIdentifier, signatureValue }
            $algorithm = $certificate->element(1);
            $signatureOid = $algorithm->element(0)->oid();
            $pssHashOid = null;
            $pssMgf1HashOid = null;
            if ($signatureOid === self::OID_RSASSA_PSS) {
                // RSASSA-PSS-params ::= SEQUENCE { hashAlgorithm [0] AlgorithmIdentifier DEFAULT sha1,
                //   maskGenAlgorithm [1] AlgorithmIdentifier DEFAULT mgf1SHA1, … } (RFC 4055 §3.1)
                $pssHashOid = self::OID_SHA1;
                $pssMgf1HashOid = self::OID_SHA1;
                $parameters = $algorithm->sequence()[1] ?? null;
                foreach ($parameters !== null && $parameters->is(TagClass::Universal, Der::SEQUENCE) ? $parameters->sequence() : [] as $field) {
                    if ($field->is(TagClass::ContextSpecific, 0)) {
                        $pssHashOid = $field->child(0)->element(0)->oid();
                    }
                    if ($field->is(TagClass::ContextSpecific, 1)) {
                        $mgf = $field->child(0);
                        $mgfOid = $mgf->element(0)->oid();
                        $pssMgf1HashOid = $mgfOid === self::OID_MGF1 ? $mgf->element(1)->element(0)->oid() : $mgfOid;
                    }
                }
            }
            $fields = $tbs->sequence();
            $versioned = $fields !== [] && $fields[0]->is(TagClass::ContextSpecific, 0);
            $algorithmMatchesTbs = $tbs->element($versioned ? 2 : 1)->encoded() === $algorithm->encoded();
            $subject = $tbs->element($versioned ? 5 : 4);

            $extensions = [];
            foreach ($fields as $field) {
                if (! $field->is(TagClass::ContextSpecific, 3)) {
                    continue;
                }
                foreach ($field->child(0)->sequence() as $extension) {
                    // Extension ::= SEQUENCE { extnID, critical BOOLEAN DEFAULT FALSE, extnValue OCTET STRING }
                    $parts = $extension->sequence();
                    $critical = count($parts) === 3 && $parts[1]->boolean();
                    $extensions[] = ['oid' => $extension->element(0)->oid(), 'critical' => $critical, 'value' => $extension->element(count($parts) - 1)->octets()];
                }
            }

            $rdns = self::rdns($subject);
            $emails = [];
            foreach ($rdns as $rdn) {
                foreach ($rdn as [$oid, $value]) {
                    if ($oid === self::OID_EMAIL_ADDRESS) {
                        $emails[] = $value;
                    }
                }
            }
            $constraints = null;
            foreach ($extensions as $extension) {
                if ($extension['oid'] === self::OID_SUBJECT_ALT_NAME) {
                    foreach ((new DerReader)->read($extension['value'])->sequence() as $name) {
                        if ($name->is(TagClass::ContextSpecific, 1)) {
                            $emails[] = strtolower($name->contents);
                        }
                    }
                }
                if ($extension['oid'] === self::OID_NAME_CONSTRAINTS) {
                    $constraints = NameConstraints::fromDer((new DerReader)->read($extension['value']));
                }
            }

            return new self($extensions, $rdns, array_values(array_unique($emails)), $constraints, $signatureOid, $pssHashOid, $pssMgf1HashOid, $algorithmMatchesTbs);
        } catch (Asn1Exception $e) {
            throw new TrustException(sprintf('the extensions or names of a certificate of %d bytes could not be read: %s', strlen($der), $e->getMessage()));
        }
    }

    /**
     * The name of the certificate's signature algorithm when it rests on MD2, MD4, MD5 or SHA-1, else null
     * (SPEC-048): RSASSA-PSS counts by its hash, and an absent hash is SHA-1.
     */
    public function weakHash(): ?string
    {
        if ($this->signatureOid === self::OID_RSASSA_PSS) {
            $hash = self::WEAK_HASHES[$this->pssHashOid ?? self::OID_SHA1] ?? null;

            return $hash === null ? null : sprintf('RSASSA-PSS over %s', $hash);
        }

        return self::WEAK_SIGNATURES[$this->signatureOid] ?? null;
    }

    /**
     * What the certificate's own signature algorithm breaks of the profile for RSASSA-PSS (SPEC-015 amendment 8, as
     * c2pa-rs's certificate profile reads it), and of RFC 5280 §4.1.1.2 for any algorithm; a weak hash is weakHash()'s.
     *
     * @return list<string>
     */
    public function algorithmFaults(): array
    {
        $faults = [];
        if (! $this->algorithmMatchesTbs) {
            $faults[] = 'the signatureAlgorithm differs from the signature field of tbsCertificate (RFC 5280 §4.1.1.2)';
        }
        if ($this->signatureOid !== self::OID_RSASSA_PSS || $this->weakHash() !== null) {
            return $faults;
        }
        $hash = $this->pssHashOid ?? self::OID_SHA1;
        if (! array_key_exists($hash, self::PSS_HASHES)) {
            $faults[] = sprintf('the signature is RSASSA-PSS over %s (C2PA 2.4 §14.5 allows SHA-256, SHA-384 and SHA-512)', self::hashName($hash));
        }
        $mgf1 = $this->pssMgf1HashOid ?? self::OID_SHA1;
        if ($mgf1 !== $hash) {
            $faults[] = sprintf('the RSASSA-PSS mask is MGF1 over %s, not over the PSS hash %s', self::hashName($mgf1), self::hashName($hash));
        }

        return $faults;
    }

    private static function hashName(string $oid): string
    {
        return self::PSS_HASHES[$oid] ?? self::OTHER_HASHES[$oid] ?? $oid;
    }

    /** @return list<string> the OIDs of critical extensions this verifier does not understand */
    public function unknownCritical(): array
    {
        $unknown = [];
        foreach ($this->extensions as $extension) {
            if ($extension['critical'] && ! array_key_exists($extension['oid'], self::UNDERSTOOD)) {
                $unknown[] = $extension['oid'];
            }
        }

        return $unknown;
    }

    /**
     * A Name as its RDNs (RFC 5280 §4.1.2.4): each RDN a sorted list of [OID, value], the value
     * normalised for comparison as §7.1 describes it for the string types — surrounding white space
     * removed, inner runs collapsed to one space, case folded.
     *
     * @return list<list<array{0: string, 1: string}>>
     */
    public static function rdns(Der $name): array
    {
        $rdns = [];
        foreach ($name->sequence() as $rdn) {
            $attributes = [];
            foreach ($rdn->set() as $attribute) {
                $attributes[] = [$attribute->element(0)->oid(), self::normalise($attribute->element(1))];
            }
            sort($attributes);
            $rdns[] = $attributes;
        }

        return $rdns;
    }

    private static function normalise(Der $value): string
    {
        $text = match (true) {
            $value->is(TagClass::Universal, 30) => (string) mb_convert_encoding($value->contents, 'UTF-8', 'UTF-16BE'),
            $value->is(TagClass::Universal, 28) => (string) mb_convert_encoding($value->contents, 'UTF-8', 'UTF-32BE'),
            default => $value->contents,
        };

        if (! mb_check_encoding($text, 'UTF-8')) {
            // SPEC-046 AC7: bytes that are not UTF-8 (a T61String or IA5String may hold them) are
            // compared as they are; the /u fold below returns null on them, and every such name was ''
            return $text;
        }

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
