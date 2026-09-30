# Step 196 — Do real C2PA chains use name constraints? Measured: no

*2026-09-30. The trust lists at `c2pa-org/conformance-public` main (the
list files last changed 2026-08-13, commit `70ec46e`); OpenSSL 3.6.3.*

## Why

SPEC-046 evaluates a name constraint only in the `directoryName` and
`rfc822Name` forms. A constraint in any other form (`dNSName`, URI, IP
address, …) makes every certificate below it `signingCredential.untrusted`,
where `c2patool` lets OpenSSL evaluate it (`docs/comparison.md`). That is
fail closed and is right while no real chain carries such a constraint. The
question was whether one does, before anyone writes a spec for the other
forms.

## Measured

1. **The C2PA trust lists.** `trust-list/C2PA-TRUST-LIST.pem` (30
   certificates: roots and issuing CAs of Google, SSL.com, Trufo, vivo,
   Xiaomi, DigiCert, Adobe, Irdeto, Tauth Labs, Huawei, Huanyu Trust,
   Verimago, Snowball, Encypher, TrustAsia, Whole Earth Labs, Castlabs) and
   `trust-list/C2PA-TSA-TRUST-LIST.pem` (22 certificates). Each one went
   through `openssl x509 -noout -text`. **None has a Name Constraints
   extension.** All are CAs, most with `pathlen` 0 to 2.
2. **The chains in real files.** Intermediates are not on the lists; they
   travel in each file's `x5chain`. Every file under `tests/Fixtures/` was
   searched for the DER encoding of the Name Constraints OID
   (`06 03 55 1D 1E`, 2.5.29.30). 631 files were searched, and 508 hold a
   certificate (they contain the basicConstraints OID). **Three hit, and all
   three are this project's own SPEC-046 variants**
   (`chain-constraints/nc-dns.png`, `nc-inside.png`, `nc-outside.png`). No
   real file does: the corpus covers Adobe, Google Pixel, Samsung, OpenAI,
   Amazon, Truepic, Leica, Nikon, Sony, Microsoft and more.

## What it means (reasoned)

No anchor on the C2PA Trust List and no chain seen in the wild uses name
constraints, in any form. SPEC-046's refusal of the other forms affects no
real file today. A spec that evaluates `dNSName`, URI and IP-address
constraints would have no real case to measure against, only built ones.

## Decided

Nothing to build. Look again when the trust list changes. A quick check
is to repeat item 1 on the new `C2PA-TRUST-LIST.pem`.
