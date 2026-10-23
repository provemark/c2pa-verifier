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

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Provemark\C2paVerifier\Asn1\Der;
use Provemark\C2paVerifier\Asn1\DerReader;
use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Manifest\HashedUri;
use Provemark\C2paVerifier\Manifest\IngredientAssertion;
use Provemark\C2paVerifier\Manifest\ManifestGraph;
use Provemark\C2paVerifier\Manifest\ManifestStore;
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

/**
 * Step 247: fixture-signed.jpg with the CBOR text "c2pa.created" in its actions assertion replaced, at the
 * same length, by an array of four half-floats: NaN, +Infinity, -Infinity, NaN. JSON has no such numbers.
 */
function spec247NonFiniteJpeg(): string
{
    $bytes = (string) file_get_contents(Corpus::fixtures().'/fixture-signed.jpg');
    $at = strpos($bytes, "\x6cc2pa.created");
    if ($at === false) {
        throw new RuntimeException('the fixture has no c2pa.created text');
    }

    return substr_replace($bytes, "\x84\xf9\x7e\x00\xf9\x7c\x00\xf9\xfc\x00\xf9\x7e\x00", $at, 13);
}

/**
 * Both sides of AC7 brought to one form: a `b64'…'` string and an array of
 * integers 0–255 as the bytes they hold, floats at single precision, and an
 * object's keys sorted, since JSON objects are unordered (RFC 8259 §4).
 */
function spec074Canon(mixed $value): mixed
{
    if (is_float($value)) {
        $single = unpack('g', pack('g', $value));

        return is_array($single) ? $single[1] : $value;
    }
    if (is_string($value) && preg_match("/^b64'([A-Za-z0-9+\\/=]*)'$/D", $value, $m) === 1) {
        return ['bytes' => bin2hex((string) base64_decode($m[1], true))];
    }
    if (is_array($value) && $value !== [] && array_is_list($value)
        && array_filter($value, static fn (mixed $i): bool => is_int($i) && $i >= 0 && $i <= 255) === $value) {
        return ['bytes' => bin2hex(pack('C*', ...$value))];
    }
    if (is_array($value)) {
        $value = array_map(spec074Canon(...), $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    return $value;
}

/** c2pa-rs adds `"pad": "b64''"` beside a hash the CBOR holds without one; drop it where ours has none. */
function spec074DropAddedPad(mixed $theirs, mixed $ours): mixed
{
    if (! is_array($theirs) || ! is_array($ours)) {
        return $theirs;
    }
    if (! array_is_list($theirs) && ($theirs['pad'] ?? null) === "b64''" && ! array_key_exists('pad', $ours)) {
        unset($theirs['pad']);
    }
    foreach ($theirs as $key => $child) {
        if (array_key_exists($key, $ours)) {
            $theirs[$key] = spec074DropAddedPad($child, $ours[$key]);
        }
    }

    return $theirs;
}

/** The manifest store of a fixture, or a failure naming it. */
function spec074Store(string $relative): ManifestStore
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }
    $store = (new Verifier)->verify($stream)->store;
    fclose($stream);
    if ($store === null) {
        throw new RuntimeException("{$relative}: no manifest store");
    }

    return $store;
}

/** The crJSON schema's $id, under which the copy in Fixtures/crjson/ is registered (ADR-0006). */
const SPEC075_SCHEMA_ID = 'https://c2pa.org/crjson/crJSON.schema.json';

function spec075Report(string $relative, ?string $settings = null, ?DateTimeInterface $at = null): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }
    $trust = $settings === null ? null : TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/'.$settings));
    $report = (new Verifier)->verify($stream, $trust, $at);
    fclose($stream);

    return $report;
}

/** @return array<string, mixed> the crJSON as arrays (objects and lists alike), for reading fields */
function spec075Doc(VerificationReport $report): array
{
    /** @var array<string, mixed> */
    return json_decode($report->toCrJson(), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The 59 oracle answers, by the fixture they answer.
 *
 * @return array<string, array<string, mixed>>
 */
function spec075Oracle(): array
{
    /** @var array<string, array<string, mixed>>|null $all */
    static $all = null;
    if ($all !== null) {
        return $all;
    }
    $root = Corpus::fixtures().'/c2patool/crjson';
    $all = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || ! str_ends_with($file->getPathname(), '.json')) {
            continue;
        }
        /** @var array<string, mixed> $answer */
        $answer = json_decode((string) file_get_contents($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
        $all[substr($file->getPathname(), strlen($root) + 1, -strlen('.json'))] = $answer;
    }
    ksort($all);

    return $all;
}

/**
 * @param  array<mixed>  $doc
 * @return list<array<string, mixed>>
 */
function spec075Manifests(array $doc): array
{
    /** @var list<array<string, mixed>> */
    return is_array($doc['manifests'] ?? null) ? $doc['manifests'] : [];
}

/**
 * Every schema error as "path :: message", paths with indexes as `*`.
 *
 * @return list<string>
 */
function spec075SchemaErrors(string $json): array
{
    /** @var Validator|null $validator */
    static $validator = null;
    if ($validator === null) {
        $validator = new Validator;
        $validator->setMaxErrors(1000);
        $validator->resolver()?->registerRaw((string) file_get_contents(Corpus::fixtures().'/crjson/crJSON.schema.json'), SPEC075_SCHEMA_ID);
    }
    $result = $validator->validate(json_decode($json, false, 512, JSON_THROW_ON_ERROR), SPEC075_SCHEMA_ID);
    $error = $result->error();
    if ($error === null) {
        return [];
    }
    $errors = [];
    foreach ((new ErrorFormatter)->format($error, true) as $path => $messages) {
        foreach (is_array($messages) ? $messages : [$messages] as $message) {
            $errors[] = preg_replace('#/\d+(?=/|$)#', '/*', (string) $path).' :: '.(is_string($message) ? $message : '');
        }
    }

    return $errors;
}

/**
 * A decoded JSON value that must be an array, or a failure naming where.
 *
 * @return array<mixed>
 */
function spec075A(mixed $value, string $where = ''): array
{
    if (! is_array($value)) {
        throw new RuntimeException("not an array: {$where}");
    }

    return $value;
}

/** A decoded JSON value that must be a string, or a failure naming where. */
function spec075S(mixed $value, string $where = ''): string
{
    if (! is_string($value)) {
        throw new RuntimeException("not a string: {$where}");
    }

    return $value;
}
