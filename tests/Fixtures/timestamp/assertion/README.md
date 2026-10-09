# Time-stamp assertion probes (SPEC-064)

Built by `bin/make-timestamp-assertion-variants.php <scratch> <c2patool-0.28.1>
<c2patool-0.27.22>` on 2026-10-09 (step 332).

`parent.png` is `fixture-unsigned.png` signed by `c2patool` 0.28.1 with a
throw-away signer valid for two and a half minutes, without a timestamp in
its header. Each probe is `fixture-unsigned.png` signed by a long-lived
throw-away signer with `parent.png` as its parent, or for `update-*` an
update manifest added to `parent.png` itself (`c2patool --update`). It
carries a `c2pa.time-stamp` assertion whose placeholder was replaced by an
RFC 3161 token from a throw-away TSA (`openssl ts`) as a byte string; the
claim was then re-hashed and signed again. The probes were judged after
the parent's signer had expired. Keys lived in a scratch directory and
were deleted. `throw-away-roots.pem` holds the public signer and TSA
roots. Each probe's settings name the signer root as a `manifest` anchor
and the TSA root as a `tsa` anchor (`untrusted`: the signer root only).

| probe | token | 0.28.1 | 0.27.22 | here (step 332, before SPEC-064 is built) |
|---|---|---|---|---|
| `raw` | over the parent's COSE signature field, as `c2pa-rs` writes it | `Trusted` | `Invalid` (parent `expired`) | `Invalid` (parent `expired`) |
| `structure` | over the CounterSignature structure of a `sigTst2` header | `Trusted` | `Invalid` | `Invalid` |
| `other-label` | keyed by another label (control) | `Trusted` | `Invalid` | `Invalid` |
| `wrong-data` | over other bytes | `Trusted` | `Invalid` | `Invalid` |
| `untrusted` | `raw`, settings without the TSA | `Trusted` | `Invalid` | `Invalid` |
| `array` | the assertion a CBOR array | error: *timestamp assertion malformed* | error | `Invalid` (parent `expired`) |
| `two-assertions` | two time-stamp assertions | `Trusted` | `Invalid` | `Invalid` |
| `update-raw` | `raw` in an update manifest | `Trusted` | `Invalid` | `Invalid` |
| `update-other-label` | the control, in an update manifest | `Trusted` | `Invalid` | `Invalid` |
| `header-wins` | the parent is `tsa-matrix/expired-signer-trusted-tsa.png` (a trusted header token); a token for it taken now | `Trusted` | `Valid` | `Trusted` |

`c2patool` 0.28.1 does not judge the parent's signer again once the
ingredient recorded its validation at signing, so it is `Trusted` with or
without a usable token. It cannot show what a token changes; 0.27.22 shows
the expired parent everywhere. 0.27.22 does not read the `anchors` form of
the settings, hence its `signingCredential.untrusted` on the active
manifest.

The answers are under `../../c2patool/timestamp-assertion/<probe>--<version>.json`.
