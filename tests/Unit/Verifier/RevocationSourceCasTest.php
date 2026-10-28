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
 * SPEC-087: the revocation source asked about the CAs of the path
 * (ADR-0007). The probes, responses and urls were measured on 2026-10-27
 * over the verifier at bc3aef5.
 */

const SPEC087_CONTROL = 'revocation/ca-control.png';
const SPEC087_CA_REVOKED = 'c457d65b';
const SPEC087_CA_GOOD = '7e48060b';
const SPEC087_CA_REMOVED = '26fd05fb';
const SPEC087_P_GOOD = '427b01dc';
const SPEC087_OPENAI = 'writers/openai-20260826-c2pa_2x.png';
const SPEC087_BING = 'writers/microsoft-20260609-bing-fast-heartbeat.jpg';

/** The calls a test source received, subject and issuer DER and urls (a plain class, so the file always loads). */
final class Spec087Calls
{
    /** @var list<array{0: string, 1: string, 2: list<string>}> */
    public array $calls = [];

    /** @return list<string> the subjects asked about, as SHA-256 */
    public function subjects(): array
    {
        return array_map(static fn (array $call): string => hash('sha256', $call[0]), $this->calls);
    }
}

/**
 * A source that answers per subject (keyed by the subject's SHA-256), null otherwise, and throws for $throwFor.
 *
 * @param  array<string, string>  $answers
 */
function spec087Source(Spec087Calls $calls, array $answers = [], ?string $throwFor = null): RevocationSource
{
    return new class($calls, $answers, $throwFor) implements RevocationSource
    {
        /** @param  array<string, string>  $answers */
        public function __construct(private Spec087Calls $calls, private array $answers, private ?string $throwFor) {}

        public function ocspResponse(string $subject, string $issuer, array $urls): ?string
        {
            $this->calls->calls[] = [$subject, $issuer, $urls];
            if (hash('sha256', $subject) === $this->throwFor) {
                throw new RuntimeException('offline');
            }

            return $this->answers[hash('sha256', $subject)] ?? null;
        }
    };
}

function spec087Settings(string $which): ?TrustSettings
{
    $root = Corpus::fixtures();
    $l = "{$root}/trust/conformance-public";

    return match ($which) {
        'none' => null,
        'revocation', 'anchor-in-chain' => TrustSettings::fromJson((string) file_get_contents("{$root}/{$which}/throw-away-root.settings.json")),
        'full' => TrustSettings::fromJson((string) file_get_contents("{$root}/trust/full.settings.json")),
        default => TrustSettings::fromTrustLists((string) file_get_contents("{$l}/C2PA-TRUST-LIST.json"), (string) file_get_contents("{$l}/C2PA-TSA-TRUST-LIST.json")),
    };
}

function spec087Verify(string $file, ?TrustSettings $settings, ?RevocationSource $source = null, ?string $at = null): VerificationReport
{
    $path = str_starts_with($file, '/') ? $file : Corpus::fixtures()."/{$file}";
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$path}");
    }
    try {
        return ($source === null ? new Verifier : new Verifier(revocationSource: $source))->verify($stream, $settings, $at === null ? null : new DateTimeImmutable($at));
    } finally {
        fclose($stream);
    }
}

/** The response whose SHA-256 starts with $prefix, from the evidence of a probe that staples or holds it (SPEC-085). */
function spec087Response(string $file, string $prefix): string
{
    foreach (spec087Verify($file, spec087Settings('revocation'))->evidence() as $evidence) {
        foreach ($evidence->ocsp as $entry) {
            if (str_starts_with(hash('sha256', $entry->response), $prefix)) {
                return $entry->response;
            }
        }
    }
    throw new RuntimeException("no response {$prefix} in {$file}");
}

/** @return list<string> the active manifest's x5chain, leaf first, as DER */
function spec087Chain(VerificationReport $report): array
{
    return $report->evidence()[$report->store->active->label ?? '']->certificates;
}

/** @return list<string> the signingCredential.* codes of this manifest's signature */
function spec087Codes(VerificationReport $report, ?string $label = null): array
{
    $label ??= $report->store->active->label ?? '';
    $codes = [];
    foreach ($report->result->statuses as $status) {
        if ($status->url === "self#jumbf=/c2pa/{$label}/c2pa.signature" && str_starts_with($status->code->value, 'signingCredential.')) {
            $codes[] = $status->code->value;
        }
    }

    return $codes;
}

function spec087Explanation(VerificationReport $report, string $code): string
{
    $label = $report->store->active->label ?? '';
    foreach ($report->result->statuses as $status) {
        if ($status->url === "self#jumbf=/c2pa/{$label}/c2pa.signature" && $status->code->value === $code) {
            return $status->explanation;
        }
    }

    return '';
}

function spec087State(VerificationReport $report): string
{
    $state = $report->toArray()['validation_state'] ?? '';

    return is_string($state) ? $state : '';
}

/** The intermediate of ca-control.png, as DER: the CA every revocation/ probe shares. */
function spec087Intermediate(): string
{
    return spec087Chain(spec087Verify(SPEC087_CONTROL, spec087Settings('revocation')))[1];
}

it('AC1: a revoked CA', function (): void {
    $calls = new Spec087Calls;
    $intermediate = spec087Intermediate();
    $report = spec087Verify(SPEC087_CONTROL, spec087Settings('revocation'), spec087Source($calls, [hash('sha256', $intermediate) => spec087Response('revocation/ca-revoked.png', SPEC087_CA_REVOKED)]));

    expect($calls->calls)->toHaveCount(1)
        ->and($calls->calls[0][0] ?? null)->toBe($intermediate)
        ->and($calls->calls[0][1] ?? null)->toBe(base64_decode(preg_replace('/-----[^-]+-----|\s/', '', (string) file_get_contents(Corpus::fixtures().'/revocation/throw-away-root.pem')) ?? '', true))
        ->and($calls->calls[0][2] ?? null)->toBe([])
        ->and(spec087Codes($report))->toContain('signingCredential.untrusted')
        ->and(spec087Codes($report))->not->toContain('signingCredential.trusted')
        ->and(spec087Explanation($report, 'signingCredential.untrusted'))->toContain('Throw-away Intermediate (revocation)')
        ->and(spec087Explanation($report, 'signingCredential.untrusted'))->toContain('fetched')
        ->and(spec087State($report))->toBe('Valid');
})->group('SPEC-087');

it('AC2: a good CA, then the signer', function (): void {
    $calls = new Spec087Calls;
    $intermediate = spec087Intermediate();
    $report = spec087Verify(SPEC087_CONTROL, spec087Settings('revocation'), spec087Source($calls, [hash('sha256', $intermediate) => spec087Response('revocation/ca-good.png', SPEC087_CA_GOOD)]));
    $chain = spec087Chain($report);

    expect(array_map(static fn (array $call): string => $call[0], $calls->calls))->toBe([$intermediate, $chain[0]])
        ->and(spec087State($report))->toBe('Trusted')
        ->and(spec087Codes($report))->toBe(['signingCredential.trusted', 'signingCredential.ocsp.inaccessible']);
})->group('SPEC-087');

it('AC3: answers that change nothing', function (string $answer): void {
    $calls = new Spec087Calls;
    $intermediate = hash('sha256', spec087Intermediate());
    $source = match ($answer) {
        'removeFromCRL' => spec087Source($calls, [$intermediate => spec087Response('revocation/ca-removed.png', SPEC087_CA_REMOVED)]),
        'null' => spec087Source($calls),
        'an exception' => spec087Source($calls, [], $intermediate),
        'not DER' => spec087Source($calls, [$intermediate => "\x01\x02not der"]),
        default => spec087Source($calls, [$intermediate => spec087Response('revocation/cs-good.png', SPEC087_P_GOOD)]),
    };
    $report = spec087Verify(SPEC087_CONTROL, spec087Settings('revocation'), $source);

    expect($calls->subjects()[0] ?? null)->toBe($intermediate)
        ->and(spec087State($report))->toBe('Trusted')
        ->and(spec087Codes($report))->toContain('signingCredential.trusted');
})->with(['removeFromCRL', 'null', 'an exception', 'not DER', 'about another certificate'])->group('SPEC-087');

it('AC4: the anchor is not asked about', function (): void {
    $calls = new Spec087Calls;
    $report = spec087Verify('anchor-in-chain/anchor-in-chain.png', spec087Settings('anchor-in-chain'), spec087Source($calls));
    $chain = spec087Chain($report);

    expect(array_map(static fn (array $call): array => [$call[0], $call[1]], $calls->calls))->toBe([[$chain[1], $chain[2]], [$chain[0], $chain[1]]]);
})->group('SPEC-087');

it('AC4: with the intermediate as the anchor, only the signer is asked', function (): void {
    $root = Corpus::fixtures();
    $json = json_decode((string) file_get_contents("{$root}/revocation/throw-away-root.settings.json"), true);
    expect($json)->toBeArray();
    /** @var array{trust: array<string, mixed>} $json */
    $json['trust']['trust_anchors'] = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode(spec087Intermediate()), 64, "\n")."-----END CERTIFICATE-----\n";
    $calls = new Spec087Calls;
    $report = spec087Verify(SPEC087_CONTROL, TrustSettings::fromJson((string) json_encode($json)), spec087Source($calls));

    expect(spec087State($report))->toBe('Trusted')
        ->and(array_map(static fn (array $call): string => $call[0], $calls->calls))->toBe([spec087Chain($report)[0]]);
})->group('SPEC-087');

it('AC5: asked although the signer\'s staple proves something', function (): void {
    $calls = new Spec087Calls;
    $report = spec087Verify(SPEC087_OPENAI, spec087Settings('lists'), spec087Source($calls), '2026-08-26T12:00:00Z');

    expect($calls->calls)->toHaveCount(1)
        ->and($calls->calls[0][0] ?? null)->toBe(spec087Chain($report)[1])
        ->and($calls->calls[0][2] ?? null)->toBe(['http://ocsp-c2pa.ssl.com'])
        ->and(spec087Codes($report))->toBe(['signingCredential.trusted', 'signingCredential.ocsp.notRevoked'])
        ->and(spec087State($report))->toBe('Trusted');
})->group('SPEC-087');

it('AC6: not asked where a stored response already shows the CA revoked', function (): void {
    $calls = new Spec087Calls;
    $with = spec087Verify('revocation/ca-revoked.png', spec087Settings('revocation'), spec087Source($calls));
    $without = spec087Verify('revocation/ca-revoked.png', spec087Settings('revocation'));

    expect($calls->calls)->toBe([])
        ->and(spec087Codes($with))->toContain('signingCredential.untrusted')
        ->and($with->toJson())->toBe($without->toJson());
})->group('SPEC-087');

it('AC7: not asked without an anchor', function (string $file, string $settings): void {
    $calls = new Spec087Calls;
    spec087Verify($file, spec087Settings($settings), spec087Source($calls));

    expect($calls->calls)->toBe([]);
})->with([
    'ca-control without settings' => [SPEC087_CONTROL, 'none'],
    'Bing under the lists' => [SPEC087_BING, 'lists'],
])->group('SPEC-087');

it('AC8: an ingredient\'s CA', function (): void {
    $report = spec087Verify('revocation/cs-good.png', spec087Settings('revocation'), spec087Source(new Spec087Calls, [hash('sha256', spec087Intermediate()) => spec087Response('revocation/ca-revoked.png', SPEC087_CA_REVOKED)]));

    expect(count($report->evidence()))->toBe(2);
    foreach (array_keys($report->evidence()) as $label) {
        expect(spec087Codes($report, $label))->toContain('signingCredential.untrusted')
            ->and(spec087Codes($report, $label))->not->toContain('signingCredential.trusted');
    }
    expect(spec087State($report))->not->toBe('Trusted');
})->group('SPEC-087');

it('AC9: the default source changes nothing', function (string $file, string $settings): void {
    // a fixed validation time: a skipped explanation names the judged time, and two runs may straddle a second
    expect(spec087Verify($file, spec087Settings($settings), new NoRevocationSource, '2026-10-27T12:00:00Z')->toJson())
        ->toBe(spec087Verify($file, spec087Settings($settings), null, '2026-10-27T12:00:00Z')->toJson());
})->with([
    'ca-control' => [SPEC087_CONTROL, 'revocation'],
    'anchor-in-chain' => ['anchor-in-chain/anchor-in-chain.png', 'anchor-in-chain'],
    'OpenAI' => [SPEC087_OPENAI, 'lists'],
])->group('SPEC-087');

/**
 * Where the calls of one run disagree with what may be asked (AC10). A manifest whose chain reached an anchor (its
 * evidence's anchor, which SPEC-086 measured to agree with signingCredential.trusted) has its CAs asked: the
 * certificates of x5chain strictly between the signer and the anchor, which are x5chain[1 .. depth-1] whether the
 * anchor sits in x5chain or issued its last certificate. Its signer is asked as SPEC-086 says.
 *
 * @return list<string>
 */
function spec087Disagreements(VerificationReport $without, VerificationReport $with, Spec087Calls $calls): array
{
    $expected = [];
    foreach ($without->evidence() as $evidence) {
        if ($evidence->signer === null || $evidence->signer->depth < 1) {
            continue;
        }
        for ($i = 1; $i < $evidence->signer->depth; $i++) {
            $expected[] = hash('sha256', $evidence->certificates[$i]);
        }
        $proved = false;
        foreach ($evidence->ocsp as $entry) {
            $proved = $proved || ($entry->outcome === OcspEvidence::CHOSEN && $entry->code?->value !== 'signingCredential.ocsp.skipped');
        }
        if (! $proved && count($evidence->certificates) >= 2) {
            $expected[] = hash('sha256', $evidence->certificates[0]);
        }
    }
    $called = $calls->subjects();
    $expected = array_values(array_unique($expected));   // SPEC-086 amendment 6: once per certificate and issuer in a run
    sort($expected);
    sort($called);
    $found = [];
    if ($called !== $expected) {
        $found[] = sprintf('%d calls, %d expected', count($called), count($expected));
    }
    if (spec087State($with) !== spec087State($without)) {
        $found[] = sprintf('%s with a null source, %s without', spec087State($with), spec087State($without));
    }

    return $found;
}

it('AC10: over the corpus, the source is asked exactly where allowed', function (string $settings): void {
    $root = Corpus::fixtures();
    $trust = spec087Settings($settings);
    $disagreements = [];
    $calls = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || preg_match('/\.(json|pem|md|txt|cfg|php|key|der|crt)$/i', $file->getFilename()) === 1) {
            continue;
        }
        $recorded = new Spec087Calls;
        $with = spec087Verify($file->getPathname(), $trust, spec087Source($recorded));
        $without = spec087Verify($file->getPathname(), $trust);
        $calls += count($recorded->calls);
        foreach (spec087Disagreements($without, $with, $recorded) as $d) {
            $disagreements[] = substr($file->getPathname(), strlen($root) + 1).": {$d}";
        }
    }

    expect($disagreements)->toBe([])
        ->and($calls)->toBeGreaterThan($settings === 'none' ? -1 : 0);
})->with(['none', 'full', 'lists'])->group('SPEC-087');

function spec087Certificate(string $pemOrDer): Certificate
{
    return Certificate::fromDer(str_starts_with($pemOrDer, '-----') ? (base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pemOrDer) ?? '', true) ?: '') : $pemOrDer);
}

// Scope lines no corpus file shows, written after the build when mutations M4, M6 and M11 survived.

it('scope: every CA is asked, from the top down', function (): void {
    $read = static fn (string $name): Certificate => spec087Certificate((string) file_get_contents(Corpus::fixtures()."/{$name}"));
    // any four certificates: the source answers null, so only the order of the questions is read
    $path = [$read('ocsp/ca.crt'), $read('aia/filter.crt'), $read('aia/broken.crt'), $read('ocsp/signer.crt')];
    $calls = new Spec087Calls;

    expect((new OcspCheck)->fetchedCaRevocation(spec087Source($calls), $path, time()))->toBeNull()
        ->and(array_map(static fn (array $call): array => [$call[0], $call[1]], $calls->calls))->toBe([[$path[1]->der, $path[0]->der], [$path[2]->der, $path[1]->der]]);
})->group('SPEC-087');

it('scope: a revocation after the judged time does not count', function (string $at, bool $revoked): void {
    $chain = spec087Chain(spec087Verify(SPEC087_CONTROL, spec087Settings('revocation')));
    $path = [spec087Certificate((string) file_get_contents(Corpus::fixtures().'/revocation/throw-away-root.pem')), spec087Certificate($chain[1]), spec087Certificate($chain[0])];
    $source = spec087Source(new Spec087Calls, [hash('sha256', $chain[1]) => spec087Response('revocation/ca-revoked.png', SPEC087_CA_REVOKED)]);
    $fault = (new OcspCheck)->fetchedCaRevocation($source, $path, (new DateTimeImmutable($at))->getTimestamp());

    expect($fault !== null)->toBe($revoked);
})->with([
    'before the revocation of 2026-09-09T14:26:58Z' => ['2026-09-01T00:00:00Z', false],
    'after it' => ['2026-10-27T12:00:00Z', true],
])->group('SPEC-087');
