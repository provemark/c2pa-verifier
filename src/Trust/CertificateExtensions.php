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
     */
    private function __construct(
        public array $extensions,
        public array $subjectRdns,
        public array $emails,
        public ?NameConstraints $nameConstraints,
    ) {}

    /** @throws TrustException when the DER does not hold a readable tbsCertificate */
    public static function fromDer(string $der): self
    {
        try {
            // TBSCertificate ::= SEQUENCE { [0] version OPTIONAL, serialNumber, signature, issuer, validity,
            //   subject, subjectPublicKeyInfo, [1] issuerUID OPTIONAL, [2] subjectUID OPTIONAL, [3] extensions OPTIONAL }
            $tbs = (new DerReader)->read($der)->element(0);
            $fields = $tbs->sequence();
            $versioned = $fields !== [] && $fields[0]->is(TagClass::ContextSpecific, 0);
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

            return new self($extensions, $rdns, array_values(array_unique($emails)), $constraints);
        } catch (Asn1Exception $e) {
            throw new TrustException(sprintf('the extensions or names of a certificate of %d bytes could not be read: %s', strlen($der), $e->getMessage()));
        }
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

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
