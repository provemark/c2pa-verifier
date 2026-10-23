# Step 402 — SPEC-079 amendment 3: a known limit, a backdated TSA token

*2026-10-23.* A low item of the review of 2026-10-23, written down rather
than changed.

The TSA list's dates are applied at the token's `genTime`. A TSA removed
because its key leaked can sign a token whose `genTime` lies before the
removal; it is judged trusted at that time, and the signer is then judged
at that time too. Nothing in the token tells a backdated time from a true
one, and C2PA 2.4 §14.4.2 names no other time. A list can refuse such
tokens by an `untrusted` status starting before the first one it wants
refused. Reasoned, not measured. No code or test changed.
