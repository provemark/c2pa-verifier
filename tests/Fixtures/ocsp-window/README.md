# OCSP windows and responders (steps 504, 505)

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
| `good-by-expired-responder.der` | `good`, signed by a delegated responder (id-kp-OCSPSigning) valid 2020-01-01 to 2021-01-01, so not at its `producedAt` |
| `good-by-responder.der` | `good`, signed by a delegated responder valid for ten years from the run |

SPEC-030 AC12 judges the `good` at `producedAt` + 23 hours (current) and
+ 25 hours (not current: C2PA 2.4 §15.9.1), with and without a revocation
source that answers `revoked.der`. The times are read from the response,
so the test does not depend on the day the files were made. SPEC-030 AC13
checks the two responder files at `producedAt` + 1 hour (RFC 6960 §3.2
requirement 4). The files were made again in step 505, so all of them
date from that run.
