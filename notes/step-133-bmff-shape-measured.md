# Step 133 — issue #4, the BMFF hash's shape rules, measured

*2026-09-24. A measurement, no spec. Issue #4 names three rules on
`c2pa.hash.bmff.*`: `PRED-BMFF-001` (exclusions present and non-empty),
`PRED-BMFF-002` (subset ranges ordered and not overlapping) and
`PRED-BMFF-004` (an informational code for additional exclusions).*

## How the variants were made

There was no route yet to edit and re-sign an MP4. Here is the one this
step used, in the scratchpad:
1. The `c2pa.hash.bmff.v3` assertion of `fixture-signed.mp4` is decoded,
   edited and re-encoded.
2. The claim's hashed URI for it is recomputed, and the claim is re-signed
   under a throwaway root, with keys that are shredded.
3. The new COSE_Sign1 is padded, so that it takes up exactly what the
   assertion gained or lost. The store keeps its length, the `uuid` box
   keeps its size, and no top-level box moves. Nothing the BMFF hash
   covers changes, except what the edit is about.

**The control, the unchanged assertion re-signed, is `Trusted` in both
`c2patool` versions and here.** So the route itself changes nothing.

The fixture's exclusions are `/uuid` (with its data match), `/ftyp`,
`/mfra`, `/free` and `/skip`. The subset variants put a `subset` on the
8-byte `/free` box at offset 14555.

## Measured

| variant | 0.27.22 | 0.28.0 | here |
|---|---|---|---|
| unchanged, re-signed | `Trusted` | `Trusted`, + informational `additionalExclusionsPresent` | `Trusted` |
| `exclusions: []` | `Invalid`, `bmffHash.malformed` | the same | `Invalid`, `bmffHash.mismatch` |
| no `exclusions` key | no report (*"missing field `exclusions`"*) | the same | `Invalid`, `bmffHash.mismatch` |
| `subset` unsorted `[{4,4},{0,4}]` | `Invalid`, `malformed` | the same | **`Trusted`** |
| `subset` overlapping `[{0,6},{4,4}]` | `Invalid`, `malformed` | the same | **`Trusted`** |
| `subset` sorted, covering the box `[{0,4},{4,4}]` | `Invalid`, `mismatch` | the same | **`Trusted`** |
| `subset` `[{0,8}]` | `Invalid`, `mismatch` | the same | **`Trusted`** |
| `subset` `[{0,0}]` (to the end) | `Invalid`, `mismatch` | the same | **`Trusted`** |
| `subset` `[{0,4}]` (partial) | `Invalid`, `mismatch` | the same | `Invalid`, `mismatch` |
| `subset` `[{8,0}]` (the body only) | `Invalid`, `mismatch` | the same | `Invalid`, `mismatch` |

Every fault is on the `c2pa.hash.bmff.v3` assertion's url.

**The informational code**, over the 24 BMFF files of the corpus with no
settings:
- 0.28.0 reports `assertion.bmffHash.additionalExclusionsPresent` on 12:
  every signed one, the fragmented init segment included;
- 0.27.22 reports it on none;
- this verifier reports it on none.

The reason is that `c2pa-rs`'s writer itself excludes `/free` and
`/skip`, and 0.28.0 counts everything beyond `/uuid` (with the C2PA data
match), `/ftyp` and `/mfra` as additional. It is informational only; no
verdict depends on it.

(One unrelated difference showed up on the way: `c2pa-rs/video1.mp4`
without settings is `Valid` in `c2patool` and `Invalid` here. Its
ingredient's certificate has expired, and with no TSA configured this
verifier judges it at the current time. That is ADR-0004 decision 3,
already named. With the DigiCert settings both say `Valid`.)

## What it means

- **Five variants are `Invalid` in both oracles and `Trusted` here.** Two
  are issue #4's second rule: unsorted or overlapping subsets. The other
  three are **a finding the issue did not name**: a `subset` that covers
  the whole box.
  - C2PA 2.4 hashes `offset || data` for every root box *"not excluded in
    its entirety"*. `c2pa-rs` treats a box with a `subset` as not
    excluded in its entirety, even when the subsets cover every byte, so
    the box's 8-byte offset still goes into the hash.
  - This verifier drops that offset when nothing of the box remains. The
    signed digest then matches here and not there.
  - No asset byte can be changed this way. The subsets sit inside the
    assertion the signature covers, so only the signer can write them. It
    is a verdict that differs from `c2patool`'s on a file that `c2patool`
    rejects.
- **Rule 1** (`exclusions` empty or absent) changes the reason, not the
  verdict: `malformed` there, `mismatch` here. For an absent key,
  `c2patool` gives no report at all, and `Invalid` here is the
  fail-closed equivalent.
- **Rule 3** is 0.28.0's alone, and it would appear on nearly every
  real BMFF file.

## Size, reasoned

One spec on `BmffHashCheck`, with fixtures from this step's route (a new
`bin/make-*` script):
1. an empty `exclusions` list is `assertion.bmffHash.malformed`; an
   absent one too, since there is no report to copy;
2. unsorted or overlapping `subset` ranges are `malformed`;
3. a box with a `subset` keeps its offset in the hash, as `c2pa-rs` reads
   *"excluded in its entirety"*. That closes the three `Trusted` variants;
4. `assertion.bmffHash.additionalExclusionsPresent`, informational, as
   0.28.0 emits it. One new code, and the SPEC-013 drift alarms need to
   know that 0.27.22 does not emit it.

Point 3 changes how the hash is computed. No corpus file carries a
`subset` on a v3 assertion, but `video1.mp4` (v2) carries one, and it
must keep its verdict.
