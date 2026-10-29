<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Asn1\Der;
use Provemark\C2paVerifier\Asn1\DerReader;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Tests\Support\DerPatch;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-016 amendment 8 (step 515): the reader gave every element its own copy of its content octets, so a
 * value n levels deep was held n + 1 times. A 1 MB OCTET STRING 31 levels deep held 33.6 MB after the parse,
 * and through verify() as a sigTst2 token peaked at 44.9 MB, where one level deep it peaked at 13.8 MB.
 */

/** The string inside $depth SEQUENCEs, built here rather than by the reader under test. */
function spec016NestDer(int $depth): string
{
    $der = pack('C', 0x04).DerPatch::length(1048000).str_repeat('A', 1048000);
    for ($i = 0; $i < $depth; $i++) {
        $der = pack('C', 0x30).DerPatch::length(strlen($der)).$der;
    }

    return $der;
}

/** The memory a kept parse result holds, in bytes. */
function spec016NestHeld(string $bytes): int
{
    gc_collect_cycles();
    $before = memory_get_usage();
    $der = (new DerReader)->read($bytes);
    $held = memory_get_usage() - $before;
    unset($der);

    return $held;
}

/** @param list<string> $failures */
function spec016NestWalk(Der $der, string $input, array &$failures): void
{
    $expected = substr($input, $der->offset, $der->length());
    if ($der->encoded() !== $expected || $der->contents() !== substr($expected, $der->headerLength)) {
        $failures[] = 'offset '.$der->offset;
    }
    foreach ($der->children ?? [] as $child) {
        spec016NestWalk($child, $input, $failures);
    }
}

function spec016NestBstr(string $bytes): string
{
    $n = strlen($bytes);

    return match (true) {
        $n < 24 => pack('C', 0x40 + $n),
        $n < 256 => "\x58".pack('C', $n),
        $n < 65536 => "\x59".pack('n', $n),
        default => "\x5a".pack('N', $n),
    }.$bytes;
}

/** fixture-signed.png with its COSE unprotected header replaced by one sigTst2 token; the store's boxes resized. */
function spec016NestFile(string $token): string
{
    require_once dirname(__DIR__, 3).'/bin/variant-helpers.php';
    $png = (string) file_get_contents(Corpus::fixtures().'/fixture-signed.png');
    $stream = fopen(Corpus::fixtures().'/fixture-signed.png', 'rb');
    assert($stream !== false);
    $extracted = (new PngManifestStoreExtractor)->extract($stream);
    assert($extracted !== null);
    $store = $extracted->bytes;
    $active = ManifestStore::fromTree((new JumbfParser)->parse($store))->active;
    $signature = $active->box->child('c2pa.signature');
    assert($signature !== null);
    $cbor = $signature->contentBoxes()[0];
    $cose = CoseSign1::fromBytes($cbor->data);
    $sigTst2 = "\x67sigTst2\xa1\x69tstTokens\x81\xa1\x63val".spec016NestBstr($token);
    $replacement = "\xd2\x84".spec016NestBstr($cose->protectedBytes)."\xa1".$sigTst2."\xf6".spec016NestBstr($cose->signature);
    $store = bSplice($store, $cbor->offset + 8, $cbor->length - 8, $replacement, [0, $active->box->offset, $signature->offset, $cbor->offset]);

    return pngWithStore($png, $store);
}

/** @return array{int, list<StatusCode>} the peak above the start, and the codes */
function spec016NestVerify(string $file): array
{
    $stream = fopen('php://memory', 'w+b');
    assert($stream !== false);
    fwrite($stream, $file);
    rewind($stream);
    unset($file);
    gc_collect_cycles();
    $before = memory_get_usage();
    memory_reset_peak_usage();
    $report = (new Verifier)->verify($stream);

    return [memory_get_peak_usage() - $before, array_map(static fn ($s) => $s->code, $report->result->statuses)];
}

it('AC14: what a parse holds does not grow with depth', function (): void {
    $shallow = spec016NestDer(1);
    $deep = spec016NestDer(31);
    $shallowHeld = spec016NestHeld($shallow);
    $deepHeld = spec016NestHeld($deep);

    expect($deepHeld)->toBeLessThan(2_000_000)
        ->and($shallowHeld)->toBeLessThan(2_000_000)
        ->and(abs($deepHeld - $shallowHeld))->toBeLessThan(1_000_000);

    $failures = [];
    spec016NestWalk((new DerReader)->read($deep), $deep, $failures);
    expect($failures)->toBe([]);
})->group('SPEC-016');

it('AC14: a deep token through verify() is malformed, and costs what a shallow one costs', function (): void {
    [$shallowPeak, $shallowCodes] = spec016NestVerify(spec016NestFile(spec016NestDer(1)));
    [$deepPeak, $deepCodes] = spec016NestVerify(spec016NestFile(spec016NestDer(31)));

    expect($shallowCodes)->toContain(StatusCode::TimeStampMalformed)
        ->and($deepCodes)->toContain(StatusCode::TimeStampMalformed)
        ->and($deepPeak - $shallowPeak)->toBeLessThan(3_000_000);
})->group('SPEC-016');
