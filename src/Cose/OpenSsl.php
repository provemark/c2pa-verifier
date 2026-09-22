<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cose;

/**
 * OpenSSL reports failures twice: a PHP warning and an entry in its own
 * error queue. Here a failure is an answer, not noise: the warning is
 * swallowed for the duration of one call and the queue drained afterwards
 * so that no stale entry surfaces on a later, unrelated call.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class OpenSsl
{
    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    public static function quiet(callable $call): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $call();
        } finally {
            restore_error_handler();
            self::drain();
        }
    }

    /** The queued OpenSSL errors, oldest first, and the queue emptied. */
    public static function drain(): string
    {
        $messages = [];
        while (($message = openssl_error_string()) !== false) {
            $messages[] = $message;
        }

        return implode('; ', $messages);
    }
}
