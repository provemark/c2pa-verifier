<?php

declare(strict_types=1);

/*
 * SPEC-025: what a version number promises. Step 69 measured 600 public symbols
 * across 69 classes with none marked `@internal`, against a README naming two
 * classes and four accessors — so a first tag would promise all six hundred by
 * default. These tests pin the contract, require everything outside it to say so,
 * and keep the answer true by recording the surface where a change to it shows up
 * as a diff.
 *
 * The recorded surface lives in tests/Fixtures/api/public-surface.txt rather than
 * in an array here, for the same reason SPEC-023 put the shipped-paths decision in
 * .gitattributes: a promise that changes should change visibly, in review.
 */

// Guarded for the same reason as SPEC-023's checker: a bare require of a file that
// is not there aborts the whole run, and one missing file must not silence 374 tests.
$apiCheckScript = dirname(__DIR__, 2).'/bin/api-check.php';
if (is_file($apiCheckScript)) {
    require_once $apiCheckScript;
}

/**
 * The nine classes a caller may build on (SPEC-025 AC1). `TrustException` is here
 * and the other seven exception types are not: SPEC-013 turns those into statuses
 * before the public boundary, so no caller can meet them, while this one escapes
 * from TrustSettings::fromJson() and the CLI catches exactly it.
 *
 * @return list<string>
 */
function spec025Contract(): array
{
    return [
        'Cli\Command',
        'Report\StatusCode',
        'Report\ValidationResult',
        'Report\ValidationState',
        'Report\ValidationStatus',
        'Trust\TrustException',
        'Trust\TrustSettings',
        'Verifier\VerificationReport',
        'Verifier\Verifier',
    ];
}

function spec025Source(): string
{
    return dirname(__DIR__, 2).'/src';
}

/** The recorded surface, as `Short\Class :: kind name` lines. @return list<string> */
function spec025Recorded(): array
{
    $path = dirname(__DIR__).'/Fixtures/api/public-surface.txt';
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException("cannot read {$path}");
    }

    return array_values($lines);
}

it('AC1: the recorded surface is exactly what the contract classes expose today', function (): void {
    $live = [];
    foreach (spec025Contract() as $short) {
        foreach (apiSurface('Provemark\C2paVerifier\\'.$short) as $symbol) {
            $live[] = $short.' :: '.$symbol;
        }
    }

    expect($live)->toBe(spec025Recorded())
        ->and($live)->toHaveCount(91);
})->group('SPEC-025');

it('AC1: no class of the contract is marked internal', function (): void {
    $classes = apiPublicClasses(spec025Source());

    foreach (spec025Contract() as $short) {
        $name = 'Provemark\C2paVerifier\\'.$short;
        expect(array_key_exists($name, $classes))->toBeTrue($short)
            ->and($classes[$name])->toBeFalse($short.' carries @internal but is in the contract');
    }
})->group('SPEC-025');

it('AC2: every public class outside the contract says it is internal', function (): void {
    $result = apiCheck(apiPublicClasses(spec025Source()), spec025Contract());

    // the count first, so that a red phase says something rather than printing a wall
    expect(count($result->findings))->toBe(0, sprintf(
        '%d classes are in neither set; first three: %s',
        count($result->findings),
        implode(' / ', array_slice($result->findings, 0, 3)),
    ))->and($result->exitCode())->toBe(0);
})->group('SPEC-025');

it('AC2: a class in neither set, and a contract class marked internal, are both findings', function (): void {
    $contract = ['Cli\Command'];
    $classes = [
        'Provemark\C2paVerifier\Cli\Command' => false,
        'Provemark\C2paVerifier\Cbor\CborDecoder' => true,
        'Provemark\C2paVerifier\Jumbf\JumbfParser' => false,
    ];

    expect(apiCheck($classes, $contract)->findings)->toBe([
        'Provemark\C2paVerifier\Jumbf\JumbfParser: in neither the contract nor marked @internal; decide which it is',
    ]);

    $contradiction = ['Provemark\C2paVerifier\Cli\Command' => true];
    expect(apiCheck($contradiction, $contract)->findings)->toBe([
        'Provemark\C2paVerifier\Cli\Command: in the contract and marked @internal at the same time',
    ]);
})->group('SPEC-025');

it('AC3: the escape hatch is marked and unmentioned', function (): void {
    $store = new ReflectionProperty('Provemark\C2paVerifier\Verifier\VerificationReport', 'store');
    $doc = (string) $store->getDocComment();
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/README.md');

    expect($doc)->toContain('@internal')
        ->and(strtolower($doc))->toContain('may change')
        // the property itself is untouched: nothing was taken away
        ->and($store->isPublic())->toBeTrue()
        // and the README documents the rest of the report, but not this
        ->and($readme)->toContain('$report->result')
        ->and($readme)->toContain('$report->hasManifest')
        ->and($readme)->toContain('$report->remoteManifestUrl')
        ->and(str_contains($readme, '$report->store'))->toBeFalse('the README documents the escape hatch');
})->group('SPEC-025');

it('AC4: the snapshot catches a symbol nobody recorded', function (): void {
    $recorded = spec025Recorded();

    $added = $recorded;
    $added[] = 'Verifier\Verifier :: method reset';
    sort($added);
    $removed = array_values(array_filter($recorded, static fn (string $l): bool => $l !== 'Verifier\Verifier :: method verify'));

    expect(apiCompare($recorded, $added)->findings)->toBe([
        'Verifier\Verifier :: method reset: public but not recorded',
    ])->and(apiCompare($recorded, $removed)->findings)->toBe([
        'Verifier\Verifier :: method verify: recorded but no longer public',
    ]);
})->group('SPEC-025');

it('AC5: the README says what the contract is and what may change', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/README.md');

    expect($readme)->toContain('## Public API')
        ->and($readme)->toContain('@internal')
        ->and($readme)->toContain('may change in any release');

    // every contract class is named where a caller will look
    foreach (spec025Contract() as $short) {
        $class = substr($short, (int) strrpos($short, '\\') + 1);
        expect($readme)->toContain($class, $short);
    }
})->group('SPEC-025');
