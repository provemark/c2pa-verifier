<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cbor;

/**
 * A CBOR value that crJSON Table 1 cannot express (SPEC-074 AC6). The
 * message names the reason and the path to the value. There is never a
 * partial value.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class CrJsonValueException extends \RuntimeException {}
