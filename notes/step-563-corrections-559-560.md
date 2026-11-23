# Step 563 — Two corrections: what steps 559 and 560 claimed

*2026-11-23. Measurement and documentation only.* Steps 559 and 560 stated
more than had been measured. This step measures it and corrects the texts.
No code, test or verdict changes. Both decisions stand.

## Step 560: where the chain is

Step 560 used a byte scan and called its 37 files "real writers". This
recount reads the 932 media files with this verifier's own parser, and takes
the COSE headers of each file's *active* manifest:

| where the chain is | files |
|---|---|
| protected header | 666 |
| unprotected only, under `"x5chain"` | 37 |
| unprotected only, under label 33 | 2 (`x5chain-unprotected`, `x5chain-swapped`: this repository's probes) |
| no chain | 3 (this repository's probes) |
| signature box not readable | 2 (`c2pa-rs/prerelease.jpg` and the probe `cose/three-items.png`) |
| manifest store not accepted, so not looked at | 133 (none in the folders of third-party files) |
| no manifest | 89 |

Of the 666, three carry a second chain elsewhere
(`chain-constraints/x5chain-both`, `manifest-probes/x5chain-unprotected-too`,
and one with both labels in the protected header).

**The 37 are 30 third-party files and 7 of this repository's probes:**

- 24 from `public-testfiles` (20 Adobe, 3 Truepic, 1 Nikon), 5 from
  `c2pa-rs`, and 1 Bing image. They are test files in part, some of them
  damaged on purpose. All 30 are claim v1.
- 5 `box-hash/bing-*` files, edited from that Bing image here, and 2
  `chain-constraints` probes, which are claim v2.

The third-party folders (`public-testfiles`, `c2pa-rs`, `writers`,
`mp3-writers`, `wav-writers`) hold 74 media files:

| | files |
|---|---|
| no manifest | 22 |
| chain protected | 21 |
| chain unprotected only, under `"x5chain"` | 30 |
| signature box not readable (`prerelease.jpg`) | 1 |

None of the 51 readable ones has an unprotected chain under label 33. That
says nothing about files outside this corpus. Step 560's "reaches no file a
writer makes today" was too wide.

The total of 37 equals step 560's. The byte scan's list was not kept, so the
two were not compared file by file.

## Step 559: what OpenSSL refuses, and why

Step 559 gave `X509_STRICT` as the reason for all three leaves. The OpenSSL
answer had been recorded for two of them. Measured now with the chains taken
from the probes and the anchor of their settings, `openssl verify
-partial_chain`, OpenSSL 4.1.0 (command line):

| probe | without `-x509_strict` | with `-x509_strict` |
|---|---|---|
| `trust/chain-matrix/control` | OK | OK |
| `trust/chain-matrix/leaf-ku-cert-sign` | OK | refused (keyCertSign invalid for non-CA cert) |
| `trust/chain-matrix/int-no-ski` | OK | refused (Missing Authority Key Identifier) |
| `profile-ed25519/control` | OK | OK |
| `profile-ed25519/leaf-spki-params` | **refused** | refused (`X509_PUBKEY_get0`: decode error) |

So for `leaf-spki-params` the strict mode is not the reason. OpenSSL cannot
decode the key.

**Not measured:**

- `c2pa`'s native chain check (`rust_native.rs`) was read, never run. That it
  would refuse none of the three is reasoned.
- The OpenSSL version inside the `c2patool` 0.29.0 binary was not
  established; its strings did not show one.

The codes stay not followed. All three probes are `Invalid` on both sides,
and the profile reports each fault here.

## Changed

- `notes/step-559-openssl-only-untrusted.md`,
  `notes/step-560-label-33-swap-measured.md`: a correction at the top; the
  text below it is left as it was.
- `NOTES.md` rows 559 and 560; `docs/comparison.md` (the `x5chain` row and
  the paragraph on the three probes); SPEC-061 amendment 9;
  `tests/Fixtures/profile-ed25519/README.md`.
