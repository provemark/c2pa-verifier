<?php

declare(strict_types=1);

/*
 * SPEC-054: requirements.php, the one file a host may require before it knows
 * whether the verifier can run. It is written for PHP 7.4 and later and returns a
 * closure; nothing else.
 *
 * The closure's arguments exist so every branch can be tested on one PHP (AC2,
 * AC3, AC5). What only a real older PHP can show, that the file parses and
 * declares nothing there (AC4), is checked by tests/Support/requirements-probe.php
 * with --assert-unsupported on 7.4, 8.0, 8.1 and 8.2 in CI; Pest 4 does not run
 * below 8.3. The same probe runs here through PHP_BINARY for AC6, in a clean
 * process, because inside Pest the autoloader has already declared half of src/.
 */

// Guarded like PackageTest: a missing checker fails its own criteria, not the run.
$spec054PackageCheck = dirname(__DIR__, 2).'/bin/package-check.php';
if (is_file($spec054PackageCheck)) {
    require_once $spec054PackageCheck;
}

function spec054File(): string
{
    return dirname(__DIR__, 2).'/requirements.php';
}

/**
 * @param  array<mixed>|null  $extensions  mixed on purpose: AC5 passes a list that is not all strings
 * @return array{supported: bool, missing: list<string>, ed25519: bool}
 */
function spec054Check(?string $version = null, ?array $extensions = null): array
{
    if (! is_file(spec054File())) {
        throw new RuntimeException('requirements.php is not there');
    }
    $check = require spec054File();
    if (! $check instanceof Closure) {
        throw new RuntimeException('requirements.php did not return a closure');
    }

    /** @var array{supported: bool, missing: list<string>, ed25519: bool} */
    return $check($version, $extensions);
}

/** @return array{exit: int, report: array<mixed>, stderr: string} */
function spec054Probe(): array
{
    $probe = dirname(__DIR__).'/Support/requirements-probe.php';
    $process = proc_open([PHP_BINARY, $probe], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('cannot start the probe');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $report = json_decode($stdout, true);

    return ['exit' => $exit, 'report' => is_array($report) ? $report : [], 'stderr' => $stderr];
}

const SPEC054_ALL = ['Core', 'openssl', 'mbstring', 'sodium'];

it('AC1: on this PHP, with openssl, mbstring and sodium, the verifier is supported', function (): void {
    expect(spec054Check())->toBe(['supported' => true, 'missing' => [], 'ed25519' => true]);
})->group('SPEC-054');

it('AC1: a verdict after the check is the verdict without it', function (): void {
    $without = spec020Verify('fixture-signed.jpg')->toArray();
    spec054Check();
    $with = spec020Verify('fixture-signed.jpg')->toArray();

    expect($with)->toBe($without);
})->group('SPEC-054');

it('AC2: each missing requirement is named on its own, in the fixed order', function (string $version, array $extensions, array $missing): void {
    expect(spec054Check($version, $extensions))->toMatchArray(['supported' => false, 'missing' => $missing]);
})->with([
    'PHP 7.4' => ['7.4.33', SPEC054_ALL, ['php>=8.3']],
    'PHP 8.2' => ['8.2.29', SPEC054_ALL, ['php>=8.3']],
    'no openssl' => ['8.3.0', ['Core', 'mbstring', 'sodium'], ['ext-openssl']],
    'no mbstring' => ['8.3.0', ['Core', 'openssl', 'sodium'], ['ext-mbstring']],
    'all three' => ['7.4.33', ['Core'], ['php>=8.3', 'ext-openssl', 'ext-mbstring']],
])->group('SPEC-054');

it('AC2: 8.3.0 with both extensions is the lowest supported platform', function (): void {
    expect(spec054Check('8.3.0', ['Core', 'openssl', 'mbstring']))->toMatchArray(['supported' => true, 'missing' => []]);
})->group('SPEC-054');

it('AC3: Ed25519 is told apart from support', function (): void {
    $without = ['Core', 'openssl', 'mbstring'];

    expect(spec054Check('8.3.0', $without))->toBe(['supported' => true, 'missing' => [], 'ed25519' => false])
        ->and(spec054Check('8.4.0', $without))->toBe(['supported' => true, 'missing' => [], 'ed25519' => true])
        ->and(spec054Check('8.3.0', [...$without, 'sodium']))->toBe(['supported' => true, 'missing' => [], 'ed25519' => true]);
})->group('SPEC-054');

it('AC5: a version it cannot place is not supported, and it does not throw', function (string $version): void {
    expect(spec054Check($version, SPEC054_ALL))->toMatchArray(['supported' => false, 'missing' => ['php>=8.3']]);
})->with(['empty' => [''], 'letters' => ['abc']])->group('SPEC-054');

it('AC5: an extension list that is not a list of strings confirms nothing', function (): void {
    expect(spec054Check('8.3.0', [1, null, ['openssl']]))->toMatchArray([
        'supported' => false,
        'missing' => ['ext-openssl', 'ext-mbstring'],
        'ed25519' => false,
    ]);
})->group('SPEC-054');

it('AC6: required twice in a clean process, it declares nothing and answers the same', function (): void {
    $probe = spec054Probe();

    expect($probe['exit'])->toBe(0, $probe['stderr'])
        ->and($probe['report']['declared'] ?? null)->toBe([])
        ->and($probe['report']['first'] ?? null)->toBe($probe['report']['second'] ?? 'missing')
        ->and($probe['report']['first'] ?? null)->toBe(spec054Check());
})->group('SPEC-054');

// `git archive` packs HEAD, so this one turns green only once the file is committed.
it('AC7: the dist archive carries requirements.php', function (): void {
    expect(packageGitArchive(dirname(__DIR__, 2), true)->entries())->toHaveKey('requirements.php')
        ->and(PACKAGE_SHIPPED)->toContain('requirements.php');
})->group('SPEC-054');
