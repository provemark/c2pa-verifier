# Step 213 — A review before 0.3.0, and the small findings fixed

*2026-10-05. Nothing in `src/` changed.*

## The review

Maurice asked for a thorough review before the release. A code review
ran over `v0.2.9..HEAD` and reported ten findings. Each was checked before
anything was done with it. The large ones were measured again with both
`c2patool` versions.

| # | finding | checked | outcome |
|---|---|---|---|
| 1 | an unsigned WAV with a 128-byte ID3v1 `TAG` appended is `has_manifest: true`, `Invalid`; `c2patool` says *No claim found* | measured, both versions; an unsigned WebP with bytes appended does the same (since 0.1) | real: the next steps (SPEC-003 and SPEC-055 amendments) |
| 2 | an unsigned WAV whose last odd chunk has no pad byte: the same | measured, both versions | real: the same steps |
| 3 | every container fault sets `has_manifest: true`, even when no `C2PA` chunk was seen | read in `Verifier::verify()` | real: the same steps |
| 4 | remote-manifest detection searches only the first 8 MiB, so XMP after a WAV's audio is not reported | measured: a 1 kB WAV with `_PMX` after `data` reports the URL, a 9 MB one does not; `c2patool` reports no remote manifest for either | real, light: `c2patool` does less; written into `docs/comparison.md` |
| 5 | `bin/fuzz.php`'s default paths hold no WAV (and no MP4) | read | fixed: MP4, WAV, `wav/`, `wav-writers/` added; a two-round run over the defaults: 264 runs, 0 faults, its one `Valid` mutation `Valid` in both `c2patool` versions |
| 6 | `SECURITY.md`'s scope does not name WAV | read | fixed |
| 7 | the SPEC-055 row in `docs/milestones.md` still said "awaiting confirmation" and did not name AC18 | read: steps 209–210c did not update it, against the project's rule | fixed |
| 8 | the RIFF helpers in three variant builders | read | kept: tooling; one shared file is a refactor for later |
| 9 | `WavManifestStoreExtractor` and `WebpManifestStoreExtractor` are near-copies | read | kept: the WebP class kept its shape so SPEC-003's tests could prove step 206; AVI's spec can revisit it |
| 10 | SPEC-003's Traceability names `hex()`, `printable()`, `fileEnd()`, `skip()`, none in the RIFF extractor | read: `hex()` and `printable()` moved to `Support\Bytes` in SPEC-004 amendment 1, and the stream helpers to `StreamReader` | fixed: the rows name `Bytes::hex()`, `Bytes::printable()`, `StreamReader::end()`, `StreamReader::skip()` |

## Decided for findings 1–3

Maurice van Loon, 2026-10-05, on the advice given: stay strict about
everything to do with the `C2PA` chunk itself (two of them, LBox, length,
its own pad byte), be as lenient as `c2patool` about the rest (bytes after
the RIFF chunk, a missing pad byte after another chunk at the end of the
file), and set `has_manifest` only when a `C2PA` chunk was seen. This
cannot make a changed signed file `Valid`, because the data hash covers
every byte outside the store. To be done before 0.3.0, as amendments to
SPEC-003 and SPEC-055 with tests seen red first.

## Measured

`composer check`: exit 0. The fuzzer's default run as above.
