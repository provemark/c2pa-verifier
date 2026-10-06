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
final readonly class WavManifestStoreExtractor extends RiffManifestStoreExtractor
{
    public function __construct(
        int $maxChunkLength = self::DEFAULT_MAX_CHUNK_LENGTH,
        MemoryBudget $budget = new MemoryBudget,
    ) {
        parent::__construct('WAVE', 'WAV', $maxChunkLength, $budget);
    }
}
