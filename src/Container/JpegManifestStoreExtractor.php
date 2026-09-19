<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

/**
 * JPEG APP11 → manifest store bytes (SPEC-001; C2PA 2.4 §A.3.1).
 *
 * Walks the marker segments from SOI to SOS, collects every APP11 segment
 * whose payload starts with `JP`, checks the 16-byte piece header (CI, En,
 * Z, LBox, TBox — measured in notes/step-02-jpeg-fixture.md) and reassembles
 * the box: LBox and TBox once, then the data of every piece in order.
 * Segment bodies that are not needed are skipped with fseek; piece data is
 * read only after the header checks and the limits pass.
 */
final readonly class JpegManifestStoreExtractor
{
    public const DEFAULT_MAX_PIECES = 2048;         // 2048 × 64 KiB ≈ 128 MiB, above MAX_LBOX

    public const DEFAULT_MAX_LBOX = 64 * 1024 * 1024;

    private const MARKER_SOI = 0xD8;

    private const MARKER_EOI = 0xD9;

    private const MARKER_SOS = 0xDA;

    private const MARKER_APP11 = 0xEB;

    /** CI (2) + En (2) + Z (4) + LBox (4) + TBox (4). */
    private const PIECE_HEADER_LENGTH = 16;

    public function __construct(
        public int $maxPieces = self::DEFAULT_MAX_PIECES,
        public int $maxLBox = self::DEFAULT_MAX_LBOX,
    ) {}

    /**
     * @param  resource  $stream  a readable, seekable stream positioned at 0
     * @return ManifestStoreBytes|null null when the JPEG has no APP11 JUMBF pieces (AC2, AC13)
     *
     * @throws ContainerException on every malformed or out-of-order case
     */
    public function extract($stream): ?ManifestStoreBytes
    {
        $soi = $this->readExactly($stream, 2, 0, 'SOI');
        if ($soi !== "\xFF\xD8") {
            throw new ContainerException(sprintf(
                'not a JPEG: expected FF D8 at offset 0, found %s',
                strtoupper(chunk_split(bin2hex($soi), 2, ' ')),
            ));
        }

        $pieces = 0;
        $instanceNumber = null;
        $lBox = null;
        $tBox = null;
        $collected = '';

        while (true) {
            $offset = $this->tell($stream);
            $marker = $this->readMarker($stream, $offset);

            if ($marker === self::MARKER_SOS) {
                break;
            }
            if ($marker === self::MARKER_SOI || $marker === self::MARKER_EOI || $marker < 0xC0) {
                throw new ContainerException(sprintf(
                    'unexpected marker FF %02X at offset %d before SOS',
                    $marker,
                    $offset,
                ));
            }

            // The length field counts itself; a segment body is length − 2.
            $length = $this->readUint16($stream, $offset, 'segment length');
            if ($length < 2) {
                throw new ContainerException(sprintf('segment length %d at offset %d is shorter than its own field', $length, $offset));
            }
            $bodyLength = $length - 2;

            if ($marker !== self::MARKER_APP11 || $bodyLength < self::PIECE_HEADER_LENGTH) {
                $this->skip($stream, $bodyLength, $offset);

                continue;
            }

            $header = $this->readExactly($stream, self::PIECE_HEADER_LENGTH, $offset, 'APP11 header');
            if (! str_starts_with($header, 'JP')) {
                // Another user of APP11 (AC8): skipped like any unknown APPn segment.
                $this->skip($stream, $bodyLength - self::PIECE_HEADER_LENGTH, $offset);

                continue;
            }

            /** @var array{en: int, z: int, lbox: int} $fields */
            $fields = unpack('nen/Nz/Nlbox', $header, 2);
            $pieceTBox = substr($header, 12, 4);
            $pieceNumber = $pieces + 1;

            if ($pieceNumber > $this->maxPieces) {
                throw new ContainerException(sprintf(
                    'piece %d exceeds the limit of %d piece(s) (offset %d)',
                    $pieceNumber,
                    $this->maxPieces,
                    $offset,
                ));
            }
            if ($fields['lbox'] > $this->maxLBox) {
                throw new ContainerException(sprintf(
                    'LBox %d exceeds the limit of %d bytes (piece %d, offset %d)',
                    $fields['lbox'],
                    $this->maxLBox,
                    $pieceNumber,
                    $offset,
                ));
            }
            // LBox 0 (to end of file) and 1 (64-bit XLBox follows) exist in ISO
            // BMFF; neither can be reassembled from fixed-size pieces here.
            if ($fields['lbox'] < 8) {
                throw new ContainerException(sprintf('LBox %d in piece %d is not a supported box length', $fields['lbox'], $pieceNumber));
            }
            if ($fields['z'] !== $pieceNumber) {
                throw new ContainerException(sprintf(
                    'piece out of order at offset %d: packet sequence number expected %d, found %d',
                    $offset,
                    $pieceNumber,
                    $fields['z'],
                ));
            }
            if ($instanceNumber === null || $lBox === null || $tBox === null) {
                $instanceNumber = $fields['en'];
                $lBox = $fields['lbox'];
                $tBox = $pieceTBox;
                $collected = substr($header, 8, 8); // LBox and TBox, once
            } else {
                if ($fields['en'] !== $instanceNumber) {
                    throw new ContainerException(sprintf(
                        'box instance number %d in piece %d differs from %d',
                        $fields['en'],
                        $pieceNumber,
                        $instanceNumber,
                    ));
                }
                if ($fields['lbox'] !== $lBox) {
                    throw new ContainerException(sprintf('LBox %d in piece %d differs from %d', $fields['lbox'], $pieceNumber, $lBox));
                }
                if ($pieceTBox !== $tBox) {
                    throw new ContainerException(sprintf('TBox %s in piece %d differs from %s', bin2hex($pieceTBox), $pieceNumber, bin2hex($tBox)));
                }
            }

            $dataLength = $bodyLength - self::PIECE_HEADER_LENGTH;
            if (strlen($collected) + $dataLength > $lBox) {
                throw new ContainerException(sprintf(
                    'piece %d carries the box past its LBox %d (%d bytes collected, %d more in the piece)',
                    $pieceNumber,
                    $lBox,
                    strlen($collected),
                    $dataLength,
                ));
            }

            $collected .= $this->readExactly($stream, $dataLength, $offset, sprintf('piece %d data', $pieceNumber));
            $pieces++;
        }

        if ($pieces === 0 || $lBox === null) {
            return null;
        }
        if (strlen($collected) !== $lBox) {
            throw new ContainerException(sprintf(
                'incomplete box: LBox %d but %d bytes collected in %d piece(s)',
                $lBox,
                strlen($collected),
                $pieces,
            ));
        }

        return new ManifestStoreBytes($collected);
    }

    /**
     * Reads the marker at the current position: one or more fill bytes
     * FF, then the marker code. Returns the code.
     *
     * @param  resource  $stream
     */
    private function readMarker($stream, int $offset): int
    {
        $byte = $this->readExactly($stream, 1, $offset, 'marker');
        if ($byte !== "\xFF") {
            throw new ContainerException(sprintf('expected a marker at offset %d, found %02X', $offset, ord($byte)));
        }
        do {
            $byte = $this->readExactly($stream, 1, $offset, 'marker');
        } while ($byte === "\xFF");

        return ord($byte);
    }

    /** @param resource $stream */
    private function readUint16($stream, int $offset, string $what): int
    {
        /** @var array{1: int} $value */
        $value = unpack('n', $this->readExactly($stream, 2, $offset, $what));

        return $value[1];
    }

    /**
     * Reads exactly $length bytes or throws; fewer bytes means the file ends
     * inside the segment that starts at $offset (AC5).
     *
     * @param  resource  $stream
     */
    private function readExactly($stream, int $length, int $offset, string $what): string
    {
        if ($length === 0) {
            return '';
        }
        if ($length < 0) {
            throw new \LogicException(sprintf('negative read length %d', $length));
        }
        $bytes = fread($stream, $length);
        if ($bytes === false || strlen($bytes) !== $length) {
            throw new ContainerException(sprintf(
                'unexpected end of file while reading %s of the segment at offset %d: wanted %d bytes, got %d',
                $what,
                $offset,
                $length,
                $bytes === false ? 0 : strlen($bytes),
            ));
        }

        return $bytes;
    }

    /**
     * Skips a segment body without reading it; the seek must land inside
     * the file, otherwise the file ends inside this segment (AC5).
     *
     * @param  resource  $stream
     */
    private function skip($stream, int $length, int $offset): void
    {
        if ($length === 0) {
            return;
        }
        $target = $this->tell($stream) + $length;
        if (fseek($stream, $length, SEEK_CUR) !== 0 || $this->tell($stream) !== $target) {
            throw new ContainerException(sprintf('cannot skip %d bytes of the segment at offset %d', $length, $offset));
        }
        // fseek past the end succeeds on plain files; probe one byte so a
        // truncated file is an error here and not a false "no APP11".
        if (fread($stream, 1) === '') {
            throw new ContainerException(sprintf('unexpected end of file inside the segment at offset %d', $offset));
        }
        if (fseek($stream, -1, SEEK_CUR) !== 0) {
            throw new ContainerException(sprintf('cannot reposition after the segment at offset %d', $offset));
        }
    }

    /** @param resource $stream */
    private function tell($stream): int
    {
        $position = ftell($stream);
        if ($position === false) {
            throw new ContainerException('the stream is not seekable');
        }

        return $position;
    }
}
