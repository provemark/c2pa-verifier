<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Report;

/**
 * c2patool's validation_state (SPEC-010). Trusted arrives with M5. Valid
 * means "no failure among the statuses" — and the statuses say which checks
 * produced them; a report is not a verdict until every check is in it.
 */
enum ValidationState: string
{
    case Valid = 'Valid';
    case Invalid = 'Invalid';
}
