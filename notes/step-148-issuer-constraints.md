# Step 148 — Only a certificate authority may issue (SPEC-014 amendment 4)

*2026-09-25. Found by the security review of the same day. A wrong
`Trusted`, present in 0.1.0, 0.2.0 and 0.2.1.*

## The flaw

`ChainCheck` walked from the leaf to an anchor. It counted a link when the
issuer's name matched and its key verified the signature. It never asked
whether the issuer was allowed to issue certificates: no basicConstraints
`CA:TRUE`, no keyUsage `keyCertSign`, no `pathlen`, no validity for an
intermediate.

Anyone holding an ordinary signing certificate under a configured anchor
could therefore use their own key to issue a leaf on any name, for example
`CN=Adobe Inc`. Signing with that leaf and their own certificate in
x5chain made this verifier say `Trusted` under the forged name. Both
`c2patool` versions say `signingCredential.untrusted`. The timestamp
check walks the same code, so under the legacy `trust.trust_anchors` a
signer could also have made itself a trusted timestamp authority.

The review reported it with a probe. It was reproduced here before
anything else: `Trusted` from this verifier, `Valid` with
`signingCredential.untrusted` from both `c2patool` versions.

## 148a — the probes and the tests seen red

`bin/make-issuer-variants.php` builds a throwaway PKI and five probes.
Both `c2patool` versions judge each one; see
`tests/Fixtures/trust/issuer/README.md`. The first run used
`trust.anchors` settings, which 0.27.22 does not read. The control then
came out `Valid` there. The settings were rewritten to the legacy string,
which both versions read.

`tests/Unit/Trust/IssuerConstraintsTest.php`: **6 failed, 1 passed**. The
control (`good-chain`) was green, and every forbidden issuer came out
`Trusted`, for example *"the chain reaches the trust anchor SPEC-014
Issuer Probe Root at depth 2 (SPEC-014 Forged Signer → SPEC-014 Honest
Signer → SPEC-014 Issuer Probe Root)"*.

## 148b — built

`ChainCheck::issuerFault()` checks every certificate that issues another
in the walk, x5chain intermediates and anchors alike:

- `CA:TRUE`;
- `keyCertSign` when keyUsage is present;
- `pathlen` against the intermediates below it;
- for an intermediate, validity at the time the leaf is judged. That is a
  trusted timestamp's `genTime`, else now, and `genTime` for a timestamp
  authority's chain.

`Certificate` reads `pathlen`. A broken rule is
`signingCredential.untrusted`, as both `c2patool` versions report it.

Measured:

- `vendor/bin/pest --group=SPEC-014`: 17 passed.
- `composer check`: exit 0, 510 passed.
- **Every signed fixture (375) under no settings and under every trust
  settings file (32 readable), 12,375 runs, before and after: 5 moved.**
  They are exactly the five probe cases, all from `Trusted` to `Valid`.
- The 78 current-writer files of step 141 under the step 147 recipe: no
  verdict changed. Real certificate authorities meet the rule.

Not in the rule: the validity of an anchor itself (an open question in
SPEC-014).

## Disclosure

The fix is local until the other findings of the review are fixed too.
They go out together as a security release. Pushing a probe or a test
before then would publish the flaw.
