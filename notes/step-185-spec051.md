# Step 185 — SPEC-051 built: the merkle map names one of the three hash algorithms

*2026-09-30. SPEC-051, drafted in step 184 and approved the same day,
built tests-first. PHP 8.5.8, OpenSSL 3.6.3.*

## Why

A fragmented ISOBMFF file is bound by a Merkle tree, and the tree's map in
the `c2pa.hash.bmff.v3` assertion may name its own hash algorithm. That
name went unchecked to `hash_init()`. A review measured that six edited
bytes in `tests/Fixtures/bmff-fragmented/init.mp4` (`sha256` → `fooooo`)
make `Verifier::verify()` throw a `ValueError`, so a site that verifies
uploads gets a crash instead of a report. No key is needed: the hash
binding runs even when the assertion no longer matches the claim.
C2PA 2.4 §13.1 allows `sha256`, `sha384` and `sha512`, and `c2pa-rs`
refuses every other name (read in `hash_utils.rs`).

## The tests, red first

`tests/Unit/Hash/MerkleHashAlgorithmTest.php` edits the map's `alg` in
memory, in place, so no box size changes: the first `sha256` after the
text `merkle`. Each of AC1–AC3 runs through `Verifier` (init segment
alone) and through `FragmentedVerifier` (with all five fragments).

Before the change, `vendor/bin/pest --group=SPEC-051`: **7 failed,
1 passed**.

| test | red because |
|---|---|
| AC1 `fooooo`, both routes | `ValueError` from `hash_init()` |
| AC2 `crc32b`, both routes | computed as a hash ("computed b0e448db…"); the explanation does not name the algorithm |
| AC3 byte-string `alg`, whole | fell back to `sha256`; failed only because no fragments were offered |
| AC3 byte-string `alg`, fragmented | **`assertion.bmffHash.match`**: the non-text `alg` was replaced silently and the tree matched |
| AC4 digest with `md5` | no `HashException` |
| AC5 genuine fragmented set | passed: a guard, green before and after |

The AC3 fragmented case is not a wrong `Valid`. The same edit breaks the
claim's hashed URI for the assertion, so the file is `Invalid` anyway. It
shows the silent assumption the spec named.

## What changed

All in `src/Hash/BmffHashCheck.php`:

- One constant, `ALGORITHMS`, for `assertionOf()`, `merkleMapOf()` and
  `digest()`.
- `merkleMapOf()` refuses a present map `alg` that is not text, or not one
  of the three, with a `HashException` naming what it found. An absent
  `alg` still means the assertion's. `FragmentedVerifier` reads the map
  through the same method.
- `digest()` refuses any other algorithm with a `HashException`. Its two
  callers outside a `try` (the whole-file hash in `check()` and the init
  hash in `checkMerkle()`) now report that as `assertion.bmffHash.mismatch`;
  `checkFragment()` already caught it.

After: `vendor/bin/pest --group=SPEC-051` 8 passed; `composer check`
exit 0, **591 passed**; `bin/api-check.php`: the recorded surface matches.

## The corpus, before and after

A scratch script verified every fixture under `tests/Fixtures/` with a
JPEG, PNG, WebP or ISOBMFF extension, with no settings and with
`trust/full.settings.json`, plus the fragmented set with five fragments:
836 runs over 418 files. It hashes each report's `toArray()`.

The first comparison showed 31 lines differing, all in files whose
certificate is judged against the current time ("checked at now"). Two
runs of the same code differed the same way, so the difference was the
clock, not the change. With today's timestamps replaced by a fixed word,
and the old code run through `git stash`, the two outputs are
**identical**. No verdict or explanation on a genuine file moved.

## Not in this step

- The hash algorithm of an ingredient reference (`IngredientManifestCheck`
  accepts any name in `hash_algos()`): the next step.
- A limit on the number of BMFF exclusions.
- The order of Merkle `location` values (`c2pa-rs` #2702).
