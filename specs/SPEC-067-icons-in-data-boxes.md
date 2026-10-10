# SPEC-067: Icons in data boxes — read, and checked

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-10                      |
| Supersedes | SPEC-034 amendment 1, the data-box refusal ("A real file with one would be its own spec") |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-034 refuses an icon whose hashed URI names a data box, knowingly against
a *should*: C2PA 2.4 §10.2.3.2 says a claim generator's icon *shall* be a
hashed URI to an embedded `c2pa.icon` assertion, and goes on: *"Manifest
Consumers should also support the data box approach recommended by earlier
versions of this specification."* The refusal rested on two facts — no
fixture and no corpus file had a data box, and `c2pa-rs` accepts one without
checking its hash — and it named its own trigger: *"A real file with one
would be its own spec."*

There is now a real file, and it is common where it occurs. The Drupal module
C2PA Sign (1.4.11) signs every upload in place with `c2patool` 0.9.12, and
when the site has a logo it puts it in `claim_generator_info` as a data box.
Measured on 2026-10-10 (the downstream Drupal module's NOTES Step 33, and
`provemark/content-credentials` SPEC-045's fixture `spec045-c2pasign-logo.png`):

- every manifest C2PA Sign writes carries a `c2pa.databoxes` store (JUMBF type
  `c2db`) holding one CBOR superbox `c2pa.data`, beside its assertion store;
- its claim's `claim_generator_info` icon is
  `{url: "self#jumbf=/c2pa/<own label>/c2pa.databoxes/c2pa.data", alg: "sha256", hash: <32 bytes>}`;
- that hash is SHA-256 over the `c2pa.data` superbox **without its 8-byte
  header** — the same range a hashed URI to an assertion covers (§8.4.2.3,
  `Superbox::payload()`); both manifests of the fixture match.
- This verifier reports `assertion.missing` on that url and `Invalid`.
  `c2patool` 0.28.1 and `c2pa-rs` (0.89.0 in ext-c2pa, 0.90.22 in the
  signing service) report the same file `Trusted` under the C2PA test
  anchors.

Downstream, that difference is the whole outcome: on the verifier route, the
only one a shared host can run, every C2PA Sign upload with a site logo reads
as untrusted and gets no suggestion.

ADR-0005 allows strictness only where it prevents a wrong `Valid` or trust in
something unchecked. Refusing a data box whose hash can be checked prevents
neither. Accepting it unchecked, as `c2pa-rs` does, would be trust in
something unchecked. So this spec reads it **and checks the hash**: more
lenient than now, stricter than `c2pa-rs`.

## Scope

**In scope**

- Reading a manifest's `c2pa.databoxes` store (`c2db`): its superboxes, by
  label, kept so a hashed URI can be checked against them.
- `IconReferenceCheck`: an icon url of the exact form
  `self#jumbf=/c2pa/<this manifest's label>/c2pa.databoxes/<label>` resolves
  to that data box, and its hash is checked over the box's payload.
- `docs/comparison.md` and `docs/reading-c2pa-2.4.md`: the rows for §5.1, §7,
  §10.2.3.2, §11.1.4.6, §18.15.6.3 and C.1 move from *not read / by design*
  to *read*.

**Out of scope** (each needs its own spec before it may be built)

- Data boxes named by anything other than an icon. No other reference to a
  data box was found; a reference to one stays whatever it is today, except a redaction (amendment 1).
- Interpreting the data box's content (its CBOR, an image format). As for
  `c2pa.icon` (SPEC-034), the bytes are bound, not judged.
- Data boxes in another manifest of the store. An icon names its own
  manifest's box or none.
- Time-stamp manifests (`c2tm`), the other construct §5.1 lists as not read.

## Behavior

- **AC1 — an icon in a data box resolves, and the file reads as the oracles
  read it**
  - Given `c2pasign-logo.png` (the downstream fixture, copied into
    `tests/Fixtures/databox/`), read under the C2PA test anchors
  - When verified
  - Then no `assertion.missing` is reported for either
    `…/c2pa.databoxes/c2pa.data` url, and the verdict is `Trusted`, as
    `c2patool` 0.28.1 reports.

- **AC2 — a data box whose bytes changed is a mismatch** *(error path)*
  - Given the same file with one byte inside a `c2pa.data` box changed (the
    claim and its signature untouched, the container's own checksum
    recomputed so only the box differs)
  - When verified
  - Then `assertion.hashedURI.mismatch` on that url, and `Invalid`.

- **AC3 — a data box that is not there is still missing** *(error path)*
  - Given an icon url naming `c2pa.databoxes/<a label no box has>`, or a
    manifest with no `c2pa.databoxes` store
  - When verified
  - Then `assertion.missing` on that url, as today.

- **AC4 — only this manifest's own data boxes** *(error path)*
  - Given an icon url whose manifest part names another manifest of the
    store (or none), even where that manifest has a matching data box
  - When verified
  - Then `assertion.missing`. The url is matched as a whole, not by its last
    segment.

- **AC5 — an ambiguous or malformed store resolves nothing** *(error path)*
  - Given a `c2pa.databoxes` store with two boxes under the same label, a
    child that is not a superbox, or a store that is not a superbox
  - When verified
  - Then the icon naming it reports `assertion.missing`, and nothing throws.
    Existing JUMBF bounds apply unchanged.

- **AC6 — nothing else moves**
  - Given the whole corpus and every existing fixture, under the two
    settings the corpus tool runs (none, and `trust/full.settings.json`),
    before and after (corrected by amendment 1)
  - When verified
  - Then every verdict and status list is identical, except on files whose
    icon names a data box.

## References

- Specification: C2PA 2.4 §10.2.3.2 (icon: hashed URI to `c2pa.icon`; data
  boxes *should* be supported), §18.12.1 (data boxes, from earlier
  versions), §11.1.4.6 (the historical `c2pa.databoxes` store), §8.4.2.3
  (what a hashed URI's hash covers), §15.10.3.3 (validation of references),
  §5.1 and Annex C.1 (deprecated constructs: validators are encouraged to
  read them). Read in the 2.4 HTML of `c2pa-org/specifications`; to be
  re-read at the pinned commit before approval.
- Oracle: `c2patool` 0.28.1 (`Trusted` on AC1's file, measured); 0.27.22 to
  be run on every probe.
- Read: `c2pa-rs` `claim.rs` `get_databox` (accepts without a hash check, per
  SPEC-034's reading).
- Measured 2026-10-10: the store layout, the url, and the hash range, on both
  manifests of the fixture.

## API sketch

Illustrative.

```php
// Manifest\Manifest gains its data boxes, keyed by label:
/** @var array<string, Superbox> */
public array $dataBoxes;   // empty when the manifest has no c2pa.databoxes store

// IconReferenceCheck, before the "names no assertion" branch:
$databoxPrefix = 'self#jumbf=/c2pa/'.$manifest->label.'/c2pa.databoxes/';
if (str_starts_with($url, $databoxPrefix)) {
    $box = $manifest->dataBoxes[substr($url, strlen($databoxPrefix))] ?? null;
    // null -> assertion.missing; hash of $box->payload() != icon hash -> mismatch
}
```

## Amendments

### Amendment 1 — from an independent review (approved by Maurice, 2026-10-10)

1. **Each data box is hashed once per algorithm** within a manifest's
   check, however many icons name it, as `HashedUriCheck` does for
   assertions (SPEC-045 AC2). Without it, 2,000 icons naming one 8 MB box
   took 42.5 s (measured by the review; before this spec, a fraction of a
   second): the check runs whether or not the claim's signature holds, so
   no key is needed. New criterion: a manifest with many icons naming one
   large data box is checked in bounded time.
2. **A redaction that names a data box is now checked.** `Manifest::resolve()`
   used to throw "unknown box" for a data-box url, so `HashedUriCheck`
   skipped such a redaction silently; it now resolves, and a box that still
   holds bytes is `assertion.notRedacted`. This is accepted as correct, and
   pinned by a test and a line in `docs/comparison.md`. The scope's "a
   reference to one stays whatever it is today" is corrected accordingly.
3. **The children of a `c2db` store go through the JUMBF rules** like every
   other walked superbox (Requestable, label rules, budgets). A malformed
   data box, even one no icon names, now refuses the store with a parse
   error where it used to be ignored. Accepted: it is an error, never a
   wrong `Valid`, and no real file in the corpus has one. Pinned by a test.
4. **`alg` as `HashedUriCheck` handles it:** the icon's `alg`, or the
   claim's; no algorithm, a non-string `alg`, or one outside sha256,
   sha384, sha512 is `algorithm.unsupported`; a hash of the wrong length,
   or not a byte string, is `assertion.hashedURI.mismatch`. New tests.
5. Cosmetic: a misplaced docblock, a `sprintf` without arguments.

## Open questions

- **The consolidation period** (no new features until about 22 October).
  This lifts a deliberate refusal rather than adding a capability, on a
  trigger SPEC-034 named itself.
  *Status 2026-10-10 (step 341):* decided by Maurice, it goes ahead.
- **Version.** Fewer files read `Invalid`, nothing that passed stops
  passing, no API change: proposed **0.6.1**, which `provemark/content-credentials`
  0.19 (`<0.6 || >=0.7` conflict) and the Drupal module (`^0.6`) take
  without a change. *(not a blocker)*
  *Status 2026-10-10 (step 342):* open; settled with the release, after
  `bin/api-check.php`.
- **The manifest label's leading colon.** C2PA Sign's labels read
  `:urn:uuid:…`; the url carries the same string, so AC4's whole-url match
  works on it as it is. Whether that colon is itself a defect of
  `c2patool` 0.9.12 is not this spec's question. *(not a blocker)*
  *Status 2026-10-10 (step 342):* reasoned, and measured in AC4's test,
  which resolves the colon-prefixed labels as they are.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Manifest/DataBoxIconTest.php :: resolves an icon in a data box, and reads the file as the oracle does / SPEC-067 | src/Jumbf/JumbfParser.php :: UUID_DATABOX_STORE, KNOWN_SUPERBOXES; src/Manifest/IconReferenceCheck.php :: check(), checkDataBox() |
| AC2 | tests/Unit/Manifest/DataBoxIconTest.php :: reports a data box whose bytes changed as a mismatch / SPEC-067 | src/Manifest/IconReferenceCheck.php :: checkDataBox() |
| AC3 | tests/Unit/Manifest/DataBoxIconTest.php :: still reports a data box that is not there as missing (two variants); resolves nothing, and does not throw, for a manifest without a data box store / SPEC-067 | src/Manifest/IconReferenceCheck.php :: dataBox(), checkDataBox() |
| AC4 | tests/Unit/Manifest/DataBoxIconTest.php :: resolves only the manifest's own data boxes / SPEC-067 | src/Manifest/IconReferenceCheck.php :: dataBox() (the prefix) |
| AC5 | tests/Unit/Manifest/DataBoxIconTest.php :: resolves nothing for a child that is not a superbox; resolves nothing when two data boxes share the label; reads a data box only from a store of the data box type; resolves nothing when the manifest has two data box stores / SPEC-067 | src/Manifest/IconReferenceCheck.php :: dataBox() |
| AC6 | the corpus before and after (notes/step-342-icons-in-data-boxes.md); every existing test unchanged | bin/make-databox-variants.php |
| Amendment 1 | tests/Unit/Manifest/DataBoxIconTest.php :: hashes one data box once, however many icons name it; checks a redaction that names a data box; refuses the store when a data box breaks the JUMBF rules, named or not; handles the icon's alg as a claim entry's (6 cases) / SPEC-067 | src/Manifest/IconReferenceCheck.php :: check() ($digests), checkDataBox(), ALGORITHMS |

The SPEC-067 tests were run red first (8 failed: every data-box url was
`assertion.missing`, in both C2PA Sign manifests). Mutations watched
failing: the store not walked, the hash not checked, any manifest's url
accepted, the first of two boxes taken, the store's UUID not checked, the
first of two stores taken. A seventh, a deeper path accepted, survived:
`JumbfParser` already refuses a label holding `/`, so that guard was dead
code and was removed. `composer check` green (1019 passed); the public
contract unchanged (`bin/api-check.php`, 135 symbols).

Amendment 1 (step 343): the bounded-time test and the alg cases were run red
first (3.07 s; `checkDataBox` not reachable), then green (0.03 s). The
redaction and JUMBF-rule tests pin behaviour the commit already had. Five
mutations: no cache, alg always sha256, a non-string alg falling back, an
unsupported alg reported as a mismatch were caught; a separate length check
survived because `hash_equals` already refuses a hash of another length, and
was removed. The corpus is identical to step 342's run.
