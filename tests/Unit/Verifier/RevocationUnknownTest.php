<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\OcspEvidence;
use Provemark\C2paVerifier\Trust\RevocationSource;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-086 amendment 3: a stored OCSP `unknown` about the signer no longer keeps the revocation source from being
 * asked. A decisive answer (revoked, or a current good) counts; anything else leaves `unknown`, the explanation
 * saying what the source gave. Found by the review of step 482; the probe is
 * bin/make-revocation-unknown-variants.php's (step 484).
 */

/** The calls a test source received (a plain class, so the file always loads). */
final class RevUnknownCalls
{
    /** @var list<array{0: string, 1: string}> */
    public array $calls = [];
}

function revUnknownSource(RevUnknownCalls $calls, ?string $answer = null, bool $throw = false): RevocationSource
{
    return new class($calls, $answer, $throw) implements RevocationSource
    {
        public function __construct(private RevUnknownCalls $calls, private ?string $answer, private bool $throw) {}

        public function ocspResponse(string $subject, string $issuer, array $urls): ?string
        {
            $this->calls->calls[] = [$subject, $issuer];
            if ($this->throw) {
                throw new RuntimeException('offline');
            }

            return $this->answer;
        }
    };
}

function revUnknownFile(string $name): string
{
    return Corpus::fixtures()."/revocation-unknown/{$name}";
}

function revUnknownDer(string $pem): string
{
    return base64_decode(preg_replace('/-----[^-]+-----|\s/', '', (string) file_get_contents(revUnknownFile($pem))) ?? '', true) ?: '';
}

function revUnknownVerify(?RevocationSource $source = null): VerificationReport
{
    $stream = fopen(revUnknownFile('leaf-unknown.png'), 'rb');
    if ($stream === false) {
        throw new RuntimeException('cannot open leaf-unknown.png');
    }
    try {
        return (new Verifier(revocationSource: $source))->verify($stream, TrustSettings::fromJson((string) file_get_contents(revUnknownFile('root.settings.json'))));
    } finally {
        fclose($stream);
    }
}

/** @return list<array{0: string, 1: string}> code and explanation of each signingCredential.ocsp.* status */
function revUnknownOcsp(VerificationReport $report): array
{
    return array_values(array_map(static fn ($s): array => [$s->code->value, $s->explanation], array_filter($report->result->statuses, static fn ($s): bool => str_starts_with($s->code->value, 'signingCredential.ocsp.'))));
}

/** @return list<array{0: string, 1: string, 2: string|null, 3: string|null}> source, outcome, code and certStatus of each entry */
function revUnknownEvidence(VerificationReport $report): array
{
    $evidence = array_values($report->evidence())[0] ?? throw new RuntimeException('no evidence');

    return array_map(static fn (OcspEvidence $e): array => [$e->source, $e->outcome, $e->code?->value, $e->certStatus], $evidence->ocsp);
}

function revUnknownState(VerificationReport $report): string
{
    $state = $report->toArray()['validation_state'] ?? '';

    return is_string($state) ? $state : '';
}

it('guard: without a source, the stored unknown is reported as before', function (): void {
    $report = revUnknownVerify();
    $ocsp = revUnknownOcsp($report);
    $oracle = (string) file_get_contents(Corpus::fixtures().'/c2patool/revocation-unknown/leaf-unknown--0.28.3.json');

    expect(array_column($ocsp, 0))->toBe(['signingCredential.ocsp.unknown'])
        ->and($ocsp[0][1] ?? '')->toContain('does not know the status of Signer L (unknown)')
        ->and(revUnknownEvidence($report))->toBe([['rVals', 'chosen', 'signingCredential.ocsp.unknown', 'unknown']])
        ->and(revUnknownState($report))->toBe('Trusted')
        ->and($oracle)->toContain('"validation_state": "Trusted"')
        ->and(str_contains($oracle, 'signingCredential.ocsp'))->toBeFalse();   // c2patool 0.28.3 reports no OCSP code
})->group('SPEC-086');

it('the source is asked after a stored unknown, with the verified issuer, and its revoked counts', function (): void {
    $calls = new RevUnknownCalls;
    $report = revUnknownVerify(revUnknownSource($calls, (string) file_get_contents(revUnknownFile('leaf-revoked.der'))));

    expect($calls->calls)->toBe([[revUnknownDer('signer-l.pem'), revUnknownDer('throw-away-root.pem')]])
        ->and(array_column(revUnknownOcsp($report), 0))->toBe(['signingCredential.ocsp.revoked'])
        ->and(revUnknownEvidence($report))->toBe([
            ['rVals', 'notChosen', null, 'unknown'],
            ['fetched', 'chosen', 'signingCredential.ocsp.revoked', 'revoked'],
        ])
        ->and(revUnknownState($report))->toBe('Invalid');
})->group('SPEC-086');

it('a current good from the source replaces the stored unknown', function (): void {
    $report = revUnknownVerify(revUnknownSource(new RevUnknownCalls, (string) file_get_contents(revUnknownFile('leaf-good.der'))));

    expect(array_column(revUnknownOcsp($report), 0))->toBe(['signingCredential.ocsp.notRevoked'])
        ->and(revUnknownEvidence($report))->toBe([
            ['rVals', 'notChosen', null, 'unknown'],
            ['fetched', 'chosen', 'signingCredential.ocsp.notRevoked', 'good'],
        ])
        ->and(revUnknownState($report))->toBe('Trusted');
})->group('SPEC-086');

it('an indecisive answer leaves the stored unknown, and says what the source gave', function (?string $answer, bool $throw, string $says, array $fetched): void {
    $calls = new RevUnknownCalls;
    $report = revUnknownVerify(revUnknownSource($calls, $answer === null ? null : (string) file_get_contents(revUnknownFile($answer)), $throw));
    $ocsp = revUnknownOcsp($report);

    expect($calls->calls)->toHaveCount(1)
        ->and(array_column($ocsp, 0))->toBe(['signingCredential.ocsp.unknown'])
        ->and($ocsp[0][1] ?? '')->toContain('does not know the status of Signer L (unknown)')
        ->and($ocsp[0][1] ?? '')->toContain('the caller\'s revocation source was asked as well')
        ->and($ocsp[0][1] ?? '')->toContain($says)
        ->and(revUnknownEvidence($report))->toBe([['rVals', 'chosen', 'signingCredential.ocsp.unknown', 'unknown'], ...$fetched])
        ->and(revUnknownState($report))->toBe('Trusted');
})->with([
    'no answer' => [null, false, 'returned no response', []],
    'an exception' => [null, true, 'failed with an exception', []],
    'unknown again' => ['leaf-unknown.der', false, 'does not know the status', [['fetched', 'notChosen', null, 'unknown']]],
])->group('SPEC-086');

it('an answer that cannot be used leaves the stored unknown', function (): void {
    $report = revUnknownVerify(revUnknownSource(new RevUnknownCalls, 'not DER'));
    $ocsp = revUnknownOcsp($report);

    expect(array_column($ocsp, 0))->toBe(['signingCredential.ocsp.unknown'])
        ->and($ocsp[0][1] ?? '')->toContain('cannot be used')
        ->and(revUnknownEvidence($report))->toBe([['rVals', 'chosen', 'signingCredential.ocsp.unknown', 'unknown'], ['fetched', 'unusable', null, null]]);
})->group('SPEC-086');
