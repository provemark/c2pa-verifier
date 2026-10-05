<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

/**
 * The container format from the first bytes of the stream (SPEC-013): JPEG's
 * SOI, PNG's signature, RIFF's header with the WEBP or WAVE form type
 * (SPEC-055), ISOBMFF's `ftyp`, MP3 (SPEC-056) and FLAC (SPEC-057). For MP3 and FLAC the detector reads
 * past the twelve-byte probe: for a tagless file the second MPEG frame header,
 * where the first frame's length says; for a tagged one each tag header, a
 * small probe for zero padding after it, and four bytes where the audio should
 * begin. Anything else is null — an unknown format is an error for the caller,
 * never a guess; RF64 and every other RIFF form included. The stream is rewound
 * afterwards.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class FormatDetector
{
    public const PROBE_LENGTH = 12;

    /**
     * @param  resource  $stream  readable and seekable
     * @return 'jpeg'|'png'|'webp'|'wav'|'avi'|'isobmff'|'mp3'|'flac'|null
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
        if (strlen($head) === self::PROBE_LENGTH && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'AVI ') {
            return 'avi';   // SPEC-058
        }
        // ISOBMFF (SPEC-026): MP4, MOV, AVIF and HEIC all open with a `ftyp` box, and
        // the brand that follows is not read — a file that declares `ftyp` and carries
        // a C2PA `uuid` box is one this verifier can read whatever its brand says.
        if (strlen($head) === self::PROBE_LENGTH && substr($head, 4, 4) === 'ftyp') {
            return 'isobmff';
        }
        // MP3 (SPEC-056): MPEG audio from the first byte, two frame headers in a row
        // (amendment 2: one header alone matched a UTF-16 text file); or an ID3v2 tag,
        // then zero padding and further tags, then MPEG audio. ID3 alone is not enough:
        // FLAC and AAC carry the same tag.
        if (self::isMpegFrame($head)) {
            $mp3 = self::mpegAudioAt($stream, 0);
            rewind($stream);

            return $mp3 ? 'mp3' : null;
        }
        // FLAC (SPEC-057): the stream marker at the start, or after the ID3 tag(s) C2PA puts in front
        if (str_starts_with($head, 'fLaC')) {
            return 'flac';
        }
        if (Id3ManifestStoreExtractor::header($head, alsoVersion2: true) !== null) {
            $audio = self::audioAfterTags($stream);
            rewind($stream);

            return $audio;
        }

        return null;
    }

    /** How far zero padding after an ID3 tag is skipped, and how many further tags (amendment 2). */
    private const MAX_PADDING = 65536;

    private const MAX_TAGS = 8;

    /**
     * What follows the ID3 tag at offset 0, after any zero padding and further
     * ID3v2 tags (SPEC-056 AC16): MPEG audio is `mp3`, a FLAC stream marker is
     * `flac` (SPEC-057 AC4), anything else null.
     *
     * @param  resource  $stream
     * @return 'mp3'|'flac'|null
     */
    private static function audioAfterTags($stream): ?string
    {
        if (fseek($stream, 0, SEEK_END) !== 0) {
            return null;
        }
        $fileEnd = (int) ftell($stream);
        $position = 0;
        for ($tags = 0; $tags <= self::MAX_TAGS; $tags++) {
            if (fseek($stream, $position) !== 0) {
                return null;
            }
            $header = Id3ManifestStoreExtractor::header(Read::upTo($stream, 10), alsoVersion2: true);
            if ($header === null) {
                break;
            }
            $position += $header['next'];   // relative to the tag's own start
            if ($position > $fileEnd) {
                return 'mp3';   // a tag that runs past the end of the file: the extractor says why (SPEC-056 AC23)
            }
            $position += self::zeroPadding($stream, $position);
        }

        // after a tag one frame header is enough (AC13); two are asked only of a file with no tag (AC17)
        $next = fseek($stream, $position) === 0 ? Read::upTo($stream, 4) : '';

        return match (true) {
            self::isMpegFrame($next) => 'mp3',
            $next === 'fLaC' => 'flac',
            default => null,
        };
    }

    /**
     * How many zero bytes follow $position, up to MAX_PADDING: a small probe first,
     * read further only while it is all zeros.
     *
     * @param  resource  $stream
     */
    private static function zeroPadding($stream, int $position): int
    {
        $zeros = 0;
        $probe = 16;
        while ($zeros < self::MAX_PADDING && fseek($stream, $position + $zeros) === 0) {
            $chunk = Read::upTo($stream, min($probe, self::MAX_PADDING - $zeros));
            $run = strspn($chunk, "\0");
            $zeros += $run;
            if ($chunk === '' || $run < strlen($chunk)) {
                break;
            }
            $probe = min($probe * 16, 16384);
        }

        return $zeros;
    }

    /**
     * Whether two MPEG audio frame headers follow each other at $position: the
     * second where the first frame's length says (SPEC-056 AC17).
     *
     * @param  resource  $stream
     */
    private static function mpegAudioAt($stream, int $position): bool
    {
        if (fseek($stream, $position) !== 0) {
            return false;
        }
        $first = Read::upTo($stream, 4);
        $length = self::isMpegFrame($first) ? self::mpegFrameLength($first) : null;
        if ($length === null || fseek($stream, $position + $length) !== 0) {
            return false;
        }

        return self::isMpegFrame(Read::upTo($stream, 4));
    }

    /**
     * The length of an MPEG audio frame from its header (ISO/IEC 11172-3, 13818-3,
     * and MPEG 2.5); null for the free-format bitrate, whose length the header does
     * not give.
     */
    private static function mpegFrameLength(string $header): ?int
    {
        $b1 = ord($header[1]);
        $b2 = ord($header[2]);
        $version = ($b1 >> 3) & 0x03;   // 0: 2.5, 2: 2, 3: 1
        $layer = 4 - (($b1 >> 1) & 0x03);   // 1, 2 or 3
        $bitrateIndex = $b2 >> 4;
        $rateIndex = ($b2 >> 2) & 0x03;
        if ($bitrateIndex === 0 || $bitrateIndex === 15 || $rateIndex === 3) {
            return null;   // free format gives no length; 15 and 3 are reserved (isMpegFrame refuses them too)
        }
        $mpeg1 = $version === 3;
        $bitrates = match (true) {
            $mpeg1 && $layer === 1 => [32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448],
            $mpeg1 && $layer === 2 => [32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384],
            $mpeg1 => [32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320],
            $layer === 1 => [32, 48, 56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 224, 256],
            default => [8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
        };
        $rates = match ($version) {
            3 => [44100, 48000, 32000],
            2 => [22050, 24000, 16000],
            default => [11025, 12000, 8000],
        };
        $bitrate = $bitrates[$bitrateIndex - 1] * 1000;
        $rate = $rates[$rateIndex];
        $padding = ($b2 >> 1) & 0x01;

        return match (true) {
            $layer === 1 => (intdiv(12 * $bitrate, $rate) + $padding) * 4,
            $layer === 3 && ! $mpeg1 => intdiv(72 * $bitrate, $rate) + $padding,
            default => intdiv(144 * $bitrate, $rate) + $padding,
        };
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
        // the seekable flag first: rewind() on a pipe prints a PHP warning before it fails (SPEC-043 AC12)
        if (! is_resource($stream) || ! stream_get_meta_data($stream)['seekable'] || ! rewind($stream)) {
            throw new \InvalidArgumentException('FormatDetector needs a seekable stream resource');
        }
        $head = Read::upTo($stream, self::PROBE_LENGTH);
        rewind($stream);

        return $head;
    }
}
