<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cli\Command;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustException;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-031: the `trust.anchors` list, and a loose `allowed_list` refused.
 * Fixtures from bin/make-anchors-variants.php (step 111a): the settings under
 * tests/Fixtures/trust/anchors/, the probe signed by a leaf whose only EKU is
 * 1.3.6.1.4.1.99999.1, and c2patool 0.28.0's answers under
 * tests/Fixtures/c2patool/anchors/.
 */

function spec031Settings(string $name): TrustSettings
{
    $path = str_contains($name, '/') ? Corpus::fixtures().'/'.$name : Corpus::fixtures().'/trust/anchors/'.$name.'.settings.json';

    return TrustSettings::fromJson((string) file_get_contents($path));
}

function spec031Verify(string $relative, ?TrustSettings $settings): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }

    return (new Verifier)->verify($stream, $settings);
}

/** @return array<string, mixed> */
function spec031Oracle(string $relative, string $settings): array
{
    $path = Corpus::fixtures().'/c2patool/anchors/'.str_replace('/', '_', $relative).'--'.$settings.'.json';

    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The codes of the active manifest that start with $prefix, sorted and unique.
 *
 * @return list<string>
 */
function spec031Codes(VerificationReport $report, string $prefix): array
{
    $codes = [];
    foreach ($report->result->statuses as $status) {
        if ($status->ingredientUri === null && str_starts_with($status->code->value, $prefix)) {
            $codes[$status->code->value] = true;
        }
    }
    $codes = array_keys($codes);
    sort($codes);

    return $codes;
}

/**
 * @param  array<string, mixed>  $oracle
 * @return list<string>
 */
function spec031OracleCodes(array $oracle, string $prefix): array
{
    $codes = [];
    $results = $oracle['validation_results'];
    assert(is_array($results) && is_array($results['activeManifest']));
    foreach (['success', 'informational', 'failure'] as $kind) {
        $list = $results['activeManifest'][$kind] ?? [];
        assert(is_array($list));
        foreach ($list as $entry) {
            assert(is_array($entry) && is_string($entry['code']));
            if (str_starts_with($entry['code'], $prefix)) {
                $codes[$entry['code']] = true;
            }
        }
    }
    $codes = array_keys($codes);
    sort($codes);

    return $codes;
}

function spec031Status(VerificationReport $report, string $code): ?ValidationStatus
{
    foreach ($report->result->statuses as $status) {
        if ($status->ingredientUri === null && $status->code->value === $code) {
            return $status;
        }
    }

    return null;
}

/** The report as JSON with the two wall-clock moments masked (the CLI drift test's mask, step 104). */
function spec031Json(VerificationReport $report): string
{
    return (string) preg_replace(
        ['/expired at \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z: /', '/the judged time is \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/'],
        ['expired at <now>: ', 'the judged time is <now>'],
        $report->toJson(),
    );
}

function spec031Refusal(string $json): string
{
    try {
        TrustSettings::fromJson($json);
    } catch (TrustException $e) {
        return $e->getMessage();
    }

    return '(no refusal)';
}

// ---------------------------------------------------------------------------

it('AC1: the new shape is read, and gives the same verdict as the old', function (): void {
    $old = spec031Settings('trust/full.settings.json');
    $new = spec031Settings('full');
    foreach (['fixture-signed.jpg', 'fixture-signed.png', 'fixture-signed.webp', 'fixture-signed.mp4'] as $file) {
        $report = spec031Verify($file, $new);
        expect(spec031Json($report))->toBe(spec031Json(spec031Verify($file, $old)), $file)
            ->and($report->result->state->value)->toBe('Trusted', $file)
            ->and(spec031Oracle($file, 'full')['validation_state'])->toBe('Trusted', $file);
    }
})->group('SPEC-031');

it('AC2: the old shape still works, and both together add up', function (): void {
    $combined = spec031Settings('legacy-plus-truepic-entry');
    $jpeg = spec031Verify('fixture-signed.jpg', $combined);
    expect($jpeg->result->state->value)->toBe('Trusted')
        ->and(spec031Oracle('fixture-signed.jpg', 'legacy-plus-truepic-entry')['validation_state'])->toBe('Trusted');

    // the Truepic signer reaches the entry's root; only the signer is compared (its data hash is SPEC-012 amendment 7's)
    $truepic = spec031Verify('public-testfiles/truepic-20230212-camera.jpg', $combined);
    expect(spec031Codes($truepic, 'signingCredential.trusted'))->toBe(['signingCredential.trusted'])
        ->and(spec031OracleCodes(spec031Oracle('public-testfiles/truepic-20230212-camera.jpg', 'legacy-plus-truepic-entry'), 'signingCredential.trusted'))->toBe(['signingCredential.trusted']);
})->group('SPEC-031');

it('AC3: the allowed list lives in the anchor now', function (): void {
    $report = spec031Verify('fixture-signed.jpg', spec031Settings('allowed-in-entry'));
    expect($report->result->state->value)->toBe('Trusted')
        ->and(spec031Codes($report, 'signingCredential.trusted'))->toBe(['signingCredential.trusted'])
        ->and(spec031Oracle('fixture-signed.jpg', 'allowed-in-entry')['validation_state'])->toBe('Trusted');
})->group('SPEC-031');

it('AC4: a loose allowed_list is refused, and says where it went', function (): void {
    $oracle = ['allowed-only' => 'Valid', 'full-plus-allowed' => 'Trusted', 'allowed-plus-wrong-root' => 'Valid'];
    foreach ($oracle as $name => $state) {
        $path = Corpus::fixtures()."/trust/{$name}.settings.json";
        expect(str_contains(spec031Refusal((string) file_get_contents($path)), 'trust.anchors[].allowed_list'))->toBeTrue($name);

        $out = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        assert($out !== false && $err !== false);
        $status = (new Command(new Verifier))->run([Corpus::fixtures().'/fixture-signed.jpg', '--settings', $path], $out, $err);
        rewind($out);
        rewind($err);
        expect($status)->toBe(2, $name)
            ->and(stream_get_contents($out))->toBe('', $name)
            ->and(str_contains((string) stream_get_contents($err), 'trust.anchors[].allowed_list'))->toBeTrue($name);

        // what c2patool 0.28.0 does with the same file: it drops the field and says nothing
        expect(spec031Oracle('fixture-signed.jpg', "loose-{$name}")['validation_state'])->toBe($state, $name);
    }
})->group('SPEC-031');

it('AC5: malformed trust.anchors is refused whole', function (): void {
    $pem = (string) file_get_contents(Corpus::fixtures().'/trust/trust_anchors.pem');
    $entry = ['trust_anchors' => $pem, 'trust_kind' => 'manifest'];
    $json = static fn (array $trust): string => json_encode(['trust' => $trust], JSON_THROW_ON_ERROR);
    $cases = [
        'anchors as an object' => [$json(['anchors' => $entry]), ['trust.anchors', 'not a list']],
        'no trust_kind' => [$json(['anchors' => [['trust_anchors' => $pem]]]), ['trust.anchors[0]', 'trust_kind']],
        'unknown trust_kind' => [$json(['anchors' => [['trust_anchors' => $pem, 'trust_kind' => 'signer']]]), ['trust.anchors[0]', 'trust_kind', 'signer']],
        'no trust_anchors' => [$json(['anchors' => [$entry, ['trust_kind' => 'manifest']]]), ['trust.anchors[1]', 'trust_anchors']],
        'trust_anchors a number' => [$json(['anchors' => [['trust_anchors' => 7, 'trust_kind' => 'manifest']]]), ['trust.anchors[0]', 'trust_anchors']],
        'unknown key' => [$json(['anchors' => [$entry + ['foo' => 1]]]), ['trust.anchors[0]', 'foo']],
        'too many entries' => [$json(['anchors' => array_fill(0, 33, ['trust_anchors' => '', 'trust_kind' => 'manifest'])]), ['trust.anchors', 'more than 32']],
        'too many certificates' => [$json(['anchors' => [
            ['trust_anchors' => str_repeat($pem, 64), 'trust_kind' => 'manifest'],
            ['trust_anchors' => str_repeat($pem, 65), 'trust_kind' => 'tsa'],
        ]]), ['more than 256']],
    ];
    foreach ($cases as $label => [$settings, $needles]) {
        $message = spec031Refusal($settings);
        expect($message)->not->toBe('(no refusal)', $label);
        foreach ($needles as $needle) {
            expect(str_contains($message, $needle))->toBeTrue("{$label}: '{$needle}' in: {$message}");
        }
    }
    // the unknown key is where this verifier is stricter than c2patool 0.28.0, which ignores it (probe N9)
})->group('SPEC-031');

it('AC6: every anchor counts only for its own kind', function (): void {
    // the signer side: the test roots as a TSA list, or as a CAWG list, anchor no signer here — c2patool 0.28.0 says Trusted
    foreach (['test-roots-as-tsa', 'test-roots-as-cawg'] as $name) {
        $report = spec031Verify('fixture-signed.jpg', spec031Settings($name));
        expect($report->result->state->value)->toBe('Valid', $name)
            ->and(spec031Codes($report, 'signingCredential.untrusted'))->toBe(['signingCredential.untrusted'], $name)
            ->and(spec031Oracle('fixture-signed.jpg', $name)['validation_state'])->toBe('Trusted', $name);
    }

    // §14.4.3: an allowed list never applies to time-stamps, so a "tsa" entry cannot carry one
    $refusal = spec031Refusal((string) file_get_contents(Corpus::fixtures().'/trust/anchors/tsa-with-allowed-list.settings.json'));
    expect(str_contains($refusal, '§14.4.3'))->toBeTrue($refusal);

    // the time-stamping side: the DigiCert cross-certificate trusts C.jpg's TSA as a "tsa" entry, and not as a "manifest" one
    $asTsa = spec031Verify('c2pa-rs/C.jpg', spec031Settings('digicert-as-tsa'));
    expect(spec031Codes($asTsa, 'timeStamp.'))->toBe(['timeStamp.trusted', 'timeStamp.validated']);
    $asManifest = spec031Verify('c2pa-rs/C.jpg', spec031Settings('digicert-as-manifest'));
    expect(spec031Codes($asManifest, 'timeStamp.'))->toBe(['timeStamp.untrusted', 'timeStamp.validated']);
    $untrusted = spec031Status($asManifest, 'timeStamp.untrusted');
    expect(str_contains((string) $untrusted?->explanation, 'trust_kind'))->toBeTrue((string) $untrusted?->explanation);
    // c2patool 0.28.0 says timeStamp.trusted for both (probe T2; and without any anchor too, docs/comparison.md)
    expect(spec031OracleCodes(spec031Oracle('c2pa-rs/C.jpg', 'digicert-as-manifest'), 'timeStamp.trusted'))->toBe(['timeStamp.trusted']);

    // the legacy string keeps counting for both: SPEC-017 AC6's settings, unchanged
    $legacy = spec031Verify('c2pa-rs/C.jpg', spec031Settings('trust/digicert-trusted-root-g4.settings.json'));
    expect(spec031Codes($legacy, 'timeStamp.trusted'))->toBe(['timeStamp.trusted']);
})->group('SPEC-031');

it('AC7: the drift alarm learns the new shape', function (): void {
    $files = [];
    foreach (['public-testfiles', 'c2pa-rs', 'writers'] as $dir) {
        foreach (glob(Corpus::fixtures()."/{$dir}/*.{jpg,jpeg,png,webp,mp4,mov,avif,heic}", GLOB_BRACE) ?: [] as $path) {
            $files[] = "{$dir}/".basename($path);
        }
    }
    expect(count($files))->toBeGreaterThan(50);
    $pairs = [
        'trust/full.settings.json' => 'full-twin',
        'trust/full-plus-digicert-g4.settings.json' => 'full-plus-digicert-g4-twin',
    ];
    foreach ($pairs as $old => $twin) {
        $legacy = spec031Settings($old);
        $new = spec031Settings($twin);
        foreach ($files as $file) {
            expect(spec031Json(spec031Verify($file, $new)))->toBe(spec031Json(spec031Verify($file, $legacy)), "{$file} with {$twin}");
        }
    }
})->group('SPEC-031');

it('AC8: a trust_config counts for its own entry', function (): void {
    $probe = 'trust/anchors/eku-probe.jpg';
    $cases = [
        'e1-legacy-no-config' => 'Invalid',
        'e2-legacy-top-config' => 'Trusted',
        'e2b-legacy-store-cfg' => 'Invalid',
        'e3-entry-no-config' => 'Invalid',
        'e4-entry-own-config' => 'Trusted',
        'e5-config-on-another-entry' => 'Invalid',
        'e6-entry-config-top-store-cfg' => 'Trusted',
        'e7-entry-email-top-config' => 'Trusted',
        'e8-entry-no-config-top-config' => 'Trusted',
        'e9-no-anchors-top-config' => 'Valid',
    ];
    foreach ($cases as $name => $state) {
        $oracle = spec031Oracle($probe, $name);
        $report = spec031Verify($probe, spec031Settings($name));
        expect($oracle['validation_state'])->toBe($state, "{$name}: the oracle")
            ->and($report->result->state->value)->toBe($state, $name)
            ->and(spec031Codes($report, 'signingCredential.'))->toBe(spec031OracleCodes($oracle, 'signingCredential.'), $name);
    }
    // E5 is the case a union across entries would have called Trusted
    $e5 = spec031Verify($probe, spec031Settings('e5-config-on-another-entry'));
    expect(spec031Codes($e5, 'signingCredential.invalid'))->toBe(['signingCredential.invalid']);
})->group('SPEC-031');
