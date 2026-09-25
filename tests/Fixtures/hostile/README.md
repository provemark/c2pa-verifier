# Hostile input (step 155, SPEC-043)

Two files for the criteria of SPEC-043 that need a real file. Each is one
byte away from a fixture, and nothing is re-signed.
`bin/make-hostile-input-variants.php` writes both.

| file | from | the byte | c2patool 0.27.22 and 0.28.0 (`--settings ../trust/full.settings.json`) |
|---|---|---|---|
| `mediatype-not-utf8.jpg` | `../fixture-signed.jpg` | the first byte of the thumbnail's media type (`bfdb`) set to 0xFF | `Invalid` (`assertion.hashedURI.mismatch`), `"format": ""` |
| `certificate-time-nul.jpg` | `../matrix/es256.jpg` | a digit of the signer's UTCTime notAfter set to NUL; `openssl_x509_parse()` warns "Illegal length in timestamp" | `Error: COSE error parsing certificate`, no report |

The answers are in `../c2patool/hostile/`. Found by the security review of
2026-09-25; the second came out of a fuzzer run and was reduced to the one
byte that matters.
