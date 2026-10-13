<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Hash;

use Provemark\C2paVerifier\Container\ContainerException;

/**
 * Where a format allows a c2pa.hash.data exclusion that holds no part of the
 * manifest store (SPEC-069). C2PA 2.4 §15.12.1 does not say; an allow-list
 * of what a measured writer does, and nothing else, fails closed.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
interface ExclusionAllowance
{
    /** The format's name in an explanation: "JPEG", "PNG". */
    public function format(): string;

    /** The rule in one clause, for the explanation of a refusal. */
    public function rule(): string;

    /**
     * The asset mapped into regions, the allowed ones marked; empty when the
     * format allows nothing. Called only for a binding with such exclusions.
     *
     * @param  resource  $stream  the asset, readable and seekable; the position is not kept
     * @return list<ExclusionRegion>
     *
     * @throws ContainerException when the structure cannot be walked: then nothing is allowed
     */
    public function regions($stream): array;
}
