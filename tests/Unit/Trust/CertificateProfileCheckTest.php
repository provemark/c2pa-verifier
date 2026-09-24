<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\Certificate;
use Provemark\C2paVerifier\Trust\CertificateProfileCheck;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;
use Provemark\ContentCredentials\Core\Reading\ManifestStoreParser;

/*
 * SPEC-015: the certificate profile — is the leaf a C2PA signing
 * certificate? The oracle is tests/Fixtures/c2patool/profile/ (steps 33 and
 * 34a: c2patool 0.27.22 on the twelve re-signed variants under
 * tests/Fixtures/profile/throw-away-root.settings.json, and without
 * settings), plus the step-14/30 JSONs for the fixtures and signature_info.
 */

const SPEC015_PNG_SIGNATURE = 'self#jumbf=/c2pa/urn:c2pa:488bf983-c973-465d-a0eb-1597392cc5d0/c2pa.signature';

function spec015Fixtures(): string
{
    return dirname(__DIR__, 2).'/Fixtures';
}

function spec015ThrowAway(): TrustSettings
{
    return TrustSettings::fromJson((string) file_get_contents(spec015Fixtures().'/profile/throw-away-root.settings.json'));
}

function spec015Trust(string $variant): TrustSettings
{
    return TrustSettings::fromJson((string) file_get_contents(spec015Fixtures()."/trust/{$variant}.settings.json"));
}

/** @return resource */
function spec015Stream(string $relative)
{
    $stream = fopen(spec015Fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }

    return $stream;
}

function spec015Verify(string $relative, ?TrustSettings $settings): VerificationReport
{
    return (new Verifier)->verify(spec015Stream($relative), $settings);
}

/** @return array<string, mixed> */
function spec015Oracle(string $path): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(spec015Fixtures()."/c2patool/{$path}.json"), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $oracle
 * @return list<string> the codes of validation_status (absent = none), sorted
 */
function spec015OracleFailures(array $oracle): array
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

/**
 * @param  array<string, mixed>  $oracle
 * @return list<string> every signingCredential.* code c2patool recorded, success or failure, sorted unique
 */
function spec015OracleCredential(array $oracle): array
{
    $results = $oracle['validation_results'];
    assert(is_array($results) && is_array($results['activeManifest']));
    $codes = [];
    foreach ($results['activeManifest'] as $list) {
        assert(is_array($list));
        foreach ($list as $status) {
            assert(is_array($status) && is_string($status['code']));
            if (str_starts_with($status['code'], 'signingCredential')) {
                $codes[] = $status['code'];
            }
        }
    }
    $codes = array_values(array_unique($codes));
    sort($codes);

    return $codes;
}

/** @return list<string> sorted unique */
function spec015Codes(VerificationReport $report, string $prefix = ''): array
{
    $codes = [];
    foreach ($report->result->statuses as $status) {
        // signingCredential.ocsp.* belongs to SPEC-030, not to the certificate profile;
        // a prefix filter written before it existed would otherwise swallow it
        if (str_starts_with($status->code->value, 'signingCredential.ocsp.')) {
            continue;
        }
        if (str_starts_with($status->code->value, $prefix)) {
            $codes[] = $status->code->value;
        }
    }
    $codes = array_values(array_unique($codes));
    sort($codes);

    return $codes;
}

/** @return list<ValidationStatus> */
function spec015With(VerificationReport $report, StatusCode $code): array
{
    return array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === $code));
}

/** @return list<string> sorted */
function spec015Failures(VerificationReport $report): array
{
    $codes = [];
    foreach ($report->result->statuses as $status) {
        if ($status->code->isFailure()) {
            $codes[] = $status->code->value;
        }
    }
    sort($codes);

    return array_values(array_unique($codes));
}

function spec015Leaf(string $variant): Certificate
{
    return TrustSettings::certificatesFromPem((string) file_get_contents(spec015Fixtures()."/profile/{$variant}.leaf.pem"), $variant, 4)[0];
}

/**
 * The good leaf's real parse data, altered — the seam for the rules no re-signed file can show.
 *
 * @param  callable(array<string, mixed>, array<string, mixed>): array{0: array<mixed, mixed>, 1: array<mixed, mixed>}  $alter
 */
function spec015HandBuilt(callable $alter): Certificate
{
    $leaf = spec015Leaf('good');
    $pem = Certificate::pem($leaf->der);
    $parsed = openssl_x509_parse($pem);
    $public = openssl_pkey_get_public($pem);
    assert(is_array($parsed) && $public !== false);
    $key = openssl_pkey_get_details($public);
    assert(is_array($key));
    /** @var array<string, mixed> $parsed */
    /** @var array<string, mixed> $key */
    [$alteredParsed, $alteredKey] = $alter($parsed, $key);
    $typed = static function (array $a): array {
        $t = [];
        foreach ($a as $k => $v) {
            $t[(string) $k] = $v;
        }

        return $t;
    };

    return Certificate::fromParsed($leaf->der, $typed($alteredParsed), $typed($alteredKey));
}

/** @return array<string, mixed> */
function spec015SignatureInfo(VerificationReport $report): array
{
    $array = $report->toArray();
    assert(is_array($array['manifests']) && is_string($array['active_manifest']));
    $manifest = $array['manifests'][$array['active_manifest']];
    assert(is_array($manifest) && is_array($manifest['signature_info']));

    /** @var array<string, mixed> */
    return $manifest['signature_info'];
}

it('AC1: the control: good and eku-c2pa pass the profile, and the four fixtures with the full settings stay Trusted', function (): void {
    foreach (['good', 'eku-c2pa'] as $variant) {
        $report = spec015Verify("profile/{$variant}.png", spec015ThrowAway());
        expect(spec015Codes($report, 'signingCredential'))->toBe(['signingCredential.trusted'], $variant)
            ->and($report->result->state)->toBe(ValidationState::Trusted, $variant)
            ->and(spec015Oracle("profile/{$variant}")['validation_state'])->toBe('Trusted', $variant)
            ->and($report->result->checksPerformed)->toContain('certificate');
    }
    expect(spec015Leaf('eku-c2pa')->extendedKeyUsage)->toBe(['1.3.6.1.4.1.62558.2.1']);

    $full = spec015Trust('full');
    foreach (['fixture-signed.jpg', 'fixture-signed.png', 'fixture-signed.webp', 'public-testfiles/adobe-20220124-C.jpg'] as $fixture) {
        $report = spec015Verify($fixture, $full);
        expect(spec015Codes($report, 'signingCredential'))->toBe(['signingCredential.trusted'], $fixture)
            ->and($report->result->state)->toBe(ValidationState::Trusted, $fixture);
    }
})->group('SPEC-015');

it('AC2: one departure, one signingCredential.invalid, the state Invalid, as c2patool', function (): void {
    $cases = [
        'ca-as-leaf' => 'CA',
        'eku-outside-list' => 'Code Signing',
        'eku-any' => 'any',
        'eku-mixed' => 'Time Stamping',
        'no-eku' => 'no ExtendedKeyUsage',
        'v1' => 'no KeyUsage',
        'rsa-1024' => '1024',
        'curve-secp256k1' => 'secp256k1',
    ];
    foreach ($cases as $variant => $word) {
        $report = spec015Verify("profile/{$variant}.png", spec015ThrowAway());
        $invalid = spec015With($report, StatusCode::SigningCredentialInvalid);
        expect($invalid)->not->toBe([], $variant)
            ->and(implode(' | ', array_map(static fn (ValidationStatus $s): string => $s->explanation, $invalid)))->toContain($word)
            ->and(spec015With($report, StatusCode::SigningCredentialTrusted))->toHaveCount(1, $variant)
            ->and($report->result->state)->toBe(ValidationState::Invalid, $variant);
        $oracle = spec015Oracle("profile/{$variant}");
        expect(spec015OracleFailures($oracle))->toBe(['signingCredential.invalid'], $variant)
            ->and($oracle['validation_state'])->toBe('Invalid', $variant);
    }
})->group('SPEC-015');

it('AC3: expired: its own code, and the time used is named', function (): void {
    $report = spec015Verify('profile/expired.png', spec015ThrowAway());
    $expired = spec015With($report, StatusCode::SigningCredentialExpired);
    expect($expired)->toHaveCount(1)
        ->and($expired[0]->explanation)->toContain('2024-01-01')
        ->and($expired[0]->explanation)->toContain('2025-01-01')
        ->and($expired[0]->explanation)->toContain('no timestamp')
        ->and($expired[0]->url)->toBe(SPEC015_PNG_SIGNATURE)
        ->and($report->result->state)->toBe(ValidationState::Invalid)
        ->and(spec015OracleFailures(spec015Oracle('profile/expired')))->toBe(['signingCredential.expired']);

    $manifest = $report->store?->active;
    assert($manifest !== null);
    $inside = (new CertificateProfileCheck)->check($manifest, spec015ThrowAway(), (int) strtotime('2024-06-01T00:00:00Z'));
    expect(array_map(static fn (ValidationStatus $s): string => $s->code->value, $inside))->not->toContain('signingCredential.expired');
})->group('SPEC-015');

it('AC4: KeyUsage as c2pa-rs keeps it', function (): void {
    $report = spec015Verify('profile/no-digital-signature.png', spec015ThrowAway());
    expect(spec015Codes($report, 'signingCredential'))->toBe(['signingCredential.trusted'])
        ->and($report->result->state)->toBe(ValidationState::Trusted)
        ->and(spec015Oracle('profile/no-digital-signature')['validation_state'])->toBe('Trusted')
        ->and(spec015Leaf('no-digital-signature')->keyUsage)->toBe(['Non Repudiation']);

    $check = new CertificateProfileCheck;
    $both = spec015HandBuilt(static function (array $parsed, array $key): array {
        assert(is_array($parsed['extensions']));
        $parsed['extensions']['keyUsage'] = 'Digital Signature, Certificate Sign';

        return [$parsed, $key];
    });
    $statuses = $check->checkLeaf($both, null, null, SPEC015_PNG_SIGNATURE);
    expect(array_map(static fn (ValidationStatus $s): string => $s->code->value, $statuses))->toBe(['signingCredential.invalid'])
        ->and($statuses[0]->explanation)->toContain('Certificate Sign');

    $none = spec015HandBuilt(static function (array $parsed, array $key): array {
        assert(is_array($parsed['extensions']));
        unset($parsed['extensions']['keyUsage']);

        return [$parsed, $key];
    });
    $statuses = $check->checkLeaf($none, null, null, SPEC015_PNG_SIGNATURE);
    expect(array_map(static fn (ValidationStatus $s): string => $s->code->value, $statuses))->toBe(['signingCredential.invalid'])
        ->and($statuses[0]->explanation)->toContain('no KeyUsage');
})->group('SPEC-015');

it('AC5: the EKU list is the built-in six plus trust_config, never fewer', function (): void {
    $throwAway = spec015ThrowAway();
    $report = spec015Verify('profile/eku-outside-list.png', $throwAway);
    expect(spec015Codes($report, 'signingCredential'))->toContain('signingCredential.invalid');

    $widened = new TrustSettings($throwAway->trustAnchors, [], [...$throwAway->trustConfig, '1.3.6.1.5.5.7.3.3']);
    $report = spec015Verify('profile/eku-outside-list.png', $widened);
    expect(spec015Codes($report, 'signingCredential'))->toBe(['signingCredential.trusted'])
        ->and($report->result->state)->toBe(ValidationState::Trusted);

    $narrowed = spec015Trust('anchors-wrong-eku');
    expect($narrowed->trustConfig)->toBe(['1.3.6.1.5.5.7.3.36']);
    $report = spec015Verify('fixture-signed.png', $narrowed);
    expect($report->result->state)->toBe(ValidationState::Trusted)
        ->and(spec015Oracle('trusted/png-anchors-wrong-eku')['validation_state'])->toBe('Trusted');
})->group('SPEC-015');

it('AC6: the rules the variants cannot show, on hand-built parse data', function (): void {
    $check = new CertificateProfileCheck;
    $cases = [
        'version' => static function (array $parsed, array $key): array {
            $parsed['version'] = 0;   // OpenSSL: 0 = v1

            return [$parsed, $key];
        },
        'sha1WithRSAEncryption' => static function (array $parsed, array $key): array {
            $parsed['signatureTypeLN'] = 'sha1WithRSAEncryption';

            return [$parsed, $key];
        },
        'brainpoolP256r1' => static function (array $parsed, array $key): array {
            assert(is_array($key['ec']));
            $key['ec']['curve_name'] = 'brainpoolP256r1';

            return [$parsed, $key];
        },
        'AuthorityKeyIdentifier' => static function (array $parsed, array $key): array {
            assert(is_array($parsed['extensions']));
            unset($parsed['extensions']['authorityKeyIdentifier']);

            return [$parsed, $key];
        },
        'OCSP Signing' => static function (array $parsed, array $key): array {
            assert(is_array($parsed['extensions']));
            $parsed['extensions']['extendedKeyUsage'] = 'OCSP Signing, Time Stamping';

            return [$parsed, $key];
        },
    ];
    foreach ($cases as $word => $alter) {
        $statuses = $check->checkLeaf(spec015HandBuilt($alter), null, null, SPEC015_PNG_SIGNATURE);
        expect(array_map(static fn (ValidationStatus $s): string => $s->code->value, $statuses))->toBe(['signingCredential.invalid'], $word)
            ->and($statuses[0]->explanation)->toContain($word);
    }

    // amendment 3: sha384WithRSAEncryption is on c2pa-rs's list — the Truepic camera files' leaves are signed with it
    $truepic = spec015HandBuilt(static function (array $parsed, array $key): array {
        $parsed['signatureTypeLN'] = 'sha384WithRSAEncryption';

        return [$parsed, $key];
    });
    expect(array_map(static fn (ValidationStatus $s): string => $s->code->value, $check->checkLeaf($truepic, null, null, SPEC015_PNG_SIGNATURE)))->toBe([]);
})->group('SPEC-015');

it('AC7: signature_info is c2patool\'s', function (): void {
    foreach (['jpg' => 'fixture-signed.jpg', 'png' => 'fixture-signed.png', 'webp' => 'fixture-signed.webp', 'adobe-20220124-C' => 'public-testfiles/adobe-20220124-C.jpg'] as $name => $fixture) {
        $report = spec015Verify($fixture, null);
        $oracle = spec015Oracle($name);
        assert(is_array($oracle['manifests']) && is_string($oracle['active_manifest']));
        $theirs = $oracle['manifests'][$oracle['active_manifest']];
        assert(is_array($theirs) && is_array($theirs['signature_info']));
        // `time` from the timestamp (the Adobe file has one) since SPEC-017: the whole block, byte for byte (amendment 2's exclusion lifted)
        expect(spec015SignatureInfo($report))->toBe($theirs['signature_info'], $name);
    }
    $png = spec015Verify('fixture-signed.png', null);
    expect(spec015SignatureInfo($png))->toBe(['alg' => 'Es256', 'issuer' => 'C2PA Test Signing Cert', 'common_name' => 'C2PA Signer', 'cert_serial_number' => '640229841392226413189608867977836244731148734950']);

    $good = spec015Verify('profile/good.png', spec015ThrowAway());
    $oracle = spec015Oracle('profile/good');
    assert(is_array($oracle['manifests']) && is_string($oracle['active_manifest']));
    $theirs = $oracle['manifests'][$oracle['active_manifest']];
    assert(is_array($theirs));
    expect(spec015SignatureInfo($good))->toBe($theirs['signature_info'])
        ->and(spec015SignatureInfo($good)['issuer'])->toBe('C2PA Verifier throw-away hierarchy');

    $signer = ManifestStoreParser::fromJson($png->toJson())->signer();
    expect($signer?->issuer)->toBe('C2PA Test Signing Cert')
        ->and($signer?->commonName)->toBe('C2PA Signer')
        ->and($signer?->algorithm)->toBe('Es256');
})->group('SPEC-015');

it('AC8: the profile is checked always; the two checks are independent', function (): void {
    $cases = [
        // the trust check runs without settings too (no anchors → untrusted, SPEC-014 amendment 1); only verify_trust false keeps it out
        'expired-wrong-anchor' => ['profile/expired.png', spec015Trust('ec-root-only'), ['signingCredential.expired', 'signingCredential.untrusted'], true],
        'expired-no-settings' => ['profile/expired.png', null, ['signingCredential.expired', 'signingCredential.untrusted'], true],
        'expired-verify-off' => ['profile/expired.png', spec015Trust('verify-off'), ['signingCredential.expired'], false],
        'no-eku-no-settings' => ['profile/no-eku.png', null, ['signingCredential.invalid', 'signingCredential.untrusted'], true],
    ];
    foreach ($cases as $name => [$file, $settings, $expected, $trustRan]) {
        $report = spec015Verify($file, $settings);
        expect(spec015Failures($report))->toBe($expected, $name)
            ->and($report->result->state)->toBe(ValidationState::Invalid, $name)
            ->and($report->result->checksPerformed)->toContain('certificate')
            ->and(in_array('trust', $report->result->checksPerformed, true))->toBe($trustRan, $name)
            ->and(spec015OracleFailures(spec015Oracle("profile/{$name}")))->toBe($expected, $name);
    }
})->group('SPEC-015');

it('AC9: M5\'s "done when": with and without the trust file, the verdicts are c2patool\'s', function (): void {
    $full = spec015Trust('full');
    $off = spec015Trust('verify-off');
    foreach (['jpg' => 'fixture-signed.jpg', 'png' => 'fixture-signed.png', 'webp' => 'fixture-signed.webp', 'adobe-20220124-C' => 'public-testfiles/adobe-20220124-C.jpg'] as $name => $fixture) {
        $none = spec015Verify($fixture, null);
        expect($none->result->state->value)->toBe(spec015Oracle($name)['validation_state'], "{$name} none")
            ->and(spec015Codes($none, 'signingCredential'))->toBe(spec015OracleCredential(spec015Oracle($name)), "{$name} none")
            ->and(spec015Codes($none, 'signingCredential'))->toBe(['signingCredential.untrusted']);

        $trusted = spec015Verify($fixture, $full);
        expect($trusted->result->state->value)->toBe(spec015Oracle("trusted/{$name}")['validation_state'], "{$name} full")
            ->and(spec015Codes($trusted, 'signingCredential'))->toBe(spec015OracleCredential(spec015Oracle("trusted/{$name}")), "{$name} full")
            ->and($trusted->result->state)->toBe(ValidationState::Trusted);
    }
    $verifyOff = spec015Verify('fixture-signed.png', $off);
    expect($verifyOff->result->state->value)->toBe(spec015Oracle('trusted/png-verify-off')['validation_state'])
        ->and(spec015Codes($verifyOff, 'signingCredential'))->toBe([])
        ->and(spec015OracleCredential(spec015Oracle('trusted/png-verify-off')))->toBe([]);
})->group('SPEC-015');

it('AC10: the codes are verbatim, and the drift alarm grows', function (): void {
    $values = array_map(static fn (StatusCode $c): string => $c->value, StatusCode::cases());
    expect($values)->toHaveCount(60)   // SPEC-027 two bmffHash codes; SPEC-017 six timeStamp codes, SPEC-018 one, SPEC-020 three ingredient codes, SPEC-021 two, SPEC-022 three manifest codes, SPEC-030 four signingCredential.ocsp codes, SPEC-032 one, SPEC-033 two, SPEC-035 six, SPEC-036 one, SPEC-037 one, SPEC-038 two, SPEC-039 one, SPEC-040 one
        ->and($values)->toContain('signingCredential.expired')
        ->and(StatusCode::SigningCredentialExpired->isFailure())->toBeTrue();

    $full = spec015Trust('full');
    foreach (SPEC013_CORPUS as $name => $carrier) {
        $oracle = spec015Oracle($name);
        $report = spec015Verify($carrier, $full);
        // recorded without settings: untrusted there is trusted here, and Valid becomes Trusted
        $theirs = array_map(static fn (string $c): string => $c === 'signingCredential.untrusted' ? 'signingCredential.trusted' : $c, spec015OracleCredential($oracle));
        sort($theirs);
        $expectedState = $oracle['validation_state'] === 'Valid' ? 'Trusted' : $oracle['validation_state'];
        expect($report->result->state->value)->toBe($expectedState, $name);
        if ($report->result->checksPerformed !== []) {   // a parse fault stops this verifier before any check (SPEC-013 AC10's subset rule)
            expect(spec015Codes($report, 'signingCredential'))->toBe($theirs, $name);
        }
    }
    $throwAway = spec015ThrowAway();
    foreach (['good', 'no-digital-signature', 'expired', 'ca-as-leaf', 'eku-outside-list', 'eku-any', 'eku-mixed', 'eku-c2pa', 'no-eku', 'v1', 'rsa-1024', 'curve-secp256k1'] as $variant) {
        $oracle = spec015Oracle("profile/{$variant}");
        $report = spec015Verify("profile/{$variant}.png", $throwAway);
        expect($report->result->state->value)->toBe($oracle['validation_state'], $variant)
            ->and(spec015Codes($report, 'signingCredential'))->toBe(spec015OracleCredential($oracle), $variant);
    }
})->group('SPEC-015');

// amendment 5 (2026-09-22, found by the coverage matrix on PHP 8.3): the key kind comes from the
// SPKI's algorithm OID, not from PHP's key-type constant. Before PHP 8.4 an Ed25519 key has no
// `ed25519` details and reads as type "other", which made every Ed25519-signed file
// signingCredential.invalid on 8.3 and Trusted on 8.4/8.5 — a verdict that depended on the runtime.
it('AC11: an Ed25519 signer is recognised on every PHP, from the SPKI algorithm OID', function (): void {
    $store = Corpus::manifestStore('matrix/ed25519.png') ?? throw new RuntimeException('no store');
    $cose = CoseSign1::fromBytes($store->active->signatureBytes());
    $leaf = Certificate::fromDer($cose->chain[0]->bytes);

    expect($leaf->keyType)->toBe('Ed25519')
        ->and($leaf->keyBits)->toBe(256);

    // and the profile accepts it: the C2PA rule (§14.5) has always allowed Ed25519
    $report = spec015Verify('matrix/ed25519.png', TrustSettings::fromJson((string) file_get_contents(spec015Fixtures().'/matrix/test-roots.settings.json')));
    expect($report->result->state)->toBe(ValidationState::Trusted)
        ->and(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::SigningCredentialInvalid))->toBe([]);
})->group('SPEC-015');
