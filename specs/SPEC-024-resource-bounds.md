# SPEC-024: Refusing what cannot be held — bounds that fit the host

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

This package exists for hosts that can run no second process, no extension
and no binary. Those hosts give PHP about **128 MB** and thirty seconds.
Step 66 measured what the verifier costs on them, and found one thing that
breaks the project's first rule.

The asset streams: a 256 MB file costs 6.0 MB of peak memory and 667 ms,
and reassembling 960 APP11 pieces is linear in time. The **manifest store**
does not stream and cannot — it is parsed, so it is held. What is wrong is
how much the verifier is willing to hold. The bound is 64 MiB in all three
containers (`DEFAULT_MAX_LBOX` in SPEC-001, `DEFAULT_MAX_CHUNK_LENGTH` in
SPEC-002 and SPEC-003). Measured:

| store | peak | outcome under `memory_limit=128M` |
|---|---|---|
| 8 MiB | 22.0 MB | `general.error` |
| 32 MiB | 70.0 MB | `general.error` |
| **63 MiB** | **132.0 MB** | **PHP fatal error** |
| 64 MiB | 6.0 MB | refused by the bound, cheaply |

A 63 MiB store is *inside* the verifier's own limits, and on a 128 MB host
it does not return `Invalid`:

```
PHP Fatal error:  Allowed memory size of 134217728 bytes exhausted
(tried to allocate 66060344 bytes) in src/Container/PngManifestStoreExtractor.php:121
```

**A fatal error is not failing closed.** It cannot be caught, so the caller
gets no report, no `validation_state` and no status code — on a web host, a
blank 500. And it is avoidable: the length is declared in the container's
own header, before a byte of the store is read. The verifier refuses a
64 MiB store in 2 ms and 6 MB; it should refuse anything it cannot hold
just as cheaply.

The bound of 64 MiB was chosen as a sane ceiling for a box length field,
not as a memory budget, and nothing measured it against the hosts this
package targets until step 66. Measured over 212 stores in this
repository's fixtures, from nine writers: the median is **45 kB**, the 90th
percentile 241 kB, and the largest ever met **3.36 MB**. The bound is
nineteen times anything real.

Two rules of this project are at stake, both in the README and in
`SECURITY.md`: *fail closed — a verifier that wrongly says `Valid` is worse
than one that errors*, and *bounded input — every parser gets hard limits*.
A limit that is enforced by the operating system killing the process
satisfies neither.

## Scope

**In scope**

- The default bound on the manifest store, in all three containers.
- A refusal, before the store is read, when its declared length cannot fit
  in the memory this process may still allocate — with a status code, a
  verdict, and no allocation of that size attempted.
- The behaviour when PHP has no memory limit (`memory_limit = -1`) or the
  limit cannot be read.
- A measured ceiling for the whole fixture corpus under a 128 MB limit, as
  a regression alarm.

**Out of scope** (each needs its own spec, or is not a rule)

- The double copy in `PngManifestStoreExtractor` (the chunk is concatenated
  once to prepend LBox and again to feed `crc32`). It changes no rule and no
  verdict: the same bytes, read once. It belongs in its own step, and this
  spec's numbers assume it has *not* happened.
- Time limits and any wall-clock budget. Step 66 measured reassembly as
  linear and the corpus as milliseconds; there is no measured problem.
- The asset hash, which streams and costs 6.0 MB for 256 MB.
- ISOBMFF (M8) and formats this verifier does not read.
- Any change to what a *valid* store means. This spec refuses files it
  cannot hold; it does not re-judge files it can.

## Behavior

- **AC1 — the default bound is 16 MiB, and a larger store is refused cheaply**
  - Given a JPEG, PNG or WebP whose declared store length is above the
    default bound
  - When it is verified with a generous memory limit
  - Then the report is `Invalid` with a status code, the explanation names
    the declared length and the bound, and the peak memory stays within
    2 MB of the baseline for a file with no manifest — the store was never
    read. A store just below the bound is read as before.

- **AC2 — a store that cannot fit the host is refused, not attempted** *(required: the error path)*
  - Given a store whose declared length is below the absolute bound but
    above what this process may still allocate
  - When it is verified
  - Then the report is `Invalid` with a status code whose explanation says
    the store does not fit, and **no fatal error occurs**: the call
    returns. Measured with `memory_limit` set low enough that the 63 MiB
    case of step 66 is refused instead of ending the process.

- **AC3 — no limit means only the absolute bound**
  - Given `memory_limit = -1`, or a limit PHP reports in a form this code
    does not understand
  - When a store below the absolute bound is verified
  - Then it is read, and the only refusal that can apply is AC1's. An
    unreadable limit is never treated as a small one: this rule may not
    turn into a refusal of valid files on a host whose configuration we
    failed to parse.

- **AC4 — the corpus fits a 128 MB host**
  - Given every signed fixture in `tests/Fixtures/`
  - When each is verified in a process with `memory_limit=128M`
  - Then every one returns the same `validation_state` and the same failure
    codes as with a generous limit, and none ends the process. This is the
    regression alarm: it fails the day a change makes an ordinary file
    expensive.

- **AC5 — the refusal is in the report, not in an exception**
  - Given any of the refusals above
  - When the caller uses the public API
  - Then it receives a `VerificationReport` whose `validation_state` is
    `Invalid` and whose `validation_status` carries the code; nothing is
    thrown past the public boundary, as SPEC-013 already requires of every
    other container fault.

## References

- Specification: C2PA 2.4 §15 for the status-code vocabulary. The
  specification says nothing about memory; this is a deployment property of
  this implementation, and the vocabulary is borrowed rather than invented
  (see Open questions 1).
- Measured, step 66 (`notes/step-66-resource-audit.md`), PHP 8.5, peak =
  `memory_get_peak_usage(true)`: the table in the Problem section; a 256 MB
  asset at 6.0 MB / 667 ms; APP11 reassembly linear at 4/10/18 ms for
  128/512/960 pieces; 212 corpus stores with median 45 kB, p90 241 kB, max
  3.36 MB; the JPEG path at about 1.1× the store against PNG's 2.1×.
- Existing bounds this spec changes: SPEC-001 `DEFAULT_MAX_LBOX`, SPEC-002
  and SPEC-003 `DEFAULT_MAX_CHUNK_LENGTH`, all 64 MiB.
- Reasoned: that a fatal error cannot be caught and therefore cannot be
  reported, so the promise in `SECURITY.md` is not kept for these inputs.

## API sketch

Illustrative only. The bound belongs where the other bounds already are —
the extractors' constructors — and the host-relative part is one small
collaborator they consult before reading, so that a caller who wants
different behaviour can supply a different one.

```php
// namespace Provemark\C2paVerifier\Support;

final readonly class MemoryBudget
{
    public const DEFAULT_SHARE = 0.25;   // of what is still allocatable

    /** Null when PHP reports no limit, or one this code does not understand. */
    public function remainingBytes(): ?int;

    /** Whether a buffer of $bytes may be allocated without risking the limit. */
    public function allows(int $bytes): bool;
}

// and, in each extractor, before the read:
//   if (! $this->budget->allows($declaredLength)) { throw new ContainerException(...); }
// which SPEC-013 already turns into a status in the report.
```

## Open questions

Both blockers were decided on 2026-09-22, the day the draft was written;
the answers are recorded in place below rather than removed, so that the
reasoning that led to them stays readable.

1. **Which status code.** The §15 vocabulary has no entry for "too large
   for this host", and this project does not invent vocabulary. The
   proposal is `general.error`, which is what every other container fault
   already uses, with an explanation that names the size and the bound.
   Non-blocker, but it is the caller-visible half of this spec.
2. **The share of remaining memory.** `DEFAULT_SHARE = 0.25` is proposed
   because the PNG path costs about 2.1× the store today and about 1.1×
   once the double copy is gone; a quarter leaves room for the box tree,
   the claim and the certificates on top. It is a judgement, not a
   measurement, and the tests-first step should measure it.
3. **16 MiB, or lower.** 16 MiB is 4.8× the largest store this project has
   ever met and 350× the median. 8 MiB would be 2.4× the largest. Neither
   is measured against the wider world — only against 212 files.
   **Blocker: it decides what AC1 asserts.**
4. **Whether reading `ini_get('memory_limit')` is acceptable at all.** It
   makes behaviour depend on the host's configuration, which no other rule
   in this verifier does: the same file could be `Invalid` on one host and
   `Trusted` on another. The alternative is the absolute bound alone, and a
   fatal error on hosts smaller than it. **Decided by Maurice van Loon,
   2026-09-22: yes, read it.** A verifier that fails closed owes the caller
   an honest "this does not fit here" rather than a blank 500, and reading
   the limit makes the difference between hosts visible *in the report*
   instead of invisible in a crash. The cost is accepted and must be put
   where a user will meet it: the same file can be refused on a small host
   and read on a large one, so AC2's explanation has to say that the file
   was not judged at all — nobody may mistake a refusal for a verdict
   about the content.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

All tests are in `tests/Unit/Container/ResourceBoundsTest.php`, group
`SPEC-024`, and run through `tests/Support/verify-probe.php` — one
verification per process, so that the memory limit can be chosen.

| Acceptance criterion | Test (name) | Source (file/symbol) |
|---|---|---|
| AC1 | `AC1: the default bound is 16 MiB in all three containers`; `AC1: a store above the bound is refused without being read` | `JpegManifestStoreExtractor::DEFAULT_MAX_LBOX`, `PngManifestStoreExtractor::DEFAULT_MAX_CHUNK_LENGTH`, `WebpManifestStoreExtractor::DEFAULT_MAX_CHUNK_LENGTH` (SPEC-001 amendment 4, SPEC-002 amendment 2, SPEC-003 amendment 2) |
| AC2 | `AC2: a store that does not fit the host is refused, and the process survives` | `src/Support/MemoryBudget.php :: allows(), remainingBytes()`; the three extractors' budget check |
| AC3 | `AC3: with no memory limit only the absolute bound applies` | `MemoryBudget::parseLimit()` (null for `-1`, empty, or a form it does not understand), `MemoryBudget::allows()` returning true on null |
| AC4 | `AC4: every signed fixture verifies the same under a 128 MB limit as under a generous one` | the whole read path; the alarm, not a change |
| AC5 | `AC5: every refusal arrives in the report, never as an exception past the public API` | `ContainerException` → `src/Verifier/Verifier.php :: check()` (SPEC-013) |
