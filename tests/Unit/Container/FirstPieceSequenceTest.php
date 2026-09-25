<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Verifier\VerificationReport;

/*
 * SPEC-041: a JPEG store whose first APP11 piece carries Z = 0. The Bing Image Creator file is
 * tests/Fixtures/writers/microsoft-20260609-bing-fast-heartbeat.jpg; the Z variants come from
 * bin/make-spec041-variants.php under tests/Fixtures/first-piece-z/, both c2patool versions' answers
 * under tests/Fixtures/c2patool/first-piece-z/.
 */

const SPEC041_BING = 'writers/microsoft-20260609-bing-fast-heartbeat';

/** The store's bytes as the JPEG extractor returns them. */
function spec041Bytes(string $relative): string
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }

    return (new JpegManifestStoreExtractor)->extract($stream)->bytes ?? '';
}

/**
 * A report's statuses as "code url", in order.
 *
 * @return list<string>
 */
function spec041Statuses(VerificationReport $report): array
{
    return array_map(static fn ($status): string => $status->code->value.' '.$status->url, $report->result->statuses);
}

it('AC1: a single piece with Z = 0 is read', function (): void {
    $bytes = spec041Bytes(SPEC041_BING.'.jpg');
    $report = spec020Verify(SPEC041_BING.'.jpg');
    $codes = array_map(static fn ($status): StatusCode => $status->code, $report->result->statuses);

    // The piece's data after CI, En and Z: LBox 0x3319 = 13 081, then TBox "jumb". Amendments 1 and 2:
    // the file's signature URI is not this spec's concern, and stays claimSignature.missing by design.
    expect(strlen($bytes))->toBe(13081)
        ->and(bin2hex(substr($bytes, 0, 8)))->toBe('000033196a756d62')
        ->and(hash('sha256', $bytes))->toBe('66109c664aafa35562da2669e60d49eaa9eee8b132f03bcfeef1f0758222d901')
        ->and($codes)->not->toContain(StatusCode::GeneralError);
})->group('SPEC-041');

it('AC2: two pieces numbered 0, 2 are read', function (): void {
    $variant = spec020Verify('first-piece-z/zero-two.jpg');
    $original = spec020Verify('fixture-signed.jpg');

    expect($variant->result->state->value)->toBe('Valid')
        ->and(spec041Statuses($variant))->toBe(spec041Statuses($original));
    foreach (['0.27.22', '0.28.0'] as $version) {
        expect(spec020Oracle("first-piece-z/zero-two--{$version}.json")['validation_state'])->toBe('Valid', $version);
    }
})->group('SPEC-041');

it('AC3: after a first Z = 0 the second piece still needs Z = 2', function (): void {
    expect(fn () => spec041Bytes('first-piece-z/zero-one.jpg'))
        ->toThrow(ContainerException::class, 'expected 2, found 1');
    foreach (['0.27.22', '0.28.0'] as $version) {
        expect(trim((string) file_get_contents(Corpus::fixtures()."/c2patool/first-piece-z/zero-one--{$version}.error.txt")))
            ->toBe('Error: invalid embedded file box', $version);
    }
})->group('SPEC-041');

it('AC4: any other first Z stays refused', function (): void {
    foreach (['seven-eight', 'one-piece-seven'] as $variant) {
        expect(fn () => spec041Bytes("first-piece-z/{$variant}.jpg"))
            ->toThrow(ContainerException::class, 'expected 1, found 7');
        // The known difference: both c2patool versions read the file.
        foreach (['0.27.22', '0.28.0'] as $version) {
            expect(spec020Oracle("first-piece-z/{$variant}--{$version}.json")['validation_state'])->toBe('Valid', "{$variant} {$version}");
        }
    }
})->group('SPEC-041');

it('AC5: SPEC-001 AC3 is unchanged', function (): void {
    expect(fn () => spec041Bytes('jpeg/swapped-pieces.jpg'))
        ->toThrow(ContainerException::class, 'expected 1, found 2');
})->group('SPEC-041');
