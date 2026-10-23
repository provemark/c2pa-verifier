<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cli\Command;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-075: the crJSON document (C2PA 2.4 crJSON §3). The oracle for order,
 * placement, keys, claims and embedded files is c2patool 0.28.2 --crjson
 * under c2patool/crjson/; the codes are the report's, tested elsewhere.
 * The schema is the page's own, under crjson/ (ADR-0006).
 */

/** The fields the crJSON schema's claimV1 names. */
const SPEC075_V1_SCHEMA_FIELDS = ['claim_generator', 'claim_generator_info', 'signature', 'assertions', 'dc:format', 'instanceID', 'dc:title', 'redacted_assertions', 'alg', 'alg_soft', 'metadata'];

/** The three files where SPEC-021 AC9 validates a manifest named twice once, under the first assertion. */
const SPEC075_NAMED_TWICE = [
    'c2pa-rs/exp-test1.png',
    'public-testfiles/adobe-20220124-CACAICAICICA.jpg',
    'public-testfiles/adobe-20220124-CAIAIIICAICIICAIICICA.jpg',
];

/** @return array{int, string, string} exit status, stdout, stderr */
function spec075Run(string ...$arguments): array
{
    $out = fopen('php://memory', 'w+b');
    $err = fopen('php://memory', 'w+b');
    if ($out === false || $err === false) {
        throw new RuntimeException('cannot open php://memory');
    }
    $status = (new Command(new Verifier))->run(array_values($arguments), $out, $err);
    rewind($out);
    rewind($err);

    return [$status, (string) stream_get_contents($out), (string) stream_get_contents($err)];
}

/** A claim's URIs made absolute as c2pa-rs writes them, for comparing; ours stays faithful. */
function spec075Absolute(mixed $claim, string $label): mixed
{
    if (! is_array($claim)) {
        return $claim;
    }
    $absolute = static fn (string $uri): string => str_starts_with($uri, 'self#jumbf=/') ? $uri : 'self#jumbf=/c2pa/'.$label.'/'.substr($uri, strlen('self#jumbf='));
    if (is_string($claim['signature'] ?? null)) {
        $claim['signature'] = $absolute($claim['signature']);
    }
    if (is_array($claim['assertions'] ?? null)) {   // v1's list; c2pa-rs leaves v2's created and gathered lists relative
        $claim['assertions'] = array_map(static function (mixed $entry) use ($absolute): mixed {
            if (is_array($entry) && is_string($entry['url'] ?? null)) {
                $entry['url'] = $absolute($entry['url']);
            }

            return $entry;
        }, $claim['assertions']);
    }

    return $claim;
}

/**
 * The three groups of a results object, nothing else.
 *
 * @return array{success: mixed, informational: mixed, failure: mixed}
 */
function spec075Groups(mixed $results): array
{
    $results = spec075A($results, 'results');

    return ['success' => $results['success'] ?? null, 'informational' => $results['informational'] ?? null, 'failure' => $results['failure'] ?? null];
}

/**
 * Each manifest's delta URIs, by manifest label, sorted.
 *
 * @param  list<array<string, mixed>>  $manifests
 * @return array<string, list<string>>
 */
function spec075Placement(array $manifests): array
{
    $map = [];
    foreach ($manifests as $manifest) {
        foreach (spec075A($manifest['ingredientDeltas'] ?? []) as $delta) {
            $map[spec075S($manifest['label'])][] = spec075S(spec075A($delta)['ingredientAssertionURI'] ?? null);
        }
    }
    ksort($map);
    foreach ($map as $label => $uris) {
        sort($uris);
        $map[$label] = $uris;
    }

    return $map;
}

// AC1
it('AC1: the root holds @context, jsonGenerator and manifests, nothing else', function (): void {
    $doc = spec075Doc(spec075Report('fixture-signed.png'));

    expect(array_keys($doc))->toBe(['@context', 'jsonGenerator', 'manifests'])
        ->and($doc['@context'])->toBe(['@vocab' => 'https://c2pa.org/crjson', 'extras' => 'https://c2pa.org/crjson/extras'])
        ->and($doc['jsonGenerator'])->toBe(['name' => 'provemark/c2pa-verifier', 'version' => Verifier::VERSION])
        ->and(Verifier::VERSION)->toMatch('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?$/');
})->group('SPEC-075');

// AC2
it('AC2: every manifest, in reverse store order, with exactly its fields', function (): void {
    $files = 0;
    foreach (spec075Oracle() as $relative => $theirs) {
        $ours = spec075Manifests(spec075Doc(spec075Report($relative)));
        $their = spec075Manifests($theirs);
        expect(array_column($ours, 'label'))->toBe(array_column($their, 'label'), $relative);
        foreach ($ours as $i => $manifest) {
            $claimKey = array_key_exists('claim.v2', spec075A($their[$i] ?? null)) ? 'claim.v2' : 'claim';
            $keys = array_keys($manifest);
            sort($keys);
            $expected = ['assertions', $claimKey, 'label', 'signature', 'validationResults', ...(array_key_exists('ingredientDeltas', $manifest) ? ['ingredientDeltas'] : [])];
            sort($expected);
            expect($keys)->toBe($expected, "{$relative} {$i}");
        }
        $files++;
    }
    expect($files)->toBe(59);
})->group('SPEC-075');

// AC3
it('AC3: the claim is faithful; against c2patool equal for v2, and for v1 but for five named ways', function (): void {
    $v1 = 0;
    $v2 = 0;
    foreach (spec075Oracle() as $relative => $theirs) {
        $their = array_column(spec075Manifests($theirs), null, 'label');
        foreach (spec075Manifests(spec075Doc(spec075Report($relative))) as $manifest) {
            $label = spec075S($manifest['label']);
            $theirClaims = spec075A($their[$label] ?? null, $label);
            if (array_key_exists('claim.v2', $manifest)) {
                expect(spec074Canon(spec075Absolute($manifest['claim.v2'], $label)))->toBe(spec074Canon(spec074DropAddedPad($theirClaims['claim.v2'] ?? null, $manifest['claim.v2'])), "{$relative} {$label}");
                $v2++;

                continue;
            }
            $ours = spec075A($manifest['claim'] ?? null, "{$relative} {$label}");
            $other = spec075A($theirClaims['claim'] ?? null, "{$relative} {$label}");
            if (in_array($ours['claim_generator_info'] ?? null, [null, []], true)) {
                unset($other['claim_generator_info'], $ours['claim_generator_info']);   // absent, null or empty: c2pa-rs makes one up from claim_generator
            }
            foreach (array_keys($ours) as $field) {
                if (! array_key_exists($field, $other) && ! in_array($field, SPEC075_V1_SCHEMA_FIELDS, true)) {
                    unset($ours[$field]);   // amendment 1: c2pa-rs leaves out what the v1 schema does not name
                }
            }
            if (($ours['redacted_assertions'] ?? null) === [] && ! array_key_exists('redacted_assertions', $other)) {
                unset($ours['redacted_assertions']);   // amendment 1: §3.5.1 asks [] of every claim; c2pa-rs writes it for v2 only
            }
            expect(spec074Canon(spec075Absolute($ours, $label)))->toBe(spec074Canon(spec074DropAddedPad($other, $ours)), "{$relative} {$label}");
            $v1++;
        }
    }
    expect($v2)->toBe(18)
        ->and($v1)->toBe(74);
})->group('SPEC-075');

it('AC3: a v2 claim without gathered or redacted assertions writes them as empty lists', function (): void {
    $claim = spec075Manifests(spec075Doc(spec075Report('fixture-signed.png')))[0]['claim.v2'] ?? null;

    expect(is_array($claim))->toBeTrue();
    if (is_array($claim)) {
        expect($claim['redacted_assertions'] ?? null)->toBe([])
            ->and(array_key_exists('gathered_assertions', $claim))->toBeTrue();
    }
})->group('SPEC-075');

// AC4
it('AC4: one key per assertion in store order; embedded files as c2patool writes them', function (): void {
    $embedded = 0;
    foreach (spec075Oracle() as $relative => $theirs) {
        $report = spec075Report($relative);
        $their = array_column(spec075Manifests($theirs), 'assertions', 'label');
        $store = $report->store;
        expect($store === null)->toBeFalse($relative);
        foreach (spec075Manifests(spec075Doc($report)) as $manifest) {
            $label = spec075S($manifest['label']);
            $assertions = spec075A($manifest['assertions'] ?? null, $label);
            $inStore = $store === null ? [] : array_values(array_map(static fn ($a): string => $a->label, $store->manifests[$label]->assertions));
            expect(array_keys($assertions))->toBe($inStore, "{$relative} {$label}");
            $theirAssertions = spec075A($their[$label] ?? null, $label);
            $theirKeys = array_keys($theirAssertions);
            sort($theirKeys);
            $ourKeys = array_keys($assertions);
            sort($ourKeys);
            expect($ourKeys)->toBe($theirKeys, "{$relative} {$label}");
            foreach ($assertions as $key => $value) {
                if (is_array($value) && array_key_exists('identifier', $value) && array_key_exists('format', $value)) {
                    expect($value)->toBe($theirAssertions[$key] ?? null, "{$relative} {$label} {$key}");
                    $embedded++;
                }
            }
        }
    }
    expect($embedded)->toBe(156);
})->group('SPEC-075');

// AC5
it('AC5: the results, per manifest; deltas on the manifest that holds the ingredient', function (): void {
    $placed = 0;
    foreach (spec075Oracle() as $relative => $theirs) {
        $report = spec075Report($relative);
        $results = spec075A($report->result->toArray()['validation_results'] ?? null);
        $manifests = spec075Manifests(spec075Doc($report));
        $first = spec075A($manifests[0]['validationResults'] ?? null);
        expect(spec075Groups($first))->toBe($results['activeManifest'] ?? null, $relative)
            ->and($first['specVersion'] ?? null)->toBe('2.4.0');
        foreach ($manifests as $manifest) {
            foreach (spec075A($manifest['ingredientDeltas'] ?? []) as $delta) {
                expect(str_starts_with(spec075S(spec075A($delta)['ingredientAssertionURI'] ?? null), 'self#jumbf=/c2pa/'.spec075S($manifest['label']).'/'))->toBeTrue($relative);
            }
        }
        $ours = spec075Placement($manifests);
        $their = spec075Placement(spec075Manifests($theirs));
        if (in_array($relative, SPEC075_NAMED_TWICE, true)) {
            expect($ours)->not->toBe($their, "{$relative}: no longer a SPEC-021 AC9 difference");
        } else {
            expect($ours)->toBe($their, $relative);
            $placed++;
        }
    }
    expect($placed)->toBe(56);
})->group('SPEC-075');

it('AC5: an ingredient manifest holds the delta that names it, and empty lists where none does', function (): void {
    $manifests = spec075Manifests(spec075Doc(spec075Report('c2pa-rs/ocsp.jpg')));
    $delta = spec075A(spec075A(spec075A($manifests[0]['ingredientDeltas'] ?? null)[0] ?? null)['validationDeltas'] ?? null);
    expect(spec075Groups($manifests[1]['validationResults'] ?? null))->toBe($delta);

    $orphan = spec075Manifests(spec075Doc(spec075Report('public-testfiles/adobe-20220124-E-clm-CAICAI.jpg')))[1]['validationResults'] ?? null;
    expect(spec075Groups($orphan))->toBe(['success' => [], 'informational' => [], 'failure' => []]);
})->group('SPEC-075');

// AC6
it('AC6: every manifest carries the validation time the report judged at', function (): void {
    $given = spec075Manifests(spec075Doc(spec075Report('c2pa-rs/ocsp.jpg', null, new DateTimeImmutable('2026-10-09T07:31:00.7+02:00'))));
    $time = static fn (array $m): string => spec075S(spec075A($m['validationResults'] ?? null)['validationTime'] ?? null);
    expect(array_values(array_unique(array_map($time, $given))))->toBe(['2026-10-09T05:31:00Z']);

    $before = time();
    $now = spec075Manifests(spec075Doc(spec075Report('c2pa-rs/ocsp.jpg')));
    $after = time();
    $times = array_values(array_unique(array_map($time, $now)));
    expect($times)->toHaveCount(1)
        ->and($times[0])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
    $at = (new DateTimeImmutable($times[0]))->getTimestamp();
    expect($at >= $before && $at <= $after)->toBeTrue();
})->group('SPEC-075');

// AC7
it('AC7: the validator first reproduces step 370 on c2patool\'s own answers', function (): void {
    $asWritten = 0;
    $stripped = [];
    foreach (spec075Oracle() as $answer) {
        $asWritten += spec075SchemaErrors((string) json_encode($answer)) === [] ? 0 : 1;
        $answer['manifests'] = array_map(static function (mixed $m): mixed {
            if (is_array($m)) {
                unset($m['isUpdateManifest'], $m['isCompressedManifest']);
            }

            return $m;
        }, spec075A($answer['manifests'] ?? null));
        $stripped = [...$stripped, ...spec075SchemaErrors((string) json_encode($answer, JSON_PRESERVE_ZERO_FRACTION))];
    }
    sort($stripped);
    expect($asWritten)->toBe(59)
        ->and(array_count_values($stripped))->toBe([
            '/manifests/*/claim/metadata :: The data (array) must match the type: object' => 1,
            '/manifests/*/signature/timeStampInfo :: The required properties (timestamp) are missing' => 6,
        ]);
})->group('SPEC-075');

it('AC7: every crJSON written is schema-valid but for the accepted v1 claims', function (): void {
    $documents = 0;
    $cases = [...array_map(static fn (string $r): array => [$r, null], array_keys(spec075Oracle())), ['c2pa-rs/ocsp.jpg', new DateTimeImmutable('2026-10-09T05:31:00Z')]];
    foreach ($cases as [$relative, $at]) {
        $report = spec075Report($relative, null, $at);
        $doc = spec075Doc($report);
        $v1 = array_filter(spec075Manifests($doc), static fn (array $m): bool => array_key_exists('claim', $m)) !== [];
        foreach (spec075SchemaErrors($report->toCrJson()) as $error) {
            // amendment 1: a v1 claim is written as its CBOR holds it, and C2PA 1.x claims break the v1 schema;
            // the signature's own exception went with SPEC-076 AC6, none of these files has an unreadable one
            $accepted = ($v1 && (str_starts_with($error, '/manifests/*/claim :: ') || str_starts_with($error, '/manifests/*/claim/')));
            expect($accepted)->toBeTrue("{$relative}: {$error}");
        }
        $documents++;
    }
    expect($documents)->toBe(60);
})->group('SPEC-075');

// AC8
it('AC8: without a store, manifests is empty and the root is otherwise as AC1', function (string $relative): void {
    $doc = spec075Doc(spec075Report($relative));

    expect(array_keys($doc))->toBe(['@context', 'jsonGenerator', 'manifests'])
        ->and($doc['manifests'])->toBe([]);
})->with(['fixture-unsigned.png', 'jumbf/child-overruns.png', 'jumbf/README.md'])->group('SPEC-075');

it('AC8: on the command line, the report\'s failure goes to standard error and the exit is the report\'s', function (): void {
    [$status, $out, $err] = spec075Run('--crjson', Corpus::fixtures().'/jumbf/child-overruns.png');

    expect($status)->toBe(1)
        ->and($out)->toBe(spec075Report('jumbf/child-overruns.png')->toCrJson()."\n")
        ->and(substr_count($err, "\n"))->toBe(1)
        ->and($err)->toContain('general.error');
})->group('SPEC-075');

// AC9
it('AC9: --crjson prints toCrJson() of the same call, with --settings and --at', function (): void {
    $probe = 'timestamp/tsa-matrix/expired-signer-no-timestamp';
    $file = Corpus::fixtures()."/{$probe}.png";
    $settings = Corpus::fixtures()."/{$probe}.settings.json";
    [$status, $out, $err] = spec075Run('--crjson', '--settings', $settings, '--at', '2026-10-09T05:31:00Z', $file);

    expect($status)->toBe(0)
        ->and($err)->toBe('')
        ->and($out)->toBe(spec075Report("{$probe}.png", "{$probe}.settings.json", new DateTimeImmutable('2026-10-09T05:31:00Z'))->toCrJson()."\n");

    [$twice, $nothing] = spec075Run('--crjson', '--crjson', $file);
    expect($twice)->toBe(2)
        ->and($nothing)->toBe('');
})->group('SPEC-075');
