# Anchor probes for SPEC-014 amendment 5

Built by `bin/make-anchor-variants.php <c2patool-0.28.1> <c2patool-0.27.22>`
on 2026-10-08 (step 273). Each JPEG is `../../fixture-unsigned.jpg` signed
by c2patool 0.27.22 with a throwaway P-256 leaf, without a timestamp, so
every certificate is judged at the time of verification. The keys lived in
a temporary directory while the script ran and were overwritten and
deleted. No private key is here.

| file | chain (leaf first) | anchor (settings) | 0.27.22 | 0.28.1 | this verifier, before amendment 5 |
|---|---|---|---|---|---|
| `control.jpg` | leaf ← intermediate | a root valid now (`root-valid`) | `Trusted` | `Trusted` | `Trusted` |
| `expired-anchor.jpg` | leaf ← intermediate | a root valid only in 2020 (`root-expired`) | `Trusted` | `Valid` | `Trusted` |
| `expired-anchor-direct.jpg` | leaf | the 2020 root (`root-expired`) | `Trusted` | `Valid` | `Trusted` |
| `expired-anchor-in-x5chain.jpg` | leaf ← intermediate ← the 2020 root | the 2020 root (`root-expired`) | `Trusted` | `Valid` | `Trusted` |
| `future-anchor.jpg` | leaf ← intermediate | a root valid from 2090 (`root-future`) | `Trusted` | `Valid` | `Trusted` |
| `expired-int-as-anchor.jpg` | leaf ← an intermediate valid only in 2020 | that intermediate (`int-expired`) | `Trusted` | `Valid` | `Trusted` |
| `expired-int-as-anchor.jpg` | the same | the root above it (`root-valid`) | `Trusted` | `Valid` | `Valid` |

The settings hold the anchor as the legacy `trust.trust_anchors` string,
which both versions read and which anchors timestamp authorities too.
Every `Valid` above carries `signingCredential.untrusted`. The answers are
under `../../c2patool/anchor/<probe>--<settings>--<version>.json`.

The leaves and intermediates valid "now" were made valid for ten years
from 2026-10-08; `control.jpg` stops being `Trusted` in 2036.
