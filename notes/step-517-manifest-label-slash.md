# Step 517 — A `/` in a manifest's label refuses the store

*2026-10-29.* Finding H4 of the security review before 0.10.0, a low
finding that the review did not reproduce. SPEC-005 amendment 3, approved
before the test was written. No code changes: the step pins a rule that
two other places rely on.

## The finding

A redaction URI names its manifest as `self#jumbf=/c2pa/<label>/…`. Two
places read that label, in two ways:

- `ManifestStore::scopedRedactions()` takes it up to the first `/`
  (SPEC-035 amendments 6 and 7);
- `Manifest::naming()` matches the whole label as a prefix.

The review's point was this. If a label could hold a `/`, a redaction
naming `/c2pa/A/b/…` would be judged as one meant for manifest `A`, but
applied to manifest `A/b`. That would get around the rule that the
active manifest is never redacted. The review saw no realistic path for
an attacker.

## What was measured

- `fixture-signed.png` with the first `:` of its active manifest's label
  (`urn:c2pa:488b…`) turned into `/`, the length unchanged. Once in the
  manifest's description box only, and once in the claim's signature URI
  as well (`self#jumbf=/c2pa/<label>/c2pa.signature`, the only other
  place the store holds the label), so that box and claim agree.
- This verifier, on `2434fb5`: both files `Invalid`, `general.error`
  ("description box at offset 46: label … contains a character that is
  not permitted"), no store. No manifest is read, and so no redaction.
- `c2patool` 0.28.3 and 0.28.1: neither file is read ("C2PA provenance
  not found in XMP"). The unchanged fixture is `Valid` in both.
- Why: SPEC-005 makes a `/` in any label an error (C2PA 2.4
  §11.1.4.1.1), and an error in a description box refuses the whole
  store. This has been so since step 5. The two readings therefore always
  agree. AC12 tested the rule with a `/` in the claim's label only, not
  in a manifest's.
- AC19 is green on `2434fb5`, as expected. With `/` removed from
  `JumbfParser::LABEL_FORBIDDEN` it fails (the exception is not thrown),
  and so does AC12. With the rule restored, both pass again.
- The existing variants of `bin/make-jumbf-variants.php` regenerate
  byte for byte. The corpus before (`2434fb5`) and after: 2,198 runs,
  none differs.
- `composer check` (1,707 passed, 1 skipped), `vendor/bin/pest
  --parallel` and `bin/package-check.php` pass.

## What was done

`bin/make-jumbf-variants.php` writes two more variants,
`tests/Fixtures/jumbf/manifest-label-slash` and
`manifest-label-slash-all` (`.bin` and `.png`). The README lists them
with `c2patool`'s answer and their SHA-256. AC19 checks that the parser
refuses both, naming offset 46 and the label in hex, and that `verify()`
reports `Invalid` with `general.error` and no store. If the label rules
are ever relaxed, this test fails, and its comment names the two places
that rely on the rule.
