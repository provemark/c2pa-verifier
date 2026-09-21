# Step 30 — Trust measured before M5 is specified: the certificates, c2patool under nine settings, what c2pa-rs checks, and what `ext-openssl` can do

*2026-09-21.* M5 makes `Valid` into `Trusted`: the leaf certificate in
`x5chain` must chain to an anchor the operator trusts, and must look like
a C2PA signing certificate (C2PA 2.4 §14). Before a spec says how, this
step measures what the test material is, what the oracle says under
every trust configuration that matters, what c2pa-rs actually checks
(read from its source, `main` at `58eac79`), and what PHP's `ext-openssl`
can and cannot do. No verifier code, no spec.

## The certificates (openssl x509, 2026-09-21)

All byte-identical to `contentauth/c2patool`'s `sample/`, copied from the
sister repository into `tests/Fixtures/trust/` — public material only;
the private key stays where it is and `git ls-files | grep '\.key$'` is
empty.

| file | # | subject CN | issuer CN | key | sig | EKU | KU | CA | valid |
|---|---|---|---|---|---|---|---|---|---|
| `es256_certs.pem` | 1 | C2PA Signer | Intermediate CA | P-256 | ecdsa-sha256 | E-mail Protection | digitalSignature, nonRepudiation | no | 2022-06-10 → 2030-08-26 |
| | 2 | Intermediate CA | Root CA | P-256 | ecdsa-sha256 | — | digitalSignature, keyCertSign, cRLSign | yes | → 2030-08-27 |
| `trust_anchors.pem` | 1 | Root CA | (self) | P-256 | ecdsa-sha256 | — | keyCertSign… | yes | → 2032-06-07 |
| | 2 | Root CA | (self) | RSA-PSS 4096 | rsassaPss | — | keyCertSign… | yes | → 2032-06-07 |
| `allowed_list.pem` | 1 | C2PA Signer (the EC leaf, `6FB5…`) | | | | | | | |
| | 2 | C2PA Signer (an RSA-PSS leaf, `68E2…`) | | | | | | | |
| | 3 | Intermediate CA (RSA-PSS, `28F3…`) | | | | | | | |

Two independent hierarchies with the same names: an EC one (root
`BC2F…` → intermediate `3C4B…` → leaf `6FB5…`) and an RSA-PSS one (root
`7E7F…` → `28F3…` → `68E2…`). Names alone would confuse them; keys and
signatures do not — which is the point of "no trust by name".

## The chains the fixtures carry (`CoseSign1::$chain`, `openssl_x509_parse`)

| fixture | alg | x5chain | reaches an anchor via |
|---|---|---|---|
| `fixture-signed.{jpg,png,webp}` | ES256 (−7) | leaf `6FB5…`, intermediate `3C4B…` — **no root** | the intermediate's issuer signature, verified with the EC root's key from `trust_anchors` |
| `adobe-20220124-C.jpg` | PS256 (−37) | leaf `68E2…`, intermediate `28F3…`, root `7E7F…` | the root is in the chain *and* is anchor #2; the leaf and intermediate are also on `allowed_list` |

So the store's x5chain is not necessarily complete: the anchor must be
supplied by the validator, and an intermediate may be on the anchor list
(c2pa-rs sets `PARTIAL_CHAIN` for exactly that).

## c2patool 0.27.22 under nine trust settings (PNG and Adobe fixtures)

`c2patool <file> --settings <json>`; the settings files are under
`tests/Fixtures/trust/*.settings.json`, the JSON outputs under
`tests/Fixtures/c2patool/trusted/`.

| settings | PNG (EC chain) | Adobe (RSA-PSS chain) |
|---|---|---|
| none | `Valid`, failure `signingCredential.untrusted` | the same |
| `full` (anchors + trust_config, the sister file) | **`Trusted`**, success `signingCredential.trusted` ("found in System trust anchors") | **`Trusted`** |
| `anchors-no-config` (no `trust_config`) | `Trusted` | `Trusted` |
| `anchors-wrong-eku` (`trust_config` = documentSigning only; the leaf has emailProtection) | **`Trusted`** — see below | `Trusted` |
| `allowed-only` (no anchors, `allowed_list` only) | `Trusted` ("found in EndEntity trust anchors") | `Trusted` |
| `full-plus-allowed` | `Trusted` | `Trusted` |
| `verify-off` (`verify.verify_trust: false`) | `Valid`, **no `signingCredential.*` at all** | the same |
| `ec-root-only` (anchor = the EC root alone) | `Trusted` | `Valid`, `untrusted` |
| `rsa-root-only` | `Valid`, `untrusted` | `Trusted` |

Two facts about the report's *shape* that SPEC-013's `toArray()` must
follow: when there is no failure at all, c2patool **omits the
`validation_status` key** (present with `[]` nowhere in this corpus);
and the `signature_info` block per manifest is `{alg: "Es256", issuer:
"C2PA Test Signing Cert" (the leaf's O, not its issuer), common_name,
cert_serial_number: "640229…" (decimal)}` — the field the sister
library's `signer()` reads.

## What c2pa-rs checks (read, not reasoned — `main` at `58eac79`, 2026-09-21)

**Trust** (`sdk/src/crypto/cose/certificate_trust_policy.rs:186–232`,
`certificate_trust/openssl.rs:22–80`):

1. If `verify_trust` is off: `passthrough`, no code at all.
2. The leaf's SHA-256 is looked up in the **allowed list** (`allowed_list`,
   hashed per certificate): a hit is `Trusted` ("EndEntity") with no
   chain check whatsoever.
3. Otherwise, per anchor set: an OpenSSL `X509Store` with
   `X509_STRICT | PARTIAL_CHAIN`, the anchors added as trusted, the rest
   of the x5chain as untrusted intermediates, and `verify_cert()` on the
   leaf. The verification time is the timestamp's `gen_time` when there
   is one (M6), else *now* — with the note (their words) that leaving it
   unset makes OpenSSL check every certificate on the path against now,
   "matching the leaf's own 'no timestamp → valid now' fallback".
4. No anchor set at all → untrusted.

**Certificate profile** (`sdk/src/crypto/cose/certificate_profile.rs`,
§14.5), on the leaf, every fault `signingCredential.invalid` unless
noted: not a CA; X.509 v3; valid at the signing time (timestamp) or now
— else `signingCredential.expired`; signature algorithm one of
sha256WithRSA, ecdsa-with-SHA256/384/512, Ed25519, rsassaPss (whose
hash and MGF hash must agree and be SHA-256/384/512); an EC key on
P-256/384/521; an RSA modulus ≥ 2048 bits; **EKU**: no
`anyExtendedKeyUsage`; at least one allowed EKU; not an invalid
combination (OCSP+timeStamping, or either with client/server/code/email);
**KU**: `digitalSignature` required, a CA-style KU refused on an
end-entity.

**The EKU list is additive, never narrowed**
(`certificate_trust_policy.rs:496–520`, `valid_eku_oids.cfg`):
`has_allowed_eku()` returns a hit for `emailProtection`, `timeStamping`
and `ocspSigning` *unconditionally*, then for any other OID present in
`additional_ekus` — which starts as the built-in file (those three plus
documentSigning, MS C2PA Signing `1.3.6.1.4.1.311.76.59.1.9`, C2PA
Signing `1.3.6.1.4.1.62558.2.1`) and only ever grows through
`trust_config`. That is why `anchors-wrong-eku` was still `Trusted`: a
`trust_config` cannot remove emailProtection. A separate, unset-by-default
`mandatory_ekus` would be the way to require one.

## What `ext-openssl` can do (PHP 8.4, OpenSSL 3.6.3 — measured)

- `openssl_x509_verify($leaf, $intermediateKey)` → `1`;
  `openssl_x509_verify($intermediate, $ecRootKey)` → `1`;
  `…($intermediate, $rsaRootKey)` → `-1`. Per-link signature
  verification works, on PEM strings, no files — the building block for
  an own chain walk.
- `openssl_x509_checkpurpose($leaf, X509_PURPOSE_ANY, [$anchorsFile],
  $untrustedFile)` → `true` with the intermediate supplied, `false`
  without it, `false` with only the RSA root: OpenSSL's own path
  building. But it needs **files** (the settings carry PEM *strings*),
  it verifies at **now** with no way to set the time, and its purposes
  are OpenSSL's, not §14's.
- `openssl_x509_parse()` gives subject, issuer, serial (**hex**, where
  c2patool prints decimal), validity as epoch, `extensions` (EKU, KU,
  BC as text), `signatureTypeLN`, and via `openssl_pkey_get_details()`
  the key type, curve and bits. Everything §14.5 asks for except the
  RSA-PSS parameter agreement, which OpenSSL itself enforces when it
  verifies the signature.
- `phpseclib` is not a dependency and, on this evidence, not needed for
  M5: no ASN.1 has to be parsed by hand. M6 (RFC 3161 `TSTInfo`) will
  need it, or an own DER reader; that is M6's ADR.

## What this settles for the ADR and the specs

- **Chain walk in own code**, `openssl_x509_verify` per link, anchors
  matched by DER equality, `PARTIAL_CHAIN` semantics (an intermediate on
  the anchor list ends the walk), bounded depth; no temp files, no
  `checkpurpose`. Validity checked at the timestamp's time when M6
  provides one, else now — the same fallback as c2pa-rs, stated.
- **Allowed list first**, by SHA-256 of the leaf's DER, as c2pa-rs.
- **The profile** as the list above, each fault `signingCredential.invalid`
  / `.expired` with the signature box's url.
- **A decision for Maurice, not for the spec to assume**: the EKU list.
  Mirror c2pa-rs (built-in six, `trust_config` adds; equivalence with the
  oracle on any real settings file) or read `trust_config` as *the* list
  when present (§14.4.1's letter; stricter; `anchors-wrong-eku` would
  then be `untrusted` where c2patool says `Trusted`). Both are failures
  on the safe side; only the second diverges from the oracle on a
  configured file.
- `ValidationState` gains `Trusted`; `validation_status` omitted when
  empty (SPEC-013 amendment); `signature_info` per manifest (alg name,
  issuer = leaf O, CN, serial in decimal — a hex→decimal conversion
  without `gmp`/`bcmath`).
- The oracle for the M5 specs is in `tests/Fixtures/c2patool/trusted/`
  (nine files) and the inputs in `tests/Fixtures/trust/` (four PEM/cfg,
  eight settings variants).
