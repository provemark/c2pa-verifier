<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Timestamp;

use Provemark\C2paVerifier\Asn1\Asn1Exception;
use Provemark\C2paVerifier\Asn1\Der;
use Provemark\C2paVerifier\Asn1\DerReader;
use Provemark\C2paVerifier\Asn1\TagClass;

/**
 * RFC 5652 §5.1 `SignedData` as a timestamp token carries it (SPEC-016
 * AC4): the digest algorithms, the encapsulated `TSTInfo` (its type must be
 * id-ct-TSTInfo and its content present), the certificates (the
 * `certificate` choice only), and exactly one `SignerInfo` (RFC 3161
 * §2.4.2). CRLs are ignored.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class SignedData
{
    public const OID_TSTINFO = '1.2.840.113549.1.9.16.1.4';

    public const OID_SUBJECT_KEY_IDENTIFIER = '2.5.29.14';

    /**
     * @param  list<string>  $digestAlgorithms  OIDs
     * @param  string  $eContent  the TSTInfo's DER
     * @param  list<string>  $certificates  DER, in the token's order
     */
    public function __construct(
        public int $version,
        public array $digestAlgorithms,
        public string $eContentType,
        public string $eContent,
        public array $certificates,
        public SignerInfo $signerInfo,
    ) {}

    /**
     * The certificate the SignerInfo's sid names — by issuer Name and serial,
     * or by subjectKeyIdentifier — or null when none matches. The token's
     * order says nothing: DigiCert puts the signer first, Truepic its root.
     */
    public function signerCertificate(?DerReader $reader = null): ?string
    {
        $reader ??= new DerReader;
        $sid = $this->signerInfo;
        foreach ($this->certificates as $der) {
            try {
                $identity = self::identity($reader->read($der));
            } catch (Asn1Exception $e) {
                throw new TimestampException('a certificate in the token: '.$e->getMessage(), 0, $e);
            }
            if ($sid->sidSubjectKeyId !== null) {
                if ($identity['subjectKeyId'] !== null && hash_equals($identity['subjectKeyId'], $sid->sidSubjectKeyId)) {
                    return $der;
                }
            } elseif ($sid->sidIssuer !== null && hash_equals($identity['issuer'], $sid->sidIssuer) && $identity['serial'] === $sid->sidSerial) {
                return $der;
            }
        }

        return null;
    }

    /**
     * What a TBSCertificate says about itself (RFC 5280 §4.1): issuer Name
     * (as DER), serial (decimal) and, for v3, the subjectKeyIdentifier.
     *
     * @return array{issuer: string, serial: string, subjectKeyId: ?string}
     */
    private static function identity(Der $certificate): array
    {
        $tbs = $certificate->child(0)->sequence();
        $i = 0;
        if (isset($tbs[0]) && $tbs[0]->is(TagClass::ContextSpecific, 0)) {
            $i = 1;   // [0] EXPLICIT version, absent on v1
        }
        $serial = $certificate->child(0)->child($i)->integer();
        $issuer = $certificate->child(0)->child($i + 2)->encoded();
        $subjectKeyId = null;
        foreach ($tbs as $field) {
            if (! $field->is(TagClass::ContextSpecific, 3)) {
                continue;
            }
            foreach ($field->child(0)->sequence() as $extension) {
                $parts = $extension->sequence();
                if ($parts[0]->oid() === self::OID_SUBJECT_KEY_IDENTIFIER) {
                    $value = $parts[count($parts) - 1];
                    // extnValue is an OCTET STRING wrapping the DER of the extension's type: for SKI, an OCTET STRING
                    $subjectKeyId = (new DerReader)->read($value->octets())->octets();
                }
            }
        }

        return ['issuer' => $issuer, 'serial' => $serial, 'subjectKeyId' => $subjectKeyId];
    }

    /**
     * @param  Der  $der  the SignedData SEQUENCE (the content of the ContentInfo's [0])
     *
     * @throws TimestampException
     */
    public static function fromDer(Der $der): self
    {
        $fields = $der->sequence();
        if (count($fields) < 4) {
            throw new TimestampException(sprintf('SignedData at offset %d has %d fields; version, digestAlgorithms, encapContentInfo and signerInfos are required', $der->offset, count($fields)));
        }
        $version = (int) $fields[0]->integer();
        $digestAlgorithms = [];
        foreach ($fields[1]->set() as $algorithm) {
            $digestAlgorithms[] = $algorithm->sequence()[0]->oid();
        }

        $encap = $fields[2]->sequence();
        $eContentType = $encap[0]->oid();
        if ($eContentType !== self::OID_TSTINFO) {
            throw new TimestampException(sprintf('eContentType is %s, not id-ct-TSTInfo (%s)', $eContentType, self::OID_TSTINFO));
        }
        if (! isset($encap[1])) {
            throw new TimestampException('encapContentInfo has no eContent; a timestamp token carries its TSTInfo');
        }
        $eContent = $encap[1]->tagged(0)->child(0)->octets();

        $i = 3;
        $certificates = [];
        if ($fields[$i]->is(TagClass::ContextSpecific, 0)) {   // the fourth field exists: checked above
            foreach ($fields[$i]->children ?? [] as $choice) {
                if (! $choice->is(TagClass::Universal, Der::SEQUENCE)) {
                    throw new TimestampException(sprintf('certificates holds a %s choice at offset %d; only certificate (a SEQUENCE) is accepted', $choice->describe(), $choice->offset));
                }
                $certificates[] = $choice->encoded();
            }
            $i++;
        }
        if ($certificates === []) {
            throw new TimestampException('SignedData carries no certificates; the TSA certificate must be in the token');
        }
        if (count($fields) > $i && $fields[$i]->is(TagClass::ContextSpecific, 1)) {
            $i++;   // crls, ignored
        }
        if (count($fields) <= $i) {
            throw new TimestampException('SignedData has no signerInfos');
        }
        $signerInfos = $fields[$i]->set();
        if (count($signerInfos) !== 1) {
            throw new TimestampException(sprintf('SignedData must hold exactly one SignerInfo, found %d (RFC 3161 §2.4.2)', count($signerInfos)));
        }
        if (count($fields) > $i + 1) {
            throw new TimestampException(sprintf('SignedData has an unexpected %s at offset %d after signerInfos', $fields[$i + 1]->describe(), $fields[$i + 1]->offset));
        }

        return new self($version, $digestAlgorithms, $eContentType, $eContent, $certificates, SignerInfo::fromDer($signerInfos[0], $eContentType));
    }
}
