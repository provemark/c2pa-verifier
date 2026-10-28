<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborBudget;
use Provemark\C2paVerifier\Cbor\CborDecoder;
use Provemark\C2paVerifier\Cbor\CborException;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Timestamp\TimestampAssertions;
use Provemark\C2paVerifier\Timestamp\TimestampCheck;
use Provemark\C2paVerifier\Timestamp\TimestampEvidence;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-088: three status codes brought to the letter of C2PA 2.4 (group F of
 * step 426). The probes and c2patool's answers were made in step 481.
 */

/** A fixture verified, under the settings file beside it when $settings names one. */
function spec088Verify(string $file, ?string $settings = null): VerificationReport
{
    $root = Corpus::fixtures();
    $stream = fopen("{$root}/{$file}", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$file}");
    }
    try {
        return (new Verifier)->verify($stream, $settings === null ? null : TrustSettings::fromJson((string) file_get_contents("{$root}/{$settings}")));
    } finally {
        fclose($stream);
    }
}

/** @return list<array{0: string, 1: string, 2: string}> code, url and explanation of every status */
function spec088Statuses(VerificationReport $report): array
{
    return array_map(static fn ($s): array => [$s->code->value, $s->url, $s->explanation], $report->result->statuses);
}

/** @return list<array{0: string, 1: string, 2: string}> the timeStamp.* statuses */
function spec088TimeStamps(VerificationReport $report): array
{
    return array_values(array_filter(spec088Statuses($report), static fn (array $s): bool => str_starts_with($s[0], 'timeStamp.')));
}

/** @return list<string> the codes */
function spec088Codes(VerificationReport $report): array
{
    return array_map(static fn (array $s): string => $s[0], spec088Statuses($report));
}

function spec088State(VerificationReport $report): string
{
    $state = $report->toArray()['validation_state'] ?? '';

    return is_string($state) ? $state : '';
}

/** @return list<string> the timeStamp.* codes in a stored c2patool answer */
function spec088OracleTimeStamps(string $path): array
{
    preg_match_all('/"code": "(timeStamp\.[A-Za-z]+)"/', (string) file_get_contents(Corpus::fixtures()."/c2patool/{$path}"), $m);

    return array_values(array_unique($m[1]));
}

it('AC1: an assertion that is not well-formed CBOR is assertion.cbor.invalid', function (string $probe, string $fault): void {
    $report = spec088Verify("claim/{$probe}.png");
    $statuses = spec088Statuses($report);

    expect(spec088Codes($report))->toBe(['assertion.cbor.invalid'])
        ->and($statuses[0][1] ?? '')->toEndWith('/c2pa.assertions/c2pa.actions.v2')
        ->and($statuses[0][2] ?? '')->toContain('invalid CBOR')
        ->and($statuses[0][2] ?? '')->toContain($fault)
        ->and(spec088State($report))->toBe('Invalid')
        ->and((string) file_get_contents(Corpus::fixtures()."/c2patool/variants/{$probe}--0.28.3.txt"))->toContain('could not decode assertion c2pa.actions.v2');
})->with([
    'reserved' => ['assertion-cbor-reserved', 'additional information 28'],
    'break' => ['assertion-cbor-break', 'break'],
])->group('SPEC-088');

it('AC2: a SHA-1 messageImprint is timeStamp.untrusted', function (string $probe, string $state, string $code): void {
    $report = spec088Verify("timestamp/tsa-matrix/{$probe}.png", "timestamp/tsa-matrix/{$probe}.settings.json");
    $stamps = spec088TimeStamps($report);

    expect(array_map(static fn (array $s): string => $s[0], $stamps))->toBe(['timeStamp.untrusted'])
        ->and($stamps[0][2] ?? '')->toContain('1.3.14.3.2.26')
        ->and(spec088State($report))->toBe($state)
        ->and(spec088Codes($report))->toContain($code)
        ->and(spec088OracleTimeStamps("tsa-matrix/{$probe}--0.28.3.json"))->toBe(['timeStamp.untrusted']);
})->with([
    'imprint-sha1' => ['imprint-sha1', 'Trusted', 'signingCredential.trusted'],
    'expired-signer-imprint-sha1' => ['expired-signer-imprint-sha1', 'Invalid', 'signingCredential.expired'],
])->group('SPEC-088');

it('AC3: a token without its TSA certificate is timeStamp.untrusted', function (string $probe, array $oracle): void {
    $report = spec088Verify("timestamp/tsa-matrix/{$probe}.png", "timestamp/tsa-matrix/{$probe}.settings.json");
    $stamps = spec088TimeStamps($report);

    expect(array_map(static fn (array $s): string => $s[0], $stamps))->toBe(['timeStamp.untrusted'])
        ->and(spec088State($report))->toBe('Trusted')
        ->and(spec088OracleTimeStamps("tsa-matrix/{$probe}--0.28.3.json"))->toBe($oracle);
})->with([
    'no certificates' => ['tsa-cert-absent', ['timeStamp.untrusted']],
    'the intermediate only' => ['tsa-cert-absent-chain-only', []],
])->group('SPEC-088');

it('AC3: the explanation names what is missing', function (): void {
    $absent = spec088TimeStamps(spec088Verify('timestamp/tsa-matrix/tsa-cert-absent.png'));
    $chainOnly = spec088TimeStamps(spec088Verify('timestamp/tsa-matrix/tsa-cert-absent-chain-only.png'));

    expect($absent[0][2] ?? '')->toContain('no certificates')
        ->and($chainOnly[0][2] ?? '')->toContain('sid');
})->group('SPEC-088');

it('AC4: other faults of a token stay timeStamp.malformed', function (string $file, string $why): void {
    $stamps = spec088TimeStamps(spec088Verify($file));

    expect(array_map(static fn (array $s): string => $s[0], $stamps))->toContain('timeStamp.malformed')
        ->and(implode(' ', array_map(static fn (array $s): string => $s[2], $stamps)))->toContain($why);
})->with([
    'a genTime that is not a date' => ['c2pa-rs/CA_ct.jpg', 'is not a date'],
    'two tokens' => ['timestamp/tsa-matrix/two-tokens.png', 'carries 2 tokens'],
    'both headers' => ['timestamp/tsa-matrix/header-both.png', 'both sigTst and sigTst2'],
])->group('SPEC-088');

it('AC6: assertion.cbor.invalid is a failure code', function (): void {
    $code = StatusCode::from('assertion.cbor.invalid');

    expect($code->name)->toBe('AssertionCborInvalid')
        ->and($code->isFailure())->toBeTrue();
})->group('SPEC-088');

// Written after the build, when mutation M6 survived: no corpus file offers a SHA-1 imprint token in a time-stamp
// assertion. raw.png's ingredient is offered imprint-sha1.png's token before its own.
it('scope: a time-stamp assertion\'s token with a SHA-1 imprint is timeStamp.untrusted too', function (): void {
    $root = Corpus::fixtures();
    $sha1 = spec088Verify('timestamp/tsa-matrix/imprint-sha1.png', 'timestamp/tsa-matrix/imprint-sha1.settings.json');
    $token = array_values($sha1->evidence())[0]->timestamps[0]->token ?? '';
    $store = spec088Verify('timestamp/assertion/raw.png', 'timestamp/assertion/raw.settings.json')->store;
    $ingredient = 'urn:c2pa:c7cb9f11-e1ba-4a20-9c1b-fe2c6dc553d9';
    $manifest = $store->manifests[$ingredient] ?? throw new RuntimeException('no ingredient manifest');
    [[$der, $url]] = TimestampAssertions::collect($store)->tokensFor($ingredient);
    $offered = Closure::bind(static fn (): TimestampAssertions => new TimestampAssertions([$ingredient => [[$token, $url], [$der, $url]]], []), null, TimestampAssertions::class)();
    $result = (new TimestampCheck)->forManifest($manifest, TrustSettings::fromJson((string) file_get_contents("{$root}/timestamp/assertion/raw.settings.json")), $offered);

    expect($token)->not->toBe('')
        ->and(array_map(static fn (TimestampEvidence $e): array => [$e->outcome, array_map(static fn (StatusCode $c): string => $c->value, $e->codes)], $result->evidence))
        ->toBe([
            [TimestampEvidence::REJECTED, ['timeStamp.untrusted']],
            [TimestampEvidence::USED, ['timeStamp.validated', 'timeStamp.trusted']],
        ]);
})->group('SPEC-088');

// ---- amendment 1 (step 485): only CBOR that is not valid is assertion.cbor.invalid ----

it('amendment 1: an assertion that is not valid CBOR is assertion.cbor.invalid; one beyond this decoder is general.error', function (string $probe, string $code, string $fault, string $oracle): void {
    $report = spec088Verify("claim/{$probe}.png");
    $statuses = spec088Statuses($report);

    expect(spec088Codes($report))->toBe([$code])
        ->and($statuses[0][1] ?? '')->toEndWith('/c2pa.assertions/c2pa.actions.v2')
        ->and($statuses[0][2] ?? '')->toContain($fault)
        ->and(spec088State($report))->toBe('Invalid')
        ->and((string) file_get_contents(Corpus::fixtures()."/c2patool/variants/{$probe}--0.28.3.txt"))->toContain($oracle);
})->with([
    'reserved' => ['assertion-cbor-reserved', 'assertion.cbor.invalid', 'additional information 28', 'Invalid CBOR value'],
    'break' => ['assertion-cbor-break', 'assertion.cbor.invalid', 'break', 'Invalid CBOR value'],
    'bytes after the item' => ['assertion-cbor-trailing', 'assertion.cbor.invalid', 'remain after the value', 'invalid type: integer `0`'],
    'not UTF-8' => ['assertion-cbor-utf8', 'assertion.cbor.invalid', 'not valid UTF-8', 'Invalid UTF-8'],
    'a duplicate key' => ['assertion-cbor-duplicate', 'assertion.cbor.invalid', 'duplicate map key', 'missing field `actions`'],
    'depth 33' => ['assertion-cbor-depth', 'general.error', 'exceeds the limit of 32', 'invalid type: sequence'],
    'undefined' => ['assertion-cbor-undefined', 'general.error', '(undefined) at offset 0 is not supported', 'invalid type: unit value'],
    'above 2^63 - 1' => ['assertion-cbor-bigint', 'general.error', 'does not fit a 64-bit signed integer', 'invalid type: integer `18446744073709551615`'],
])->group('SPEC-088');

it('amendment 1: the decoder tells a limit or an item outside its subset from CBOR that is not valid', function (string $bytes, int $budget, bool $limit): void {
    try {
        (new CborDecoder)->decode($bytes, new CborBudget($budget));
        $code = null;
    } catch (CborException $e) {
        $code = $e->getCode();
    }

    expect($code)->toBe($limit ? CborException::LIMIT : 0);
})->with([
    'the store-wide budget' => ["\x82\x00\x00", 2, true],
    // amendment 2: a count above the limit whose items are there is a limit; one the bytes cannot hold is truncation
    'a declared count above the limit' => ["\x9a\x00\x01\x00\x01".str_repeat("\x00", 65537), 70000, true],
    'a count of 2^63 - 1 in nine bytes' => ["\x9b\x7f\xff\xff\xff\xff\xff\xff\xff", 10, false],
    'a count of 2^64 - 1 in nine bytes' => ["\x9b".str_repeat("\xff", 8), 10, false],
    'a million items, then one byte' => ["\x9a\x00\x0f\x42\x40\x01", 10, false],
    'a map of two entries in three bytes' => ["\xa2\x00\x00", 10, false],
    'a map of two entries in four bytes' => ["\xa2\x00\x00\x01", 10, false],
    // the first fault met decides: a limit before the end of the input stays a limit
    'depth 33, then the bytes stop' => [str_repeat("\x81", 40), 100, true],
    // step 493: the 33rd array's head is a limit before its count is weighed against the bytes left
    'depth 33, the bytes stopping at the 33rd array' => [str_repeat("\x81", 33), 100, true],
    'a map at depth 33, the bytes stopping there' => [str_repeat("\x81", 32)."\xa1", 100, true],
    'a byte-string key, then the bytes stop' => ["\xa1\x41\x61", 10, true],
    'a map of two entries in three bytes, a byte-string key first' => ["\xa2\x41\x61", 10, false],
    'a key that is not an integer or text' => ["\xa1\x80\x00", 10, true],
    'a text key that would collide with an integer' => ["\xa1\x61\x31\x00", 10, true],
    'a simple value of 32 or more' => ["\xf8\x20", 10, true],
    'a tag number above 2^63 - 1' => ["\xdb\xff\xff\xff\xff\xff\xff\xff\xff\x00", 10, true],
    'a two-byte simple value below 32' => ["\xf8\x10", 10, false],
    'truncated' => ["\x82\x00", 10, false],
    'indefinite-length unsigned integer' => ["\x1f", 10, false],
    'a chunk of another type' => ["\x5f\x61\x61\xff", 10, false],
])->group('SPEC-088');

// Written after the build, when mutation M2 survived: the claim keeps claim.cbor.invalid for a limit (SPEC-013).
it('amendment 1: the claim keeps claim.cbor.invalid for CBOR beyond this decoder', function (): void {
    $report = spec088Verify('claim/claim-cbor-undefined.png');
    $statuses = spec088Statuses($report);

    expect(spec088Codes($report))->toBe(['claim.cbor.invalid'])
        ->and($statuses[0][2] ?? '')->toContain('invalid CBOR: simple value 23 (undefined)')
        ->and(spec088State($report))->toBe('Invalid')
        ->and((string) file_get_contents(Corpus::fixtures().'/c2patool/variants/claim-cbor-undefined--0.28.3.txt'))->toContain('claim could not be converted from CBOR');
})->group('SPEC-088');
