<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Hash;

use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\ManifestStoreBytes;
use Provemark\C2paVerifier\Container\StreamReader;

/**
 * The JPEG's boxes for the general box hash (SPEC-090; C2PA 2.4 §18.7.3.1), in file order:
 *  - one box per marker segment, named by its ITU-T T.81 Table B.1 symbol, from the marker to the end
 *    of the segment;
 *  - SOS and RSTn with the entropy-coded data that follows them, up to the next marker that is not a
 *    stuffed FF00;
 *  - the APP11 segments of the manifest store, one box named C2PA per contiguous run (the store's
 *    ranges, SPEC-001), so a store split by another segment is two C2PA boxes and fails the list;
 *  - the bytes after EOI, if any, as c2pa.after (c2pa-rs #2949).
 *
 * The walk is bounded: SPEC-069's segment count before the first scan, and a bound on all boxes.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class JpegBoxMap
{
    /** Before the first SOS, as SPEC-069's walk (JpegExclusionAllowance). */
    public const DEFAULT_MAX_SEGMENTS_BEFORE_SCAN = JpegExclusionAllowance::DEFAULT_MAX_SEGMENTS;

    /** Every box: room for restart markers, which each count as a box. */
    public const DEFAULT_MAX_BOXES = 65536;

    public const C2PA = 'C2PA';

    public const AFTER = 'c2pa.after';

    private const CHUNK = 64 * 1024;

    /** T.81 Table B.1's symbols for the markers that are not a numbered family. */
    private const NAMES = [0x01 => 'TEM', 0xC4 => 'DHT', 0xC8 => 'JPG', 0xCC => 'DAC', 0xD8 => 'SOI', 0xD9 => 'EOI', 0xDA => 'SOS', 0xDB => 'DQT', 0xDC => 'DNL', 0xDD => 'DRI', 0xDE => 'DHP', 0xDF => 'EXP', 0xFE => 'COM'];

    public function __construct(
        private int $maxSegmentsBeforeScan = self::DEFAULT_MAX_SEGMENTS_BEFORE_SCAN,
        private int $maxBoxes = self::DEFAULT_MAX_BOXES,
    ) {}

    /**
     * @param  resource  $stream  the asset, readable and seekable; the position is not kept
     * @return list<AssetBox>
     *
     * @throws ContainerException when the segments cannot be walked from SOI to EOI
     */
    public function boxes($stream, ManifestStoreBytes $store): array
    {
        $reader = new StreamReader($stream, 'JPEG');
        $end = $reader->end();
        if (self::read($reader, 2, 0, 'SOI marker') !== "\xFF\xD8") {
            throw new ContainerException('the asset does not start with a JPEG SOI marker');
        }
        $boxes = [new AssetBox('SOI', 0, 2)];
        $storeRanges = $store->ranges;
        $p = 2;
        $scanned = false;
        $segments = 1;
        while (true) {
            if (count($boxes) >= $this->maxBoxes) {
                throw new ContainerException(sprintf('more than %d boxes in the JPEG', $this->maxBoxes));
            }
            if (! $scanned && ++$segments > $this->maxSegmentsBeforeScan) {
                throw new ContainerException(sprintf('more than %d segments before the first scan', $this->maxSegmentsBeforeScan));
            }
            // a store run starts here: it is one C2PA box, and it must end on a segment boundary
            if ($storeRanges !== [] && $storeRanges[0]['start'] === $p) {
                $range = array_shift($storeRanges);
                $boxes[] = new AssetBox(self::C2PA, $range['start'], $range['length']);
                $p = $range['start'] + $range['length'];

                continue;
            }
            $head = self::read($reader, 2, $p, 'JPEG marker');
            if ($head[0] !== "\xFF") {
                throw new ContainerException(sprintf('no JPEG marker at offset %d (byte 0x%02X)', $p, ord($head[0])));
            }
            $marker = ord($head[1]);
            $name = self::name($marker) ?? throw new ContainerException(sprintf('marker 0x%02X at offset %d has no T.81 symbol (fill bytes are not read)', $marker, $p));
            if ($marker === 0xD9) {
                $boxes[] = new AssetBox('EOI', $p, 2);
                $p += 2;
                break;
            }
            $length = self::hasLength($marker) ? 2 + self::u16(self::read($reader, 2, $p + 2, "{$name} length")) : 2;
            if ($length < 4 && self::hasLength($marker)) {
                throw new ContainerException(sprintf('%s at offset %d declares a length below 2', $name, $p));
            }
            if ($marker === 0xDA || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $scanned = true;
                $length = $this->entropyEnd($reader, $p + $length, $end) - $p;
            }
            if ($storeRanges !== [] && $p + $length > $storeRanges[0]['start']) {
                throw new ContainerException(sprintf('the manifest store at offset %d does not start on a segment boundary', $storeRanges[0]['start']));
            }
            $boxes[] = new AssetBox($name, $p, $length);
            $p += $length;
        }
        if ($storeRanges !== []) {
            throw new ContainerException(sprintf('the manifest store at offset %d lies after EOI', $storeRanges[0]['start']));
        }
        if ($p < $end) {
            $boxes[] = new AssetBox(self::AFTER, $p, $end - $p);
        }

        return $boxes;
    }

    private static function name(int $marker): ?string
    {
        return match (true) {
            isset(self::NAMES[$marker]) => self::NAMES[$marker],
            $marker >= 0xC0 && $marker <= 0xCF => 'SOF'.($marker - 0xC0),
            $marker >= 0xD0 && $marker <= 0xD7 => 'RST'.($marker - 0xD0),
            $marker >= 0xE0 && $marker <= 0xEF => 'APP'.($marker - 0xE0),
            $marker >= 0xF0 && $marker <= 0xFD => 'JPG'.($marker - 0xF0),
            default => null,
        };
    }

    /** SOI, EOI, RSTn and TEM stand alone; every other marker is followed by a length (T.81 §B.1.1.4). */
    private static function hasLength(int $marker): bool
    {
        return ! ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD9));
    }

    /** $length bytes at $offset: StreamReader reads from where it stands, so it is moved there first. */
    private static function read(StreamReader $reader, int $length, int $offset, string $what): string
    {
        $reader->seek($offset);

        return $reader->readExactly($length, $offset, $what);
    }

    private static function u16(string $bytes): int
    {
        return (ord($bytes[0]) << 8) | ord($bytes[1]);
    }

    /** The offset of the first marker at or after $from that is not a stuffed FF00 (T.81 §B.1.1.5). */
    private function entropyEnd(StreamReader $reader, int $from, int $end): int
    {
        $p = $from;
        while ($p < $end) {
            $chunk = self::read($reader, min(self::CHUNK, $end - $p), $p, 'entropy-coded data');
            $at = 0;
            while ($at < strlen($chunk) && ($ff = strpos($chunk, "\xFF", $at)) !== false) {
                if ($ff + 1 >= strlen($chunk)) {
                    if ($p + $ff + 1 >= $end) {
                        throw new ContainerException('the entropy-coded data ends inside a marker');
                    }
                    $next = self::read($reader, 1, $p + $ff + 1, 'entropy-coded data');
                } else {
                    $next = $chunk[$ff + 1];
                }
                if ($next !== "\x00") {
                    return $p + $ff;
                }
                $at = $ff + 2;
            }
            $p += strlen($chunk);
        }
        throw new ContainerException('the entropy-coded data runs to the end of the file without EOI');
    }
}
