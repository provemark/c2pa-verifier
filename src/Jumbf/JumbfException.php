<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Jumbf;

/**
 * Thrown for every malformed box, description box or tree (SPEC-005
 * AC8–AC16). There is never a partial tree: the parser returns the whole
 * store as boxes, or throws.
 */
final class JumbfException extends \RuntimeException {}
