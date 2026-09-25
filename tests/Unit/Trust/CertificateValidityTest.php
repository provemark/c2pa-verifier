<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\Certificate;
use Provemark\C2paVerifier\Trust\TrustException;
use Provemark\C2paVerifier\Verifier\VerificationReport;

/*
 * SPEC-044: a certificate's validity is read from its own DER, in UTC. The variants come from
 * bin/make-spec044-variants.php under tests/Fixtures/validity/, both c2patool versions' answers under
 * tests/Fixtures/c2patool/validity/. AC5 (php-wasm against native PHP) is measured by hand, in the step note.
 */

const SPEC044_SETTINGS = 'validity/throw-away-root.settings.json';

/**
 * Every certificate in a PEM text, as DER.
 *
 * @return list<string>
 */
function spec044Ders(string $pem): array
{
    preg_match_all('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $m);

    return array_values(array_filter(array_map(static fn (string $body): string => (string) base64_decode(preg_replace('/\s/', '', $body) ?? '', true), $m[1])));
}

function spec044Pem(string $der): string
{
    return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
}

function spec044Leaf(string $variant): Certificate
{
    return Certificate::fromDer(spec044Ders((string) file_get_contents(Corpus::fixtures()."/validity/{$variant}.leaf.pem"))[0]);
}

/** @return list<StatusCode> */
function spec044Codes(VerificationReport $report): array
{
    return array_map(static fn ($status): StatusCode => $status->code, $report->result->statuses);
}

/**
 * The failure codes of both c2patool versions' answers.
 *
 * @return array<string, list<string>>
 */
function spec044OracleFailures(string $variant): array
{
    $out = [];
    foreach (['0.28.0', '0.27.22'] as $version) {
        $json = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/validity/{$variant}--{$version}.json"), true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($json) && is_array($json['validation_results']) && is_array($json['validation_results']['activeManifest']));
        $failures = $json['validation_results']['activeManifest']['failure'] ?? [];
        assert(is_array($failures));
        $out[$version] = array_values(array_map(static fn ($s): string => is_array($s) && is_string($s['code']) ? $s['code'] : '?', $failures));
    }

    return $out;
}

it('AC1: validity does not depend on OpenSSL\'s epoch', function (): void {
    $der = spec044Ders((string) file_get_contents(Corpus::fixtures().'/trust/es256_certs.pem'))[0];
    $parsed = openssl_x509_parse(spec044Pem($der));
    $public = openssl_pkey_get_public(spec044Pem($der));
    assert(is_array($parsed) && $public !== false);
    $details = openssl_pkey_get_details($public);
    assert(is_array($details));
    // what php-wasm returns under Europe/Amsterdam in June: two hours early
    $parsed['validFrom_time_t'] = 1654886800 - 7200;
    $parsed['validTo_time_t'] = 1914000400 - 7200;
    /** @var array<string, mixed> $parsed */
    /** @var array<string, mixed> $details */
    $certificate = Certificate::fromParsed($der, $parsed, $details);

    // openssl x509 -noout -startdate -enddate: Jun 10 18:46:40 2022 GMT, Aug 26 18:46:40 2030 GMT
    expect($certificate->validFrom)->toBe(1654886800)
        ->and($certificate->validTo)->toBe(1914000400);
})->group('SPEC-044');

it('AC2: the same values as OpenSSL on native PHP, for every fixture certificate', function (): void {
    $ders = [];
    $pems = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(Corpus::fixtures(), FilesystemIterator::SKIP_DOTS));
    foreach ($pems as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'pem') {
            foreach (spec044Ders((string) file_get_contents($file->getPathname())) as $der) {
                $ders[$file->getFilename()] = $der;
            }
        }
    }
    foreach (glob(Corpus::fixtures().'/matrix/*.{jpg,png,webp}', GLOB_BRACE) ?: [] as $file) {
        foreach (Corpus::cose('matrix/'.basename($file))->chain ?? [] as $i => $der) {
            $ders[basename($file).'#'.$i] = $der->bytes;
        }
    }
    $compared = 0;
    foreach ($ders as $name => $der) {
        $warned = false;
        set_error_handler(static function () use (&$warned): bool {
            $warned = true;

            return true;
        });
        try {
            $parsed = openssl_x509_parse(spec044Pem($der));
        } finally {
            restore_error_handler();
        }
        // the reference only where PHP's reading can be right: both times DER (YYMMDDHHMMSSZ or YYYYMMDDHHMMSSZ, so
        // no fraction, amendment 1) and no warning. Anything else is AC4's case. How PHP reads `no-seconds` differs
        // by OpenSSL: 3.6 refuses it, Ubuntu 24.04's warns "Unable to parse time string" (CI run 36132220588),
        // php-wasm's 1.1.1t reads it silently
        $isDerTime = static fn (mixed $time): bool => is_string($time) && preg_match('/\A(\d{2}){6,7}Z\z/', $time) === 1;
        if ($warned || ! is_array($parsed) || ! is_int($parsed['validFrom_time_t'] ?? null) || ! $isDerTime($parsed['validFrom'] ?? null) || ! $isDerTime($parsed['validTo'] ?? null)) {
            continue;
        }
        $certificate = Certificate::fromDer($der);
        expect([$name, $certificate->validFrom, $certificate->validTo])->toBe([$name, $parsed['validFrom_time_t'], $parsed['validTo_time_t']]);
        $compared++;
    }

    expect($compared)->toBeGreaterThan(40);
})->group('SPEC-044');

it('AC3: both DER forms are read, up to 9999-12-31T23:59:59Z', function (): void {
    $report = spec020Verify('validity/not-after-9999.png', SPEC044_SETTINGS);

    expect(spec044Leaf('not-after-9999')->validFrom)->toBe(1704067200)   // 240101000000Z, a UTCTime
        ->and(spec044Leaf('not-after-9999')->validTo)->toBe(253402300799)   // 99991231235959Z, a GeneralizedTime
        ->and($report->result->state->value)->toBe('Trusted');
})->group('SPEC-044');

it('AC4: a validity that is not DER time is refused', function (): void {
    $report = spec020Verify('validity/no-seconds.png', SPEC044_SETTINGS);

    // green before the change on OpenSSL 3.6 (amendment 1); it must stay so
    expect($report->result->state->value)->toBe('Invalid')
        ->and(spec044Codes($report))->toContain(StatusCode::SigningCredentialInvalid);
    expect(static fn () => spec044Leaf('no-seconds'))->toThrow(TrustException::class);
})->group('SPEC-044');

it('AC6: an expired certificate with a fraction is expired', function (): void {
    foreach (['expired', 'expired-fraction'] as $variant) {
        $report = spec020Verify("validity/{$variant}.png", SPEC044_SETTINGS);

        expect([$variant, spec044Leaf($variant)->validTo])->toBe([$variant, 1735689600])   // 2025-01-01T00:00:00Z
            ->and([$variant, $report->result->state->value])->toBe([$variant, 'Invalid'])
            ->and(spec044Codes($report))->toContain(StatusCode::SigningCredentialExpired);
        foreach (spec044OracleFailures($variant) as $failures) {
            expect($failures)->toContain('signingCredential.expired');
        }
    }
    // the fraction is dropped, not refused
    expect(spec020Verify('validity/fraction.png', SPEC044_SETTINGS)->result->state->value)->toBe('Trusted')
        ->and(spec044Leaf('fraction')->validTo)->toBe(2524608000);   // 2050-01-01T00:00:00Z
})->group('SPEC-044');
