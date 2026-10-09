# SPEC-065: The alternative content representation — the original preservation image's rules

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A camera can record an *Original Preservation Image* (OPI, CIPA Exif):
the image as captured, before in-camera processing. C2PA 2.4 §18.14 lets a
manifest point at it with a `c2pa.alternative-content-representation`
assertion of type `exif.originalPreservationImage`. The image is either a
part of a multi-picture file, named by `multiAssetPartIndex` into the
manifest's `c2pa.hash.multi-asset` parts, or carried in the manifest as an
embedded data assertion, named by the hashed URI
`embeddedOriginalPreservationImage`.

§15.10.3.2.7 tells the validator what to check:

- at most one such assertion of type `exif.originalPreservationImage` in
  the active manifest, else `assertion.alternativeContentRepresentation.malformed`;
- exactly one of the two fields, else malformed;
- `multiAssetPartIndex`: the active manifest has a `c2pa.hash.multi-asset`
  assertion and the index is inside its `parts`, else malformed;
- `embeddedOriginalPreservationImage`: a hashed URI with a `hash` (else
  malformed) that matches the data it names, else
  `assertion.alternativeContentRepresentation.hashMismatch`;
- no failure: the success code `assertion.alternativeContentRepresentation.match`.

This verifier checks none of it (issue #5). Neither does `c2pa-rs`
0.91.1: it has no code for the assertion, so `c2patool` ignores it. The
assertion itself is covered by the claim's hashed URI, so it cannot be
altered after signing. What goes unchecked is whether it points at what
it says.

## Scope

**In scope**

1. Every `c2pa.alternative-content-representation` assertion (any
   instance) the claim lists, in the active manifest and in every
   ingredient manifest this verifier validates, as for cloud data
   (SPEC-063). An assertion whose `type` is not
   `exif.originalPreservationImage` is a generic representation and is
   not checked (§15.10.3.2.7 names no rule for it).
2. **The count.** Two or more of type `exif.originalPreservationImage` in
   one manifest: each is malformed.
3. **The shape.** The assertion is a map whose `parameters` is a map
   holding exactly one of `multiAssetPartIndex` (an unsigned integer) and
   `embeddedOriginalPreservationImage` (a map with a text `url`); else
   malformed.
4. **The index**, in the active manifest only (§18.14.2.1: it *"can only
   be validated"* there): a `c2pa.hash.multi-asset` assertion the claim
   lists, with a `parts` list, and `0 ≤ index < count(parts)`; else
   malformed. In an ingredient manifest the index is not checked. The
   multi-asset hash itself stays unread (`docs/conformance.md` §3).
5. **The embedded image.** The `hash` is a byte string, else malformed.
   The url is resolved in the same manifest and its box is hashed as
   `HashedUriCheck` hashes any hashed URI (§8.4.2.3, the algorithm by
   §15.4). A mismatch, or a url that names nothing, is `hashMismatch`.
6. **Success.** An OPI assertion with no fault records `match`, a success
   code, as `assertion.dataHash.match` is.
7. The three codes join `StatusCode` verbatim: `malformed` and
   `hashMismatch` as failures, `match` as a success. The report names the
   check (`alternativeContent`) only where a manifest carries the assertion.
8. Probes from a builder, judged by both `c2patool` versions.

**Out of scope** (each needs its own spec before it may be built)

- The multi-asset hash: its parts, locators and coverage (§15.12.4).
- Whether the embedded data is an image, or the OPI the camera recorded.
- A `c2pa.deleted` action or a redaction when the OPI is removed (§18.14.2.1).

## Behavior

- **AC1 — an embedded OPI that matches is `match`**
  - Given an OPI assertion whose `embeddedOriginalPreservationImage` names
    an assertion of the same manifest with its correct hash
  - Then `assertion.alternativeContentRepresentation.match`, and the file
    keeps its state (`Trusted` under the probes' settings)

- **AC2 — a hash that does not match** *(error path)*
  - Given the same with another 32-byte hash
  - Then `assertion.alternativeContentRepresentation.hashMismatch`, `Invalid`

- **AC3 — the shape** *(error paths)*
  - Given an OPI assertion with both fields, with neither, with an
    embedded reference without `hash`, and (in the unit test) with a
    `parameters` that is not a map, a negative or text index, a `url`
    that is not text
  - Then each is `assertion.alternativeContentRepresentation.malformed`
    naming the fault, `Invalid`

- **AC4 — an index without a multi-asset hash, or out of bounds** *(error paths)*
  - Given `multiAssetPartIndex` 0 in a manifest without
    `c2pa.hash.multi-asset`, and (unit test) an index equal to the parts'
    count
  - Then malformed, `Invalid`

- **AC5 — two OPI assertions in one manifest** *(error path)*
  - Then each is malformed, `Invalid`

- **AC6 — a generic representation is not checked**
  - Given an assertion of another `type`
  - Then no `alternativeContentRepresentation` code, and the state is
    unchanged

- **AC7 — the oracles**
  - Both `c2patool` versions judge every probe. They ignore the
    assertion, so AC2 to AC5 are stricter than `c2patool`, by the
    specification's rule, and named.

- **AC8 — the vocabulary grows by three codes, verbatim**

## References

- Specification: C2PA 2.4 §15.10.3.2.7, §18.14 (§18.14.2.1 description,
  §18.14.2.2 schema), §18.9 (`parts`), §15.10.3.3 and §8.4.2.3 (hashing
  the named box), the §15 status table.
- Oracle: `c2patool` 0.28.1 and 0.27.22 on the builder's probes.
- Read: `c2pa-rs` 0.91.1 has no code for this assertion (searched for
  `alternative-content` and `alternativeContentRepresentation`).

## API sketch

```php
namespace Provemark\C2paVerifier\Manifest;

/** @internal SPEC-025 */
final readonly class AlternativeContentCheck
{
    public const LABEL = 'c2pa.alternative-content-representation';

    /** @param list<string> $unreadable @return list<ValidationStatus> */
    public function check(Manifest $manifest, bool $active, array $unreadable = []): array;

    public static function present(Manifest $manifest): bool;
}
```

## Open questions

- 1. **Ingredient manifests.** §15.10.3.2 applies to each assertion of a
  validated manifest, and this spec checks them there too, except the
  index (scope 4). Proposal: as written.
- 2. **A url that names nothing.** §15.10.3.2.7 says to *"resolve"* it and
  gives no code for failing to. Proposal: `hashMismatch`, since nothing
  was found to match the declared hash. `assertion.missing` would be
  another reading.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
| AC8                  | —                           | —                    |
