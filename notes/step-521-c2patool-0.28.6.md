# Step 521 — The corpus against `c2patool` 0.28.6

*2026-11-10.* `c2patool` 0.28.5 (`c2pa` 0.91.11) and 0.28.6 (the same
engine, plus a fix to how resources are written to a folder) were released
on 2026-11-06. The last corpus run was against 0.28.3 (`c2pa` 0.91.6, step
419). The verdict here follows `c2patool`, so Maurice asked for a check of
whether any verdict moved ("ja, begin met de corpusmeting tegen 0.28.6"),
then for this record ("akkoord, documenteer stap 521").

## What changed in between (read from the release notes and the commits)

`c2pa` 0.91.7 to 0.91.11 are backports from the 0.92 line. The ones that
touch reading:

| change | upstream |
|---|---|
| an icon that is a resource reference, not a hashed URI, is `hashedURI.missing` (CAI-13410) | #3003 (#2919) |
| an OCSP `good` outside its window counts for nothing when no trusted time-stamp gives a time (CAI-13263) | #3003 (#2921) |
| time-stamp trust only from TSA anchors; manifest trust only from manifest anchors | #2993 (#2951), #3009 |
| `keyCertSign` refused on a certificate that is not a CA; a signer needs `digitalSignature` | #2973 (#2972) |
| a box-hash asset with data after its last box is refused, unless a `c2pa.after` box covers it | #2993 (#2949) |
| a redacted Claim-v1 ingredient needs a matching `claimSignature` | #3017 (#3006), #3022 |
| `mdat` boxes paired with their merkle maps in a fixed order, not a hash map's | #2958 (#2946) |
| no `claimSignature.validated` when the credential is refused; no `insideValidity` when it is not inside; `ocsp.skipped` for a staple that cannot be used | #2984, #2985, #2979 |

## What was run

- The macOS release zip of `contentauth/c2patool` v0.28.6; its SHA-256
  (`d627f748…71b5`) equals the digest GitHub publishes for the asset.
- 0.28.3 and 0.28.6 on every media file under `tests/Fixtures/` (897:
  png, jpg, mp4, mp3, webp, wav, gif, avi, m4s, flac, mov, heic, avif),
  each without settings and with `trust/full.settings.json`: 3,588 runs,
  eight at a time, one output file per run.
- Per run: the exit code, `validation_state`, and every status code under
  `validation_results` and `validation_status` with its url, sorted. The
  two versions compared file by file.
- Each file whose state moved, through `bin/c2pa-verify` with the same
  settings; `two-mdat.mp4` 20 more times under 0.28.6 with each of the
  two settings files.
- For the three groups of code changes: whether `bin/c2pa-verify` reports
  the code that moved, on every file it moved on (with settings).

## Measured

Without settings 90 files differ in some way, with settings 85. The state
moved on three files; the fourth row is the one time-stamp code that moved:

| file | 0.28.3 | 0.28.6 | here |
|---|---|---|---|
| `icons/agents-icon.png` | `Valid` | `Invalid`: `hashedURI.missing` on `c2pa.actions.v2`, *"icon must contain a hashed URI, found resource reference: icon.png"* | `Valid` (`Trusted` under `probe-root.settings.json`, where 0.28.6 says `Invalid`) |
| `profile/no-digital-signature.png` | `Valid` | `Invalid`: `signingCredential.invalid` | `Invalid`: `signingCredential.invalid` |
| `mdat-merkle/two-mdat.mp4`, with settings | `Invalid` | `Valid` | `Invalid` (SPEC-080 AC8) |
| `writers/trustnxt-20260113-icon-signed-timestamp.jpg` | `timeStamp.trusted` (state `Trusted`) | `timeStamp.untrusted` (state `Trusted`) | `timeStamp.untrusted` (state `Trusted`) |

`two-mdat.mp4` was 20 of 20 `Valid` under 0.28.6 with
`trust/full.settings.json`, and 20 of 20 `Trusted` with its own
`throw-away-root.settings.json`; under 0.28.3 it varied from run to run
(step 446).

The other moves are codes only, on files whose state stays:

| code in 0.28.6 | files | reported here on those files |
|---|---|---|
| `claimSignature.validated` gone | 55 | 52 |
| `claimSignature.insideValidity` gone | 12 | 12 |
| `signingCredential.ocsp.skipped` added | 27 | 15 |

Totals for 0.28.6 with settings: 74 `Trusted`, 297 `Valid`, 308 `Invalid`,
218 errors. Without settings: 368 `Valid`, 311 `Invalid`, 218 errors.

## What it means (reasoned)

- **`agents-icon.png` is a file this verifier now passes and `c2patool`
  refuses.** SPEC-034 amendment 2 (step 126a) leaves an icon map without
  a `url` unchecked, because `c2pa` 0.91.0 did. `c2pa` 0.91.11 reports
  it as `hashedURI.missing` in `claim_generator_info`, `softwareAgents`
  and `templates`. Nothing in the asset is unbound; the icon is: a reader
  that shows it shows something the signature does not cover. Under the
  project's fail-closed rule, this verifier should not be more lenient
  than its oracle here. That is the next step (SPEC-034 amendment 3).
- **Two moves bring `c2patool` to what this verifier already said.** A
  manifest signer without `digitalSignature` has been `Invalid` here
  since SPEC-015 amendment 10 (step 324), with the same exception for a
  TSA that `c2pa` #2972 now makes; the named difference of its AC4 is
  gone. The TrustNXT time-stamp is `timeStamp.untrusted` here because
  the TSA's certificate holds E-mail Protection, not time-stamping, as
  its EKU; which upstream change made 0.28.6 say the same was not traced.
- **`two-mdat.mp4` stays a difference, the safe way round.** The fixed
  pairing (#2946) makes `c2patool` answer the same every run, and the
  answer is `Trusted`. This verifier refuses two merkle maps by name
  (SPEC-080 AC8: a form it does not read). Stricter, not wrong; reading
  the form would need its own spec.
- **The code moves change no verdict.** They do make the code lists
  differ from 0.28.6 on some of those files (the table above). The drift alarms stay pinned to
  0.27.22, so none fails. Following them is a decision for its own round.
- **Not covered by the corpus.** No file has a redacted Claim-v1
  ingredient, an OCSP `good` outside its window without a time-stamp, or
  a box-hash asset; that they did not move shows nothing about them. The
  OCSP window is SPEC-030 AC12; whether it equals CAI-13263 was not
  traced.

## What changed

- `docs/comparison.md`: a paragraph for 0.28.6; the KeyUsage row (0.28.6
  agrees) and the resource-reference icon row (more lenient here since
  0.28.6) brought up to date.
- `tests/Fixtures/mdat-merkle/README.md`: 0.28.6's answer for `two-mdat`.
- No code, no tests; the pinned oracle of the drift alarms stays 0.27.22.
  No answer files were added under `tests/Fixtures/c2patool/`: the
  generators of `icons/` and `mdat-merkle/` rewrite those folders with
  their own two versions.

## Next

SPEC-034 amendment 3: an icon that is not a hashed URI is
`hashedURI.missing`, with red tests first. Later and separately: whether
to follow the three code moves, and moving the drift alarms to 0.28.6.
