<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\RevocationSource;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-086 amendment 2: the revocation source is told which certificate issued the signer as the chain walk
 * verified it, not whatever x5chain carries second. SPEC-087 amendment 1 and SPEC-066 amendment 3: the second walk
 * of a signer whose only EKU is Time Stamping checks the CAs of its path as the first walk does. Found by the review
 * of step 482; the probes are bin/make-revocation-issuer-variants.php's (step 483).
 */

/** The calls a test source received (a plain class, so the file always loads). */
final class RevIssuerCalls
{
    /** @var list<array{0: string, 1: string, 2: list<string>}> */
    public array $calls = [];
}

/** @param  array<string, string>  $answers  subject DER => response DER */
function revIssuerSource(RevIssuerCalls $calls, array $answers = []): RevocationSource
{
    return new class($calls, $answers) implements RevocationSource
    {
        /** @param  array<string, string>  $answers */
        public function __construct(private RevIssuerCalls $calls, private array $answers) {}

        public function ocspResponse(string $subject, string $issuer, array $urls): ?string
        {
            $this->calls->calls[] = [$subject, $issuer, $urls];

            return $this->answers[$subject] ?? null;
        }
    };
}

function revIssuerFile(string $name): string
{
    return Corpus::fixtures()."/revocation-issuer/{$name}";
}

function revIssuerDer(string $pem): string
{
    return base64_decode(preg_replace('/-----[^-]+-----|\s/', '', (string) file_get_contents(revIssuerFile($pem))) ?? '', true) ?: '';
}

/**
 * The settings file named, or root.settings.json with its trust replaced: $anchor as the only legacy anchor, or
 * $allowed as the only allowed-list entry.
 */
function revIssuerSettings(string $file, ?string $anchor = null, ?string $allowed = null): TrustSettings
{
    $json = json_decode((string) file_get_contents(revIssuerFile($file)), true);
    if (! is_array($json) || ! is_array($json['trust'] ?? null)) {
        throw new RuntimeException("no trust in {$file}");
    }
    if ($anchor !== null) {
        $json['trust']['trust_anchors'] = (string) file_get_contents(revIssuerFile($anchor));
    }
    if ($allowed !== null) {
        // an allowed list lives in a "manifest" entry (SPEC-031), which needs anchors: B, which reaches nothing here
        unset($json['trust']['trust_anchors']);
        $json['trust']['anchors'] = [['trust_anchors' => (string) file_get_contents(revIssuerFile('unrelated-b.pem')), 'allowed_list' => (string) file_get_contents(revIssuerFile($allowed)), 'trust_kind' => 'manifest']];
    }

    return TrustSettings::fromJson((string) json_encode($json));
}

function revIssuerVerify(string $png, TrustSettings $settings, ?RevocationSource $source = null): VerificationReport
{
    $stream = fopen(revIssuerFile($png), 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$png}");
    }
    try {
        return ($source === null ? new Verifier : new Verifier(revocationSource: $source))->verify($stream, $settings);
    } finally {
        fclose($stream);
    }
}

/** @return list<string> the signingCredential.* codes */
function revIssuerCodes(VerificationReport $report): array
{
    return array_values(array_map(static fn ($s): string => $s->code->value, array_filter($report->result->statuses, static fn ($s): bool => str_starts_with($s->code->value, 'signingCredential.'))));
}

function revIssuerExplanation(VerificationReport $report, string $code): string
{
    foreach ($report->result->statuses as $status) {
        if ($status->code->value === $code) {
            return $status->explanation;
        }
    }

    return '';
}

function revIssuerState(VerificationReport $report): string
{
    $state = $report->toArray()['validation_state'] ?? '';

    return is_string($state) ? $state : '';
}

// ---- SPEC-086 amendment 2: the issuer the walk verified ----

it('the signer is asked about with the anchor that issued it, whatever x5chain carries second', function (string $png): void {
    $calls = new RevIssuerCalls;
    $leaf = revIssuerDer('signer-l.pem');
    $report = revIssuerVerify($png, revIssuerSettings('root.settings.json'), revIssuerSource($calls, [$leaf => (string) file_get_contents(revIssuerFile('leaf-revoked.der'))]));

    expect(array_map(static fn (array $c): array => [$c[0], $c[1]], $calls->calls))->toBe([[$leaf, revIssuerDer('throw-away-root.pem')]])
        ->and(revIssuerCodes($report))->toContain('signingCredential.ocsp.revoked')
        ->and(revIssuerState($report))->toBe('Invalid');
})->with([
    'R not in x5chain' => ['leaf-alone.png'],
    'an unrelated second certificate' => ['leaf-bogus.png'],
    'R\'s name, another key' => ['leaf-name-twin.png'],
    'R in x5chain (the control)' => ['leaf-root.png'],
])->group('SPEC-086');

it('option b: an allowed-list signer is asked about with x5chain[1] only when that certificate issued it', function (string $png, bool $asked): void {
    $calls = new RevIssuerCalls;
    $leaf = revIssuerDer('signer-l.pem');
    $report = revIssuerVerify($png, revIssuerSettings('root.settings.json', allowed: 'signer-l.pem'), revIssuerSource($calls, [$leaf => (string) file_get_contents(revIssuerFile('leaf-revoked.der'))]));

    expect(array_map(static fn (array $c): array => [$c[0], $c[1]], $calls->calls))->toBe($asked ? [[$leaf, revIssuerDer('throw-away-root.pem')]] : [])
        ->and(revIssuerState($report))->toBe($asked ? 'Invalid' : 'Trusted');
})->with([
    'x5chain [L, R]: R issued L' => ['leaf-root.png', true],
    'x5chain [L, B]: B did not' => ['leaf-bogus.png', false],
    'x5chain [L, N]: R\'s name, another key' => ['leaf-name-twin.png', false],
    'x5chain [L, K]: R\'s key, another name' => ['leaf-key-twin.png', false],
    'x5chain [L]' => ['leaf-alone.png', false],
])->group('SPEC-086');

it('option b: a signer that is itself an anchor is asked about with x5chain[1] only when that certificate issued it', function (string $png, bool $asked): void {
    $calls = new RevIssuerCalls;
    $leaf = revIssuerDer('signer-l.pem');
    $report = revIssuerVerify($png, revIssuerSettings('root.settings.json', anchor: 'signer-l.pem'), revIssuerSource($calls, [$leaf => (string) file_get_contents(revIssuerFile('leaf-revoked.der'))]));

    expect(array_map(static fn (array $c): array => [$c[0], $c[1]], $calls->calls))->toBe($asked ? [[$leaf, revIssuerDer('throw-away-root.pem')]] : [])
        ->and(revIssuerState($report))->toBe($asked ? 'Invalid' : 'Trusted');
})->with([
    'x5chain [L, R]: R issued L' => ['leaf-root.png', true],
    'x5chain [L, B]: B did not' => ['leaf-bogus.png', false],
    'x5chain [L, N]: R\'s name, another key' => ['leaf-name-twin.png', false],
    'x5chain [L, K]: R\'s key, another name' => ['leaf-key-twin.png', false],
])->group('SPEC-086');

// ---- SPEC-087 amendment 1, SPEC-066 amendment 3: the second walk of a time-stamping signer ----

it('a time-stamping signer\'s second walk asks the source about the CAs of its path', function (): void {
    $calls = new RevIssuerCalls;
    $intermediate = revIssuerDer('intermediate-i.pem');
    $report = revIssuerVerify('tsa-signer.png', revIssuerSettings('tsa-split.settings.json'), revIssuerSource($calls, [$intermediate => (string) file_get_contents(revIssuerFile('int-revoked.der'))]));

    expect(array_map(static fn (array $c): string => $c[0], $calls->calls))->toContain($intermediate)
        ->and(revIssuerCodes($report))->toContain('signingCredential.untrusted')
        ->and(revIssuerCodes($report))->not->toContain('signingCredential.trusted')
        ->and(revIssuerExplanation($report, 'signingCredential.untrusted'))->toContain('Throw-away Intermediate (issuer)');
})->group('SPEC-087');

it('a time-stamping signer\'s second walk reads a stapled response about a CA of its path', function (): void {
    $report = revIssuerVerify('tsa-signer-staple.png', revIssuerSettings('tsa-split.settings.json'));

    expect(revIssuerCodes($report))->toContain('signingCredential.untrusted')
        ->and(revIssuerCodes($report))->not->toContain('signingCredential.trusted')
        ->and(revIssuerExplanation($report, 'signingCredential.untrusted'))->toContain('Throw-away Intermediate (issuer)')
        ->and(revIssuerState($report))->toBe('Valid');
})->group('SPEC-066');

it('guard: the time-stamping signer without a revocation stays trusted', function (): void {
    $report = revIssuerVerify('tsa-signer.png', revIssuerSettings('tsa-split.settings.json'));

    expect(revIssuerCodes($report))->toContain('signingCredential.trusted')
        ->and(revIssuerState($report))->toBe('Trusted');
})->group('SPEC-087');
