# Step 109 — the store's exclusion holds the store and nothing else

*2026-09-24. SPEC-012 amendment 7, which reverses amendment 5, and SPEC-017
amendment 4, which follows from it. Decided by the maintainer after step
108.*

## The rule

Every piece of the manifest store must still lie inside an exclusion; that
check is unchanged. What is new: **an exclusion that holds any part of the
store must hold nothing else.** Its length must equal the sum of the store
pieces inside it. If it does not, the result is `assertion.dataHash.mismatch`
with the number of bytes the exclusion takes in before the store, after it
and between its pieces, and the file is not hashed.

The rule applies C2PA 2.4 VAL-ASSE-0043/0044. In JPEG, PNG and WebP the
padding lives inside the store (JUMBF `free` boxes, `pad` fields), so "only
the store and padding" leaves nothing outside the pieces. It is written per
exclusion rather than as "equal to the store's span", so that a gap between
two APP11 pieces can never be excluded along with them. Exclusions that
hold no part of the store are honoured and reported as
`assertion.dataHash.additionalExclusionsPresent`, as before
(VAL-ASSE-0045).

## Red, then green

- `DataHashCheckTest` AC3 was rewritten first. Run with
  `vendor/bin/pest --filter="AC3: an exclusion must cover the store"`, it
  gave 1 failed: `assertion.dataHash.match` where `…mismatch` was expected
  on `truepic-20230212-camera.jpg`. The test is now named after the new
  rule.
- The tamper from step 108 is rebuilt inside the test in `php://memory`:
  the EXIF date `2023` → `2019` at offsets 202, 616 and 636, and nothing
  on disk. The first version forgot to rewind the stream, and the
  extractor threw a `ContainerException`. That was a mistake in the test,
  not a finding, and it is fixed.
- With the check in `DataHashCheck::check()`, one other test went red:
  SPEC-017 AC6, which compared the Truepic files under their root with
  `c2patool` 0.27.22's `Trusted`. Amendment 4 keeps everything that
  criterion is about (the timestamp validated and trusted, nothing
  expired) and expects the oracle's failures plus the one mismatch, state
  `Invalid`.
- `composer check`: 422 passed, PHPStan, Deptrac and Pint clean.

## Measured: what changed, and what did not

Every signed file under `tests/Fixtures/` (288), each without settings,
with `full-plus-digicert-g4.settings.json` and with
`truepic-root.settings.json`: 864 runs of `bin/c2pa-verify`, with the code
before and after the change, compared line by line.

- **9 lines differ, all of them a Truepic file.**
- Without settings, and with the DigiCert settings, the three were
  already `Invalid` (the signer expired, no anchor). They gain
  `assertion.dataHash.mismatch`.
- With the Truepic root, **`Trusted` → `Invalid`**, with
  `assertion.dataHash.mismatch` as the only failure.
- The 11 negative variants step 108 counted as "wider" did not change at
  all: they fail at an earlier gate.

The step-108 copy with the changed EXIF date, under the Truepic root:

```
Invalid  assertion.dataHash.mismatch  the exclusion [0, 206316] (start,
length) holds the manifest store and 13617 bytes that are not: 13617
before it, 0 after it, 0 between its pieces; …
```

## Texts brought up to date in the same commit

- `docs/conformance.md`: `PRED-IMG-004` back to **yes**, now with the date
  it became true; the counts are 54 and 17; section 0 records the gap as
  closed.
- `README.md` and `SECURITY.md`: 17 gaps. The third finding is marked
  present in `0.1.0` and fixed after it.
- `CHANGELOG.md`: an `Unreleased` security entry.
- `docs/comparison.md`: a by-design row. `c2patool` 0.27.22 accepts these
  files and 0.28.0 does not.

Not done here, because both are the maintainer's call: a `0.1.1` release,
and a security advisory for `0.1.0`.
