# Certificate serials on both sides of the integer bound (step 154)

SPEC-015 amendment 6 and SPEC-016 amendment 5: an INTEGER read as a
decimal number has at most 256 octets of magnitude. `bin/make-integer-bound-variants.php
<c2patool> <scratch>` builds these files: a throw-away P-256 hierarchy
(keys in the scratch directory, deleted at the end of the run), whose
leaves `c2patool` 0.28.0 uses to sign `../fixture-unsigned.jpg`.
`root.pem` is the public root; `root.settings.json` is
`../trust/full.settings.json` with that root as the trust anchor.

| file | leaf serial | c2patool 0.27.22 and 0.28.0 | this verifier |
|---|---|---|---|
| `serial-200.jpg` | 200 octets | `Trusted` | `Trusted`, the same 482-digit `cert_serial_number` |
| `serial-256.jpg` | 256 octets | `Trusted` | `Trusted`, the same 617 digits |
| `serial-257.jpg` | 257 octets | `Trusted` | `Invalid`, `signingCredential.invalid` naming the bound |

RFC 5280 §4.1.2.2 allows 20 octets. No file this project has measured
carries more. The JSON of both versions is in `../c2patool/integer-bound/`.
