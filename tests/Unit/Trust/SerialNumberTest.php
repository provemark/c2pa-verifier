<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Support\Bytes;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-015 amendment 7: a certificate's serial number is a positive integer (RFC 5280 §4.1.2.2). The probes are
 * the trust matrix's (SPEC-061 amendment 2), each with a serial OpenSSL wrote as asked: the leaf -0x0FDB19DB89FA0E,
 * the leaf 0, the intermediate -0x0FDB19DB89FA0F.
 */

/** Verifies a chain-matrix probe under its settings, with every PHP notice turned into a failure. */
function spec015Serial(string $probe): VerificationReport
{
    set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });
    try {
        $stream = fopen(Corpus::fixtures()."/trust/chain-matrix/{$probe}.png", 'rb');
        if ($stream === false) {
            throw new RuntimeException("cannot open {$probe}");
        }

        return (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures()."/trust/chain-matrix/{$probe}.settings.json")));
    } finally {
        restore_error_handler();
    }
}

/** @return array<string, string> code => explanation, the active manifest's failures */
function spec015SerialFailures(VerificationReport $report): array
{
    $failures = [];
    foreach ($report->result->statuses as $status) {
        if ($status->code->isFailure() && $status->ingredientUri === null) {
            $failures[$status->code->value] = $status->explanation;
        }
    }

    return $failures;
}

it('AC12: a negative serial converts with its sign, and without a notice', function (): void {
    set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });
    try {
        expect(Bytes::hexToDecimal('-0FDB19DB89FA0E'))->toBe('-4463028754577934')
            ->and(Bytes::hexToDecimal('-01'))->toBe('-1')
            ->and(Bytes::hexToDecimal('0FDB19DB89FA0E'))->toBe('4463028754577934');
    } finally {
        restore_error_handler();
    }
})->group('SPEC-015');

it('AC12: a leaf whose serial is not positive is signingCredential.invalid, naming the serial', function (): void {
    $negative = spec015Serial('leaf-serial-negative');
    $zero = spec015Serial('leaf-serial-zero');

    expect($negative->result->state->value)->toBe('Invalid')
        ->and(spec015SerialFailures($negative)['signingCredential.invalid'] ?? '')->toContain('serial number -4463028754577934 is not a positive integer')
        ->and($zero->result->state->value)->toBe('Invalid')
        ->and(spec015SerialFailures($zero)['signingCredential.invalid'] ?? '')->toContain('serial number 0 is not a positive integer');
})->group('SPEC-015');

it('AC12: an intermediate whose serial is not positive makes the chain untrusted, naming it', function (): void {
    $report = spec015Serial('int-serial-negative');
    $explanations = implode(' ', array_map(static fn (ValidationStatus $s): string => $s->explanation, $report->result->statuses));

    expect($report->result->state->value)->toBe('Valid')
        ->and(array_map(static fn (ValidationStatus $s): string => $s->code->value, $report->result->statuses))->toContain('signingCredential.untrusted')
        ->and($explanations)->toContain('has serial number -4463028754577935, which is not a positive integer');
})->group('SPEC-015');

it('AC12: the control keeps its positive serial and stays Trusted', function (): void {
    expect(spec015Serial('control')->result->state->value)->toBe('Trusted');
})->group('SPEC-015');
