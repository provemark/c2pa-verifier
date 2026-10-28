<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\Certificate;
use Provemark\C2paVerifier\Trust\OcspCheck;
use Provemark\C2paVerifier\Trust\OcspEvidence;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\ManifestEvidence;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-085: the OCSP responses offered. The responses, outcomes and times
 * were measured on 2026-10-27 with OcspCheck's pool and its reading of each
 * response over the verifier at 13839bf.
 */

const SPEC085_OPENAI = 'writers/openai-20260826-c2pa_2x.png';
const SPEC085_OPENAI_RESPONSE = 'f440effccf83491c4cbf3894e41609d213f8c09cad509cca4a8dd214bb5bd4e6';

// its own, not EvidenceTest's: a parallel worker loads only some test files
function spec085Epoch(string $iso): int
{
    return (new DateTimeImmutable($iso))->getTimestamp();
}

/** @return list<OcspEvidence> the responses of the manifest in evidence() with this label, or of the active one */
function spec085Ocsp(VerificationReport $report, ?string $label = null): array
{
    $label ??= $report->store->active->label ?? '';
    expect($report->evidence())->toHaveKey($label);

    return $report->evidence()[$label]->ocsp;
}

/** @return array{string, string, string, string, ?string, ?string, ?int, ?int, ?int} one entry as plain values */
function spec085Entry(OcspEvidence $e): array
{
    return [$e->source, $e->url, hash('sha256', $e->response), $e->outcome, $e->code?->value, $e->certStatus, $e->producedAt, $e->thisUpdate, $e->nextUpdate];
}

/**
 * @param  list<OcspEvidence>  $entries
 * @return list<array<int, mixed>> each entry as plain values, without its code
 */
function spec085WithoutCode(array $entries): array
{
    return array_map(static function (OcspEvidence $e): array {
        $entry = spec085Entry($e);
        unset($entry[4]);

        return $entry;
    }, $entries);
}

/**
 * @param  list<OcspEvidence>  $entries
 * @return list<array{string, string, ?string, ?string, bool}> each entry's response hash, outcome, code, status, and whether its times are unset
 */
function spec085Shape(array $entries): array
{
    return array_map(static fn (OcspEvidence $e): array => [hash('sha256', $e->response), $e->outcome, $e->code?->value, $e->certStatus, $e->thisUpdate === null], $entries);
}

function spec085Report(string $relative, ?string $settings = null, ?string $at = null): VerificationReport
{
    return spec075Report($relative, $settings, $at === null ? null : new DateTimeImmutable($at));
}

/** @return list<Certificate> the throw-away signer and issuer the DER responses under tests/Fixtures/ocsp/ were made for */
function spec085Chain(): array
{
    $read = static fn (string $name): Certificate => Certificate::fromDer(base64_decode(preg_replace('/-----[^-]+-----|\s/', '', (string) file_get_contents(Corpus::fixtures()."/ocsp/{$name}.crt")) ?? '', true) ?: '');

    return [$read('signer'), $read('ca')];
}

function spec085Der(string $name): string
{
    return (string) file_get_contents(Corpus::fixtures()."/ocsp/{$name}.der");
}

/**
 * @param  list<string>  $ders  stapled, in order
 * @param  list<Certificate>|null  $chain
 * @return list<OcspEvidence> what the check made of the stapled responses, against the throw-away signer and issuer
 */
function spec085Stapled(array $ders, ?array $chain = null): array
{
    [, $evidence] = (new OcspCheck)->checkWithEvidence(['rVals' => ['ocspVals' => array_map(static fn (string $der): CborBytes => new CborBytes($der), $ders)]], $chain ?? spec085Chain(), null, 'self#jumbf=/c2pa/test/c2pa.signature');

    return $evidence;
}

it('AC1: a current response, chosen', function (): void {
    $report = spec085Report(SPEC085_OPENAI, null, '2026-08-26T12:00:00Z');
    $label = $report->store->active->label ?? '';
    $ocsp = spec085Ocsp($report);

    expect(array_map(spec085Entry(...), $ocsp))->toBe([[
        OcspEvidence::RVALS,
        "self#jumbf=/c2pa/{$label}/c2pa.signature",
        SPEC085_OPENAI_RESPONSE,
        OcspEvidence::CHOSEN,
        'signingCredential.ocsp.notRevoked',
        'good',
        spec085Epoch('2026-08-25T02:43:57Z'),
        spec085Epoch('2026-08-25T02:43:57Z'),
        spec085Epoch('2026-09-01T02:43:56Z'),
    ]])
        ->and(strlen($ocsp[0]->response))->toBe(2007);
})->group('SPEC-085');

it('AC2: the same response, no longer current, keeps its bytes and times', function (): void {
    $then = spec085Ocsp(spec085Report(SPEC085_OPENAI, null, '2026-08-26T12:00:00Z'));
    $now = spec085Ocsp(spec085Report(SPEC085_OPENAI));
    $without = spec085WithoutCode(...);

    expect($now[0]->code)->toBe(StatusCode::SigningCredentialOcspSkipped)
        ->and($now[0]->response)->toBe($then[0]->response)
        ->and($without($now))->toBe($without($then));
})->group('SPEC-085');

it('AC3: chosen, not chosen and unusable in one manifest', function (): void {
    $assertion = 'self#jumbf=/c2pa/urn:uuid:b82c36da-6dc9-418d-bd2c-9587009f797f/c2pa.assertions/c2pa.certificate-status';
    $ocsp = spec085Ocsp(spec085Report('c2pa-rs/ocsp_with_assertion.jpg'), 'adobe:urn:uuid:8208d145-e90c-43f6-b9a4-58bd7ba87f80');

    expect(array_map(spec085Entry(...), $ocsp))->toBe([
        [OcspEvidence::RVALS, 'self#jumbf=/c2pa/adobe:urn:uuid:8208d145-e90c-43f6-b9a4-58bd7ba87f80/c2pa.signature', 'd0a065257508f05f7a1827632837914a7288becd220524d7eec1cdb361108663', OcspEvidence::CHOSEN, 'signingCredential.ocsp.skipped', 'good', spec085Epoch('2025-07-28T21:45:30Z'), spec085Epoch('2025-07-28T21:45:30Z'), spec085Epoch('2025-08-04T21:45:30Z')],
        [OcspEvidence::ASSERTION, $assertion, 'ed5c71951b4dc996bab7f86678834db5bc4f396e7489c1033151308d85527d5e', OcspEvidence::UNUSABLE, null, null, null, null, null],
        [OcspEvidence::ASSERTION, $assertion, '29424eae5ddda0039395b027ea24bf6c16c5f00204ce34545ebb2ff49399098c', OcspEvidence::NOT_CHOSEN, null, 'good', spec085Epoch('2025-07-16T21:41:26Z'), spec085Epoch('2025-07-16T21:41:26Z'), spec085Epoch('2025-07-23T21:41:26Z')],
    ]);
})->group('SPEC-085');

it('AC4: a revocation in an assertion, for the ingredient and not for the active signer', function (): void {
    $report = spec085Report('revocation/cs-two.png', 'revocation/throw-away-root.settings.json');
    $shape = static fn (string $label): array => array_map(static fn (array $entry): array => array_slice($entry, 0, 4), spec085Shape(spec085Ocsp($report, $label)));

    expect($shape('urn:c2pa:f0970afc-efb2-4e94-a5b7-e3a8d2f03901'))->toBe([
        ['c9befada48a87d0f8cfbe6af2ee2c16a923c8071b98de04012fd0c7872697e46', OcspEvidence::UNUSABLE, null, null],
        ['533def490912bf43b8915314153bd325cc9cf7ef438eb68d7740d09a2e05ef84', OcspEvidence::CHOSEN, 'signingCredential.ocsp.revoked', 'revoked'],
    ])
        ->and($shape($report->store->active->label ?? ''))->toBe([
            ['c9befada48a87d0f8cfbe6af2ee2c16a923c8071b98de04012fd0c7872697e46', OcspEvidence::UNUSABLE, null, null],
            ['533def490912bf43b8915314153bd325cc9cf7ef438eb68d7740d09a2e05ef84', OcspEvidence::UNUSABLE, null, null],
        ]);
})->group('SPEC-085');

it('AC5: a revoked answer stops the check, and wins over an earlier good one', function (): void {
    $shape = spec085Shape(...);
    $good = hash('sha256', spec085Der('good'));
    $revoked = hash('sha256', spec085Der('revoked'));

    expect($shape(spec085Stapled([spec085Der('revoked'), spec085Der('good')])))->toBe([
        [$revoked, OcspEvidence::CHOSEN, 'signingCredential.ocsp.revoked', 'revoked', false],
        [$good, OcspEvidence::NOT_TRIED, null, null, true],
    ])
        ->and($shape(spec085Stapled([spec085Der('good'), spec085Der('revoked')])))->toBe([
            [$good, OcspEvidence::NOT_CHOSEN, null, 'good', false],
            [$revoked, OcspEvidence::CHOSEN, 'signingCredential.ocsp.revoked', 'revoked', false],
        ]);
})->group('SPEC-085');

it('AC6: nothing usable, and nothing thrown', function (string $case): void {
    // amendment 2: without the issuer in x5chain no response is read, so none is unusable
    [$ders, $chain, $outcome] = match ($case) {
        'another certificate' => [[spec085Der('other-good')], null, OcspEvidence::UNUSABLE],
        'not DER' => [["\xff\x00not an OCSP response"], null, OcspEvidence::UNUSABLE],
        'no issuer in the chain' => [[spec085Der('good')], [spec085Chain()[0]], OcspEvidence::NOT_TRIED],
        default => throw new LogicException("no case {$case}"),
    };
    $evidence = spec085Stapled($ders, $chain);

    expect(count($evidence))->toBe(1)
        ->and($evidence[0]->response)->toBe($ders[0])
        ->and([$evidence[0]->outcome, $evidence[0]->code, $evidence[0]->certStatus, $evidence[0]->producedAt, $evidence[0]->thisUpdate, $evidence[0]->nextUpdate])
        ->toBe([$outcome, null, null, null, null, null]);
})->with(['another certificate', 'not DER', 'no issuer in the chain'])->group('SPEC-085');

it('AC7: nothing to list', function (): void {
    [, $listed] = (new OcspCheck)->checkWithEvidence(['rVals' => [new CborBytes(spec085Der('good'))]], spec085Chain(), null, 'self#jumbf=/c2pa/test/c2pa.signature');

    expect(spec085Ocsp(spec085Report('timestamp/tsa-matrix/control.png', 'timestamp/tsa-matrix/control.settings.json')))->toBe([])
        ->and($listed)->toBe([]);
})->group('SPEC-085');

/**
 * Where the responses of one manifest disagree with each other and, for the active manifest, with the report (AC8).
 *
 * @return list<string>
 */
function spec085Disagreements(VerificationReport $report, string $label, ManifestEvidence $evidence): array
{
    $found = [];
    $outcomes = array_map(static fn (OcspEvidence $e): string => $e->outcome, $evidence->ocsp);
    $chosen = array_keys($outcomes, OcspEvidence::CHOSEN, true);
    $usable = array_filter($outcomes, static fn (string $o): bool => in_array($o, [OcspEvidence::CHOSEN, OcspEvidence::NOT_CHOSEN], true));
    if (count($chosen) > 1 || ($chosen === []) !== ($usable === [])) {
        $found[] = sprintf('%s: %d chosen, %d usable', $label, count($chosen), count($usable));
    }
    foreach ($evidence->ocsp as $i => $e) {
        $read = in_array($e->outcome, [OcspEvidence::CHOSEN, OcspEvidence::NOT_CHOSEN], true);
        if (($e->certStatus !== null) !== $read || ($e->producedAt !== null) !== $read || ($e->thisUpdate !== null) !== $read) {
            $found[] = "{$label}: entry {$i} is {$e->outcome}, its status and times do not follow";
        }
        if (($e->code !== null) !== ($e->outcome === OcspEvidence::CHOSEN)) {
            $found[] = "{$label}: entry {$i} is {$e->outcome} with a code of ".($e->code->value ?? 'none');
        }
        // amendment 2: without the issuer in x5chain every stored entry is not tried
        if ($e->source !== OcspEvidence::FETCHED && count($evidence->certificates) < 2) {
            if ($e->outcome !== OcspEvidence::NOT_TRIED) {
                $found[] = "{$label}: entry {$i} is {$e->outcome} with x5chain holding ".count($evidence->certificates).' certificate(s)';
            }
        } elseif ($e->outcome === OcspEvidence::NOT_TRIED && ($chosen === [] || $chosen[0] > $i || $evidence->ocsp[$chosen[0]]->certStatus !== 'revoked')) {
            $found[] = "{$label}: entry {$i} not tried without a revoked one before it";
        }
    }
    if ($label !== ($report->store->active->label ?? '') || $evidence->ocsp === []) {
        return $found;
    }
    $codes = [];
    foreach ($report->result->statuses as $status) {
        if ($status->ingredientUri === null && $status->url === "self#jumbf=/c2pa/{$label}/c2pa.signature" && str_starts_with($status->code->value, 'signingCredential.ocsp.')) {
            $codes[] = $status->code->value;
        }
    }
    $expected = $chosen === [] ? 'signingCredential.ocsp.skipped' : ($evidence->ocsp[$chosen[0]]->code->value ?? '');
    if ($codes !== [$expected]) {
        $found[] = sprintf('%s: the evidence says %s, the report %s', $label, $expected, implode(',', $codes));
    }

    return $found;
}

it('AC8: over the corpus, the responses agree with the verdict and the statuses', function (string $settings): void {
    $root = Corpus::fixtures();
    $l = "{$root}/trust/conformance-public";
    $trust = match ($settings) {
        'none' => null,
        'full' => TrustSettings::fromJson((string) file_get_contents("{$root}/trust/full.settings.json")),
        default => TrustSettings::fromTrustLists((string) file_get_contents("{$l}/C2PA-TRUST-LIST.json"), (string) file_get_contents("{$l}/C2PA-TSA-TRUST-LIST.json")),
    };
    $disagreements = [];
    $outcomes = [OcspEvidence::CHOSEN => 0, OcspEvidence::NOT_CHOSEN => 0, OcspEvidence::UNUSABLE => 0, OcspEvidence::NOT_TRIED => 0];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || preg_match('/\.(json|pem|md|txt|cfg|php|key|der|crt)$/i', $file->getFilename()) === 1) {
            continue;
        }
        $stream = fopen($file->getPathname(), 'rb');
        if ($stream === false) {
            throw new RuntimeException("cannot open {$file->getPathname()}");
        }
        $report = (new Verifier)->verify($stream, $trust);
        fclose($stream);
        foreach ($report->evidence() as $label => $evidence) {
            foreach (spec085Disagreements($report, $label, $evidence) as $d) {
                $disagreements[] = substr($file->getPathname(), strlen($root) + 1).": {$d}";
            }
            foreach ($evidence->ocsp as $e) {
                $outcomes[$e->outcome]++;
            }
        }
    }

    expect($disagreements)->toBe([])
        ->and($outcomes[OcspEvidence::CHOSEN])->toBeGreaterThan(10)
        ->and($outcomes[OcspEvidence::UNUSABLE])->toBeGreaterThan(0)
        ->and($outcomes[OcspEvidence::NOT_CHOSEN])->toBeGreaterThan(0);
})->with(['none', 'full', 'lists'])->group('SPEC-085');

it('AC9: the reports carry no response bytes', function (): void {
    // the corpus run before and after is in the step's note; here the shapes are pinned on the OpenAI file
    $report = spec085Report(SPEC085_OPENAI, null, '2026-08-26T12:00:00Z');
    $response = base64_encode(spec085Ocsp($report)[0]->response ?? '');

    expect($response)->not->toBe('')
        ->and($report->toJson())->not->toContain($response)
        ->and($report->toCrJson())->not->toContain($response);
})->group('SPEC-085');

it('AC10: OcspEvidence and ocsp are in the contract', function (): void {
    expect((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/api/public-surface.txt'))
        ->toContain('Trust\OcspEvidence :: method __construct')
        ->toContain('Verifier\ManifestEvidence :: property ocsp');
})->group('SPEC-085');

it('scope: an assertion response over the size limit is listed, unusable', function (): void {
    // no corpus file has one; a stapled one over the limit refuses the whole rVals, an assertion's is skipped alone
    $url = 'self#jumbf=/c2pa/test/c2pa.assertions/c2pa.certificate-status';
    $large = str_repeat("\x00", OcspCheck::DEFAULT_MAX_RESPONSE_BYTES + 1);
    [, $evidence] = (new OcspCheck)->checkWithEvidence([], spec085Chain(), null, 'self#jumbf=/c2pa/test/c2pa.signature', [[$large, $url], [spec085Der('good'), $url]]);

    expect(spec085Shape($evidence))->toBe([
        [hash('sha256', $large), OcspEvidence::UNUSABLE, null, null, true],
        [hash('sha256', spec085Der('good')), OcspEvidence::CHOSEN, 'signingCredential.ocsp.notRevoked', 'good', false],
    ])
        ->and($evidence[0]->source)->toBe(OcspEvidence::ASSERTION);
})->group('SPEC-085');
