<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

/**
 * The manifest store exactly as it was embedded in the container, one JUMBF
 * box from LBox to its last data byte, reassembled but not interpreted.
 * What the bytes mean is M2's concern (SPEC-001, Scope).
 */
final readonly class ManifestStoreBytes
{
    public function __construct(public string $bytes) {}
}
