<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Asn1\Asn1Exception;
use Provemark\C2paVerifier\Asn1\Der;
use Provemark\C2paVerifier\Asn1\DerReader;
use Provemark\C2paVerifier\Asn1\TagClass;

/*
 * SPEC-016, AC1–AC2: the DER reader on hand-made vectors. Every expected
 * offset and length below is what `openssl asn1parse -inform DER` prints
 * for the same bytes (offset = the identifier octet, hl + l = the whole
 * element); every value is X.690's reading of the octets.
 */

/** The offset a message of the reader names, or -1. */
function spec016OffsetIn(string $message): int
{
    return preg_match('/offset (\d+)/', $message, $m) === 1 ? (int) $m[1] : -1;
}

test('SPEC-016 AC1: the ten tags decode, and the values are X.690\'s — INTEGER: 0, 127, 128 (two\'s complement keeps the leading zero), and a sixteen-byte serial as a decimal string', function (): void {
    expect(spec016Der('02 01 00')->integer())->toBe('0')
        ->and(spec016Der('02 01 7f')->integer())->toBe('127')
        ->and(spec016Der('02 02 00 80')->integer())->toBe('128')
        ->and(spec016Der('02 02 00 80')->integerBytes())->toBe("\x00\x80")
        // the serial of C.jpg's timestamp token (step 40): openssl prints the hex, SPEC-015's routine the decimal
        ->and(spec016Der('02 10 06 37 d4 46 c6 86 35 79 6b e2 99 41 ae 68 f7 a9')->integer())->toBe('8265249780176541439333781366280615849');
})->group('SPEC-016');

test('SPEC-016 AC1: the ten tags decode, and the values are X.690\'s — OBJECT IDENTIFIER: DigiCert\'s timestamp policy and id-ct-TSTInfo, dotted decimal', function (): void {
    expect(spec016Der('06 09 60 86 48 01 86 fd 6c 07 01')->oid())->toBe('2.16.840.1.114412.7.1')
        ->and(spec016Der('06 0b 2a 86 48 86 f7 0d 01 09 10 01 04')->oid())->toBe('1.2.840.113549.1.9.16.1.4')
        ->and(spec016Der('06 09 60 86 48 01 65 03 04 02 01')->oid())->toBe('2.16.840.1.101.3.4.2.1');
})->group('SPEC-016');

test('SPEC-016 AC1: the ten tags decode, and the values are X.690\'s — NULL, BOOLEAN, OCTET STRING', function (): void {
    $null = spec016Der('05 00');
    $null->null();
    expect($null->is(TagClass::Universal, 5))->toBeTrue()
        ->and(spec016Der('01 01 ff')->boolean())->toBeTrue()
        ->and(spec016Der('01 01 00')->boolean())->toBeFalse()
        ->and(spec016Der('04 03 61 62 63')->octets())->toBe('abc');
})->group('SPEC-016');

test('SPEC-016 AC1: the ten tags decode, and the values are X.690\'s — UTCTime and GeneralizedTime: the same instant three ways, fractions dropped, the century rule of RFC 5280', function (): void {
    $epoch = 1722981217; // 2024-08-06T21:53:37Z, C.jpg's genTime
    expect(spec016Der('17 0d 32 34 30 38 30 36 32 31 35 33 33 37 5a')->time())->toBe($epoch)
        ->and(spec016Der('18 0f 32 30 32 34 30 38 30 36 32 31 35 33 33 37 5a')->time())->toBe($epoch)
        ->and(spec016Der('18 13 32 30 32 34 30 38 30 36 32 31 35 33 33 37 2e 35 30 30 5a')->time())->toBe($epoch)
        // UTCTime years: 50–99 → 1950–1999, 00–49 → 2000–2049 (RFC 5280 §4.1.2.5.1)
        ->and(spec016Der('17 0d 39 39 31 32 33 31 32 33 35 39 35 39 5a')->time())->toBe(946684799)   // 1999-12-31T23:59:59Z
        ->and(spec016Der('17 0d 34 39 31 32 33 31 32 33 35 39 35 39 5a')->time())->toBe(2524607999); // 2049-12-31T23:59:59Z
})->group('SPEC-016');

test('SPEC-016 AC1: the ten tags decode, and the values are X.690\'s — SEQUENCE and SET: children with their offsets, an empty SET, and encoded() returns the input', function (): void {
    $seq = spec016Der('30 06 02 01 01 02 01 02');
    $children = $seq->sequence();
    expect($seq->offset)->toBe(0)->and($seq->headerLength)->toBe(2)->and($seq->constructed)->toBeTrue()
        ->and(count($children))->toBe(2)
        ->and($children[0]->offset)->toBe(2)->and($children[0]->integer())->toBe('1')
        ->and($children[1]->offset)->toBe(5)->and($children[1]->integer())->toBe('2')
        ->and($seq->child(1)->encoded())->toBe("\x02\x01\x02")
        ->and($seq->encoded())->toBe("\x30\x06\x02\x01\x01\x02\x01\x02")
        ->and(spec016Der('31 00')->set())->toBe([]);
})->group('SPEC-016');

test('SPEC-016 AC1: the ten tags decode, and the values are X.690\'s — context-specific tags: [0] constructed holding an INTEGER, [0] primitive holding raw bytes', function (): void {
    $explicit = spec016Der('a0 03 02 01 05');
    expect($explicit->class)->toBe(TagClass::ContextSpecific)->and($explicit->tag)->toBe(0)->and($explicit->constructed)->toBeTrue()
        ->and($explicit->tagged(0)->child(0)->integer())->toBe('5');
    $implicit = spec016Der('80 01 ff');
    expect($implicit->class)->toBe(TagClass::ContextSpecific)->and($implicit->constructed)->toBeFalse()
        ->and($implicit->tagged(0)->contents)->toBe("\xff");
})->group('SPEC-016');

test('SPEC-016 AC1: the ten tags decode, and the values are X.690\'s — a long-form length: 04 82 01 00 and 256 bytes, header 4, whole element 260', function (): void {
    $der = (new DerReader)->read("\x04\x82\x01\x00".str_repeat('x', 256));
    expect($der->headerLength)->toBe(4)->and(strlen($der->contents))->toBe(256)->and(strlen($der->encoded()))->toBe(260);
})->group('SPEC-016');

test('SPEC-016 AC1: the ten tags decode, and the values are X.690\'s — the wrong accessor throws, naming the offset and both tags', function (): void {
    $oid = spec016Der('06 09 60 86 48 01 86 fd 6c 07 01');
    expect(fn () => $oid->integer())->toThrow(Asn1Exception::class, 'offset 0');
    try {
        $oid->integer();
    } catch (Asn1Exception $e) {
        expect($e->getMessage())->toContain('INTEGER')->toContain('OBJECT IDENTIFIER');
    }
    expect(fn () => spec016Der('31 00')->sequence())->toThrow(Asn1Exception::class, 'SEQUENCE')
        ->and(fn () => spec016Der('02 01 00')->child(0))->toThrow(Asn1Exception::class)
        ->and(fn () => spec016Der('a0 03 02 01 05')->tagged(1))->toThrow(Asn1Exception::class, '[1]');
})->group('SPEC-016');

/** @return array<string, array{0: string, 1: string, 2: string}> case => [hex, the accessor that refuses ('read' = the reader itself), expected fragment] */
$cases = [
    'a length past the end' => ['04 05 61 62', 'read', 'offset 1'],
    'an indefinite length' => ['30 80 02 01 01 00 00', 'read', 'indefinite'],
    'a non-minimal length (BER, not DER)' => ['04 81 03 61 62 63', 'read', 'minimal'],
    'a long form of five bytes' => ['04 85 00 00 00 00 03 61 62 63', 'read', 'offset 1'],
    'the reserved length octet ff' => ['04 ff', 'read', 'offset 1'],
    'a high tag number' => ['1f 81 00 01 00', 'read', 'tag'],
    'a truncated identifier' => ['30', 'read', 'offset 0'],
    'one trailing byte after the element' => ['02 01 01 00', 'read', 'trailing'],
    'a UTCTime without Z' => ['17 0c 32 34 30 38 30 36 32 31 35 33 33 37', 'time', 'Z'],
    'a GeneralizedTime with minute 63 (CA_ct.jpg)' => ['18 0f 32 30 32 34 30 38 30 36 32 31 36 33 33 37 5a', 'time', '63'],
    '31 February' => ['18 0f 32 30 32 34 30 32 33 31 30 30 30 30 30 30 5a', 'time', '31'],
    'a BOOLEAN of two bytes' => ['01 02 ff ff', 'boolean', 'BOOLEAN'],
    'an OID with an unterminated subidentifier' => ['06 02 2a 86', 'oid', 'offset 0'],
    'an OID of zero length' => ['06 00', 'oid', 'offset 0'],
    'an INTEGER with a non-minimal leading 00 00' => ['02 03 00 00 05', 'integer', 'minimal'],
    'a negative INTEGER through integer()' => ['02 01 ff', 'integer', 'negative'],
];
foreach ($cases as $name => [$hex, $accessor, $fragment]) {
    test('SPEC-016 AC2: malformed DER is refused with the offset, never read past — '.$name, function () use ($hex, $accessor, $fragment): void {
        $bytes = (string) hex2bin(str_replace(' ', '', $hex));
        $thrown = null;
        try {
            $der = (new DerReader)->read($bytes);
            match ($accessor) {
                'read' => null,
                'time' => $der->time(),
                'boolean' => $der->boolean(),
                'oid' => $der->oid(),
                'integer' => $der->integer(),
            };
        } catch (Asn1Exception $e) {
            $thrown = $e;
        }
        expect($thrown)->toBeInstanceOf(Asn1Exception::class, "{$hex} was read without an exception");
        assert($thrown !== null);
        expect($thrown->getMessage())->toContain($fragment);
        expect(spec016OffsetIn($thrown->getMessage()))->toBeGreaterThanOrEqual(0, 'the message names an offset: '.$thrown->getMessage());
        expect(spec016OffsetIn($thrown->getMessage()))->toBeLessThanOrEqual(strlen($bytes), 'the offset lies within the input: '.$thrown->getMessage());
    })->group('SPEC-016');
}

test('SPEC-016 AC2: malformed DER is refused with the offset, never read past — nesting 33 deep exceeds maxDepth 32; 32 deep does not', function (): void {
    $nest = static function (int $depth): string {
        $bytes = "\x05\x00";
        for ($i = 0; $i < $depth; $i++) {
            $bytes = pack('CC', 0x30, strlen($bytes)).$bytes;
        }

        return $bytes;
    };
    expect((new DerReader)->read($nest(32))->constructed)->toBeTrue();
    expect(fn () => (new DerReader)->read($nest(33)))->toThrow(Asn1Exception::class, 'depth');
})->group('SPEC-016');

test('SPEC-016 AC2: malformed DER is refused with the offset, never read past — 65 537 elements in one SEQUENCE exceed maxElements 65 536', function (): void {
    $contents = str_repeat("\x05\x00", 65537);
    $bytes = "\x30\x83".substr(pack('N', strlen($contents)), 1).$contents;
    expect(fn () => (new DerReader)->read($bytes))->toThrow(Asn1Exception::class, 'elements');
    expect(fn () => (new DerReader(maxElements: 70000))->read($bytes))->not->toThrow(Asn1Exception::class);
})->group('SPEC-016');

test('SPEC-016 AC2: malformed DER is refused with the offset, never read past — an input of maxBytes + 1 is refused before reading', function (): void {
    $reader = new DerReader(maxBytes: 16);
    expect(fn () => $reader->read("\x04\x0f".str_repeat('x', 15)))->toThrow(Asn1Exception::class, '17 bytes');
    expect((new DerReader(maxBytes: 17))->read("\x04\x0f".str_repeat('x', 15))->octets())->toBe(str_repeat('x', 15));
})->group('SPEC-016');
