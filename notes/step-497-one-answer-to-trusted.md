# Step 497 — One answer to "is the signer trusted?"

*2026-10-28.* Finding 9 of the review of 0.9's code (`v0.8.3..HEAD`);
Maurice: "akkoord, begin met 497 en 498". Only SPEC-031's Traceability changes; no report
changes.

## Why

Whether a revocation source may be asked about a signer, and whether its
stored responses are read against the issuer the walk verified (SPEC-086,
SPEC-030 amendment 5), turned on `IngredientManifestCheck::trusted()`,
which searched the trust statuses for `signingCredential.trusted`. The
`Verifier` borrowed that static helper from another check, and
`ChainCheck::timeStampingSigner()` searched the same statuses twice with
its own code. The walk already answers the question: it hands out an
anchor (`AnchorEvidence`) exactly when it returns
`signingCredential.trusted` — the three trusted returns of
`ChainCheck::walk()` each carry one, and every other return, and the
Time-Stamping refusal, carries none. Two answers to one question can
drift apart.

## What changed

- The `Verifier`, `IngredientManifestCheck` and
  `ChainCheck::timeStampingSigner()` ask `$anchor !== null`.
- `IngredientManifestCheck::trusted()` is gone (internal; the recorded
  contract is unchanged), and with it the `$trust = []` it needed.

- A test under SPEC-031 AC9: a Time-Stamping-only leaf that no anchor
  reaches (`tsa-signer/tsa-only.png` under `trust/full.settings.json`)
  keeps the walk's own reason. Nothing pinned that explanation before;
  see the mutations below.

## Measured

- SPEC-082 AC7 already checks over five fixture sets that a manifest's
  evidence has a signer anchor exactly when it has
  `signingCredential.trusted`.
- Hand mutations, six (each `$anchor !== null` replaced by `true` or the
  source passed unconditionally, in the `Verifier` and in
  `IngredientManifestCheck`, and the two tests in `timeStampingSigner()`
  short-circuited). The first run was void: `pest --parallel` with several
  `--group` options runs the first group only, and with a comma list none,
  so the six "killed" were a failing command. Run again without
  `--parallel`, then against the whole suite and the corpus:
  - killed: the stored responses of the active signer read as trusted
    (SPEC-030), the second walk's check (`if (true)`), and the same for an
    ingredient signer (by SPEC-085 AC8; it turns
    `ingredient-staple/child-staple-revoked.png` and two `revocation/`
    probes `Valid` without settings);
  - the first test of `timeStampingSigner()` short-circuited: it survived
    the whole suite, and over the corpus it changes only the explanation of
    four files, where the Time Stamping refusal claimed the leaf "reaches
    only the legacy trust_anchors" though no anchor reached it. The new
    AC9 test fails against it and passes without it;
  - equivalent: the source passed for an untrusted signer, in the
    `Verifier` and in `IngredientManifestCheck`. `OcspCheck` asks a source
    only with the issuer the walk verified, and the walk gives one only
    when it trusts; the whole suite and the corpus (6,264 runs) are
    unchanged with either mutation.
- The corpus against `7a2098e`: 6,264 runs (1,044 files × none, full and
  lists, each without and with a source that records its calls and
  answers null), the hash of `toJson()`, `toCrJson()` and
  `evidenceToJson()` plus the source's calls: identical. 189 runs asked
  the source.
- `composer check` exit 0 and `vendor/bin/pest --parallel` exit 0.
