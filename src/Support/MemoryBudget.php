<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Support;

/**
 * What this process may still hold (SPEC-024).
 *
 * A manifest store cannot stream: it is parsed, so it is held whole. The
 * containers declare its length in their own headers, before a byte of it is
 * read, which means a store too large for this host can be refused for the
 * price of reading a length field — and must be. Step 66 measured what happens
 * otherwise: a 63 MiB store on a 128 MB host ends the process with
 *
 *     PHP Fatal error: Allowed memory size of 134217728 bytes exhausted
 *
 * which cannot be caught, so the caller gets no report, no `validation_state`
 * and no status code. A verifier that fails closed owes better than a blank 500.
 *
 * Reading `memory_limit` makes behaviour depend on the host, which no other rule
 * in this verifier does: the same file can be refused on a small host and read on
 * a large one. That cost was weighed and accepted by the maintainer on
 * 2026-09-22, on the condition that a refusal never reads as a judgement about
 * the file — hence the wording the extractors use, which says the file was not
 * examined.
 */
final readonly class MemoryBudget
{
    /**
     * The share of what is still allocatable that a store may claim.
     *
     * Measured (step 67b, PNG, peak = memory_get_peak_usage(true)): a store of
     * 4 MiB peaks at 14 MB, 8 MiB at 22 MB, 16 MiB at 38 MB — about **twice the
     * store plus six megabytes**, because the store is held once as bytes and
     * again as the box tree that quotes it. A quarter therefore leaves roughly
     * half the limit unused at the worst permitted size: on a 64 MB host the
     * largest store allowed is 16 MiB, which peaks at 38 MB; on 32 MB it is
     * 8 MiB, peaking at 22 MB; on 16 MB it is 4 MiB, peaking at 14 MB. The
     * headroom is for everything that comes after the store — the claim, the
     * certificates, the timestamp — which the corpus puts at single megabytes.
     */
    public const DEFAULT_SHARE = 0.25;

    public function __construct(
        private float $share = self::DEFAULT_SHARE,
    ) {}

    /**
     * Bytes this process may still allocate, or null when PHP reports no limit
     * or one in a form this code does not understand.
     *
     * Null is never "nothing": SPEC-024 AC3 requires that an absent or
     * unreadable limit leave only the absolute bound in force. A configuration
     * we failed to parse may not become a reason to refuse a valid file.
     */
    public function remainingBytes(): ?int
    {
        $limit = self::parseLimit(ini_get('memory_limit'));
        if ($limit === null) {
            return null;
        }
        $used = memory_get_usage(true);

        return $limit > $used ? $limit - $used : 0;
    }

    /** Whether a buffer of this many bytes may be allocated without risking the limit. */
    public function allows(int $bytes): bool
    {
        $remaining = $this->remainingBytes();
        if ($remaining === null) {
            return true;
        }

        return (float) $bytes <= $remaining * $this->share;
    }

    /**
     * `memory_limit` as bytes: an integer with an optional K, M or G suffix, as
     * PHP's own shorthand notation defines it. -1 is no limit; anything else
     * unrecognised is null, which means the same here.
     */
    public static function parseLimit(string|false $value): ?int
    {
        if ($value === false) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return null;
        }
        if (preg_match('/^(\d+)([KMG])?$/i', $value, $matches) !== 1) {
            return null;
        }
        $number = (int) $matches[1];
        $factor = match (strtoupper($matches[2] ?? '')) {
            'K' => 1024,
            'M' => 1024 * 1024,
            'G' => 1024 * 1024 * 1024,
            default => 1,
        };

        return $number * $factor;
    }
}
