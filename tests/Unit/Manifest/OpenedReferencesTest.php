<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

// SPEC-033 amendment 3: every reference of a c2pa.opened is the one parent (bin/make-opened-reference-variants.php, step 429)

function openedReferenceProbe(string $name): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/opened-references/{$name}.jpg", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$name}");
    }

    return (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/opened-references/throw-away-root.settings.json')));
}

function openedReferenceOracle(string $name, string $version): ?string
{
    $json = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/opened-references/{$name}--{$version}.json"), true);

    return is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : null;
}

/** @return list<string> */
function openedReferenceFailures(VerificationReport $report): array
{
    return array_values(array_map(
        static fn (ValidationStatus $s): string => $s->code->value,
        array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->isFailure()),
    ));
}

it('AC10: every reference of a c2pa.opened must be the one parentOf ingredient; placed keeps at least one (SPEC-033 amendment 3)', function (): void {
    expect(openedReferenceProbe('opened-good')->result->state->value)->toBe('Trusted');
    foreach (['opened-good-unresolvable', 'opened-unresolvable-good', 'opened-good-twice', 'opened-good-component'] as $name) {
        $report = openedReferenceProbe($name);
        expect($report->result->state->value)->toBe('Invalid', $name)
            ->and(openedReferenceFailures($report))->toBe(['assertion.action.ingredientMismatch'], $name);
    }
    foreach (['placed-good-unresolvable', 'placed-unresolvable-good'] as $name) {
        expect(openedReferenceProbe($name)->result->state->value)->toBe('Trusted', $name);
    }

    // c2patool lets the last reference decide
    $expected = [
        'opened-good' => 'Trusted', 'opened-good-unresolvable' => 'Invalid', 'opened-unresolvable-good' => 'Trusted',
        'opened-good-twice' => 'Trusted', 'opened-good-component' => 'Invalid',
        'placed-good-unresolvable' => 'Trusted', 'placed-unresolvable-good' => 'Trusted',
    ];
    foreach (['0.28.1', '0.28.3'] as $version) {
        foreach ($expected as $name => $state) {
            expect(openedReferenceOracle($name, $version))->toBe($state, "{$name} {$version}");
        }
    }
})->group('SPEC-033');
