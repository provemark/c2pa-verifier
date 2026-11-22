<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Timestamp\TimestampCheck;
use Provemark\C2paVerifier\Timestamp\TimestampHeader;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-017 AC14 (amendment 8, rewritten by amendment 12) and AC15: a timestamp authority's leaf that
 * fails the certificate profile makes the time-stamp untrusted and ignored (C2PA 2.4 §15.8.2, 2.5
 * §14.8.2), as c2patool 0.29.0 says; it is not a fault in the signer, and it cannot keep an expired
 * signer valid. The probes come from bin/make-tsa-matrix.php under tests/Fixtures/timestamp/tsa-profile/;
 * the answers are under tests/Fixtures/c2patool/tsa-profile/.
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

test('SPEC-017 AC14: a TSA on the profile leaves the file Trusted, as c2patool 0.29.0', function (): void {
    $report = spec017ProfileVerify('control', true);

    expect($report->result->state->value)->toBe('Trusted')
        ->and(spec017ProfileCodes($report))->toContain(StatusCode::TimeStampTrusted)
        ->and(spec017ProfileOracle('control', '0.29.0'))->toBe('Trusted');
})->group('SPEC-017');

test('SPEC-017 AC14: a TSA leaf off the profile makes the time-stamp untrusted, not the file Invalid, as c2patool 0.29.0', function (string $probe, string $why): void {
    foreach (['Trusted' => true, 'Valid' => false] as $state => $withSettings) {
        $report = spec017ProfileVerify($probe, $withSettings);
        $codes = spec017ProfileCodes($report);
        $untrusted = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::TimeStampUntrusted));
        $context = $probe.($withSettings ? ' with settings' : ' without settings');

        expect($report->result->state->value)->toBe($state, $context)
            ->and($untrusted)->not->toBe([], $context)
            ->and($untrusted[0]->explanation)->toContain('timestamp cert untrusted')
            ->and($untrusted[0]->explanation)->toContain($why)
            ->and(in_array(StatusCode::SigningCredentialInvalid, $codes, true))->toBeFalse($context)
            ->and(in_array(StatusCode::TimeStampTrusted, $codes, true))->toBeFalse($context);
    }
    expect(spec017ProfileOracle($probe, '0.29.0'))->toBe('Trusted')
        ->and(spec017ProfileOracle($probe, '0.29.0--no-settings'))->toBe('Valid')
        ->and(spec017ProfileOracle($probe, '0.28.1'))->toBe('Invalid');   // the oracle moved (step 549)
})->with([
    'no keyUsage' => ['tsa-leaf-no-key-usage', 'no KeyUsage extension'],
    'CA:TRUE' => ['tsa-leaf-ca-true', 'CA'],
    'signed over SHA-1' => ['tsa-leaf-sha1', 'ecdsa-with-SHA1'],
])->group('SPEC-017');

test('SPEC-017 AC14: a version 1 claim gives the same statuses as a version 2 claim', function (): void {
    // amendment 8's version 1 exception is gone with the rule it excepted: the judgement takes no claim
    // version, so a version 1 claim's TSA is judged exactly as a version 2 claim's
    foreach (['judge', 'checkHeader'] as $method) {
        $names = array_map(static fn (ReflectionParameter $p): string => $p->getName(), (new ReflectionMethod(TimestampCheck::class, $method))->getParameters());
        expect($names)->not->toContain('claimVersion');
    }
    $store = Corpus::manifestStore('timestamp/tsa-profile/tsa-leaf-no-key-usage.png');
    assert($store !== null);
    $cose = CoseSign1::ofManifest($store->active);
    $header = TimestampHeader::fromUnprotected($cose->unprotected);
    assert($header !== null);
    $result = (new TimestampCheck)->checkHeader($header, $cose, $store->active->claimBytes(), null, 'self#jumbf=/probe');
    $codes = array_map(static fn (ValidationStatus $s): StatusCode => $s->code, $result->statuses);

    expect($codes)->toContain(StatusCode::TimeStampUntrusted)
        ->and(in_array(StatusCode::SigningCredentialInvalid, $codes, true))->toBeFalse()
        ->and($result->trustedTime())->toBeNull();
})->group('SPEC-017');

test('SPEC-017 AC15: a TSA off the profile does not keep an expired signer valid', function (string $probe): void {
    foreach ([true, false] as $withSettings) {
        $report = spec017ProfileVerify($probe, $withSettings);
        $codes = spec017ProfileCodes($report);
        $context = $probe.($withSettings ? ' with settings' : ' without settings');

        expect($report->result->state->value)->toBe('Invalid', $context)
            ->and($codes)->toContain(StatusCode::SigningCredentialExpired)
            ->and($codes)->toContain(StatusCode::TimeStampUntrusted)
            ->and(in_array(StatusCode::SigningCredentialInvalid, $codes, true))->toBeFalse($context)
            ->and(in_array(StatusCode::TimeStampTrusted, $codes, true))->toBeFalse($context);
    }
    expect(spec017ProfileOracle($probe, '0.29.0'))->toBe('Invalid')
        ->and(spec017ProfileOracle($probe, '0.29.0--no-settings'))->toBe('Invalid');
})->with([
    'openssl ts, CA:TRUE' => ['expired-signer-tsa-leaf-ca-true'],
    'cms, E-mail Protection only' => ['expired-signer-tsa-leaf-eku-email-only'],
])->group('SPEC-017');

test('SPEC-017 AC15: a TSA on the profile still keeps the same expired signer valid', function (): void {
    $stream = fopen(Corpus::fixtures().'/timestamp/tsa-matrix/expired-signer-trusted-tsa.png', 'rb');
    assert($stream !== false);
    $settings = TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/timestamp/tsa-matrix/expired-signer-trusted-tsa.settings.json'));
    $report = (new Verifier)->verify($stream, $settings);

    expect($report->result->state->value)->toBe('Trusted')
        ->and(spec017ProfileCodes($report))->toContain(StatusCode::TimeStampTrusted);
})->group('SPEC-017');
