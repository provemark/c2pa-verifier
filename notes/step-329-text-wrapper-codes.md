# Step 329 — Plain text names its two failures (SPEC-060 amendment 3)

*2026-10-09.*

C2PA 2.4 §15.12.1.3 and A.8.7.1 define two failure codes for text. A
wrapper found but corrupted, *"invalid version, algorithm, or manifest
length"*, is `manifest.text.corruptedWrapper`. More than one wrapper is
`manifest.text.multipleWrappers`. This verifier refused both shapes with
`general.error`. It read a wrapper with the magic but another version as
text, a choice that followed the oracle (SPEC-060 AC4, AC7). Maurice
chose to follow the specification.

## Measured before

The oracle, `c2patool` 0.28.1 built with `unstable_plain_text`, emits
neither code:

| fixture | oracle | here before | here after |
|---|---|---|---|
| `text/two-wrappers.txt` | error: *No claim found* | `Invalid`, `general.error` | `Invalid`, `manifest.text.multipleWrappers` |
| `text/cut-in-store.txt` | error: *No claim found* | `Invalid`, `general.error` | `Invalid`, `manifest.text.corruptedWrapper` |
| `text/letter-in-run.txt` | error: *No claim found* | `Invalid`, `general.error` | `Invalid`, `manifest.text.corruptedWrapper` |
| `text/length-too-long.txt` | `Valid` | `Invalid`, `general.error` | `Invalid`, `manifest.text.corruptedWrapper` |
| `text/version-2.txt` | error: *No claim found* | `Invalid`, no code (no wrapper) | `Invalid`, `manifest.text.corruptedWrapper` |
| `text/bad-then-good.txt` | `Invalid` (hash mismatch) | `Invalid` (hash mismatch) | `Invalid`, `manifest.text.corruptedWrapper` |

No verdict changes. `length-too-long` was already stricter than the oracle
(SPEC-060 AC6, named).

## What changed

- **`ContainerException::$statusCode`**: a C2PA status code as a string,
  for a fault the specification names. The Container layer depends on no
  other, so `Verifier` maps it to `StatusCode`, else `general.error`.
- **`PlainTextManifestStoreExtractor`**: a candidate with the magic and
  another version is a corrupted wrapper. So are a length shorter than a
  box header, a store cut short, and an LBox that differs. A second
  wrapper is `multipleWrappers`. A limit of this verifier's own (the store
  size, the memory budget, the padding) stays `general.error`, because it
  is no corruption.
- **`StatusCode`**: the two codes, verbatim. This is a contract addition:
  the recorded surface and the two counters grow by two.
- SPEC-060 amendment 3 (AC4 to AC7 changed). AC15's second stress case now
  uses candidates of another magic, so it still measures a long read. The
  CHANGELOG, and `docs/reading-c2pa-2.4.md` (four rows, P10-7 resolved; 22
  candidates).

## Measured after

- **Tests first.** `PlainTextTest`: 7 failed, then green. The stress case
  failed too, because the first corrupted candidate now stops the read;
  it was given another magic.
- **The corpus.** 990 runs moved, all six text fixtures above, each
  `Invalid` before and after with another code. No other file moved.
- **The fuzzer.** 0 faults in every run. Without settings it made 21
  fewer runs (15,015). Mutations of the store need a store, and
  `bad-then-good.txt` no longer yields one. With `--trust`, 534 and 1,765
  suspects, each judged by `c2patool` 0.28.1, none more lenient here.
- `composer check`: 972 passed.
