#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Step 45: light fuzzing of the verifier over the corpora. Every corpus file
 * is mutated — random bit flips, a truncation, a block of random bytes, and
 * bit flips aimed at the manifest store itself — and run through
 * Verifier::verify(). Two things are never allowed, whatever the input:
 *
 *   1. an exception escaping the verifier (every fault must be a report);
 *   2. a Valid or Trusted verdict on a mutated file, unless the mutation
 *      provably touched nothing the verdict covers — those few are written
 *      out so that c2patool can be asked the same question (the note).
 *
 * Deterministic: the seed is the first argument; the same seed replays the
 * same mutations. Usage:
 *
 *   php bin/fuzz.php <seed> <rounds-per-file> <out-dir> [file-or-dir …]
 *
 * With no files, the four corpora and the three signed fixtures are used.
 * Prints one line per finding and a summary; exit code 1 on any fault.
 */

use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Container\WebpManifestStoreExtractor;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Verifier\Verifier;

require __DIR__.'/../vendor/autoload.php';

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
$seed = (int) ($argv[1] ?? 1);
$rounds = (int) ($argv[2] ?? 10);
$out = $argv[3] ?? sys_get_temp_dir().'/c2pa-fuzz';
$paths = array_slice($argv, 4);
if ($paths === []) {
    $root = dirname(__DIR__).'/tests/Fixtures';
    $paths = [$root.'/public-testfiles', $root.'/c2pa-rs', $root.'/writers', $root.'/binding', $root.'/fixture-signed.jpg', $root.'/fixture-signed.png', $root.'/fixture-signed.webp'];
}
$files = [];
foreach ($paths as $path) {
    if (is_dir($path)) {
        foreach (glob($path.'/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $file) {
            $files[] = $file;
        }
    } elseif (is_file($path)) {
        $files[] = $path;
    }
}
sort($files);
if (! is_dir($out)) {
    mkdir($out, 0777, true);
}
mt_srand($seed);
$verifier = new Verifier;

/** @return list<array{start: int, length: int}> the manifest store's byte ranges in the file, or [] */
function fuzzStoreRanges(string $file): array
{
    $stream = fopen($file, 'rb');
    if ($stream === false) {
        return [];
    }
    try {
        $head = (string) fread($stream, 12);
        rewind($stream);
        $store = match (true) {
            str_starts_with($head, "\xFF\xD8") => (new JpegManifestStoreExtractor)->extract($stream),
            str_starts_with($head, "\x89PNG") => (new PngManifestStoreExtractor)->extract($stream),
            substr($head, 0, 4) === 'RIFF' => (new WebpManifestStoreExtractor)->extract($stream),
            default => null,
        };

        return $store === null ? [] : $store->ranges;
    } catch (Throwable) {
        return [];
    } finally {
        fclose($stream);
    }
}

/** One random bit of a byte flipped. */
function fuzzFlip(string $byte): string
{
    return pack('C', ord($byte) ^ (1 << mt_rand(0, 7)));
}

/**
 * @param  list<array{start: int, length: int}>  $ranges
 * @return array{0: string, 1: list<int>, 2: string} the mutated bytes, the offsets touched, a reason to skip (or '')
 */
function fuzzMutate(string $bytes, string $kind, array $ranges): array
{
    $n = strlen($bytes);
    $where = [];
    switch ($kind) {
        case 'flip1':
        case 'flip8':
        case 'flip64':
            $count = (int) substr($kind, 4);
            for ($i = 0; $i < $count; $i++) {
                $at = mt_rand(0, $n - 1);
                $bytes[$at] = fuzzFlip($bytes[$at]);
                $where[] = $at;
            }
            break;
        case 'truncate':
            $at = mt_rand(0, $n - 1);
            $bytes = substr($bytes, 0, $at);
            $where[] = $at;
            break;
        case 'storecut':
            // the file cut off inside the manifest store: every length field lies
            if ($ranges === []) {
                return [$bytes, [], 'no store'];
            }
            $range = $ranges[mt_rand(0, count($ranges) - 1)];
            $at = $range['start'] + mt_rand(0, max(0, $range['length'] - 1));
            $bytes = substr($bytes, 0, min($at, $n));
            $where[] = $at;
            break;
        case 'block':
            $at = mt_rand(0, max(0, $n - 16));
            $block = '';
            for ($i = 0; $i < 16; $i++) {
                $block .= pack('C', mt_rand(0, 255));
            }
            $bytes = substr_replace($bytes, $block, $at, 16);
            $where[] = $at;
            break;
        case 'store8':
        case 'store64':
            // flips inside the manifest store: the parsers, not the hash
            if ($ranges === []) {
                return [$bytes, [], 'no store'];
            }
            $count = (int) substr($kind, 5);
            for ($i = 0; $i < $count; $i++) {
                $range = $ranges[mt_rand(0, count($ranges) - 1)];
                $at = $range['start'] + mt_rand(0, max(0, $range['length'] - 1));
                if ($at >= $n) {
                    continue;
                }
                $bytes[$at] = fuzzFlip($bytes[$at]);
                $where[] = $at;
            }
            break;
    }

    return [$bytes, $where, ''];
}

$kinds = ['flip1', 'flip8', 'flip64', 'truncate', 'block', 'store8', 'store64', 'storecut'];
$runs = 0;
$faults = 0;
$suspects = 0;
$states = [];
$slowest = 0.0;
$peak = 0;
$start = microtime(true);
foreach ($files as $file) {
    $original = (string) file_get_contents($file);
    $ranges = fuzzStoreRanges($file);
    for ($round = 0; $round < $rounds; $round++) {
        $kind = $kinds[$round % count($kinds)];
        [$mutated, $where, $skip] = fuzzMutate($original, $kind, $ranges);
        if ($skip !== '') {
            continue;
        }
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('no memory stream');
        }
        fwrite($stream, $mutated);
        rewind($stream);
        $runs++;
        $t = microtime(true);
        $name = basename($file).'#'.$seed.'-'.$round.'-'.$kind;
        try {
            $report = $verifier->verify($stream);
            $state = $report->result->state;
            $states[$state->value] = ($states[$state->value] ?? 0) + 1;
            if ($state === ValidationState::Valid || $state === ValidationState::Trusted) {
                $suspects++;
                $target = $out.'/suspect-'.$name.'.'.pathinfo($file, PATHINFO_EXTENSION);
                file_put_contents($target, $mutated);
                printf("SUSPECT %-60s %s at %s -> %s\n", $name, $kind, implode(',', $where), $target);
            }
        } catch (Throwable $e) {
            $faults++;
            $target = $out.'/fault-'.$name.'.'.pathinfo($file, PATHINFO_EXTENSION);
            file_put_contents($target, $mutated);
            printf("FAULT   %-60s %s at %s: %s: %s (%s:%d) -> %s\n", $name, $kind, implode(',', $where), get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine(), $target);
        } finally {
            fclose($stream);
        }
        $slowest = max($slowest, microtime(true) - $t);
        $peak = max($peak, memory_get_peak_usage(true));
    }
}
printf(
    "\n%d runs over %d files, seed %d, %d rounds each: %d faults, %d suspects; states %s; slowest run %.2fs; peak memory %d MiB; %.1fs total\n",
    $runs,
    count($files),
    $seed,
    $rounds,
    $faults,
    $suspects,
    json_encode($states),
    $slowest,
    intdiv($peak, 1048576),
    microtime(true) - $start,
);
exit($faults > 0 ? 1 : 0);
