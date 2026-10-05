<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

use Provemark\C2paVerifier\Support\MemoryBudget;

/**
 * WebP RIFF `C2PA` → manifest store bytes (SPEC-003; C2PA 2.4 §A.3.7).
 *
 * The walk is `RiffManifestStoreExtractor`'s, shared with the other RIFF
 * forms since step 206; this class gives it the form type `WEBP` and keeps
 * the name, constructor and limit SPEC-003 describes.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class WebpManifestStoreExtractor
{
    public const DEFAULT_MAX_CHUNK_LENGTH = RiffManifestStoreExtractor::DEFAULT_MAX_CHUNK_LENGTH;

    private RiffManifestStoreExtractor $riff;

    public function __construct(
        public int $maxChunkLength = self::DEFAULT_MAX_CHUNK_LENGTH,
        MemoryBudget $budget = new MemoryBudget,
    ) {
        $this->riff = new RiffManifestStoreExtractor('WEBP', 'WebP', $maxChunkLength, $budget);
    }

    /**
     * @param  resource  $stream  a readable, seekable stream positioned at 0
     * @return ManifestStoreBytes|null null when the WebP has no C2PA chunk (AC2)
     *
     * @throws ContainerException on every malformed case
     */
    public function extract($stream): ?ManifestStoreBytes
    {
        return $this->riff->extract($stream);
    }
}
