# Step 59 — The coverage matrix: what the fixtures actually proved, and what they did not

*2026-09-22.* Before deciding whether this verifier is ready to be seen
by anyone, Maurice asked the right question: does it work on everything
it claims to handle? The honest way to answer is not to add more files
that happen to be findable, but to **measure what the files we have
cover** and then fill the empty cells on purpose.

## 1. The measurement

Every fixture with a manifest, walked through the verifier (a scratch
script; `format`, claim version, `signature_info.alg`, the data hash's
algorithm, whether a timestamp validated, the number of manifests).

**Over the four corpora only** — 50 files:

| dimension | coverage |
|---|---|
| format | jpeg 45, **png 4, webp 1** |
| claim | v1 41, v2 9 |
| signature | **Es256 11, Es384 1, Ps256 38** — and nothing else |
| data hash | **sha256, all fifty** |
| manifests per store | 1×30, 2×15, 3×2, 4×1, 6×2 |

**Over every fixture directory**, the own variant families included —
165 files: the picture is better (sha384 appears once, in
`binding/alg-sha384.png`), but the holes are the same: **Es512, Ps384,
Ps512 and Ed25519 in no file at all**, sha512 in none, WebP in one.

Those four algorithms are implemented (SPEC-009) and tested — with
hand-made vectors. A vector proves the primitive; it does not prove that
a *file* written by a real tool, with that algorithm, comes out of this
verifier the way c2patool sees it.

## 2. The matrix

`bin/make-matrix-fixtures.php <scratch>`: the three unsigned fixtures
signed with every algorithm, using c2patool 0.27.22 and c2pa-rs's public
test certificates, pinned to the tag `c2pa-v0.90.22`. The private keys
are downloaded into the scratch directory and **deleted before the script
ends**; what stays is 21 signed files, the trust settings built from the
matching root bundle (certificates only), and c2patool's JSON for each,
recorded twice — with the roots and without settings.

Two decisions worth recording:

- **No generated thumbnail** (`builder.thumbnail.enabled = false` when
  signing). With it, each file was ~100 KB of JPEG preview; without,
  13–16 KB. The matrix is evidence about signatures and hashes, not
  previews. 376 KB in all.
- **The hash algorithms had to be made by hand.** c2patool writes
  `sha256` into the claim whatever the signature algorithm is (measured
  on all 21 files: es512 and ps512 also hash with sha256), so sha384 and
  sha512 data hashes cannot be produced with it. `es256-sha384.png` and
  `es256-sha512.png` are made by surgery on the es256 PNG — the
  assertion's `alg` and digest replaced, its exclusion re-lengthened, the
  digest recomputed over the file, the claim's hashed URI recomputed and
  the claim **re-signed with the same test key**. c2patool validates
  both, so the oracle still answers for them.

## 3. What it says

All 23 files: `Trusted` with the roots, `Valid` with
`signingCredential.untrusted` without settings — this verifier and
c2patool agreeing on state, failure codes, `signature_info.alg` and the
certificate serial number, file by file (`tests/Unit/Verifier/MatrixTest.php`,
SPEC-013 AC16–AC18, the fifth drift alarm; SPEC-013 amendment 12).

Coverage after the matrix, over all 167 fixtures with a manifest:

| dimension | coverage |
|---|---|
| format | jpeg 62, png 95, **webp 10** |
| signature | Ed25519 4, Es256 97, Es384 4, **Es512 3**, Ps256 43, **Ps384 3**, **Ps512 3** (and 10 files whose signature cannot be read — the COSE variant family, on purpose) |
| data hash | sha256 155, **sha384 2, sha512 1**, sha1 1 (a refusal variant), bmff 1 (refused) |

Every algorithm this verifier implements is now exercised by at least one
real file in at least one container, and every container by every
algorithm.

## 4. What this still does not prove

- **One oracle.** Agreeing with c2patool twice is not the same as
  agreeing with two implementations. The Go verifier
  (`richardwooding/c2pa`) remains the obvious second opinion.
- **Timestamps** in the matrix: none. Adding one needs a TSA over the
  network at signing time; the corpora already carry 40 timestamped
  files, so the gap is small.
- **Claim v1** in the matrix: none — c2patool 0.27 writes v2. The corpora
  carry 41 v1 files.
- Formats outside JPEG/PNG/WebP are refused by design (M8 and later).

## Measured / reasoned

- Measured: the two coverage passes (before and after), the 21 + 2 files
  and their c2patool JSON, the state/codes/signature_info comparison in
  the new alarm, `composer check` exit 0 with 348 tests.
- Reasoned: that a matrix generated from the oracle is worth more than
  more found files for *these* gaps, because the expected answer comes
  with the file; and that the remaining gaps above are worth naming
  rather than papering over.
