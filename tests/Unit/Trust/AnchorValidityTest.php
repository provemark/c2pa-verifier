<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\Certificate;
use Provemark\C2paVerifier\Trust\ChainCheck;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-014 AC12 (amendment 5): an anchor outside its validity vouches for no one. The probes come
 * from bin/make-anchor-variants.php under tests/Fixtures/trust/anchor/; both c2patool versions'
 * answers are under tests/Fixtures/c2patool/anchor/<probe>--<settings>--<version>.json.
 */

function spec014AnchorSettings(string $name): TrustSettings
{
    return TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures()."/trust/anchor/{$name}.settings.json"));
}

function spec014AnchorVerify(string $probe, string $settings): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/trust/anchor/{$probe}.jpg", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$probe}");
    }

    return (new Verifier)->verify($stream, spec014AnchorSettings($settings));
}

/** The one signing-credential trust status of a report. */
function spec014AnchorTrust(VerificationReport $report): ValidationStatus
{
    $found = array_values(array_filter(
        $report->result->statuses,
        static fn (ValidationStatus $s): bool => in_array($s->code, [StatusCode::SigningCredentialTrusted, StatusCode::SigningCredentialUntrusted], true),
    ));
    expect($found)->toHaveCount(1);

    return $found[0];
}

function spec014AnchorOracleState(string $probe, string $settings, string $version): string
{
    /** @var array{validation_state: string} $json */
    $json = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/anchor/{$probe}--{$settings}--{$version}.json"), true, 512, JSON_THROW_ON_ERROR);

    return $json['validation_state'];
}

/** @return non-empty-list<Certificate> the x5chain of a probe, leaf first */
function spec014AnchorChain(string $probe): array
{
    $store = Corpus::manifestStore("trust/anchor/{$probe}.jpg");
    assert($store !== null);
    $chain = array_map(static fn ($c): Certificate => Certificate::fromDer($c->bytes), CoseSign1::fromBytes($store->active->signatureBytes())->chain);
    assert($chain !== []);

    return $chain;
}

/**
 * @param  list<ValidationStatus>  $statuses
 * @return list<StatusCode>
 */
function spec014AnchorCodes(array $statuses): array
{
    return array_map(static fn (ValidationStatus $s): StatusCode => $s->code, $statuses);
}

it('AC12: an anchor valid now still leads to Trusted', function (): void {
    $report = spec014AnchorVerify('control', 'root-valid');

    expect($report->result->state->value)->toBe('Trusted')
        ->and(spec014AnchorTrust($report)->code)->toBe(StatusCode::SigningCredentialTrusted);
    foreach (['0.27.22', '0.28.1'] as $version) {
        expect(spec014AnchorOracleState('control', 'root-valid', $version))->toBe('Trusted', $version);
    }
})->group('SPEC-014');

it('AC12: an anchor outside its validity breaks the chain', function (string $probe, string $settings, string $anchor): void {
    $report = spec014AnchorVerify($probe, $settings);
    $trust = spec014AnchorTrust($report);

    expect($trust->code)->toBe(StatusCode::SigningCredentialUntrusted, $trust->explanation)
        ->and($report->result->state->value)->toBe('Valid')
        ->and($trust->explanation)->toContain($anchor)
        ->and($trust->explanation)->toContain('not valid at')
        ->and($trust->explanation)->toContain('valid from');
    expect(spec014AnchorOracleState($probe, $settings, '0.28.1'))->toBe('Valid')
        ->and(spec014AnchorOracleState($probe, $settings, '0.27.22'))->toBe('Trusted');
})->with([
    'a root that expired' => ['expired-anchor', 'root-expired', 'SPEC-014 Anchor Probe Root Expired'],
    'the leaf directly under it' => ['expired-anchor-direct', 'root-expired', 'SPEC-014 Anchor Probe Root Expired'],
    'the expired root also in x5chain' => ['expired-anchor-in-x5chain', 'root-expired', 'SPEC-014 Anchor Probe Root Expired'],
    'a root not yet valid' => ['future-anchor', 'root-future', 'SPEC-014 Anchor Probe Root Future'],
    'an expired intermediate as the anchor' => ['expired-int-as-anchor', 'int-expired', 'SPEC-014 Expired Intermediate Anchor'],
])->group('SPEC-014');

it('AC12: the same expired intermediate under a valid root stays untrusted', function (): void {
    $report = spec014AnchorVerify('expired-int-as-anchor', 'root-valid');

    expect(spec014AnchorTrust($report)->code)->toBe(StatusCode::SigningCredentialUntrusted)
        ->and($report->result->state->value)->toBe('Valid')
        ->and(spec014AnchorOracleState('expired-int-as-anchor', 'root-valid', '0.28.1'))->toBe('Valid');
})->group('SPEC-014');

it('AC12: the anchor is judged at the time it is given, and the timestamp walk refuses it at now', function (): void {
    $chain = spec014AnchorChain('expired-anchor-direct');   // the leaf alone: no intermediate's validity enters
    $settings = spec014AnchorSettings('root-expired');   // the legacy string anchors timestamp authorities too

    $inside = (new ChainCheck)->checkCertificates($chain, $settings, 'self#jumbf=/probe', (int) gmmktime(0, 0, 0, 6, 1, 2020));
    $now = (new ChainCheck)->checkCertificates($chain, $settings, 'self#jumbf=/probe');

    expect(spec014AnchorCodes($inside))->toBe([StatusCode::SigningCredentialTrusted])
        ->and(spec014AnchorCodes($now))->toBe([StatusCode::SigningCredentialUntrusted]);
})->group('SPEC-014');
