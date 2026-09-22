<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Timestamp;

/**
 * Thrown for a timestamp header or token this layer does not read
 * (SPEC-016 AC6–AC9): a header of the wrong shape, a response that was
 * not granted, a token that breaks RFC 3161 / RFC 5652's own rules, and
 * every DER fault underneath, wrapped with what was being read. SPEC-017
 * maps it to `timeStamp.malformed`.
 */
final class TimestampException extends \RuntimeException {}
