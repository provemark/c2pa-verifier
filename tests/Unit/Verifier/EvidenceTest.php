<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\AnchorEvidence;
use Provemark\C2paVerifier\Trust\Certificate;
use Provemark\C2paVerifier\Trust\TrustAnchorSet;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\ManifestEvidence;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-082: the time judged at, and the anchor reached. The anchors and
 * depths of control.png were measured on 2026-10-27 from the status
 * explanations of the verifier at e01b800; the token's time is
 * SPEC-079's measurement.
 */

const SPEC082_SIGNER_ROOT = 'dfc7fc93b63a3b31c666f40da3f10902cd7ad0a77c33e197d3801545d7d3ec98';
const SPEC082_TSA_ROOT = '48f98f4727139f87f872f31de665b28fee7182a4adc00b3048dd742c5836fbb9';
const SPEC082_TOKEN_TIME = '2026-10-09T05:30:24Z';

function spec082Verify(string $relative, ?TrustSettings $settings = null, ?DateTimeInterface $at = null): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }
    try {
        return (new Verifier)->verify($stream, $settings, $at);
    } finally {
        fclose($stream);
    }
}

function spec082Settings(string $relative): TrustSettings
{
    return TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/'.$relative));
}

function spec082Epoch(string $iso): int
{
    return (new DateTimeImmutable($iso))->getTimestamp();
}

/** @return array{manifest: string, tsa: string} the probe's two roots as PEM, by kind */
function spec082Roots(string $probe): array
{
    $by = [];
    foreach (spec075A(spec075A(spec075A(json_decode((string) file_get_contents(Corpus::fixtures()."/timestamp/tsa-matrix/{$probe}.settings.json"), true, 512, JSON_THROW_ON_ERROR))['trust'] ?? null)['anchors'] ?? null) as $entry) {
        $entry = spec075A($entry);
        $by[spec075S($entry['trust_kind'] ?? null)] = spec075S($entry['trust_anchors'] ?? null);
    }

    return ['manifest' => $by['manifest'] ?? '', 'tsa' => $by['tsa'] ?? ''];
}

/**
 * A LoTE list of one service holding the PEM's first certificate, with a current status and a history.
 *
 * @param  list<array{string, string}>  $statuses  [status, starting time], the first the current one
 */
function spec082Lote(string $pem, array $statuses): string
{
    if (preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $pem, $m) !== 1) {
        throw new RuntimeException('no certificate');
    }
    $uri = static fn (string $s): string => "http://c2pa.org/conformance/trust-list/{$s}";
    $current = $statuses[0];
    $history = array_slice($statuses, 1);
    $service = ['ServiceInformation' => [
        'ServiceDigitalIdentity' => ['X509Certificates' => [['val' => (string) preg_replace('/\s+/', '', $m[1])]]],
        'ServiceStatus' => $uri($current[0]),
        'StatusStartingTime' => $current[1],
    ]];
    foreach ($history as [$status, $time]) {
        $service['ServiceHistory'][] = ['ServiceStatus' => $uri($status), 'StatusStartingTime' => $time];
    }

    return json_encode(['LoTE' => ['ListAndSchemeInformation' => ['LoTESequenceNumber' => 1], 'TrustedEntitiesList' => [['TrustedEntityServices' => [$service]]]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/** @return array<string, mixed>|null */
function spec082Anchor(?AnchorEvidence $anchor): ?array
{
    return $anchor === null ? null : [
        'route' => $anchor->route,
        'sha256' => $anchor->sha256,
        'depth' => $anchor->depth,
        'list' => $anchor->list === null ? null : [$anchor->list->kind, $anchor->list->sha256],
        'trustedFrom' => $anchor->trustedFrom,
        'trustedUntil' => $anchor->trustedUntil,
    ];
}

it('AC1: a trusted signer and a trusted time-stamp, each with its anchor', function (): void {
    $report = spec082Verify('timestamp/tsa-matrix/control.png', spec082Settings('timestamp/tsa-matrix/control.settings.json'));
    $evidence = $report->evidence();
    $active = $report->store->active->label ?? '';

    expect(array_keys($evidence))->toBe([$active])
        ->and($evidence[$active]->label)->toBe($active)
        ->and($evidence[$active]->timeSource)->toBe(ManifestEvidence::TIMESTAMP)
        ->and($evidence[$active]->judgedAt)->toBe(spec082Epoch(SPEC082_TOKEN_TIME))
        ->and(spec082Anchor($evidence[$active]->signer))->toBe(['route' => AnchorEvidence::ANCHOR, 'sha256' => SPEC082_SIGNER_ROOT, 'depth' => 2, 'list' => null, 'trustedFrom' => null, 'trustedUntil' => null])
        ->and(spec082Anchor($evidence[$active]->tsa))->toBe(['route' => AnchorEvidence::ANCHOR, 'sha256' => SPEC082_TSA_ROOT, 'depth' => 2, 'list' => null, 'trustedFrom' => null, 'trustedUntil' => null]);
})->group('SPEC-082');

it('AC2: the list that made each anchor count is the one trustLists() names', function (): void {
    $roots = spec082Roots('control');
    $report = spec082Verify('timestamp/tsa-matrix/control.png', TrustSettings::fromTrustLists($roots['manifest'], $roots['tsa']));
    $evidence = array_values($report->evidence())[0];
    [$signers, $tsas] = $report->trustLists();

    expect($evidence->signer?->list)->toEqual($signers)
        ->and($evidence->tsa?->list)->toEqual($tsas)
        ->and($evidence->signer?->list?->kind)->toBe(TrustAnchorSet::MANIFEST)
        ->and($evidence->tsa?->list?->kind)->toBe(TrustAnchorSet::TSA)
        ->and($evidence->signer?->sha256)->toBe(SPEC082_SIGNER_ROOT);
})->group('SPEC-082');

it('AC3: the stretch of a dated list that held the time judged at', function (): void {
    $roots = spec082Roots('control');
    $until = '2026-12-01T00:00:00Z';
    $ending = spec082Verify('timestamp/tsa-matrix/control.png', TrustSettings::fromTrustLists(spec082Lote($roots['manifest'], [['untrusted', $until]]), $roots['tsa']));
    $back = '2026-02-01T00:00:00Z';
    $resumed = spec082Verify('timestamp/tsa-matrix/control.png', TrustSettings::fromTrustLists(spec082Lote($roots['manifest'], [['trusted', $back], ['untrusted', '2026-01-01T00:00:00Z']]), $roots['tsa']));

    $a = array_values($ending->evidence())[0]->signer;
    $b = array_values($resumed->evidence())[0]->signer;
    expect([$a?->trustedFrom, $a?->trustedUntil])->toBe([null, spec082Epoch($until)])
        ->and([$b?->trustedFrom, $b?->trustedUntil])->toBe([spec082Epoch($back), null])
        ->and($a?->list?->form)->toBe('lote');
})->group('SPEC-082');

it('AC4: the time when no time-stamp gave one: given, or the clock', function (): void {
    $settings = spec082Settings('timestamp/tsa-matrix/no-timestamp.settings.json');
    $given = new DateTimeImmutable('2026-10-20T08:00:00Z');
    $withTime = array_values(spec082Verify('timestamp/tsa-matrix/no-timestamp.png', $settings, $given)->evidence())[0];
    $report = spec082Verify('timestamp/tsa-matrix/no-timestamp.png', $settings);
    $clock = array_values($report->evidence())[0];

    expect([$withTime->timeSource, $withTime->judgedAt, $withTime->tsa])->toBe([ManifestEvidence::GIVEN, $given->getTimestamp(), null])
        ->and([$clock->timeSource, $clock->judgedAt, $clock->tsa])->toBe([ManifestEvidence::CLOCK, $report->validationTime?->getTimestamp(), null])
        ->and($withTime->signer?->sha256)->not->toBeNull();
})->group('SPEC-082');

it('AC5: a leaf on the allowed list', function (): void {
    $settings = new TrustSettings([], [], [], true, [new TrustAnchorSet(TrustAnchorSet::MANIFEST, [], TrustSettings::certificatesFromPem((string) file_get_contents(Corpus::fixtures().'/trust/allowed_list.pem'), 'the allowed list', 64))]);
    $report = spec082Verify('matrix/es256.jpg', $settings);
    $active = $report->store?->active;
    $leaf = $active === null ? '' : hash('sha256', Certificate::chainOf(CoseSign1::ofManifest($active))[0]->der);

    expect($report->toArray()['validation_state'])->toBe('Trusted')
        ->and(spec082Anchor(array_values($report->evidence())[0]->signer))->toBe(['route' => AnchorEvidence::ALLOWED_LIST, 'sha256' => $leaf, 'depth' => 0, 'list' => null, 'trustedFrom' => null, 'trustedUntil' => null]);
})->group('SPEC-082');

it('AC6: an ingredient manifest has its own evidence, after the active one', function (): void {
    $report = spec082Verify('c2pa-rs/CACA.jpg', spec082Settings('trust/full.settings.json'));
    $evidence = $report->evidence();
    $active = $report->store->active->label ?? '';

    expect(count($evidence))->toBe(2)
        ->and(array_key_first($evidence))->toBe($active)
        ->and(array_map(static fn (ManifestEvidence $e): string => $e->label, array_values($evidence)))->toBe(array_keys($evidence));
    $ingredient = array_values($evidence)[1];
    expect($ingredient->label)->not->toBe($active)
        ->and($ingredient->signer?->route)->toBe(AnchorEvidence::ANCHOR)
        ->and($ingredient->timeSource)->toBeIn([ManifestEvidence::TIMESTAMP, ManifestEvidence::CLOCK]);
})->group('SPEC-082');

/**
 * Whether the evidence agrees with the statuses (AC7, amendment 1). A manifest was judged when a status
 * names its signature; each judged manifest must be in evidence(), and for each manifest in evidence()
 * whose signature has a status, signer and tsa follow signingCredential.trusted and timeStamp.trusted.
 * A manifest in evidence() whose signature has no status in the report is an ingredient manifest whose
 * success lines the writer recorded, dropped as c2pa-rs drops them (SPEC-021 amendment 1); it is
 * returned in the second list rather than compared.
 *
 * @return array{list<string>, list<string>} every disagreement, and the manifests not compared
 */
function spec082Disagreements(VerificationReport $report): array
{
    $judged = [];
    foreach ($report->result->statuses as $status) {
        if (preg_match('#^self\#jumbf=/c2pa/([^/]+)/c2pa\.signature$#', $status->url, $m) === 1) {
            $judged[$m[1]] = $status->ingredientUri;
        }
    }
    $found = [];
    $dropped = [];
    foreach (array_diff_key($judged, $report->evidence()) as $label => $_) {
        $found[] = "{$label}: judged, but not in evidence()";
    }
    foreach ($report->evidence() as $label => $evidence) {
        if (! array_key_exists($label, $judged)) {
            $dropped[] = $label;

            continue;
        }
        $scope = $judged[$label];
        $signer = $tsa = false;
        foreach ($report->result->statuses as $status) {
            $signer = $signer || ($status->code->value === 'signingCredential.trusted' && $status->url === "self#jumbf=/c2pa/{$label}/c2pa.signature");
            $tsa = $tsa || ($status->code->value === 'timeStamp.trusted' && $status->ingredientUri === $scope);
        }
        if (($evidence->signer !== null) !== $signer) {
            $found[] = sprintf('%s: signer %s, signingCredential.trusted %s', $label, $evidence->signer === null ? 'null' : 'set', $signer ? 'present' : 'absent');
        }
        if (($evidence->tsa !== null) !== ($tsa && $evidence->timeSource === ManifestEvidence::TIMESTAMP)) {
            $found[] = sprintf('%s: tsa %s, timeStamp.trusted %s, source %s', $label, $evidence->tsa === null ? 'null' : 'set', $tsa ? 'present' : 'absent', $evidence->timeSource);
        }
    }

    return [$found, $dropped];
}

it('AC7: the evidence agrees with the statuses', function (string $directory, string $settings): void {
    $trust = $settings === 'own' ? null : spec082Settings($settings);
    $checked = 0;
    $disagreements = [];
    $notCompared = [];
    foreach (glob(Corpus::fixtures()."/{$directory}/*.{jpg,png}", GLOB_BRACE) ?: [] as $path) {
        $relative = substr($path, strlen(Corpus::fixtures()) + 1);
        $own = preg_replace('/\.(jpg|png)$/', '.settings.json', $path) ?? '';
        $report = spec082Verify($relative, $settings === 'own' ? (is_file($own) ? TrustSettings::fromJson((string) file_get_contents($own)) : null) : $trust);
        [$found, $dropped] = spec082Disagreements($report);
        foreach ($found as $d) {
            $disagreements[] = "{$relative}: {$d}";
        }
        foreach ($dropped as $label) {
            $notCompared[] = "{$relative}: {$label}";
        }
        $checked += count($report->evidence());
    }

    expect($disagreements)->toBe([])
        ->and($checked)->toBeGreaterThan(count($notCompared));
})->with([
    'tsa-matrix, own settings' => ['timestamp/tsa-matrix', 'own'],
    'c2pa-rs, full' => ['c2pa-rs', 'trust/full.settings.json'],
    'c2pa-rs, none' => ['c2pa-rs', 'own'],
    'public-testfiles, full' => ['public-testfiles', 'trust/full.settings.json'],
    'chain-matrix, own settings' => ['trust/chain-matrix', 'own'],
])->group('SPEC-082');

it('AC7 (amendment 1): an ingredient manifest whose recorded success lines were dropped keeps its evidence', function (): void {
    $report = spec082Verify('c2pa-rs/update_manifest.jpg', spec082Settings('trust/full.settings.json'));
    [$found, $dropped] = spec082Disagreements($report);

    expect($found)->toBe([])
        ->and($dropped)->toBe(['adobe:urn:uuid:6a6b4dbe-5ef9-40ec-81ec-d8955db3e95c'])
        ->and($report->evidence()['adobe:urn:uuid:6a6b4dbe-5ef9-40ec-81ec-d8955db3e95c']->signer?->route)->toBe(AnchorEvidence::ANCHOR);
})->group('SPEC-082');

it('AC8: nothing to show, and nothing thrown', function (): void {
    $full = spec082Settings('trust/full.settings.json');
    $broken = spec082Verify('cose/leaf-der-broken.png', $full);
    $untrusted = spec082Verify('fixture-signed.png');
    $off = spec082Verify('fixture-signed.png', spec082Settings('trust/verify-off.settings.json'));

    expect(spec082Verify('fixture-unsigned.jpg', $full)->evidence())->toBe([])
        ->and(spec082Verify('jumbf/child-overruns.png', $full)->evidence())->toBe([])
        ->and(array_map(static fn (ManifestEvidence $e): ?AnchorEvidence => $e->signer, $broken->evidence()))->toBe([$broken->store->active->label ?? '' => null])
        ->and(array_map(static fn (ManifestEvidence $e): ?AnchorEvidence => $e->signer, $untrusted->evidence()))->toBe([$untrusted->store->active->label ?? '' => null])
        ->and(array_map(static fn (ManifestEvidence $e): ?AnchorEvidence => $e->signer, $off->evidence()))->toBe([$off->store->active->label ?? '' => null])
        ->and(spec082Verify('fixture-signed.png', $full)->toArray()['validation_state'])->toBe('Trusted');
})->group('SPEC-082');

it('AC9: the reports are the same with the evidence as without', function (): void {
    // the corpus run before and after is in the step's note; here the shapes are pinned on the files above
    $report = spec082Verify('timestamp/tsa-matrix/control.png', spec082Settings('timestamp/tsa-matrix/control.settings.json'), new DateTimeImmutable('2026-10-27T12:00:00Z'));
    $array = $report->toArray();

    expect(array_keys($array))->toBe(['active_manifest', 'manifests', 'validation_results', 'validation_state', 'format', 'has_manifest', 'checks_performed'])
        ->and($report->toCrJson())->not->toContain(SPEC082_SIGNER_ROOT)
        ->and($report->toJson())->not->toContain(SPEC082_SIGNER_ROOT)
        ->and($report->evidence())->not->toBe([]);
})->group('SPEC-082');
