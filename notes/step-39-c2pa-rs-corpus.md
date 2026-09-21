# Step 39 — The oracle's own fixtures as a third corpus, and three more things they taught

*2026-09-21.* Maurice asked whether the fixtures were in order and whether
more could be found. c2pa-rs — the library c2patool is — tests itself
with 33 JPEG, PNG and WebP files under `sdk/tests/fixtures/`, Apache-2.0
OR MIT. They are now `tests/Fixtures/c2pa-rs/` (12 MB, licences
alongside), c2patool's JSON for the 17 that yield one under
`tests/Fixtures/c2patool/c2pa-rs/`, and the whole set a third drift
alarm (SPEC-013 AC12). On first contact 5 of 33 states agreed; the
differences sorted into "no manifest" (12), "remote manifest" (2),
"both refuse" (2), and three findings.

## Finding 1: indefinite-length CBOR (SPEC-006 amendment 3, "optie a")

Nine files carry indefinite-length arrays or maps in the *claim* — the
older c2pa-rs writers, and `C_with_CAWG_data.jpg` with a 2.x
`urn:c2pa:` label. SPEC-006 refused them because RFC 8949 §4.2.1's
deterministic encoding, which C2PA asks of a claim, forbids them. The
oracle writes and reads them. Maurice chose to accept them, bounded:
the decoder now reads chunked strings (RFC 8949 §3.2.3 — same major
type, definite chunks only), and indefinite arrays and maps until a
break, counting items against `maxItems` and depth against `maxDepth`
as their definite twins do; a break outside an indefinite item, a chunk
of another type, a nested indefinite chunk and an unterminated stream
are still errors naming the offset. Seen red on the Appendix A rows,
then green. The step-14 variant `claim-indefinite-array` now decodes,
so SPEC-010 AC6 and SPEC-013 AC7 use `claim-duplicate-key` as their
CBOR-fault example (their amendments).

## Finding 2: a `null` `claim_generator_info` (SPEC-007 amendment 4)

`ocsp.jpg`'s first manifest, a v1 claim, has `claim_generator_info:
null`. `Claim::fromMap()` refused it as "not a non-empty list of maps";
c2pa-rs reads `null` as none. Now a `null` is absent — and a required
field that is `null` is missing (v2 still requires the field). Test:
ManifestStoreTest "AC5 (amendment 4)".

## Finding 3: a CAWG identity assertion (SPEC-013 amendment 7, for confirmation)

`C_with_CAWG_data.jpg`: the C2PA manifest is intact and its signer
trusted — c2patool's success list says so — but the manifest carries a
`cawg.identity` assertion with an X.509 credential of its own, which
c2patool validates and finds untrusted; its state is therefore `Valid`,
not `Trusted`. This verifier saw an assertion whose hashed URI matched
and said `Trusted`: more lenient than the oracle on a credential it
never examined — the same shape as the ingredient case of amendment 5.
Treated the same way: a `cawg.identity` assertion adds a `general.error`
on its URI and the state is `Invalid`, until a spec validates CAWG
identity assertions. Stricter than c2patool's `Valid`, in the safe
direction, named in `SPEC013_RS_CAWG`. Made under the principle Maurice
chose in step 36; his confirmation asked.

## The third drift alarm

`SPEC013_RS_CORPUS` (17 files) in `tests/Pest.php`, with the named
exceptions: `SPEC013_RS_MULTI` (seven, M7 — `update_manifest` also
carries a `c2um` box SPEC-005 refuses), `SPEC013_RS_NO_TIMESTAMP`
(`ocsp`, `ocsp_with_assertion`: one-year leaves, M6), `SPEC013_RS_REMOTE`
(`cloud`: c2patool fetched the manifest over the network — this
verifier never will), `SPEC013_RS_CAWG`. After this step:

| | files |
|---|---|
| equal | `C`, `CA`, `CA_ct`, `boxhash` (`Trusted`); `E-sig-CA`, `XCA`, `adobe-20220124-E-clm-CAICAI`, `exp-test1` (`Invalid`) — 8 of 17 |
| stricter on purpose, named | 9 of 17 |
| more lenient than c2patool | **0** |

`composer check` → **`206 passed (2253 assertions)`**.

## For M6

Thirteen of the 17 JSONs carry `timeStamp.validated` (and mostly
`timeStamp.trusted`); `boxhash.jpg` and the `C`/`CA` family are
timestamped by the c2pa-rs test TSA, `ocsp*.jpg` by Adobe's, Nikon and
Truepic by theirs. Five signers' timestamps to measure against.
