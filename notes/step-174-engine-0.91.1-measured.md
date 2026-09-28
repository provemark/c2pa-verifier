# Step 174 — c2pa-rs 0.91.1 read against this verifier, measured

*2026-09-28. Measurement only: no spec, test or code in `src/` changed.
The reference engine moved from 0.90 (c2patool 0.27.22) and 0.91.0
(c2patool 0.28.0) to 0.91.1 on 2026-09-27. Its verification-side changes
were read one by one and, where a wrong verdict was possible, probed.*

## What moved upstream

`c2pa-v0.91.1` carries three fixes on the read path (the rest of the
release is writing and CI). Their pull requests, and how each bears on a
pure-PHP verifier with no network:

1. **PR #2712 — reject invalid RSA public exponents in certificate
   profiles (CAI-13156 / VULN-37613).** With public exponent `e = 1`,
   `s^e mod n = s`, so raising a signature to the public exponent is the
   identity and a PS256 claim validates without the private key. c2pa-rs
   now refuses `e < 3`, even `e`, and negative `e` during profile
   validation.
2. **PR #2686 — ignore unreferenced claims when reconciling validation
   statuses.** An attacker-supplied *unreferenced* manifest could
   suppress a genuine failure on a referenced claim, because
   `ValidationResults::from_store` pooled ingredient-recorded statuses
   from **every** parsed claim and de-duplicated live findings against
   them (matched by `code` + `url` + `kind`). Includes the
   `claimSignature.insideValidity` / expiry path.
3. **PR #2688 — validate live-fetched OCSP responder certificates at the
   current time, and finish claim verification on revocation.** Online
   AIA OCSP responder certificates rotate and were being judged at the
   asset's historical `signing_time`, which dropped
   `signingCredential.ocsp.revoked` / `notRevoked`.

## 1. RSA public exponent — a wrong `Trusted` here

Measured with a throw-away P-256 root as the one anchor, and three RSA
leaves that re-sign the PNG fixture's claim (PS256, `x5chain` protected).
The `e = 65537` and `e = 3` leaves use real PSS keys; the `e = 1` leaf's
signature is the PSS-encoded message itself, computed **without any
private key** — the whole point of the attack. `openssl_pkey_get_details()`
reports the exponent faithfully (`['rsa']['e']` = `01`, `03`, `010001`).

| leaf | this verifier | c2patool 0.27.22 (0.90) | c2patool 0.28.0 (0.91.0) | c2pa-rs 0.91.1 (read) |
|---|---|---|---|---|
| `e = 65537` | `Trusted` | `Trusted` | `Trusted` | trusted |
| `e = 3` | `Trusted` | `Trusted` | `Trusted` | trusted |
| **`e = 1`** | **`Trusted`** | **`Trusted`** | **`Trusted`** | **refused** |

The `e = 1` row is a wrong `Trusted`: a file no one holds the key for is
trusted. This verifier is level with every released binary — there is no
c2patool on 0.91.1 yet — but the fixed engine now refuses it. SPEC-015
checks the RSA **modulus** (≥ 2048 bits, C2PA 2.4 §14.5.1.1, verbatim:
"the modulus field of the parameters field shall have a length of at least
2048 bits") but nothing checks the exponent; the specification itself
states no exponent bound, so refusing `e < 3` or even `e` is being
stricter than the letter, as c2pa-rs 0.91.1 is, to close this attack
(ADR-0005).

The intermediate case was probed too: an `e = 1` intermediate under the
root, issuing a leaf named `O=Adobe Inc` whose issuing signature is the
same keyless `e = 1` forgery. The chain walk **accepted the forged link**
— `signingCredential.trusted` succeeded — and the file was `Invalid` only
because the probe leaf lacked an AuthorityKeyIdentifier, an artefact of
the probe, not a check on the exponent. A clean intermediate probe did
not resolve within this step (an `openssl verify` issuer-matching detail),
so the exact net verdict for a well-formed `e = 1` intermediate is left
open. What is measured is that the walk does not refuse `e = 1` anywhere.
PR #2712 itself is scoped to the end-entity profile, so the leaf is the
firm, spec-backed finding.

## 2. Unreferenced-claim suppression — covered here by construction

Read from the code, not probed, because the structure forbids it. This
verifier does not pool ingredient-recorded statuses across the whole
store to cancel live findings. `Verifier::check()` reconciles only
**scoped** statuses (`ingredientUri !== null`) through
`IngredientManifestCheck::drop()`, and:

- a status with `ingredientUri === null` — the active manifest's own
  line, which is where signature, certificate, trust and expiry land — is
  never dropped (`drop()`, the first guard);
- the recorded set is `recordedInStore($graph)`, built from the active
  manifest and the manifests the ingredient graph **reaches**, never an
  unreferenced one (SPEC-021 amendment 6 / step 149, SPEC-035 amendment 5
  / step 158);
- a failure of the active manifest, or of the manifest that binds an
  update manifest's asset, is protected by name.

So an unreferenced manifest cannot cancel a finding about the active
manifest, which is exactly what PR #2686 closes upstream. A confirming
probe (an unreferenced claim forging `insideValidity` for the active
signature) is the natural follow-up, but the class is already the one
steps 149 and 158 built fixtures against.

## 3. OCSP responder timing — out of this project's path

PR #2688's first half is about **live-fetched** (AIA) OCSP responder
certificates. This verifier never goes online: it reads only the OCSP
responses a signer staples into `rVals`, and those were signed at the
asset's time, which is the right time to judge them (SPEC-030). The
responder-rotation problem does not arise. Its second half — on
revocation, append the log and finish verification rather than returning
early — concerns the online path too; stapled revocation here already
records `signingCredential.ocsp.*` and never returns a bare default.
Neither half is a wrong `Valid`.

## Conclusion

One new gap that yields a wrong `Trusted`: an RSA signing certificate with
public exponent `e = 1` (and, by the same arithmetic, `e = 2` or any even
exponent). It is a candidate for a spec that refuses such a certificate,
as c2pa-rs 0.91.1 does. Point 2 is covered by construction; point 3 is
outside the no-network path.

## Disclosure

Point 1 affects every released version up to and including 0.2.5. By
SECURITY.md's definition it sits on the line: the pinned `c2patool`
versions give the same `Trusted`, but the specification arguably refuses
it — §13.2.1 requires refusing keys "not correct for the algorithm
choice", and RFC 8017 §3.1 does not count `e = 1` as an RSA public
exponent — and the reference engine now does too. The technique is public
through PR #2712's own description. How to treat it (a security release,
an advisory, the order of pushing) is the maintainer's decision; until
then this note and SPEC-049 stay local, as steps 148–155 did.

The probe keys never entered the repository and were deleted at the end of
the run; the probe scripts and the throw-away certificates stay outside
the repository.
