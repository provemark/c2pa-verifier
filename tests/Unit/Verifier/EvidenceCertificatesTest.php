<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cose\CoseException;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestException;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Verifier\ManifestEvidence;
use Provemark\C2paVerifier\Verifier\VerificationReport;

/*
 * SPEC-083: the certificates of x5chain as evidence. The hashes and sizes
 * were measured on 2026-10-27 with CoseSign1::ofManifest() over the
 * verifier at 15d378c.
 */

const SPEC083_CONTROL = [
    ['64b274043395a7d67c6c30387b3fc514e47b8c1912b0be487efda979c8fba5a9', 540],
    ['6c431bd08d9ab0be5df53afe3372f25351eb0e6e6351f6a43ef5d5f98cb3520b', 523],
];

const SPEC083_TIME_NUL = [
    ['fa7c16f8b1a3cb39a6212ef27636acd4fc10a1910ea48d096d25350736998914', 651],
    ['3c4b61503a62b9afdf593e9e01a1fbe91afa800b6f18cf51f9836a5b65f7376d', 622],
];

/**
 * @param  list<string>  $certificates
 * @return list<array{string, int}> each certificate's SHA-256 and size, in order
 */
function spec083Shape(array $certificates): array
{
    return array_map(static fn (string $der): array => [hash('sha256', $der), strlen($der)], $certificates);
}

/** @return list<string> the active manifest's certificates in evidence() */
function spec083Active(string $relative, ?string $settings = null): array
{
    $report = spec075Report($relative, $settings);
    $label = $report->store->active->label ?? '';
    expect($report->evidence())->toHaveKey($label);

    return $report->evidence()[$label]->certificates;
}

it('AC1: the chain, byte for byte, the same with settings as without', function (): void {
    $without = spec083Active('timestamp/tsa-matrix/control.png');
    $with = spec083Active('timestamp/tsa-matrix/control.png', 'timestamp/tsa-matrix/control.settings.json');

    expect(spec083Shape($without))->toBe(SPEC083_CONTROL)
        ->and($with)->toBe($without);
})->group('SPEC-083');

it('AC2: certificates X.509 refuses are still handed out as their bytes', function (): void {
    $report = spec075Report('hostile/certificate-time-nul.jpg', 'trust/full.settings.json');
    $label = $report->store->active->label ?? '';
    $codes = array_map(static fn ($s): string => $s->code->value, $report->result->statuses);

    expect(spec083Shape($report->evidence()[$label]->certificates))->toBe(SPEC083_TIME_NUL)
        ->and($codes)->toContain('signingCredential.invalid')
        ->and($report->toArray()['validation_state'])->toBe('Invalid');
})->group('SPEC-083');

/** @return array<string, list<string>> each manifest's certificates in evidence(), by label, after checking them against its x5chain */
function spec083Chains(string $relative): array
{
    $report = spec075Report($relative, 'trust/full.settings.json');
    $chains = [];
    foreach ($report->evidence() as $label => $e) {
        $chain = array_map(static fn ($c): string => $c->bytes, CoseSign1::ofManifest(spec083Manifest($report, $label))->chain);
        expect($e->certificates)->toBe($chain);
        $chains[$label] = $e->certificates;
    }

    return $chains;
}

it('AC3: each manifest carries its own chain', function (): void {
    $caca = spec083Chains('c2pa-rs/CACA.jpg');
    // CACA.jpg's two manifests share one signer; ocsp.jpg's do not (measured 2026-10-27), so it shows
    // that a manifest's chain is not the active one's
    $ocsp = array_values(spec083Chains('c2pa-rs/ocsp.jpg'));

    expect($caca)->toHaveCount(2)
        ->and(array_map(count(...), $caca))->toBe(array_fill_keys(array_keys($caca), 2))
        ->and($ocsp)->toHaveCount(2)
        ->and($ocsp[0])->not->toBe([])
        ->and($ocsp[1])->not->toBe([])
        ->and($ocsp[0])->not->toBe($ocsp[1]);
})->group('SPEC-083');

it('AC4: no chain to show when COSE refuses the signature', function (string $relative): void {
    expect(spec083Active($relative, 'trust/full.settings.json'))->toBe([]);
})->with([
    'leaf-der-broken' => ['cose/leaf-der-broken.png'],
    'chain-empty' => ['cose/chain-empty.png'],
    'x5chain-missing' => ['cose/x5chain-missing.png'],
])->group('SPEC-083');

it('AC5: over the corpus, the evidence agrees with the COSE reader', function (): void {
    $root = Corpus::fixtures();
    $manifests = 0;
    $chains = 0;
    $disagreements = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || ! preg_match('/\.(jpe?g|png|webp|gif|tiff?|svg|wav|mp3|flac|avi|mp4|mov|m4a|heic|heif|avif|txt|md)$/i', $file->getFilename())) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        $report = spec075Report($relative);
        foreach ($report->evidence() as $label => $e) {
            $manifests++;
            try {
                $expected = array_map(static fn ($c): string => $c->bytes, CoseSign1::ofManifest(spec083Manifest($report, $label))->chain);
            } catch (CoseException|ManifestException) {
                $expected = [];
            }
            $chains += $expected === [] ? 0 : 1;
            if ($e->certificates !== $expected || count($e->certificates) > CoseSign1::DEFAULT_MAX_CHAIN) {
                $disagreements[] = "{$relative}: {$label}";
            }
        }
    }

    expect($disagreements)->toBe([])
        ->and($chains)->toBeGreaterThan(0)
        ->and($manifests)->toBeGreaterThan($chains);
})->group('SPEC-083');

it('AC6: the reports carry no certificate bytes', function (): void {
    // the corpus run before and after is in the step's note; here the shapes are pinned on control.png
    $report = spec075Report('timestamp/tsa-matrix/control.png', 'timestamp/tsa-matrix/control.settings.json');
    $label = $report->store->active->label ?? '';
    $leaf = base64_encode($report->evidence()[$label]->certificates[0] ?? '');

    expect($leaf)->not->toBe('')
        ->and($report->toJson())->not->toContain($leaf)
        ->and($report->toCrJson())->not->toContain($leaf);
})->group('SPEC-083');

it('AC7: certificates is a contract property', function (): void {
    expect((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/api/public-surface.txt'))
        ->toContain('Verifier\ManifestEvidence :: property certificates')
        ->and(property_exists(ManifestEvidence::class, 'certificates'))->toBeTrue();
})->group('SPEC-083');

function spec083Manifest(VerificationReport $report, string $label): Manifest
{
    return $report->store->manifests[$label] ?? throw new RuntimeException("{$label}: in evidence(), but not in the store");
}
