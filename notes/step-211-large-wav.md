# Step 211 — A large WAV: memory and time

*2026-10-05. Apple M4, PHP 8.5.8 CLI, `c2patool` 0.27.22. Nothing in the
repository changed except this note; the files were made, measured and
deleted in a scratch directory.*

## Why

A WAV is large by nature: an hour of CD-quality stereo is about 600 MB,
and RIFF allows up to 4 GB. Shared hosting, the audience this verifier
is for, has a memory limit (often 64–128 MB) and, for web requests, a
time limit (often 30 s). SPEC-055 reads WAV through the walk and the data
hash that stream their input, so the memory should stay flat. That had
not been measured on a large file.

## How

Three unsigned WAVs (16-bit stereo, 44.1 kHz header, a `data` chunk of
zeros) of 100 MB, 500 MB and 2,000 MB, each signed with `c2patool`
0.27.22 and the test certificate (the `C2PA` chunk at the end, as for the
fixture), then verified with `bin/c2pa-verify` under
`/usr/bin/time -l`, once without a memory limit and once with
`memory_limit=64M`. `c2patool` read each file for comparison. Last, one
byte in the middle of the 2,000 MB file (offset 1,048,576,000) was
changed and the file verified again.

## Measured

| file | this verifier, no limit | this verifier, `memory_limit=64M` | `c2patool` 0.27.22 |
|---|---|---|---|
| 100 MB | `Valid`, 0.38 s, 33 MB peak | `Valid`, 0.32 s, 34 MB | `Valid`, 0.05 s |
| 500 MB | `Valid`, 1.36 s, 33 MB | `Valid`, 1.35 s, 34 MB | `Valid`, 0.23 s |
| 2,000 MB | `Valid`, 5.32 s, 33 MB | `Valid`, 5.14 s, 34 MB | `Valid`, 0.79 s |

The middle byte changed in the 2,000 MB file: `Invalid`,
`assertion.dataHash.mismatch` (under the 64 MB limit).

Signing took `c2patool` 0.22 s, 0.83 s and 5.11 s.

## What it means (reasoned)

- **Memory is flat.** The peak is the same 33 MB at 100 MB and at 2 GB,
  and a 64 MB limit is no problem. That is the walk skipping `data` with
  a seek and the data hash reading in 64 KiB chunks (SPEC-012).
- **Time grows linearly**, at about 2.6 s per GB here: the SHA-256 of
  the whole file in PHP, six to seven times slower than `c2patool`. A WAV
  at RIFF's 4 GB limit would take about 10.5 s on this machine.
- **The risk is the time limit on a slow host, not memory.** A shared
  host's CPU is slower than an M4. If it is two to five times slower (not
  measured), a 4 GB WAV takes 20–50 s, and a web request with
  `max_execution_time = 30` would be stopped by PHP mid-hash: a fatal
  error, no report at all. That is the same kind of failure SPEC-024 closed
  for memory (step 66), now for time. It is not specific to WAV: a large
  MP4 has it as well.
- Nothing to build in this step. Whether to bound it (for instance a
  stated throughput in the README, or a time budget the caller can set,
  reported as "not examined" rather than a fatal error) is a question for
  a later spec, if Maurice wants one.

## Next

Step 212: the check before a release — fuzzing over the WAV files, and,
on Maurice's word, a push so that CI runs.
