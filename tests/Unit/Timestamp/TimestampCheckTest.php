<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Tests\Support\DerPatch;
use Provemark\C2paVerifier\Timestamp\TimestampCheck;
use Provemark\C2paVerifier\Timestamp\TimestampHeader;
use Provemark\C2paVerifier\Timestamp\TimestampResult;
use Provemark\C2paVerifier\Timestamp\TimeStampToken;
use Provemark\C2paVerifier\Timestamp\TstInfo;
use Provemark\C2paVerifier\Trust\Certificate;
use Provemark\C2paVerifier\Trust\CertificateProfileCheck;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-017: the timestamp check — the CMS signature, the imprint, the
 * TSA's trust, the six timeStamp.* codes, and the time SPEC-015 judges at.
 * Oracles: the 41 c2patool JSONs of the two external corpora, the JSONs
 * under the two TSA anchors (tests/Fixtures/c2patool/timestamp/, step 42a),
 * the step-40 digests, and openssl_verify on SPEC-016 AC5's cut.
 */

const SPEC017_EKU_TIME_STAMPING = '1.3.6.1.5.5.7.3.8';

function spec017Settings(string $variant): TrustSettings
{
    return TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures()."/trust/{$variant}.settings.json"));
}

function spec017Verify(string $relative, ?TrustSettings $settings = null): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }

    return (new Verifier)->verify($stream, $settings);
}

/** @return array<string, mixed> */
function spec017Oracle(string $relative): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/{$relative}.json"), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The codes of c2patool's activeManifest list ('success' | 'informational' | 'failure'), in order.
 *
 * @param  array<string, mixed>  $oracle
 * @return list<string>
 */
function spec017OracleCodes(array $oracle, string $list): array
{
    $results = $oracle['validation_results'] ?? [];
    assert(is_array($results));
    $active = $results['activeManifest'] ?? [];
    assert(is_array($active));
    $entries = $active[$list] ?? [];
    assert(is_array($entries));
    $codes = [];
    foreach ($entries as $entry) {
        assert(is_array($entry) && is_string($entry['code']));
        $codes[] = $entry['code'];
    }

    return $codes;
}

/** @return list<string> the codes of a report's statuses, in order */
function spec017Codes(VerificationReport|TimestampResult $report): array
{
    $statuses = $report instanceof VerificationReport ? $report->result->statuses : $report->statuses;

    return array_map(static fn (ValidationStatus $s): string => $s->code->value, $statuses);
}

function spec017Status(VerificationReport|TimestampResult $report, StatusCode $code): ?ValidationStatus
{
    $statuses = $report instanceof VerificationReport ? $report->result->statuses : $report->statuses;
    foreach ($statuses as $status) {
        if ($status->code === $code) {
            return $status;
        }
    }

    return null;
}

/** @return list<string> the failure codes of a report, sorted */
function spec017Failures(VerificationReport $report): array
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

/**
 * @param  list<string>  $codes
 * @return list<string>
 */
function spec017Sorted(array $codes): array
{
    sort($codes);

    return array_values(array_unique($codes));
}

function spec017Manifest(string $relative): Manifest
{
    $store = Corpus::manifestStore($relative);
    if ($store === null) {
        throw new RuntimeException("{$relative} has no manifest");
    }

    return $store->active;
}

/** The countersigned bytes of a manifest's own timestamp header, through the code under test. */
function spec017Tbs(string $relative): string
{
    $manifest = spec017Manifest($relative);
    $cose = CoseSign1::fromBytes($manifest->signatureBytes());
    $header = TimestampHeader::fromUnprotected($cose->unprotected);
    if ($header === null) {
        throw new RuntimeException("{$relative} has no timestamp header");
    }

    return TimestampCheck::countersignedBytes($cose, $header->header, $manifest->claimBytes());
}

function spec017Url(string $relative): string
{
    return sprintf('self#jumbf=/c2pa/%s/c2pa.signature', spec017Manifest($relative)->label);
}

/**
 * The fields of the one SignerInfo of a sigTst value (a TimeStampResp), by offset:
 * version, sid, digestAlgorithm, signedAttrs, signatureAlgorithm, signature.
 *
 * @return list<array{offset: int, headerLength: int, length: int, tag: int}>
 */
function spec017SignerInfoFields(string $bytes): array
{
    $set = DerPatch::signerInfoSet($bytes);
    $si = DerPatch::element($bytes, $set['contents']);
    $child = $set['contents'] + $si['headerLength'];
    $end = $child + $si['length'];
    $fields = [];
    while ($child < $end) {
        $e = DerPatch::element($bytes, $child);
        $fields[] = ['offset' => $child, 'headerLength' => $e['headerLength'], 'length' => $e['length'], 'tag' => $e['tag']];
        $child += $e['headerLength'] + $e['length'];
    }

    return $fields;
}

/** One bit of the CMS signature (the last field of the SignerInfo, an OCTET STRING) flipped. */
function spec017FlipSignature(string $bytes): string
{
    $fields = spec017SignerInfoFields($bytes);
    $signature = $fields[count($fields) - 1];
    if ($signature['tag'] !== 0x04) {
        throw new LogicException('the last SignerInfo field is not the signature');
    }
    $at = $signature['offset'] + $signature['headerLength'] + 7;

    return substr_replace($bytes, chr(ord($bytes[$at]) ^ 0x01), $at, 1);
}

// ---------------------------------------------------------------------------
// AC1 — every corpus token c2patool validates, this verifier validates, and the time is c2patool's

test('SPEC-017 AC1: every corpus token c2patool validates, this verifier validates, and the time is c2patool\'s', function (): void {
    $files = [];
    $unreadable = [];
    foreach (['public-testfiles', 'c2pa-rs'] as $dir) {
        foreach (glob(Corpus::fixtures()."/c2patool/{$dir}/*.json") ?: [] as $json) {
            $name = basename($json, '.json');
            $oracle = spec017Oracle("{$dir}/{$name}");
            if (! in_array('timeStamp.validated', spec017OracleCodes($oracle, 'success'), true)) {
                continue;
            }
            $path = glob(Corpus::fixtures()."/{$dir}/{$name}.*")[0] ?? null;
            if ($path === null) {
                continue;
            }
            try {
                if (Corpus::manifestStore("{$dir}/".basename($path)) === null) {
                    continue;   // cloud.jpg: the manifest c2patool fetched over the network is not here
                }
            } catch (Throwable $e) {
                $unreadable[] = "{$dir}/{$name}: ".$e->getMessage();

                continue;   // a store this verifier refuses before any check (M2 refusals) — counted, not judged
            }
            $files["{$dir}/".basename($path)] = $oracle;
        }
    }
    expect($unreadable)->toBe([], 'measured in step 42a: every validated file is readable here')
        ->and(count($files))->toBe(35, implode(', ', array_keys($files)));

    $check = new TimestampCheck;
    foreach ($files as $relative => $oracle) {
        $result = $check->check(spec017Manifest($relative), null);
        $validated = spec017Status($result, StatusCode::TimeStampValidated);
        expect($result->present)->toBeTrue($relative)
            ->and($validated)->not->toBeNull($relative);
        assert($validated !== null);
        expect($validated->url)->toBe(spec017Url($relative), $relative)
            ->and($result->time)->not->toBeNull($relative);
        assert($result->time !== null);
        $manifests = $oracle['manifests'];
        assert(is_array($manifests) && is_string($oracle['active_manifest']));
        $info = $manifests[$oracle['active_manifest']]['signature_info'] ?? null;
        assert(is_array($info));
        expect(gmdate('c', $result->time))->toBe($info['time'], $relative);
        // without anchors the TSA is not trusted, and no time is handed on
        expect(spec017Codes($result))->toContain('timeStamp.untrusted')
            ->and($result->trustedTime())->toBeNull($relative);
    }

    // through the front door, on the files whose store this verifier reads (one manifest, no CAWG assertion)
    $multi = array_merge(SPEC013_PUBLIC_MULTI, SPEC013_RS_MULTI, SPEC013_RS_CAWG);
    $seen = 0;
    foreach ($files as $relative => $oracle) {
        $name = pathinfo($relative, PATHINFO_FILENAME);
        if (in_array($name, $multi, true)) {
            continue;
        }
        $settings = str_starts_with($relative, 'c2pa-rs/') ? spec017Settings('full') : null;
        $report = spec017Verify($relative, $settings);
        $codes = spec017Codes($report);
        expect($codes[0])->toBe('timeStamp.validated', $relative)
            ->and($report->result->checksPerformed[0])->toBe('timestamp', $relative);
        $array = $report->toArray();
        assert(is_array($array['validation_results']) && is_array($array['validation_results']['activeManifest']) && is_array($array['validation_results']['activeManifest']['success']));
        $first = $array['validation_results']['activeManifest']['success'][0];
        assert(is_array($first));
        expect($first['code'])->toBe('timeStamp.validated', $relative);
        // the verdict is what it was: c2patool's, except the Truepic files (expired here until their TSA is an anchor, AC6)
        $expected = str_starts_with($name, 'truepic-') ? 'Invalid' : $oracle['validation_state'];
        expect($report->result->state->value)->toBe($expected, $relative);
        $seen++;
    }
    expect($seen)->toBeGreaterThanOrEqual(10);
})->group('SPEC-017');

// ---------------------------------------------------------------------------
// AC2 — the corpus's two failures, and no verdict moves

test('SPEC-017 AC2: E-sig-CA is a mismatch, CA_ct is malformed, and no verdict moves', function (): void {
    foreach (['public-testfiles/adobe-20220124-E-sig-CA.jpg' => null, 'c2pa-rs/E-sig-CA.jpg' => spec017Settings('full')] as $relative => $settings) {
        $report = spec017Verify($relative, $settings);
        $mismatch = spec017Status($report, StatusCode::TimeStampMismatch);
        expect($mismatch)->not->toBeNull($relative);
        assert($mismatch !== null);
        expect($mismatch->explanation)->toContain('imprint')
            ->and(spec017Codes($report))->not->toContain('timeStamp.validated')
            ->and($report->signatureInfo)->not->toHaveKey('time')
            ->and($report->result->state->value)->toBe('Invalid', $relative)
            ->and(array_values(array_unique(array_map(static fn (ValidationStatus $s): string => $s->code->value, array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->isFailure())))))->toContain('claimSignature.mismatch');
        $array = $report->toArray();
        assert(is_array($array['validation_results']) && is_array($array['validation_results']['activeManifest']) && is_array($array['validation_results']['activeManifest']['informational']));
        $informational = array_map(static fn (mixed $e): mixed => is_array($e) ? $e['code'] : null, $array['validation_results']['activeManifest']['informational']);
        expect($informational)->toContain('timeStamp.mismatch');
        $oracle = spec017Oracle(str_replace('.jpg', '', $relative));
        expect(spec017OracleCodes($oracle, 'informational'))->toContain('timeStamp.mismatch');
    }

    $report = spec017Verify('c2pa-rs/CA_ct.jpg', spec017Settings('full'));
    $malformed = spec017Status($report, StatusCode::TimeStampMalformed);
    expect($malformed)->not->toBeNull();
    assert($malformed !== null);
    expect($malformed->explanation)->toContain('genTime')->toContain('63')->toContain('offset 86')
        ->and(spec017Codes($report))->not->toContain('timeStamp.validated')
        ->and($report->signatureInfo)->not->toHaveKey('time')
        ->and($report->result->state->value)->toBe(spec017Oracle('c2pa-rs/CA_ct')['validation_state'])
        ->and(spec017OracleCodes(spec017Oracle('c2pa-rs/CA_ct'), 'informational'))->toContain('timeStamp.malformed');
})->group('SPEC-017');

// ---------------------------------------------------------------------------
// AC3 — the CMS signature, three algorithms, one flipped bit each

foreach ([
    'c2pa-rs/C.jpg' => 'rsaEncryption',
    'public-testfiles/truepic-20230212-camera.jpg' => 'sha384WithRSAEncryption',
    'c2pa-rs/ocsp.jpg' => 'ecdsa-with-SHA256',
] as $relative => $algorithm) {
    test('SPEC-017 AC3: the CMS signature — '.$algorithm.' on '.$relative, function () use ($relative, $algorithm): void {
        $value = Corpus::headerValue($relative);
        $tbs = spec017Tbs($relative);
        $url = spec017Url($relative);
        $check = new TimestampCheck;

        $good = $check->judge(TimeStampToken::fromHeaderValue($value), $tbs, null, $url);
        expect(spec017Codes($good))->toBe(['timeStamp.validated', 'timeStamp.untrusted'], $relative)
            ->and($good->time)->not->toBeNull();

        $flipped = $check->judge(TimeStampToken::fromHeaderValue(spec017FlipSignature($value)), $tbs, null, $url);
        $untrusted = spec017Status($flipped, StatusCode::TimeStampUntrusted);
        expect(spec017Codes($flipped))->toBe(['timeStamp.untrusted'], $relative)
            ->and($untrusted?->explanation)->toContain('signature')->toContain($algorithm)
            ->and($flipped->time)->toBeNull();
    })->group('SPEC-017');
}

test('SPEC-017 AC3: a signature algorithm outside the list is untrusted, naming the OID', function (): void {
    $value = Corpus::headerValue('c2pa-rs/C.jpg');
    $fields = spec017SignerInfoFields($value);
    $sigAlg = $fields[count($fields) - 2];
    expect($sigAlg['tag'])->toBe(0x30);
    $oidValue = $sigAlg['offset'] + $sigAlg['headerLength'] + 2;   // SEQUENCE { OID rsaEncryption, NULL }
    expect(bin2hex(substr($value, $oidValue, 9)))->toBe('2a864886f70d010101');
    $patched = substr_replace($value, "\x02", $oidValue + 8, 1);   // 1.2.840.113549.1.1.2, md2WithRSAEncryption
    $result = (new TimestampCheck)->judge(TimeStampToken::fromHeaderValue($patched), spec017Tbs('c2pa-rs/C.jpg'), null, spec017Url('c2pa-rs/C.jpg'));
    expect(spec017Codes($result))->toBe(['timeStamp.untrusted'])
        ->and(spec017Status($result, StatusCode::TimeStampUntrusted)?->explanation)->toContain('1.2.840.113549.1.1.2');
})->group('SPEC-017');

// ---------------------------------------------------------------------------
// AC4 — messageDigest, sid, validity: one status each

test('SPEC-017 AC4: messageDigest, sid and validity each give exactly one status; the control validates', function (): void {
    $value = Corpus::headerValue('c2pa-rs/C.jpg');
    $tbs = spec017Tbs('c2pa-rs/C.jpg');
    $url = spec017Url('c2pa-rs/C.jpg');
    $check = new TimestampCheck;

    // (d) the control
    $control = $check->judge(TimeStampToken::fromHeaderValue($value), $tbs, null, $url);
    expect(spec017Codes($control))->toBe(['timeStamp.validated', 'timeStamp.untrusted']);

    // (a) one byte of the messageDigest attribute's value: SEQUENCE { OID (11), SET { OCTET STRING (2+32) } }
    $attribute = DerPatch::attribute($value, '2a864886f70d010904');
    $digestValue = $attribute['offset'] + $attribute['headerLength'] + 11 + 2 + 2;
    $patched = substr_replace($value, chr(ord($value[$digestValue]) ^ 0x01), $digestValue, 1);
    $a = $check->judge(TimeStampToken::fromHeaderValue($patched), $tbs, null, $url);
    expect(spec017Codes($a))->toBe(['timeStamp.mismatch'])
        ->and(spec017Status($a, StatusCode::TimeStampMismatch)?->explanation)->toContain('messageDigest')
        ->and($a->time)->toBeNull();

    // (b) the sid's serial: IssuerAndSerialNumber = SEQUENCE { Name, INTEGER } — the INTEGER's last byte
    $sid = spec017SignerInfoFields($value)[1];
    expect($sid['tag'])->toBe(0x30);
    $name = DerPatch::element($value, $sid['offset'] + $sid['headerLength']);
    $serialAt = $sid['offset'] + $sid['headerLength'] + $name['headerLength'] + $name['length'];
    $serial = DerPatch::element($value, $serialAt);
    expect($serial['tag'])->toBe(0x02);
    $lastByte = $serialAt + $serial['headerLength'] + $serial['length'] - 1;
    $patched = substr_replace($value, chr(ord($value[$lastByte]) ^ 0x01), $lastByte, 1);
    $b = $check->judge(TimeStampToken::fromHeaderValue($patched), $tbs, null, $url);
    expect(spec017Codes($b))->toBe(['timeStamp.malformed'])
        ->and(spec017Status($b, StatusCode::TimeStampMalformed)?->explanation)->toContain('no certificate')
        ->and($b->time)->toBeNull();

    // (c) a genTime outside the TSA certificate's validity (DigiCert Timestamp 2023: 2023-07-14 .. 2034-10-13)
    $token = TimeStampToken::fromHeaderValue($value);
    $tst = $token->tstInfo;
    $early = new TstInfo($tst->version, $tst->policy, $tst->hashAlgorithm, $tst->hashedMessage, $tst->serialNumber, (int) gmmktime(0, 0, 0, 1, 1, 2010), $tst->accuracy, $tst->ordering, $tst->nonce, $tst->tsa, $tst->extensions);
    $c = $check->judge(new TimeStampToken($token->signedData, $early, $token->responseStatus), $tbs, null, $url);
    $outside = spec017Status($c, StatusCode::TimeStampOutsideValidity);
    expect(spec017Codes($c))->toBe(['timeStamp.outsideValidity'])
        ->and($outside?->explanation)->toContain('2010-01-01')->toContain('2023-07-14')->toContain('2034-10-13')
        ->and($c->time)->toBeNull();
})->group('SPEC-017');

// ---------------------------------------------------------------------------
// AC5 — the countersigned bytes: v1 over the claim, v2 over the signature

test('SPEC-017 AC5: the countersigned bytes equal the four imprints, and the wrong payload is a mismatch', function (): void {
    $expected = [
        'c2pa-rs/C.jpg' => ['sigTst', 'sha256', '64d055da'],
        'public-testfiles/adobe-20220124-C.jpg' => ['sigTst', 'sha256', '0432594d'],
        'public-testfiles/truepic-20230212-camera.jpg' => ['sigTst', 'sha384', 'd8160464'],
        'c2pa-rs/C_with_CAWG_data.jpg' => ['sigTst2', 'sha256', '37ab305c'],
    ];
    foreach ($expected as $relative => [$header, $hash, $imprint]) {
        $manifest = spec017Manifest($relative);
        $cose = CoseSign1::fromBytes($manifest->signatureBytes());
        $found = TimestampHeader::fromUnprotected($cose->unprotected);
        expect($found?->header)->toBe($header, $relative);
        $tbs = TimestampCheck::countersignedBytes($cose, $header, $manifest->claimBytes());
        expect(substr($tbs, 0, 18))->toBe("\x84\x70CounterSignature", $relative)
            ->and(bin2hex(substr(hash($hash, $tbs, true), 0, 4)))->toBe($imprint, $relative)
            ->and(TimeStampToken::fromHeaderValue(Corpus::headerValue($relative))->tstInfo->hashedMessage)->toBe(hash($hash, $tbs, true), $relative);
    }

    // C.jpg's token judged against the sigTst2-style bytes (the signature bstr): a mismatch, not an error
    $manifest = spec017Manifest('c2pa-rs/C.jpg');
    $cose = CoseSign1::fromBytes($manifest->signatureBytes());
    $wrong = TimestampCheck::countersignedBytes($cose, 'sigTst2', $manifest->claimBytes());
    $result = (new TimestampCheck)->judge(TimeStampToken::fromHeaderValue(Corpus::headerValue('c2pa-rs/C.jpg')), $wrong, null, spec017Url('c2pa-rs/C.jpg'));
    expect(spec017Codes($result))->toBe(['timeStamp.mismatch'])
        ->and(spec017Status($result, StatusCode::TimeStampMismatch)?->explanation)->toContain('imprint');
})->group('SPEC-017');

// ---------------------------------------------------------------------------
// AC6 — trust only through an anchor: Truepic's root and DigiCert's cross-certificate

test('SPEC-017 AC6: the Truepic root as anchor: trusted, no longer expired, and c2patool\'s verdict', function (): void {
    $settings = spec017Settings('truepic-root');
    foreach (['truepic-20230212-camera', 'truepic-20230212-landscape', 'truepic-20230212-library'] as $name) {
        $report = spec017Verify("public-testfiles/{$name}.jpg", $settings);
        $codes = spec017Codes($report);
        $oracle = spec017Oracle("timestamp/{$name}-truepic-root");
        expect($codes)->toContain('timeStamp.validated', $name)
            ->and($codes)->toContain('timeStamp.trusted', $name)
            ->and($codes)->not->toContain('signingCredential.expired', $name)
            ->and($report->result->state->value)->toBe($oracle['validation_state'], $name)
            ->and(spec017Failures($report))->toBe(spec017Sorted(spec017OracleCodes($oracle, 'failure')), $name);
        $trusted = spec017Status($report, StatusCode::TimeStampTrusted);
        expect($trusted?->explanation)->toContain('Truepic Lens Time-Stamping Authority');

        // without the anchor: untrusted, and expired at now — with the reason
        $bare = spec017Verify("public-testfiles/{$name}.jpg");
        $untrusted = spec017Status($bare, StatusCode::TimeStampUntrusted);
        $expired = spec017Status($bare, StatusCode::SigningCredentialExpired);
        expect($untrusted?->explanation)->toContain('no trust anchors configured')
            ->and($expired)->not->toBeNull($name);
        assert($expired !== null);
        expect($expired->explanation)->toContain('now')->toContain('not trusted');
    }
})->group('SPEC-017');

test('SPEC-017 AC6: the DigiCert cross-certificate as anchor: C.jpg\'s TSA chain reaches it', function (): void {
    $settings = spec017Settings('digicert-trusted-root-g4');
    $report = spec017Verify('c2pa-rs/C.jpg', $settings);
    $oracle = spec017Oracle('timestamp/C-digicert-g4');
    $trusted = spec017Status($report, StatusCode::TimeStampTrusted);
    expect($trusted)->not->toBeNull();
    assert($trusted !== null);
    expect($trusted->explanation)->toContain('DigiCert Timestamp 2023')
        ->and($report->result->state->value)->toBe($oracle['validation_state'])   // Valid: the C2PA test signer reaches no DigiCert anchor
        ->and(spec017Failures($report))->toBe(spec017Sorted(spec017OracleCodes($oracle, 'failure')));

    $bare = spec017Verify('c2pa-rs/C.jpg');
    expect(spec017Status($bare, StatusCode::TimeStampUntrusted)?->explanation)->toContain('no trust anchors configured')
        ->and(spec017Codes($bare))->not->toContain('timeStamp.trusted');
})->group('SPEC-017');

// ---------------------------------------------------------------------------
// AC7 — the TSA profile: timeStamping alone, at the token's time

test('SPEC-017 AC7: the TSA profile accepts timeStamping alone, and tsaSettings() carries nothing else', function (): void {
    $token = TimeStampToken::fromHeaderValue(Corpus::headerValue('c2pa-rs/C.jpg'));
    $signer = $token->signedData->signerCertificate();
    assert($signer !== null);
    $tsa = Certificate::fromDer($signer);
    $url = spec017Url('c2pa-rs/C.jpg');
    $profile = new CertificateProfileCheck;

    $settings = TimestampCheck::tsaSettings(null);
    expect($settings->trustConfig)->toBe([SPEC017_EKU_TIME_STAMPING])
        ->and($settings->trustAnchors)->toBe([])
        ->and($settings->allowedList)->toBe([])
        ->and($settings->verifyTrust)->toBeTrue();
    $full = spec017Settings('full');
    $withOperator = TimestampCheck::tsaSettings($full);
    expect(count($withOperator->trustAnchors))->toBe(count($full->trustAnchors))
        ->and($withOperator->trustConfig)->toBe([SPEC017_EKU_TIME_STAMPING]);

    $faults = $profile->checkLeaf($tsa, $settings, $token->tstInfo->genTime, $url, ekus: [SPEC017_EKU_TIME_STAMPING]);
    expect(array_map(static fn (ValidationStatus $s): string => $s->code->value, $faults))->not->toContain('signingCredential.invalid');

    // the counter-example: our own leaf (EKU emailProtection) is not a TSA
    $pems = (string) file_get_contents(Corpus::fixtures().'/trust/es256_certs.pem');
    preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pems, $m);
    $leafDer = (string) base64_decode(preg_replace('/-----.*?-----|\s/', '', $m[0]) ?? '', true);
    $leaf = Certificate::fromDer($leafDer);
    $faults = $profile->checkLeaf($leaf, $settings, null, $url, ekus: [SPEC017_EKU_TIME_STAMPING]);
    $invalid = array_values(array_filter($faults, static fn (ValidationStatus $s): bool => $s->code === StatusCode::SigningCredentialInvalid));
    expect($invalid)->toHaveCount(1)
        ->and($invalid[0]->explanation)->toContain('EKU');
    // and the same leaf under the ordinary list (no override) passes, as SPEC-015 measured
    expect($profile->checkLeaf($leaf, null, null, $url))->toBe([]);
})->group('SPEC-017');

// ---------------------------------------------------------------------------
// AC8 — the header: absent, one token, more than one

test('SPEC-017 AC8: no header: nothing reported, no time, Nikon stays expired at now', function (): void {
    foreach (['public-testfiles/nikon-20221019-building.jpg', 'binding/fixture-signed.jpg', 'binding/fixture-signed.png', 'binding/fixture-signed.webp'] as $relative) {
        $result = (new TimestampCheck)->check(spec017Manifest($relative), null);
        expect($result->present)->toBeFalse($relative)
            ->and($result->statuses)->toBe([])
            ->and($result->time)->toBeNull()
            ->and($result->trustedTime())->toBeNull();
        $report = spec017Verify($relative);
        expect($report->result->checksPerformed)->not->toContain('timestamp')
            ->and($report->signatureInfo)->not->toHaveKey('time');
    }
    $nikon = spec017Verify('public-testfiles/nikon-20221019-building.jpg');
    $expired = spec017Status($nikon, StatusCode::SigningCredentialExpired);
    expect($expired?->explanation)->toContain('now')->toContain('no timestamp');
})->group('SPEC-017');

test('SPEC-017 AC8: one token is judged; a doubled header judges the first only and says so', function (): void {
    $manifest = spec017Manifest('c2pa-rs/C.jpg');
    $cose = CoseSign1::fromBytes($manifest->signatureBytes());
    $value = Corpus::headerValue('c2pa-rs/C.jpg');
    $url = spec017Url('c2pa-rs/C.jpg');
    $check = new TimestampCheck;

    $one = $check->checkHeader(new TimestampHeader('sigTst', [$value]), $cose, $manifest->claimBytes(), null, $url);
    expect(spec017Codes($one))->toBe(['timeStamp.validated', 'timeStamp.untrusted'])
        ->and(spec017Status($one, StatusCode::TimeStampValidated)?->explanation)->not->toContain('of 2');

    $two = $check->checkHeader(new TimestampHeader('sigTst', [$value, $value]), $cose, $manifest->claimBytes(), null, $url);
    expect(spec017Codes($two))->toBe(['timeStamp.validated', 'timeStamp.untrusted'])
        ->and(spec017Status($two, StatusCode::TimeStampValidated)?->explanation)->toContain('1 of 2 tokens');
})->group('SPEC-017');

// ---------------------------------------------------------------------------
// AC9 — the report: order, keys, and the time SPEC-015 used

test('SPEC-017 AC9: the report — timeStamp entries first, signature_info with time equal to c2patool\'s, checks_performed', function (): void {
    $report = spec017Verify('c2pa-rs/C.jpg', spec017Settings('digicert-trusted-root-g4'));
    $array = $report->toArray();
    assert(is_array($array['validation_results']) && is_array($array['validation_results']['activeManifest']) && is_array($array['validation_results']['activeManifest']['success']));
    $success = array_map(static fn (mixed $e): mixed => is_array($e) ? $e['code'] : null, $array['validation_results']['activeManifest']['success']);
    expect(array_slice($success, 0, 2))->toBe(['timeStamp.validated', 'timeStamp.trusted'])
        ->and($success[2])->toBe('claimSignature.validated');

    $oracle = spec017Oracle('timestamp/C-digicert-g4');
    $manifests = $oracle['manifests'];
    assert(is_array($manifests) && is_string($oracle['active_manifest']));
    $theirs = $manifests[$oracle['active_manifest']]['signature_info'];
    assert(is_array($array['manifests']) && is_string($array['active_manifest']) && is_array($array['manifests'][$array['active_manifest']]));
    $ours = $array['manifests'][$array['active_manifest']]['signature_info'];
    expect($ours)->toBe($theirs)   // byte for byte, keys in c2patool's order: alg, issuer, common_name, cert_serial_number, time
        ->and(array_keys($ours))->toBe(['alg', 'issuer', 'common_name', 'cert_serial_number', 'time'])
        ->and($array['checks_performed'])->toBe(['timestamp', 'signature', 'certificate', 'trust', 'hashedUris', 'dataHash']);

    $plain = spec017Verify('binding/fixture-signed.jpg')->toArray();
    assert(is_array($plain['manifests']) && is_string($plain['active_manifest']) && is_array($plain['manifests'][$plain['active_manifest']]));
    expect($plain['manifests'][$plain['active_manifest']]['signature_info'])->not->toHaveKey('time')
        ->and($plain['checks_performed'])->toBe(['signature', 'certificate', 'trust', 'hashedUris', 'dataHash']);
})->group('SPEC-017');

// ---------------------------------------------------------------------------
// AC10 — the drift alarms without their timestamp exceptions

test('SPEC-017 AC10: the NO_TIMESTAMP exceptions are gone; TSA_NOT_CONFIGURED names the files that stay expired, and an anchor un-expires them', function (): void {
    expect(defined('SPEC013_PUBLIC_NO_TIMESTAMP'))->toBeFalse()
        ->and(defined('SPEC013_RS_NO_TIMESTAMP'))->toBeFalse()
        ->and(SPEC013_PUBLIC_TSA_NOT_CONFIGURED)->toBe(['truepic-20230212-camera', 'truepic-20230212-landscape', 'truepic-20230212-library'])
        ->and(SPEC013_RS_TSA_NOT_CONFIGURED)->toBe(['ocsp', 'ocsp_with_assertion', 'exp-test1']);

    // exp-test1.png: expired under `full` (its DigiCert TSA reaches no test anchor), not under `full` plus the cross-certificate; Invalid either way (six manifests)
    $under = spec017Verify('c2pa-rs/exp-test1.png', spec017Settings('full'));
    $with = spec017Verify('c2pa-rs/exp-test1.png', spec017Settings('full-plus-digicert-g4'));
    expect(spec017Failures($under))->toContain('signingCredential.expired')
        ->and(spec017Failures($with))->not->toContain('signingCredential.expired')
        ->and($under->result->state->value)->toBe('Invalid')
        ->and($with->result->state->value)->toBe('Invalid')
        ->and(spec017Oracle('timestamp/exp-test1-full-plus-digicert-g4')['validation_state'])->toBe('Invalid');
})->group('SPEC-017');
