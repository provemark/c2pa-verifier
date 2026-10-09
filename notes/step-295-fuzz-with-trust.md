# Step 295 — The fuzzer with trust settings

*2026-10-09. Tooling only; no change to `src/`.*

`bin/fuzz.php` (step 45) mutates every corpus file and asks two things of
each run: no exception escapes, and a mutated file that stays `Valid` is
written out for `c2patool` to judge. It always verified without settings.
Without anchors no run can be `Trusted`, so none of the fuzzer's runs ever
reached the end of the chain walk to an anchor, the TSA's trust, the
allowed list, or a timestamp keeping an expired signer valid. Those are the
rules where most of this project's wrong `Trusted` verdicts were found
(`SECURITY.md`). The corpus measurements reach them, but only unmutated.

## What changed

`php bin/fuzz.php <seed> <rounds> <out> --trust` runs the same mutations
over pairs of a file and a settings file:

- every fixture beside its own `<name>.settings.json` (the trust and
  timestamp matrices, `tsa-profile`, `trust/anchor`, `trust/anchors`,
  `key-usage`, `issuer` and the single-file sets);
- the signed fixtures and the `c2pa-rs` corpus under
  `trust/full-plus-digicert-g4.settings.json`, the file that makes them
  `Trusted`.

Each pair is first verified unmutated. A mutation that raises the state
above that (`Invalid` < `Valid` < `Trusted`) is reported as `RAISED`, the
heaviest kind of finding. Every other `Valid` or `Trusted` remains a
suspect. Each line names the settings file, so the oracle can be asked the
same question. The summary counts the unmutated states.

Without `--trust` nothing changes. Measured with `php bin/fuzz.php 20261005
60 <out>` before and after: 12,903 runs over 249 files, 0 faults, the same
122 suspects by name.

## Measured

| run | pairs (unmutated) | runs | faults | raised | suspects |
|---|---|---|---|---|---|
| `20261005 60 --trust` | 120 (51 `Trusted`, 21 `Valid`, 48 `Invalid`) | 6,885 | 0 | 0 | 53 (51 `Trusted`, 2 `Valid`) |
| `20261009 200 --trust` | the same 120 | 22,875 | 0 | 0 | 163 (161 `Trusted`, 2 `Valid`) |

Every suspect was judged by `c2patool` 0.28.1 with the same settings (the
plain-text one by the text-enabled build of 0.28.1, and here with
`--text`): 216 of 216 the same state. All suspects are files of the
`c2pa-rs` corpus or signed fixtures. They are mutations that touched bytes
no hash covers, as in the runs without settings. No mutation of a matrix
probe stayed `Valid` or `Trusted`.

Reasoned, not measured: random mutations almost always break the data hash
or the claim signature before trust is judged. The bytes where a mutation
can change trust without breaking either are the COSE unprotected header
(the `sigTst2` token), which the claim signature does not cover. Aiming
mutations there is the next step.

## Checked

`composer check`: 943 passed. PHPStan in Docker `php:8.3-cli`: no errors.
