# Step 528 — The 0.10.1 entry corrected: five files, not one

*2026-11-11.* Maurice: "zet de verifier-changelog recht".

Bundling 0.10.1 in the WordPress plugin (its SPEC-006 amendment 26)
measured **five** files moving `Valid` → `Invalid`, where step 526 and the
0.10.1 CHANGELOG entry said one. The cause is step 526's comparison, not
the code: it fixed the validation time at 2026-10-27, as step 500 did, and
the `icon-forms/` probes of step 522 are signed with certificates issued
on 2026-11-10. At 2026-10-27 all eight probes — the controls too — were
`Invalid` under both versions, so the four bad-icon probes could not move.

## Measured

The same comparison as step 526 (a `v0.10.0` worktree with a copied
`vendor/`, 953 files, three trust configurations, each without and with
a revocation source), at validation time **2026-11-11T12:00:00Z**: 5,718
runs, 0 exceptions, 30 move, all `Valid` → `Invalid`, in five files:
`icons/agents-icon.png`, `icon-forms/action-agent-icon-resource.png`,
`generator-icon-resource.png`, `generator-icon-text.png`,
`templates-icon-resource.png`.

## Changed

- `CHANGELOG.md`, the 0.10.1 introduction: five files, the validation
  time named, and a sentence saying the entry first said one. The tag
  `v0.10.1` keeps its text; the correction is on `main`.
- `notes/step-526-release-0.10.1.md`: a pointer to this step.

## Lesson

A fixed validation time keeps two runs comparable, but it can hide a
fixture younger than that time. Pick a time after the newest fixture's
certificates, or run twice.
