<?php

declare(strict_types=1);

/*
 * SPEC-024: one verification in a process of its own, so that a test can choose the
 * memory limit it runs under. PHP cannot lower `memory_limit` reliably from inside a
 * running process, and the whole subject of that spec is what happens at the edge of
 * that limit, so the edge has to be set before the process starts:
 *
 *   php -d memory_limit=128M tests/Support/verify-probe.php <file> [settings.json]
 *
 * Prints one line of JSON: the verdict, the failure codes, the explanations, the peak
 * memory and the time. A process killed by the limit never reaches that line, and PHP
 * writes its fatal-error text to stdout on the CLI, so the caller takes the last line
 * that parses as JSON and treats "no such line" as "the process died" — which is the
 * outcome AC2 exists to forbid.
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\Verifier;

$path = $argv[1] ?? throw new RuntimeException('usage: verify-probe.php <file> [settings.json]');
$settings = isset($argv[2]) ? TrustSettings::fromJson((string) file_get_contents($argv[2])) : null;

$stream = fopen($path, 'rb');
if ($stream === false) {
    throw new RuntimeException("cannot open {$path}");
}

$started = hrtime(true);
$thrown = null;
$state = null;
$codes = [];
$explanations = [];
try {
    $report = (new Verifier)->verify($stream, $settings);
    $state = $report->result->state->value;
    foreach ($report->result->statuses as $status) {
        if ($status->code->isFailure()) {
            $codes[] = $status->code->value;
            $explanations[] = $status->explanation;
        }
    }
} catch (Throwable $e) {
    // AC5: nothing may reach here from the public API. Reported rather than swallowed.
    $thrown = get_class($e).': '.$e->getMessage();
}

echo json_encode([
    'state' => $state,
    'codes' => array_values(array_unique($codes)),
    'explanations' => $explanations,
    'thrown' => $thrown,
    'peak_bytes' => memory_get_peak_usage(true),
    'ms' => (hrtime(true) - $started) / 1e6,
    'limit' => ini_get('memory_limit'),
], JSON_THROW_ON_ERROR), PHP_EOL;
