<?php

declare(strict_types=1);

/*
 * SPEC-000: spec traceability check.
 *
 * Reads specs/ and tests/ under one root and reports where they disagree:
 * a test group naming no spec, a test file with no group, a draft that
 * already has tests, an implemented spec without tests or with an unfilled
 * Traceability row, a spec whose Status is missing or not one of the four
 * words, a test whose name claims a criterion (`ACn: …`) that its spec's
 * Traceability table has no row for (AC11). Every finding is an error; there are no warnings. Exit 0 only when
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

/**
 * The criteria a spec's Traceability table has a row for: `| AC3 |`, and
 * also `| AC11 (amendments 2–3) |` — the number is what counts (AC11).
 *
 * @return list<string> e.g. ['AC1', 'AC11']
 */
function specCheckTracedCriteria(string $text): array
{
    $pos = strpos($text, '## Traceability');
    if ($pos === false || preg_match_all('/^\|\s*(AC\d+)\b/m', substr($text, $pos), $rows) === 0) {
        return [];
    }

    return array_values(array_unique($rows[1]));
}

/**
 * Every Pest declaration in a test file whose name opens with a criterion —
 * `it('AC3: …')` or `test('SPEC-017 AC12: …')` — with its line and the spec
 * it answers to: the one in its own name, else the groups of its own
 * statement (AC11). Read with PHP's tokenizer, not a regex over the text, so
 * that comments and strings cannot fake a declaration and a `{$var}` inside
 * a string cannot throw the statement boundaries off.
 *
 * @return list<array{line: int, criterion: string, specs: list<string>}>
 */
function specCheckNamedCriteria(string $source): array
{
    $tokens = token_get_all($source);
    $count = count($tokens);
    $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    $declarations = [];
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], ['it', 'test'], true)) {
            continue;
        }
        // a declaration starts a statement: what precedes it ends one
        $before = $i - 1;
        while ($before >= 0 && is_array($tokens[$before]) && in_array($tokens[$before][0], $skip, true)) {
            $before--;
        }
        if ($before >= 0 && $tokens[$before] !== ';' && $tokens[$before] !== '}' && ! (is_array($tokens[$before]) && $tokens[$before][0] === T_OPEN_TAG)) {
            continue;
        }
        $next = $i + 1;
        while ($next < $count && is_array($tokens[$next]) && in_array($tokens[$next][0], $skip, true)) {
            $next++;
        }
        if (($tokens[$next] ?? null) !== '(') {
            continue;
        }
        $next++;
        while ($next < $count && is_array($tokens[$next]) && in_array($tokens[$next][0], $skip, true)) {
            $next++;
        }
        $name = $tokens[$next] ?? null;
        if (! is_array($name) || $name[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        if (preg_match('/^.(?:(SPEC-\d{3})\s+)?(AC\d+)\b/', $name[1], $claim) !== 1) {
            continue;
        }
        // the groups of this statement, at its own depth
        $groups = [];
        $depth = 0;
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if ($t === '(' || $t === '[' || $t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($t === ')' || $t === ']' || $t === '}') {
                $depth--;
            } elseif ($t === ';' && $depth === 0) {
                break;
            } elseif ($depth === 0 && is_array($t) && $t[0] === T_STRING && $t[1] === 'group') {
                for ($k = $j + 1; $k < $count && $tokens[$k] !== ')'; $k++) {
                    if (is_array($tokens[$k]) && $tokens[$k][0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/^.(SPEC-\d{3}).$/', $tokens[$k][1], $group) === 1) {
                        $groups[] = $group[1];
                    }
                }
            }
        }
        $declarations[] = ['line' => $token[2], 'criterion' => $claim[2], 'specs' => $claim[1] !== '' ? [$claim[1]] : array_values(array_unique($groups))];
    }

    return $declarations;
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
    /** @var array<string, list<array{line: int, criterion: string, specs: list<string>}>> $namedBy  test file => declarations */
    $namedBy = [];
    $testFiles = specCheckTestFiles($root);
    foreach ($testFiles as $relative) {
        $source = (string) file_get_contents($root.'/'.$relative);
        $namedBy[$relative] = specCheckNamedCriteria($source);
        $groups = specCheckGroups($source);
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

    // --- a test that claims a criterion its spec does not trace (AC11) ---
    foreach ($namedBy as $relative => $declarations) {
        foreach ($declarations as $declaration) {
            foreach ($declaration['specs'] as $id) {
                if (isset($specTexts[$id]) && ! in_array($declaration['criterion'], specCheckTracedCriteria($specTexts[$id]), true)) {
                    $findings[] = sprintf("%s: %s:%d names %s, which has no row in the spec's Traceability table", $id, $relative, $declaration['line'], $declaration['criterion']);
                }
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
