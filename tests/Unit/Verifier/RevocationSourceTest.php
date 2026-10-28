<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\Certificate;
use Provemark\C2paVerifier\Trust\NoRevocationSource;
use Provemark\C2paVerifier\Trust\OcspCheck;
use Provemark\C2paVerifier\Trust\OcspEvidence;
use Provemark\C2paVerifier\Trust\RevocationSource;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-086: revocation data from a source the caller injects, for the
 * signer (ADR-0007). The probes, responses and the Google AIA url were
 * measured on 2026-10-27 over the verifier at c0eaf42.
 */

const SPEC086_PARENT = 'revocation/cs-parent.png';
const SPEC086_P = 'urn:c2pa:f0970afc-efb2-4e94-a5b7-e3a8d2f03901';
const SPEC086_P_GOOD = '427b01dc';
const SPEC086_P_REVOKED = 'd39d48fa';
const SPEC086_U_REVOKED = '3490ee03';
const SPEC086_GOOGLE = 'writers/google-20250919-pixel10-npld-picnic-table.jpg';
const SPEC086_BING = 'writers/microsoft-20260609-bing-fast-heartbeat.jpg';

/** The calls a test source received (a plain class, so the file loads before RevocationSource exists). */
final class Spec086Calls
{
    /** @var list<array{0: string, 1: string, 2: list<string>}> */
    public array $calls = [];
}

/** A source that records each call in $calls and gives a fixed answer, or throws. */
function spec086Source(Spec086Calls $calls, ?string $answer = null, ?Throwable $throw = null): RevocationSource
{
    return new class($calls, $answer, $throw) implements RevocationSource
    {
        public function __construct(private Spec086Calls $calls, private ?string $answer, private ?Throwable $throw) {}

        public function ocspResponse(string $subject, string $issuer, array $urls): ?string
        {
            $this->calls->calls[] = [$subject, $issuer, $urls];
            if ($this->throw !== null) {
                throw $this->throw;
            }

            return $this->answer;
        }
    };
}

function spec086Settings(string $which): ?TrustSettings
{
    $root = Corpus::fixtures();
    $l = "{$root}/trust/conformance-public";

    return match ($which) {
        'none' => null,
        'throwaway' => TrustSettings::fromJson((string) file_get_contents("{$root}/revocation/throw-away-root.settings.json")),
        'full' => TrustSettings::fromJson((string) file_get_contents("{$root}/trust/full.settings.json")),
        default => TrustSettings::fromTrustLists((string) file_get_contents("{$l}/C2PA-TRUST-LIST.json"), (string) file_get_contents("{$l}/C2PA-TSA-TRUST-LIST.json")),
    };
}

function spec086Verify(string $path, ?TrustSettings $settings, ?RevocationSource $source = null): VerificationReport
{
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$path}");
    }
    try {
        return ($source === null ? new Verifier : new Verifier(revocationSource: $source))->verify($stream, $settings);
    } finally {
        fclose($stream);
    }
}

/** The response whose SHA-256 starts with $prefix, from the evidence of a probe that carries it (SPEC-085). */
function spec086Response(string $file, string $prefix): string
{
    foreach (spec086Verify(Corpus::fixtures()."/{$file}", spec086Settings('throwaway'))->evidence() as $evidence) {
        foreach ($evidence->ocsp as $entry) {
            if (str_starts_with(hash('sha256', $entry->response), $prefix)) {
                return $entry->response;
            }
        }
    }
    throw new RuntimeException("no response {$prefix} in {$file}");
}

/** @return list<string> the signingCredential.ocsp.* codes of this manifest's signature, in any scope */
function spec086Codes(VerificationReport $report, string $label): array
{
    $codes = [];
    foreach ($report->result->statuses as $status) {
        if ($status->url === "self#jumbf=/c2pa/{$label}/c2pa.signature" && str_starts_with($status->code->value, 'signingCredential.ocsp.')) {
            $codes[] = $status->code->value;
        }
    }

    return $codes;
}

function spec086Explanation(VerificationReport $report, string $label, string $code): string
{
    foreach ($report->result->statuses as $status) {
        if ($status->url === "self#jumbf=/c2pa/{$label}/c2pa.signature" && $status->code->value === $code) {
            return $status->explanation;
        }
    }

    return '';
}

/** @return list<array{string, string, string, ?string}> source, outcome, response hash prefix, status */
function spec086Fetched(VerificationReport $report, string $label): array
{
    expect($report->evidence())->toHaveKey($label);

    return array_values(array_map(
        static fn (OcspEvidence $e): array => [$e->source, $e->outcome, substr(hash('sha256', $e->response), 0, 8), $e->certStatus],
        array_filter($report->evidence()[$label]->ocsp, static fn (OcspEvidence $e): bool => $e->source === 'fetched'),
    ));
}

function spec086State(VerificationReport $report): string
{
    $state = $report->toArray()['validation_state'] ?? '';

    return is_string($state) ? $state : '';
}

/**
 * The calls about a judged manifest's signer (amendment 1): since SPEC-087 the source is first asked about the CAs
 * of each path, and those calls are SPEC-087's to count.
 *
 * @return list<array{0: string, 1: string, 2: list<string>}>
 */
function spec086SignerCalls(VerificationReport $report, Spec086Calls $calls): array
{
    $signers = [];
    foreach ($report->evidence() as $evidence) {
        if (isset($evidence->certificates[0])) {
            $signers[$evidence->certificates[0]] = true;
        }
    }

    return array_values(array_filter($calls->calls, static fn (array $call): bool => isset($signers[$call[0]])));
}

function spec086Parent(?RevocationSource $source, string $settings = 'throwaway'): VerificationReport
{
    return spec086Verify(Corpus::fixtures().'/'.SPEC086_PARENT, spec086Settings($settings), $source);
}

it('AC1: a fetched good', function (): void {
    $calls = new Spec086Calls;
    $report = spec086Parent(spec086Source($calls, spec086Response('revocation/cs-good.png', SPEC086_P_GOOD)));
    $chain = $report->evidence()[SPEC086_P]->certificates;

    expect(spec086SignerCalls($report, $calls))->toBe([[$chain[0], $chain[1], []]])
        ->and(spec086Codes($report, SPEC086_P))->toBe(['signingCredential.ocsp.notRevoked'])
        ->and(spec086Explanation($report, SPEC086_P, 'signingCredential.ocsp.notRevoked'))->toContain('fetched')
        ->and(spec086State($report))->toBe('Trusted')
        ->and(spec086Fetched($report, SPEC086_P))->toBe([['fetched', 'chosen', SPEC086_P_GOOD, 'good']]);
})->group('SPEC-086');

it('AC2: a fetched revoked', function (): void {
    $report = spec086Parent(spec086Source(new Spec086Calls, spec086Response('revocation/cs-revoked.png', SPEC086_P_REVOKED)));

    expect(spec086Codes($report, SPEC086_P))->toBe(['signingCredential.ocsp.revoked'])
        ->and(spec086Explanation($report, SPEC086_P, 'signingCredential.ocsp.revoked'))->toContain('fetched')
        ->and(spec086State($report))->toBe('Invalid')
        ->and(spec086Fetched($report, SPEC086_P))->toBe([['fetched', 'chosen', SPEC086_P_REVOKED, 'revoked']]);
})->group('SPEC-086');

it('AC3: an answer about another certificate is inaccessible', function (): void {
    $report = spec086Parent(spec086Source(new Spec086Calls, spec086Response('revocation/cs-own.png', SPEC086_U_REVOKED)));

    expect(spec086Codes($report, SPEC086_P))->toBe(['signingCredential.ocsp.inaccessible'])
        ->and(spec086Explanation($report, SPEC086_P, 'signingCredential.ocsp.inaccessible'))->not->toBe('')
        ->and(spec086State($report))->toBe('Trusted')
        ->and(spec086Fetched($report, SPEC086_P))->toBe([['fetched', 'unusable', SPEC086_U_REVOKED, null]]);
})->group('SPEC-086');

it('AC4: no answer is inaccessible, and nothing escapes', function (?string $answer, ?Throwable $throw, int $entries): void {
    $calls = new Spec086Calls;
    $report = spec086Parent(spec086Source($calls, $answer, $throw));

    expect(spec086SignerCalls($report, $calls))->toHaveCount(1)
        ->and(spec086Codes($report, SPEC086_P))->toBe(['signingCredential.ocsp.inaccessible'])
        ->and(spec086Explanation($report, SPEC086_P, 'signingCredential.ocsp.inaccessible'))->toContain('source')
        ->and(spec086State($report))->toBe('Trusted')
        ->and(spec086Fetched($report, SPEC086_P))->toHaveCount($entries);
})->with([
    'null' => [null, null, 0],
    'an exception' => fn (): array => [null, new RuntimeException('offline'), 0],
    'an error' => fn (): array => [null, new Error('broken'), 0],
    'not DER' => ["\x01\x02not der", null, 1],
])->group('SPEC-086');

it('AC5: not asked where a stored response proves something', function (string $file, string $asked): void {
    $calls = new Spec086Calls;
    $report = spec086Verify(Corpus::fixtures()."/{$file}", spec086Settings('throwaway'), spec086Source($calls));
    $label = $asked === 'active' ? ($report->store->active->label ?? '') : SPEC086_P;

    $signerCalls = spec086SignerCalls($report, $calls);

    expect($signerCalls)->toHaveCount(1)
        ->and($signerCalls[0][0] ?? null)->toBe($report->evidence()[$label]->certificates[0]);
})->with([
    'cs-good: U, not P' => ['revocation/cs-good.png', 'active'],
    'cs-own: P, not U' => ['revocation/cs-own.png', 'ingredient'],
])->group('SPEC-086');

it('AC6: not asked for an untrusted signer', function (string $file, string $settings): void {
    $calls = new Spec086Calls;
    $with = spec086Verify(Corpus::fixtures()."/{$file}", spec086Settings($settings), spec086Source($calls, spec086Response('revocation/cs-good.png', SPEC086_P_GOOD)));
    $without = spec086Verify(Corpus::fixtures()."/{$file}", spec086Settings($settings));

    expect($calls->calls)->toBe([])
        ->and($with->toJson())->toBe($without->toJson());
})->with([
    'cs-parent without settings' => [SPEC086_PARENT, 'none'],
    'Bing under the lists' => [SPEC086_BING, 'lists'],
])->group('SPEC-086');

it('AC7: the urls from the AIA', function (): void {
    $calls = new Spec086Calls;
    $report = spec086Verify(Corpus::fixtures().'/'.SPEC086_GOOGLE, spec086Settings('lists'), spec086Source($calls));
    $label = $report->store->active->label ?? '';

    $signerCalls = spec086SignerCalls($report, $calls);

    expect($signerCalls)->toHaveCount(1)
        ->and($signerCalls[0][2] ?? null)->toBe(['http://c2pa-ocsp.pki.goog/'])
        ->and(spec086Codes($report, $label))->toBe(['signingCredential.ocsp.inaccessible']);
})->group('SPEC-086');

it('AC8: urls that are not kept', function (string $file, array $urls): void {
    $der = (string) file_get_contents(Corpus::fixtures()."/{$file}");
    if (str_starts_with($der, '-----BEGIN')) {
        $der = (string) base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $der) ?? '', true);
    }

    expect(Certificate::fromDer($der)->x509->ocspUrls())->toBe($urls);
})->with([
    'ldap, caIssuers, five, too long' => ['aia/filter.crt', ['http://ocsp1.example/', 'HTTPS://ocsp2.example/', 'http://ocsp3.example/', 'http://ocsp4.example/']],
    'an AIA that is not DER' => ['aia/broken.crt', []],
    // amendment 4: CR LF, a final LF, NUL, a space, a tab, DEL and a non-ASCII byte are not in a URI
    'control bytes' => ['aia/control.crt', ['http://ocsp-plain.example/']],
    'no AIA' => ['ocsp/signer.crt', []],
])->group('SPEC-086');

it('AC9: the default source changes nothing', function (string $file, string $settings): void {
    $path = Corpus::fixtures()."/{$file}";

    expect((new NoRevocationSource)->ocspResponse('', '', []))->toBeNull()
        ->and(spec086Verify($path, spec086Settings($settings), new NoRevocationSource)->toJson())
        ->toBe(spec086Verify($path, spec086Settings($settings))->toJson());
})->with([
    'cs-parent' => [SPEC086_PARENT, 'throwaway'],
    'cs-good' => ['revocation/cs-good.png', 'throwaway'],
    'Google' => [SPEC086_GOOGLE, 'lists'],
])->group('SPEC-086');

/**
 * Where the calls of one run disagree with the manifests that may be asked (AC10): a trusted signer with its issuer
 * in x5chain whose stored responses proved nothing, read from the run without a source. Trusted is the report's
 * signingCredential.trusted; where the report holds no trust status for a manifest (an ingredient whose statuses the
 * store recorded, SPEC-021), the evidence's anchor, which agrees with it wherever both are present. The calls counted
 * are those about a signer (amendment 1); SPEC-087 counts those about the CAs.
 *
 * @return list<string>
 */
function spec086Disagreements(VerificationReport $without, VerificationReport $with, Spec086Calls $calls): array
{
    $found = [];
    $expected = [];
    $eligible = [];
    foreach ($without->evidence() as $label => $evidence) {
        $codes = [];
        foreach ($without->result->statuses as $status) {
            if ($status->url === "self#jumbf=/c2pa/{$label}/c2pa.signature" && in_array($status->code->value, ['signingCredential.trusted', 'signingCredential.untrusted'], true)) {
                $codes[] = $status->code->value;
            }
        }
        $trusted = $codes === [] ? $evidence->signer !== null : in_array('signingCredential.trusted', $codes, true);
        if ($trusted !== ($evidence->signer !== null)) {
            $found[] = "{$label}: trusted and the evidence's anchor disagree";
        }
        $proved = false;
        foreach ($evidence->ocsp as $entry) {
            $proved = $proved || ($entry->outcome === OcspEvidence::CHOSEN && $entry->code?->value !== 'signingCredential.ocsp.skipped');
        }
        if ($trusted && ! $proved && count($evidence->certificates) >= 2) {
            $expected[] = hash('sha256', $evidence->certificates[0]);
            $eligible[$label] = true;
        }
    }
    $called = array_map(static fn (array $call): string => hash('sha256', $call[0]), spec086SignerCalls($without, $calls));
    sort($expected);
    sort($called);
    if ($called !== $expected) {
        $found[] = sprintf('%d calls, %d manifests that may be asked', count($called), count($expected));
    }
    foreach ($with->result->statuses as $status) {
        if ($status->code->value === 'signingCredential.ocsp.inaccessible'
            && (preg_match('#^self\#jumbf=/c2pa/([^/]+)/c2pa\.signature$#', $status->url, $m) !== 1 || ! isset($eligible[$m[1]]))) {
            $found[] = "inaccessible on {$status->url}, which may not be asked";
        }
    }

    return $found;
}

it('AC10: over the corpus, the source is asked exactly where allowed', function (string $settings): void {
    $root = Corpus::fixtures();
    $trust = spec086Settings($settings);
    $disagreements = [];
    $calls = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || preg_match('/\.(json|pem|md|txt|cfg|php|key|der|crt)$/i', $file->getFilename()) === 1) {
            continue;
        }
        $recorded = new Spec086Calls;
        $with = spec086Verify($file->getPathname(), $trust, spec086Source($recorded));
        $without = spec086Verify($file->getPathname(), $trust);
        $calls += count($recorded->calls);
        foreach (spec086Disagreements($without, $with, $recorded) as $d) {
            $disagreements[] = substr($file->getPathname(), strlen($root) + 1).": {$d}";
        }
    }

    expect($disagreements)->toBe([])
        ->and($calls)->toBeGreaterThan($settings === 'none' ? -1 : 0);
})->with(['none', 'full', 'lists'])->group('SPEC-086');

/** @return list<Certificate> the signer and issuer of tests/Fixtures/ocsp/ (its own, not SPEC-085's: a parallel worker loads only some files) */
function spec086OcspChain(): array
{
    $read = static fn (string $name): Certificate => Certificate::fromDer(base64_decode(preg_replace('/-----[^-]+-----|\s/', '', (string) file_get_contents(Corpus::fixtures()."/ocsp/{$name}.crt")) ?? '', true) ?: '');

    return [$read('signer'), $read('ca')];
}

// Scope lines no corpus file shows, written after the build when mutations M5, M8 and M16 survived.

it('scope: a fetched response follows the stored ones in the evidence', function (): void {
    $calls = new Spec086Calls;
    $report = spec086Verify(Corpus::fixtures().'/revocation/cs-good.png', spec086Settings('throwaway'), spec086Source($calls, spec086Response('revocation/cs-own.png', SPEC086_U_REVOKED)));
    $label = $report->store->active->label ?? '';
    $entries = array_map(static fn (OcspEvidence $e): array => [$e->source, $e->outcome, substr(hash('sha256', $e->response), 0, 8)], $report->evidence()[$label]->ocsp);

    expect($entries)->toBe([['assertion', 'unusable', SPEC086_P_GOOD], ['fetched', 'chosen', SPEC086_U_REVOKED]])
        ->and(spec086Codes($report, $label))->toBe(['signingCredential.ocsp.revoked']);
})->group('SPEC-086');

// amendment 2: what counts is the issuer the chain walk verified, passed in; none is passed here
it('scope: the source is not asked without an issuer the walk verified', function (): void {
    $calls = new Spec086Calls;
    [$statuses, $evidence] = (new OcspCheck)->checkWithEvidence([], [spec086OcspChain()[0]], null, 'self#jumbf=/c2pa/test/c2pa.signature', [], spec086Source($calls));

    expect($calls->calls)->toBe([])
        ->and($statuses[0]->code->value)->toBe('signingCredential.ocsp.skipped')
        ->and($evidence)->toBe([]);
})->group('SPEC-086');

it('scope: a fetched response over the size limit is not read', function (): void {
    $calls = new Spec086Calls;
    [$statuses, $evidence] = (new OcspCheck)->checkWithEvidence([], spec086OcspChain(), null, 'self#jumbf=/c2pa/test/c2pa.signature', [], spec086Source($calls, str_repeat("\x30", OcspCheck::DEFAULT_MAX_RESPONSE_BYTES + 1)), spec086OcspChain()[1]);

    expect($statuses[0]->code->value)->toBe('signingCredential.ocsp.inaccessible')
        ->and($statuses[0]->explanation)->toContain('over the limit')
        ->and(array_map(static fn (OcspEvidence $e): string => $e->outcome, $evidence))->toBe(['unusable']);
})->group('SPEC-086');

it('amendment 5: no source has one spelling', function (): void {
    $parameter = array_values(array_filter((new ReflectionMethod(Verifier::class, '__construct'))->getParameters(), static fn (ReflectionParameter $p): bool => $p->getName() === 'revocationSource'))[0] ?? throw new RuntimeException('no revocationSource parameter');
    $type = $parameter->getType();

    expect($type instanceof ReflectionNamedType ? $type->getName() : null)->toBe(RevocationSource::class)
        ->and($type?->allowsNull())->toBeFalse()
        ->and($parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null)->toBeInstanceOf(NoRevocationSource::class)
        // null on purpose, through reflection, as a caller without static analysis would pass it
        ->and(static fn (): object => (new ReflectionClass(Verifier::class))->newInstanceArgs(['revocationSource' => null]))->toThrow(TypeError::class);
})->group('SPEC-086');

it('amendment 5: a source of the caller\'s that answers null was asked; NoRevocationSource never is', function (): void {
    $calls = new Spec086Calls;
    $asked = spec086Parent(spec086Source($calls));
    $default = spec086Parent(new NoRevocationSource);

    expect(spec086SignerCalls($asked, $calls))->toHaveCount(1)
        ->and(spec086Codes($asked, SPEC086_P))->toBe(['signingCredential.ocsp.inaccessible'])
        ->and(spec086Codes($default, SPEC086_P))->toBe(['signingCredential.ocsp.skipped'])
        ->and($default->toJson())->toBe(spec086Parent(null)->toJson());
})->group('SPEC-086');
