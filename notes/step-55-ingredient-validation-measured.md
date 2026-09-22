# Step 55 — What validating the ingredient manifests would say (before SPEC-021)

*2026-09-22.* SPEC-020 walks the graph; this note measures what happens
when the manifests it finds are actually checked, so that SPEC-021's
acceptance criteria are written against numbers rather than hopes. Two
scratch scripts, this verifier's own checks, c2patool 0.27.22 as the
oracle (`full.settings.json`).

## 1. The reference hash: six box, eleven legacy

For each referenced manifest, the referring hashed URI's hash was
compared with (a) the hash of the manifest superbox's payload (C2PA 2.4
§8.4.2.3) and (b) the hash of the claim's CBOR bytes — the pre-1.3 form
c2pa-rs still accepts.

| form | files |
|---|---|
| box payload | `c2pa-rs/CACA`, `CACAE-uri-CA`, `CIE-sig-CA`, `ocsp`, `ocsp_with_assertion`, `writers/cawg_ica` |
| claim CBOR (legacy) | the ten Adobe 2022 files, `c2pa-rs/legacy_ingredient_hash`, `exp-test1` |

c2patool issues `ingredient.manifest.validated` **only** for the box
form; on the legacy form it says nothing at all — neither success nor
failure. Its url is the reference's url as the writer wrote it
(`self#jumbf=/c2pa/<label>` everywhere except `update_manifest`, which
writes `…/c2pa.claim`).

## 2. The checks on an ingredient, and the dropping rule

Running the existing checks (timestamp, signature, certificate profile,
chain, hashed URIs, actions — not the data hash) on every referenced
manifest gives, *before* any dropping, four files with failures c2patool
does not report:

| file | what we find | c2patool |
|---|---|---|
| `public-testfiles/adobe-20220124-CIE-sig-CA` | `claimSignature.mismatch` | nothing — `Trusted` |
| `c2pa-rs/CIE-sig-CA` | `claimSignature.mismatch` | nothing — `Trusted` |
| `public-testfiles/adobe-20220124-E-uri-CIE-sig-CA` | `claimSignature.mismatch` + `assertion.hashedURI.mismatch` | only `assertion.hashedURI.mismatch` |
| `c2pa-rs/CACAE-uri-CA` | `assertion.hashedURI.mismatch` | nothing — `Trusted` |

In every case the ingredient assertion **recorded** the fault:
`validationStatus` holds it with the same code and the same absolute url.
That is the specification's "an actor has acknowledged validation errors
… and has chosen to proceed" (§18.16.12.4), and c2pa-rs drops such a
status from the report. Applying the same rule — drop on (code, url),
recorded relative urls made absolute, never drop a status whose url names
the active manifest — makes our delta failures equal c2patool's on
**fourteen of seventeen** files.

The three that remain: `ocsp`, `ocsp_with_assertion`, `exp-test1`, each by
`signingCredential.expired` on an ingredient signer. Their TSAs are not
in the settings, so the signer is judged at *now* — the leniency ADR-0004
decision 3 named, and those three files already carry it for their active
manifest (`SPEC013_RS_TSA_NOT_CONFIGURED`).

## 3. The verdicts

Simulating the whole report — active manifest as today, plus the graph,
plus the ingredient checks with the dropping rule — gives:

| | files |
|---|---|
| state equal to c2patool | 16 of 18 (`Trusted` ×12, `Invalid` ×4) |
| different | `ocsp`, `ocsp_with_assertion`: `Invalid` here, `Valid` there — `signingCredential.expired` as above |

`adobe-20220124-E-uri-CIE-sig-CA` — the file that made SPEC-013
amendment 5 — is `Invalid` for exactly c2patool's reason
(`assertion.hashedURI.mismatch` on the ingredient's actions, a fault
nobody recorded), and `adobe-20220124-CIE-sig-CA` is `Trusted` because
its broken signature *was* recorded. The pair is the whole rule in two
files.

## 4. A false alarm, chased down

The first comparison also showed `writers/c2pa-rs-cawg_ica` as different:
c2patool reported `signingCredential.untrusted` where we found the
ingredient trusted. Chased with `openssl verify`: the chain (C2PA Signer
→ Intermediate CA → Root CA) does reach a configured anchor, so `Trusted`
is right — and c2patool's JSON for the *writers* corpus was recorded
**without settings** (`tests/Fixtures/c2patool/writers/README.md`), where
everything is untrusted. An artefact of my comparison, not a leniency.
Worth writing down: the four corpora are not recorded under the same
settings, and a cross-corpus comparison must say which.

## Measured / reasoned

- Measured: the two scratch scripts above over the seventeen readable
  multi-manifest files; the delta contents of four oracles read code by
  code; `openssl verify -CAfile` on the cawg chain.
- Reasoned: that the dropping rule belongs in SPEC-021 rather than
  SPEC-020 (it needs the statuses the validation produces); that the
  guard this verifier can apply is the url test (it has no second signal
  like c2pa-rs's "logged outside an ingredient scope").
