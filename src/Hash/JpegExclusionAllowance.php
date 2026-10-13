<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Hash;

use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\StreamReader;

/**
 * JPEG's allowance (SPEC-069): an exclusion beyond the manifest store may lie
 * wholly inside the payload of an APP1 segment of the primary image whose
 * identifier is Exif or XMP (standard or extended) — what the Google Pixel 10
 * excludes, measured in step 350 — and nowhere else. The payload is what
 * follows the marker and the length field (ITU-T T.81 §B.1.1.4).
 *
 * The segments are walked from SOI to the first SOS, bounded; everything
 * from the scan on is one region. A walk that cannot reach SOS throws, and
 * the caller allows nothing.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class JpegExclusionAllowance implements ExclusionAllowance
{
    /** A bound on the walk, as SPEC-024 bounds every other; a real file has a few dozen segments before SOS. */
    public const DEFAULT_MAX_SEGMENTS = 1024;

    /** The APP1 identifiers whose payloads may be excluded, and what they are called. */
    private const ALLOWED_APP1 = [
        "Exif\0\0" => 'APP1 Exif payload',
        "http://ns.adobe.com/xap/1.0/\0" => 'APP1 XMP payload',
        "http://ns.adobe.com/xmp/extension/\0" => 'APP1 XMP payload',
    ];

    public function __construct(private int $maxSegments = self::DEFAULT_MAX_SEGMENTS) {}

    public function format(): string
    {
        return 'JPEG';
    }

    public function rule(): string
    {
        return 'in JPEG an exclusion beyond the manifest store may lie only wholly inside the payload of an APP1 Exif or XMP segment of the primary image';
    }

    public function regions($stream): array
    {
        if (! is_resource($stream) || ! rewind($stream)) {
            throw new ContainerException('cannot seek to the start of the JPEG');
        }
        $reader = new StreamReader($stream, 'segment');
        $end = $reader->end();
        if ($reader->readExactly(2, 0, 'the SOI marker') !== "\xff\xd8") {
            throw new ContainerException('not a JPEG: no SOI at offset 0');
        }
        $regions = [new ExclusionRegion(0, 2, 'the SOI marker', false)];
        $identifierLength = max(array_map(strlen(...), array_keys(self::ALLOWED_APP1)));
        $p = 2;
        for ($segments = 0; ; $segments++) {
            if ($segments >= $this->maxSegments) {
                throw new ContainerException(sprintf('more than %d segments before the first SOS (offset %d)', $this->maxSegments, $p));
            }
            $head = $reader->readExactly(2, $p, 'a marker');
            if ($head[0] !== "\xff") {
                throw new ContainerException(sprintf('no marker at offset %d (0x%02x): the segment walk lost its place', $p, ord($head[0])));
            }
            $marker = ord($head[1]);
            if ($marker === 0xFF) {
                // a fill byte before a marker (T.81 §B.1.1.2): step one byte on
                $regions[] = new ExclusionRegion($p, 1, sprintf('a fill byte at %d', $p), false);
                $p++;
                fseek($stream, $p);

                continue;
            }
            if ($marker === 0x01 || $marker === 0xD8 || $marker === 0xD9 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                throw new ContainerException(sprintf('marker 0xFF%02X without a length before the first SOS (offset %d)', $marker, $p));
            }
            $length = unpack('n', $reader->readExactly(2, $p, 'the segment length'));
            $length = is_array($length) && is_int($length[1]) ? $length[1] : 0;
            if ($length < 2) {
                throw new ContainerException(sprintf('the segment at %d has a length of %d, below the 2 its own length field takes', $p, $length));
            }
            if ($p + 2 + $length > $end) {
                throw new ContainerException(sprintf('the segment at %d (length %d) runs past the end of the file at %d', $p, $length, $end));
            }
            $name = self::name($marker);
            $what = null;
            if ($marker === 0xE1) {
                $identifier = $reader->readExactly(min($length - 2, $identifierLength), $p + 4, 'the APP1 identifier');
                foreach (self::ALLOWED_APP1 as $prefix => $called) {
                    if (str_starts_with($identifier, $prefix)) {
                        $what = $called;
                        break;
                    }
                }
            }
            $kind = $what === null ? '' : ' '.explode(' ', $what)[1];   // "Exif" or "XMP"
            $regions[] = new ExclusionRegion($p, 2 + $length, sprintf('the %s%s segment at %d', $name, $kind, $p), false);
            if ($what !== null) {
                $regions[] = new ExclusionRegion($p + 4, $length - 2, $what, true);
            }
            $p += 2 + $length;
            if ($marker === 0xDA) {
                $regions[] = new ExclusionRegion($p, $end - $p, sprintf('the scan data and what follows (from %d)', $p), false);

                return $regions;
            }
            if (fseek($stream, $p) !== 0) {
                throw new ContainerException(sprintf('cannot seek to the segment at %d', $p));
            }
        }
    }

    /** The marker's name (T.81 Table B.1), for an explanation. */
    private static function name(int $marker): string
    {
        return match (true) {
            $marker >= 0xE0 && $marker <= 0xEF => sprintf('APP%d', $marker - 0xE0),
            $marker === 0xC4 => 'DHT',
            $marker === 0xCC => 'DAC',
            $marker >= 0xC0 && $marker <= 0xCF && $marker !== 0xC8 => sprintf('SOF%d', $marker - 0xC0),
            $marker === 0xDA => 'SOS',
            $marker === 0xDB => 'DQT',
            $marker === 0xDD => 'DRI',
            $marker === 0xFE => 'COM',
            default => sprintf('0xFF%02X', $marker),
        };
    }
}
