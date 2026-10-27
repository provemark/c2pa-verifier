# Step 472 — ADR-0007 accepted

*2026-10-27.* Docs only. Maurice accepted ADR-0007 as proposed in step
471, with both open questions answered as proposed:

1. **The side effect is not copied.** With `verify.ocsp_fetch` on,
   `c2pa-rs` validates the time-stamp a second time on the fetch path and
   can add failures unrelated to revocation (the TrustNXT writer file:
   `Invalid` with nothing fetched). Here an online lookup changes the
   `signingCredential.ocsp.*` status, and through a revoked CA
   `signingCredential.untrusted`, and nothing else. This is more lenient
   than `c2patool` on the opt-in path, so it is an explicit exception
   beside ADR-0005, not a case of it. The spec that builds the source
   records it in `docs/comparison.md`. With the default source nothing
   differs from `c2patool`'s default.
2. **The source goes on the `Verifier` constructor**, beside the other
   collaborators; `verify()` keeps its signature.

The fixed rule now reads: no network in this package; revocation data
from outside only through a source the caller injects, default off. The
README and SPEC-014's out-of-scope line change in the spec that builds
it. Next: that spec, as a draft, for the signer's certificate; the CAs
through their AIA responders may follow as a second.
