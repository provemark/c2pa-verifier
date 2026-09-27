# Step 159 — Under the legacy anchors a time-stamping certificate signs nothing

*2026-09-27. SPEC-031 amendment 3, criterion AC9. Finding 2 of step 157.*

## The problem

The legacy settings field `trust.trust_anchors` anchors two things at once:
manifest signers and time-stamping authorities (SPEC-031 AC6, as `c2pa-rs`
reads it). The certificate profile accepts a signer whose only EKU is Time
Stamping, because `c2pa-rs`'s built-in EKU list (`valid_eku_oids.cfg`) holds
it and C2PA 2.4 §14.5 lets Time Stamping stand alone (SPEC-015).

Put the two together, and any certificate a TSA under such a list holds can
sign a manifest. That manifest comes out `Trusted`. Step 157 measured this
on the Pixel 10 fixture's TSA leaf ("Google Pixel Time Stamping Authority")
under `google-pixel-intermediates.settings.json`.

## Measured before the change

`bin/make-tsa-signer-variants.php` makes a throw-away P-256 root and two
leaves under it. Each leaf re-signs the PNG fixture's manifest, the same way
`bin/make-profile-variants.php` does. Each file is judged under three
settings files that name the root.

| file | settings | c2patool 0.27.22 | c2patool 0.28.0 | this verifier before | after |
|---|---|---|---|---|---|
| `tsa-only` (EKU Time Stamping, critical) | legacy `trust_anchors` | `Trusted` | `Trusted` | **`Trusted`** | `Valid`, `untrusted` |
| `tsa-only` | `"manifest"` entry | `Valid` | `Trusted` | `Trusted` | `Trusted` |
| `tsa-only` | `"tsa"` entry | `Valid` | `Trusted` | `Valid`, `untrusted` | unchanged |
| `email` (EKU E-mail Protection) | legacy | `Trusted` | `Trusted` | `Trusted` | unchanged |
| `email` | `"manifest"` entry | `Valid` | `Trusted` | `Trusted` | unchanged |
| `email` | `"tsa"` entry | `Valid` | `Trusted` | `Valid`, `untrusted` | unchanged |

0.27.22 does not read `trust.anchors` (step 107). 0.28.0 lets every entry
anchor everything, which `docs/comparison.md` already records (SPEC-031
AC6).

## The decision

Maurice chose option A: refuse such a signer, and record the rule as a
deliberate difference from `c2pa-rs`. Option B was to document the legacy
field as unsafe for mixed lists and change nothing.

The difference is allowed by ADR-0005 because it prevents unchecked trust.
Nobody has confirmed that a CA's time-stamping key is also a signing key for
content.

## The change

`ChainCheck::check()` judges the manifest signer. It now adds one rule,
`timeStampingSigner()`. It applies when all three of these hold:

- the leaf's only EKU is Time Stamping;
- the legacy `trust_anchors` are set;
- the chain came out trusted.

The chain is then judged again without the legacy anchors, so only
`"manifest"` entries and the allowed lists count. If that walk does not
trust the leaf either, the result is `signingCredential.untrusted`, with an
explanation that names the EKU and the field.

The time-stamping side is not touched. `checkCertificates()` still judges a
TSA's own chain against the legacy anchors, and step 157's probe calls that
seam directly, so it still prints `trusted` for the TSA leaf. That is
correct: the leaf really is a trusted TSA. A manifest signed with its key
goes through `check()`, which the fixture shows.

The first build applied the rule to any leaf whose EKUs *include* Time
Stamping. SPEC-015's `eku-mixed` probe (Time Stamping with E-mail
Protection) then gained an `untrusted` beside its `invalid`, where both
`c2patool` versions say `trusted` (AC2 and AC10 of SPEC-015 failed). Such a
leaf is already a profile fault, so the rule now needs Time Stamping to be
the only EKU.

## Measured after

- `vendor/bin/pest --group=SPEC-031`: before the change 1 failed, 8 passed.
  The failure was AC9: `tsa-only` under `legacy` was `'Trusted'` where
  `'Valid'` was expected. After the change, 9 passed.
- `composer check`: exit 0, 532 passed.
- **All 398 media fixtures under no settings and under every readable
  settings file in `tests/Fixtures/`, 21,384 runs, before (step 158) and
  after.** Only `tsa-signer/tsa-only.png` under `tsa-signer/legacy` moved,
  from `Trusted` to `Valid`. The writer files in `tests/Fixtures/writers/`, the Pixel 10 among
  them, are unchanged. The 78 Commons files of step 141 live outside the
  repository and were not re-run. The runner is the scratch script of step 158.

**Weight A:** a file that was wrongly `Trusted` becomes `Valid`. Present
since trust anchors were first read (M5) to 0.2.3.

## Disclosure

The fix is local until the other findings of step 157 are fixed too.
They go out together as a security release.
