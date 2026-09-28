# CBOR fixtures for SPEC-006

## Recorded values (`*.json`)

One file per `cbor` content box of the four stores — the three M1 fixtures
and `public-testfiles/adobe-20220124-C.jpg` — named `<store>--<box label>.json`.
Each holds the box's data offset in the store, its length and SHA-256, and
its decoded `value` in a JSON form that keeps what JSON alone cannot:

- byte strings as `{"$bytes": "<hex>"}` (never confused with text);
- maps as `{"$map": [[key, value], …]}` — int keys stay ints, string keys
  strings, order is kept;
- tags as `{"$tag": n, "$value": …}`;
- ints, text, `true`/`false`/`null` as themselves.

Recorded once on 2026-09-21 (step 12) from `spomky-labs/cbor-php` 3.4.2
running in a scratch directory, with the boxes located by the SPEC-005
parser; committed as data so that no test depends on that library. They
are the oracle for SPEC-006 AC2: the decoder's output, rendered the same
way, must equal these files.

## Claim-level variants (`*.cbor`, `*.png`)

Built by `bin/make-cbor-vectors.php` from the PNG fixture's store: a few
bytes spliced into one CBOR box, every enclosing LBox adjusted. The
`.cbor` is the changed box's data; the `.png` is the fixture with that
store re-embedded (CRC recomputed), which is what c2patool was given.
Measured with c2patool 0.27.22 on 2026-09-21 (`notes/step-12-cbor-vectors.md`).

| file | what is wrong | cbor-php 3.4.2 | c2patool 0.27.22 on the `.png` | SPEC-006 |
|---|---|---|---|---|
| `claim-indefinite-array` | `created_assertions` as an indefinite-length array (`9f … ff`) | decodes | parses it; `Invalid` only for `claimSignature.mismatch` (the bytes changed) — **indefinite lengths are not refused** | AC6 error (stricter; RFC 8949 §4.2.1, which C2PA requires of claims) |
| `claim-float` | `claim_generator_info.version` as the float 0.0 (`fa 00000000`) | decodes | `Error: claim could not be converted from CBOR` — a type error on `version`, not a float check | AC7 error |
| `claim-duplicate-key` | the key `alg` renamed to a second `dc:title` | refuses: "defined more than once" | `Error: unknown algorithm` — `alg` is missing; the duplicate itself is not noticed | AC13 error (stricter; RFC 8949 §5.6) |
| `hashdata-nonshortest-int` | in `c2pa.hash.data`, `start` 33 as `19 0021` instead of `18 21` | decodes | parses it; `Invalid` only for `assertion.hashedURI.mismatch` — **deterministic encoding is not enforced on input** | decodes (SPEC-006 does not enforce it either) |

SHA-256 of the `.cbor` files (as printed by the script):

```
3440e15417a080472861e480ec16075ce459d78e00ff827b94cb8f44f9167f08  claim-indefinite-array.cbor
d99e8029f329fc57b34b3340b8e90fd60c8c0468ed6cc49e30e6af2912802028  claim-float.cbor
8b6f5c57526d0d72128dafdd162c7c6118dc9c337910ed9412b7fb517bdfc193  claim-duplicate-key.cbor
c2eef09f5162c55abc4c9198f0ae636aa58fef6bc8ba0b1cb45e2cef37345325  hashdata-nonshortest-int.cbor
```

## A real writer's claim with trailing bytes (step 173)

`claim-trailing-bytes-c2pa-rs-0.91.0.png` was not built here. It is what
c2pa-rs 0.91.0 wrote, through `@contentauth/c2pa-node` 0.9.8, when signing
the unsigned PNG fixture with the c2pa-rs `sample/` ES256 test
certificate on 2026-09-28, sync path, no timestamp. The manifest put
`specVersion: "2.4.0"` in `claim_generator_info`, as C2PA 2.4 places it.
The claim's CBOR map then holds one entry more than its header declares
(upstream c2pa-rs #2731, open on that day), so a decoder that honours the
header stops 18 bytes before the end of the box: exactly one more text key
`specVersion` (12 bytes) and its value `2.4.0` (6 bytes).

| reader | verdict |
|---|---|
| c2patool 0.27.22 (c2pa-rs 0.90.22) | `Error: claim could not be converted from CBOR` |
| c2pa-rs 0.91.0 itself (`Reader.fromAsset` in c2pa-node 0.9.8) | `claim_cbor: Syntax error: unexpected trailing data: 18 bytes remaining` |
| this verifier | `Invalid`, `claim.cbor.invalid`, "18 byte(s) remain after the value, which ended at offset 584" |

The signature and every hash in it are correct, because the writer signed
the bytes it wrote. With the trailing-bytes check disabled, this verifier
reports `claimSignature.validated`, `assertion.dataHash.match` and only
`signingCredential.untrusted`, which means `Valid`. That check is the only
thing between this file and a wrong `Valid`.

```
61b832efafea428600887aa784358c4981ab55a712a834ee49f5e637d224b5ee  claim-trailing-bytes-c2pa-rs-0.91.0.png
```

The RFC 8949 Appendix A and F vectors of AC4–AC11 are short and live in
the test file as hex.
