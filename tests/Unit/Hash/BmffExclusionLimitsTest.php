<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Container\IsobmffManifestStoreExtractor;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Verifier\FragmentedVerifier;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-053: a BMFF hash's exclusions are bounded. BmffHashCheck::plan() costs boxes × boxes ×
 * exclusions (4096 × 8 took 11.9 s in plan() alone, 2026-09-30). Every variant here is built in
 * memory: the file's c2pa.hash.bmff.v3 assertion re-encoded with other exclusions, every enclosing
 * box grown or shrunk with it, and top-level `free` boxes appended where a criterion needs many.
 * The claim is not signed again — the keyless case: the hashed URI fails and the hash still runs.
 */

const SPEC053_EIGHT_BYTE_FREE = "\x00\x00\x00\x08free";

/** A big-endian uint32 at $at. */
function spec053U32(string $bytes, int $at): int
{
    /** @var array{1: int} $u */
    $u = unpack('N', $bytes, $at);

    return $u[1];
}

/** A small CBOR encoder: ints, text, CborBytes, lists and text-keyed maps in the order given. */
function spec053Cbor(mixed $v): string
{
    $head = static function (int $major, int $n): string {
        $major <<= 5;

        return match (true) {
            $n < 24 => pack('C', $major | $n),
            $n < 256 => pack('CC', $major | 24, $n),
            $n < 65536 => pack('Cn', $major | 25, $n),
            default => pack('CN', $major | 26, $n),
        };
    };

    return match (true) {
        $v instanceof CborBytes => $head(2, strlen($v->bytes)).$v->bytes,
        is_string($v) => $head(3, strlen($v)).$v,
        is_int($v) => $head(0, $v),
        is_array($v) && array_is_list($v) => $head(4, count($v)).implode('', array_map('spec053Cbor', $v)),
        is_array($v) => $head(5, count($v)).implode('', array_map(static fn (string $k, mixed $x): string => spec053Cbor($k).spec053Cbor($x), array_keys($v), array_values($v))),
        default => throw new RuntimeException('cannot encode '.get_debug_type($v)),
    };
}

/**
 * $relative with its BMFF hash assertion's exclusions replaced, and $free eight-byte `free` boxes appended.
 *
 * @param  list<array<string, mixed>>  $exclusions
 */
function spec053Variant(string $relative, array $exclusions, int $free = 0): string
{
    $file = (string) file_get_contents(Corpus::fixtures().'/'.$relative);
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    $store = $stream === false ? null : (new IsobmffManifestStoreExtractor)->extract($stream);
    if ($store === null) {
        throw new RuntimeException("no store in {$relative}");
    }
    $bytes = $store->bytes;
    $at = strpos($file, $bytes);
    if ($at === false) {
        throw new RuntimeException("the store is not in {$relative} as extracted");
    }
    $manifest = ManifestStore::fromTree((new JumbfParser)->parse($bytes))->active;
    $assertion = $manifest->assertions['c2pa.hash.bmff.v3'] ?? throw new RuntimeException("no c2pa.hash.bmff.v3 in {$relative}");
    assert(is_array($assertion->data));
    $data = $assertion->data;
    $data['exclusions'] = $exclusions;
    $cbor = $assertion->box->contentBoxes()[0];
    $payload = spec053Cbor($data);
    $delta = strlen($payload) - ($cbor->length - 8);

    foreach ([0, $manifest->box->offset, $manifest->assertionStore->offset, $assertion->box->offset, $cbor->offset] as $lbox) {
        $bytes = substr_replace($bytes, pack('N', spec053U32($bytes, $lbox) + $delta), $lbox, 4);
    }
    $bytes = substr_replace($bytes, $payload, $cbor->offset + 8, $cbor->length - 8);

    // the top-level uuid box that holds the store ends where the store ends
    $uuid = null;
    for ($p = 0; $p < strlen($file); $p += spec053U32($file, $p)) {
        if (substr($file, $p + 4, 4) === 'uuid' && $p < $at && $p + spec053U32($file, $p) === $at + strlen($store->bytes)) {
            $uuid = $p;
        }
    }
    if ($uuid === null) {
        throw new RuntimeException("no top-level uuid box ends with the store in {$relative}");
    }
    $file = substr_replace($file, pack('N', spec053U32($file, $uuid) + $delta), $uuid, 4);
    $file = substr($file, 0, $at).$bytes.substr($file, $at + strlen($store->bytes));

    return $file.str_repeat(SPEC053_EIGHT_BYTE_FREE, $free);
}

/** @return resource */
function spec053Stream(string $bytes)
{
    $stream = fopen('php://memory', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('cannot open php://memory');
    }
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

/** @return array{0: VerificationReport, 1: float} the report and the seconds it took */
function spec053Verify(string $bytes): array
{
    $start = hrtime(true);
    $report = (new Verifier)->verify(spec053Stream($bytes));

    return [$report, (hrtime(true) - $start) / 1e9];
}

/** @return list<ValidationStatus> */
function spec053Malformed(VerificationReport $report): array
{
    return array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::AssertionBmffHashMalformed));
}

/** @return list<array<string, mixed>> $count exclusions of $xpath, each with $subsets one-byte subsets */
function spec053Exclusions(int $count, string $xpath, int $subsets): array
{
    $subset = array_map(static fn (int $i): array => ['offset' => $i, 'length' => 1], range(0, $subsets - 1));

    return array_fill(0, $count, ['xpath' => $xpath, 'subset' => $subset]);
}

it('builds a variant that the verifier reads as the same assertion when nothing changes', function (): void {
    // the helper itself, before any criterion rests on it: the original exclusions re-encoded give a
    // store the verifier parses, with the BMFF hash still reached (a mismatch now, since the claim's
    // hashed URI is not recomputed — and no malformed)
    $stream = fopen(Corpus::fixtures().'/fixture-signed.mp4', 'rb');
    assert($stream !== false);
    $store = (new IsobmffManifestStoreExtractor)->extract($stream);
    assert($store !== null);
    $data = ManifestStore::fromTree((new JumbfParser)->parse($store->bytes))->active->assertions['c2pa.hash.bmff.v3']->data;
    assert(is_array($data) && is_array($data['exclusions']));
    /** @var list<array<string, mixed>> $exclusions */
    $exclusions = array_values($data['exclusions']);

    [$report] = spec053Verify(spec053Variant('fixture-signed.mp4', $exclusions));

    expect(spec053Malformed($report))->toBe([])
        ->and(array_map(static fn (ValidationStatus $s): string => $s->code->value, $report->result->statuses))->toContain('assertion.bmffHash.match');
})->group('SPEC-053');

it('AC1: more than 64 exclusions is malformed', function (): void {
    [$report] = spec053Verify(spec053Variant('fixture-signed.mp4', array_fill(0, 65, ['xpath' => '/skip'])));
    $malformed = spec053Malformed($report);

    expect($malformed)->toHaveCount(1)
        ->and($malformed[0]->explanation)->toContain('65')
        ->and($malformed[0]->explanation)->toContain('64')
        ->and($report->result->state)->toBe(ValidationState::Invalid);
})->group('SPEC-053');

it('AC2: more than 64 subsets in one exclusion is malformed', function (): void {
    [$report] = spec053Verify(spec053Variant('fixture-signed.mp4', spec053Exclusions(1, '/ftyp', 65)));
    $malformed = spec053Malformed($report);

    expect($malformed)->toHaveCount(1)
        ->and($malformed[0]->explanation)->toContain('65')
        ->and($malformed[0]->explanation)->toContain('64');
})->group('SPEC-053');

it('AC3: more than 4096 excluded ranges is malformed, and quickly', function (): void {
    // 4000 free boxes appended (the extractor refuses more than 4096 boxes in all; the file has 29)
    // and 8 exclusions matching every free box: 8 × 4001 = 32,008 ranges
    [$report, $seconds] = spec053Verify(spec053Variant('fixture-signed.mp4', spec053Exclusions(8, '/free', 1), 4000));
    $malformed = spec053Malformed($report);

    expect($malformed)->toHaveCount(1)
        ->and($malformed[0]->explanation)->toContain('4096')
        ->and($seconds)->toBeLessThan(2.0);
})->group('SPEC-053');

it('AC4: at the limits it is not malformed and stays within 5 seconds', function (string $case): void {
    $bytes = match ($case) {
        // 2048 free boxes, one exclusion with 2 subsets: exactly 4096 ranges
        'many boxes' => spec053Variant('fixture-signed.mp4', spec053Exclusions(1, '/free', 2), 2047),
        // 64 exclusions of 64 subsets, all on ftyp: exactly 4096 ranges
        'many subsets' => spec053Variant('fixture-signed.mp4', spec053Exclusions(64, '/ftyp', 64)),
        default => throw new RuntimeException("unknown case {$case}"),
    };
    $limit = ini_get('memory_limit');
    ini_set('memory_limit', '128M');
    try {
        [$report, $seconds] = spec053Verify($bytes);
    } finally {
        ini_set('memory_limit', (string) $limit);
    }
    fwrite(STDERR, sprintf("\n    SPEC-053 AC4 %s: %.2f s\n", $case, $seconds));

    expect(spec053Malformed($report))->toBe([])
        ->and($seconds)->toBeLessThan(5.0);
})->with(['many boxes', 'many subsets'])->group('SPEC-053');

it('AC5: the fragmented init segment is bounded too', function (): void {
    $fragments = (static function (): Generator {
        foreach (['seg_1.m4s', 'seg_2.m4s', 'seg_3.m4s', 'seg_4.m4s', 'seg_5.m4s'] as $name) {
            $stream = fopen(Corpus::fixtures().'/bmff-fragmented/'.$name, 'rb');
            assert($stream !== false);
            yield $name => $stream;
        }
    })();
    $report = (new FragmentedVerifier)->verify(spec053Stream(spec053Variant('bmff-fragmented/init.mp4', array_fill(0, 65, ['xpath' => '/skip']))), $fragments);
    $malformed = spec053Malformed($report);

    expect($malformed)->toHaveCount(1)
        ->and($malformed[0]->explanation)->toContain('64')
        ->and(array_map(static fn (ValidationStatus $s): string => $s->code->value, $report->result->statuses))->not->toContain('assertion.bmffHash.match');
})->group('SPEC-053');
