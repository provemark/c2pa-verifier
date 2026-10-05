<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

use Provemark\C2paVerifier\Support\MemoryBudget;

/**
 * AVI RIFF `C2PA` → manifest store bytes (SPEC-058; C2PA 2.4 §A.3.7).
 *
 * SPEC-003's walk with the form type `AVI `: the same chunk, the same checks,
 * the same bounds. The store is looked for in the first RIFF chunk only; an
 * OpenDML file's further RIFF chunks (`AVIX`) follow it and are left to the
 * data hash, which covers them (SPEC-003 amendments 3 and 4). The AVI
 * structure itself is not read.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class AviManifestStoreExtractor
{
    public const DEFAULT_MAX_CHUNK_LENGTH = RiffManifestStoreExtractor::DEFAULT_MAX_CHUNK_LENGTH;

    private RiffManifestStoreExtractor $riff;

    public function __construct(
        public int $maxChunkLength = self::DEFAULT_MAX_CHUNK_LENGTH,
        MemoryBudget $budget = new MemoryBudget,
    ) {
        $this->riff = new RiffManifestStoreExtractor('AVI ', 'AVI', $maxChunkLength, $budget);
    }

    /**
     * @param  resource  $stream  a readable, seekable stream positioned at 0
     * @return ManifestStoreBytes|null null when the first RIFF chunk has no C2PA chunk (AC2)
     *
     * @throws ContainerException on every malformed case
     */
    public function extract($stream): ?ManifestStoreBytes
    {
        return $this->riff->extract($stream);
    }
}
