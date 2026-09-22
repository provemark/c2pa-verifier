<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Tests\Support;

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Container\FormatDetector;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Container\WebpManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use RuntimeException;

/**
 * The corpus files as the tests read them (SPEC-016 AC3–AC10, SPEC-017):
 * the same road the verifier walks — format detector, extractor, JUMBF,
 * ManifestStore, CoseSign1 — so that a token the tests read is a token
 * the verifier will meet. No new fixtures: the tokens are cut out of the
 * files already in the repository.
 */
final class Corpus
{
    public static function fixtures(): string
    {
        return dirname(__DIR__).'/Fixtures';
    }

    /** The manifest store of a corpus file, or null when the file has none. */
    public static function manifestStore(string $relative): ?ManifestStore
    {
        $stream = fopen(self::fixtures().'/'.$relative, 'rb');
        if ($stream === false) {
            throw new RuntimeException("cannot open {$relative}");
        }
        $format = (new FormatDetector)->detect($stream);
        $store = match ($format) {
            'jpeg' => (new JpegManifestStoreExtractor)->extract($stream),
            'png' => (new PngManifestStoreExtractor)->extract($stream),
            'webp' => (new WebpManifestStoreExtractor)->extract($stream),
            default => null,
        };
        fclose($stream);
        if ($store === null) {
            return null;
        }

        return ManifestStore::fromTree((new JumbfParser)->parse($store->bytes));
    }

    /** The active manifest's COSE_Sign1 of a corpus file, or null when the file has no manifest store. */
    public static function cose(string $relative): ?CoseSign1
    {
        $manifests = self::manifestStore($relative);

        return $manifests === null ? null : CoseSign1::fromBytes($manifests->active->signatureBytes());
    }

    /** The raw sigTst / sigTst2 value (tstTokens[0].val) of the active manifest. */
    public static function headerValue(string $relative): string
    {
        $cose = self::cose($relative);
        if ($cose === null) {
            throw new RuntimeException("{$relative} has no manifest");
        }
        $header = $cose->unprotected['sigTst2'] ?? $cose->unprotected['sigTst'] ?? null;
        $first = is_array($header) && is_array($header['tstTokens'] ?? null) && is_array($header['tstTokens'][0] ?? null) ? $header['tstTokens'][0] : [];
        $val = $first['val'] ?? null;
        if (! $val instanceof CborBytes) {
            throw new RuntimeException("{$relative} carries no timestamp header");
        }

        return $val->bytes;
    }
}
