# Step 300 — A PHP notice while verifying is a fault

*2026-10-09. Tooling only; no change to `src/`.*

In step 299 the verifier raised a PHP deprecation while reading a mutated
file ("Invalid characters passed for attempted conversion",
`src/Support/Bytes.php:57`). The fuzzer printed it on stderr and still
reported 0 faults with exit code 0. A notice like that is a fault in the
verifier: PHP may turn it into an error in a later version, and here it
signalled a wrong value.

`bin/fuzz.php` now installs an error handler that turns every warning,
notice and deprecation into an `ErrorException`. The existing catch counts
it as a fault, writes the mutated file out, and names the message, the
file and the line.

## Measured

- **Seed 20261005 × 60, no settings:** 15,036 runs and 1 fault, exit 1
  (step 299: 0 faults, exit 0, and the notice on stderr). The fault is
  `mdat-byte-changed.mp4#20261005-25-flip8`, `ErrorException` from
  `Bytes.php:57`. stderr is empty. The 238 suspects are the same by name.
- **With `--trust`, seeds 20261005 × 60 and 20261009 × 200:** 0 faults,
  0 raised. The suspects are the same by name as in step 299.
- `composer check`: 945 passed. PHPStan in Docker `php:8.3-cli`: no
  errors.

Until the serial number is fixed (step 302), a run of that seed without
settings ends with exit 1. That is the intended signal.
