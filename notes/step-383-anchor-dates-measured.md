# Step 383 — Trust anchors with dates (issue #9): measured, before a spec

*2026-10-23.* `PRED-CRYP-006`, C2PA 2.4 §14.4.1 and §15.7: a trust
anchor configuration may carry a `notBefore` ("before which there is no
trust for that trust anchor configuration") and a `notAfter`; a
configuration "shall not be used for claim signatures whose timestamp (or
current time, if no timestamp is present)" falls outside them. This
verifier's settings have no such field (issue #9). Maurice asked to
measure this before the release. No code.

## Where the dates live

The programme publishes each list twice (`c2pa-org/conformance-public`
`da71a72`, `trust-list/`): as a PEM bundle, and as JSON in ETSI TS
119 602's "List of Trusted Entities" form (LoTE). The PEM bundle holds
certificates only. The JSON holds, per anchor, a `ServiceStatus` and a
`StatusStartingTime`. The programme's schema extension
(`schemas/trust-list/c2pa-trust-list-extensions.schema.json`) allows two
statuses: `…/trusted`, whose time is "the date a certificate was added to
the trust list", and `…/untrusted`, "the date a certificate was
untrusted", and notes that these "maybe different from the notBefore and
notAfter dates of the certificate itself". That is §14.4.1's anchor
configuration, and the PEM bundle drops it.

`c2pa-rs` 0.91.1 reads neither the JSON form nor any anchor dates.

## What the lists hold today

| | signer list | TSA list |
|---|---|---|
| anchors | 32 | 23 |
| `trusted`, list date equal to the certificate's own `notBefore` | 29 | 21 |
| `trusted`, list date later than the certificate's `notBefore` | 1 | 1 |
| status and date empty | 2 | 1 |
| `untrusted` | 0 | 0 |

- The one later date is the same CA in both lists: **Castlabs C2PA ECC
  P-384 Root CA**, a certificate valid from 2026-07-08, on the list from
  2026-08-10 (TSA list: 00:09:13 that day). A signature under it, judged
  at a time between those two dates, is `Trusted` here today; read by
  §14.4.1 it is not.
- The empty entries are three NETLOCK certificates (signer list: the
  root CA G2 and "Claim Signing G2 CA 1"; TSA list: "TSA G2 CA 1"). An
  empty status is outside the schema's two values. They are in the PEM
  bundles like every other anchor.
- No anchor has ever been untrusted, so no list carries a `notAfter` yet.
- Of this repository's fixtures, two reach an official anchor
  (`writers/google-20250919-pixel10-npld-picnic-table.jpg`, Google C2PA
  Root CA G3; `writers/openai-20260826-c2pa_2x.png`, SSL.com C2PA RSA
  Root CA 2025). Both anchors' list dates equal their certificates'
  `notBefore`, and both files were signed long after. No fixture moves.

## What follows

Today the dates change one window of one CA. The case they exist for is
the other one: a CA removed from the list, whose earlier signatures stay
good and whose later ones do not. The PEM bundle cannot say that, and
neither can the settings format shared with `c2patool`.

## Measured and reasoned

- Measured: both JSON lists and their schema extension at `da71a72`;
  every anchor's list date against its certificate (`openssl x509`); the
  fixtures under the lists (`bin/c2pa-verify --trust-list
  --tsa-trust-list`); `c2pa-rs` 0.91.1's source.
- Reasoned: that `trusted` and `untrusted` times are §14.4.1's
  `notBefore` and `notAfter`, from the extension's own description.
