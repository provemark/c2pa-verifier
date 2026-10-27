<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Timestamp\TimestampAssertions;
use Provemark\C2paVerifier\Timestamp\TimestampCheck;
use Provemark\C2paVerifier\Timestamp\TimestampEvidence;
use Provemark\C2paVerifier\Timestamp\TimeStampToken;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\ManifestEvidence;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-084: the time-stamps found. The tokens, times, certificates and
 * statuses were measured on 2026-10-27 with TimestampHeader,
 * TimestampAssertions and TimeStampToken over the verifier at ebecb10.
 */

const SPEC084_CONTROL_TOKEN = '16eca41812a7299990c6840b7be210939b6b1eb8369f090da32227039a942a29';
const SPEC084_CONTROL_CERTIFICATES = [
    ['cca524868f101319e6d560b8c544ce9ae6546fae9085a7ed821b4b22db51ab1a', 554],
    ['d09e9495cebfeb1e019faeb1be361ed172415ecf1259a48a0694be31e3f65900', 532],
];

/** @return list<TimestampEvidence> the time-stamps of the manifest in evidence() with this label, or of the active one */
// its own, not EvidenceTest's: a parallel worker loads only some test files
function spec084Epoch(string $iso): int
{
    return (new DateTimeImmutable($iso))->getTimestamp();
}

function spec084Timestamps(VerificationReport $report, ?string $label = null): array
{
    $label ??= $report->store->active->label ?? '';
    expect($report->evidence())->toHaveKey($label);

    return $report->evidence()[$label]->timestamps;
}

/** @return array{string, string, string, ?int, list<array{string, int}>, string, list<string>} one entry as plain values */
function spec084Entry(TimestampEvidence $e): array
{
    return [
        $e->source,
        $e->url,
        hash('sha256', $e->token),
        $e->genTime,
        array_map(static fn (string $der): array => [hash('sha256', $der), strlen($der)], $e->certificates),
        $e->outcome,
        array_map(static fn (StatusCode $c): string => $c->value, $e->codes),
    ];
}

/**
 * @param  list<TimestampEvidence>  $entries
 * @return list<array<int, mixed>> each entry as plain values, without its certificates
 */
function spec084WithoutCertificates(array $entries): array
{
    return array_map(static function (TimestampEvidence $e): array {
        $entry = spec084Entry($e);
        unset($entry[4]);

        return $entry;
    }, $entries);
}

function spec084Signature(string $label): string
{
    return "self#jumbf=/c2pa/{$label}/c2pa.signature";
}

it('AC1: the token that gave the time', function (): void {
    $report = spec075Report('timestamp/tsa-matrix/control.png', 'timestamp/tsa-matrix/control.settings.json');
    $label = $report->store->active->label ?? '';
    $timestamps = spec084Timestamps($report);

    expect(array_map(spec084Entry(...), $timestamps))->toBe([[
        TimestampEvidence::SIG_TST2,
        spec084Signature($label),
        SPEC084_CONTROL_TOKEN,
        spec084Epoch('2026-10-09T05:30:24Z'),
        SPEC084_CONTROL_CERTIFICATES,
        TimestampEvidence::USED,
        ['timeStamp.validated', 'timeStamp.trusted'],
    ]])
        ->and(strlen($timestamps[0]->token))->toBe(1635)
        ->and($timestamps[0]->genTime)->toBe($report->evidence()[$label]->judgedAt);
})->group('SPEC-084');

it('AC2: the same token, rejected without settings', function (): void {
    $report = spec075Report('timestamp/tsa-matrix/control.png');

    expect(array_map(spec084Entry(...), spec084Timestamps($report)))->toBe([[
        TimestampEvidence::SIG_TST2,
        spec084Signature($report->store->active->label ?? ''),
        SPEC084_CONTROL_TOKEN,
        spec084Epoch('2026-10-09T05:30:24Z'),
        SPEC084_CONTROL_CERTIFICATES,
        TimestampEvidence::REJECTED,
        ['timeStamp.validated', 'timeStamp.untrusted'],
    ]]);
})->group('SPEC-084');

it('AC3: a token an assertion offers, used and rejected', function (): void {
    $ingredient = 'urn:c2pa:c7cb9f11-e1ba-4a20-9c1b-fe2c6dc553d9';
    $assertion = 'self#jumbf=/c2pa/urn:c2pa:59a5a1ee-9540-4894-84e2-61c4d62762dd/c2pa.assertions/c2pa.time-stamp';
    $used = spec084Timestamps(spec075Report('timestamp/assertion/raw.png', 'timestamp/assertion/raw.settings.json'), $ingredient);
    $rejected = spec084Timestamps(spec075Report('timestamp/assertion/untrusted.png', 'timestamp/assertion/untrusted.settings.json'), $ingredient);
    $shape = spec084WithoutCertificates(...);

    expect($shape($used))->toBe([[
        TimestampEvidence::ASSERTION, $assertion, '0201f3b59a4b9a8770d2c766af9c7e2efb0ed5547bc70e0c752a1446d96266cc', spec084Epoch('2026-10-09T13:38:13Z'),
        5 => TimestampEvidence::USED, 6 => ['timeStamp.validated', 'timeStamp.trusted'],
    ]])
        ->and($shape($rejected))->toBe([[
            TimestampEvidence::ASSERTION, $assertion, '0201f3b59a4b9a8770d2c766af9c7e2efb0ed5547bc70e0c752a1446d96266cc', spec084Epoch('2026-10-09T13:38:13Z'),
            5 => TimestampEvidence::REJECTED, 6 => ['timeStamp.validated', 'timeStamp.untrusted'],
        ]]);
})->group('SPEC-084');

it('AC4: a token after the one that gave the time is not tried', function (): void {
    $label = 'urn:c2pa:488bf983-c973-465d-a0eb-1597392cc5d0';
    $timestamps = spec084Timestamps(spec075Report('timestamp/assertion/header-wins.png', 'timestamp/assertion/header-wins.settings.json'), $label);

    expect(count($timestamps))->toBe(2)
        ->and([$timestamps[0]->source, hash('sha256', $timestamps[0]->token), $timestamps[0]->outcome])
        ->toBe([TimestampEvidence::SIG_TST2, 'ed24d4efb26865d58a52d56920f166fb3f79b39f6233d76eb5c79f13bf80aeb5', TimestampEvidence::USED])
        ->and(spec084Entry($timestamps[1]))->toBe([
            TimestampEvidence::ASSERTION,
            'self#jumbf=/c2pa/urn:c2pa:f66fe0a9-1cad-404d-b191-9fdc78e9e365/c2pa.assertions/c2pa.time-stamp',
            'af27feaa16d3bedd449852c84ab87bbc9bc4595d989c0ef0eb64cf19ff3a03db',
            spec084Epoch('2026-10-09T13:38:13Z'),
            [['735d754b362fec394c32cfe0543ab7c85decf4929c5d23fd8a6799454c484052', 610]],
            TimestampEvidence::NOT_TRIED,
            [],
        ]);
})->group('SPEC-084');

it('AC5: both tokens of a two-token header are listed, rejected', function (): void {
    $report = spec075Report('timestamp/tsa-matrix/two-tokens.png', 'timestamp/tsa-matrix/two-tokens.settings.json');
    $label = $report->store->active->label ?? '';
    $shape = array_map(static fn (TimestampEvidence $e): array => [hash('sha256', $e->token), $e->outcome, array_map(static fn (StatusCode $c): string => $c->value, $e->codes)], spec084Timestamps($report));

    expect($shape)->toBe([
        ['c04b079535290f70f6d96f60434d921b806f23a70eb1d1cba608683a24b22a11', TimestampEvidence::REJECTED, ['timeStamp.malformed']],
        ['44401016b5c1d15244e0f7992e0b0c148665ebbd10b6b12b5344cb5a3ea57696', TimestampEvidence::REJECTED, ['timeStamp.malformed']],
    ])
        ->and($report->evidence()[$label]->timeSource)->not->toBe(ManifestEvidence::TIMESTAMP);
})->group('SPEC-084');

it('AC6: a token that cannot be read keeps its bytes', function (string $relative, ?string $settings, string $source): void {
    $timestamps = spec084Timestamps(spec075Report($relative, $settings));

    expect(count($timestamps))->toBe(1)
        ->and($timestamps[0]->token)->not->toBe('')
        ->and([$timestamps[0]->source, $timestamps[0]->genTime, $timestamps[0]->certificates, $timestamps[0]->outcome, $timestamps[0]->codes])
        ->toBe([$source, null, [], TimestampEvidence::REJECTED, [StatusCode::TimeStampMalformed]]);
})->with([
    'genTime with minute 63' => ['c2pa-rs/CA_ct.jpg', null, 'sigTst'],
    'SHA-1 imprint' => ['timestamp/tsa-matrix/imprint-sha1.png', 'timestamp/tsa-matrix/imprint-sha1.settings.json', 'sigTst2'],
])->group('SPEC-084');

it('AC7: nothing to list', function (string $relative, ?string $settings): void {
    expect(spec084Timestamps(spec075Report($relative, $settings)))->toBe([]);
})->with([
    'a header that cannot be read' => ['timestamp/tsa-matrix/header-both.png', 'timestamp/tsa-matrix/header-both.settings.json'],
    'no time-stamp' => ['fixture-signed.png', null],
])->group('SPEC-084');

/**
 * Where the time-stamps of one manifest disagree with its evidence and, for the active manifest, the statuses (AC8).
 *
 * @return list<string>
 */
function spec084Disagreements(VerificationReport $report, string $label, ManifestEvidence $evidence): array
{
    $found = [];
    $outcomes = array_map(static fn (TimestampEvidence $e): string => $e->outcome, $evidence->timestamps);
    $used = array_keys($outcomes, TimestampEvidence::USED, true);
    if ($evidence->timeSource === ManifestEvidence::TIMESTAMP) {
        if (count($used) !== 1) {
            $found[] = sprintf('%s: time from a time-stamp, %d used', $label, count($used));
        } elseif ($evidence->timestamps[$used[0]]->genTime !== $evidence->judgedAt) {
            $found[] = "{$label}: the used token's genTime is not judgedAt";
        }
    } elseif ($used !== []) {
        $found[] = "{$label}: a token used, but the time is {$evidence->timeSource}";
    }
    foreach ($outcomes as $i => $outcome) {
        if ($used !== [] && $i > $used[0] && $outcome !== TimestampEvidence::NOT_TRIED) {
            $found[] = "{$label}: entry {$i} after the used one is {$outcome}";
        }
        if (($outcome === TimestampEvidence::NOT_TRIED) !== ($evidence->timestamps[$i]->codes === [])) {
            $found[] = "{$label}: entry {$i} is {$outcome} with ".count($evidence->timestamps[$i]->codes).' codes';
        }
    }
    if ($label !== ($report->store->active->label ?? '')) {
        return $found;
    }
    $codes = [];
    foreach ($evidence->timestamps as $e) {
        foreach ($e->codes as $code) {
            $codes[$e->url][$code->value] = true;
        }
    }
    $statuses = [];
    foreach ($report->result->statuses as $status) {
        if ($status->ingredientUri === null && array_key_exists($status->url, $codes) && str_starts_with($status->code->value, 'timeStamp.')) {
            $statuses[$status->url][$status->code->value] = true;
        }
    }
    foreach ($codes as $url => $set) {
        $have = $statuses[$url] ?? [];
        ksort($set);
        ksort($have);
        if ($set !== $have) {
            $found[] = sprintf('%s: at %s the entries say %s, the report %s', $label, $url, implode(',', array_keys($set)), implode(',', array_keys($have)));
        }
    }

    return $found;
}

it('AC8: over the corpus, the time-stamps agree with the verdict and the statuses', function (string $settings): void {
    $root = Corpus::fixtures();
    $l = "{$root}/trust/conformance-public";
    $trust = match ($settings) {
        'none' => null,
        'full' => TrustSettings::fromJson((string) file_get_contents("{$root}/trust/full.settings.json")),
        default => TrustSettings::fromTrustLists((string) file_get_contents("{$l}/C2PA-TRUST-LIST.json"), (string) file_get_contents("{$l}/C2PA-TSA-TRUST-LIST.json")),
    };
    $disagreements = [];
    $outcomes = [TimestampEvidence::USED => 0, TimestampEvidence::REJECTED => 0, TimestampEvidence::NOT_TRIED => 0];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || preg_match('/\.(json|pem|md|txt|cfg|php|key)$/i', $file->getFilename()) === 1) {
            continue;
        }
        $stream = fopen($file->getPathname(), 'rb');
        if ($stream === false) {
            throw new RuntimeException("cannot open {$file->getPathname()}");
        }
        $report = (new Verifier)->verify($stream, $trust);
        fclose($stream);
        foreach ($report->evidence() as $label => $evidence) {
            foreach (spec084Disagreements($report, $label, $evidence) as $d) {
                $disagreements[] = substr($file->getPathname(), strlen($root) + 1).": {$d}";
            }
            foreach ($evidence->timestamps as $e) {
                $outcomes[$e->outcome]++;
            }
        }
    }

    expect($disagreements)->toBe([])
        ->and($outcomes[TimestampEvidence::REJECTED])->toBeGreaterThan(0)
        ->and($outcomes[TimestampEvidence::USED] + $outcomes[TimestampEvidence::REJECTED])->toBeGreaterThan(100);
})->with(['none', 'full', 'lists'])->group('SPEC-084');

it('AC9: the reports carry no token bytes', function (): void {
    // the corpus run before and after is in the step's note; here the shapes are pinned on control.png
    $report = spec075Report('timestamp/tsa-matrix/control.png', 'timestamp/tsa-matrix/control.settings.json');
    $token = base64_encode(spec084Timestamps($report)[0]->token ?? '');

    expect($token)->not->toBe('')
        ->and($report->toJson())->not->toContain($token)
        ->and($report->toCrJson())->not->toContain($token);
})->group('SPEC-084');

it('AC10: TimestampEvidence and timestamps are in the contract', function (): void {
    expect((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/api/public-surface.txt'))
        ->toContain('Timestamp\TimestampEvidence :: method __construct')
        ->toContain('Verifier\ManifestEvidence :: property timestamps');
})->group('SPEC-084');

it('scope: an assertion token after the one that gave the time is not tried', function (): void {
    // standard-borrows-with-update.jpg offers one manifest two assertion tokens (one DigiCert token,
    // measured 2026-10-27); trusting the token's own chain lets the first give the time
    $relative = 'update-manifest/standard-borrows-with-update.jpg';
    $label = 'adobe:urn:uuid:6a6b4dbe-5ef9-40ec-81ec-d8955db3e95c';
    $token = spec084Timestamps(spec075Report($relative), $label)[0]->token ?? '';
    $pem = '';
    foreach (TimeStampToken::fromHeaderValue($token)->signedData->certificates as $der) {
        $pem .= "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
    }
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }
    $report = (new Verifier)->verify($stream, TrustSettings::fromJson((string) json_encode(['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $pem]])));
    fclose($stream);
    $timestamps = spec084Timestamps($report, $label);

    expect(array_map(static fn (TimestampEvidence $e): array => [$e->outcome, count($e->codes)], $timestamps))
        ->toBe([[TimestampEvidence::USED, 2], [TimestampEvidence::NOT_TRIED, 0]])
        ->and($timestamps[1]->url)->not->toBe($timestamps[0]->url)
        ->and($timestamps[1]->genTime)->toBe($timestamps[0]->genTime);
})->group('SPEC-084');

it('scope: an assertion token that cannot be read is listed, rejected, and the next is tried', function (): void {
    // no corpus file has an unreadable assertion token: raw.png's ingredient is offered a broken one before its own
    $settings = TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/timestamp/assertion/raw.settings.json'));
    $store = spec075Report('timestamp/assertion/raw.png', 'timestamp/assertion/raw.settings.json')->store;
    $ingredient = 'urn:c2pa:c7cb9f11-e1ba-4a20-9c1b-fe2c6dc553d9';
    $manifest = $store->manifests[$ingredient] ?? throw new RuntimeException('no ingredient manifest');
    [[$der, $url]] = TimestampAssertions::collect($store)->tokensFor($ingredient);
    $offered = Closure::bind(static fn (): TimestampAssertions => new TimestampAssertions([$ingredient => [["\x30\x03\x02\x01\x00", $url], [$der, $url]]], []), null, TimestampAssertions::class)();
    $result = (new TimestampCheck)->forManifest($manifest, $settings, $offered);

    expect(array_map(static fn (TimestampEvidence $e): array => [$e->genTime === null, $e->certificates === [], $e->outcome, array_map(static fn (StatusCode $c): string => $c->value, $e->codes)], $result->evidence))
        ->toBe([
            [true, true, TimestampEvidence::REJECTED, ['timeStamp.malformed']],
            [false, false, TimestampEvidence::USED, ['timeStamp.validated', 'timeStamp.trusted']],
        ])
        ->and($result->evidence[0]->token)->toBe("\x30\x03\x02\x01\x00");
})->group('SPEC-084');
