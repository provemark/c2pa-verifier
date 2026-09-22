<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Timestamp;

use Provemark\C2paVerifier\Asn1\Der;
use Provemark\C2paVerifier\Asn1\TagClass;

/**
 * RFC 5652 §5.3 `SignerInfo`, the one signer of a timestamp token
 * (SPEC-016 AC4): who signed (`sid`), with which digest and signature
 * algorithm, over which signed attributes, and the signature itself.
 * `signedAttributesForVerification()` gives the bytes the signature was
 * made over — the same attributes with the `[0]` tag replaced by `SET`
 * (§5.4). `messageDigest` and `contentType` are required (§11.1, §11.2);
 * every other attribute is kept by OID and never refused.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class SignerInfo
{
    public const OID_CONTENT_TYPE = '1.2.840.113549.1.9.3';

    public const OID_MESSAGE_DIGEST = '1.2.840.113549.1.9.4';

    public const OID_SIGNING_TIME = '1.2.840.113549.1.9.5';

    public const OID_RSA_PSS = '1.2.840.113549.1.1.10';

    /**
     * @param  string|null  $sidIssuer  issuerAndSerialNumber: the issuer Name's DER
     * @param  string|null  $sidSerial  issuerAndSerialNumber: decimal
     * @param  string|null  $sidSubjectKeyId  the other choice
     * @param  string  $digestAlgorithm  OID
     * @param  string  $signedAttributes  the [0] element, as encoded
     * @param  array<string, string>  $otherAttributes  OID => the Attribute's DER
     * @param  string  $signatureAlgorithm  OID
     * @param  string|null  $signatureParameters  RSA-PSS: the parameters' DER
     * @param  list<string>  $attributeEncodings  every signed Attribute's DER in the order written (for the DER-canonical SET)
     */
    public function __construct(
        public int $version,
        public ?string $sidIssuer,
        public ?string $sidSerial,
        public ?string $sidSubjectKeyId,
        public string $digestAlgorithm,
        public string $signedAttributes,
        public string $messageDigest,
        public ?int $signingTime,
        public string $contentTypeAttribute,
        public array $otherAttributes,
        public string $signatureAlgorithm,
        public ?string $signatureParameters,
        public string $signature,
        public array $attributeEncodings = [],
    ) {}

    /**
     * What the signature covers (RFC 5652 §5.4): the *DER* encoding of the
     * SET OF Attribute — the `[0]` tag replaced by `SET`, and the attributes
     * in DER's SET OF order (X.690 §11.6: ascending by encoded octets, the
     * shorter padded with zeros). Every TSA measured until step 43 wrote
     * them sorted, so this equalled the re-tag; `c2pa-ts` writes them
     * unsorted and signs the sorted form (SPEC-017 amendment 3).
     */
    public function signedAttributesForVerification(): string
    {
        $encodings = $this->attributeEncodings;
        usort($encodings, static function (string $a, string $b): int {
            $n = max(strlen($a), strlen($b));

            return strcmp(str_pad($a, $n, "\0"), str_pad($b, $n, "\0"));
        });
        $body = implode('', $encodings);

        return "\x31".self::length(strlen($body)).$body;
    }

    private static function length(int $n): string
    {
        if ($n < 128) {
            return pack('C', $n);
        }
        $bytes = ltrim(pack('N', $n), "\0");

        return pack('C', 0x80 | strlen($bytes)).$bytes;
    }

    /**
     * @param  string  $eContentType  the SignedData's, which the contentType attribute must repeat
     *
     * @throws TimestampException
     */
    public static function fromDer(Der $der, string $eContentType): self
    {
        $fields = $der->sequence();
        if (count($fields) < 5) {
            throw new TimestampException(sprintf('SignerInfo at offset %d has %d fields; five are required', $der->offset, count($fields)));
        }
        $version = (int) $fields[0]->integer();

        // sid: IssuerAndSerialNumber (a SEQUENCE) or [0] SubjectKeyIdentifier
        $sid = $fields[1];
        $sidIssuer = $sidSerial = $sidSubjectKeyId = null;
        if ($sid->is(TagClass::Universal, Der::SEQUENCE)) {
            $sidIssuer = $sid->child(0)->encoded();
            $sidSerial = $sid->child(1)->integer();
        } elseif ($sid->is(TagClass::ContextSpecific, 0)) {
            $sidSubjectKeyId = $sid->contents;
        } else {
            throw new TimestampException(sprintf('SignerInfo sid at offset %d is %s, neither issuerAndSerialNumber nor [0] subjectKeyIdentifier', $sid->offset, $sid->describe()));
        }

        $digestAlgorithm = $fields[2]->sequence()[0]->oid();

        $i = 3;
        if (! $fields[$i]->is(TagClass::ContextSpecific, 0)) {   // the fourth field exists: five are checked above
            throw new TimestampException('SignerInfo has no signedAttrs; a timestamp token signs its TSTInfo through the messageDigest attribute (RFC 3161 §2.4.2)');
        }
        $signedAttrs = $fields[$i];
        $i++;
        $messageDigest = null;
        $signingTime = null;
        $contentType = null;
        $other = [];
        $encodings = [];
        foreach ($signedAttrs->children ?? [] as $attribute) {
            $encodings[] = $attribute->encoded();
            $parts = $attribute->sequence();
            if (count($parts) !== 2) {
                throw new TimestampException(sprintf('Attribute at offset %d has %d fields, not type and values', $attribute->offset, count($parts)));
            }
            $oid = $parts[0]->oid();
            $values = $parts[1]->set();
            switch ($oid) {
                case self::OID_MESSAGE_DIGEST:
                    $messageDigest = self::single($values, $attribute, 'messageDigest')->octets();
                    break;
                case self::OID_SIGNING_TIME:
                    $signingTime = self::single($values, $attribute, 'signingTime')->time();
                    break;
                case self::OID_CONTENT_TYPE:
                    $contentType = self::single($values, $attribute, 'contentType')->oid();
                    break;
                default:
                    $other[$oid] = $attribute->encoded();
            }
        }
        if ($messageDigest === null) {
            throw new TimestampException('signedAttrs has no messageDigest attribute (RFC 5652 §11.2 requires it)');
        }
        if ($contentType === null) {
            throw new TimestampException('signedAttrs has no contentType attribute (RFC 5652 §11.1 requires it)');
        }
        if ($contentType !== $eContentType) {
            throw new TimestampException(sprintf('the contentType attribute (%s) is not the eContentType (%s)', $contentType, $eContentType));
        }

        if (count($fields) <= $i + 1) {
            throw new TimestampException(sprintf('SignerInfo at offset %d ends before signatureAlgorithm and signature', $der->offset));
        }
        $signatureAlgorithmParts = $fields[$i]->sequence();
        $signatureAlgorithm = $signatureAlgorithmParts[0]->oid();
        $signatureParameters = $signatureAlgorithm === self::OID_RSA_PSS && isset($signatureAlgorithmParts[1]) ? $signatureAlgorithmParts[1]->encoded() : null;
        $signature = $fields[$i + 1]->octets();

        return new self($version, $sidIssuer, $sidSerial, $sidSubjectKeyId, $digestAlgorithm, $signedAttrs->encoded(), $messageDigest, $signingTime, $contentType, $other, $signatureAlgorithm, $signatureParameters, $signature, $encodings);
    }

    /** @param list<Der> $values */
    private static function single(array $values, Der $attribute, string $name): Der
    {
        if (count($values) !== 1) {
            throw new TimestampException(sprintf('the %s attribute at offset %d has %d values; exactly one is allowed', $name, $attribute->offset, count($values)));
        }

        return $values[0];
    }
}
