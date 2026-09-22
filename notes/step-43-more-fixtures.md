# Step 43 — More fixtures, from other writers: what nine open-source repositories hold, five files taken, three findings

*2026-09-22.* Maurice asked whether the code really works and whether
more fixtures could be found. The three corpora so far come from four
writers (Adobe 2022 test material, Nikon, Truepic, c2pa-rs's own
`make_test_images`) and one oracle. This step looked for files from
*other* writers, ran c2patool and this verifier over them, and took what
was new into a fourth corpus, `tests/Fixtures/writers/`. Measurement
only; the fixes it calls for are step 44.

## Where I looked (measured: `gh api …/git/trees/HEAD?recursive=1`)

| repository | image files | new here | note |
|---|---|---|---|
| `c2pa-org/public-testfiles` (CC BY-SA 4.0, pushed 2026-07-30) | 115 under `legacy/1.4/image/jpeg`, of which 26 assets — all already in `tests/Fixtures/public-testfiles/` — and 89 recorded thumbnails/JSON under `manifests/`; `2.2/image/{good,bad}/…` hold READMEs and `.gitkeep` only | none | the 2.2 tree is empty; `manifests/` are c2patool outputs of an older version — a historical oracle at most |
| `encypherai/c2pa-conformance-suite` (**Apache-2.0** — corrected in step 71; this row said "no licence declared", pushed 2026-09-17, 1 star) | 0 | none | a Python validator over 242 knowledge-graph predicates with 140 JSON rubric vectors; no assets. Possibly a second *tool* to compare with later, not a corpus |
| `richardwooding/c2pa` (Go, MIT) | 4 | 2 | `c2pa_2x_openai.png` (OpenAI), `cawg_ica.jpg`; `c2pa_signed.jpg` = `c2pa-rs/CA.jpg`, `cawg_x509.jpg` = `C_with_CAWG_data.jpg` (md5) |
| `TrustNXT/c2pa-ts` (TypeScript, Apache-2.0) | 4 | 2 | `amazon-titan-g1.png` (Amazon Bedrock), `trustnxt-icon-signed-timestamp.jpg` (written by `c2pa-ts` itself); two unsigned |
| `contentauth/c2pa-js` (MIT) | 12 | 1 | `PirateShip_save_credentials_to_cloud.jpg` (Photoshop 27.4, remote manifest); the rest are c2pa-rs fixtures |
| `faceless2/c2pa` (Java, Apache-2.0) | 27 | 0 taken | 26 = public-testfiles; `adobe-20221004-ukraine_building.jpeg` (7.7 MB, Adobe Stock, c2pa-rs 0.4.2) left out — see the corpus README |
| `contentauth/c2pa-python` | 29 | 0 | all c2pa-rs fixtures |
| `c2pa-org/conformance`, `contentauth/c2pa-web`, `ProofMode/c2pa-android`, `contentauth/c2pa-swift-example` | — | — | not found / private |

Five files, 4.7 MB, licences alongside, c2patool's JSON under
`tests/Fixtures/c2patool/writers/`.

## What c2patool and this verifier say (measured, no settings)

| file | c2patool | this verifier | agree? |
|---|---|---|---|
| OpenAI PNG (PS256, v2, `sigTst2`, private TSA) | `Valid`; `validated`, `untrusted`; `time` `…10:48:55.837381+00:00` | `Valid`; `validated`, `untrusted`; `time` `…10:48:55+00:00` | state yes; **`time` no — the microseconds** |
| Amazon Bedrock PNG (**ES384**, v1, `sigTst`, DigiCert 2023) | `Valid`; `validated`, `trusted`; `time` `2024-09-25T08:29:07+00:00` | `Invalid`: **`timeStamp.malformed` "INTEGER at offset 119 is negative"**, hence `expired` at now | **no** |
| TrustNXT JPEG (ES256, **v1 with `sigTst2`**, `c2pa-ts`) | `Valid`; `validated`, `trusted`; `time` `…12:35:40.669+00:00` | `Valid`; **`timeStamp.malformed` "INTEGER at offset 108 is negative"**; no `time` | state yes (the signer is not expired); timestamp **no** |
| Photoshop JPEG (remote manifest) | `Valid` — the manifest fetched from `cai-manifests.adobe.com` | `hasManifest` false, nothing said | by design (no network) — but **silent** about the URL |
| `cawg_ica.jpg` (two manifests, CAWG ICA) | `Valid`, both manifests | `Invalid`: `general.error` ×2 (multi-manifest until M7; CAWG refused) | by design, named |

The ES384 signature of the Amazon file verified (`claimSignature.validated`)
— the first ES384 signer any corpus has shown; M3's code took it without
a change.

## Three findings

1. **A negative INTEGER is legal DER, and TSA clients send negative
   nonces.** RFC 3161's `nonce INTEGER OPTIONAL` is a random value; when
   its first byte has the high bit set and the client encodes it as is,
   the INTEGER is negative — `openssl ts -reply -text` prints
   `Nonce: 0x-335F9549` (Amazon) and `0x-612D17525B24B1B64D0E` (TrustNXT).
   SPEC-016's `Der::integer()` refuses every negative INTEGER ("none of
   the four structures has one" — true of the five tokens measured then,
   wrong in general). Two of five new tokens fail on it. c2pa-rs reads
   the nonce as a big integer of either sign. **Fix (SPEC-016
   amendment 3):** `integer()` takes a signed reading for the nonce
   (two's complement → a decimal with a minus sign); serials stay
   non-negative (RFC 5280 §4.1.2.2; a negative serial would be a
   `malformed` we have not met).
2. **`genTime` may carry fractional seconds, and c2patool keeps them in
   `signature_info.time`.** RFC 3161 §2.4.2 allows them; OpenAI's TSA
   writes microseconds, `c2pa-ts` milliseconds; c2patool renders
   `2026-08-26T10:48:55.837381+00:00` and `…40.669+00:00` — the token's
   own digits, not a fixed width. `Der::time()` drops the fraction
   (SPEC-016 said so, as an open question "if a corpus token carries
   fractions and c2patool renders them, an amendment keeps them" — it
   does, on two of five). **Fix (SPEC-016 amendment 3, SPEC-017
   amendment 2):** the TSTInfo keeps the fraction digits; the report's
   `time` prints them as c2patool does; the epoch handed to SPEC-015 is
   unchanged (whole seconds — a certificate's validity has none).
3. **A remote manifest is declared in the file and this verifier says
   nothing.** The Photoshop file carries no JUMBF; its XMP has
   `dcterms:provenance="https://cai-manifests.adobe.com/manifests/urn-c2pa-…"`
   (C2PA 2.4 §11.4, remote manifests). Not fetching is the rule (no
   network in the verification path); saying nothing is a gap: the
   caller cannot tell "no Content Credentials" from "Content Credentials
   elsewhere". `cloud.jpg` in the c2pa-rs corpus is the same case, excused
   as `_REMOTE`. **Proposal (a small spec, or SPEC-013 amendment):**
   detect the XMP `dcterms:provenance` URL in the three containers and
   report `hasManifest` false with a `manifest.remote` note carrying the
   URL, informational, never fetched. Not a §15 code; the report shape
   decides where it goes — for Maurice.

Two facts confirmed rather than found: `c2pa-ts` writes a **v1 claim
with `sigTst2`** and c2patool validates it — SPEC-017's choice not to
check the header/claim-version pairing (as c2pa-rs) was right; and the
OpenAI token's private "OpenAI TSA Leaf" is `untrusted` at c2patool as
well as here — the one TSA in all corpora where the oracle and this
verifier agree on `untrusted`, and a hint that c2patool's `trusted` for
the others rests on a list that has DigiCert and Truepic on it (step 40
§5 stays open).

## What "does the code really work" comes to

Measured: on 22 + 24 + 17 + 5 files from seven writers and seven signing
algorithms, every state this verifier gives is c2patool's or stricter by
a named rule, with one class of exception now known — the timestamp
reader's two literal-mindednesses above, which cost the *time* on two
files (one of them the verdict, through `expired`). Nothing was more
lenient than c2patool on any file. Not measured: a second independent
oracle (the Go implementation and the conformance suite are candidates;
neither was run), formats beyond JPEG/PNG/WebP, and anything a
multi-manifest store hides until M7.

Step 44: the two amendments with their tests seen red on these files,
and the writers corpus as the fourth drift alarm.
