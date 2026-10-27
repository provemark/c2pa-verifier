<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

/**
 * The default: no revocation data from outside the file (SPEC-086). With it the
 * verifier asks nothing, and every report is what it was without a source.
 */
final readonly class NoRevocationSource implements RevocationSource
{
    public function ocspResponse(string $subject, string $issuer, array $urls): ?string
    {
        return null;
    }
}
