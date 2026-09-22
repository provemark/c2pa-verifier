<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationResult;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Trust\Certificate;
use Provemark\C2paVerifier\Trust\TrustException;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;
use Provemark\ContentCredentials\Core\Reading\ManifestStoreParser;

/*
 * SPEC-014: trust — the settings read, the allowed list, the chain walk to
 * an anchor; Trusted. The oracle is tests/Fixtures/c2patool/trusted/
 * (steps 30 and 31, c2patool 0.27.22 under tests/Fixtures/trust/*.settings.json)
 * and, as ADR-0003's second oracle, openssl_x509_checkpurpose() on the same
 * anchors written to a temporary file.
 */

const SPEC014_PNG_SIGNATURE = 'self#jumbf=/c2pa/urn:c2pa:488bf983-c973-465d-a0eb-1597392cc5d0/c2pa.signature';

function spec014Fixtures(): string
{
    return dirname(__DIR__, 2).'/Fixtures';
}

function spec014Settings(string $variant): TrustSettings
{
    return TrustSettings::fromJson((string) file_get_contents(spec014Fixtures()."/trust/{$variant}.settings.json"));
}

/** @return resource */
function spec014Stream(string $relative)
{
    $stream = fopen(spec014Fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }

    return $stream;
}

function spec014Verify(string $relative, ?TrustSettings $settings): VerificationReport
{
    return (new Verifier)->verify(spec014Stream($relative), $settings);
}

/** @return array<string, mixed> */
function spec014Oracle(string $name): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(spec014Fixtures()."/c2patool/trusted/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $oracle
 * @return list<string> the codes of c2patool's validation_status (absent = none), sorted
 */
function spec014OracleFailures(array $oracle): array
{
    $codes = [];
    $list = $oracle['validation_status'] ?? [];
    assert(is_array($list));
    foreach ($list as $status) {
        assert(is_array($status) && is_string($status['code']));
        $codes[] = $status['code'];
    }
    sort($codes);

    return $codes;
}

/** @param  array<string, mixed>  $oracle */
function spec014OracleUrl(array $oracle, string $code): ?string
{
    $results = $oracle['validation_results'];
    assert(is_array($results) && is_array($results['activeManifest']));
    foreach ($results['activeManifest'] as $list) {
        assert(is_array($list));
        foreach ($list as $status) {
            assert(is_array($status) && is_string($status['code']) && is_string($status['url']));
            if ($status['code'] === $code) {
                return $status['url'];
            }
        }
    }

    return null;
}

/** @return list<ValidationStatus> */
function spec014Credential(VerificationReport $report): array
{
    return array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => str_starts_with($s->code->value, 'signingCredential')));
}

/** @return list<string> sorted failure codes */
function spec014Failures(VerificationReport $report): array
{
    $codes = [];
    foreach ($report->result->statuses as $status) {
        if ($status->code->isFailure()) {
            $codes[] = $status->code->value;
        }
    }
    sort($codes);

    return $codes;
}

/** @return list<Certificate> */
function spec014Certificates(string $pemFile): array
{
    return TrustSettings::certificatesFromPem((string) file_get_contents(spec014Fixtures().'/trust/'.$pemFile), $pemFile, 256);
}

function spec014Pem(Certificate $certificate): string
{
    return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($certificate->der), 64, "\n")."-----END CERTIFICATE-----\n";
}

it('AC1: the four fixtures with the full settings: Trusted, and the words are c2patool\'s', function (): void {
    $full = spec014Settings('full');
    foreach (['jpg' => 'fixture-signed.jpg', 'png' => 'fixture-signed.png', 'webp' => 'fixture-signed.webp', 'adobe-20220124-C' => 'public-testfiles/adobe-20220124-C.jpg'] as $name => $fixture) {
        $report = spec014Verify($fixture, $full);
        $credential = spec014Credential($report);
        // the Adobe file carries a timestamp: SPEC-017 puts `timestamp` first (amendment 2)
        expect($report->result->checksPerformed)->toBe([...($name === 'adobe-20220124-C' ? ['timestamp'] : []), 'signature', 'certificate', 'trust', 'hashedUris', 'actions', 'dataHash'], $name)
            ->and($credential)->toHaveCount(1, $name)
            ->and($credential[0]->code)->toBe(StatusCode::SigningCredentialTrusted, $name)
            ->and($credential[0]->url)->toBe("self#jumbf=/c2pa/{$report->store?->active->label}/c2pa.signature", $name)
            ->and(spec014Failures($report))->toBe([], $name)
            ->and($report->result->state)->toBe(ValidationState::Trusted, $name);

        $oracle = spec014Oracle($name);
        expect($oracle['validation_state'])->toBe('Trusted', $name)
            ->and(spec014OracleUrl($oracle, 'signingCredential.trusted'))->toBe($credential[0]->url, $name)
            ->and($oracle)->not->toHaveKey('validation_status')
            ->and($report->toArray())->not->toHaveKey('validation_status');

        $sister = ManifestStoreParser::fromJson($report->toJson());
        expect($sister->isTrusted())->toBeTrue($name)
            ->and($sister->validationState()?->value)->toBe('Trusted', $name);
    }
})->group('SPEC-014');

it('AC2: the wrong root: untrusted, and the state is Valid, not Invalid', function (): void {
    foreach ([
        ['fixture-signed.png', 'rsa-root-only', 'png-untrusted-rsa-root-only', 'Intermediate CA'],
        ['public-testfiles/adobe-20220124-C.jpg', 'ec-root-only', 'adobe-untrusted-ec-root-only', 'Root CA'],
    ] as [$fixture, $variant, $oracleName, $cn]) {
        $report = spec014Verify($fixture, spec014Settings($variant));
        $credential = spec014Credential($report);
        expect(spec014Failures($report))->toBe(['signingCredential.untrusted'], $variant)
            ->and($credential)->toHaveCount(1, $variant)
            ->and($credential[0]->url)->toEndWith('/c2pa.signature', $variant)
            ->and($credential[0]->explanation)->toContain($cn)
            ->and($report->result->state)->toBe(ValidationState::Valid, $variant);

        $oracle = spec014Oracle($oracleName);
        expect($oracle['validation_state'])->toBe('Valid', $variant)
            ->and(spec014OracleFailures($oracle))->toBe(['signingCredential.untrusted'], $variant);

        $array = $report->toArray();
        assert(is_array($array['validation_status']));
        expect($array['validation_status'])->toHaveCount(1, $variant);
        $sister = ManifestStoreParser::fromJson($report->toJson());
        expect($sister->isTrusted())->toBeFalse($variant)
            ->and($sister->validationState()?->value)->toBe('Valid', $variant);
    }
})->group('SPEC-014');

it('AC3: the allowed list: trusted without a chain', function (): void {
    foreach (['allowed-only', 'allowed-plus-wrong-root'] as $variant) {
        $report = spec014Verify('fixture-signed.png', spec014Settings($variant));
        $credential = spec014Credential($report);
        expect($credential)->toHaveCount(1, $variant)
            ->and($credential[0]->code)->toBe(StatusCode::SigningCredentialTrusted, $variant)
            ->and($credential[0]->explanation)->toContain('allowed list')
            ->and($report->result->state)->toBe(ValidationState::Trusted, $variant)
            ->and(spec014Oracle("png-{$variant}")['validation_state'])->toBe('Trusted', $variant);
    }
    // the second: the RSA root alone would refuse the EC chain — the allowed list is tried first
    $settings = spec014Settings('allowed-plus-wrong-root');
    expect($settings->allowedList)->toHaveCount(3)
        ->and($settings->trustAnchors)->toHaveCount(1)
        ->and($settings->trustAnchors[0]->subjectCn())->toBe('Root CA');
})->group('SPEC-014');

it('AC4: the walk needs the intermediate the chain carries, and an intermediate may be the anchor', function (): void {
    $leafOnly = spec014Verify('binding/x5chain-leaf-only.png', spec014Settings('full'));
    $credential = spec014Credential($leafOnly);
    expect(spec014Failures($leafOnly))->toBe(['claimSignature.mismatch', 'signingCredential.untrusted'])
        ->and($credential[0]->code)->toBe(StatusCode::SigningCredentialUntrusted)
        ->and($credential[0]->explanation)->toContain('C2PA Signer')
        ->and($credential[0]->explanation)->toContain('Intermediate CA')
        ->and($leafOnly->result->state)->toBe(ValidationState::Invalid)
        ->and(spec014OracleFailures(spec014Oracle('x5chain-leaf-only')))->toBe(['claimSignature.mismatch', 'signingCredential.untrusted']);

    $viaIntermediate = spec014Verify('fixture-signed.png', spec014Settings('intermediate-anchor'));
    $credential = spec014Credential($viaIntermediate);
    expect($credential[0]->code)->toBe(StatusCode::SigningCredentialTrusted)
        ->and($credential[0]->explanation)->toContain('Intermediate CA')
        ->and($credential[0]->explanation)->toContain('depth 1')
        ->and($viaIntermediate->result->state)->toBe(ValidationState::Trusted)
        ->and(spec014Oracle('png-intermediate-anchor')['validation_state'])->toBe('Trusted');
})->group('SPEC-014');

it('AC5: no trust by name', function (): void {
    [$ecRoot, $rsaRoot] = spec014Certificates('trust_anchors.pem');
    [, $intermediate] = spec014Certificates('es256_certs.pem');
    // the two roots carry the same subject, byte for byte as OpenSSL renders it
    expect($rsaRoot->subject)->toBe($ecRoot->subject)
        ->and($intermediate->issuer)->toBe($rsaRoot->subject)
        ->and($intermediate->signedBy($ecRoot))->toBeTrue()
        ->and($intermediate->signedBy($rsaRoot))->toBeFalse()
        ->and($rsaRoot->sameAs($ecRoot))->toBeFalse();

    $report = spec014Verify('fixture-signed.png', spec014Settings('rsa-root-only'));
    $credential = spec014Credential($report);
    expect($credential[0]->code)->toBe(StatusCode::SigningCredentialUntrusted)
        ->and($credential[0]->explanation)->toContain('name')
        ->and($credential[0]->explanation)->toContain('signature');
})->group('SPEC-014');

it('AC6: verify_trust off: no credential code at all', function (): void {
    $off = spec014Verify('fixture-signed.png', spec014Settings('verify-off'));
    expect(spec014Credential($off))->toBe([])
        ->and($off->result->checksPerformed)->toBe(['signature', 'certificate', 'hashedUris', 'actions', 'dataHash'])
        ->and($off->result->state)->toBe(ValidationState::Valid)
        ->and(spec014Oracle('png-verify-off')['validation_state'])->toBe('Valid');

    // no settings is not verify_trust off: with no anchors the leaf is untrusted, as c2patool says (SPEC-014 amendment 1)
    $none = spec014Verify('fixture-signed.png', null);
    expect(spec014Credential($none))->toHaveCount(1)
        ->and(spec014Credential($none)[0]->code)->toBe(StatusCode::SigningCredentialUntrusted)
        ->and(spec014Credential($none)[0]->explanation)->toContain('no trust anchors')
        ->and($none->result->checksPerformed)->toContain('trust')
        ->and($none->result->state)->toBe(ValidationState::Valid);
})->group('SPEC-014');

it('AC7: the settings are whole or absent', function (): void {
    $full = (string) file_get_contents(spec014Fixtures().'/trust/full.settings.json');
    /** @var array{trust: array{trust_anchors: string, trust_config: string}, verify: array{verify_trust: bool}} $base */
    $base = json_decode($full, true, 512, JSON_THROW_ON_ERROR);
    $leaf = spec014Pem(spec014Certificates('es256_certs.pem')[0]);

    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    assert($key !== false);
    $privatePem = '';
    openssl_pkey_export($key, $privatePem);
    assert(is_string($privatePem));
    if (preg_match('/-----BEGIN[^\n]*-----\n(.*?)\n-----END/s', $privatePem, $m) !== 1) {
        throw new RuntimeException('no PEM body in the exported key');
    }
    $keyBody = $m[1];

    $cases = [
        'truncated PEM' => [array_replace_recursive($base, ['trust' => ['trust_anchors' => substr($base['trust']['trust_anchors'], 0, 200)."\n-----END CERTIFICATE-----\n"]]), 'trust_anchors'],
        'verify_trust not a boolean' => [array_replace_recursive($base, ['verify' => ['verify_trust' => 'yes']]), 'verify_trust'],
        'unknown top-level key' => [$base + ['trusts' => []], 'trusts'],
        'trust_anchors a list' => [array_replace($base, ['trust' => ['trust_anchors' => [$base['trust']['trust_anchors']], 'trust_config' => $base['trust']['trust_config']]]), 'trust_anchors'],
        'too many certificates' => [array_replace_recursive($base, ['trust' => ['allowed_list' => str_repeat($leaf, 257)]]), 'allowed_list'],
        'a private key in a block' => [array_replace_recursive($base, ['trust' => ['trust_anchors' => $privatePem]]), 'trust_anchors'],
    ];
    foreach ($cases as $name => [$settings, $field]) {
        $json = json_encode($settings, JSON_THROW_ON_ERROR);
        try {
            TrustSettings::fromJson($json);
            expect(false)->toBeTrue("{$name}: no exception");
        } catch (TrustException $e) {
            expect($e->getMessage())->toContain($field);
            if ($name === 'a private key in a block') {
                expect($e->getMessage())->not->toContain(substr($keyBody, 0, 40));
            }
        }
    }
})->group('SPEC-014');

it('AC8: the second oracle: OpenSSL agrees with the walk', function (): void {
    $anchors = spec014Fixtures().'/trust/trust_anchors.pem';
    $rsaOnly = tempnam(sys_get_temp_dir(), 'spec014-rsa');
    file_put_contents($rsaOnly, spec014Pem(spec014Certificates('trust_anchors.pem')[1]));
    $untrusted = tempnam(sys_get_temp_dir(), 'spec014-untrusted');

    $full = spec014Settings('full');
    $rsaSettings = spec014Settings('rsa-root-only');
    try {
        foreach (['fixture-signed.jpg' => false, 'fixture-signed.png' => false, 'fixture-signed.webp' => false, 'public-testfiles/adobe-20220124-C.jpg' => true] as $fixture => $rsaTrusts) {
            $report = spec014Verify($fixture, $full);
            $chain = $report->store?->active;
            assert($chain !== null);
            $cose = CoseSign1::fromBytes($chain->signatureBytes());
            $certs = array_map(static fn ($c): Certificate => Certificate::fromDer($c->bytes), $cose->chain);
            $leafPem = spec014Pem($certs[0]);
            file_put_contents($untrusted, implode('', array_map(static fn (Certificate $c): string => spec014Pem($c), array_slice($certs, 1))));

            expect(openssl_x509_checkpurpose($leafPem, X509_PURPOSE_ANY, [$anchors], $untrusted))->toBeTrue($fixture)
                ->and(spec014Credential($report)[0]->code)->toBe(StatusCode::SigningCredentialTrusted, $fixture);

            $rsaReport = spec014Verify($fixture, $rsaSettings);
            expect(openssl_x509_checkpurpose($leafPem, X509_PURPOSE_ANY, [$rsaOnly], $untrusted))->toBe($rsaTrusts, $fixture)
                ->and(spec014Credential($rsaReport)[0]->code)->toBe($rsaTrusts ? StatusCode::SigningCredentialTrusted : StatusCode::SigningCredentialUntrusted, $fixture);
        }
    } finally {
        unlink($rsaOnly);
        unlink($untrusted);
    }
})->group('SPEC-014');

it('AC9: the three states are told apart by the rule, on paper and on files', function (): void {
    $url = SPEC014_PNG_SIGNATURE;
    $validated = new ValidationStatus(StatusCode::ClaimSignatureValidated, $url, 'ok');
    $trusted = new ValidationStatus(StatusCode::SigningCredentialTrusted, $url, 'ok');
    $untrusted = new ValidationStatus(StatusCode::SigningCredentialUntrusted, $url, 'no');
    $mismatch = new ValidationStatus(StatusCode::AssertionDataHashMismatch, $url, 'no');
    $info = new ValidationStatus(StatusCode::AssertionDataHashAdditionalExclusionsPresent, $url, 'extra');
    expect(ValidationResult::fromStatuses([$trusted, $validated], ['trust'])->state)->toBe(ValidationState::Trusted)
        ->and(ValidationResult::fromStatuses([$validated, $untrusted], ['trust'])->state)->toBe(ValidationState::Valid)
        ->and(ValidationResult::fromStatuses([$validated, $untrusted, $mismatch], ['trust'])->state)->toBe(ValidationState::Invalid)
        ->and(ValidationResult::fromStatuses([$info], [])->state)->toBe(ValidationState::Invalid)
        ->and(ValidationResult::fromStatuses([], [])->state)->toBe(ValidationState::Invalid);

    $report = spec014Verify('binding/pixel-changed.png', spec014Settings('full'));
    $codes = array_map(static fn (ValidationStatus $s): string => $s->code->value, $report->result->statuses);
    expect($codes)->toContain('signingCredential.trusted')
        ->and($codes)->toContain('assertion.dataHash.mismatch')
        ->and($report->result->state)->toBe(ValidationState::Invalid)
        ->and(spec014Oracle('pixel-changed')['validation_state'])->toBe('Invalid')
        ->and(spec014OracleUrl(spec014Oracle('pixel-changed'), 'signingCredential.trusted'))->toBe($url);
})->group('SPEC-014');

it('AC10: the codes are verbatim, and the drift alarm grows', function (): void {
    $values = array_map(static fn (StatusCode $c): string => $c->value, StatusCode::cases());
    expect($values)->toContain('signingCredential.trusted')   // the exact count is SPEC-015 AC10's since it added signingCredential.expired
        ->and($values)->toContain('signingCredential.trusted')
        ->and($values)->toContain('signingCredential.untrusted')
        ->and(StatusCode::SigningCredentialTrusted->isSuccess())->toBeTrue()
        ->and(StatusCode::SigningCredentialUntrusted->isFailure())->toBeTrue();

    // SPEC-013 AC10's corpus, verified with the full settings: the four fixtures become Trusted, nothing else changes state
    $full = spec014Settings('full');
    foreach (SPEC013_CORPUS as $name => $carrier) {
        $oracle = json_decode((string) file_get_contents(spec014Fixtures()."/c2patool/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($oracle) && is_string($oracle['validation_state']));
        $expected = $oracle['validation_state'] === 'Valid' ? 'Trusted' : $oracle['validation_state'];
        expect(spec014Verify($carrier, $full)->result->state->value)->toBe($expected, $name);
    }
})->group('SPEC-014');
