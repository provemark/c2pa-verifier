<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Verifier;

use Provemark\C2paVerifier\Hash\BmffHashCheck;
use Provemark\C2paVerifier\Hash\HashException;
use Provemark\C2paVerifier\Trust\TrustSettings;

/**
 * A fragmented ISOBMFF stream — a DASH init segment and its fragments — as one
 * verdict (SPEC-028).
 *
 * The init segment carries the manifest. Its `c2pa.hash.bmff.v3` assertion has no
 * `hash` at all: a `merkle` map instead, holding the hash of the init segment and
 * the root of a tree whose leaves are the fragments. Each fragment carries a C2PA
 * box of its own with the leaf's position and the sibling hashes up to that root.
 *
 * Why this is a class of its own rather than a method on `Verifier` (decided by
 * the maintainer, 2026-09-22): almost every caller verifies one file, and it is
 * worth more that `verify()` does one thing with one signature than that they see
 * a method they will never call. What this does will grow — the `merkle` field is
 * already a list, for renditions — and it grows where nothing else depends on it.
 *
 * The fragments arrive **one open stream at a time**, because fifty fragments must
 * not mean fifty open handles. Each is read to its end before the next is asked
 * for, and nothing here closes a stream it did not open.
 */
final readonly class FragmentedVerifier
{
    /**
     * An init segment and its fragments, as one report.
     *
     * The report is the same `VerificationReport` a whole file yields, so anything
     * downstream of it is unchanged. What differs is inside the statuses: a stream
     * is many files, and a status that fails says which one.
     *
     * @param  resource  $init  the init segment, readable and seekable
     * @param  iterable<string, resource>  $fragments  a name and an open stream, one
     *                                                 at a time. The name is what a status says when that fragment is the
     *                                                 one that failed, so it should be something a caller recognises.
     */
    public function verify($init, iterable $fragments, ?TrustSettings $settings = null): VerificationReport
    {
        // Verifier already routes an ISOBMFF file to the BMFF hard binding; what it
        // does not know is that there are more files. Handing it a check that does
        // keeps the dispatch, the report and every other rule exactly as they are.
        //
        // The Verifier is built here rather than injected: it has to carry a check
        // that knows this call's fragments, so one handed in at construction time
        // could not be used. An earlier draft took one and quietly ignored it, which
        // PHPStan caught and which would have read as a seam that was not one.
        return (new Verifier(bmffHash: new BmffHashCheck(fragments: $fragments)))
            ->verify($init, $settings);
    }

    /**
     * Exactly one merkle map, or a refusal by name.
     *
     * Here as well as on `BmffHashCheck` because this is the class a caller holds,
     * and the rule — one rendition, because what selects among several is
     * unmeasured — is one they may want to ask about.
     *
     * @param  array<string, mixed>  $assertion  a decoded `c2pa.hash.bmff.v3`
     * @return array<string, mixed>
     *
     * @throws HashException when the assertion carries more than one
     */
    public static function merkleMapOf(array $assertion): array
    {
        return BmffHashCheck::merkleMapOf($assertion['merkle'] ?? null);
    }
}
