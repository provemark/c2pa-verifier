<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-068: a data hash that leaves no byte of the file to hash is a
 * mismatch. The files are tests/Fixtures/exclusion-coverage/ (steps 349 and
 * 351, re-signed under a throw-away root); the c2patool answers, which say
 * Trusted where this spec says Invalid, are under
 * tests/Fixtures/c2patool/exclusion-coverage/.
 */

function spec068Verify(string $name, bool $withSettings): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/exclusion-coverage/{$name}.png", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$name}");
    }
    $settings = $withSettings ? TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/exclusion-coverage/throw-away-root.settings.json')) : null;

    return (new Verifier)->verify($stream, $settings);
}

/** @return list<ValidationStatus> the statuses the data-hash check gave */
function spec068DataHash(VerificationReport $report): array
{
    return array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => str_starts_with($s->code->value, 'assertion.dataHash.')));
}

/** The state c2patool recorded for the file. */
function spec068Oracle(string $name, string $version): string
{
    /** @var array{validation_state: string} $oracle */
    $oracle = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/exclusion-coverage/{$name}--{$version}.json"), true, 512, JSON_THROW_ON_ERROR);

    return $oracle['validation_state'];
}

it('reports a data hash whose exclusions cover every byte as a mismatch, without hashing', function (string $name, bool $withSettings): void {
    $report = spec068Verify($name, $withSettings);
    $statuses = spec068DataHash($report);

    expect($statuses)->toHaveCount(1)
        ->and($statuses[0]->code)->toBe(StatusCode::AssertionDataHashMismatch)
        ->and($statuses[0]->url)->toEndWith('/c2pa.assertions/c2pa.hash.data')
        ->and($statuses[0]->explanation)->toContain('leave no byte of the 47773-byte file to hash')
        ->and($statuses[0]->explanation)->toContain('[0, 33], [33, 46074], [46107, 1666]')
        ->and($report->result->state->value)->toBe('Invalid');
    // the deliberate divergence: both c2patool versions call this file Trusted
    foreach (['0.28.1', '0.28.2'] as $version) {
        expect(spec068Oracle($name, $version))->toBe('Trusted', "{$name} {$version}");
    }
})->with([
    'every byte but the store excluded' => ['all-but-store'],
    'the same, a pixel changed after signing' => ['all-but-store-pixel-edited'],
])->with([
    'without trust settings' => [false],
    'under the throw-away root' => [true],
])->group('SPEC-068');

it('keeps amendment 7\'s answer for one exclusion over the whole file', function (): void {
    $statuses = spec068DataHash(spec068Verify('whole-file', true));

    expect($statuses)->toHaveCount(1)
        ->and($statuses[0]->code)->toBe(StatusCode::AssertionDataHashMismatch)
        ->and($statuses[0]->explanation)->toContain('33 before it, 1666 after it')
        ->and($statuses[0]->explanation)->toContain('C2PA 2.4 §15.12.1.1');
})->group('SPEC-068');

it('does not refuse a data hash that leaves one byte: the floor', function (): void {
    $report = spec068Verify('all-but-one-byte', true);
    $codes = array_map(static fn (ValidationStatus $s): StatusCode => $s->code, spec068DataHash($report));

    expect($codes)->toBe([StatusCode::AssertionDataHashMatch, StatusCode::AssertionDataHashAdditionalExclusionsPresent])
        ->and(spec068DataHash($report)[0]->explanation)->toContain('over 1 of 47773 bytes')
        ->and($report->result->state->value)->toBe('Trusted')
        ->and(spec068Oracle('all-but-one-byte', '0.28.2'))->toBe('Trusted');
})->group('SPEC-068');
