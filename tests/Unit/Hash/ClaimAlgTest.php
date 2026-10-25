<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

// SPEC-027 amendment 9: a claim without alg (bin/make-claim-alg-variants.php, step 427)

function claimAlgProbe(string $name): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/claim-alg/{$name}.mp4", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$name}");
    }

    return (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/claim-alg/throw-away-root.settings.json')));
}

function claimAlgOracle(string $name, string $version): string
{
    $out = (string) file_get_contents(Corpus::fixtures()."/c2patool/claim-alg/{$name}--{$version}.json");
    $json = json_decode($out, true);

    return is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : trim($out);
}

/** @return list<string> */
function claimAlgStatuses(VerificationReport $report, string $code): array
{
    return array_values(array_map(
        static fn (ValidationStatus $s): string => $s->url.' '.$s->explanation,
        array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->value === $code),
    ));
}

it('AC10: a BMFF hash with no algorithm named, here or in the claim, is algorithm.unsupported (SPEC-027 amendment 9)', function (): void {
    foreach (['control', 'entries-alg'] as $name) {
        expect(claimAlgProbe($name)->result->state->value)->toBe('Trusted', $name);
    }
    $report = claimAlgProbe('entries-alg-bmff-no-alg');
    $unsupported = claimAlgStatuses($report, 'algorithm.unsupported');

    expect($report->result->state->value)->toBe('Invalid')
        ->and($unsupported)->toHaveCount(1)
        ->and($unsupported[0])->toContain('c2pa.hash.bmff.v3')
        ->and($unsupported[0])->toContain('§15.4.1')
        ->and(claimAlgStatuses($report, 'assertion.bmffHash.match'))->toBe([]);

    // c2patool reads the control and refuses a claim without alg outright
    foreach (['0.28.1', '0.28.3'] as $version) {
        expect(claimAlgOracle('control', $version))->toBe('Trusted')
            ->and(claimAlgOracle('entries-alg', $version))->toBe('Error: unknown algorithm')
            ->and(claimAlgOracle('entries-alg-bmff-no-alg', $version))->toBe('Error: unknown algorithm');
    }
})->group('SPEC-027');

it('AC10: a claim alg outside §13.1 that no entry and no binding falls back on is not judged, as in c2patool (step 428)', function (): void {
    expect(claimAlgProbe('entries-alg-claim-sha224')->result->state->value)->toBe('Trusted');
    foreach (['0.28.1', '0.28.3'] as $version) {
        expect(claimAlgOracle('entries-alg-claim-sha224', $version))->toBe('Trusted', $version);
    }
})->group('SPEC-027');
