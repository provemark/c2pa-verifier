<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\ManifestStoreBytes;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;

/*
 * SPEC-002: PNG caBX → manifest store bytes. The fixture and every variant
 * were measured against c2patool 0.27.22 in notes/step-04 and
 * tests/Fixtures/png/README.md; the criteria copy that behaviour.
 */

const SPEC002_STORE_LENGTH = 46025;

const SPEC002_STORE_SHA256 = '1a018eb892c4b30c112976cd7411df24baa9ce788cfe2904f58a69dec6e057df';

/** @return resource */
function spec002Stream(string $name)
{
    $path = dirname(__DIR__, 2).'/Fixtures/'.$name;
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$path}");
    }

    return $stream;
}

function spec002Extract(string $name, ?PngManifestStoreExtractor $extractor = null): ?ManifestStoreBytes
{
    return ($extractor ?? new PngManifestStoreExtractor)->extract(spec002Stream($name));
}

/** The store's bytes, or '' when there is none — so a null result fails the hash check, not the type check. */
function spec002Bytes(string $name): string
{
    return spec002Extract($name)->bytes ?? '';
}

it('AC1: extracts the store from the fixture, byte-exact', function (): void {
    expect(spec002Extract('fixture-signed.png'))->toBeInstanceOf(ManifestStoreBytes::class);

    $bytes = spec002Bytes('fixture-signed.png');

    expect(strlen($bytes))->toBe(SPEC002_STORE_LENGTH)
        ->and(hash('sha256', $bytes))->toBe(SPEC002_STORE_SHA256)
        ->and(bin2hex(substr($bytes, 0, 8)))->toBe('0000b3c96a756d62');
})->group('SPEC-002');
