<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

// SPEC-066 amendment 2: an ingredient's own stapled revoked (bin/make-ingredient-staple-variants.php, step 430)

function ingredientStapleProbe(string $name): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/ingredient-staple/{$name}.png", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$name}");
    }

    return (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/ingredient-staple/throw-away-root.settings.json')));
}

function ingredientStapleOracle(string $name, string $version): ?string
{
    $json = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/ingredient-staple/{$name}--{$version}.json"), true);

    return is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : null;
}

/** @return list<string> the OCSP codes reported under an ingredient */
function ingredientStapleOcsp(VerificationReport $report): array
{
    return array_values(array_map(
        static fn (ValidationStatus $s): string => $s->code->value,
        array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->ingredientUri !== null && str_starts_with($s->code->value, 'signingCredential.ocsp.')),
    ));
}

it('AC8: an ingredient whose signer stapled a revoked response makes the file Invalid; good and none report nothing (SPEC-066 amendment 2)', function (): void {
    $revoked = ingredientStapleProbe('child-staple-revoked');
    expect($revoked->result->state->value)->toBe('Invalid')
        ->and(ingredientStapleOcsp($revoked))->toBe(['signingCredential.ocsp.revoked']);

    foreach (['child-control', 'child-staple-good'] as $name) {
        $report = ingredientStapleProbe($name);
        expect($report->result->state->value)->toBe('Trusted', $name)
            ->and(ingredientStapleOcsp($report))->toBe([], $name);
    }
    expect(ingredientStapleProbe('parent-staple-revoked')->result->state->value)->toBe('Invalid');   // SPEC-030

    // c2patool reads none of these responses
    foreach (['0.28.1', '0.28.3'] as $version) {
        foreach (['parent', 'parent-staple-revoked', 'parent-staple-good', 'child-control', 'child-staple-revoked', 'child-staple-good'] as $name) {
            expect(ingredientStapleOracle($name, $version))->toBe('Trusted', "{$name} {$version}");
        }
    }
})->group('SPEC-066');
