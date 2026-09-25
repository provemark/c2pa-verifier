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
 * SPEC-014 AC11 (amendment 4): only a certificate authority may issue. The probes come from
 * bin/make-issuer-variants.php under tests/Fixtures/trust/issuer/; both c2patool versions' answers
 * are under tests/Fixtures/c2patool/issuer/<probe>--<settings>--<version>.json.
 */

function spec014IssuerSettings(string $name): TrustSettings
{
    return TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures()."/trust/issuer/{$name}.settings.json"));
}

function spec014IssuerVerify(string $probe, string $settings): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/trust/issuer/{$probe}.jpg", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$probe}");
    }

    return (new Verifier)->verify($stream, spec014IssuerSettings($settings));
}

/** The one signing-credential trust status of a report. */
function spec014IssuerTrust(VerificationReport $report): ValidationStatus
{
    $found = array_values(array_filter(
        $report->result->statuses,
        static fn (ValidationStatus $s): bool => in_array($s->code, [StatusCode::SigningCredentialTrusted, StatusCode::SigningCredentialUntrusted], true),
    ));
    expect($found)->toHaveCount(1);

    return $found[0];
}

/** @return array<string, mixed> */
function spec014IssuerOracle(string $probe, string $settings, string $version): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/issuer/{$probe}--{$settings}--{$version}.json"), true, 512, JSON_THROW_ON_ERROR);
}

it('AC11: a proper intermediate still leads to Trusted', function (): void {
    $report = spec014IssuerVerify('good-chain', 'probe-root');

    expect($report->result->state->value)->toBe('Trusted')
        ->and(spec014IssuerTrust($report)->code)->toBe(StatusCode::SigningCredentialTrusted);
    foreach (['0.27.22', '0.28.0'] as $version) {
        expect(spec014IssuerOracle('good-chain', 'probe-root', $version)['validation_state'])->toBe('Trusted', $version);
    }
})->group('SPEC-014');

it('AC11: a certificate that may not issue breaks the chain', function (string $probe, string $settings, string $rule): void {
    $report = spec014IssuerVerify($probe, $settings);
    $trust = spec014IssuerTrust($report);

    expect($trust->code)->toBe(StatusCode::SigningCredentialUntrusted, $trust->explanation)
        ->and($report->result->state->value)->toBe('Valid')
        ->and($trust->explanation)->toContain($rule);
    expect(spec014IssuerOracle($probe, $settings, '0.28.0')['validation_state'])->toBe('Valid');
})->with([
    'an end-entity certificate as issuer' => ['ee-as-issuer', 'probe-root', 'not a certificate authority'],
    'a CA without keyCertSign' => ['ca-without-keycertsign', 'probe-root', 'keyCertSign'],
    'a path longer than pathlen allows' => ['pathlen-exceeded', 'probe-root', 'path length'],
    'an expired intermediate' => ['expired-intermediate', 'probe-root', 'not valid'],
    'an end-entity certificate as the anchor' => ['ee-as-issuer', 'honest-as-anchor', 'not a certificate authority'],
])->group('SPEC-014');

it('AC11: the walk the timestamp check shares refuses the same chain', function (): void {
    $store = Corpus::manifestStore('trust/issuer/ee-as-issuer.jpg');
    assert($store !== null);
    $cose = CoseSign1::fromBytes($store->active->signatureBytes());
    $chain = array_map(static fn ($c): Certificate => Certificate::fromDer($c->bytes), $cose->chain);
    assert($chain !== []);

    // the legacy single string anchors timestamp authorities as well as signers
    $statuses = (new ChainCheck)->checkCertificates($chain, spec014IssuerSettings('probe-root'), 'self#jumbf=/probe');

    expect(array_map(static fn (ValidationStatus $s): StatusCode => $s->code, $statuses))
        ->toBe([StatusCode::SigningCredentialUntrusted]);
})->group('SPEC-014');
