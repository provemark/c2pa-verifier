# An OCSP `good` without `nextUpdate` (step 504)

Made by `bin/make-ocsp-window-variants.php`, as `../ocsp/` was made by
`bin/make-ocsp-variants.php`: a throw-away certificate authority, one
end-entity certificate under it, and `openssl ocsp` answering from an
index. The keys were deleted when the script ended.

| file | what it is |
|---|---|
| `ca.crt` | the issuer, and the responder |
| `signer.crt` | the subject of both responses |
| `good-no-next.der` | `good`, `thisUpdate` = `producedAt`, no `nextUpdate` (no `-ndays`) |
| `revoked.der` | `revoked` (keyCompromise) a day before `producedAt`, `nextUpdate` ten years on |

SPEC-030 AC12 judges the `good` at `producedAt` + 23 hours (current) and
+ 25 hours (not current: C2PA 2.4 §15.9.1), with and without a revocation
source that answers `revoked.der`. The times are read from the response,
so the test does not depend on the day the files were made.
