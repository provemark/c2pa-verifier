<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cli\Command;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-073: a validation time given by the caller. It replaces "the current
 * time" of C2PA 2.4 §15.8.2, §15.9 and §15.9.1, and nothing else: a trusted
 * timestamp's attested time keeps precedence. No oracle has a time option,
 * so the criteria rest on the specification's text; the windows below were
 * measured with bin/c2pa-verify on 2026-10-23.
 */

const SPEC073_NO_TIMESTAMP = 'timestamp/tsa-matrix/expired-signer-no-timestamp';
const SPEC073_TRUSTED_TSA = 'timestamp/tsa-matrix/expired-signer-trusted-tsa';

function spec073Report(string $relative, ?string $settings, ?DateTimeInterface $at): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }
    $trust = $settings === null ? null : TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/'.$settings));

    return (new Verifier)->verify($stream, $trust, $at);
}

/** A tsa-matrix probe with its own settings file. */
function spec073Probe(string $probe, ?DateTimeInterface $at): VerificationReport
{
    return spec073Report("{$probe}.png", "{$probe}.settings.json", $at);
}

/**
 * Every status code and explanation in the report, the ingredients' included.
 *
 * @return list<array{string, string}>
 */
function spec073Statuses(VerificationReport $report): array
{
    $found = [];
    $walk = static function (mixed $node) use (&$walk, &$found): void {
        if (! is_array($node)) {
            return;
        }
        if (is_string($node['code'] ?? null)) {
            $found[] = [$node['code'], is_string($node['explanation'] ?? null) ? $node['explanation'] : ''];
        }
        foreach ($node as $child) {
            $walk($child);
        }
    };
    $walk(json_decode($report->toJson(), true, 512, JSON_THROW_ON_ERROR));

    return $found;
}

/** @return list<string> */
function spec073Codes(VerificationReport $report): array
{
    return array_values(array_unique(array_column(spec073Statuses($report), 0)));
}

/** @return array{int, string, string} exit status, stdout, stderr */
function spec073Run(string ...$arguments): array
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

// AC1
it('AC1: an expired signer without a timestamp is judged at the given time', function (): void {
    $now = spec073Probe(SPEC073_NO_TIMESTAMP, null);
    $then = spec073Probe(SPEC073_NO_TIMESTAMP, new DateTimeImmutable('2026-10-09T05:31:00Z'));

    expect($now->result->state->value)->toBe('Invalid')
        ->and(spec073Codes($now))->toContain('signingCredential.expired')
        ->and($then->result->state->value)->toBe('Trusted')
        ->and(spec073Codes($then))->toContain('claimSignature.insideValidity')
        ->and(spec073Codes($then))->toContain('signingCredential.trusted');
})->group('SPEC-073');

// AC2
it('AC2: the edges of the validity, and a fraction rounded down', function (string $time, string $state, ?string $said): void {
    $report = spec073Probe(SPEC073_NO_TIMESTAMP, new DateTimeImmutable($time));

    expect($report->result->state->value)->toBe($state);
    if ($said !== null) {
        $expired = array_values(array_filter(spec073Statuses($report), static fn (array $s): bool => $s[0] === 'signingCredential.expired'));
        expect($expired)->not->toBe([])
            ->and($expired[0][1])->toContain($said)
            ->and($expired[0][1])->toContain('checked at the given validation time');
    }
})->with([
    'notAfter itself' => ['2026-10-09T05:32:54Z', 'Trusted', null],
    'notAfter and nine tenths' => ['2026-10-09T05:32:54.9Z', 'Trusted', null],
    'a second after notAfter' => ['2026-10-09T05:32:55Z', 'Invalid', 'expired at 2026-10-09T05:32:55Z'],
    'a second before notBefore' => ['2026-10-08T05:30:23Z', 'Invalid', 'not yet valid at 2026-10-08T05:30:23Z'],
])->group('SPEC-073');

// AC3
it('AC3: a trusted timestamp wins over the given time', function (): void {
    $now = spec073Probe(SPEC073_TRUSTED_TSA, null);
    $later = spec073Probe(SPEC073_TRUSTED_TSA, new DateTimeImmutable('2099-01-01T00:00:00Z'));
    $earlier = spec073Probe(SPEC073_TRUSTED_TSA, new DateTimeImmutable('2020-01-01T00:00:00Z'));

    expect($now->result->state->value)->toBe('Trusted')
        ->and($later->toJson())->toBe($now->toJson())
        ->and($earlier->toJson())->toBe($now->toJson());
})->group('SPEC-073');

// AC4
it('AC4: the stapled OCSP response and the ingredient follow the given time', function (): void {
    $now = spec073Report('c2pa-rs/ocsp.jpg', null, null);
    $then = spec073Report('c2pa-rs/ocsp.jpg', null, new DateTimeImmutable('2025-08-14T00:00:00Z'));
    $expiredNow = array_filter(spec073Statuses($now), static fn (array $s): bool => $s[0] === 'signingCredential.expired');
    $signers = array_values(array_unique(array_map(static fn (array $s): string => (string) preg_replace('/^.*valid from (\S+) to (\S+),.*$/', '$1 $2', $s[1]), $expiredNow)));

    // today: both signers expired, the active one's and the ingredient's, and the response not current
    expect($signers)->toEqualCanonicalizing(['2024-10-15T00:00:00Z 2025-10-15T23:59:59Z', '2025-05-08T18:14:36Z 2026-05-08T18:14:36Z'])
        ->and(spec073Codes($now))->toContain('signingCredential.ocsp.skipped')
        ->and(spec073Codes($now))->not->toContain('signingCredential.ocsp.notRevoked')
        // at a time inside the response's week: the response is used and neither signer is expired
        ->and(spec073Codes($then))->toContain('signingCredential.ocsp.notRevoked')
        ->and(spec073Codes($then))->toContain('ingredient.manifest.validated')
        ->and(spec073Codes($then))->not->toContain('signingCredential.expired');
})->group('SPEC-073');

// AC5
it('AC5: a time that is not RFC 3339 is refused on the command line', function (string ...$options): void {
    [$status, $out, $err] = spec073Run(...[...$options, Corpus::fixtures().'/'.SPEC073_NO_TIMESTAMP.'.png']);

    expect($status)->toBe(2)
        ->and($out)->toBe('')
        ->and($err)->toContain('--at')
        ->and($err)->toContain('RFC 3339');
})->with([
    'a date only' => ['--at', '2026-10-09'],
    'no offset' => ['--at', '2026-10-09T05:31:00'],
    'no such day' => ['--at', '2026-02-30T00:00:00Z'],
    'a word' => ['--at', 'yesterday'],
    'empty' => ['--at='],
])->group('SPEC-073');

it('AC5: --at as the last argument is refused', function (): void {
    [$status, $out, $err] = spec073Run(Corpus::fixtures().'/'.SPEC073_NO_TIMESTAMP.'.png', '--at');

    expect($status)->toBe(2)
        ->and($out)->toBe('')
        ->and($err)->toContain('--at')
        ->and($err)->toContain('RFC 3339');
})->group('SPEC-073');

it('AC5: an offset other than Z is converted', function (): void {
    $file = Corpus::fixtures().'/'.SPEC073_NO_TIMESTAMP.'.png';
    $settings = Corpus::fixtures().'/'.SPEC073_NO_TIMESTAMP.'.settings.json';
    [$plus, $plusOut] = spec073Run('--settings', $settings, '--at', '2026-10-09T07:31:00+02:00', $file);
    [$zulu, $zuluOut] = spec073Run('--settings', $settings, '--at=2026-10-09T05:31:00Z', $file);

    expect($plus)->toBe(0)
        ->and($zulu)->toBe(0)
        ->and($plusOut)->toBe($zuluOut)
        ->and($plusOut)->toBe(spec073Probe(SPEC073_NO_TIMESTAMP, new DateTimeImmutable('2026-10-09T05:31:00Z'))->toJson()."\n");
})->group('SPEC-073');

// AC6 (amendment 1)
/** inspect() with a clock of its own: what verify() does with time(), read once. */
function spec073AtClock(string $relative, string $clock): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }
    $at = (int) strtotime($clock);
    $inspect = Closure::bind(static fn (Verifier $v): VerificationReport => $v->inspect($stream, null, null, $at), null, Verifier::class);

    return $inspect(new Verifier);
}

/** @return list<string> the explanations of signingCredential.expired for one manifest's signature */
function spec073Expired(VerificationReport $report, string $manifest): array
{
    $found = [];
    foreach ($report->result->statuses as $status) {
        if ($status->code->value === 'signingCredential.expired' && str_contains($status->url, $manifest)) {
            $found[] = $status->explanation;
        }
    }

    return $found;
}

it('AC6: without a given time, every check judges at the one clock reading verify() took, and says "now"', function (string $relative, string $manifest, string $then, string $later): void {
    $atThen = spec073Expired(spec073AtClock($relative, $then), $manifest);
    $atLater = spec073Expired(spec073AtClock($relative, $later), $manifest);

    expect($atThen)->toBe([])
        ->and($atLater)->toHaveCount(1)
        ->and($atLater[0] ?? '')->toContain('expired at '.$later)
        ->and($atLater[0] ?? '')->toContain('checked at now');
})->with([
    'the active signer (ocsp.jpg, valid 2024-10-15 to 2025-10-15)' => ['c2pa-rs/ocsp.jpg', 'adobe:urn:uuid:f3a46a78-8103-4e04-9f98-abcf03ebab19', '2025-01-01T00:00:00Z', '2026-10-23T00:00:00Z'],
    'an ingredient\'s signer (video1.mp4, valid 2022-04-04 to 2023-04-04)' => ['c2pa-rs/video1.mp4', 'urn:uuid:54281c07-ad34-430e-bea5-112a18facf0b', '2022-06-01T00:00:00Z', '2026-10-23T00:00:00Z'],
])->group('SPEC-073');

it('AC6: the report records the clock reading the checks used', function (): void {
    $before = time();
    $report = spec073Report('c2pa-rs/ocsp.jpg', null, null);
    $after = time();
    $at = $report->validationTime?->getTimestamp() ?? 0;
    $expired = implode("\n", array_column(array_filter(spec073Statuses($report), static fn (array $s): bool => $s[0] === 'signingCredential.expired'), 1));

    expect($at)->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual($after)
        ->and($expired)->toContain('expired at '.gmdate('Y-m-d\TH:i:s\Z', $at));
})->group('SPEC-073');
