#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Step 45: light fuzzing of the verifier over the corpora. Every corpus file
 * is mutated — random bit flips, a truncation, a block of random bytes, and
 * bit flips aimed at the manifest store itself — and run through
 * Verifier::verify(). Two things are never allowed, whatever the input:
 *
 *   1. an exception escaping the verifier, or its report's toJson() (every
 *      fault must be a report that can be written; step 247);
 *   2. a Valid or Trusted verdict on a mutated file, unless the mutation
 *      provably touched nothing the verdict covers — those few are written
 *      out so that c2patool can be asked the same question (the note).
 *
 * Deterministic: the seed is the first argument; the same seed replays the
 * same mutations. Each file draws from its own stream, seeded by the seed and
 * the file's path (step 297), so adding or changing one file moves no other
 * file's mutations. Usage:
 *
 *   php bin/fuzz.php <seed> <rounds-per-file> <out-dir> [--trust] [file-or-dir …]
 *
 * With no files, the four corpora and the three signed fixtures are used.
 *
 * With --trust (step 295), every file is verified under trust settings, so that
 * the chain walk, the TSA's trust and an expired signer kept by a timestamp are
 * reached: each fixture beside its own <name>.settings.json, and the signed
 * fixtures and the c2pa-rs corpus under trust/full-plus-digicert-g4.settings.json.
 * Each pair is verified unmutated first; a mutation that raises the state above
 * that (Invalid < Valid < Trusted) is reported as RAISED, beside the suspects.
 * Without --trust the runs are the same as before.
 *
 * With --trust two more kinds (step 296), unprot1 and unprot8, flip bits only in
 * the values of the active manifest's COSE unprotected header — the timestamp
 * tokens, and x5chain where it is unprotected — which the claim signature does
 * not cover. A file that stays Trusted after such a flip is often right (its
 * signer needs no timestamp), so for these kinds a suspect is a run that still
 * reports timeStamp.validated or timeStamp.trusted (the changed token accepted),
 * or one that stays Valid or Trusted after a flip in an unprotected x5chain.
 * Prints one line per finding and a summary; exit code 1 on any fault.
 */

use Provemark\C2paVerifier\Container\AviManifestStoreExtractor;
use Provemark\C2paVerifier\Container\GifManifestStoreExtractor;
use Provemark\C2paVerifier\Container\Id3ManifestStoreExtractor;
use Provemark\C2paVerifier\Container\IsobmffManifestStoreExtractor;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\ManifestStoreBytes;
use Provemark\C2paVerifier\Container\PlainTextManifestStoreExtractor;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Container\WavManifestStoreExtractor;
use Provemark\C2paVerifier\Container\WebpManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Timestamp\TimestampHeader;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\Verifier;

require __DIR__.'/../vendor/autoload.php';

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
$seed = (int) ($argv[1] ?? 1);
$rounds = (int) ($argv[2] ?? 10);
$out = $argv[3] ?? sys_get_temp_dir().'/c2pa-fuzz';
$paths = array_slice($argv, 4);
$trust = in_array('--trust', $paths, true);
$paths = array_values(array_filter($paths, static fn (string $p): bool => $p !== '--trust'));
$extensions = '{jpg,jpeg,png,gif,webp,wav,avi,mp3,flac,txt,mp4,mov,avif,heic}';   // ISOBMFF since step 299
$pairs = null;
if ($paths === [] && $trust) {
    // step 295: every fixture beside its own settings, and the signed corpus under the test and DigiCert roots
    $root = dirname(__DIR__).'/tests/Fixtures';
    $pairs = [];
    foreach (array_merge(glob($root.'/*/*.settings.json') ?: [], glob($root.'/*/*/*.settings.json') ?: []) as $settingsFile) {
        foreach (glob(substr($settingsFile, 0, -strlen('.settings.json')).'.'.$extensions, GLOB_BRACE) ?: [] as $file) {
            $pairs[] = [$file, $settingsFile];
        }
    }
    foreach (array_merge(glob($root.'/fixture-signed.'.$extensions, GLOB_BRACE) ?: [], glob($root.'/c2pa-rs/*.'.$extensions, GLOB_BRACE) ?: []) as $file) {
        $pairs[] = [$file, $root.'/trust/full-plus-digicert-g4.settings.json'];
    }
    foreach (glob($root.'/bmff-shape/*.mp4') ?: [] as $file) {
        $pairs[] = [$file, $root.'/bmff-shape/probe-root.settings.json'];   // step 299: the re-signed BMFF probes and their root
    }
    usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
} elseif ($paths === []) {
    $root = dirname(__DIR__).'/tests/Fixtures';
    $paths = [$root.'/public-testfiles', $root.'/c2pa-rs', $root.'/writers', $root.'/binding', $root.'/fixture-signed.jpg', $root.'/fixture-signed.png', $root.'/fixture-signed.webp', $root.'/fixture-signed.mp4', $root.'/fixture-signed.wav', $root.'/wav', $root.'/wav-writers', $root.'/fixture-signed.mp3', $root.'/mp3', $root.'/fixture-signed.flac', $root.'/flac', $root.'/fixture-signed.avi', $root.'/avi', $root.'/fixture-signed.gif', $root.'/gif', $root.'/fixture-signed.txt', $root.'/text', $root.'/fixture-signed.mov', $root.'/fixture-signed.avif', $root.'/fixture-signed.heic', $root.'/isobmff', $root.'/bmff', $root.'/bmff-shape', $root.'/bmff-tail', $root.'/bmff-fragmented', $root.'/bmff-fragmented/broken'];   // MP4 and WAV since step 213, MP3 228, FLAC 237, AVI 241, GIF 259, plain text 268, the ISOBMFF sets 299
}
$files = [];
foreach ($paths as $path) {
    if (is_dir($path)) {
        foreach (glob($path.'/*.'.$extensions, GLOB_BRACE) ?: [] as $file) {
            $files[] = $file;
        }
    } elseif (is_file($path)) {
        $files[] = $path;
    }
}
sort($files);
if ($pairs === null) {
    // without --trust, or with files named: each file without settings, or (with --trust) under the default settings
    $pairs = array_map(static fn (string $f): array => [$f, $trust ? dirname(__DIR__).'/tests/Fixtures/trust/full-plus-digicert-g4.settings.json' : null], $files);
}
if (! is_dir($out)) {
    mkdir($out, 0777, true);
}
$verifier = new Verifier;
$textVerifier = new Verifier(text: new PlainTextManifestStoreExtractor);   // for .txt files only, so the other files' runs are as before (step 268)

/** @return list<array{start: int, length: int}> the manifest store's byte ranges in the file, or [] */
function fuzzStoreRanges(string $file): array
{
    return fuzzStore($file)->ranges ?? [];
}

/**
 * The values of the active manifest's unprotected header, found in the file: each timestamp token, and each
 * x5chain certificate when the chain is unprotected (step 296). A value split across container segments is not
 * found and counted as skipped.
 *
 * @return array{0: list<array{start: int, length: int, what: string}>, 1: int} the ranges, the values skipped
 */
function fuzzUnprotectedRanges(string $file, string $bytes): array
{
    try {
        $store = fuzzStore($file);
        if ($store === null) {
            return [[], 0];
        }
        $cose = CoseSign1::ofManifest(ManifestStore::fromTree((new JumbfParser)->parse($store->bytes))->active);
        $values = [];
        foreach (TimestampHeader::fromUnprotected($cose->unprotected)->tokens ?? [] as $token) {
            $values[] = ['token', $token];
        }
        if (! $cose->chainProtected) {
            foreach ($cose->chain as $certificate) {
                $values[] = ['x5chain', $certificate->bytes];
            }
        }
    } catch (Throwable) {
        return [[], 0];
    }
    $ranges = [];
    $skipped = 0;
    foreach ($values as [$what, $value]) {
        $at = $value === '' ? false : strpos($bytes, $value);
        if ($at === false) {
            $skipped++;

            continue;
        }
        $ranges[] = ['start' => $at, 'length' => strlen($value), 'what' => $what];
    }

    return [$ranges, $skipped];
}

/** The manifest store as the verifier's extractor for the format reads it, or null. */
function fuzzStore(string $file): ?ManifestStoreBytes
{
    $stream = fopen($file, 'rb');
    if ($stream === false) {
        return null;
    }
    try {
        $head = (string) fread($stream, 12);
        rewind($stream);
        $store = match (true) {
            str_ends_with($file, '.txt') => (new PlainTextManifestStoreExtractor)->extract($stream),   // step 268: text has no magic
            str_starts_with($head, "\xFF\xD8") => (new JpegManifestStoreExtractor)->extract($stream),
            str_starts_with($head, "\x89PNG") => (new PngManifestStoreExtractor)->extract($stream),
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => (new GifManifestStoreExtractor)->extract($stream),   // as FormatDetector (SPEC-059 amendment 1 E)
            substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WAVE' => (new WavManifestStoreExtractor)->extract($stream),   // step 212
            substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'AVI ' => (new AviManifestStoreExtractor)->extract($stream),   // step 241
            str_starts_with($head, 'ID3') => (new Id3ManifestStoreExtractor)->extract($stream),   // step 228
            substr($head, 4, 4) === 'ftyp' => (new IsobmffManifestStoreExtractor)->extract($stream),   // step 299, as FormatDetector (SPEC-026)
            substr($head, 0, 4) === 'RIFF' => (new WebpManifestStoreExtractor)->extract($stream),
            default => null,
        };

        return $store;
    } catch (Throwable) {
        return null;
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
 * @param  list<array{start: int, length: int, what: string}>  $unprotected
 * @return array{0: string, 1: list<int>, 2: string} the mutated bytes, the offsets touched, a reason to skip (or '')
 */
function fuzzMutate(string $bytes, string $kind, array $ranges, array $unprotected = []): array
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
        case 'unprot1':
        case 'unprot8':
            // flips in the unprotected header's values, which the claim signature does not cover (step 296)
            if ($unprotected === []) {
                return [$bytes, [], 'no unprotected value'];
            }
            $count = (int) substr($kind, 6);
            for ($i = 0; $i < $count; $i++) {
                $range = $unprotected[mt_rand(0, count($unprotected) - 1)];
                $at = $range['start'] + mt_rand(0, max(0, $range['length'] - 1));
                $bytes[$at] = fuzzFlip($bytes[$at]);
                $where[] = $at;
            }
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

/** @var array<string, int> $rank */
$rank = ['Invalid' => 0, 'Valid' => 1, 'Trusted' => 2];
$kinds = ['flip1', 'flip8', 'flip64', 'truncate', 'block', 'store8', 'store64', 'storecut'];
if ($trust) {
    $kinds = [...$kinds, 'unprot1', 'unprot8'];   // step 296: only with --trust, so the runs without it are as before
}
$withUnprotected = 0;
$unprotectedSkipped = 0;
$runs = 0;
$faults = 0;
$suspects = 0;
$raised = 0;
$baselines = [];
$states = [];
$slowest = 0.0;
$peak = 0;
$start = microtime(true);
foreach ($pairs as [$file, $settingsFile]) {
    // step 297: one stream per file, from the seed and the path inside the repository (or the path as given)
    $repo = dirname(__DIR__).'/';
    mt_srand(crc32($seed.':'.(str_starts_with($file, $repo) ? substr($file, strlen($repo)) : $file)));
    $original = (string) file_get_contents($file);
    $ranges = fuzzStoreRanges($file);
    [$unprotected, $skippedValues] = $trust ? fuzzUnprotectedRanges($file, $original) : [[], 0];
    $withUnprotected += $unprotected === [] ? 0 : 1;
    $unprotectedSkipped += $skippedValues;
    $settings = $settingsFile === null ? null : TrustSettings::fromJson((string) file_get_contents($settingsFile));
    $baseline = null;
    if ($settings !== null) {
        $stream = fopen($file, 'rb');
        if ($stream === false) {
            throw new RuntimeException("cannot open {$file}");
        }
        $baseline = (str_ends_with($file, '.txt') ? $textVerifier : $verifier)->verify($stream, $settings)->result->state->value;
        fclose($stream);
        $baselines[$baseline] = ($baselines[$baseline] ?? 0) + 1;
    }
    $under = $settingsFile === null ? '' : ' under '.substr($settingsFile, strlen(dirname(__DIR__)) + 1);
    for ($round = 0; $round < $rounds; $round++) {
        $kind = $kinds[$round % count($kinds)];
        [$mutated, $where, $skip] = fuzzMutate($original, $kind, $ranges, $unprotected);
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
        $name = basename($file).'#'.$seed.'-'.$round.'-'.$kind.($settingsFile === null ? '' : '@'.basename(dirname($settingsFile)).'-'.basename($settingsFile, '.settings.json'));
        try {
            $report = (str_ends_with($file, '.txt') ? $textVerifier : $verifier)->verify($stream, $settings);
            $report->toJson();   // a report that cannot be written is a fault too (step 247: a NaN in an assertion)
            $state = $report->result->state;
            $states[$state->value] = ($states[$state->value] ?? 0) + 1;
            if ($baseline !== null && $rank[$state->value] > $rank[$baseline]) {
                $raised++;
                $target = $out.'/raised-'.$name.'.'.pathinfo($file, PATHINFO_EXTENSION);
                file_put_contents($target, $mutated);
                printf("RAISED  %-60s %s at %s: %s -> %s%s -> %s\n", $name, $kind, implode(',', $where), $baseline, $state->value, $under, $target);
            } elseif (str_starts_with($kind, 'unprot')) {
                $accepted = array_intersect(['timeStamp.validated', 'timeStamp.trusted'], array_map(static fn (ValidationStatus $s): string => $s->code->value, array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->ingredientUri === null)));
                $chainTouched = array_filter($unprotected, static fn (array $r): bool => $r['what'] === 'x5chain' && array_filter($where, static fn (int $at): bool => $at >= $r['start'] && $at < $r['start'] + $r['length']) !== []) !== [];
                if ($accepted !== [] || ($chainTouched && $state !== ValidationState::Invalid)) {
                    $suspects++;
                    $target = $out.'/suspect-'.$name.'.'.pathinfo($file, PATHINFO_EXTENSION);
                    file_put_contents($target, $mutated);
                    printf("SUSPECT %-60s %s at %s: %s, %s%s -> %s\n", $name, $kind, implode(',', $where), $state->value, $accepted === [] ? 'x5chain changed' : implode('+', $accepted), $under, $target);
                }
            } elseif ($state === ValidationState::Valid || $state === ValidationState::Trusted) {
                $suspects++;
                $target = $out.'/suspect-'.$name.'.'.pathinfo($file, PATHINFO_EXTENSION);
                file_put_contents($target, $mutated);
                printf("SUSPECT %-60s %s at %s%s -> %s\n", $name, $kind, implode(',', $where), $under, $target);
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
    "\n%d runs over %d %s, seed %d, %d rounds each: %d faults, %d suspects%s; states %s; slowest run %.2fs; peak memory %d MiB; %.1fs total\n",
    $runs,
    count($pairs),
    $trust ? 'file and settings pairs' : 'files',
    $seed,
    $rounds,
    $faults,
    $suspects,
    $trust ? sprintf(', %d raised (unmutated: %s; %d pairs with an unprotected value, %d values split and skipped)', $raised, json_encode($baselines), $withUnprotected, $unprotectedSkipped) : '',
    json_encode($states),
    $slowest,
    intdiv($peak, 1048576),
    microtime(true) - $start,
);
exit($faults > 0 ? 1 : 0);
