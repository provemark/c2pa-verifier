<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Hash;

use RuntimeException;

/**
 * A hash assertion this verifier cannot read (SPEC-027).
 *
 * Every other layer has an exception of its own — Asn1, Cbor, Container, Cose,
 * Jumbf, Manifest, Timestamp and Trust — and SPEC-013 turns each into a status
 * before the public boundary. Hash had none, because its checks answer with
 * statuses rather than throwing: a hash that does not match is a verdict, not a
 * fault. Reading an assertion whose filters this verifier does not implement is
 * the other thing, and it must never be silently skipped: ignoring a filter
 * would compute a digest over the wrong bytes and call the result a match.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class HashException extends RuntimeException {}
