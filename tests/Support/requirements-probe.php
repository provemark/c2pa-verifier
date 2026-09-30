<?php

declare(strict_types=1);

/*
 * SPEC-054: requires requirements.php in a clean process and reports what it
 * returned and what it declared, as JSON on stdout.
 *
 * Written in PHP 7.4 syntax, like the file it probes: CI runs it on PHP 7.4,
 * 8.0, 8.1 and 8.2 (AC4), and RequirementsTest runs it through PHP_BINARY on
 * 8.3 and later (AC6).
 * A separate process is the only honest way to ask "did requiring this declare
 * anything": inside Pest the autoloader has already loaded half of src/.
 *
 * The file is required twice, once through a second spelling of its path, the
 * way two bundled copies would each require their own (AC6). `require`, not
 * `require_once`: the point is that doing it twice is harmless.
 *
 * With --assert-unsupported it checks AC4 itself and exits 1 with a reason on
 * a mismatch, because Pest 4 does not run below PHP 8.3.
 *
 * Exit codes: 0 reported (or asserted), 1 assertion failed, 2 the file is not
 * there or did not return a closure.
 */

$root = dirname(__DIR__, 2);
$file = $root.'/requirements.php';
$secondSpelling = $root.'/tests/../requirements.php';

if (! is_file($file)) {
    fwrite(STDERR, "requirements.php is not there\n");
    exit(2);
}

$classesBefore = get_declared_classes();
$functionsBefore = get_defined_functions()['user'];
$constantsBefore = get_defined_constants(true)['user'] ?? [];

$first = require $file;
$second = require $secondSpelling;

if (! $first instanceof Closure || ! $second instanceof Closure) {
    fwrite(STDERR, "requirements.php did not return a closure\n");
    exit(2);
}

$firstResult = $first();
$secondResult = $second();

if (! is_array($firstResult) || ! is_array($secondResult)) {
    fwrite(STDERR, "the closure did not return an array\n");
    exit(2);
}

$declared = array_merge(
    array_values(array_diff(get_declared_classes(), $classesBefore)),
    array_values(array_diff(get_defined_functions()['user'], $functionsBefore)),
    array_keys(array_diff_key(get_defined_constants(true)['user'] ?? [], $constantsBefore))
);

$report = [
    'php' => PHP_VERSION,
    'first' => $firstResult,
    'second' => $secondResult,
    'declared' => $declared,
];

echo json_encode($report), PHP_EOL;

$arguments = $argv ?? [];
if (in_array('--assert-unsupported', $arguments, true)) {
    $failures = [];
    if (($firstResult['supported'] ?? null) !== false) {
        $failures[] = 'supported is not false';
    }
    $missing = $firstResult['missing'] ?? null;
    if (! is_array($missing) || ! in_array('php>=8.3', $missing, true)) {
        $failures[] = 'missing does not name php>=8.3';
    }
    if ($declared !== []) {
        $failures[] = 'the file declared: '.implode(', ', $declared);
    }
    if ($firstResult !== $secondResult) {
        $failures[] = 'the second copy answered differently';
    }
    if ($failures !== []) {
        fwrite(STDERR, 'SPEC-054 AC4: '.implode('; ', $failures).PHP_EOL);
        exit(1);
    }
    fwrite(STDERR, 'SPEC-054 AC4: ok on PHP '.PHP_VERSION.PHP_EOL);
}

exit(0);
