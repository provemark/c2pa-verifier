# The timestamp matrix (SPEC-062)

Built by `bin/make-tsa-matrix.php <scratch> <c2patool-0.28.1>
<c2patool-0.27.22> tsa-matrix <probe>…` (all 29 probes) on 2026-10-09
(step 294). One valid signer chain and one valid timestamp authority chain
(TSA leaf ← intermediate ← anchor, the leaf with the critical
`timeStamping` EKU), and probes in which one property of the timestamp
differs. Each PNG is `../../fixture-signed.png` with its version 2 claim
re-signed by a throw-away signer and an RFC 3161 token in `sigTst2` (or
`sigTst`, or both, where the probe says so), over the CounterSignature
structure of C2PA 2.4 §10.3.2.5. The tokens come from `openssl ts -reply`; the
EKU probes and `control-cms` were signed again by the probe's TSA
certificate with `openssl cms -sign -cades` (step 292). The keys lived in a
scratch directory while the script ran and were deleted. No private key is
here.

`<probe>.settings.json` holds the signer's root as a `"manifest"` entry and
the TSA's root as a `"tsa"` entry, except `tsa-not-configured` (the
signer's root only), `tsa-root-as-manifest` (both as `"manifest"`) and
`legacy-string-both` (both in the legacy `trust.trust_anchors` string).

The three `expired-signer-*` probes have a signer valid for 2.5 minutes,
stamped at once; the generator waited until it had expired before
`c2patool` answered, and it stays expired. Certificates without fixed dates
are valid for ten years from the build, so the control stops being
`Trusted` around 2036. A rerun of the generator makes new keys and tokens:
the PNGs change byte for byte, the answers should not.

The answers are under
`../../c2patool/tsa-matrix/<probe>--<version>[--no-settings].json`.
0.27.22 does not read `trust.anchors`, so it trusts no TSA here except in
`legacy-string-both`. 0.28.1 is the alarm.

OpenSSL's answer on each token (`openssl ts -verify` against the TSA's root,
at build time), as information:

| probe | `openssl ts -verify` |
|---|---|
| `no-timestamp`, `expired-signer-no-timestamp` | no token |
| `tsa-leaf-expired`, `tsa-leaf-not-yet-valid`, `tsa-int-expired`, `tsa-int-ca-false`, `tsa-root-expired`, `tsa-root-not-yet-valid` | refused: certificate verify error |
| `tsa-leaf-eku-not-critical`, `tsa-leaf-eku-plus-email`, `tsa-leaf-eku-email-only`, `tsa-leaf-no-eku`, `tsa-leaf-eku-any`, `tsa-leaf-eku-plus-ocsp` | refused: certificate verify error (unsuitable certificate purpose) |
| `header-both` | refused: message imprint mismatch. This is the generator's doing: it checks the `sigTst2` token against the digest of the `sigTst` token, the last one it made |
| every other probe | OK |

Added on 2026-10-09 (SPEC-062 amendment 1), with the same command and
these probe names: `two-tokens` and `expired-signer-two-tokens`. They hold
two tokens from the trusted TSA in `sigTst2`'s `tstTokens`, the second made
right after the first. `openssl ts -verify` accepts the first token of
each.
