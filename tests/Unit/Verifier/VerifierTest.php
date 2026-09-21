<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;
use Provemark\ContentCredentials\Core\Reading\ManifestStoreParser;

/*
 * SPEC-013: the Verifier — one call from file to verdict. The oracle is
 * every c2patool 0.27.22 JSON under tests/Fixtures/c2patool/ (steps 14–26),
 * its exit messages on unsigned and unknown files (step 28), and the sister
 * library's ManifestStoreParser reading our report.
 */

const SPEC013_PNG = 'self#jumbf=/c2pa/urn:c2pa:488bf983-c973-465d-a0eb-1597392cc5d0';

// SPEC013_CORPUS — the 22 recorded c2patool JSONs and their carriers — lives in tests/Pest.php, shared with SPEC-014.

/** The files where this verifier reports a strict subset of c2patool's failures, by decision (SPEC-013 AC10). */
const SPEC013_SUBSET_ONLY = [
    // decision 1 of SPEC-011: the data hash is skipped after a hashed-URI mismatch on c2pa.hash.data
    // (hashed-uri-changed and hashed-uris-two-changed are not here: c2patool's data hash *matched* on them, so the sets are equal)
    'variants/exclusions-overlap', 'variants/hashed-uri-truncated', 'variants/hash-as-text', 'variants/exclusions-too-many', 'variants/claim-alg-sha1',
    // a parse fault stops this verifier where c2patool goes on
    'variants/json-broken',
];

/** Codes this verifier does not emit yet: M5, and the assertion-content rules of later specs. */
// signingCredential.untrusted left this list with SPEC-014/015: without settings this verifier says it too (SPEC-014 amendment 1)
const SPEC013_NOT_YET = ['assertion.required.missing', 'assertion.action.redacted'];

/** @return resource */
function spec013Stream(string $relative)
{
    $stream = fopen(dirname(__DIR__, 2).'/Fixtures/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }

    return $stream;
}

function spec013Verify(string $relative): VerificationReport
{
    return (new Verifier)->verify(spec013Stream($relative));
}

/** @return array<string, mixed> */
function spec013C2patool(string $name): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/c2patool/'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The codes of c2patool's validation_status (its failures), sorted.
 *
 * @param  array<string, mixed>  $oracle
 * @return list<string>
 */
function spec013OracleFailures(array $oracle): array
{
    assert(is_array($oracle['validation_status']));
    $codes = [];
    foreach ($oracle['validation_status'] as $status) {
        assert(is_array($status) && is_string($status['code']));
        $codes[] = $status['code'];
    }
    sort($codes);

    return array_values(array_unique($codes));
}

/**
 * Whether c2patool recorded this code with this url, under any of its lists.
 *
 * @param  array<string, mixed>  $oracle
 */
function spec013OracleHas(array $oracle, string $code, string $url): bool
{
    $results = $oracle['validation_results'];
    assert(is_array($results) && is_array($results['activeManifest']));
    foreach ($results['activeManifest'] as $list) {
        assert(is_array($list));
        foreach ($list as $status) {
            assert(is_array($status) && is_string($status['code']) && is_string($status['url']));
            if ($status['code'] === $code && $status['url'] === $url) {
                return true;
            }
        }
    }

    return false;
}

/**
 * The url c2patool recorded for a code (the first, under any of its lists).
 *
 * @param  array<string, mixed>  $oracle
 */
function spec013OracleUrl(array $oracle, string $code): ?string
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

/** @return list<string> our failure codes, sorted, unique */
function spec013Failures(VerificationReport $report): array
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

/** @return list<string> */
function spec013Codes(VerificationReport $report): array
{
    return array_map(static fn (ValidationStatus $s): string => $s->code->value, $report->result->statuses);
}

it('AC1: the four fixtures, front door: Valid, three checks, and the report is c2patool\'s', function (): void {
    foreach (['jpg' => ['fixture-signed.jpg', 'jpeg', 3], 'png' => ['fixture-signed.png', 'png', 3], 'webp' => ['fixture-signed.webp', 'webp', 3], 'adobe-20220124-C' => ['public-testfiles/adobe-20220124-C.jpg', 'jpeg', 4]] as $name => [$fixture, $format, $entries]) {
        $report = spec013Verify($fixture);
        expect($report->format)->toBe($format, $name)
            ->and($report->hasManifest)->toBeTrue($name)
            ->and($report->result->state)->toBe(ValidationState::Valid, $name)
            // certificate and trust joined the list with SPEC-015/014: without settings the leaf is checked and found untrusted, as c2patool
            ->and($report->result->checksPerformed)->toBe(['signature', 'certificate', 'trust', 'hashedUris', 'dataHash'], $name)
            ->and(spec013Codes($report))->toBe(['claimSignature.validated', 'signingCredential.untrusted', ...array_fill(0, $entries, 'assertion.hashedURI.match'), 'assertion.dataHash.match'], $name);

        $oracle = spec013C2patool($name);
        expect($oracle['validation_state'])->toBe('Valid', $name);
        foreach ($report->result->statuses as $status) {
            expect(spec013OracleHas($oracle, $status->code->value, $status->url))->toBeTrue("{$name}: {$status->code->value} {$status->url}");
        }

        $sister = ManifestStoreParser::fromJson($report->toJson());
        expect($sister->validationState()?->value)->toBe('Valid', $name)
            ->and($sister->validationStatusCodes())->toBe(['signingCredential.untrusted'], $name)
            ->and($sister->isSignatureValid())->toBeTrue($name)
            ->and($sister->activeManifestLabel())->toBe($report->store?->active->label, $name);
    }
})->group('SPEC-013');

it('AC2: one changed pixel byte, front door: Invalid with assertion.dataHash.mismatch', function (): void {
    foreach (['binding/pixel-changed.png', 'binding/pixel-changed.jpg'] as $variant) {
        $report = spec013Verify($variant);
        expect($report->result->state)->toBe(ValidationState::Invalid, $variant)
            ->and($report->result->checksPerformed)->toBe(['signature', 'certificate', 'trust', 'hashedUris', 'dataHash'], $variant)
            ->and(spec013Failures($report))->toBe(['assertion.dataHash.mismatch', 'signingCredential.untrusted'], $variant)
            ->and(ManifestStoreParser::fromJson($report->toJson())->validationStatusCodes())->toBe(['signingCredential.untrusted', 'assertion.dataHash.mismatch'], $variant);
    }
    $report = spec013Verify('binding/pixel-changed.png');
    $oracle = spec013C2patool('variants/pixel-changed');
    $mismatch = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::AssertionDataHashMismatch));
    expect($mismatch[0]->url)->toBe(spec013OracleUrl($oracle, 'assertion.dataHash.mismatch'))
        ->and(array_values(array_diff(spec013OracleFailures($oracle), SPEC013_NOT_YET)))->toBe(['assertion.dataHash.mismatch', 'signingCredential.untrusted']);
})->group('SPEC-013');

it('AC3: a broken signature does not stop the verifier', function (): void {
    foreach (['claim-title-changed', 'signature-changed'] as $variant) {
        $report = spec013Verify("cose/{$variant}.png");
        expect($report->result->state)->toBe(ValidationState::Invalid, $variant)
            ->and($report->result->checksPerformed)->toBe(['signature', 'certificate', 'trust', 'hashedUris', 'dataHash'], $variant)
            ->and(spec013Failures($report))->toBe(['claimSignature.mismatch', 'signingCredential.untrusted'], $variant)
            ->and(spec013Codes($report))->toContain('assertion.dataHash.match')
            ->and(array_values(array_diff(spec013OracleFailures(spec013C2patool("variants/{$variant}")), SPEC013_NOT_YET)))->toBe(['claimSignature.mismatch', 'signingCredential.untrusted'], $variant);
    }
})->group('SPEC-013');

it('AC4: the data hash is skipped when the claim does not vouch for it', function (): void {
    foreach (['pad-nonzero', 'exclusions-overlap', 'exclusion-past-end', 'alg-sha1'] as $variant) {
        $report = spec013Verify("binding/{$variant}.png");
        expect($report->result->checksPerformed)->toBe(['signature', 'certificate', 'trust', 'hashedUris'], $variant)
            ->and(array_filter(spec013Codes($report), static fn (string $c): bool => str_starts_with($c, 'assertion.dataHash')))->toBe([], $variant)
            ->and(spec013Failures($report))->toBe(['assertion.hashedURI.mismatch', 'signingCredential.untrusted'], $variant)
            ->and($report->result->state)->toBe(ValidationState::Invalid, $variant);
        $mismatch = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::AssertionHashedUriMismatch));
        expect($mismatch)->toHaveCount(1, $variant)
            ->and($mismatch[0]->url)->toBe(SPEC013_PNG.'/c2pa.assertions/c2pa.hash.data', $variant);
    }
    // c2patool evaluates the data hash anyway: a strict superset of our failures, the same verdict
    $theirs = array_values(array_diff(spec013OracleFailures(spec013C2patool('variants/exclusions-overlap')), SPEC013_NOT_YET));
    expect($theirs)->toBe(['assertion.dataHash.mismatch', 'assertion.hashedURI.mismatch', 'signingCredential.untrusted'])
        ->and(spec013C2patool('variants/exclusions-overlap')['validation_state'])->toBe('Invalid');
})->group('SPEC-013');

it('AC5: no manifest: not valid, not an error', function (): void {
    foreach (['fixture-unsigned.jpg' => 'jpeg', 'fixture-unsigned.png' => 'png', 'fixture-unsigned.webp' => 'webp'] as $fixture => $format) {
        $report = spec013Verify($fixture);
        expect($report->format)->toBe($format, $fixture)
            ->and($report->hasManifest)->toBeFalse($fixture)
            ->and($report->store)->toBeNull($fixture)
            ->and($report->result->statuses)->toBe([], $fixture)
            ->and($report->result->checksPerformed)->toBe([], $fixture)
            ->and($report->result->state)->toBe(ValidationState::Invalid, $fixture);
        $array = $report->toArray();
        expect($array['active_manifest'])->toBeNull($fixture)
            ->and($array['manifests'])->toBe([], $fixture)
            ->and($array['has_manifest'])->toBeFalse($fixture)
            ->and(ManifestStoreParser::fromJson($report->toJson())->hasManifest())->toBeFalse($fixture);
    }
})->group('SPEC-013');

it('AC6: an unknown format is an error, and nothing is read past the magic bytes', function (): void {
    $empty = fopen('php://memory', 'r+b');
    $short = fopen('php://memory', 'r+b');
    assert($empty !== false && $short !== false);
    fwrite($short, "\x89PNG\x0D\x0A\x1A\x00");
    rewind($short);

    foreach (['not-a-jpeg' => spec013Stream('jpeg/not-a-jpeg.bin'), 'empty' => $empty, 'short' => $short] as $name => $stream) {
        $report = (new Verifier)->verify($stream);
        expect($report->format)->toBe('unknown', $name)
            ->and($report->hasManifest)->toBeFalse($name)
            ->and(spec013Codes($report))->toBe(['general.error'], $name)
            ->and($report->result->statuses[0]->url)->toBe('self#jumbf=/c2pa', $name)
            ->and($report->result->state)->toBe(ValidationState::Invalid, $name)
            ->and(ftell($stream))->toBe(0, $name);
    }
    $report = (new Verifier)->verify(spec013Stream('jpeg/not-a-jpeg.bin'));
    expect($report->result->statuses[0]->explanation)->toContain('54 68 69 73');   // "This"
    $report = (new Verifier)->verify($short);
    expect($report->result->statuses[0]->explanation)->toContain('89 50 4E 47 0D 0A 1A 00');
})->group('SPEC-013');

it('AC7: the parsers\' faults become statuses with their codes and urls', function (): void {
    $cases = [
        'jpeg/truncated-in-piece-2.jpg' => ['general.error', 'self#jumbf=/c2pa'],
        'jumbf/lbox-zero.png' => ['general.error', 'self#jumbf=/c2pa'],
        'cbor/claim-indefinite-array.png' => ['claim.cbor.invalid', SPEC013_PNG.'/c2pa.claim.v2'],
        'claim/second-claim.png' => ['claim.multiple', SPEC013_PNG.'/c2pa.claim.v2'],
        'claim/claim-no-signature.png' => ['claim.malformed', SPEC013_PNG.'/c2pa.claim.v2'],
        'claim/no-manifest.png' => ['claim.missing', 'self#jumbf=/c2pa'],
        'claim/json-broken.png' => ['assertion.json.invalid', 'self#jumbf=/c2pa/contentauth:urn:uuid:4d971750-1db4-4492-a87c-5c3e7ed33efc/c2pa.assertions/stds.schema-org.CreativeWork'],
    ];
    foreach ($cases as $file => [$code, $url]) {
        $report = spec013Verify($file);
        expect($report->hasManifest)->toBeTrue($file)
            ->and(spec013Codes($report))->toBe([$code], $file)
            ->and($report->result->statuses[0]->url)->toBe($url, $file)
            ->and($report->result->statuses[0]->explanation)->not->toBe('', $file)
            ->and($report->result->checksPerformed)->toBe([], $file)
            ->and($report->result->state)->toBe(ValidationState::Invalid, $file);
    }
    // SPEC-010 AC7, closed: c2patool's code for json-broken, our absolute url where it prints a bare label
    expect(spec013OracleFailures(spec013C2patool('variants/json-broken')))->toContain('assertion.json.invalid')
        ->and(spec013OracleUrl(spec013C2patool('variants/json-broken'), 'assertion.json.invalid'))->toBe('stds.schema-org.CreativeWork');
})->group('SPEC-013');

it('AC8: the report\'s shape, and the sister parser reads it', function (): void {
    $valid = spec013Verify('fixture-signed.png');
    $tampered = spec013Verify('binding/pixel-changed.png');
    $oracle = spec013C2patool('png');

    foreach ([$valid, $tampered] as $report) {
        $array = $report->toArray();
        // validation_status is present only when there is a failure (SPEC-013 amendment 3); without settings there is always one — untrusted (SPEC-014 amendment 1)
        expect(array_keys($array))->toBe(['active_manifest', 'manifests', 'validation_results', 'validation_state', 'validation_status', 'format', 'has_manifest', 'checks_performed']);
        assert(is_array($array['manifests']) && is_array($oracle['manifests']) && is_string($array['active_manifest']));
        expect(array_keys($array['manifests']))->toBe(array_keys($oracle['manifests']));
        $ours = $array['manifests'][$array['active_manifest']];
        $theirs = $oracle['manifests'][$array['active_manifest']];
        assert(is_array($ours) && is_array($theirs) && is_array($array['validation_results']));
        foreach (array_keys($ours) as $key) {
            expect($theirs)->toHaveKey($key);
        }
        assert(is_array($array['validation_results']['activeManifest']));
        expect(array_keys($array['validation_results']['activeManifest']))->toBe(['success', 'informational', 'failure']);
    }

    $sister = ManifestStoreParser::fromJson($valid->toJson());
    $reference = ManifestStoreParser::fromJson((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/c2patool/png.json'));
    expect($sister->softwareAgents())->toBe($reference->softwareAgents())
        ->and($sister->digitalSourceTypes())->toBe($reference->digitalSourceTypes())
        ->and($sister->isAiGenerated())->toBe($reference->isAiGenerated())
        ->and($sister->validationState()?->value)->toBe($reference->validationState()?->value)
        ->and($sister->activeManifestLabel())->toBe($reference->activeManifestLabel())
        ->and($sister->isTrusted())->toBeFalse()
        ->and($reference->isTrusted())->toBeFalse();
})->group('SPEC-013');

it('AC9: end to end, the file is streamed', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'spec013-big');
    $out = fopen($tmp, 'wb');
    assert($out !== false);
    fwrite($out, (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/fixture-signed.png'));
    $chunk = str_repeat("\x5a", 1024 * 1024);
    for ($i = 0; $i < 48; $i++) {
        fwrite($out, $chunk);
    }
    fclose($out);
    unset($chunk);
    $stream = fopen($tmp, 'rb');
    assert($stream !== false);

    $before = memory_get_peak_usage();
    $report = (new Verifier)->verify($stream);
    $grown = memory_get_peak_usage() - $before;
    unlink($tmp);

    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and(spec013Failures($report))->toBe(['assertion.dataHash.mismatch', 'signingCredential.untrusted'])
        ->and($report->result->checksPerformed)->toBe(['signature', 'certificate', 'trust', 'hashedUris', 'dataHash'])
        ->and($grown)->toBeLessThan(4 * 1024 * 1024, sprintf('peak memory grew by %d bytes', $grown));
})->group('SPEC-013');

it('AC10: the drift alarm: every recorded c2patool JSON, state and failures', function (): void {
    $seen = 0;
    $subsetOnly = [];
    foreach (SPEC013_CORPUS as $name => $carrier) {
        $seen++;
        $report = spec013Verify($carrier);
        $oracle = spec013C2patool($name);

        expect($report->result->state->value)->toBe($oracle['validation_state'], "{$name}: validation_state");

        // theirs, minus what this verifier does not emit yet
        $theirs = array_values(array_diff(spec013OracleFailures($oracle), SPEC013_NOT_YET));

        // ours, normalised by the divergences SPEC-011/012 recorded
        $ours = [];
        foreach ($report->result->statuses as $status) {
            if (! $status->code->isFailure()) {
                continue;
            }
            $ours[] = match ($status->code) {
                StatusCode::AssertionDataHashMalformed => 'assertion.dataHash.mismatch',
                StatusCode::AlgorithmUnsupported => str_ends_with($status->url, '/c2pa.assertions/c2pa.hash.data') && in_array('dataHash', $report->result->checksPerformed, true) && ! in_array(StatusCode::AssertionHashedUriMismatch, array_map(static fn (ValidationStatus $s): StatusCode => $s->code, $report->result->statuses), true)
                    ? 'assertion.dataHash.mismatch'
                    : 'assertion.hashedURI.mismatch',
                StatusCode::GeneralError, StatusCode::AssertionUndeclared => null,
                default => $status->code->value,
            };
        }
        $ours = array_values(array_unique(array_filter($ours, static fn (?string $c): bool => $c !== null)));
        sort($ours);

        $side = sprintf("%s\n  ours:   %s\n  theirs: %s", $name, implode(', ', $ours), implode(', ', $theirs));
        expect(array_diff($ours, $theirs))->toBe([], "not a subset — {$side}");

        $complete = $report->result->checksPerformed === ['signature', 'certificate', 'trust', 'hashedUris', 'dataHash']
            && ! in_array(StatusCode::GeneralError, array_map(static fn (ValidationStatus $s): StatusCode => $s->code, $report->result->statuses), true)
            && ! in_array(StatusCode::AssertionUndeclared, array_map(static fn (ValidationStatus $s): StatusCode => $s->code, $report->result->statuses), true);
        if ($ours !== $theirs) {
            $subsetOnly[] = $name;
            expect($complete)->toBeFalse("subset yet complete — {$side}");
        } else {
            expect(in_array($name, SPEC013_SUBSET_ONLY, true))->toBeFalse("expected a strict subset — {$side}");
        }
    }
    expect($seen)->toBe(22);
    sort($subsetOnly);
    $expected = SPEC013_SUBSET_ONLY;
    sort($expected);
    expect($subsetOnly)->toBe($expected);
})->group('SPEC-013');

it('AC11: a store with more than one manifest is refused until M7', function (): void {
    $full = TrustSettings::fromJson((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/trust/full.settings.json'));
    $verify = static function (string $name) use ($full): VerificationReport {
        $path = glob(dirname(__DIR__, 2)."/Fixtures/public-testfiles/{$name}.*")[0] ?? throw new RuntimeException("no file for {$name}");

        return (new Verifier)->verify(spec013Stream('public-testfiles/'.basename($path)), $full);
    };
    $c2patool = static function (string $name): array {
        /** @var array<string, mixed> */
        return json_decode((string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/c2patool/public-testfiles/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
    };

    foreach (SPEC013_PUBLIC_MULTI as $name) {
        $report = $verify($name);
        $errors = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::GeneralError));
        $count = $report->store === null ? 0 : count($report->store->manifests);
        expect($count)->toBeGreaterThan(1, $name)
            ->and($errors)->toHaveCount(1, $name)
            ->and($errors[0]->url)->toBe('self#jumbf=/c2pa', $name)
            ->and($errors[0]->explanation)->toContain((string) $count)
            ->and($errors[0]->explanation)->toContain('M7')
            ->and($report->result->checksPerformed)->toBe(['signature', 'certificate', 'trust', 'hashedUris', 'dataHash'], $name)
            ->and($report->result->state)->toBe(ValidationState::Invalid, $name);
    }
    $single = $verify('adobe-20220124-C');
    expect(array_filter($single->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::GeneralError))->toBe([])
        ->and($single->result->state)->toBe(ValidationState::Trusted);
    // the file that made the rule: tampered only in its ingredient manifest
    expect($verify('adobe-20220124-E-uri-CIE-sig-CA')->result->state)->toBe(ValidationState::Invalid)
        ->and($c2patool('adobe-20220124-E-uri-CIE-sig-CA')['validation_state'])->toBe('Invalid');

    // the second drift alarm: the official corpus, c2patool's state unless stricter on purpose
    foreach (SPEC013_PUBLIC_CORPUS as $name) {
        $report = $verify($name);
        $oracle = $c2patool($name);
        $expected = $oracle['validation_state'];
        if (in_array($name, SPEC013_PUBLIC_MULTI, true) || in_array($name, SPEC013_PUBLIC_NO_TIMESTAMP, true)) {
            $expected = 'Invalid';
        }
        expect($report->result->state->value)->toBe($expected, $name);
        if (in_array($name, SPEC013_PUBLIC_NO_TIMESTAMP, true)) {
            expect(in_array('signingCredential.expired', spec013Failures($report), true))->toBeTrue($name);   // M6 removes this
        }
    }
    foreach (['adobe-20220124-A', 'adobe-20220124-I'] as $name) {
        expect($verify($name)->hasManifest)->toBeFalse($name);
    }
})->group('SPEC-013');
