<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * Step 59, the coverage matrix: the three unsigned fixtures signed with every algorithm this
 * verifier implements, plus two files whose data hash is sha384 and sha512 — the cells the four
 * corpora left empty. Built by bin/make-matrix-fixtures.php with c2patool 0.27.22 and c2pa-rs's
 * public test certificates; the oracle is c2patool's JSON beside each file, recorded twice (with
 * the test roots and without settings). This is the fifth drift alarm (SPEC-013 amendment 12).
 */

/** @return list<string> every matrix file, as a fixture-relative path */
function spec013Matrix(): array
{
    $files = [];
    foreach (glob(Corpus::fixtures().'/matrix/*.{jpg,png,webp}', GLOB_BRACE) ?: [] as $path) {
        $files[] = 'matrix/'.basename($path);
    }
    sort($files);

    return $files;
}

function spec013MatrixSettings(): TrustSettings
{
    return TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/matrix/test-roots.settings.json'));
}

/** @return array<string, mixed> */
function spec013MatrixOracle(string $relative, bool $trusted): array
{
    $name = basename($relative);

    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/matrix/{$name}".($trusted ? '.trusted' : '').'.json'), true, 512, JSON_THROW_ON_ERROR);
}

function spec013MatrixVerify(string $relative, ?TrustSettings $settings): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }

    return (new Verifier)->verify($stream, $settings);
}

it('AC16: the matrix covers every algorithm this verifier implements, in every format it reads', function (): void {
    $files = spec013Matrix();
    expect($files)->toHaveCount(23);   // 7 algorithms × 3 formats, plus the two hash-algorithm files

    $signature = [];
    $hash = [];
    $formats = [];
    foreach ($files as $relative) {
        $report = spec013MatrixVerify($relative, spec013MatrixSettings());
        $store = $report->store ?? throw new RuntimeException($relative);
        $alg = $report->signatureInfo['alg'] ?? throw new RuntimeException("{$relative}: no signature_info");
        $data = $store->active->assertions['c2pa.hash.data']->data;
        assert(is_array($data) && is_string($data['alg']));
        $signature[$alg] = ($signature[$alg] ?? 0) + 1;
        $hash[$data['alg']] = ($hash[$data['alg']] ?? 0) + 1;
        $formats[$report->format] = ($formats[$report->format] ?? 0) + 1;
    }
    ksort($signature);
    ksort($hash);
    ksort($formats);

    expect(array_keys($signature))->toBe(['Ed25519', 'Es256', 'Es384', 'Es512', 'Ps256', 'Ps384', 'Ps512'])
        ->and(array_keys($hash))->toBe(['sha256', 'sha384', 'sha512'])
        ->and($formats)->toBe(['jpeg' => 7, 'png' => 9, 'webp' => 7]);
})->group('SPEC-013');

it('AC17: the matrix is the fifth drift alarm: c2patool\'s state and codes, with and without the test roots', function (): void {
    $trusted = spec013MatrixSettings();
    foreach (spec013Matrix() as $relative) {
        foreach ([true, false] as $withSettings) {
            $report = spec013MatrixVerify($relative, $withSettings ? $trusted : null);
            $oracle = spec013MatrixOracle($relative, $withSettings);
            $label = $relative.($withSettings ? ' (roots)' : '');

            expect($report->result->state->value)->toBe($oracle['validation_state'], $label);
            $theirs = [];
            foreach ((array) ($oracle['validation_status'] ?? []) as $status) {
                assert(is_array($status) && is_string($status['code']));
                $theirs[$status['code']] = true;
            }
            $ours = [];
            foreach ($report->result->statuses as $status) {
                if ($status->code->isFailure()) {
                    $ours[$status->code->value] = true;
                }
            }
            ksort($theirs);
            ksort($ours);
            expect(array_keys($ours))->toBe(array_keys($theirs), $label);
            // the signer, as c2patool prints it
            assert(is_array($oracle['manifests']) && is_string($oracle['active_manifest']));
            $manifest = $oracle['manifests'][$oracle['active_manifest']];
            assert(is_array($manifest) && is_array($manifest['signature_info']));
            expect($report->signatureInfo['alg'] ?? null)->toBe($manifest['signature_info']['alg'], $label)
                ->and($report->signatureInfo['cert_serial_number'] ?? null)->toBe($manifest['signature_info']['cert_serial_number'], $label);
        }
    }
})->group('SPEC-013');

it('AC18: every matrix file is Trusted with the roots and untrusted without them', function (): void {
    $trusted = spec013MatrixSettings();
    foreach (spec013Matrix() as $relative) {
        $with = spec013MatrixVerify($relative, $trusted);
        $without = spec013MatrixVerify($relative, null);
        $codes = static fn (VerificationReport $r): array => array_map(static fn (ValidationStatus $s): string => $s->code->value, $r->result->statuses);

        expect($with->result->state)->toBe(ValidationState::Trusted, $relative)
            ->and(in_array(StatusCode::SigningCredentialTrusted->value, $codes($with), true))->toBeTrue($relative)
            ->and($without->result->state)->toBe(ValidationState::Valid, $relative)
            ->and(in_array(StatusCode::SigningCredentialUntrusted->value, $codes($without), true))->toBeTrue($relative);
    }
})->group('SPEC-013');
