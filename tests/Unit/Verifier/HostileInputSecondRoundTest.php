<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Cbor\CborDecoder;
use Provemark\C2paVerifier\Cbor\CborException;
use Provemark\C2paVerifier\Cbor\CborTag;
use Provemark\C2paVerifier\Cli\Command;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-045 (step 161): hostile input, the second round. Findings 3–6 of the review of step 157, each
 * with its own criterion. The inputs larger than about 1 MB are built here in memory; the three that
 * use a file are in tests/Fixtures/hostile-2/ (bin/make-hostile-input-2-variants.php).
 */

/** The limit messages of CborBudget and Manifest::MAX_JSON_BYTES, as the criteria name them. */
const SPEC045_ITEM_LIMIT = 'limit of 65536 CBOR items';

/** @param  string  $bytes  a whole file */
function spec045Verify(string $bytes): VerificationReport
{
    $stream = fopen('php://memory', 'w+b');
    assert($stream !== false);
    fwrite($stream, $bytes);
    rewind($stream);

    return (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/trust/full.settings.json')));
}

function spec045File(string $name): string
{
    return (string) file_get_contents(Corpus::fixtures()."/hostile-2/{$name}.png");
}

function spec045Box(string $type, string $data): string
{
    return pack('N', 8 + strlen($data)).$type.$data;
}

/** @return list<string> */
function spec045Explanations(VerificationReport $report): array
{
    return array_map(static fn (ValidationStatus $s): string => $s->explanation, $report->result->statuses);
}

/** Whether any explanation of the report contains $needle. */
function spec045Names(VerificationReport $report, string $needle): bool
{
    return array_filter(spec045Explanations($report), static fn (string $e): bool => str_contains($e, $needle)) !== [];
}

/**
 * The PNG fixture's store, its active manifest, and a function that puts an edited store back.
 *
 * @return array{0: string, 1: Closure(string): string}
 */
function spec045Store(): array
{
    $png = (string) file_get_contents(Corpus::fixtures().'/fixture-signed.png');
    $at = (int) strpos($png, 'caBX');
    $length = unpack('N', substr($png, $at - 4, 4));
    assert(is_array($length) && is_int($length[1]));
    $store = substr($png, $at + 4, $length[1]);
    $old = $length[1];

    return [$store, static fn (string $s): string => substr($png, 0, $at - 4).pack('N', strlen($s)).'caBX'.$s.pack('N', crc32('caBX'.$s)).substr($png, $at + 8 + $old)];
}

/**
 * $store with the LBox at each offset grown by $delta.
 *
 * @param  list<int>  $offsets
 */
function spec045Grow(string $store, array $offsets, int $delta): string
{
    foreach ($offsets as $offset) {
        $old = unpack('N', substr($store, $offset, 4));
        assert(is_array($old) && is_int($old[1]));
        $store = substr_replace($store, pack('N', $old[1] + $delta), $offset, 4);
    }

    return $store;
}

/** fixture-signed.png with $extra appended to the active manifest's assertion store. */
function spec045PngWithAssertions(string $extra): string
{
    [$store, $put] = spec045Store();
    $root = (new JumbfParser)->parse($store);
    $manifest = $root->superboxes()[count($root->superboxes()) - 1];
    $assertions = $manifest->child('c2pa.assertions');
    assert($assertions !== null);
    $store = substr_replace($store, $extra, $assertions->offset + $assertions->length, 0);

    return $put(spec045Grow($store, [0, $manifest->offset, $assertions->offset], strlen($extra)));
}

/** A small definite-length CBOR encoder for the claim rewrite. */
function spec045Cbor(mixed $v): string
{
    $head = static fn (int $major, int $n): string => match (true) {
        $n < 24 => pack('C', $major << 5 | $n),
        $n < 256 => pack('CC', $major << 5 | 24, $n),
        $n < 65536 => pack('Cn', $major << 5 | 25, $n),
        default => pack('CN', $major << 5 | 26, $n),
    };

    return match (true) {
        is_int($v) => $v >= 0 ? $head(0, $v) : $head(1, -1 - $v),
        is_string($v) => $head(3, strlen($v)).$v,
        $v instanceof CborBytes => $head(2, strlen($v->bytes)).$v->bytes,
        $v instanceof CborTag => $head(6, $v->number).spec045Cbor($v->value),
        is_bool($v) => $v ? "\xf5" : "\xf4",
        $v === null => "\xf6",
        is_array($v) && array_is_list($v) => $head(4, count($v)).implode('', array_map(spec045Cbor(...), $v)),
        is_array($v) => $head(5, count($v)).implode('', array_map(static fn (mixed $k, mixed $x): string => spec045Cbor($k).spec045Cbor($x), array_keys($v), $v)),
        default => throw new RuntimeException(get_debug_type($v)),
    };
}

/**
 * fixture-signed.png with one assertion of $bytes bytes added and a claim whose created_assertions
 * names it $references times (the claim is changed, so its signature fails).
 */
function spec045References(int $references, int $bytes): string
{
    [$store, $put] = spec045Store();
    $root = (new JumbfParser)->parse($store);
    $manifest = $root->superboxes()[count($root->superboxes()) - 1];
    $assertions = $manifest->child('c2pa.assertions');
    $claimBox = $manifest->child('c2pa.claim.v2');
    assert($assertions !== null && $claimBox !== null && $claimBox->offset > $assertions->offset);
    $claimCbor = $claimBox->contentBoxes()[0];
    $claim = (new CborDecoder)->decode($claimCbor->data);
    assert(is_array($claim));
    $claim['created_assertions'] = array_fill(0, $references, ['url' => 'self#jumbf=c2pa.assertions/org.example.big', 'hash' => new CborBytes(str_repeat("\0", 32))]);
    $newClaim = spec045Cbor($claim);
    $big = spec045Box('jumb', spec045Box('jumd', (string) hex2bin('63626f7200110010800000aa00389b71')."\x03org.example.big\0").spec045Box('cbor', "\x5a".pack('N', $bytes).str_repeat('A', $bytes)));

    // the claim first (it lies after the assertion store), then the new assertion
    $delta = strlen($newClaim) - strlen($claimCbor->data);
    $store = substr_replace($store, $newClaim, $claimCbor->offset + 8, strlen($claimCbor->data));
    $store = spec045Grow($store, [$claimCbor->offset, $claimBox->offset], $delta);
    $store = substr_replace($store, $big, $assertions->offset + $assertions->length, 0);
    $store = spec045Grow($store, [$assertions->offset], strlen($big));

    return $put(spec045Grow($store, [0, $manifest->offset], $delta + strlen($big)));
}

/** fixture-signed.png with an unprotected COSE header "zzz" holding a byte string of $chunks empty chunks. */
function spec045CoseChunks(int $chunks): string
{
    [$store, $put] = spec045Store();
    $root = (new JumbfParser)->parse($store);
    $manifest = $root->superboxes()[count($root->superboxes()) - 1];
    $signature = $manifest->child('c2pa.signature');
    assert($signature !== null);
    $cbor = $signature->contentBoxes()[0];
    $cose = $cbor->data;
    // after the tag and the array head: the protected header's byte string, then the unprotected map
    $ai = ord($cose[2]) & 0x1F;
    $protected = match (true) {
        $ai < 24 => 1 + $ai,
        $ai === 24 => 2 + ord($cose[3]),
        default => 3 + (int) hexdec(bin2hex(substr($cose, 3, 2))),
    };
    $mapAt = 2 + $protected;
    $mapByte = ord($cose[$mapAt]);
    assert($mapByte >> 5 === 5 && ($mapByte & 0x1F) < 23);
    $newCose = substr($cose, 0, $mapAt).pack('C', $mapByte + 1)."\x63zzz\x5f".str_repeat("\x40", $chunks)."\xff".substr($cose, $mapAt + 1);
    $store = substr_replace($store, $newCose, $cbor->offset + 8, strlen($cose));

    return $put(spec045Grow($store, [0, $manifest->offset, $signature->offset, $cbor->offset], strlen($newCose) - strlen($cose)));
}

it('AC1: a JSON assertion is bounded', function (): void {
    // 4 MB of [[0],[0],…]: refused before json_decode(), with the limit named
    $json = '['.rtrim(str_repeat('[0],', 1048576), ',').']';
    $jsonAssertion = spec045Box('jumb', spec045Box('jumd', (string) hex2bin('6a736f6e00110010800000aa00389b71')."\x03org.example.big\0").spec045Box('json', $json));
    $report = spec045Verify(spec045PngWithAssertions($jsonAssertion));
    $jsonInvalid = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->value === 'assertion.json.invalid'));
    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and(count($jsonInvalid))->toBe(1)
        ->and($jsonInvalid[0]->explanation ?? '')->toContain('262144')
        ->and(strlen($report->toJson()))->toBeGreaterThan(0);

    // 200 KiB of one string: within the limit, decoded, Invalid for the undeclared assertion only
    $string = spec045Verify(spec045File('json-string-200k'));
    $codes = array_map(static fn (ValidationStatus $s): string => $s->code->value.' '.substr($s->url, (int) strrpos($s->url, '/') + 1), $string->result->statuses);
    expect(in_array('assertion.undeclared org.example.string', $codes, true))->toBeTrue()
        ->and(spec045Names($string, '262144'))->toBeFalse()
        ->and(spec045Names($string, SPEC045_ITEM_LIMIT))->toBeFalse();

    // two arrays of about 51,200 items each: within the budget alone, over it together
    $numbers = spec045Verify(spec045File('json-numbers-2x150k'));
    expect($numbers->result->state)->toBe(ValidationState::Invalid)
        ->and(spec045Names($numbers, SPEC045_ITEM_LIMIT))->toBeTrue();
})->group('SPEC-045');

it('AC2: one assertion referenced many times is hashed once', function (): void {
    $file = spec045References(1000, 8 * 1048576);
    $start = microtime(true);
    $report = spec045Verify($file);
    $seconds = microtime(true) - $start;
    $hashed = array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => str_starts_with($s->code->value, 'assertion.hashedURI.') && str_ends_with($s->url, '/org.example.big'));
    expect($seconds)->toBeLessThan(2.0)
        ->and(count($hashed))->toBe(1000);
})->group('SPEC-045');

it('AC3: the chunks of a string are charged', function (): void {
    expect(fn () => (new CborDecoder)->decode("\x5f".str_repeat("\x40", 70000)."\xff"))->toThrow(CborException::class, SPEC045_ITEM_LIMIT);
    $joined = (new CborDecoder)->decode("\x5f".str_repeat("\x41a", 1000)."\xff");
    expect($joined)->toBeInstanceOf(CborBytes::class)
        ->and($joined instanceof CborBytes ? $joined->bytes : null)->toBe(str_repeat('a', 1000));

    $file = spec045CoseChunks(2_000_000);
    $start = microtime(true);
    $report = spec045Verify($file);
    $seconds = microtime(true) - $start;
    expect($seconds)->toBeLessThan(2.0)
        ->and($report->result->state)->toBe(ValidationState::Invalid)
        ->and(spec045Names($report, SPEC045_ITEM_LIMIT))->toBeTrue();
})->group('SPEC-045');

it('AC4: an empty bfdb is refused, not thrown', function (): void {
    $report = spec045Verify(spec045File('bfdb-empty'));
    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and(spec045Names($report, 'no media type'))->toBeTrue();

    $out = fopen('php://memory', 'w+b');
    $err = fopen('php://memory', 'w+b');
    assert($out !== false && $err !== false);
    $status = (new Command(new Verifier))->run([Corpus::fixtures().'/hostile-2/bfdb-empty.png'], $out, $err);
    expect($status)->toBe(1);
})->group('SPEC-045');
