<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustAnchorSet;
use Provemark\C2paVerifier\Trust\TrustListIdentity;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-081: which trust lists a verdict was judged against. The digests
 * below were measured with `shasum -a 256` over the fixture lists
 * (2026-10-27); the numbers and dates read from their
 * `ListAndSchemeInformation`. The asset is one the programme's lists trust
 * (SPEC-077).
 */

const SPEC081_ASSET = 'writers/openai-20260826-c2pa_2x.png';

function spec081List(string $name): string
{
    return (string) file_get_contents(Corpus::fixtures()."/trust/conformance-public/{$name}");
}

function spec081Verify(TrustSettings $settings): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/'.SPEC081_ASSET, 'rb');
    if ($stream === false) {
        throw new RuntimeException('cannot open '.SPEC081_ASSET);
    }
    try {
        return (new Verifier)->verify($stream, $settings, new DateTimeImmutable('2026-10-27T12:00:00Z'));
    } finally {
        fclose($stream);
    }
}

/** @return array{kind: string, form: string, sha256: string, sequenceNumber: int|null, issueDateTime: string|null} */
function spec081Fields(TrustListIdentity $identity): array
{
    return [
        'kind' => $identity->kind,
        'form' => $identity->form,
        'sha256' => $identity->sha256,
        'sequenceNumber' => $identity->sequenceNumber,
        'issueDateTime' => $identity->issueDateTime,
    ];
}

/** The signer list in JSON with its `ListAndSchemeInformation` changed, or a field removed when null. */
function spec081Lote(string $field, mixed $value, bool $remove = false): string
{
    $lote = spec075A(json_decode(spec081List('C2PA-TRUST-LIST.json'), true, 512, JSON_THROW_ON_ERROR));
    $list = spec075A($lote['LoTE'] ?? null);
    $information = spec075A($list['ListAndSchemeInformation'] ?? null);
    if ($remove) {
        unset($information[$field]);
    } else {
        $information[$field] = $value;
    }
    $list['ListAndSchemeInformation'] = $information;
    $lote['LoTE'] = $list;

    return json_encode($lote, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/** @return array{list<string>, array<string, mixed>, array<string, string>} what a set trusts: its anchors, their dates, their reasons */
function spec081Trusts(TrustAnchorSet $set): array
{
    return [array_map(static fn ($c): string => bin2hex($c->sha256), $set->anchors), $set->windows, $set->reasons];
}

it('AC1: a PEM list is named by its bytes', function (): void {
    $report = spec081Verify(TrustSettings::fromTrustLists(spec081List('C2PA-TRUST-LIST.pem'), spec081List('C2PA-TSA-TRUST-LIST.pem')));

    expect(array_map(spec081Fields(...), $report->trustLists()))->toBe([
        ['kind' => 'manifest', 'form' => 'pem', 'sha256' => 'b3949271a07b007e92ef1d18050b55388f5e0d2686a16fd864176c48421af8ae', 'sequenceNumber' => null, 'issueDateTime' => null],
        ['kind' => 'tsa', 'form' => 'pem', 'sha256' => '7182cb227c02701be0eebd67ab635fca7f85431c32796fb53b267c657cd2c675', 'sequenceNumber' => null, 'issueDateTime' => null],
    ]);
})->group('SPEC-081');

it('AC2: a LoTE list also carries its number and date', function (): void {
    $report = spec081Verify(TrustSettings::fromTrustLists(spec081List('C2PA-TRUST-LIST.json'), spec081List('C2PA-TSA-TRUST-LIST.json')));

    expect(array_map(spec081Fields(...), $report->trustLists()))->toBe([
        ['kind' => 'manifest', 'form' => 'lote', 'sha256' => 'f023f31dc3eb534d0afaab97b95cc8744ee2d88da6daebe5abf08770c3841d8c', 'sequenceNumber' => 1, 'issueDateTime' => '2026-08-05T00:11:39Z'],
        ['kind' => 'tsa', 'form' => 'lote', 'sha256' => '7ac0b1c96e4b5ad346c7f40794c54ce8510776e4a772d62c6ec33556a011a503', 'sequenceNumber' => 1, 'issueDateTime' => '2026-08-05T00:11:47Z'],
    ]);
})->group('SPEC-081');

it('AC2: one list alone is named alone, of its own kind', function (): void {
    $report = spec081Verify(TrustSettings::fromTrustLists(null, spec081List('C2PA-TSA-TRUST-LIST.json')));

    expect(array_map(static fn (TrustListIdentity $i): string => $i->kind.' '.$i->sha256, $report->trustLists()))
        ->toBe(['tsa 7ac0b1c96e4b5ad346c7f40794c54ce8510776e4a772d62c6ec33556a011a503']);
})->group('SPEC-081');

it('AC3: one byte apart, two identities that differ in the digest alone', function (): void {
    $original = spec081List('C2PA-TRUST-LIST.json');
    // a byte inside the first certificate's signature, ten characters from the end of its base64
    if (preg_match('/"val"\s*:\s*"([A-Za-z0-9+\/]+=*)"/', $original, $m, PREG_OFFSET_CAPTURE) !== 1) {
        throw new RuntimeException('no certificate in the list');
    }
    $at = $m[1][1] + strlen($m[1][0]) - 10;
    $changed = substr_replace($original, $original[$at] === 'A' ? 'B' : 'A', $at, 1);
    expect(strlen($changed))->toBe(strlen($original))
        ->and($changed)->not->toBe($original);

    $a = spec081Fields(spec081Verify(TrustSettings::fromTrustLists($original))->trustLists()[0]);
    $b = spec081Fields(spec081Verify(TrustSettings::fromTrustLists($changed))->trustLists()[0]);

    expect($b['sha256'])->toBe(hash('sha256', $changed))
        ->and($a['sha256'])->toBe(hash('sha256', $original))
        ->and(array_diff_assoc($a, $b))->toBe(['sha256' => $a['sha256']]);
})->group('SPEC-081');

it('AC4: the digest is of the bytes as given, a byte order mark included', function (): void {
    $marked = "\u{FEFF}".spec081List('C2PA-TRUST-LIST.pem');

    $digest = TrustSettings::fromTrustLists($marked)->anchorSets[0]->identity?->sha256;

    expect($digest)->toBe(hash('sha256', $marked))
        ->and($digest === 'b3949271a07b007e92ef1d18050b55388f5e0d2686a16fd864176c48421af8ae')->toBeFalse();
})->group('SPEC-081');

it('AC5: without lists, and with a settings document, nothing is named', function (): void {
    $stream = fopen(Corpus::fixtures().'/'.SPEC081_ASSET, 'rb');
    if ($stream === false) {
        throw new RuntimeException('cannot open '.SPEC081_ASSET);
    }
    $bare = (new Verifier)->verify($stream);
    fclose($stream);
    $settings = TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/timestamp/tsa-matrix/control.settings.json'));

    expect($bare->trustLists())->toBe([])
        ->and(spec081Verify($settings)->trustLists())->toBe([])
        ->and(array_map(static fn (TrustAnchorSet $s): ?TrustListIdentity => $s->identity, $settings->anchorSets))->each->toBeNull();
})->group('SPEC-081');

it('AC6: the verdict and both report shapes are the same with the identities as without', function (string $signers, string $tsas): void {
    $named = TrustSettings::fromTrustLists(spec081List($signers), spec081List($tsas));
    $unnamed = new TrustSettings([], [], [], true, array_map(
        static fn (TrustAnchorSet $s): TrustAnchorSet => new TrustAnchorSet($s->kind, $s->anchors, $s->allowedList, $s->trustConfig, $s->uri, $s->windows, $s->reasons),
        $named->anchorSets,
    ));
    $with = spec081Verify($named);
    $without = spec081Verify($unnamed);

    expect($with->trustLists())->toHaveCount(2)
        ->and($without->trustLists())->toBe([])
        ->and($with->toArray()['validation_state'])->toBe('Trusted')
        ->and($with->toJson())->toBe($without->toJson())
        ->and($with->toCrJson())->toBe($without->toCrJson());
})->with([
    'PEM' => ['C2PA-TRUST-LIST.pem', 'C2PA-TSA-TRUST-LIST.pem'],
    'LoTE' => ['C2PA-TRUST-LIST.json', 'C2PA-TSA-TRUST-LIST.json'],
])->group('SPEC-081');

it('AC7: a number or date that is not one is null, and the list is read as before', function (string $field, mixed $value, bool $remove): void {
    $reference = TrustSettings::fromTrustLists(spec081Lote('LoTESequenceNumber', 1))->anchorSets[0];
    $list = spec081Lote($field, $value, $remove);
    $set = TrustSettings::fromTrustLists($list)->anchorSets[0];

    expect(spec081Trusts($set))->toBe(spec081Trusts($reference))
        ->and($set->identity?->sha256)->toBe(hash('sha256', $list))
        ->and($field === 'LoTESequenceNumber' ? $set->identity?->sequenceNumber : $set->identity?->issueDateTime)->toBeNull()
        ->and($field === 'LoTESequenceNumber' ? $set->identity?->issueDateTime : $set->identity?->sequenceNumber)->not->toBeNull();
})->with([
    'number absent' => ['LoTESequenceNumber', null, true],
    'number null' => ['LoTESequenceNumber', null, false],
    'number a string' => ['LoTESequenceNumber', '1', false],
    'number zero' => ['LoTESequenceNumber', 0, false],
    'number negative' => ['LoTESequenceNumber', -1, false],
    'number a float' => ['LoTESequenceNumber', 1.5, false],
    'number true' => ['LoTESequenceNumber', true, false],
    'date absent' => ['ListIssueDateTime', null, true],
    'date a number' => ['ListIssueDateTime', 1754352699, false],
    'date without a time' => ['ListIssueDateTime', '2026-08-05', false],
    'date with a control character' => ['ListIssueDateTime', "2026-08-05T00:11:39Z\u{7}", false],
    'date with a line break before the end' => ['ListIssueDateTime', "2026-08-05T00:11:39Z\n", false],
    'date on a day that does not exist' => ['ListIssueDateTime', '2026-02-30T00:11:39Z', false],
])->group('SPEC-081');

it('AC7: a well-formed number and date are kept verbatim, offset and fraction included', function (): void {
    $set = TrustSettings::fromTrustLists(spec081Lote('ListIssueDateTime', '2026-10-17T09:30:00.25+02:00'))->anchorSets[0];

    expect($set->identity?->issueDateTime)->toBe('2026-10-17T09:30:00.25+02:00')
        ->and(TrustSettings::fromTrustLists(spec081Lote('LoTESequenceNumber', 7))->anchorSets[0]->identity?->sequenceNumber)->toBe(7);
})->group('SPEC-081');
