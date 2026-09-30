<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

/**
 * How every part of the verifier reads bytes from the caller's stream
 * (SPEC-050). PHP's fread() may return fewer bytes than asked on a stream
 * that is not a plain file, so a short read is not the end of the file:
 * this asks again until it has the bytes or the stream gives nothing more.
 * A stream that gives nothing while not at its end is treated as ended, so
 * the caller reports a truncated file instead of waiting forever.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class Read
{
    /**
     * Up to $length bytes: fewer only at the end of the stream, '' when
     * nothing is left or $length is below 1.
     *
     * @param  resource  $stream
     */
    public static function upTo(mixed $stream, int $length): string
    {
        $bytes = '';
        while (($wanted = $length - strlen($bytes)) > 0) {
            $chunk = fread($stream, $wanted);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }
}
