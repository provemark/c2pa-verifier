<?php

declare(strict_types=1);

/*
 * SPEC-000: spec traceability check.
 *
 * Reads specs/ and tests/ under one root and reports where they disagree:
 * a test group naming no spec, a test file with no group, a draft that
 * already has tests, an implemented spec without tests or with an unfilled
 * Traceability row, a spec whose Status is missing or not one of the four
 * words. Every finding is an error; there are no warnings. Exit 0 only when
 * there is nothing to report.
 *
 * Tooling, not product code: outside the Deptrac layers, inside PHPStan and
 * Pint. Tests require_once this file and call specCheck() on fixture trees;
 * the command-line part at the bottom runs only when this file is the script
 * being executed.
 */

final readonly class SpecCheckResult
{
    /**
     * @param  list<string>  $findings  one line each, `SPEC-###: reason` or `path: reason`
     * @param  array<string, string>  $specs  spec ID => status, valid statuses only
     */
    public function __construct(
        public array $findings,
        public array $specs,
        public int $testFiles,
    ) {}

    public function exitCode(): int
    {
        return $this->findings === [] ? 0 : 1;
    }

    public function render(): string
    {
        $lines = [];
        foreach ($this->specs as $id => $status) {
            $lines[] = "spec {$id} {$status}";
        }
        foreach ($this->findings as $finding) {
            $lines[] = $finding;
        }
        $lines[] = $this->findings === []
            ? sprintf('OK: %d spec(s), %d test file(s)', count($this->specs), $this->testFiles)
            : sprintf('FAIL: %d finding(s)', count($this->findings));

        return implode("\n", $lines)."\n";
    }
}

/**
 * @return list<string>
 */
function specCheckStatuses(): array
{
    return ['draft', 'approved', 'implemented', 'superseded'];
}

/**
 * Reads the Status row of one spec file. Returns the status word, or null
 * after appending a finding — a spec without a recognisable status takes
 * part in nothing else (AC7).
 *
 * @param  list<string>  $findings
 */
function specCheckStatus(string $relativePath, string $text, array &$findings): ?string
{
    if (preg_match('/^\|\s*Status\s*\|\s*(.*?)\s*\|/m', $text, $m) !== 1) {
        $findings[] = "{$relativePath}: Status missing";

        return null;
    }
    $status = $m[1];
    if (! in_array($status, specCheckStatuses(), true)) {
        $findings[] = sprintf('%s: Status "%s" is not one of %s', $relativePath, $status, implode(', ', specCheckStatuses()));

        return null;
    }

    return $status;
}

/**
 * The criteria whose Traceability row has an empty or `—` Test cell (AC6).
 *
 * @return list<string>
 */
function specCheckUnfilledTraceability(string $text): array
{
    $pos = strpos($text, '## Traceability');
    if ($pos === false) {
        return [];
    }
    $unfilled = [];
    if (preg_match_all('/^\|\s*(AC\d+)\s*\|\s*(.*?)\s*\|/m', substr($text, $pos), $rows, PREG_SET_ORDER) > 0) {
        foreach ($rows as $row) {
            if ($row[2] === '' || $row[2] === '—') {
                $unfilled[] = $row[1];
            }
        }
    }

    return $unfilled;
}

/**
 * Every `*Test.php` under tests/, except tests/Fixtures/, relative to root.
 *
 * @return list<string>
 */
function specCheckTestFiles(string $root): array
{
    $dir = $root.'/tests';
    if (! is_dir($dir)) {
        return [];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! str_ends_with($file->getFilename(), 'Test.php')) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if (str_starts_with($relative, 'tests/Fixtures/')) {
            continue;
        }
        $files[] = $relative;
    }
    sort($files);

    return $files;
}

/**
 * The SPEC-### groups carried by one test file, from every ->group(...)
 * call including multi-argument ones (AC9).
 *
 * @return list<string>
 */
function specCheckGroups(string $text): array
{
    $groups = [];
    if (preg_match_all('/->group\(\s*([^)]*)\)/', $text, $calls) > 0) {
        foreach ($calls[1] as $arguments) {
            if (preg_match_all('/[\'"](SPEC-\d{3})[\'"]/', $arguments, $ids) > 0) {
                foreach ($ids[1] as $id) {
                    $groups[] = $id;
                }
            }
        }
    }

    return array_values(array_unique($groups));
}

function specCheck(string $root): SpecCheckResult
{
    $root = rtrim($root, '/');
    $findings = [];

    // --- specs: ID from the filename, status from the table (AC1, AC7) ---
    /** @var array<string, string> $specs */
    $specs = [];
    /** @var array<string, string> $specTexts */
    $specTexts = [];
    foreach (glob($root.'/specs/SPEC-*.md') ?: [] as $path) {
        $relative = substr($path, strlen($root) + 1);
        if (preg_match('/^(SPEC-\d{3})/', basename($path), $m) !== 1) {
            $findings[] = "{$relative}: filename does not start with SPEC-### ";

            continue;
        }
        $text = (string) file_get_contents($path);
        $status = specCheckStatus($relative, $text, $findings);
        if ($status === null) {
            continue;
        }
        $specs[$m[1]] = $status;
        $specTexts[$m[1]] = $text;
    }
    ksort($specs);

    // --- tests: groups per file (AC3, AC9) ---
    /** @var array<string, list<string>> $carriedBy  spec ID => test files */
    $carriedBy = [];
    $testFiles = specCheckTestFiles($root);
    foreach ($testFiles as $relative) {
        $groups = specCheckGroups((string) file_get_contents($root.'/'.$relative));
        if ($groups === []) {
            $findings[] = "{$relative}: no ->group('SPEC-###') call";

            continue;
        }
        foreach ($groups as $id) {
            $carriedBy[$id][] = $relative;
        }
    }
    ksort($carriedBy);

    // --- a group in the suite with no spec file (AC2) ---
    foreach ($carriedBy as $id => $files) {
        if (! isset($specs[$id])) {
            foreach ($files as $file) {
                $findings[] = "{$id}: group carried by {$file} but no specs/{$id}-*.md exists";
            }
        }
    }

    // --- status against tests (AC4, AC5, AC6) ---
    foreach ($specs as $id => $status) {
        $files = $carriedBy[$id] ?? [];
        if ($status === 'draft') {
            foreach ($files as $file) {
                $findings[] = "{$id}: status draft but {$file} carries its group — tests precede approval";
            }
        }
        if ($status === 'implemented') {
            if ($files === []) {
                $findings[] = "{$id}: status implemented but no test carries its group";
            }
            foreach (specCheckUnfilledTraceability($specTexts[$id]) as $criterion) {
                $findings[] = "{$id}: status implemented but Traceability row {$criterion} names no test";
            }
        }
    }

    return new SpecCheckResult($findings, $specs, count($testFiles));
}

// --- command line: run on the repository root, exit with the result ---
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $result = specCheck(dirname(__DIR__));
    fwrite($result->exitCode() === 0 ? STDOUT : STDERR, $result->render());
    exit($result->exitCode());
}
