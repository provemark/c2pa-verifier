# SPEC-051: The merkle map names one of the three hash algorithms

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-30                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A fragmented ISOBMFF file is bound by a Merkle tree (SPEC-028). Its
`c2pa.hash.bmff.v3` assertion carries a `merkle` map, and that map may
name its own `alg`. When it does not, the assertion's `alg` applies.

C2PA 2.4 §13.1 allows three hash algorithms: `sha256`, `sha384` and
`sha512`. `BmffHashCheck::assertionOf()` holds the assertion's own `alg`
to those three. The map's `alg` is not checked at all
(`BmffHashCheck::checkMerkle()`, read at `8a21e41`):

```php
$alg = is_string($map['alg'] ?? null) ? $map['alg'] : $assertion['alg'];
```

It goes straight to `hash_init()` for the init segment and to `hash()`
for the proof climb. Three consequences:

- **Measured** (2026-09-30, a script against `v0.2.7`): the six bytes
  `sha256` of the map's `alg` in
  `tests/Fixtures/bmff-fragmented/init.mp4` replaced by `fooooo`.
  `(new Verifier)->verify()` throws `ValueError: hash_init(): Argument #1
  ($algo) must be a valid hashing algorithm`, and `bin/c2pa-verify` stops
  with no report. The same file with `sha256` put back verifies without
  throwing.
- **Reasoned:** an algorithm PHP knows but C2PA does not (`md5`,
  `crc32b`) is computed. A tree built with it would match, which breaks
  the rule that an unknown algorithm is `Invalid`.
- **Reasoned:** an `alg` that is not text (a byte string, a number) falls
  back to the assertion's `alg` without saying so, which is an
  assumption, not a check.

No key is needed for the first. The hash binding runs even when the
assertion's bytes no longer match the claim's hashed URI, so anyone can
edit the map in an existing file and crash a site that verifies uploads.
The verdict is never a wrong `Valid`: the crash replaces the report.

What the oracle does (read in `contentauth/c2pa-rs` main,
`sdk/src/utils/hash_utils.rs`, 2026-09-30): only `sha256`, `sha384` and
`sha512` make a hasher; anything else is `Error::UnsupportedType`, so no
hash is computed and the binding fails. `sdk/src/assertions/bmff_hash.rs`
takes the map's `alg` first and the assertion's `alg` when the map has
none, as this verifier does.

## Scope

**In scope**

1. **The map's `alg` is checked where the map is read**
   (`BmffHashCheck::merkleMapOf()`, which `FragmentedVerifier` also
   calls). When present it must be text and one of `sha256`, `sha384`,
   `sha512`; otherwise the map is refused with `assertion.bmffHash.mismatch`
   and an explanation that names what it found. That is the code a bad
   top-level `alg` gives today, so there is no new vocabulary.
2. **A second guard where hashing happens.** `BmffHashCheck`'s range
   digest refuses any algorithm outside the three with a `HashException`,
   and every caller reports that as `assertion.bmffHash.mismatch`. No
   future path that forgets step 1 can crash on it or compute a weak hash.
3. An absent map `alg` still means the assertion's `alg`, unchanged.

**Out of scope** (each needs its own spec before it may be built)

- The hash algorithm of an ingredient reference
  (`IngredientManifestCheck`, which accepts any name in `hash_algos()`).
  Same idea, another place and another code (`algorithm.unsupported`):
  the next step.
- A limit on the number of BMFF exclusions (a separate DoS finding).
- The order of Merkle `location` values (`c2pa-rs` #2702).
- Catching `\Throwable` around the whole verifier. A crash should be
  fixed where it starts, not hidden.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and
will be covered by a Pest test tagged `->group('SPEC-051')`. Every
variant is built in memory from `tests/Fixtures/bmff-fragmented/init.mp4`
by replacing the map's `alg` in place, so no new fixture file is needed.
The file holds `sha256` three times (offsets 636, 682 and 1316 at
`8a21e41`); the map's is the first one after the text `merkle` (643), and
the test finds it that way rather than by a fixed offset. Each edit keeps
the length, so no box size changes.

- **AC1 — an unknown name is refused, not thrown** *(malformed input)*
  - Given `init.mp4` with the map's `alg` replaced by `fooooo`
  - When it is verified with `Verifier::verify()`, and with
    `FragmentedVerifier` and the five fragments `seg_1.m4s`…`seg_5.m4s`
  - Then no exception escapes, the state is `Invalid`, and the report
    holds `assertion.bmffHash.mismatch` with an explanation that names
    `fooooo`

- **AC2 — a name PHP knows but C2PA does not is refused** *(malformed input)*
  - Given `init.mp4` with the map's `alg` replaced by `crc32b` (six
    letters, like `sha256`, and in `hash_algos()`)
  - When it is verified as in AC1
  - Then the report holds `assertion.bmffHash.mismatch` with an
    explanation that names `crc32b`, and no status says the init hash or
    a fragment matched

- **AC3 — an `alg` that is not text is refused, not replaced** *(malformed input)*
  - Given `init.mp4` with the CBOR head of the map's `alg` changed from a
    text string (`0x66`) to a byte string of the same length (`0x46`)
  - When it is verified as in AC1
  - Then the report holds `assertion.bmffHash.mismatch` with an
    explanation that says the `alg` is not text

- **AC4 — the range digest refuses an algorithm outside the three**
  *(internal guard)*
  - Given the range digest called directly (bound to the class in the
    test) with `md5` and with `fooooo`
  - When it runs
  - Then it throws `HashException` both times, and with `sha256` it
    returns a 32-byte digest

- **AC5 — genuine files do not move**
  - Given every fixture under `tests/Fixtures/` that the corpus runs
    (`bin/fuzz.php` as the reference) and the fragmented set with all
    five fragments
  - When each is verified before and after the change
  - Then every report is identical

## References

- C2PA Technical Specification 2.4, §13.1 (the hash algorithms `sha256`,
  `sha384`, `sha512`) and the `c2pa.hash.bmff` assertion's `merkle` map
  as SPEC-028 reads it.
- Oracle, read not run: `contentauth/c2pa-rs` main, `hash_utils.rs`
  (`UnsupportedType` for any other name) and `bmff_hash.rs` (map `alg`
  first, assertion `alg` otherwise).
- Measured: the `fooooo` crash, 2026-09-30, on `v0.2.7`.
- Governing rules: SPEC-028 (the Merkle tree), SPEC-027 (the BMFF hash
  assertion's `alg`), fail closed.

## API sketch

Illustrative only. The public API does not change.

```php
// namespace Provemark\C2paVerifier\Hash;

final class BmffHashCheck
{
    /** One list, used by assertionOf(), merkleMapOf() and digest(). */
    private const ALGORITHMS = ['sha256', 'sha384', 'sha512'];

    /** @throws HashException also for a map alg that is not text or not one of the three */
    public static function merkleMapOf(mixed $merkle): array;

    /** @throws HashException for an algorithm outside the three */
    private function digest($stream, array $included, string $alg): string;
}
```

## Open questions

- None blocking. Whether `assertionOf()` and the map share one constant
  or one small function is an implementation choice.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | `tests/Unit/Hash/MerkleHashAlgorithmTest.php` :: "AC1: an unknown name in the merkle map is refused, not thrown" (whole, fragmented) / SPEC-051 | `Hash\BmffHashCheck::merkleMapOf()` (`ALGORITHMS`) |
| AC2                  | `tests/Unit/Hash/MerkleHashAlgorithmTest.php` :: "AC2: a name PHP knows but C2PA does not is refused" (whole, fragmented) / SPEC-051 | `Hash\BmffHashCheck::merkleMapOf()` |
| AC3                  | `tests/Unit/Hash/MerkleHashAlgorithmTest.php` :: "AC3: a merkle map alg that is not text is refused, not replaced" (whole, fragmented) / SPEC-051 | `Hash\BmffHashCheck::merkleMapOf()` |
| AC4                  | `tests/Unit/Hash/MerkleHashAlgorithmTest.php` :: "AC4: the range digest refuses an algorithm outside the three" / SPEC-051 | `Hash\BmffHashCheck::digest()`; its two callers in `check()` and `checkMerkle()` report the refusal (`checkFragment()` already caught it) |
| AC5                  | `tests/Unit/Hash/MerkleHashAlgorithmTest.php` :: "AC5: the genuine fragmented set still matches" / SPEC-051 (a guard, green before and after); corpus measured in `notes/step-185-spec051.md`: 836 runs over 418 fixtures plus the fragmented set, reports identical before and after | — |
