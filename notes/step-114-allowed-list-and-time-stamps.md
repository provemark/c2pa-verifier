# Step 114 — the allowed list still reaches a time-stamp, through PHP

*2026-09-24. Measurement only: no specification, test or code changed.
This is SPEC-031 open question 6.*

## The rule

C2PA 2.4 §14.4.3 says the private credential store (the allowed list)
*"shall only apply to validating signed C2PA manifests, and shall not apply
to validating time-stamps"*. §14.5.1.2 repeats it: *"The private credential
store is not consulted when validating time-stamps."*

## The code

`TimestampCheck::tsaSettings()` builds the settings for the TSA's chain
from `ChainCheck::tsaAnchorsOf($operator)` and **`$operator->allowedList`**.
Since SPEC-031, `fromJson()` never fills that property: a loose
`trust.allowed_list` is refused, and an entry's allowed list goes to
`$anchorSets`, which `tsaSettings()` does not read. The property is still
public on a contract class, though. A caller who builds `TrustSettings` in
PHP reaches it.

## Measured

A throwaway script used the public API only: `new TrustSettings(...)`,
`Verifier::verify()`. The TSA leaf was taken from each file's own token.

| file | settings | state | TSA | signer |
|---|---|---|---|---|
| `c2pa-rs/C.jpg` | `full`'s anchors | `Trusted` | `timeStamp.untrusted` | trusted |
| | + DigiCert Timestamp 2023 on the allowed list | `Trusted` | **`timeStamp.trusted`** (*"on the allowed list"*) | trusted |
| `adobe-20220124-C.jpg` | `full`'s anchors | `Trusted` | `timeStamp.untrusted` | trusted |
| | + DigiCert Timestamp 2022 - 2 on the allowed list | `Trusted` | **`timeStamp.trusted`** | trusted |
| `truepic-20230212-camera.jpg` | its root as a `"manifest"` entry only | `Invalid` | `timeStamp.untrusted` (and the SPEC-031 note that the chain would reach `trust.anchors[0]`) | trusted, **`signingCredential.expired`** (judged at now) |
| | + the Truepic TSA leaf on the allowed list | `Invalid` | **`timeStamp.trusted`** | trusted, **no longer expired** (judged at the token's time) |

The last pair is the one that matters. A TSA trusted **only** because its
certificate is on the allowed list moves the moment at which the signer is
judged. An expired signer stops being expired. The Truepic file stays
`Invalid` only because of its data-hash exclusion (step 108). On a file
without that fault, the same settings would turn `Invalid` into
`Trusted`, **against a *shall not* of §14.4.3**.

## The oracle

It cannot be measured: `c2patool` reports `timeStamp.trusted` for the
DigiCert and Truepic TSAs with no anchor configured at all
(`docs/comparison.md`), so its answer says nothing about the allowed list.

Read in `c2pa` 0.91.0 `crypto/time_stamp/verify.rs`: after the timeStamping
profile, the TSA chain goes to `ctp.check_certificate_trust()`. That is
the same trust policy the signer uses, and it looks at the end-entity
(allowed) set *first*. So `c2pa-rs` applies the allowed list to
time-stamps too, and every anchor of every kind. This is reasoned from
the code, not measured.

## How far it reaches

- **Not through a settings file.** `fromJson()` refuses a loose
  `allowed_list` (SPEC-031 AC4), and an entry's list never reaches the TSA
  (SPEC-031 AC6).
- **Through PHP, yes**, with the constructor's second argument. The README
  documents `fromJson()` as the way in, but the constructor is part of the
  contract.
- **The corpus**: no test builds such settings, so no verdict in the suite
  changes whichever way this goes.

## The fix it points to

`tsaSettings()` passes `[]` where it passes `$operator->allowedList`
today. The allowed list then counts for signers only, as §14.4.3 says.
That is one argument. The rule it changes was written in SPEC-014
amendment 2 and SPEC-017 (the TSA check reuses `ChainCheck`), so the
change is an amendment to SPEC-017, with a test that is red first. It
makes this verifier stricter than `c2pa-rs` on a *shall not*, in the
fail-closed direction. Not done: it waits for the maintainer.

## Step 115 — built, the same day

The maintainer agreed to the fix as proposed.

- **Red first.** `SPEC-017 AC13` failed on its first assertion,
  `tsaSettings()->allowedList` = one certificate instead of `[]`. The
  consequence it goes on to assert (the Truepic signer stays expired) is
  what the table above measured.
- **The fix.** `TimestampCheck::tsaSettings()` passes `[]` for the allowed
  list. The docblock cites §14.4.3 and §14.5.1.2.
- **Green.** `composer check`: 431 passed; PHPStan, Deptrac, Pint and the
  API check clean. No other test moved. That fits step 114's reading that
  no settings file and no corpus run reaches the property.
- **Recorded while numbering.** The next free criterion should have been
  AC12, but a test called *"SPEC-017 AC12"* (step 46, Pixel 10) already
  exists with no AC12 in the spec and no traceability row, and
  `bin/spec-check.php` passed it. The new criterion is AC13. The orphan is
  named in SPEC-017 amendment 5 and waits for the maintainer.
