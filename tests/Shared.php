<?php

declare(strict_types=1);

/*
 * The eight test helpers that more than one test file uses.
 *
 * Everything else in tests/ is defined in the file that needs it, which is
 * how this suite prefers it: a reader opening one test sees the whole of it.
 * These eight are the exception, and they are here for a reason that only
 * shows up when the suite is run in parallel.
 *
 * `pest` loads every test file into one process, so a function declared in
 * DerReaderTest.php is visible from TimeStampTokenTest.php and nobody
 * notices the coupling. `pest --parallel` gives each worker a subset, and
 * the second file then calls a function that was never declared — 24 tests
 * failed that way, all with `Error`, while the serial run was green.
 *
 * Pest loads tests/Pest.php for every worker, and that file requires this
 * one, so these declarations reach all of them. The rule to keep: a helper
 * moves here the moment a second file needs it, and not before.
 */

use Provemark\C2paVerifier\Asn1\Der;
use Provemark\C2paVerifier\Asn1\DerReader;
use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Manifest\HashedUri;
use Provemark\C2paVerifier\Manifest\IngredientAssertion;
use Provemark\C2paVerifier\Manifest\ManifestGraph;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\IngredientManifestCheck;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/**
 * The trust settings SPEC-021's fixtures were signed against, named once.
 * Used by IngredientManifestCheckTest and UpdateManifestTest.
 */
const SPEC021_SETTINGS = 'trust/full.settings.json';

/** A DER element from a hex string, spaces allowed — SPEC-016's vectors are written that way. */
function spec016Der(string $hex): Der
{
    return (new DerReader)->read((string) hex2bin(str_replace(' ', '', $hex)));
}

/** @return array<string, mixed> */
function spec020Oracle(string $relative): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(Corpus::fixtures().'/c2patool/'.$relative), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * One manifest of an oracle, typed.
 *
 * @return array<string, mixed>
 */
function spec020OracleManifest(string $oracle, string $label): array
{
    $json = spec020Oracle($oracle);
    assert(is_array($json['manifests']));
    $manifest = $json['manifests'][$label];
    assert(is_array($manifest));

    /** @var array<string, mixed> */
    return $manifest;
}

function spec020Verify(string $relative, ?string $settings = null): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }
    $trust = $settings === null ? null : TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/'.$settings));

    return (new Verifier)->verify($stream, $trust);
}

/**
 * The ingredient deltas of a report or an oracle, typed: the shape c2patool prints.
 *
 * @param  array<string, mixed>  $array
 * @return list<array{ingredientAssertionURI: string, validationDeltas: array{success: list<array<string, string>>, informational: list<array<string, string>>, failure: list<array<string, string>>}}>
 */
function spec020Deltas(array $array): array
{
    $results = $array['validation_results'] ?? [];
    assert(is_array($results));
    $deltas = $results['ingredientDeltas'] ?? [];
    assert(is_array($deltas));

    /** @var list<array{ingredientAssertionURI: string, validationDeltas: array{success: list<array<string, string>>, informational: list<array<string, string>>, failure: list<array<string, string>>}}> */
    return array_values($deltas);
}

/**
 * The report's flat failure list, typed.
 *
 * @param  array<string, mixed>  $array
 * @return list<array<string, string>>
 */
function spec020Failures(array $array): array
{
    /** @var list<array<string, string>> */
    return array_values((array) ($array['validation_status'] ?? []));
}

/** @return array<string, string> corpus name => fixture-relative path, the seventeen `_MULTI` files this verifier's JUMBF parser reads */
function spec020Multi(): array
{
    $files = [];
    foreach ([['public-testfiles', SPEC013_PUBLIC_MULTI], ['c2pa-rs', SPEC013_RS_MULTI], ['writers', SPEC013_WRITERS_MULTI]] as [$dir, $names]) {
        foreach ($names as $name) {
            if ($name === 'update_manifest') {
                continue;   // a c2um box: refused by SPEC-005 AC13 until SPEC-022
            }
            $path = glob(Corpus::fixtures()."/{$dir}/{$name}.*")[0] ?? throw new RuntimeException("no file for {$name}");
            $files["{$dir}/{$name}"] = "{$dir}/".basename($path);
        }
    }

    return $files;
}

/** @return list<string> the failure codes of an oracle's validation_status, unique and sorted */
function spec021OracleFailures(string $relative): array
{
    $oracle = spec020Oracle($relative);
    $codes = [];
    foreach ((array) ($oracle['validation_status'] ?? []) as $status) {
        assert(is_array($status) && is_string($status['code']));
        $codes[$status['code']] = true;
    }
    $codes = array_keys($codes);
    sort($codes);

    return $codes;
}

/**
 * Every PHP file under tests/, for the rules this suite keeps about itself.
 *
 * @return list<string>
 */
function spec000TestFiles(): array
{
    $files = [];
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/tests', FilesystemIterator::SKIP_DOTS));
    foreach ($walk as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

/**
 * Every `->toContain(…)` a test really calls, with its line and the number of
 * arguments it passes.
 *
 * Read through PHP's own tokenizer rather than with a regular expression, for
 * a reason this rule met immediately: the comments that warn about this very
 * trap contain the word `->toContain($needle, $message)`, and a text search
 * reports them as violations. The tokenizer knows a comment from a call.
 *
 * @return list<array{0: int, 1: int}> line, argument count
 */
function spec000ToContainCalls(string $source): array
{
    $tokens = token_get_all($source);
    $calls = [];
    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'toContain') {
            continue;
        }
        // a call, not a mention: `->toContain(` or `?->toContain(`
        $before = $tokens[$index - 1] ?? null;
        if (! is_array($before) || ($before[0] !== T_OBJECT_OPERATOR && $before[0] !== T_NULLSAFE_OBJECT_OPERATOR)) {
            continue;
        }
        $depth = 0;
        $arguments = 0;
        $seen = false;
        for ($i = $index + 1; $i < count($tokens); $i++) {
            $next = $tokens[$i];
            $text = is_array($next) ? $next[1] : $next;
            if ($text === '(' || $text === '[') {
                $depth++;
            } elseif ($text === ')' || $text === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($text === ',' && $depth === 1) {
                $arguments++;
            } elseif ($depth === 1 && trim($text) !== '') {
                $seen = true;
            }
        }
        $calls[] = [$token[2], $seen ? $arguments + 1 : 0];
    }

    return $calls;
}

/**
 * The hash statuses for c2pa-rs/CACA with the reference's hash replaced by one that matches nothing:
 * the seam, since no corpus file has a mismatching reference.
 *
 * @return list<ValidationStatus>
 */
function spec021Mismatch(): array
{
    $store = Corpus::manifestStore('c2pa-rs/CACA.jpg') ?? throw new RuntimeException('no store');
    $graph = ManifestGraph::fromStore($store);
    $ingredient = $graph->ingredients[$store->active->label][0];
    $reference = $ingredient->manifest ?? throw new RuntimeException('no reference');
    $broken = new IngredientAssertion(
        $ingredient->label, $ingredient->url, $ingredient->version, $ingredient->relationship,
        $ingredient->title, $ingredient->format, $ingredient->documentId, $ingredient->instanceId,
        new HashedUri($reference->url, new CborBytes(str_repeat("\x00", 32)), $reference->alg),
        $ingredient->claimSignature, $ingredient->thumbnail, $ingredient->validationStatus,
        $ingredient->validationResults, $ingredient->digitalSourceType, $ingredient->data,
    );
    $label = $ingredient->manifestLabel() ?? throw new RuntimeException('no reference');

    return (new IngredientManifestCheck)->hash($store->manifests[$label], $broken);
}
