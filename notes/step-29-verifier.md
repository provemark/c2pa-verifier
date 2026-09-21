# Step 29 — `Verifier::verify()`: one call from file to verdict, and the drift alarm over 22 files

*2026-09-21.* SPEC-013 implemented. For the first time a single call —
`(new Verifier)->verify($stream)` — takes a JPEG, PNG or WebP and answers
with c2patool's vocabulary: `validation_state`, `validation_status`,
`validation_results`, plus what c2patool does not say and this verifier
must: `checks_performed`. And for the first time the oracle is compared
per *file*: every c2patool JSON recorded since step 14 goes through the
front door in one test.

## What was built

- `src/Container/FormatDetector.php` — the format from the first twelve
  bytes, the stream rewound afterwards; `head()` gives those bytes to
  the message when nothing matched.
- `src/Verifier/Verifier.php` — the seven steps of the spec. Format
  unknown → `general.error` with the bytes in hex, nothing else read.
  No store → `hasManifest` false, no statuses, `Invalid`. A
  `ContainerException`, `JumbfException`, `CborException` →
  `general.error` on `self#jumbf=/c2pa`; a `ManifestException` → its
  own code on its own url. Then signature, hashed URIs, and the data
  hash only when the hashed URI for `c2pa.hash.data` matched — the one
  place this verifier reports *less* than c2patool, by decision.
- `src/Verifier/VerificationReport.php` — c2patool's five keys plus
  `format`, `has_manifest`, `checks_performed`; `toJson()`.
- SPEC-007 amendment 3: `ManifestException::$url` and `at()`;
  `Manifest::fromBox()` wraps the claim section, the signature box and
  each assertion's data in `at($url, …)`, so that a fault found while
  reading a box leaves with that box's absolute URI — and SPEC-010 AC7's
  deferred item closes: `assertion.json.invalid` on `json-broken` now
  carries `self#jumbf=/c2pa/contentauth:urn:uuid:…/c2pa.assertions/stds.schema-org.CreativeWork`
  where c2patool prints the bare label.
- Deptrac: `Verifier` → `Support` (amendment 2 of SPEC-013; the ruleset
  predated the `Support` layer).

## Measured

- Before: `10 failed (0 assertions)` — step 28.
- After: `composer check` → Pint passed, PHPStan `[OK] No errors`,
  Deptrac `Violations 0` (one on the first run: the missing `Support`
  arrow), Pest **`183 passed (1642 assertions)`** — the ten of SPEC-013
  green on the first run of the implementation, 300 assertions among
  them; AC9's 48 MiB file through the front door under 4 MiB, 0.13 s.
- **AC10, the drift alarm**: 22 files (4 fixtures, 18 variants).
  `validation_state` equal on all 22. Failure sets, normalised by the
  divergences SPEC-011/012 recorded: equal on 16, a strict subset on
  exactly the 6 the spec names — five where c2patool's data hash failed
  on an assertion the claim did not vouch for (`exclusions-overlap`,
  `hashed-uri-truncated`, `hash-as-text`, `exclusions-too-many`,
  `claim-alg-sha1`) and `json-broken`, where a parse fault stops this
  verifier and c2patool goes on. Two files the spec had listed as
  subset-only turned out equal (`hashed-uri-changed`,
  `hashed-uris-two-changed`: c2patool's data hash *matched* there, and a
  match is not a failure) — amendment 1, made in step 28 when the test
  said so.
- The sister library's `ManifestStoreParser::fromJson()` reads every
  report: `validationState()`, `validationStatusCodes()`,
  `isSignatureValid()`, `hasManifest()` false on the unsigned files, and
  on the PNG the same `softwareAgents()`, `digitalSourceTypes()`,
  `isAiGenerated()`, `activeManifestLabel()` as on c2patool's own JSON —
  `isTrusted()` false on both, as it must be before M5.

## Where the two divergences live in c2pa-rs (measured 2026-09-21, `main` at `58eac79`)

The question "is it c2patool or c2pa-rs?" was answered from the source,
sparse-cloned and read, not reasoned:

1. **The data hash after a hashed-URI mismatch is by design.**
   `sdk/src/store.rs:2117–2139`: `verify_store` runs
   `Claim::verify_claim(…)?`, then the ingredient checks, then "verify
   the asset hash binding once for the whole store" —
   `Claim::verify_hash_binding(…)`. The mismatch in the assertion loop
   (`sdk/src/claim.rs:3684–3696`) is logged with `.failure(log, err)?`,
   and `sdk/src/status_tracker/mod.rs:95–96` decides what the `?`
   does: `StopOnFirstError => Err`, `ContinueWhenPossible => Ok`.
   c2patool runs in *ContinueWhenPossible*, so the loop goes on and the
   hard binding is evaluated regardless. Spec-conformant (§15.2.1,
   collect everything); the `dataHash.match` it then logs as a success
   on an assertion whose hash the claim disputes is what SPEC-011
   decision 1 declines to report.
2. **The undeclared assertion is a hard error by a bare `return Err`.**
   `sdk/src/claim.rs:3725–3745`: after the loop, every box the claim
   did not name is logged `ASSERTION_UNDECLARED` ("assertion is not
   referenced by the claim") with `failure_no_throw`, and then the
   function does `return Err(Error::AssertionMissing { url })` —
   unconditionally, outside the tracker's mode. The right code is in
   the log; the error climbs to the top; c2patool prints only the error,
   with the opposite word, and no report. That is the one of the two
   that looks like a defect rather than a choice: it is inconsistent
   with the function's own ContinueWhenPossible handling three screens
   up. Whether to raise it upstream is Maurice's call; nothing was
   opened.

c2patool 0.27.22 behaved exactly as these `main` lines on every variant
measured, so the reading applies to the version this project pins.

## What `Valid` means now, and what it does not

After this step `Valid` says: the file's format was recognised, its
store read, its manifest parsed, the claim signed by the leaf
certificate, every assertion the one the signer saw, and the file the
one the signer hashed. It does not say the certificate is trusted (M5)
or the signature made in the certificate's lifetime (M6); the report's
`checks_performed` shows `['signature', 'hashedUris', 'dataHash']` and
nothing more, and the sister parser's `isTrusted()` is false on it.

## Next

M5: trust. The chain from `x5chain` to an anchor in `trust_anchors`, the
EKU rule from `trust_config`, `allowed_list`, and the codes
`signingCredential.trusted` / `.untrusted` with the state `Trusted`. It
starts with an ADR: ASN.1 and X.509 through phpseclib, or written here
— the same question ADR-0001 answered for CBOR and COSE, now for
certificates.
