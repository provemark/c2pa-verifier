<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

use Provemark\C2paVerifier\Support\MemoryBudget;

/**
 * WAV RIFF `C2PA` → manifest store bytes (SPEC-055; C2PA 2.4 §A.3.7).
 *
 * SPEC-003's walk with the form type `WAVE`: the same chunk, the same
 * checks, the same bounds. `fmt ` and `data` are not required or read; a
 * `C2PA` nested in a `LIST` chunk is not looked for, as in c2patool.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class WavManifestStoreExtractor
{
    public const DEFAULT_MAX_CHUNK_LENGTH = RiffManifestStoreExtractor::DEFAULT_MAX_CHUNK_LENGTH;

    private RiffManifestStoreExtractor $riff;

    public function __construct(
        public int $maxChunkLength = self::DEFAULT_MAX_CHUNK_LENGTH,
        MemoryBudget $budget = new MemoryBudget,
    ) {
        $this->riff = new RiffManifestStoreExtractor('WAVE', 'WAV', $maxChunkLength, $budget);
    }

    /**
     * @param  resource  $stream  a readable, seekable stream positioned at 0
     * @return ManifestStoreBytes|null null when the WAV has no top-level C2PA chunk (AC2, AC15)
     *
     * @throws ContainerException on every malformed case
     */
    public function extract($stream): ?ManifestStoreBytes
    {
        return $this->riff->extract($stream);
    }
}
