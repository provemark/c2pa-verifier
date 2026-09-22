# The coverage matrix (step 59)

On 2026-09-22 the fifty corpus files with a manifest covered **jpeg 45,
png 4, webp 1**, signature algorithms **Es256 11, Es384 1, Ps256 38**,
and **sha256 as the data hash in all fifty**. Four of the seven signature
algorithms this verifier implements, and two of the three hash
algorithms, rested on hand-made vectors and on no file at all — and WebP,
a format the README claims, had exactly one file, our own.

`bin/make-matrix-fixtures.php <scratch>` fills that in: it signs the three
unsigned fixtures (`../fixture-unsigned.{jpg,png,webp}`) with **every**
algorithm, using c2patool 0.27.22 and c2pa-rs's public test certificates
(downloaded to the scratch directory, pinned to the tag `c2pa-v0.90.22`,
**deleted before the script ends** — no key ever enters this repository).
Thumbnail generation is switched off, so each file is 13–16 KB instead of
~100 KB; what the matrix is evidence for is the signature and the hash.

| file | what it adds |
|---|---|
| `es256.{jpg,png,webp}` … `ps512.{jpg,png,webp}`, `ed25519.{jpg,png,webp}` | 7 algorithms × 3 formats, each `Trusted` with the roots below and `Valid` (untrusted) without settings |
| `es256-sha384.png`, `es256-sha512.png` | the **data hash** under sha384 and sha512. c2patool writes sha256 into the claim whatever the signature algorithm is, so these two are made by surgery: the assertion's `alg` and digest replaced, its exclusion re-lengthened, the digest recomputed over the file, the claim's hashed URI recomputed and the claim re-signed with the same test key — c2patool validates them, so the oracle still answers |
| `test-roots.settings.json` | c2pa-rs's `test_cert_root_bundle.pem` (certificates only) and its `store.cfg` EKU list |

c2patool's JSON for every file, with the roots and without settings, is
in `../c2patool/matrix/`. The alarm that compares the two is
`tests/Unit/Verifier/MatrixTest.php` (SPEC-013 AC16–AC18).

Regenerating changes nothing about the certificates — they are c2pa-rs's,
pinned to a tag — so the files are stable apart from signature
randomness (ECDSA) and should be regenerated only when the tag moves.
