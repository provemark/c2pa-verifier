<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

use Provemark\C2paVerifier\Support\Bytes;

/**
 * JPEG APP11 → manifest store bytes (SPEC-001; C2PA 2.4 §A.3.1).
 *
 * Walks the marker segments from SOI to SOS, collects every APP11 segment
 * whose payload starts with `JP`, checks the 16-byte piece header (CI, En,
 * Z, LBox, TBox — measured in notes/step-02-jpeg-fixture.md) and reassembles
 * the box: LBox and TBox once, then the data of every piece in order.
 * Segment bodies that are not needed are skipped unread; piece data is
 * read only after the header checks and the limits pass.
 */
final readonly class JpegManifestStoreExtractor
{
    public const DEFAULT_MAX_PIECES = 2048;         // 2048 × 64 KiB ≈ 128 MiB, above MAX_LBOX

    public const DEFAULT_MAX_LBOX = 64 * 1024 * 1024;

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
        $reader = new StreamReader($stream, 'segment');
        $soi = $reader->readExactly(2, 0, 'SOI');
        if ($soi !== "\xFF\xD8") {
            throw new ContainerException(sprintf(
                'not a JPEG: expected FF D8 at offset 0, found %s',
                Bytes::hex($soi),
            ));
        }

        $pieces = 0;
        $instanceNumber = null;
        $lBox = null;
        $tBox = null;
        $collected = '';
        $ranges = [];

        while (true) {
            $offset = $reader->tell();
            $marker = $this->readMarker($reader, $offset);

            if ($marker === self::MARKER_SOS) {
                break;
            }
            if (! self::hasLengthField($marker)) {
                // Reading a length where there is none would skip an arbitrary
                // number of bytes and could land the scan past the store (AC15).
                throw new ContainerException(sprintf(
                    'unexpected marker FF %02X at offset %d before SOS',
                    $marker,
                    $offset,
                ));
            }

            // The length field counts itself; a segment body is length − 2.
            $length = $this->readUint16($reader, $offset, 'segment length');
            if ($length < 2) {
                throw new ContainerException(sprintf('segment length %d at offset %d is shorter than its own field', $length, $offset));
            }
            $bodyLength = $length - 2;

            if ($marker !== self::MARKER_APP11 || $bodyLength < self::PIECE_HEADER_LENGTH) {
                $reader->skip($bodyLength, $offset);

                continue;
            }

            $header = $reader->readExactly(self::PIECE_HEADER_LENGTH, $offset, 'APP11 header');
            if (! str_starts_with($header, 'JP')) {
                // Another user of APP11 (AC8): skipped like any unknown APPn segment.
                $reader->skip($bodyLength - self::PIECE_HEADER_LENGTH, $offset);

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
                    throw new ContainerException(sprintf('TBox %s in piece %d differs from %s', Bytes::hex($pieceTBox), $pieceNumber, Bytes::hex($tBox)));
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

            $collected .= $reader->readExactly($dataLength, $offset, sprintf('piece %d data', $pieceNumber));
            $ranges[] = ['start' => $offset, 'length' => 2 + $length];   // marker, length field, piece header, data
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

        return new ManifestStoreBytes($collected, $ranges);
    }

    /**
     * Whether a marker code is followed by a two-byte length field
     * (ITU-T T.81, Table B.1). TEM (01) and RST0–7 (D0–D7) stand alone, as
     * do SOI (D8) and EOI (D9); 02–BF are reserved. Everything else — SOFn,
     * DHT, DAC, SOS, DQT, DNL, DRI, DHP, EXP, APPn, JPGn, COM — has one.
     */
    private static function hasLengthField(int $marker): bool
    {
        if ($marker < 0xC0) {
            return false;
        }

        return $marker < 0xD0 || $marker > 0xD9;
    }

    /**
     * Reads the marker at the current position: one or more fill bytes
     * FF, then the marker code. Returns the code.
     */
    private function readMarker(StreamReader $reader, int $offset): int
    {
        $byte = $reader->readExactly(1, $offset, 'marker');
        if ($byte !== "\xFF") {
            throw new ContainerException(sprintf('expected a marker at offset %d, found %02X', $offset, ord($byte)));
        }
        do {
            $byte = $reader->readExactly(1, $offset, 'marker');
        } while ($byte === "\xFF");

        return ord($byte);
    }

    private function readUint16(StreamReader $reader, int $offset, string $what): int
    {
        /** @var array{1: int} $value */
        $value = unpack('n', $reader->readExactly(2, $offset, $what));

        return $value[1];
    }
}
