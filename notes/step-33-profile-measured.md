# Step 33 — The certificate profile measured: twelve re-signed variants through c2patool, and c2pa-rs's rules read to the end

*2026-09-21.* SPEC-015 will make `Trusted` mean what §14.5 means: the
leaf is a C2PA signing certificate. The test material had no certificate
that fails the profile in one known way, and the test hierarchy's keys
are, rightly, not here. So a throw-away hierarchy was made for one run,
the PNG's manifest re-signed with each leaf, and c2patool asked — with
Maurice's explicit permission (asked twice, at his request) that tooling
may sign with keys that exist only during the run. No verifier code, no
spec.

## Made: `bin/make-profile-variants.php`

A P-256 root, self-signed; per variant a leaf with one departure (the
table is in `tests/Fixtures/profile/README.md`); the manifest re-signed:
protected header `{1: alg, 33: [leaf, root]}`, the Sig_structure
borrowed from the verifier's own `CoseSign1::sigStructure()`, the
signature by the openssl CLI (ECDSA DER → R‖S; PSS with SHA-256/MGF1
salt 32 for the RSA variant), and the unprotected `pad` sized so that
the COSE bytes fill exactly the original 12,297 — the step-31 trick:
nothing but the signature box changes, the exclusion and every hashed
URI stay valid. Keys in a directory under the scratchpad, `0700`,
deleted by a shutdown function whatever happens (the first run died on
the expired variant's dates and the keys still went).

The verifier's own `SignatureVerifier` accepted the ten ES256/PS256
signatures it can accept and refused the two it must — `rsa-1024`
("RSA key of 1024 bits; §13.2.1 requires 2048 to 16384") and
`curve-secp256k1` ("EC key on secp256k1") — the key rules SPEC-009
already enforces at the signature, before any profile check.

## Measured: c2patool 0.27.22, the throw-away root as the only anchor

| variant | verdict | the code |
|---|---|---|
| `good` | `Trusted` | — the control: `claimSignature.validated` and `signingCredential.trusted`, so the re-signing pipeline is right end to end |
| `no-digital-signature` (KU nonRepudiation only) | **`Trusted`** | — see below |
| `expired` | `Invalid` | `signingCredential.expired` |
| `ca-as-leaf` | `Invalid` | `signingCredential.invalid` |
| `eku-outside-list` (codeSigning) | `Invalid` | `.invalid` |
| `eku-any` | `Invalid` | `.invalid` |
| `eku-mixed` (timeStamping + emailProtection) | `Invalid` | `.invalid` |
| `eku-c2pa` (1.3.6.1.4.1.62558.2.1) | `Trusted` | — on the built-in list |
| `no-eku` | `Invalid` | `.invalid` |
| `v1` — not v1 after all: OpenSSL 3 adds SKI/AKI, so v3 with no KU and no EKU | `Invalid` | `.invalid` (KU absent; EKU absent on an end-entity) |
| `rsa-1024` | `Invalid` | `.invalid` |
| `curve-secp256k1` | `Invalid` | `.invalid` |

Two things the JSON shows about the *report*: `signingCredential.trusted`
is logged as a success on every one of the twelve — the chain check and
the profile check are independent, and a failing profile does not
withdraw the trust line — and the state is then `Invalid` because
`.invalid` / `.expired` are failures; SPEC-014's three-state rule gives
the same answer.

## Read to the end: c2pa-rs `certificate_profile.rs` (`main`, `58eac79`)

Step 30 read the top of the function; the bottom holds three rules the
measurement made visible:

- **KU**: `key_usage_good` is set when the KeyUsage extension carries
  `digitalSignature` *or* `keyCertSign` *or* `nonRepudiation`; a
  non-CA that has both `digitalSignature` and `keyCertSign` is refused;
  no KeyUsage extension at all → not good → `.invalid`. So
  `nonRepudiation` alone passes — the `no-digital-signature` result.
- **AKI**: an AuthorityKeyIdentifier extension must be present on the
  leaf (`aki_good`); a CA must also carry a SubjectKeyIdentifier. OpenSSL
  3's `x509 -req -CA` adds both by default, which is why `good` has
  them; a leaf without AKI would be `.invalid`.
- **Unknown critical extensions** are refused (`handled_all_critical`);
  the known list is x509-parser's.
- EKU, as step 30 read it: `any` refused; one of the allowed set
  required (emailProtection, timeStamping, ocspSigning always; the
  built-in file and `trust_config` add more); the timeStamping/OCSP
  combinations refused; **no EKU is accepted only on a CA**, which a
  leaf cannot be — so `no-eku` is `.invalid`.

## One variant corrected by `openssl_x509_parse`

`v1` was meant to be an X.509 v1 certificate (no extensions). Parsing
every leaf afterwards showed `version` 2 (= v3) and SKI/AKI on it:
OpenSSL 3's `x509 -req -CA` adds those two whatever `-extfile` says. The
file is kept, honestly renamed in the table as the no-KU-no-EKU variant
(which is why c2pa-rs refused it), and the version rule goes to the spec
on hand-built parse data.

## Open for the spec

- **KU**: mirror c2pa-rs (`digitalSignature` or `nonRepudiation` or
  `keyCertSign`, the last two not together with `digitalSignature` on a
  non-CA) — equal to the oracle on every variant here — or require
  `digitalSignature` as §14.5's text reads, and diverge on
  `no-digital-signature` (`Trusted` there, `invalid` here). The same
  shape as the EKU decision of ADR-0003; Maurice decides.
- The AKI requirement: as c2pa-rs, since a real C2PA certificate always
  carries it and a home-made one without it is exactly the kind §14.5
  wants refused.
- The validity time: *now* until M6; `expired` shows the code.

## Files

`tests/Fixtures/profile/` — twelve `.bin`/`.png`/`.leaf.pem`, the root,
the settings, a README with the SHA-256s (re-running the script makes a
new hierarchy; the committed set is the measured one).
`tests/Fixtures/c2patool/profile/` — twelve JSONs.
