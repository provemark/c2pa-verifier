# SPEC-018: The actions assertion — a 2.x manifest opens with `c2pa.created` or `c2pa.opened`, or it is not valid

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-22                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The absence audit (step 48) built a properly signed 2.x manifest with
its actions assertion removed. c2patool refuses it —
`assertion.action.malformed`, "first action must be created or opened",
on the manifest's url — and this verifier says `Valid`, `Trusted` with
the signer's root as an anchor. No check here reads the content of any
assertion; the actions assertion is the one whose *presence and opening*
the specification makes normative for 2.x manifests, and the one c2pa-rs
verifies before it says `Valid`. Being more lenient than the oracle is
what the drift alarms exist to forbid.

C2PA 2.4 §18.x (actions): a standard manifest with a 2.x claim shall
carry an actions assertion (`c2pa.actions.v2`) whose first action is
`c2pa.created` (the asset was made here) or `c2pa.opened` (an existing
asset was opened for editing); an update manifest is exempt. c2pa-rs
0.90.22 (`claim.rs`, `verify_actions`) implements exactly that for
claim v2 — the first actions assertion among `created_assertions`,
then `gathered_assertions`, must exist and open with one of the two;
every actions assertion must have a non-empty `actions` list — and for
claim v1 only "at most one actions assertion" (the rest only under
`strict_v1_validation`, off by default). Measured on the corpora (step
48): all eight v2 manifests open with `created`/`opened`; six v1
manifests carry no actions assertion at all (Truepic ×3, Nikon,
`cawg_ica`, `c2pa-ts`) and one opens with `c2pa.edited`
(`exp-test1.png`) — all accepted by c2patool, as v1. The rule as
c2pa-rs applies it changes no corpus verdict; the audit's variant is
the only file it refuses.

## Scope

**In scope**

- `Manifest\ActionsCheck::check(Manifest $manifest): list<ValidationStatus>`
  — the two normative rules, and nothing of the content family:
  1. **claim v2**: the first actions assertion — the first entry labelled
     `c2pa.actions.v2` (or, tolerated as c2pa-rs tolerates it,
     `c2pa.actions`) in `created_assertions`, else in
     `gathered_assertions` — must exist, decode to a map with a
     non-empty `actions` list, and its first action's `action` must be
     `c2pa.created` or `c2pa.opened`. Otherwise one
     `assertion.action.malformed` with the **manifest's url**
     (`self#jumbf=/c2pa/<label>`) and an explanation naming what was
     found ("no actions assertion", "the first action is c2pa.edited",
     "actions is empty").
  2. **every actions assertion, claim v2**: its `actions` must be a
     non-empty list of maps each with a text `action` → else
     `assertion.action.malformed` with the **assertion's url**.
  3. **claim v1**: at most one actions assertion → else
     `assertion.action.malformed` with the manifest's url. Nothing else
     (c2pa-rs's default; `strict_v1_validation` is out of scope).
- Update manifests: none reach this check before M7 (a store with more
  than one manifest is refused; an update manifest is never alone). The
  exemption is written down here and tested when M7 lets one through.
- `Report\StatusCode` gains `assertion.action.malformed` (a failure).
- `Verifier`: the check runs after `hashedUris` (it reads assertions
  the claim vouched for) and before `dataHash`; `actions` joins
  `checksPerformed`. An actions assertion whose hashed URI did not match
  is not read (the file is already refused; reading it would judge
  bytes the signer did not vouch for) — the check reports "not read"
  for it and moves on.
- The absence variant `tests/Fixtures/absence/no-actions.png` (with its
  root as anchor: `signingCredential.trusted` **and** `Invalid`) as the
  file that made the rule; `SPEC013_NOT_YET` loses nothing (the code was
  never on it) and gains nothing.

**Out of scope** (named, so that nobody reads `Valid` as "the actions
were validated")

- The content family of c2pa-rs's `verify_actions` 2.b–2.f: no second
  `created`/`opened`, `c2pa.opened` and `c2pa.placed` needing an
  ingredient parameter, `softwareAgent` indices, icons and templates,
  `digitalSourceType` values (the `c2pa.created` case moved to SPEC-032,
  amendment 4) — M7's, with ingredients, or a spec of their own; until then a manifest that breaks one of them and nothing else is
  `Valid` here and `Invalid` at c2patool, and the drift alarms name any
  corpus file that shows it (none does today).
- `assertion.action.redacted` and `assertion.required.missing` stay on
  `SPEC013_NOT_YET`.
- Reading any other assertion's content.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-018')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

The oracle is c2patool 0.27.22 on `tests/Fixtures/absence/no-actions.png`
(`tests/Fixtures/c2patool/absence/no-actions{,-trusted}.json`) and on the
corpora; the variants of AC3–AC4 are made in the tests-first step with
`bin/make-absence-variants.php` (extended) and run through c2patool.

- **AC1 — the audit's file: `assertion.action.malformed` on the manifest, `Invalid`, even when trusted** *(the file that made the rule)*
  - Given `absence/no-actions.png`, without settings and with
    `absence/throw-away-root.settings.json`
  - When `Verifier::verify()` runs
  - Then both reports are `Invalid` with exactly one
    `assertion.action.malformed`, url `self#jumbf=/c2pa/<label>` — equal to
    c2patool's code and url in the two JSONs — and an explanation
    containing "no actions assertion"; the trusted run still carries
    `signingCredential.trusted`; `checks_performed` is
    `['signature', 'certificate', 'trust', 'hashedUris', 'actions', 'dataHash']`
    (the data hash still runs: the binding is a separate question).

- **AC2 — every corpus verdict is unchanged** *(the rule costs nothing on real writers)*
  - Given the four corpora and the three signed fixtures
  - When the drift alarms run (SPEC-013 AC10–AC13)
  - Then no state changes; the eight v2 manifests (the three fixtures,
    `C_with_CAWG_data`, `no_alg`, `CACA`, OpenAI, Pixel) report no
    `assertion.action.malformed`; the seven v1 manifests without an
    actions assertion or opening otherwise (Truepic ×3, Nikon,
    `cawg_ica`, `c2pa-ts`, `exp-test1`) report none either — and `actions`
    is in `checks_performed` of every readable manifest.

- **AC3 — the first action is not an opening** *(claim v2, wrong first action)*
  - Given `absence/actions-first-edited.png` (the fixture's actions
    assertion with its first `action` rewritten to `c2pa.edited`, the
    hashed URI recomputed, the claim re-signed) and
    `absence/actions-empty.png` (`actions: []`, likewise)
  - When verified
  - Then each is `Invalid` with `assertion.action.malformed` on the
    manifest's url (the first) / the assertion's url (the second), the
    explanations naming `c2pa.edited` and "empty", equal to c2patool's
    codes on the same files (measured in the tests-first step; if
    c2patool puts the empty-list fault on the manifest url instead, the
    criterion follows the oracle and says so in an amendment).

- **AC4 — a gathered actions assertion counts, and two in a v1 claim do not** *(placement and version)*
  - Given `fixture-signed.png` itself (its `c2pa.actions.v2` sits in
    `gathered_assertions`, opening with `c2pa.created`) and
    `absence/v1-two-actions.png` (a v1-style pair: the fixture's claim
    relabelled to v1 would need re-keying — instead, in the tests-first
    step, the variant is judged feasible or the criterion is measured on
    a v1 corpus file with a second actions box spliced in and the claim
    re-signed; either way c2patool is asked)
  - When verified
  - Then the fixture reports no `assertion.action.malformed` (gathered
    is fine) and the v1 pair reports one on the manifest's url, as
    c2patool — or, if no signed v1 variant can be made, AC4's second
    half is measured through `ActionsCheck::check()` on a `Manifest`
    built from the spliced store (the check does not need the
    signature) and the c2patool half is dropped with a note.

- **AC5 — an actions assertion the claim did not vouch for is not read** *(the gate)*
  - Given `binding/hashed-uris-two-changed.png` (the claim's hash for
    the actions assertion changed)
  - When verified
  - Then the report carries `assertion.hashedURI.mismatch` for the
    actions assertion and no `assertion.action.malformed`; the check's
    own status for it (informational text in the `hashedURI.mismatch`
    explanation or none) never claims to have read it.

- **AC6 — malformed content is refused with the offset of the fault, never read past** *(the error path)*
  - Given actions assertions hand-made through `ActionsCheck::checkData()`
    (the seam that takes the decoded assertion value and the claim
    version): `actions` absent; `actions` a map, not a list; an entry
    that is not a map; an entry whose `action` is not text; an entry
    with `action` `""`; `actions` of 10 001 entries (`maxActions`
    10 000)
  - When checked as claim v2
  - Then each is `assertion.action.malformed` with a message naming the
    field (`actions`, `actions[0]`, `action`) — and the same six as claim
    v1 return nothing (rule 3 only).

## References

- Specification: C2PA 2.4 §18.x actions (the `c2pa.actions.v2` assertion;
  `c2pa.created` / `c2pa.opened` as the opening action of a manifest that
  is not an update manifest), §15.2.2 (`assertion.action.malformed`),
  §10.2.1 (`created_assertions` / `gathered_assertions`). Read
  2026-09-22.
- Oracle: c2patool 0.27.22 on `absence/no-actions.png` (step 48: `Invalid`,
  `assertion.action.malformed`, "first action must be created or
  opened", url the manifest) and on the 49 readable corpus manifests
  (no such code); c2pa-rs `claim.rs` 0.90.22 `verify_actions` (the v1
  rule, the v1 skip unless `strict_v1_validation`, the created-then-
  gathered order, the non-empty list rule, the update-manifest
  exemption).
- Reasoned: the split between the two normative rules and the content
  family; tolerating a `c2pa.actions` label in a v2 claim (c2pa-rs's
  `action_assertions()` collects both labels); `maxActions`.
- Divergence: none intended on the two rules. On the content family
  this verifier stays more lenient than c2patool until M7, named above
  and in `SPEC013_NOT_YET`'s comment.

## API sketch

```php
// namespace Provemark\C2paVerifier\Manifest;

final readonly class ActionsCheck
{
    public const LABEL_V2 = 'c2pa.actions.v2';
    public const LABEL_V1 = 'c2pa.actions';
    public const OPENING_ACTIONS = ['c2pa.created', 'c2pa.opened'];
    public const DEFAULT_MAX_ACTIONS = 10000;

    public function __construct(private int $maxActions = self::DEFAULT_MAX_ACTIONS) {}

    /**
     * @param  list<string>  $unreadable  assertion urls whose hashed URI failed — not read
     * @return list<ValidationStatus>
     */
    public function check(Manifest $manifest, array $unreadable = []): array;

    /** The seam for AC4: the ordered actions assertions (created first, then gathered) as check() collects them. @param list<array{url: string, data: mixed}> $actions @return list<ValidationStatus> */
    public function checkAssertions(string $manifestUrl, int $version, array $actions): array;

    /** The seam for AC6: one decoded actions assertion, as claim $version. @return list<string> faults */
    public function checkData(mixed $data, int $version): array;
}

// Report\StatusCode: case AssertionActionMalformed = 'assertion.action.malformed';  (a failure)
// Verifier::check(): … hashedUris → actions ($this->actions->check($manifest, $mismatchedUrls)) → dataHash …
```

## Open questions

- Non-blocker (tests-first step): whether c2patool puts the empty-list
  fault on the assertion's url or the manifest's (AC3), and whether a
  signed v1 two-actions variant can be made without a v1 fixture (AC4).
- Non-blocker: `c2pa.actions` (the v1 label) inside a v2 claim —
  tolerated here as c2pa-rs tolerates it; if a corpus file ever shows
  c2patool refusing it, an amendment follows.
- Non-blocker: whether `ActionsCheck` belongs under `Manifest` (it reads
  a manifest's assertions, needs `Report`) or a new `Assertions` layer
  for the content rules to come. `Manifest` now; Deptrac gets
  `Manifest → Report` if it does not have it.

## Amendments

1. **2026-09-22, step 49a, measured before the tests** — (a) c2patool does not put the empty-list fault on any url: `actions-empty.png` makes it exit 1 with "validation rule was violated: No Action array in Actions" and no report (`tests/Fixtures/c2patool/absence/actions-empty.stderr.txt`); AC3's second half keeps the assertion's url for this verifier's `assertion.action.malformed`, as the spec proposed, with the oracle's refusal as the equality. (b) No signed v1 fixture can be made from the v2 PNG fixture without rewriting its claim, so AC4's v1 half runs through a seam: `ActionsCheck::checkAssertions(string $manifestUrl, int $version, list<array{url: string, data: mixed}> $actions)` — the ordered actions assertions (created list first, then gathered) as `check()` collects them from a `Manifest`; `check()` is that collection plus this call. (c) `actions-first-edited.png` measured: c2patool `Invalid`, `assertion.action.malformed` on the manifest's url, "first action must be created or opened" — AC3's first half as written.
2. **2026-09-22, step 49b, at implementation** *(confirmed by Maurice van Loon, 2026-09-22)* — c2patool's url for the manifest-level faults of this rule is the **bare manifest label** (`urn:c2pa:488bf983-…`), not the JUMBF form its hard-binding faults carry (`self#jumbf=/c2pa/urn:c2pa:…`, SPEC-012) — measured in `no-actions.json` and `actions-first-edited.json`. This verifier prints the same for `assertion.action.malformed` on the manifest, so that code and url compare equal in the drift alarms; the assertion-level faults keep the assertion's JUMBF url. AC1, AC3 and AC4 read so. `checkAssertions()`'s first parameter is therefore the label. Also at implementation: two `toContain($needle, $message)` slips in the tests (Pest's variadic trap, the fifth and sixth time in this project) rewritten; the older tests that list `checks_performed` gained `actions` between `hashedUris` and `dataHash` (SPEC-013/014/017), and the enum count is 31 (SPEC-015 AC10).

3. **2026-09-22, step 57b, defined in SPEC-022 and approved with it** *(confirmed by Maurice van Loon, 2026-09-22)* —
   the opening rule does not apply to an update manifest. This spec's
   Problem section already said so ("an update manifest is exempt"), but
   the code could not tell: `c2um` boxes were refused by the JUMBF parser
   until SPEC-022. `ActionsCheck::checkAssertions()` now takes
   `$isUpdateManifest` and returns after the well-formedness rule for
   one. Measured: without it, SPEC-022's `action-not-allowed` variant
   reports `assertion.action.malformed` where c2patool reports only
   `manifest.update.invalid`.

4. **2026-09-24, step 122b, with SPEC-032** — the `digitalSourceType`
   item leaves this spec's out-of-scope list. SPEC-032 rule A refuses a
   `c2pa.created` without one in a v2 claim, as `c2patool` 0.27.22 and
   0.28.0 do. AC2's corpus list keeps every verdict, and it now expects
   exactly that fault on `c2pa-rs/no_alg.jpg`. That is a v2 claim whose
   `c2pa.created` has no `digitalSourceType`, and whose verdict was and
   stays `Invalid` for its unknown algorithm. `c2patool` refuses it for
   the algorithm before it reads the actions. The rest of the content
   family stays out of scope.

   Weight B: one more status on one file whose verdict does not move.

   Confirmed by Maurice van Loon, 2026-09-24 (step 122).

5. **2026-09-24, step 125b, with SPEC-033** — the out-of-scope content
   family shrinks again. SPEC-033 takes one opening, the ingredient
   references of opened, placed, removed, transcoded and repackaged,
   `c2pa.translated`'s languages, `relatedAssertions` and the watermark's
   soft binding. Once this spec's opening rule has refused a manifest,
   no content rule runs (SPEC-033 amendment 1, as `c2pa-rs` returns
   there). Still out of scope: icons in `softwareAgents` and `templates`,
   and `c2pa.redacted`. Weight B: new faults, on files the oracles
   already refuse.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Manifest/ActionsCheckTest.php :: SPEC-018 AC1: the audit's file — assertion.action.malformed on the manifest, Invalid, even when trusted / SPEC-018 | src/Manifest/ActionsCheck.php :: check(), checkAssertions() (rule 1); src/Verifier/Verifier.php :: check() (`actions` between `hashedUris` and `dataHash`); src/Report/StatusCode.php :: AssertionActionMalformed |
| AC2 | tests/Unit/Manifest/ActionsCheckTest.php :: SPEC-018 AC2: every corpus verdict is unchanged, and actions is in checks_performed of every readable manifest / SPEC-018 (and SPEC-013 AC10–AC13 unchanged) | src/Manifest/ActionsCheck.php :: checkAssertions() (the v1 branch), isActionsLabel() |
| AC3 | tests/Unit/Manifest/ActionsCheckTest.php :: SPEC-018 AC3: a first action that is not an opening, and an empty actions list / SPEC-018 | src/Manifest/ActionsCheck.php :: checkAssertions() (rules 1–2), firstAction(); tests/Fixtures/absence/actions-first-edited.png, actions-empty.png (bin/make-absence-variants.php) |
| AC4 | tests/Unit/Manifest/ActionsCheckTest.php :: SPEC-018 AC4: a gathered actions assertion counts; two in a v1 claim do not / SPEC-018 | src/Manifest/ActionsCheck.php :: check() (created then gathered), checkAssertions() (rule 3) |
| AC5 | tests/Unit/Manifest/ActionsCheckTest.php :: SPEC-018 AC5: an actions assertion the claim did not vouch for is not read / SPEC-018 | src/Verifier/Verifier.php :: check() ($unreadable); src/Manifest/ActionsCheck.php :: check() |
| AC6 | tests/Unit/Manifest/ActionsCheckTest.php :: SPEC-018 AC6: malformed content is refused naming the field; as claim v1 the same six pass / SPEC-018 | src/Manifest/ActionsCheck.php :: checkData(), DEFAULT_MAX_ACTIONS |
