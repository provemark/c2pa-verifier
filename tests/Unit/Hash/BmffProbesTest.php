<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-027 amendment 8 and SPEC-028 amendment 2: the ISOBMFF probes of the reading of C2PA 2.4 (L1, L12), made by
 * bin/make-bmff-probe-variants.php under tests/Fixtures/bmff-probes/, with both c2patool versions' answers.
 */

function spec027Probe(string $name): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/bmff-probes/{$name}.mp4", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$name}");
    }

    return (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/bmff-probes/throw-away-root.settings.json')));
}

function spec027ProbeOracle(string $name, string $version): ?string
{
    $json = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/bmff-probes/{$name}--{$version}.json"), true);

    return is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : null;
}

/** @return list<string> */
function spec027ProbeExplanations(VerificationReport $report, string $code): array
{
    return array_values(array_map(
        static fn (ValidationStatus $s): string => $s->explanation,
        array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->value === $code),
    ));
}

it('AC9: a BMFF hash without alg uses the claim\'s algorithm, as c2patool does (SPEC-027 amendment 8)', function (): void {
    foreach (['sha384-control', 'sha384-bmff-no-alg'] as $name) {
        expect(spec027Probe($name)->result->state->value)->toBe('Trusted', $name)
            ->and(spec027ProbeOracle($name, '0.28.1'))->toBe('Trusted', $name)
            ->and(spec027ProbeOracle($name, '0.27.22'))->toBe('Trusted', $name);
    }
})->group('SPEC-027');

it('AC9: a merkle map without a count, or with a count of 0, is malformed; an init segment alone is never a match (SPEC-028 amendment 2)', function (): void {
    foreach (['init-no-count' => 'no count', 'init-count-zero' => 'a count of 0'] as $name => $says) {
        $report = spec027Probe($name);

        expect($report->result->state->value)->toBe('Invalid', $name)
            ->and(implode(' | ', spec027ProbeExplanations($report, 'assertion.bmffHash.malformed')))->toContain($says)
            ->and(spec027ProbeExplanations($report, 'assertion.bmffHash.match'))->toBe([], $name);
    }
    // c2patool refuses both: it cannot decode the first, and calls the second Invalid
    expect(spec027ProbeOracle('init-no-count', '0.28.1'))->toBeNull()
        ->and(spec027ProbeOracle('init-count-zero', '0.28.1'))->toBe('Invalid')
        ->and(spec027Probe('init-alone')->result->state->value)->toBe('Invalid')
        ->and(spec027ProbeOracle('init-alone', '0.28.1'))->toBe('Invalid');
})->group('SPEC-028');
