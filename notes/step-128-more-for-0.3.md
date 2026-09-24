# Step 128 — What else could go into 0.3: box hashes, redactions, the time-stamp assertion

*2026-09-24. Measurement only: no specification, test or code changed.
The maintainer asked, before any release, what more could go into 0.3.*

## 1. Box hashes (`c2pa.hash.boxes`): not reachable

`c2pa` 0.91.0's builder has a `prefer_box_hash` setting. `c2patool`
0.28.0 was run with `{"builder": {"prefer_box_hash": true}}` on
`fixture-unsigned.jpg` and `.png`. Both files came out with a
**`c2pa.hash.data`**, as always, and 0.27.22, 0.28.0 and this verifier all
say `Trusted`. The command line does not reach the box-hash path, and no
writer in the corpus uses one. **Dropped as a candidate.**

## 2. Redactions: a real refusal of a valid file

SPEC-021 refused redactions *"until a fixture exists"*. `c2patool` 0.28.0
can now build one. The steps, with step 110's scratchpad CA:

1. A parent was made: `fixture-unsigned.jpg` with an extra assertion
   `com.example.secret`.
2. A child was signed from it with `-p parent.jpg` and
   `"redactions": ["self#jumbf=/c2pa/<parent>/c2pa.assertions/com.example.secret"]`.
   It was built once with a `c2pa.redacted` action naming the assertion,
   and once without any action of ours.

The builder writes both files. Verified with their root as anchor:

| file | 0.27.22 | 0.28.0 | here |
|---|---|---|---|
| child with `c2pa.redacted` | `Trusted` | `Trusted` | **`Invalid`**: `assertion.missing`, *"URI self#jumbf=c2pa.assertions/com.example.secret does not resolve …"* |
| child without it | `Trusted` | `Trusted` | **`Invalid`**: the same |

Reasoned from the message: the parent's claim still lists the assertion
the child redacted, the box is gone as a redaction intends, and this
verifier's manifest reader (`Manifest::checkReferences()`) refuses a
listed assertion it cannot find. It does this before the redaction the
child declares is ever read. **This is a valid file refused.** It fails
closed, but it is a wrong `Invalid` on a shape `c2patool` produces today.

A redaction specification would have to cover several things. All of
them are C2PA 2.4 rules, and none is written yet:
- an ingredient manifest's assertion may be absent when a later
  manifest's `redacted_assertions` names it;
- what may not be redacted (hard bindings, actions);
- the `c2pa.redacted` action's reference (`c2pa-rs` 2.d,
  `assertion.notRedacted`);
- lifting SPEC-021's refusal.

The size is about that of SPEC-033. That is an estimate.

## 3. The `c2pa.time-stamp` assertion (issue #6): not buildable here

`c2pa` 0.91.0 reads it during validation (`store.rs`, `timestamp_assertions()`),
and its builder can add one in update manifests. `c2patool`'s command
line offers no way to write one, so no probe could be made. As issue #6
says, this verifier is **stricter** without it: an old certificate is
judged at *now*. There is no real file with one either. **Left as it is.**

## Summary for the maintainer

| candidate | what the measurement says | fits 0.3? |
|---|---|---|
| CAWG middle step (step 127) | wrong `Invalid` on 2 c2pa-rs test files; no real file in the corpus | possible, one small spec |
| **Redactions** | **wrong `Invalid` on a file `c2patool` 0.28.0 builds today, `Trusted` in both oracles** | **yes, the strongest candidate: one spec** |
| Box hashes | not writable by `c2patool`, not in the corpus | no |
| Time-stamp assertion | not buildable; stricter here, not laxer | no |
