<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\OcspEvidence;
use Provemark\C2paVerifier\Trust\RevocationSource;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-085 amendment 2: a stored OCSP response is not read when the signer's issuer is not in x5chain, and the
 * evidence calls it notTried, not unusable. Found by the review of step 482; the probe is
 * bin/make-revocation-notried-variants.php's (step 486): signer L under root R, x5chain [L], L's good stapled.
 */

/** The calls a test source received (a plain class, so the file always loads). */
final class RevNotTriedCalls
{
    /** @var list<array{0: string, 1: string}> */
    public array $calls = [];
}

function revNotTriedSource(RevNotTriedCalls $calls, ?string $answer): RevocationSource
{
    return new class($calls, $answer) implements RevocationSource
    {
        public function __construct(private RevNotTriedCalls $calls, private ?string $answer) {}

        public function ocspResponse(string $subject, string $issuer, array $urls): ?string
        {
            $this->calls->calls[] = [$subject, $issuer];

            return $this->answer;
        }
    };
}

function revNotTriedFile(string $name): string
{
    return Corpus::fixtures()."/revocation-notried/{$name}";
}

function revNotTriedDer(string $pem): string
{
    return base64_decode(preg_replace('/-----[^-]+-----|\s/', '', (string) file_get_contents(revNotTriedFile($pem))) ?? '', true) ?: '';
}

function revNotTriedVerify(?RevocationSource $source = null): VerificationReport
{
    $stream = fopen(revNotTriedFile('leaf-alone-stapled.png'), 'rb');
    if ($stream === false) {
        throw new RuntimeException('cannot open leaf-alone-stapled.png');
    }
    try {
        return ($source === null ? new Verifier : new Verifier(revocationSource: $source))->verify($stream, TrustSettings::fromJson((string) file_get_contents(revNotTriedFile('root.settings.json'))));
    } finally {
        fclose($stream);
    }
}

/** @return list<array{0: string, 1: string}> code and explanation of each signingCredential.ocsp.* status */
function revNotTriedOcsp(VerificationReport $report): array
{
    return array_values(array_map(static fn ($s): array => [$s->code->value, $s->explanation], array_filter($report->result->statuses, static fn ($s): bool => str_starts_with($s->code->value, 'signingCredential.ocsp.'))));
}

/** @return list<array{0: string, 1: string, 2: string|null, 3: string|null, 4: bool}> source, outcome, code, certStatus, and whether the bytes are the staple's */
function revNotTriedEvidence(VerificationReport $report): array
{
    $evidence = array_values($report->evidence())[0] ?? throw new RuntimeException('no evidence');
    $staple = (string) file_get_contents(revNotTriedFile('leaf-good.der'));

    return array_map(static fn (OcspEvidence $e): array => [$e->source, $e->outcome, $e->code?->value, $e->certStatus, $e->response === $staple], $evidence->ocsp);
}

function revNotTriedState(VerificationReport $report): string
{
    $state = $report->toArray()['validation_state'] ?? '';

    return is_string($state) ? $state : '';
}

it('without a source, the staple is not tried and the verdict is unchanged', function (): void {
    $report = revNotTriedVerify();
    $ocsp = revNotTriedOcsp($report);
    $oracle = (string) file_get_contents(Corpus::fixtures().'/c2patool/revocation-notried/leaf-alone-stapled--0.28.3.json');

    expect(array_column($ocsp, 0))->toBe(['signingCredential.ocsp.skipped'])
        ->and($ocsp[0][1] ?? '')->toContain('the signer\'s issuer is not in the x5chain (1 certificate(s))')
        ->and(revNotTriedEvidence($report))->toBe([['rVals', 'notTried', null, null, true]])
        ->and(revNotTriedState($report))->toBe('Trusted')
        ->and($oracle)->toContain('"validation_state": "Trusted"');
})->group('SPEC-085');

it('with a source answering revoked, the staple stays not tried and the fetched answer is chosen', function (): void {
    $calls = new RevNotTriedCalls;
    $report = revNotTriedVerify(revNotTriedSource($calls, (string) file_get_contents(revNotTriedFile('leaf-revoked.der'))));

    expect($calls->calls)->toBe([[revNotTriedDer('signer-l.pem'), revNotTriedDer('throw-away-root.pem')]])
        ->and(array_column(revNotTriedOcsp($report), 0))->toBe(['signingCredential.ocsp.revoked'])
        ->and(revNotTriedEvidence($report))->toBe([
            ['rVals', 'notTried', null, null, true],
            ['fetched', 'chosen', 'signingCredential.ocsp.revoked', 'revoked', false],
        ])
        ->and(revNotTriedState($report))->toBe('Invalid');
})->group('SPEC-085');

it('with a source that answers nothing, the staple stays not tried', function (): void {
    $report = revNotTriedVerify(revNotTriedSource(new RevNotTriedCalls, null));

    expect(array_column(revNotTriedOcsp($report), 0))->toBe(['signingCredential.ocsp.inaccessible'])
        ->and(revNotTriedEvidence($report))->toBe([['rVals', 'notTried', null, null, true]])
        ->and(revNotTriedState($report))->toBe('Trusted');
})->group('SPEC-085');
