<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Timestamp;

use Provemark\C2paVerifier\Asn1\Asn1Exception;
use Provemark\C2paVerifier\Asn1\Der;
use Provemark\C2paVerifier\Asn1\DerReader;
use Provemark\C2paVerifier\Asn1\TagClass;

/**
 * A timestamp token as data (SPEC-016): the value of a `sigTst` header (a
 * `TimeStampResp`, RFC 3161 §2.4.2) or of a `sigTst2` header (the
 * `TimeStampToken` itself, a CMS `ContentInfo`), read either way from
 * either header — the first child tells them apart. The response must be
 * granted; the ContentInfo must be signedData; then SignedData and its
 * TSTInfo follow. Reads only: SPEC-017 verifies.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class TimeStampToken
{
    public const OID_SIGNED_DATA = '1.2.840.113549.1.7.2';

    /** @var array<int, string> RFC 3161 §2.4.2 PKIStatus */
    public const STATUS_NAMES = [
        0 => 'granted',
        1 => 'grantedWithMods',
        2 => 'rejection',
        3 => 'waiting',
        4 => 'revocationWarning',
        5 => 'revocationNotification',
    ];

    /**
     * @param  int|null  $responseStatus  the PKIStatus when the value was a TimeStampResp, else null
     */
    public function __construct(
        public SignedData $signedData,
        public TstInfo $tstInfo,
        public ?int $responseStatus,
    ) {}

    /**
     * @throws TimestampException
     */
    public static function fromHeaderValue(string $bytes, ?DerReader $reader = null): self
    {
        $reader ??= new DerReader;
        try {
            $root = $reader->read($bytes);
        } catch (Asn1Exception $e) {
            throw new TimestampException('timestamp token: '.$e->getMessage(), 0, $e);
        }
        try {
            $children = $root->sequence();
            if ($children === []) {
                throw new TimestampException('timestamp token: the outer SEQUENCE is empty');
            }
            $status = null;
            $contentInfo = $root;
            if ($children[0]->is(TagClass::Universal, Der::SEQUENCE)) {
                // TimeStampResp { status PKIStatusInfo, timeStampToken TimeStampToken OPTIONAL }
                $status = self::status($children[0]);
                if (! isset($children[1])) {
                    throw new TimestampException(sprintf('TimeStampResp with status %d (%s) carries no token', $status, self::STATUS_NAMES[$status] ?? '?'));
                }
                $contentInfo = $children[1];
                $children = $contentInfo->sequence();
            }
            // ContentInfo { contentType OID, content [0] EXPLICIT }
            $contentType = ($children[0] ?? null)?->oid();
            if ($contentType !== self::OID_SIGNED_DATA) {
                throw new TimestampException(sprintf('ContentInfo is %s, not signedData (%s)', $contentType ?? 'empty', self::OID_SIGNED_DATA));
            }
            if (! isset($children[1])) {
                throw new TimestampException('ContentInfo has no content');
            }
            $signedData = SignedData::fromDer($children[1]->tagged(0)->child(0));
        } catch (Asn1Exception $e) {
            throw new TimestampException('timestamp token: '.$e->getMessage(), 0, $e);
        }
        $tstInfo = TstInfo::fromDer($signedData->eContent, $reader);

        return new self($signedData, $tstInfo, $status);
    }

    /** PKIStatusInfo { status INTEGER, statusString PKIFreeText OPTIONAL, failInfo BIT STRING OPTIONAL }: 0 and 1 pass, anything else is a refusal naming the reason. */
    private static function status(Der $statusInfo): int
    {
        $parts = $statusInfo->sequence();
        if ($parts === []) {
            throw new TimestampException('PKIStatusInfo is empty');
        }
        $status = (int) $parts[0]->integer();
        if ($status === 0 || $status === 1) {
            return $status;
        }
        $texts = [];
        if (isset($parts[1]) && $parts[1]->is(TagClass::Universal, Der::SEQUENCE)) {
            foreach ($parts[1]->sequence() as $text) {
                $texts[] = $text->is(TagClass::Universal, Der::UTF8_STRING) ? $text->contents : $text->describe();
            }
        }
        throw new TimestampException(sprintf(
            'TimeStampResp status %d (%s) is not granted%s',
            $status,
            self::STATUS_NAMES[$status] ?? 'unknown',
            $texts === [] ? '' : ': '.implode('; ', $texts),
        ));
    }
}
