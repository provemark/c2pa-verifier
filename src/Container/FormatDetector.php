<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

/**
 * The container format from the first twelve bytes, nothing else read
 * (SPEC-013): JPEG's SOI, PNG's signature, RIFF's header with the WEBP or
 * WAVE form type (SPEC-055), ISOBMFF's `ftyp`. Anything else is null — RF64
 * and every other RIFF form included — an unknown format is an error for the
 * caller, never a guess. The stream is rewound afterwards.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class FormatDetector
{
    public const PROBE_LENGTH = 12;

    /**
     * @param  resource  $stream  readable and seekable
     * @return 'jpeg'|'png'|'webp'|'wav'|'isobmff'|'mp3'|null
     */
    public function detect($stream): ?string
    {
        $head = $this->head($stream);
        if (str_starts_with($head, "\xFF\xD8")) {
            return 'jpeg';
        }
        if (str_starts_with($head, "\x89PNG\x0D\x0A\x1A\x0A")) {
            return 'png';
        }
        if (strlen($head) === self::PROBE_LENGTH && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (strlen($head) === self::PROBE_LENGTH && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE') {
            return 'wav';   // SPEC-055; RF64 (`RF64`, files over 4 GB) is not read, as c2patool does not
        }
        // ISOBMFF (SPEC-026): MP4, MOV, AVIF and HEIC all open with a `ftyp` box, and
        // the brand that follows is not read — a file that declares `ftyp` and carries
        // a C2PA `uuid` box is one this verifier can read whatever its brand says.
        if (strlen($head) === self::PROBE_LENGTH && substr($head, 4, 4) === 'ftyp') {
            return 'isobmff';
        }
        // MP3 (SPEC-056): an ID3v2 tag followed by MPEG audio, or MPEG audio from the
        // first byte. ID3 alone is not enough: FLAC and AAC carry the same tag.
        if (self::isMpegFrame($head)) {
            return 'mp3';
        }
        if (strlen($head) === self::PROBE_LENGTH && str_starts_with($head, 'ID3') && preg_match('/[\x80-\xFF]/', substr($head, 6, 4)) !== 1) {
            $size = 0;
            foreach (str_split(substr($head, 6, 4)) as $byte) {
                $size = ($size << 7) | ord($byte);
            }
            $audio = 10 + $size + ((ord($head[5]) & 0x10) !== 0 ? 10 : 0);   // after the tag, and its footer when flagged
            $next = fseek($stream, $audio) === 0 ? Read::upTo($stream, 4) : '';
            rewind($stream);

            return self::isMpegFrame($next) ? 'mp3' : null;
        }

        return null;
    }

    /**
     * An MPEG audio frame header (ISO/IEC 11172-3): eleven sync bits, then a
     * version, layer, bitrate and sample rate that are not reserved. JPEG's
     * FF D8 is not one: D8 lacks the three high bits.
     */
    public static function isMpegFrame(string $bytes): bool
    {
        if (strlen($bytes) < 3 || $bytes[0] !== "\xFF") {
            return false;
        }
        $b1 = ord($bytes[1]);
        $b2 = ord($bytes[2]);

        return ($b1 & 0xE0) === 0xE0
            && (($b1 >> 3) & 0x03) !== 0x01   // version 01 is reserved
            && (($b1 >> 1) & 0x03) !== 0x00   // layer 00 is reserved
            && ($b2 >> 4) !== 0x0F            // bitrate index 1111 is bad
            && (($b2 >> 2) & 0x03) !== 0x03;  // sample rate 11 is reserved
    }

    /**
     * The first bytes, for the detection and for the message when it fails.
     *
     * @param  resource  $stream
     */
    public function head($stream): string
    {
        if (! is_resource($stream) || ! rewind($stream)) {
            throw new \InvalidArgumentException('FormatDetector needs a seekable stream resource');
        }
        $head = Read::upTo($stream, self::PROBE_LENGTH);
        rewind($stream);

        return $head;
    }
}
