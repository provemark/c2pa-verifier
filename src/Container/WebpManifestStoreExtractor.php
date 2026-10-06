<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

use Provemark\C2paVerifier\Support\MemoryBudget;

/**
 * WebP RIFF `C2PA` → manifest store bytes (SPEC-003; C2PA 2.4 §A.3.7).
 *
 * The walk is `RiffManifestStoreExtractor`'s, shared with the other RIFF
 * forms since step 206; this subclass gives it the form type `WEBP` and keeps
 * the name, constructor and limit SPEC-003 describes (step 254).
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class WebpManifestStoreExtractor extends RiffManifestStoreExtractor
{
    public function __construct(
        int $maxChunkLength = self::DEFAULT_MAX_CHUNK_LENGTH,
        MemoryBudget $budget = new MemoryBudget,
    ) {
        parent::__construct('WEBP', 'WebP', $maxChunkLength, $budget);
    }
}
