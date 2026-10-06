# Step 254 — The RIFF forms as one walk

*2026-10-06. The second clean-up step; no behaviour changes.*

**What.** WebP, WAV and AVI share one walk, `RiffManifestStoreExtractor`,
since steps 206 and 241. Each still had its own class of about 40 lines: a
copy of the limit constant, a constructor that built the walk with its form
type, and an `extract()` that passed the call on. Now
`RiffManifestStoreExtractor` is abstract, and the three are its subclasses:
each is only a constructor that names its form type and name (`WEBP`/WebP,
`WAVE`/WAV, `AVI `/AVI). The constant, `extract()` and `$maxChunkLength`
are inherited.

**Why this way.** Three ways were weighed with Maurice: leave it (no gain),
subclasses (this), or named constructors on one class
(`RiffManifestStoreExtractor::webp()`), which would have changed the types
of `Verifier`'s constructor — in the recorded API surface — and so been a
0.4.0. Subclasses keep the class names, the `Verifier` constructor and every
`instanceof`; a fourth RIFF form would be one short class.

## Measured

- A new test that the three are `RiffManifestStoreExtractor` with the
  default limit, and SPEC-004's source check now looking for
  `parent::__construct('WEBP'`, both red first, then green.
- PHPStan; `bin/api-check.php`: the recorded surface matches.
- `composer check`: exit 0, 804 tests.
- The corpus against step 253: 0 of 1,400 measurements moved.
- `bin/fuzz.php 20261005 60` over the release set: 16,041 runs, 0 faults,
  the same 118 files `Valid` as in step 253.
