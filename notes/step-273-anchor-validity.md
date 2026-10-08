# Step 273 — An anchor outside its validity vouches for no one (SPEC-014 amendment 5)

*2026-10-08. Reported privately from a security review of a downstream
module. A wrong `Trusted`, present in every release up to 0.5.0.*

## The flaw

When `ChainCheck` reaches an anchor that issued the current certificate,
it calls `issuerFault()` without a time. The anchor's `notBefore` and
`notAfter` are therefore never read. An x5chain intermediate is judged at
`$at ?? time()`; the anchor is not judged at all.

The report said an anchor carried in x5chain is judged. Measured, it is
not: the walk meets the anchor at the intermediate's issuer, and returns
before it reaches the copy in x5chain.

## The measurement

A throwaway PKI (P-256, keys overwritten and deleted), six probes, each
`fixture-unsigned.png` and `.jpg` signed by `c2patool` 0.27.22 without a
timestamp, every anchor as the legacy `trust.trust_anchors` string with
`store.cfg`. PNG and JPEG gave the same answer in every row.

| probe | anchor | 0.27.22 | 0.28.1 | this verifier |
|---|---|---|---|---|
| leaf ← intermediate ← root valid now | that root | `Trusted` | `Trusted` | `Trusted` |
| leaf ← intermediate ← root valid only in 2020 | that root | `Trusted` | `Valid` | `Trusted` |
| leaf directly under the 2020 root | that root | `Trusted` | `Valid` | `Trusted` |
| as the second row, the root also in x5chain | that root | `Trusted` | `Valid` | `Trusted` |
| leaf ← intermediate ← root valid from 2030 | that root | `Trusted` | `Valid` | `Trusted` |
| leaf ← intermediate valid only in 2020 | that intermediate | `Trusted` | `Valid` | `Trusted` |
| the same | the root above it | `Trusted` | `Valid` | `Valid` |

Every `Valid` carries `signingCredential.untrusted`.

Read, not measured: `c2pa-rs` 0.91.1 has two trust backends. The OpenSSL
one (`crypto/cose/certificate_trust/openssl.rs`) lets OpenSSL check every
certificate on the path it builds, the anchor included, at the trusted
timestamp's time, else now. The Rust one (`rust_native.rs`) checks the
x5chain certificates only. The measurement matches the first.

## 273 — the amendment, the fixtures and the tests seen red

Amendment 5 was proposed and confirmed by Maurice van Loon the same day.
An anchor that issues a certificate in the walk must be valid at the time
the leaf is judged, the same time as an intermediate. New criterion AC12.

`bin/make-anchor-variants.php <c2patool-0.28.1> <c2patool-0.27.22>` builds
the probes as JPEG fixtures under `tests/Fixtures/trust/anchor/` and
records both versions' answers under `tests/Fixtures/c2patool/anchor/`.
The answers equal the measurement above. The root "not yet valid" is
valid from 2090 in the fixtures, not 2030, so the test does not turn in
2030. No private key is in the repository.

`tests/Unit/Trust/AnchorValidityTest.php`: **6 failed, 2 passed.** The
control and the expired intermediate under a valid root are green, as
today. Every other probe is `Trusted` where `untrusted` is expected, for
example *"signing certificate trusted: the chain reaches the trust anchor
SPEC-014 Anchor Probe Root Expired at depth 2"*. In the time test the
half judged at 2020-06-01 is already green; the half judged at now is red.
A first version of that test judged the chain with its intermediate at
2020-06-01, when the intermediate was not yet valid. It was red for the
wrong reason, and now uses the leaf alone.

## Also found: a writers file turned `Invalid` overnight

`composer check` also fails in two tests that this step did not touch,
on a clean `HEAD` too. Adobe's signing certificate in
`writers/adobe-20260425-lightroom-classic-church.jpg` expired on
2026-10-07 at 16:39 UTC. Without settings this verifier does not trust
the timestamp's TSA (ADR-0004), judges the signer at now, and says
`signingCredential.expired`, `Invalid`. Both `c2patool` versions say
`Valid`. It is the case `SPEC013_WRITERS_TSA_NOT_CONFIGURED` names for
Amazon's file. Left for its own step.

## Disclosure

The fix stays local until it is built and the release is decided, as in
step 148.
