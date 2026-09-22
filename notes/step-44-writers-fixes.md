# Step 44 — What the writers corpus fixed: signed nonces, kept fractions, sorted attributes, raw ECDSA, and a remote manifest named

*2026-09-22.* Step 43's three findings, plus a fourth the fix uncovered,
as amendments with their tests seen red on the new files first, then
green: SPEC-016 amendment 3, SPEC-017 amendments 2–3, SPEC-013
amendment 9. `composer check` green, `Tests: 293 passed (3472
assertions)` (was 285).

## Red first

The new tests (`SPEC-016 AC11` ×5, `SPEC-017 AC11`, `SPEC-013 AC13`,
`AC14`) on the code as it stood: 5 + 1 + 1 red, AC13 green at once (the
fourth drift alarm needed no change — every writers file was already
c2patool's state or a named exception). The red reasons: `Unknown named
parameter $signed`, `undefined method Der::timeFraction()`, the two
`malformed` tokens, and `remote_manifest` absent.

## The fixes, and what each measured

1. **Signed INTEGER for the nonce** (`Der::integer(signed: true)`, two's
   complement → decimal with a minus sign): Amazon's `0x-335F9549` reads
   `-861902153`, `c2pa-ts`'s `0x-612D17525B24B1B64D0E` reads
   `-458901332827496636632334`; `02 01 ff` → `-1`, `02 02 ff 7f` → `-129`;
   the unsigned reading still refuses. Serials, versions and accuracies
   stay unsigned.
2. **Fractions kept** (`Der::timeFraction()`, `TstInfo::$genTimeFraction`,
   `TimestampResult::timeIso()`): `signature_info.time` is now
   `2026-08-26T10:48:55.837381+00:00` (OpenAI) and
   `2026-01-13T12:35:40.669+00:00` (`c2pa-ts`) — byte-equal to
   c2patool's; the epoch SPEC-015 judges at is unchanged.
3. **The DER-canonical SET, not a re-tag** — the finding the fix
   uncovered. With the nonce read, the `c2pa-ts` token still failed:
   "timestamp signature did not verify". By hand, `openssl_verify` over
   five candidate inputs: the attributes as `[0]` → 0, re-tagged as `SET`
   → 0, **re-tagged and sorted → 1**, the `eContent` → 0, the
   `messageDigest` → 0. RFC 5652 §5.4 signs "the complete DER encoding of
   the SET OF signedAttrs" and DER sorts a SET OF (X.690 §11.6); every
   TSA measured before wrote the attributes sorted, so the re-tag *was*
   the DER encoding — by luck of the writers, not by the rule. `c2pa-ts`
   writes `contentType, messageDigest, signingCertificateV2` unsorted and
   signs the sorted form. `signedAttributesForVerification()` now sorts
   (shorter padded with zeros, as §11.6 says) and re-encodes the length;
   on the five older tokens the result is byte-equal to the re-tag —
   SPEC-016 AC5 still asserts "differs in exactly the first byte" and
   stays green, which is the measurement of that claim. c2pa-rs
   re-encodes with `rasn` and so sorts as well.
4. **Raw R‖S ECDSA in CMS**: `c2pa-ts` writes the 64-byte P1363 form
   where RFC 3279 wants DER; c2patool accepts it. Accepted here by a rule
   that cannot misread DER: a well-formed `SEQUENCE { INTEGER, INTEGER }`
   passes through; otherwise exactly two coordinates convert as SPEC-009
   converts COSE's R‖S (`EcdsaSignature::toDer`); anything else fails.
5. **A remote manifest is named** (`Container\RemoteManifestDetector`;
   `VerificationReport::$remoteManifestUrl`; `remote_manifest` in the
   array after `has_manifest`): the first 8 MiB searched for XMP
   `dcterms:provenance="…"`, reported only when it is an `http(s)` URL of
   printable ASCII, never fetched, `checks_performed` empty. `cloud.jpg`,
   `cloudx.jpg` (its deliberately broken `cai-manifestx` host reported as
   declared, not judged) and the Photoshop file; the unsigned fixtures,
   `adobe-20220124-A` and a signed file report nothing.

## The writers corpus now (measured, no settings)

| file | state | `time` | timestamp |
|---|---|---|---|
| OpenAI | `Valid` | `…55.837381+00:00` | `validated`, `untrusted` (private TSA; c2patool the same) |
| Amazon Bedrock (ES384) | `Invalid` | `2024-09-25T08:29:07+00:00` | `validated`, `untrusted` → `expired` at now; with the DigiCert cross-certificate as anchor: `trusted`, no `expired`, `Valid` as c2patool |
| `c2pa-ts` (v1 + `sigTst2`, raw ECDSA, unsorted attributes) | `Valid` | `…40.669+00:00` | `validated`, `untrusted` (the TSA is the test signer with an `emailProtection` EKU — no `timeStamping`, so the profile refuses it; c2patool says `trusted`) |
| Photoshop (remote) | `Invalid`, no manifest | — | `remote_manifest` = `https://cai-manifests.adobe.com/manifests/…` |
| `cawg_ica` | `Invalid` (two manifests, CAWG) | — | — |

`SPEC013_WRITERS_*` in `tests/Pest.php` name the three exceptions.

## Honest tally

Two of step 43's findings were this verifier being literal-minded where
the standards allow more (negative nonces, fractions); one was a gap in
what it says (remote manifests); and the fix uncovered a fourth that was
a genuine correctness hole hidden by five well-behaved TSAs — the SET
ordering. None made a wrong `Valid`; all cost the *time*, and one of
them a verdict through `expired`. The lesson for the corpus policy: one
writer that is not c2pa-rs (`c2pa-ts`) found more than sixteen c2pa-rs
fixtures did. More such writers are worth more than more files.
