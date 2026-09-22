<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborDecoder;
use Provemark\C2paVerifier\Cbor\CborException;
use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Container\WebpManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\ClaimSignatureCheck;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Cose\SignatureVerifier;
use Provemark\C2paVerifier\Jumbf\JumbfException;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestException;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationResult;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\ContentCredentials\Core\Reading\ManifestStoreParser;

/*
 * SPEC-010: the report — C2PA 2.4 §15 status codes, verbatim, for the claim
 * signature and the Manifest layer's faults. The oracle JSON is under
 * tests/Fixtures/c2patool/ (fixtures, step 14) and
 * tests/Fixtures/c2patool/variants/ (step 21).
 */

const SPEC010_PNG_SIGNATURE_URL = 'self#jumbf=/c2pa/urn:c2pa:488bf983-c973-465d-a0eb-1597392cc5d0/c2pa.signature';

function spec010Store(string $fixture): string
{
    $stream = fopen(dirname(__DIR__, 2).'/Fixtures/'.$fixture, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$fixture}");
    }
    $extractor = match (pathinfo($fixture, PATHINFO_EXTENSION)) {
        'jpg' => new JpegManifestStoreExtractor,
        'png' => new PngManifestStoreExtractor,
        'webp' => new WebpManifestStoreExtractor,
        default => throw new RuntimeException("no extractor for {$fixture}"),
    };
    $store = $extractor->extract($stream);
    if ($store === null) {
        throw new RuntimeException("no manifest store in {$fixture}");
    }

    return $store->bytes;
}

function spec010Manifest(string $fixture): Manifest
{
    return ManifestStore::fromTree((new JumbfParser)->parse(spec010Store($fixture)))->active;
}

/** The active manifest of a variant store (`cose/x`, `claim/x`, `jumbf/x`). */
function spec010Variant(string $path): Manifest
{
    return ManifestStore::fromTree((new JumbfParser)->parse((string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/{$path}.bin")))->active;
}

/**
 * A vector of step 19 as a Manifest-less check input: the CoseSign1 bytes and the claim bytes.
 *
 * @return array{cose: string, claim: string}
 */
function spec010Vector(string $name): array
{
    /** @var array{claim_hex: string, protected_hex: string, signature_hex: string} $v */
    $v = json_decode((string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/signatures/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
    $protected = (string) hex2bin($v['protected_hex']);
    $signature = (string) hex2bin($v['signature_hex']);
    $head = static fn (string $b): string => (strlen($b) < 256 ? "\x58".pack('C', strlen($b)) : "\x59".pack('n', strlen($b))).$b;

    return ['cose' => "\xd2\x84".$head($protected)."\xa0\xf6".$head($signature), 'claim' => (string) hex2bin($v['claim_hex'])];
}

/** @return array<string, mixed> */
function spec010C2patool(string $name, bool $variant = false): array
{
    $file = dirname(__DIR__, 2).'/Fixtures/c2patool/'.($variant ? 'variants/' : '').$name.'.json';

    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
}

/** @return list<array{code: string, url: string}> the code/url pairs of a c2patool status list */
function spec010Pairs(mixed $statuses): array
{
    assert(is_array($statuses));
    $pairs = [];
    foreach ($statuses as $status) {
        assert(is_array($status) && is_string($status['code']) && is_string($status['url']));
        $pairs[] = ['code' => $status['code'], 'url' => $status['url']];
    }

    return $pairs;
}

/**
 * The activeManifest block of a recorded c2patool JSON (or of our toArray()).
 *
 * @param  array<string, mixed>  $oracle
 * @return array<string, mixed>
 */
function spec010ActiveManifest(array $oracle): array
{
    $results = $oracle['validation_results'];
    assert(is_array($results) && is_array($results['activeManifest']));
    $active = [];
    foreach ($results['activeManifest'] as $kind => $statuses) {
        $active[(string) $kind] = $statuses;
    }

    return $active;
}

it('AC1: the four fixtures: claimSignature.validated, and the words are c2patool\'s', function (): void {
    foreach (['png' => 'fixture-signed.png', 'jpg' => 'fixture-signed.jpg', 'webp' => 'fixture-signed.webp', 'adobe-20220124-C' => 'public-testfiles/adobe-20220124-C.jpg'] as $name => $fixture) {
        $manifest = spec010Manifest($fixture);
        $statuses = (new ClaimSignatureCheck)->check($manifest);

        expect($statuses)->toHaveCount(1, $name)
            ->and($statuses[0]->code)->toBe(StatusCode::ClaimSignatureValidated, $name)
            ->and($statuses[0]->url)->toBe("self#jumbf=/c2pa/{$manifest->label}/c2pa.signature", $name);

        $oracle = spec010C2patool($name);
        $validated = array_values(array_filter(spec010Pairs(spec010ActiveManifest($oracle)['success']), static fn (array $p): bool => $p['code'] === 'claimSignature.validated'));
        expect($validated)->toBe([['code' => $statuses[0]->code->value, 'url' => $statuses[0]->url]], $name);

        $result = ValidationResult::fromStatuses($statuses, ['signature']);
        expect($result->state)->toBe(ValidationState::Valid, $name)
            ->and($result->checksPerformed)->toBe(['signature'], $name);
    }
})->group('SPEC-010');

it('AC2: one altered byte: claimSignature.mismatch, as c2patool', function (): void {
    foreach (['claim-title-changed', 'signature-changed'] as $name) {
        $statuses = (new ClaimSignatureCheck)->check(spec010Variant("cose/{$name}"));

        expect($statuses)->toHaveCount(1, $name)
            ->and($statuses[0]->code)->toBe(StatusCode::ClaimSignatureMismatch, $name)
            ->and($statuses[0]->url)->toBe(SPEC010_PNG_SIGNATURE_URL, $name)
            ->and(ValidationResult::fromStatuses($statuses, ['signature'])->state)->toBe(ValidationState::Invalid, $name);

        $oracle = spec010C2patool($name, true);
        expect(spec010Pairs($oracle['validation_status']))->toContain(['code' => 'claimSignature.mismatch', 'url' => SPEC010_PNG_SIGNATURE_URL]);
    }
})->group('SPEC-010');

it('AC3: cannot verify: the codes §15.7 names', function (): void {
    $check = new ClaimSignatureCheck;
    $statuses = $check->check(spec010Variant('cose/alg-eddsa-with-ec-key'));
    expect($statuses)->toHaveCount(1)
        ->and($statuses[0]->code)->toBe(StatusCode::SigningCredentialInvalid)
        ->and($statuses[0]->url)->toBe(SPEC010_PNG_SIGNATURE_URL)
        ->and($statuses[0]->explanation)->toContain('key does not fit EdDSA');

    $expected = ['es256-p256k1' => StatusCode::SigningCredentialInvalid, 'ps256-rsa1024' => StatusCode::SigningCredentialInvalid, 'eddsa-rsa' => StatusCode::SigningCredentialInvalid, 'alg-unsupported' => StatusCode::AlgorithmUnsupported];
    foreach ($expected as $name => $code) {
        $v = spec010Vector($name);
        $statuses = $check->checkBytes($v['cose'], $v['claim'], 'self#jumbf=/c2pa/x/c2pa.signature');
        expect($statuses[0]->code)->toBe($code, $name)
            ->and(ValidationResult::fromStatuses($statuses, ['signature'])->state)->toBe(ValidationState::Invalid, $name);
    }

    $v = spec010Vector('eddsa-ed25519');
    $noEd25519 = new ClaimSignatureCheck(new SignatureVerifier(useSodium: false, useOpensslEd25519: false));
    $statuses = $noEd25519->checkBytes($v['cose'], $v['claim'], 'self#jumbf=/c2pa/x/c2pa.signature');
    expect($statuses[0]->code)->toBe(StatusCode::AlgorithmUnsupported)
        ->and($statuses[0]->explanation)->toContain('neither ext-sodium nor OpenSSL Ed25519');
})->group('SPEC-010');

it('AC4: structural COSE faults: general.error with the message', function (): void {
    foreach (['tag-19' => 'expected tag 18', 'payload-present' => 'the payload must be detached', 'alg-missing' => 'the protected header has no alg'] as $name => $message) {
        $statuses = (new ClaimSignatureCheck)->check(spec010Variant("cose/{$name}"));

        expect($statuses)->toHaveCount(1, $name)
            ->and($statuses[0]->code)->toBe(StatusCode::GeneralError, $name)
            ->and($statuses[0]->explanation)->toContain($message)
            ->and(ValidationResult::fromStatuses($statuses, ['signature'])->state)->toBe(ValidationState::Invalid, $name);
    }
})->group('SPEC-010');

it('AC5: chain faults: signingCredential.invalid', function (): void {
    foreach (['x5chain-missing' => 'no x5chain', 'leaf-der-broken' => 'not an X.509 certificate', 'chain-empty' => 'x5chain is empty'] as $name => $message) {
        $statuses = (new ClaimSignatureCheck)->check(spec010Variant("cose/{$name}"));

        expect($statuses[0]->code)->toBe(StatusCode::SigningCredentialInvalid, $name)
            ->and($statuses[0]->explanation)->toContain($message);
    }
})->group('SPEC-010');

it('AC6: the Manifest layer\'s faults carry their codes', function (): void {
    $expected = [
        'claim/claim-no-signature' => StatusCode::ClaimMalformed,
        'claim/claim-no-created-assertions' => StatusCode::ClaimMalformed,
        'claim/claim-no-instanceid' => StatusCode::ClaimMalformed,
        'claim/claim-no-claim-generator-info' => StatusCode::ClaimMalformed,
        'claim/generator-info-no-name' => StatusCode::ClaimMalformed,
        'claim/hash-as-text' => StatusCode::ClaimMalformed,
        'claim/hash-missing' => StatusCode::ClaimMalformed,
        'claim/claim-label-v3' => StatusCode::ClaimMalformed,
        'claim/two-cbor-boxes' => StatusCode::ClaimMalformed,
        'claim/assertion-store-label' => StatusCode::ClaimMalformed,
        'claim/second-claim' => StatusCode::ClaimMultiple,
        'claim/no-manifest' => StatusCode::ClaimMissing,
        'claim/uri-not-found' => StatusCode::AssertionMissing,
        'claim/uri-wrong-place' => StatusCode::AssertionMissing,
        'jumbf/unknown-uuid' => StatusCode::AssertionMissing,
        'claim/json-broken' => StatusCode::AssertionJsonInvalid,
    ];
    foreach ($expected as $path => $code) {
        try {
            spec010Variant($path);
            expect(false)->toBeTrue("{$path}: no exception");
        } catch (ManifestException $e) {
            expect($e->status)->toBe($code, $path);
        }
    }

    // A claim whose CBOR SPEC-006 refuses: the claim box's data replaced by the step-12 variant
    // (claim-indefinite-array left this list with SPEC-006 amendment 3: indefinite lengths decode).
    foreach (['claim-duplicate-key'] as $name) {
        $store = spec010Store('fixture-signed.png');
        $cbor = (string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/cbor/{$name}.cbor");
        // The claim's cbor box (offset 33073, LBox 599) and its data (33081, 591): splice, adjusting root, manifest, claim, cbor LBoxes.
        $delta = strlen($cbor) - 591;
        foreach ([0, 38, 33026, 33073] as $lbox) {
            /** @var array{1: int} $u */
            $u = unpack('N', $store, $lbox);
            $store = substr($store, 0, $lbox).pack('N', $u[1] + $delta).substr($store, $lbox + 4);
        }
        $store = substr($store, 0, 33081).$cbor.substr($store, 33081 + 591);
        try {
            ManifestStore::fromTree((new JumbfParser)->parse($store));
            expect(false)->toBeTrue("{$name}: no exception");
        } catch (ManifestException $e) {
            expect($e->status)->toBe(StatusCode::ClaimCborInvalid, $name);
        }
    }
})->group('SPEC-010');

it('AC7: assertion.json.invalid agrees with c2patool (code; c2patool\'s url is a bare label)', function (): void {
    $label = 'contentauth:urn:uuid:4d971750-1db4-4492-a87c-5c3e7ed33efc';
    try {
        spec010Variant('claim/json-broken');
        expect(false)->toBeTrue('no exception');
    } catch (ManifestException $e) {
        $status = new ValidationStatus($e->status, "self#jumbf=/c2pa/{$label}/c2pa.assertions/stds.schema-org.CreativeWork", $e->getMessage());
        $oracle = spec010C2patool('json-broken', true);
        $codes = array_column(spec010Pairs($oracle['validation_status']), 'code');

        expect($status->code)->toBe(StatusCode::AssertionJsonInvalid)
            ->and($codes)->toContain('assertion.json.invalid')
            ->and($status->url)->toStartWith('self#jumbf=/c2pa/');
    }
})->group('SPEC-010');

it('AC8: the leaf layers\' faults become general.error', function (): void {
    $caught = [];
    try {
        $stream = fopen(dirname(__DIR__, 2).'/Fixtures/jpeg/truncated-in-piece-2.jpg', 'rb');
        assert($stream !== false);
        (new JpegManifestStoreExtractor)->extract($stream);
    } catch (ContainerException $e) {
        $caught['container'] = $e;
    }
    try {
        (new JumbfParser)->parse((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/jumbf/lbox-zero.bin'));
    } catch (JumbfException $e) {
        $caught['jumbf'] = $e;
    }
    try {
        (new CborDecoder)->decode("\x1c");
    } catch (CborException $e) {
        $caught['cbor'] = $e;
    }
    expect(array_keys($caught))->toBe(['container', 'jumbf', 'cbor']);

    foreach ($caught as $layer => $e) {
        $status = new ValidationStatus(StatusCode::GeneralError, 'self#jumbf=/c2pa', $e->getMessage());
        expect($status->code)->toBe(StatusCode::GeneralError, $layer)
            ->and($status->explanation)->toBe($e->getMessage(), $layer)
            ->and(ValidationResult::fromStatuses([$status], ['container'])->state)->toBe(ValidationState::Invalid, $layer);
    }
})->group('SPEC-010');

it('AC9: the array shape is c2patool\'s, plus the checks performed', function (): void {
    $png = spec010Manifest('fixture-signed.png');
    $valid = ValidationResult::fromStatuses((new ClaimSignatureCheck)->check($png), ['signature'])->toArray();
    $success = spec010ActiveManifest($valid)['success'];
    assert(is_array($success) && is_array($success[0]) && is_string($success[0]['explanation']));
    $explanation = $success[0]['explanation'];

    expect($explanation)->not->toBe('')
        ->and($valid)->toBe([   // no validation_status key: none when there is no failure (SPEC-013 amendment 3, measured in step 30)
            'validation_results' => ['activeManifest' => [
                'success' => [['code' => 'claimSignature.validated', 'url' => SPEC010_PNG_SIGNATURE_URL, 'explanation' => $explanation]],
                'informational' => [],
                'failure' => [],
            ]],
            'validation_state' => 'Valid',
            'checks_performed' => ['signature'],
        ]);

    $mismatch = ValidationResult::fromStatuses((new ClaimSignatureCheck)->check(spec010Variant('cose/claim-title-changed')), ['signature'])->toArray();
    $failure = spec010ActiveManifest($mismatch)['failure'];
    assert(is_array($mismatch['validation_status']) && is_array($failure));
    expect(array_column($mismatch['validation_status'], 'code'))->toBe(['claimSignature.mismatch'])
        ->and(array_column($failure, 'code'))->toBe(['claimSignature.mismatch'])
        ->and($mismatch['validation_state'])->toBe('Invalid');

    // The sister library reads what c2patool would have written.
    $store = ManifestStore::fromTree((new JumbfParser)->parse(spec010Store('fixture-signed.png')));
    $validReport = ManifestStoreParser::fromJson(json_encode($store->toArray() + $valid, JSON_THROW_ON_ERROR));
    $mismatchReport = ManifestStoreParser::fromJson(json_encode($store->toArray() + $mismatch, JSON_THROW_ON_ERROR));
    expect($validReport->validationStatusCodes())->toBe([])
        ->and($validReport->validationState()?->value)->toBe('Valid')
        ->and($mismatchReport->validationStatusCodes())->toBe(['claimSignature.mismatch'])
        ->and($mismatchReport->validationState()?->value)->toBe('Invalid');
})->group('SPEC-010');

it('AC10: every code is verbatim, and success and failure are told apart', function (): void {
    $values = array_map(static fn (StatusCode $c): string => $c->value, StatusCode::cases());
    sort($values);
    // SPEC-010's twelve; SPEC-011 and SPEC-012 added their own (SPEC-012 AC10 asserts the full twenty-one and the three kinds)
    foreach ([
        'algorithm.unsupported', 'assertion.json.invalid', 'assertion.missing',
        'claim.cbor.invalid', 'claim.malformed', 'claim.missing', 'claim.multiple',
        'claimSignature.mismatch', 'claimSignature.missing', 'claimSignature.validated',
        'general.error', 'signingCredential.invalid',
    ] as $value) {
        expect($values)->toContain($value);
    }
    foreach (StatusCode::cases() as $code) {
        if ((str_starts_with($code->value, 'assertion.') && ! in_array($code->value, ['assertion.json.invalid', 'assertion.missing'], true)) || in_array($code->value, ['signingCredential.trusted', 'signingCredential.untrusted'], true) || str_starts_with($code->value, 'timeStamp.') || str_starts_with($code->value, 'ingredient.') || str_starts_with($code->value, 'signingCredential.ocsp.')) {
            continue;   // SPEC-011's, SPEC-012's, SPEC-014's, SPEC-017's, SPEC-020's and SPEC-030's, with their own successes and the informational
        }
        expect($code->isSuccess())->toBe($code === StatusCode::ClaimSignatureValidated, $code->value)
            ->and($code->isFailure())->toBe($code !== StatusCode::ClaimSignatureValidated, $code->value);
    }

    $ok = new ValidationStatus(StatusCode::ClaimSignatureValidated, 'self#jumbf=/c2pa/x/c2pa.signature', 'ok');
    $bad = new ValidationStatus(StatusCode::GeneralError, 'self#jumbf=/c2pa', 'no');
    expect(ValidationResult::fromStatuses([$ok], ['signature'])->state)->toBe(ValidationState::Valid)
        ->and(ValidationResult::fromStatuses([$ok, $bad], ['signature'])->state)->toBe(ValidationState::Invalid)
        ->and(ValidationResult::fromStatuses([], [])->state)->toBe(ValidationState::Invalid);
})->group('SPEC-010');
