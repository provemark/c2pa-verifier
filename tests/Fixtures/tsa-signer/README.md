# A time-stamping certificate as signer (step 159, SPEC-031 AC9)

`bin/make-tsa-signer-variants.php` makes a throw-away P-256 root and two
leaves under it, and re-signs `../fixture-signed.png`'s manifest with each
(a new protected header and signature, the pad resized so that the store
keeps its length). The keys are deleted when the script ends.

| file | the leaf's EKU |
|---|---|
| `tsa-only.png` | Time Stamping only, critical, as a TSA certificate carries it |
| `email.png` | E-mail Protection only: the guard |

Three settings files name `throw-away-root.pem`: in the legacy
`trust.trust_anchors` (`legacy`), as a `"manifest"` entry of `trust.anchors`
(`manifest-entry`), and as a `"tsa"` entry (`tsa-entry`). All three carry
`../trust/store.cfg` as `trust_config`.

| file | settings | c2patool 0.27.22 | c2patool 0.28.0 | this verifier |
|---|---|---|---|---|
| `tsa-only.png` | `legacy` | `Trusted` | `Trusted` | `Valid` (`untrusted`) |
| `tsa-only.png` | `manifest-entry` | `Valid` | `Trusted` | `Trusted` |
| `tsa-only.png` | `tsa-entry` | `Valid` | `Trusted` | `Valid` (`untrusted`) |
| `email.png` | `legacy` | `Trusted` | `Trusted` | `Trusted` |
| `email.png` | `manifest-entry` | `Valid` | `Trusted` | `Trusted` |
| `email.png` | `tsa-entry` | `Valid` | `Trusted` | `Valid` (`untrusted`) |

0.27.22 does not read `trust.anchors` (step 107). The answers are in
`../c2patool/tsa-signer/`.
