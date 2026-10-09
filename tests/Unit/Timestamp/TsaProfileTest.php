<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Timestamp\TimestampCheck;
use Provemark\C2paVerifier\Timestamp\TimestampHeader;
use Provemark\C2paVerifier\Timestamp\TimeStampToken;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-017 AC14 (amendment 8): a timestamp authority's leaf that fails the certificate profile
 * makes a version 2 claim's file Invalid, as c2patool 0.28.1 says. The probes come from
 * bin/make-tsa-matrix.php under tests/Fixtures/timestamp/tsa-profile/; the answers are under
 * tests/Fixtures/c2patool/tsa-profile/.
 */

function spec017ProfileVerify(string $probe, bool $withSettings): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/timestamp/tsa-profile/{$probe}.png", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$probe}");
    }
    $settings = $withSettings ? TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures()."/timestamp/tsa-profile/{$probe}.settings.json")) : null;

    return (new Verifier)->verify($stream, $settings);
}

/** @return list<StatusCode> */
function spec017ProfileCodes(VerificationReport $report): array
{
    return array_map(static fn (ValidationStatus $s): StatusCode => $s->code, $report->result->statuses);
}

function spec017ProfileOracle(string $probe, string $suffix): string
{
    /** @var array{validation_state: string} $json */
    $json = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/tsa-profile/{$probe}--{$suffix}.json"), true, 512, JSON_THROW_ON_ERROR);

    return $json['validation_state'];
}

test('SPEC-017 AC14: a TSA on the profile leaves the file Trusted, as c2patool 0.28.1', function (): void {
    $report = spec017ProfileVerify('control', true);

    expect($report->result->state->value)->toBe('Trusted')
        ->and(spec017ProfileCodes($report))->toContain(StatusCode::TimeStampTrusted)
        ->and(spec017ProfileOracle('control', '0.28.1'))->toBe('Trusted');
})->group('SPEC-017');

test('SPEC-017 AC14: a TSA leaf off the profile makes the file Invalid, with and without settings, as c2patool 0.28.1', function (string $probe, string $why): void {
    foreach ([true, false] as $withSettings) {
        $report = spec017ProfileVerify($probe, $withSettings);
        $invalid = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::SigningCredentialInvalid));
        $context = $probe.($withSettings ? ' with settings' : ' without settings');

        expect($report->result->state->value)->toBe('Invalid', $context)
            ->and(spec017ProfileCodes($report))->toContain(StatusCode::TimeStampUntrusted)
            ->and($invalid)->toHaveCount(1)
            ->and($invalid[0]->explanation)->toContain('timestamp authority')
            ->and($invalid[0]->explanation)->toContain($why);
    }
    expect(spec017ProfileOracle($probe, '0.28.1'))->toBe('Invalid')
        ->and(spec017ProfileOracle($probe, '0.28.1--no-settings'))->toBe('Invalid')
        ->and(spec017ProfileOracle($probe, '0.27.22--no-settings'))->toBe('Invalid');
})->with([
    'no keyUsage' => ['tsa-leaf-no-key-usage', 'no KeyUsage extension'],
    'CA:TRUE' => ['tsa-leaf-ca-true', 'CA'],
    'signed over SHA-1' => ['tsa-leaf-sha1', 'ecdsa-with-SHA1'],
])->group('SPEC-017');

test('SPEC-017 AC14: a version 1 claim is unchanged, as c2pa-rs checks no TSA profile there', function (): void {
    $store = Corpus::manifestStore('timestamp/tsa-profile/tsa-leaf-no-key-usage.png');
    assert($store !== null);
    $cose = CoseSign1::ofManifest($store->active);
    $header = TimestampHeader::fromUnprotected($cose->unprotected);
    assert($header !== null);
    $token = TimeStampToken::fromHeaderValue($header->tokens[0]);
    $tbs = TimestampCheck::countersignedBytes($cose, $header->header, $store->active->claimBytes());
    $codes = static fn (int $version): array => array_map(
        static fn (ValidationStatus $s): StatusCode => $s->code,
        (new TimestampCheck)->judge($token, $tbs, null, 'self#jumbf=/probe', claimVersion: $version)->statuses,
    );

    expect($codes(2))->toContain(StatusCode::SigningCredentialInvalid)
        ->and($codes(1))->toContain(StatusCode::TimeStampUntrusted)
        ->and(in_array(StatusCode::SigningCredentialInvalid, $codes(1), true))->toBeFalse();
})->group('SPEC-017');
