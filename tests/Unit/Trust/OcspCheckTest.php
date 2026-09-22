<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\Certificate;
use Provemark\C2paVerifier\Trust\OcspCheck;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-030: the OCSP responses a signer staples into its own signature, read
 * without a network.
 *
 * Everything here rests on one measurement, made in step 91 before the spec was
 * written: `rVals` sits in the COSE **unprotected** bucket. It is not covered by
 * the signature, so anyone holding the file can add one, alter it or strip it.
 * Hence the asymmetry these tests assert from every side: a stapled response may
 * lower trust and never raise it, and may never fail a file it cannot prove
 * anything about.
 *
 * `ocsp.jpg` is both the happy path and the stale path. Its response was fresh on
 * 2025-08-13, the moment its Adobe timestamp attests, and expired on 2025-08-18.
 * Which of the two a run gets depends on the trust anchor, not on the bytes:
 * with `full-plus-digicert-g4.settings.json` the timestamp is trusted and the
 * judged time is 2025-08-13 (AC1); without it there is no trusted timestamp, the
 * judged time is now, and the same response is stale (AC6). SPEC-030 amendment 1.
 *
 * The fixtures under tests/Fixtures/ocsp/ are built by bin/make-ocsp-variants.php
 * because no public file carries a `revoked` stapled response. They are DER
 * responses and **public certificates**; the keys that signed them were shredded
 * by the script that made them, as this repository holds no private key.
 */

function spec030Fixtures(): string
{
    return Corpus::fixtures().'/ocsp';
}

/** @return resource */
function spec030Stream(string $relative = 'c2pa-rs/ocsp.jpg', ?string $bytes = null)
{
    if ($bytes !== null) {
        $memory = fopen('php://memory', 'r+b');
        if ($memory === false) {
            throw new RuntimeException('cannot open php://memory');
        }
        fwrite($memory, $bytes);
        rewind($memory);

        return $memory;
    }
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }

    return $stream;
}

function spec030Verify(?string $settings = null, string $relative = 'c2pa-rs/ocsp.jpg', ?string $bytes = null): VerificationReport
{
    $trust = $settings === null
        ? null
        : TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/trust/'.$settings));

    return (new Verifier)->verify(spec030Stream($relative, $bytes), $trust);
}

/** @return list<string> */
function spec030Codes(VerificationReport $report): array
{
    return array_map(static fn (ValidationStatus $s): string => $s->code->value, $report->result->statuses);
}

function spec030Explanations(VerificationReport $report): string
{
    return implode(' | ', array_map(static fn (ValidationStatus $s): string => $s->explanation, $report->result->statuses));
}

/** The DER of the one OCSP response `ocsp.jpg` staples, read through this verifier's own parser. */
function spec030StapledDer(): string
{
    $bytes = (new JpegManifestStoreExtractor)->extract(spec030Stream());
    if ($bytes === null) {
        throw new RuntimeException('no store in ocsp.jpg');
    }
    $store = ManifestStore::fromTree((new JumbfParser)->parse($bytes->bytes));
    $cose = CoseSign1::fromBytes($store->active->signatureBytes());
    $rVals = $cose->otherHeaders['rVals'] ?? null;
    if (! is_array($rVals) || ! is_array($rVals['ocspVals'] ?? null)) {
        throw new RuntimeException('ocsp.jpg carries no rVals.ocspVals');
    }
    $first = $rVals['ocspVals'][0];
    if (! $first instanceof CborBytes) {
        throw new RuntimeException('the stapled response is not a byte string');
    }

    return $first->bytes;
}

/** A DER certificate from one of the PEM fixtures — public certificates, never keys. */
function spec030Certificate(string $name): Certificate
{
    $pem = (string) file_get_contents(spec030Fixtures().'/'.$name.'.crt');
    if (str_contains($pem, 'PRIVATE KEY')) {
        throw new RuntimeException("{$name}.crt holds a private key; this repository must hold none");
    }
    $body = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem) ?? '';

    return Certificate::fromDer((string) base64_decode($body, true));
}

/**
 * The header shape a signer staples: a map with `ocspVals`, a list of byte strings.
 *
 * @param  list<string>  $ders
 * @return array<string, mixed>
 */
function spec030Header(array $ders): array
{
    return ['rVals' => ['ocspVals' => array_map(static fn (string $d): CborBytes => new CborBytes($d), $ders)]];
}

/**
 * The check at its seam: one status, always.
 *
 * @param  list<string>  $ders
 * @param  list<Certificate>  $chain
 */
function spec030Check(array $ders, array $chain, ?int $at = null): ValidationStatus
{
    $statuses = (new OcspCheck)->check(spec030Header($ders), $chain, $at, 'self#jumbf=/c2pa/test/c2pa.signature');
    expect($statuses)->toHaveCount(1);

    return $statuses[0];
}

/** @return list<Certificate> the throw-away chain the DER fixtures were issued under */
function spec030Chain(string $leaf = 'signer'): array
{
    return [spec030Certificate($leaf), spec030Certificate('ca')];
}

function spec030Der(string $name): string
{
    return (string) file_get_contents(spec030Fixtures().'/'.$name.'.der');
}

it('AC1: a stapled good response is read and reported, and changes no verdict', function (): void {
    $report = spec030Verify('full-plus-digicert-g4.settings.json');

    // the anchor makes the Adobe timestamp trusted, so the judged time is the
    // attested 2025-08-13 and the response (11–18 August 2025) is fresh at it
    expect($report->result->state)->toBe(ValidationState::Valid)
        ->and(in_array(StatusCode::SigningCredentialOcspNotRevoked->value, spec030Codes($report), true))->toBeTrue(implode(' | ', spec030Codes($report)));

    $explanations = spec030Explanations($report);
    foreach (['Adobe', '2025-08-11', 'unsigned'] as $needle) {
        // not toContain($needle, $message): Pest reads the second argument as
        // another needle rather than a message — twelve times in this project now
        expect(str_contains($explanations, $needle))->toBeTrue("{$needle} missing from: {$explanations}");
    }
})->group('SPEC-030');

it('AC2: a response whose signature does not verify is skipped, not believed and not fatal', function (): void {
    $der = spec030StapledDer();
    $bytes = (string) file_get_contents(Corpus::fixtures().'/c2pa-rs/ocsp.jpg');
    $at = strpos($bytes, $der);
    expect($at)->toBeInt();

    // one byte inside the stapled response. Which byte hardly matters: a flip in
    // tbsResponseData breaks the message, one in the signature breaks the signature
    $bytes[(int) $at + 200] = chr(ord($bytes[(int) $at + 200]) ^ 0xFF);

    $report = spec030Verify('full-plus-digicert-g4.settings.json', bytes: $bytes);
    $codes = spec030Codes($report);

    // the header is unsigned, so editing it must not be able to fail a valid asset
    expect($report->result->state)->toBe(ValidationState::Valid)
        ->and(in_array(StatusCode::SigningCredentialOcspSkipped->value, $codes, true))->toBeTrue(implode(' | ', $codes))
        ->and(in_array(StatusCode::SigningCredentialOcspNotRevoked->value, $codes, true))->toBeFalse(implode(' | ', $codes));
})->group('SPEC-030');

it('AC3: a verified revoked response is a failure', function (): void {
    $status = spec030Check([spec030Der('revoked')], spec030Chain());

    expect($status->code)->toBe(StatusCode::SigningCredentialOcspRevoked)
        ->and($status->code->isFailure())->toBeTrue();

    // the serial as this verifier renders it everywhere: decimal, not openssl's hex
    foreach (['serial 1', 'keyCompromise'] as $needle) {
        expect(str_contains($status->explanation, $needle))->toBeTrue("{$needle} missing from: {$status->explanation}");
    }

    // and the same response offered for another file's chain proves the two
    // fixtures are not accidentally interchangeable
    $bytes = (new JpegManifestStoreExtractor)->extract(spec030Stream());
    if ($bytes === null) {
        throw new RuntimeException('no store in ocsp.jpg');
    }
    $store = ManifestStore::fromTree((new JumbfParser)->parse($bytes->bytes));
    $chain = array_map(static fn (object $c): Certificate => Certificate::fromDer($c->bytes), CoseSign1::fromBytes($store->active->signatureBytes())->chain);

    expect(spec030Check([spec030Der('revoked')], $chain)->code)->toBe(StatusCode::SigningCredentialOcspSkipped);
})->group('SPEC-030');

it('AC4: a response for another certificate is not applied', function (): void {
    $status = spec030Check([spec030Der('other-good')], spec030Chain());

    expect($status->code)->toBe(StatusCode::SigningCredentialOcspSkipped)
        ->and(str_contains(strtolower($status->explanation), 'certificate'))->toBeTrue($status->explanation);
})->group('SPEC-030');

it('AC5: a file without rVals says what was not checked', function (): void {
    $report = spec030Verify(relative: 'fixture-signed.jpg');
    $codes = spec030Codes($report);
    $skipped = array_filter($codes, static fn (string $c): bool => $c === StatusCode::SigningCredentialOcspSkipped->value);

    expect(count($skipped))->toBe(1, implode(' | ', $codes))
        ->and(in_array('revocation', $report->result->checksPerformed, true))->toBeTrue(implode(', ', $report->result->checksPerformed));
})->group('SPEC-030');

it('AC6: a stale good is not evidence', function (): void {
    // the same file as AC1, with no anchor: no trusted timestamp, so the judged
    // time is now and the response expired on 2025-08-18
    $report = spec030Verify();
    $codes = spec030Codes($report);

    expect(in_array(StatusCode::SigningCredentialOcspSkipped->value, $codes, true))->toBeTrue(implode(' | ', $codes))
        ->and(in_array(StatusCode::SigningCredentialOcspNotRevoked->value, $codes, true))->toBeFalse(implode(' | ', $codes));

    $explanations = spec030Explanations($report);
    expect(str_contains($explanations, '2025-08-18'))->toBeTrue($explanations);
})->group('SPEC-030');

it('AC7: malformed input is skipped by name, never an exception', function (): void {
    $good = spec030Der('good');
    $cases = [
        'rVals is not a map' => ['rVals' => 'everything'],
        'ocspVals is not a list' => ['rVals' => ['ocspVals' => 'one']],
        'an entry is not a byte string' => ['rVals' => ['ocspVals' => ['not bytes']]],
        'not DER at all' => spec030Header(["\x00\x01\x02\x03"]),
        'truncated' => spec030Header([substr($good, 0, 40)]),
        // DER that parses and is not an OCSPResponse: a certificate, which begins
        // with a SEQUENCE where an OCSPResponseStatus should be
        'well-formed DER, wrong structure' => spec030Header([spec030Certificate('ca')->der]),
    ];

    foreach ($cases as $name => $header) {
        /** @var array<string, mixed> $header */
        $statuses = (new OcspCheck)->check($header, spec030Chain(), null, 'self#jumbf=/c2pa/test/c2pa.signature');

        expect($statuses)->toHaveCount(1, $name)
            ->and($statuses[0]->code)->toBe(StatusCode::SigningCredentialOcspSkipped, $name)
            ->and($statuses[0]->code->isFailure())->toBeFalse($name);
    }
})->group('SPEC-030');

it('AC8: removeFromCRL is not a revocation', function (): void {
    $status = spec030Check([spec030Der('removed')], spec030Chain());

    // RFC 6960 §4.2.1: that reason is a re-instatement, and treating it as a
    // revocation would fail a file wrongly
    expect($status->code)->not->toBe(StatusCode::SigningCredentialOcspRevoked)
        ->and($status->code->isFailure())->toBeFalse($status->explanation);
})->group('SPEC-030');

it('AC9: nothing that passed stops passing', function (): void {
    // the twelve verdicts as they stand the moment before this spec is implemented,
    // measured in step 92a. A stapled response may add an informational line; if any
    // of these twelve moves, it changed a verdict and that is exactly what SPEC-030
    // promises never to do. ocsp.jpg is Invalid without an anchor because its Adobe
    // TSA reaches none, so its 2025 signer is judged at now — the divergence from
    // c2patool that step 87b measured on video1.mp4, on a second file.
    $baseline = [
        ['fixture-signed.jpg', null, 'Valid'],
        ['fixture-signed.jpg', 'full-plus-digicert-g4.settings.json', 'Trusted'],
        ['fixture-signed.png', null, 'Valid'],
        ['fixture-signed.png', 'full-plus-digicert-g4.settings.json', 'Trusted'],
        ['fixture-signed.webp', null, 'Valid'],
        ['fixture-signed.webp', 'full-plus-digicert-g4.settings.json', 'Trusted'],
        ['fixture-signed.mp4', null, 'Valid'],
        ['fixture-signed.mp4', 'full-plus-digicert-g4.settings.json', 'Trusted'],
        ['c2pa-rs/ocsp.jpg', null, 'Invalid'],
        ['c2pa-rs/ocsp.jpg', 'full-plus-digicert-g4.settings.json', 'Valid'],
        ['c2pa-rs/ocsp_with_assertion.jpg', null, 'Invalid'],
        ['c2pa-rs/ocsp_with_assertion.jpg', 'full-plus-digicert-g4.settings.json', 'Valid'],
    ];

    foreach ($baseline as [$file, $settings, $state]) {
        $report = spec030Verify($settings, $file);
        $where = $file.' '.($settings ?? 'no settings');

        expect($report->result->state->value)->toBe($state, $where);

        foreach ($report->result->statuses as $status) {
            if (str_starts_with($status->code->value, 'signingCredential.ocsp')) {
                // a stapled response may lower trust only when it proves something,
                // and nothing here carries a verified revocation
                expect($status->code->isFailure())->toBeFalse("{$where}: {$status->code->value} — {$status->explanation}");
            }
        }
    }
})->group('SPEC-030');

it('AC10: bounded, like every other parser here', function (): void {
    $good = spec030Der('good');

    $tooMany = array_fill(0, OcspCheck::DEFAULT_MAX_RESPONSES + 1, $good);
    expect(spec030Check($tooMany, spec030Chain())->code)->toBe(StatusCode::SigningCredentialOcspSkipped);

    $tooBig = $good.str_repeat("\0", OcspCheck::DEFAULT_MAX_RESPONSE_BYTES);
    $status = spec030Check([$tooBig], spec030Chain());
    expect($status->code)->toBe(StatusCode::SigningCredentialOcspSkipped)
        ->and(str_contains($status->explanation, (string) OcspCheck::DEFAULT_MAX_RESPONSE_BYTES))->toBeTrue($status->explanation);
})->group('SPEC-030');
