<?php

declare(strict_types=1);

/*
 * SPEC-023: what `composer require` delivers. Composer fetches the dist archive,
 * which is `git archive` with `.gitattributes` applied — so the thing under test
 * is that archive, not the working tree. Two shapes of test:
 *
 *   AC1 runs over fixture trees under tests/Fixtures/package-check/, one per
 *   finding, the way SpecCheckTest runs over tests/Fixtures/spec-check/. The
 *   top-level paths are passed in rather than discovered, so a case needs no
 *   git repository of its own.
 *
 *   AC2–AC6 need a real archive. A test in this group may invoke `git` — the
 *   only thing that produces the archive Composer would fetch; re-implementing
 *   it in PHP would be a second truth (SPEC-023, open question 1, decided by
 *   the maintainer 2026-09-22). No other test in this repository may, and
 *   nothing in src/ ever may: the rule against `exec` binds the verification
 *   path and is unchanged there.
 */

// Guarded on purpose. A bare `require_once` of a checker that is not there aborts
// the whole Pest run — 353 other tests stop reporting because of one missing file.
// With the guard, each criterion below fails on its own, which is what a red phase
// is for, and a checker deleted later fails these tests loudly instead of silently
// taking the suite down with it.
$packageCheckScript = dirname(__DIR__, 2).'/bin/package-check.php';
if (is_file($packageCheckScript)) {
    require_once $packageCheckScript;
}

function packageRoot(): string
{
    return dirname(__DIR__, 2);
}

function packageFixture(string $case): string
{
    return dirname(__DIR__).'/Fixtures/package-check/'.$case;
}

/** The archive Composer would fetch: `.gitattributes` applied. */
function packageDist(): PackageArchive
{
    return packageGitArchive(packageRoot(), true);
}

/** The dist as it would have shipped before `.gitattributes` existed. */
function packageDistBefore(): PackageArchive
{
    return packageArchiveBeforeGitattributes(packageRoot())
        ?? throw new RuntimeException('the commit before .gitattributes is not in this repository');
}

/** False on a shallow clone, where that commit is not there to archive. */
function packageHasHistory(): bool
{
    return packageArchiveBeforeGitattributes(packageRoot()) !== null;
}

/** @param array<string, string> $files path => contents */
function packageArchiveOf(array $files): PackageArchive
{
    return new PackageArrayArchive($files);
}

/**
 * A dist that is correct, as the smallest set of files the criteria accept. The
 * broken cases below are this one with a single thing taken away or altered, so
 * that what each criterion catches is visible in the diff between them.
 *
 * @return array<string, string>
 */
function packageMinimalDist(): array
{
    return [
        'composer.json' => '{"name":"provemark/c2pa-verifier","bin":["bin/c2pa-verify"]}',
        'LICENSE' => "MIT License\n",
        'README.md' => "# c2pa-verifier\n\nSee [`AI-LOG.md`](AI-LOG.md).\n\n## How this is built\n\nWritten with an assistant; every contribution is recorded in [`AI-LOG.md`](AI-LOG.md).\n",
        'AI-LOG.md' => "# AI log\n",
        'bin/c2pa-verify' => "#!/usr/bin/env php\n",
        'src/Verifier/Verifier.php' => "<?php\n",
    ];
}

const PACKAGE_CEILING = 4 * 1024 * 1024;

it('AC1: a top-level path that is neither shipped nor export-ignore is a finding', function (): void {
    $result = packageCheck(packageFixture('unclassified'), ['src', 'tests', 'benchmarks'], ['src']);

    expect($result->findings)->toBe([
        'benchmarks: in neither the shipped list nor export-ignore; decide which it is',
    ])->and($result->exitCode())->toBe(1);
})->group('SPEC-023');

it('AC1: a clean tree has no findings, and says what ships and what does not', function (): void {
    $result = packageCheck(packageFixture('clean'), ['src', 'tests', '.github'], ['src']);

    expect($result->findings)->toBe([])
        ->and($result->exitCode())->toBe(0)
        ->and($result->shipped)->toBe(['src'])
        ->and($result->ignored)->toBe(['.github', 'tests'])
        ->and($result->render())->toContain('1 shipped, 2 export-ignore');
})->group('SPEC-023');

it('AC1: a path that is both shipped and export-ignore is a finding, neither winning silently', function (): void {
    $result = packageCheck(packageFixture('contradiction'), ['src', 'tests'], ['src']);

    expect($result->findings)->toBe([
        'src: on the shipped list and export-ignore at the same time',
    ])->and($result->exitCode())->toBe(1);
})->group('SPEC-023');

it('AC1: a missing .gitattributes is a finding, not an empty ignore set', function (): void {
    $result = packageCheck(packageFixture('no-gitattributes'), ['src', 'tests'], ['src']);

    expect($result->findings)->toBe([
        'no .gitattributes: nothing is export-ignore, so the dist is whatever the repository holds',
    ])->and($result->exitCode())->toBe(1);
})->group('SPEC-023');

it('AC1: the repository itself classifies every top-level path it tracks', function (): void {
    $result = packageCheck(packageRoot(), packageTrackedTopLevel(packageRoot()));

    expect($result->findings)->toBe([])
        ->and($result->shipped)->toContain('src')
        ->and($result->shipped)->toContain('bin')
        ->and($result->ignored)->toContain('tests');
})->group('SPEC-023');

it('AC2: the dist carries composer.json, LICENSE, README.md, src/ and every declared bin', function (): void {
    $result = packageDistCheck(packageDist(), PACKAGE_CEILING);

    expect($result->findings)->toBe([]);

    $paths = packageDist()->entries();
    expect(array_key_exists('composer.json', $paths))->toBeTrue()
        ->and(array_key_exists('LICENSE', $paths))->toBeTrue()
        ->and(array_key_exists('README.md', $paths))->toBeTrue()
        ->and(array_key_exists('bin/c2pa-verify', $paths))->toBeTrue()
        ->and(count(array_filter(array_keys($paths), static fn (string $p): bool => str_starts_with($p, 'src/'))))->toBeGreaterThan(20);
})->group('SPEC-023');

it('AC2: a dist without src/ is a finding, and so is a bin composer.json declares but the archive lacks', function (): void {
    $without = packageMinimalDist();
    unset($without['src/Verifier/Verifier.php']);
    $noBin = packageMinimalDist();
    unset($noBin['bin/c2pa-verify']);

    expect(packageDistCheck(packageArchiveOf(packageMinimalDist()), PACKAGE_CEILING)->findings)->toBe([])
        ->and(packageDistCheck(packageArchiveOf($without), PACKAGE_CEILING)->findings)->toBe([
            'src/: the archive holds no file under it',
        ])
        ->and(packageDistCheck(packageArchiveOf($noBin), PACKAGE_CEILING)->findings)->toBe([
            'bin/c2pa-verify: declared in composer.json "bin" but not in the archive',
        ]);
})->group('SPEC-023');

it('AC3: the dist holds no fixture and stays under the ceiling', function (): void {
    $dist = packageDist();
    $under = array_filter(array_keys($dist->entries()), static fn (string $p): bool => str_starts_with($p, 'tests/'));

    expect($under)->toBe([])
        ->and($dist->size())->toBeLessThan(PACKAGE_CEILING)
        ->and(packageDistCheck($dist, PACKAGE_CEILING)->findings)->toBe([]);
})->group('SPEC-023');

it('AC3: the dist as it would have shipped before .gitattributes is what this criterion exists to catch', function (): void {
    // not constructed: the archive of the commit before the file was added, 62.9 MB
    // of fixtures, measured in step 62. Both findings must fire on it.
    $before = packageDistBefore();
    $findings = implode("\n", packageDistCheck($before, PACKAGE_CEILING)->findings);

    expect($before->size())->toBeGreaterThan(PACKAGE_CEILING)
        ->and($findings)->toContain('tests/')
        ->and($findings)->toContain('over the ceiling');
    // not `static`: Pest binds a skip closure to the test case, and a static one cannot be bound
})->skip(fn (): bool => ! packageHasHistory(), 'shallow clone: the commit before .gitattributes is not here')->group('SPEC-023');

it('AC4: every relative link in the markdown the package ships resolves inside the package', function (): void {
    $result = packageDistCheck(packageDist(), PACKAGE_CEILING);

    expect($result->findings)->toBe([]);

    $broken = packageMinimalDist();
    $broken['README.md'] .= "\nAnd the [specs](specs/SPEC-001-nothing.md) it does not carry.\n";
    expect(packageDistCheck(packageArchiveOf($broken), PACKAGE_CEILING)->findings)->toBe([
        'README.md: the link specs/SPEC-001-nothing.md leaves the package',
    ]);
})->group('SPEC-023');

it('AC4: a scheme, an absolute path and a bare fragment are not this criterion\'s business', function (): void {
    $links = packageMinimalDist();
    $links['README.md'] .= "\n[a](https://c2pa.org) [b](mailto:x@example.org) [c](/etc/passwd) [d](#section) [e](AI-LOG.md#today)\n";

    expect(packageDistCheck(packageArchiveOf($links), PACKAGE_CEILING)->findings)->toBe([]);
})->group('SPEC-023');

it('AC5: the disclosure travels with the package', function (): void {
    expect(packageDistCheck(packageDist(), PACKAGE_CEILING)->findings)->toBe([])
        ->and(array_key_exists('AI-LOG.md', packageDist()->entries()))->toBeTrue()
        ->and(packageDist()->read('README.md'))->toContain('## How this is built');

    $noLog = packageMinimalDist();
    unset($noLog['AI-LOG.md']);
    $noSection = packageMinimalDist();
    $noSection['README.md'] = "# c2pa-verifier\n\nNothing about how it was built.\n";

    expect(packageDistCheck(packageArchiveOf($noLog), PACKAGE_CEILING)->findings)->toContain('AI-LOG.md: the disclosure is not in the archive')
        ->and(packageDistCheck(packageArchiveOf($noSection), PACKAGE_CEILING)->findings)->toContain('README.md: no "How this is built" section, so the package does not say how it was made');
})->group('SPEC-023');

it('AC6: the package, installed where Composer would put it and nothing else, verifies a file', function (): void {
    // vendor/provemark/c2pa-verifier/ plus an autoloader built from the archive's own
    // psr-4 map — the layout a consumer gets, and the only one the shim can work in
    // (SPEC-023 amendment 1)
    $root = packageInstall(packageDist());
    $package = $root.'/vendor/provemark/c2pa-verifier';

    expect(is_file($root.'/vendor/autoload.php'))->toBeTrue()
        ->and(is_dir($package.'/tests'))->toBeFalse()
        ->and(is_dir($package.'/vendor'))->toBeFalse()
        ->and(is_file($package.'/bin/c2pa-verify'))->toBeTrue();

    $fixture = dirname(__DIR__).'/Fixtures/fixture-signed.jpg';
    $settings = dirname(__DIR__).'/Fixtures/trust/full.settings.json';
    $run = packageRun($package, [$fixture, '--settings', $settings]);

    /** @var array<string, mixed> $report */
    $report = json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);

    expect($run['exit'])->toBe(0, $run['stderr'])
        ->and($report['validation_state'])->toBe('Trusted');
})->group('SPEC-023');
