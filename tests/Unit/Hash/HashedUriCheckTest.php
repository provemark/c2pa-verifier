<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Container\WebpManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\ClaimSignatureCheck;
use Provemark\C2paVerifier\Hash\HashedUriCheck;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\HashedUri;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationResult;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;

/*
 * SPEC-011: the hashed-URI check — every assertion the claim names, hashed
 * and compared. The oracle JSON is under tests/Fixtures/c2patool/ (fixtures,
 * step 14) and tests/Fixtures/c2patool/variants/ (steps 23 and 24); the
 * variant stores under tests/Fixtures/binding/ (steps 23 and 24).
 */

const SPEC011_PNG = 'self#jumbf=/c2pa/urn:c2pa:488bf983-c973-465d-a0eb-1597392cc5d0';

function spec011Manifest(string $fixture): Manifest
{
    $stream = fopen(dirname(__DIR__, 2).'/Fixtures/'.$fixture, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$fixture}");
    }
    $extractor = match (pathinfo($fixture, PATHINFO_EXTENSION)) {
        'jpg' => new JpegManifestStoreExtractor,
        'png' => new PngManifestStoreExtractor,
        'webp' => new WebpManifestStoreExtractor,
        default => throw new RuntimeException("no extractor for {$fixture}"),
    };
    $store = $extractor->extract($stream);
    if ($store === null) {
        throw new RuntimeException("no manifest store in {$fixture}");
    }

    return ManifestStore::fromTree((new JumbfParser)->parse($store->bytes))->active;
}

/** The active manifest of a binding variant's store. */
function spec011Variant(string $name): Manifest
{
    return ManifestStore::fromTree((new JumbfParser)->parse((string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/binding/{$name}.bin")))->active;
}

/** @return array<string, mixed> */
function spec011C2patool(string $name, bool $variant = false): array
{
    $file = dirname(__DIR__, 2).'/Fixtures/c2patool/'.($variant ? 'variants/' : '').$name.'.json';

    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The code/url pairs of a recorded c2patool list whose code starts with $prefix, sorted by url.
 *
 * @param  array<string, mixed>  $oracle
 * @return list<array{code: string, url: string}>
 */
function spec011OraclePairs(array $oracle, string $kind, string $prefix): array
{
    $results = $oracle['validation_results'];
    assert(is_array($results) && is_array($results['activeManifest']) && is_array($results['activeManifest'][$kind]));
    $pairs = [];
    foreach ($results['activeManifest'][$kind] as $status) {
        assert(is_array($status) && is_string($status['code']) && is_string($status['url']));
        if (str_starts_with($status['code'], $prefix)) {
            $pairs[] = ['code' => $status['code'], 'url' => $status['url']];
        }
    }
    usort($pairs, static fn (array $a, array $b): int => strcmp($a['url'], $b['url']));

    return $pairs;
}

/**
 * Our statuses as code/url pairs, sorted by url.
 *
 * @param  list<ValidationStatus>  $statuses
 * @return list<array{code: string, url: string}>
 */
function spec011Pairs(array $statuses): array
{
    $pairs = array_map(static fn (ValidationStatus $s): array => ['code' => $s->code->value, 'url' => $s->url], $statuses);
    usort($pairs, static fn (array $a, array $b): int => strcmp($a['url'], $b['url']));

    return $pairs;
}

/**
 * Our statuses as label => code, in the order returned (the PNG store's three assertions).
 *
 * @param  list<ValidationStatus>  $statuses
 * @return list<array{0: string, 1: string}>
 */
function spec011Codes(array $statuses): array
{
    return array_map(static fn (ValidationStatus $s): array => [substr($s->url, strrpos($s->url, '/') + 1), $s->code->value], $statuses);
}

it('AC1: the four fixtures: every entry matches, and the urls are c2patool\'s', function (): void {
    foreach (['png' => ['fixture-signed.png', 3], 'jpg' => ['fixture-signed.jpg', 3], 'webp' => ['fixture-signed.webp', 3], 'adobe-20220124-C' => ['public-testfiles/adobe-20220124-C.jpg', 4]] as $name => [$fixture, $entries]) {
        $manifest = spec011Manifest($fixture);
        expect(count($manifest->claim->createdAssertions) + count($manifest->claim->gatheredAssertions))->toBe($entries, $name);

        $statuses = (new HashedUriCheck)->check($manifest);
        expect($statuses)->toHaveCount($entries, $name);
        foreach ($statuses as $status) {
            expect($status->code)->toBe(StatusCode::AssertionHashedUriMatch, "{$name}: {$status->url}")
                ->and($status->url)->toStartWith("self#jumbf=/c2pa/{$manifest->label}/c2pa.assertions/", $name);
        }

        expect(spec011Pairs($statuses))->toBe(spec011OraclePairs(spec011C2patool($name), 'success', 'assertion.hashedURI'), $name);

        $result = ValidationResult::fromStatuses($statuses, ['hashedUris']);
        expect($result->state)->toBe(ValidationState::Valid, $name)
            ->and($result->checksPerformed)->toBe(['hashedUris'], $name);
    }
})->group('SPEC-011');

it('AC2: the assertion changed, the claim not: assertion.hashedURI.mismatch on that entry alone', function (): void {
    foreach (['pad-nonzero', 'alg-sha1', 'exclusions-overlap', 'exclusion-past-end'] as $variant) {
        $statuses = (new HashedUriCheck)->check(spec011Variant($variant));
        expect(spec011Codes($statuses))->toBe([
            ['c2pa.hash.data', 'assertion.hashedURI.mismatch'],
            ['c2pa.thumbnail.claim', 'assertion.hashedURI.match'],
            ['c2pa.actions.v2', 'assertion.hashedURI.match'],
        ], $variant)
            ->and($statuses[0]->url)->toBe(SPEC011_PNG.'/c2pa.assertions/c2pa.hash.data', $variant)
            ->and(ValidationResult::fromStatuses($statuses, ['hashedUris'])->state)->toBe(ValidationState::Invalid, $variant);
    }

    $oracle = spec011OraclePairs(spec011C2patool('exclusions-overlap', true), 'failure', 'assertion.hashedURI');
    expect($oracle)->toBe([['code' => 'assertion.hashedURI.mismatch', 'url' => SPEC011_PNG.'/c2pa.assertions/c2pa.hash.data']]);
})->group('SPEC-011');

it('AC3: the claim\'s hash changed: mismatch, and the check goes on', function (): void {
    $one = (new HashedUriCheck)->check(spec011Variant('hashed-uri-changed'));
    expect(spec011Codes($one))->toBe([
        ['c2pa.hash.data', 'assertion.hashedURI.mismatch'],
        ['c2pa.thumbnail.claim', 'assertion.hashedURI.match'],
        ['c2pa.actions.v2', 'assertion.hashedURI.match'],
    ]);

    $two = (new HashedUriCheck)->check(spec011Variant('hashed-uris-two-changed'));
    expect(spec011Codes($two))->toBe([
        ['c2pa.hash.data', 'assertion.hashedURI.mismatch'],
        ['c2pa.thumbnail.claim', 'assertion.hashedURI.mismatch'],
        ['c2pa.actions.v2', 'assertion.hashedURI.match'],
    ]);

    // c2patool agrees on both, code and url
    expect(spec011OraclePairs(spec011C2patool('hashed-uri-changed', true), 'failure', 'assertion.hashedURI'))
        ->toBe([['code' => 'assertion.hashedURI.mismatch', 'url' => SPEC011_PNG.'/c2pa.assertions/c2pa.hash.data']]);
    $mismatches = array_values(array_filter($two, static fn (ValidationStatus $s): bool => $s->code === StatusCode::AssertionHashedUriMismatch));
    expect(spec011Pairs($mismatches))->toBe(spec011OraclePairs(spec011C2patool('hashed-uris-two-changed', true), 'failure', 'assertion.hashedURI'));
})->group('SPEC-011');

it('AC4: a digest of the wrong length is a mismatch, not an error', function (): void {
    $manifest = spec011Variant('hashed-uri-truncated');
    expect(strlen($manifest->claim->createdAssertions[0]->hash->bytes))->toBe(31);

    $statuses = (new HashedUriCheck)->check($manifest);
    expect(spec011Codes($statuses))->toBe([
        ['c2pa.hash.data', 'assertion.hashedURI.mismatch'],
        ['c2pa.thumbnail.claim', 'assertion.hashedURI.match'],
        ['c2pa.actions.v2', 'assertion.hashedURI.match'],
    ])
        ->and($statuses[0]->explanation)->toContain('31')
        ->and($statuses[0]->explanation)->toContain('32')
        ->and(ValidationResult::fromStatuses($statuses, ['hashedUris'])->state)->toBe(ValidationState::Invalid);
})->group('SPEC-011');

it('AC5: a box the claim does not name: assertion.undeclared', function (): void {
    $relabelled = (new HashedUriCheck)->check(spec011Variant('assertion-undeclared'));
    expect(spec011Codes($relabelled))->toBe([
        ['c2pa.hash.data', 'assertion.hashedURI.match'],
        ['c2pa.thumbnail.claim', 'assertion.hashedURI.match'],
        ['c2pa.actions.v2', 'assertion.hashedURI.match'],
        ['c2pa.extraz.v2x', 'assertion.undeclared'],
    ])
        ->and($relabelled[3]->url)->toBe(SPEC011_PNG.'/c2pa.assertions/c2pa.extraz.v2x')
        ->and(ValidationResult::fromStatuses($relabelled, ['hashedUris'])->state)->toBe(ValidationState::Invalid);

    // the same label twice: the entry resolved to the first box, the second (at 33026) is nobody's
    $duplicate = (new HashedUriCheck)->check(spec011Variant('assertion-duplicate-label'));
    expect(spec011Codes($duplicate))->toBe([
        ['c2pa.hash.data', 'assertion.hashedURI.match'],
        ['c2pa.thumbnail.claim', 'assertion.hashedURI.match'],
        ['c2pa.actions.v2', 'assertion.hashedURI.match'],
        ['c2pa.actions.v2', 'assertion.undeclared'],
    ])
        ->and($duplicate[3]->url)->toBe(SPEC011_PNG.'/c2pa.assertions/c2pa.actions.v2')
        ->and($duplicate[3]->explanation)->toContain('33026')
        ->and(ValidationResult::fromStatuses($duplicate, ['hashedUris'])->state)->toBe(ValidationState::Invalid);
})->group('SPEC-011');

it('AC6: an unknown box in the store is undeclared too', function (): void {
    $manifest = spec011Variant('assertion-undeclared-unknown-uuid');
    expect($manifest->assertions)->toHaveCount(3);   // the unknown box is not an Assertion (SPEC-005 AC7)

    $statuses = (new HashedUriCheck)->check($manifest);
    expect(spec011Codes($statuses))->toBe([
        ['c2pa.hash.data', 'assertion.hashedURI.match'],
        ['c2pa.thumbnail.claim', 'assertion.hashedURI.match'],
        ['c2pa.actions.v2', 'assertion.hashedURI.match'],
        ['c2pa.assertions', 'assertion.undeclared'],
    ])
        ->and($statuses[3]->url)->toBe(SPEC011_PNG.'/c2pa.assertions')
        ->and($statuses[3]->explanation)->toContain('33026')
        ->and($statuses[3]->explanation)->toContain('deadbeef-0011-0010-8000-00aa00389b71')
        ->and(ValidationResult::fromStatuses($statuses, ['hashedUris'])->state)->toBe(ValidationState::Invalid);
})->group('SPEC-011');

it('AC7: the algorithm: the entry\'s, else the claim\'s, else unsupported', function (): void {
    $own = spec011Variant('uri-alg-sha384');
    expect($own->claim->createdAssertions[0]->alg)->toBe('sha384')
        ->and(strlen($own->claim->createdAssertions[0]->hash->bytes))->toBe(48)
        ->and($own->claim->alg)->toBe('sha256');
    expect(spec011Codes((new HashedUriCheck)->check($own)))->toBe([
        ['c2pa.hash.data', 'assertion.hashedURI.match'],
        ['c2pa.thumbnail.claim', 'assertion.hashedURI.match'],
        ['c2pa.actions.v2', 'assertion.hashedURI.match'],
    ]);

    $sha1 = spec011Variant('claim-alg-sha1');
    expect($sha1->claim->alg)->toBe('sha1');
    $statuses = (new HashedUriCheck)->check($sha1);
    expect(spec011Codes($statuses))->toBe([
        ['c2pa.hash.data', 'algorithm.unsupported'],
        ['c2pa.thumbnail.claim', 'algorithm.unsupported'],
        ['c2pa.actions.v2', 'algorithm.unsupported'],
    ]);
    foreach ($statuses as $status) {
        expect($status->explanation)->toContain('sha1');
    }
    expect(ValidationResult::fromStatuses($statuses, ['hashedUris'])->state)->toBe(ValidationState::Invalid);

    $missing = spec011Variant('claim-alg-missing');
    expect($missing->claim->alg)->toBeNull();
    $statuses = (new HashedUriCheck)->check($missing);
    expect(spec011Codes($statuses))->toBe([
        ['c2pa.hash.data', 'algorithm.unsupported'],
        ['c2pa.thumbnail.claim', 'algorithm.unsupported'],
        ['c2pa.actions.v2', 'algorithm.unsupported'],
    ]);
    foreach ($statuses as $status) {
        expect($status->explanation)->toContain('no algorithm');
    }
    expect(ValidationResult::fromStatuses($statuses, ['hashedUris'])->state)->toBe(ValidationState::Invalid);

    // the two checks are independent: these variants changed the claim, so the signature fails, the hashed URIs answer on their own
    foreach ([$own, $sha1, $missing] as $manifest) {
        expect((new ClaimSignatureCheck)->check($manifest)[0]->code)->toBe(StatusCode::ClaimSignatureMismatch);
    }
})->group('SPEC-011');

it('AC8: a redaction is read by SPEC-035\'s rules', function (): void {
    // amendment 3: SPEC-035 lifted the refusal; a relative entry naming the actions is
    // assertion.action.redacted on the entry as written, and nothing else (SPEC-035 amendment 2)
    $manifest = spec011Variant('claim-redacted');
    expect($manifest->claim->other['redacted_assertions'])->toBe(['self#jumbf=c2pa.assertions/c2pa.actions.v2']);

    $statuses = (new HashedUriCheck)->check($manifest);
    expect(spec011Codes($statuses))->toBe([
        ['c2pa.hash.data', 'assertion.hashedURI.match'],
        ['c2pa.thumbnail.claim', 'assertion.hashedURI.match'],
        ['c2pa.actions.v2', 'assertion.hashedURI.match'],
        ['c2pa.actions.v2', 'assertion.action.redacted'],
    ])
        ->and($statuses[3]->url)->toBe('self#jumbf=c2pa.assertions/c2pa.actions.v2')
        ->and(ValidationResult::fromStatuses($statuses, ['hashedUris'])->state)->toBe(ValidationState::Invalid);
})->group('SPEC-011');

it('AC9: the codes are verbatim, and success is told apart', function (): void {
    // SPEC-011's fifteen; SPEC-012 added the six of the data hash (its AC10 asserts the full twenty-one)
    $values = array_map(static fn (StatusCode $c): string => $c->value, StatusCode::cases());
    foreach ([
        'algorithm.unsupported', 'assertion.hashedURI.match', 'assertion.hashedURI.mismatch',
        'assertion.json.invalid', 'assertion.missing', 'assertion.undeclared',
        'claim.cbor.invalid', 'claim.malformed', 'claim.missing', 'claim.multiple',
        'claimSignature.mismatch', 'claimSignature.missing', 'claimSignature.validated',
        'general.error', 'signingCredential.invalid',
    ] as $value) {
        expect($values)->toContain($value);
    }
    $successes = [StatusCode::ClaimSignatureValidated, StatusCode::AssertionHashedUriMatch, StatusCode::AssertionBmffHashMatch];   // the last added by SPEC-027
    foreach (StatusCode::cases() as $code) {
        if (str_starts_with($code->value, 'assertion.dataHash') || str_contains($code->value, 'HardBindings') || in_array($code->value, ['signingCredential.trusted', 'signingCredential.untrusted'], true) || str_starts_with($code->value, 'timeStamp.') || str_starts_with($code->value, 'ingredient.') || str_starts_with($code->value, 'signingCredential.ocsp.')) {
            continue;   // SPEC-012's, SPEC-014's, SPEC-017's, SPEC-020's and SPEC-030's
        }
        expect($code->isSuccess())->toBe(in_array($code, $successes, true), $code->value)
            ->and($code->isFailure())->toBe(! in_array($code, $successes, true), $code->value);
    }

    $match = static fn (string $label): ValidationStatus => new ValidationStatus(StatusCode::AssertionHashedUriMatch, SPEC011_PNG.'/c2pa.assertions/'.$label, 'ok');
    $undeclared = new ValidationStatus(StatusCode::AssertionUndeclared, SPEC011_PNG.'/c2pa.assertions/c2pa.extraz.v2x', 'no');
    expect(ValidationResult::fromStatuses([$match('a'), $match('b'), $match('c')], ['hashedUris'])->state)->toBe(ValidationState::Valid)
        ->and(ValidationResult::fromStatuses([$match('a'), $match('b'), $match('c'), $undeclared], ['hashedUris'])->state)->toBe(ValidationState::Invalid);
})->group('SPEC-011');

it('AC10: a ManifestException inside the check becomes its status, never escapes', function (): void {
    $manifest = spec011Manifest('fixture-signed.png');
    $nowhere = new HashedUri('self#jumbf=c2pa.assertions/c2pa.nowhere', new CborBytes(str_repeat("\0", 32)), null);

    $status = (new HashedUriCheck)->checkEntry($manifest, $nowhere);
    expect($status->code)->toBe(StatusCode::AssertionMissing)
        ->and($status->url)->toBe('self#jumbf=c2pa.assertions/c2pa.nowhere')
        ->and($status->explanation)->toContain('c2pa.nowhere');

    // the real entries still answer through the same seam
    $real = (new HashedUriCheck)->checkEntry($manifest, $manifest->claim->createdAssertions[0]);
    expect($real->code)->toBe(StatusCode::AssertionHashedUriMatch)
        ->and($real->url)->toBe(SPEC011_PNG.'/c2pa.assertions/c2pa.hash.data');
})->group('SPEC-011');
