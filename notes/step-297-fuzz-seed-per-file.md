# Step 297 — One random stream per fuzzed file

*2026-10-09. Tooling, and a finding for the next step; no change to `src/`.*

`bin/fuzz.php` seeded PHP's generator once, and every file drew from the
same stream. Adding a file, or one file taking a different branch (a store
range found where none was before), shifted the mutations of every file
after it. Comparing two runs by name then said nothing. Adding the ISOBMFF
files (the next step) would have broken the comparison with the old
baseline, though nothing about the old files had changed.

Now each file is seeded by `crc32("<seed>:<path in the repository>")`. Its
mutations depend on the seed and its path only.

## Measured

- **Replay.** `php bin/fuzz.php 20261005 60 <out>` twice: the same suspects
  by name.
- **Independence.** The same seed over `c2pa-rs/` and `gif/` alone gives,
  for those files, the same 11 suspects by name as the full run.
- **The new baseline** (seed 20261005, 60 rounds): 12,903 runs over 249
  files, 0 faults, 126 suspects (122 under the old seeding, which is no
  longer comparable by name). All 126 are `Valid` in `c2patool` 0.28.1 and
  0.27.22 (the plain-text one in the text-enabled build).
- **With `--trust`:** seed 20261005 × 60 gives 6,030 runs, 0 faults,
  0 raised, 100 suspects; seed 20261009 × 200 gives 20,100 runs, 0 faults,
  0 raised, 345 suspects. Judged by `c2patool` 0.28.1 under the same
  settings on the state and the `timeStamp.*` codes: none more lenient
  here, except one case `c2patool` could not read at all.

## The finding: Requestable is not checked

That case is `fixture-signed.flac` with eight flips in the store. Seven
land in zero padding. One turns the manifest store's description-box
toggles from `03` to `02`, which clears *Requestable* and keeps *Label
Present*. This verifier says `Trusted`, and both `c2patool` versions refuse
the file ("unexpected end of file").

Probes on the signed JPEG, PNG and FLAC: toggles `02` on the description
box of the store, the manifest, the assertion store, the claim or the
signature gives `Trusted` here and an error in both `c2patool` versions.
On an assertion's box it gives `Invalid` here, because the assertion's
hash covers its description box. Toggles `01`, `07`, `0b` and `13` on the
store's box give `Invalid` here and an error there.

SPEC-005 already says: *"Within a C2PA manifest every description box
shall have Label Present and Requestable set (§11.1.4.1.2); a box that has
not is an error."* The parser checks Label Present only;
`DescriptionBox::requestable()` is never called. The content and the
signature are intact in every probe, so no signed byte goes unchecked. But
the verdict is `Trusted` where the approved spec says error and the oracle
refuses the file.

## Checked

`composer check`: 943 passed. PHPStan in Docker `php:8.3-cli`: no errors.
