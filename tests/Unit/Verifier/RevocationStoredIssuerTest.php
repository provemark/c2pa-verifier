<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\OcspEvidence;
use Provemark\C2paVerifier\Trust\RevocationSource;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-030 amendment 5: the stored OCSP responses about a trusted signer are read against the issuer the chain walk
 * verified, not against x5chain[1]. Found by the review of steps 483–489: a certificate with the anchor's name and
 * the author's key (N) could vouch for a revoked signer in the unsigned rVals and keep the source from being asked.
 * The probes are bin/make-revocation-stored-issuer-variants.php's (step 490): signer L under root R.
 */

/** The calls a test source received (a plain class, so the file always loads). */
final class RevStoredCalls
{
    public int $count = 0;
}

function revStoredSource(RevStoredCalls $calls): RevocationSource
{
    return new class($calls, (string) file_get_contents(revStoredFile('leaf-revoked.der'))) implements RevocationSource
    {
        public function __construct(private RevStoredCalls $calls, private string $answer) {}

        public function ocspResponse(string $subject, string $issuer, array $urls): string
        {
            $this->calls->count++;

            return $this->answer;
        }
    };
}

function revStoredFile(string $name): string
{
    return Corpus::fixtures()."/revocation-stored-issuer/{$name}";
}

function revStoredVerify(string $probe, ?string $settings, ?RevocationSource $source = null): VerificationReport
{
    $stream = fopen(revStoredFile("{$probe}.png"), 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$probe}.png");
    }
    try {
        $trust = $settings === null ? null : TrustSettings::fromJson((string) file_get_contents(revStoredFile("{$settings}.settings.json")));

        return ($source === null ? new Verifier : new Verifier(revocationSource: $source))->verify($stream, $trust);
    } finally {
        fclose($stream);
    }
}

/** @return array{0: string, 1: list<string>, 2: list<string>, 3: string} state, signingCredential.ocsp.* codes, evidence source:outcome, the ocsp explanation */
function revStoredShape(VerificationReport $report): array
{
    $ocsp = array_values(array_filter($report->result->statuses, static fn ($s): bool => str_starts_with($s->code->value, 'signingCredential.ocsp.')));
    $evidence = array_values($report->evidence())[0] ?? throw new RuntimeException('no evidence');
    $state = $report->toArray()['validation_state'] ?? '';

    return [
        is_string($state) ? $state : '',
        array_map(static fn ($s): string => $s->code->value, $ocsp),
        array_map(static fn (OcspEvidence $e): string => "{$e->source}:{$e->outcome}", $evidence->ocsp),
        $ocsp[0]->explanation ?? '',
    ];
}

it('a trusted signer\'s stored responses are read against the issuer the walk verified', function (string $settings, string $probe, bool $withSource, string $state, string $code, array $outcomes, int $asked): void {
    $calls = new RevStoredCalls;
    [$gotState, $codes, $gotOutcomes] = revStoredShape(revStoredVerify($probe, $settings, $withSource ? revStoredSource($calls) : null));

    expect([$gotState, $codes, $gotOutcomes, $calls->count])->toBe([$state, [$code], $outcomes, $asked]);
})->with([
    // N carries R's name and its own key: its forged good no longer verifies, so the source is asked
    'root, [L, N] forged good' => ['root', 'leaf-twin-forged', false, 'Trusted', 'signingCredential.ocsp.skipped', ['rVals:unusable'], 0],
    'root, [L, N] forged good, a source' => ['root', 'leaf-twin-forged', true, 'Invalid', 'signingCredential.ocsp.revoked', ['rVals:unusable', 'fetched:chosen'], 1],
    'root, [L, B] forged good, a source' => ['root', 'leaf-bogus-forged', true, 'Invalid', 'signingCredential.ocsp.revoked', ['rVals:unusable', 'fetched:chosen'], 1],
    // the anchor R issued L: its revoked is read though x5chain holds L alone (stricter than c2patool)
    'root, [L] R\'s revoked' => ['root', 'leaf-alone-revoked', false, 'Invalid', 'signingCredential.ocsp.revoked', ['rVals:chosen'], 0],
    'root, [L] R\'s revoked, a source' => ['root', 'leaf-alone-revoked', true, 'Invalid', 'signingCredential.ocsp.revoked', ['rVals:chosen'], 0],
    'root, [L, R] R\'s good (control)' => ['root', 'leaf-root-good', false, 'Trusted', 'signingCredential.ocsp.notRevoked', ['rVals:chosen'], 0],
    'root, [L, R] R\'s good, a source (control)' => ['root', 'leaf-root-good', true, 'Trusted', 'signingCredential.ocsp.notRevoked', ['rVals:chosen'], 0],
    // on the allowed list, x5chain[1] counts only when it demonstrably issued L (SPEC-086 amendment 2, option b)
    'allowed, [L, N] forged good' => ['allowed', 'leaf-twin-forged', false, 'Trusted', 'signingCredential.ocsp.skipped', ['rVals:notTried'], 0],
    'allowed, [L, N] forged good, a source' => ['allowed', 'leaf-twin-forged', true, 'Trusted', 'signingCredential.ocsp.skipped', ['rVals:notTried'], 0],
    'allowed, [L, B] forged good' => ['allowed', 'leaf-bogus-forged', false, 'Trusted', 'signingCredential.ocsp.skipped', ['rVals:notTried'], 0],
    'allowed, [L, R] R\'s good (control)' => ['allowed', 'leaf-root-good', false, 'Trusted', 'signingCredential.ocsp.notRevoked', ['rVals:chosen'], 0],
])->group('SPEC-030');

it('an ingredient manifest\'s stored responses are read against its verified issuer too', function (bool $withSource, string $state, array $ingredientOcsp, int $asked): void {
    $calls = new RevStoredCalls;
    $report = revStoredVerify('child-twin-forged', 'root', $withSource ? revStoredSource($calls) : null);
    $codes = array_values(array_map(static fn ($s): string => $s->code->value, array_filter($report->result->statuses, static fn ($s): bool => $s->ingredientUri !== null && str_starts_with($s->code->value, 'signingCredential.ocsp.'))));
    $outcomes = [];
    foreach ($report->evidence() as $label => $evidence) {
        if ($label !== ($report->store->active->label ?? '')) {
            $outcomes = array_map(static fn (OcspEvidence $e): string => "{$e->source}:{$e->outcome}", $evidence->ocsp);
        }
    }
    $got = $report->toArray()['validation_state'] ?? '';

    // M signs the child; the source's answer is about L, so it is unusable for M and decisive for the ingredient
    expect([$got, $codes, $outcomes, $calls->count])->toBe([$state, $ingredientOcsp, $withSource ? ['rVals:unusable', 'fetched:chosen'] : ['rVals:unusable'], $asked]);
})->with([
    'no source' => [false, 'Trusted', [], 0],
    'a source' => [true, 'Invalid', ['signingCredential.ocsp.revoked'], 2],
])->group('SPEC-030');

it('without a verified issuer the explanation says why nothing was read', function (): void {
    $explanation = revStoredShape(revStoredVerify('leaf-twin-forged', 'allowed'))[3];

    expect($explanation)->toContain('the chain walk verified no issuer for the signer');
})->group('SPEC-030');

it('guard: for a signer that is not trusted nothing changes', function (): void {
    // without trust settings L is untrusted; x5chain[1] is read as before and no verdict hangs on it
    [$state, $codes, $outcomes] = revStoredShape(revStoredVerify('leaf-twin-forged', null));

    expect([$state, $codes, $outcomes])->toBe(['Valid', ['signingCredential.ocsp.notRevoked'], ['rVals:chosen']]);
})->group('SPEC-030');

it('c2patool 0.28.3 calls every probe Trusted, and reports no OCSP code on the four leaf probes', function (string $probe): void {
    $oracle = (string) file_get_contents(Corpus::fixtures()."/c2patool/revocation-stored-issuer/{$probe}--0.28.3.json");

    expect($oracle)->toContain('"validation_state": "Trusted"')
        ->and(str_contains($oracle, 'signingCredential.ocsp'))->toBe($probe === 'child-twin-forged');   // the child: skipped, for the active signer
})->with(['leaf-twin-forged', 'leaf-bogus-forged', 'leaf-alone-revoked', 'leaf-root-good', 'child-twin-forged'])->group('SPEC-030');
