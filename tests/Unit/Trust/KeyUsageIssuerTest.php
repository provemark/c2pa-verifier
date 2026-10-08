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
 * SPEC-014 AC13 (amendment 7): a certificate that issues must carry keyUsage. The probes come from
 * bin/make-trust-matrix.php under tests/Fixtures/trust/key-usage/; both c2patool versions' answers
 * are under tests/Fixtures/c2patool/key-usage/<probe>--<version>.json.
 */

function spec014KeyUsageSettings(string $probe): TrustSettings
{
    return TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures()."/trust/key-usage/{$probe}.settings.json"));
}

function spec014KeyUsageVerify(string $probe): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/trust/key-usage/{$probe}.png", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$probe}");
    }

    return (new Verifier)->verify($stream, spec014KeyUsageSettings($probe));
}

/** The one signing-credential trust status of a report. */
function spec014KeyUsageTrust(VerificationReport $report): ValidationStatus
{
    $found = array_values(array_filter(
        $report->result->statuses,
        static fn (ValidationStatus $s): bool => in_array($s->code, [StatusCode::SigningCredentialTrusted, StatusCode::SigningCredentialUntrusted], true),
    ));
    expect($found)->toHaveCount(1);

    return $found[0];
}

function spec014KeyUsageOracleState(string $probe, string $version): string
{
    /** @var array{validation_state: string} $json */
    $json = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/key-usage/{$probe}--{$version}.json"), true, 512, JSON_THROW_ON_ERROR);

    return $json['validation_state'];
}

it('AC13: a chain whose CAs carry keyCertSign still leads to Trusted', function (): void {
    $report = spec014KeyUsageVerify('control');

    expect($report->result->state->value)->toBe('Trusted')
        ->and(spec014KeyUsageTrust($report)->code)->toBe(StatusCode::SigningCredentialTrusted);
    foreach (['0.27.22', '0.28.1'] as $version) {
        expect(spec014KeyUsageOracleState('control', $version))->toBe('Trusted', $version);
    }
})->group('SPEC-014');

it('AC13: a certificate authority without keyUsage issues nothing', function (string $probe, string $who): void {
    $report = spec014KeyUsageVerify($probe);
    $trust = spec014KeyUsageTrust($report);

    expect($trust->code)->toBe(StatusCode::SigningCredentialUntrusted, $trust->explanation)
        ->and($report->result->state->value)->toBe('Valid')
        ->and($trust->explanation)->toContain($who)
        ->and($trust->explanation)->toContain('keyUsage');
    foreach (['0.27.22', '0.28.1'] as $version) {
        expect(spec014KeyUsageOracleState($probe, $version))->toBe('Valid', $version);
    }
})->with([
    'an intermediate' => ['int-no-key-usage', 'Matrix Intermediate (int-no-key-usage)'],
    'the anchor' => ['anchor-no-key-usage', 'Matrix Anchor (anchor-no-key-usage)'],
])->group('SPEC-014');

it('AC13: the walk the timestamp check shares refuses the same chain', function (): void {
    $store = Corpus::manifestStore('trust/key-usage/int-no-key-usage.png');
    assert($store !== null);
    $chain = array_map(static fn ($c): Certificate => Certificate::fromDer($c->bytes), CoseSign1::fromBytes($store->active->signatureBytes())->chain);
    assert($chain !== []);

    // the legacy single string anchors timestamp authorities as well as signers
    $statuses = (new ChainCheck)->checkCertificates($chain, spec014KeyUsageSettings('int-no-key-usage'), 'self#jumbf=/probe');

    expect(array_map(static fn (ValidationStatus $s): StatusCode => $s->code, $statuses))
        ->toBe([StatusCode::SigningCredentialUntrusted]);
})->group('SPEC-014');
