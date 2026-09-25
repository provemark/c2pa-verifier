# Certificate-validity variants for SPEC-044

Made by `bin/make-spec044-variants.php` on 2026-09-25 with a **throw-away
hierarchy**: a P-256 root generated for the run and, per variant, a leaf
with the SPEC-015 `good` profile whose validity was rewritten by hand. The
tbsCertificate's validity SEQUENCE was replaced with the variant's bytes and
signed again with the root key. The PNG fixture's manifest was then
re-signed with each leaf as `bin/make-profile-variants.php` does it. The
keys existed in a directory outside the repository for the run and were
deleted by the script; **no private key is here**
(`grep -rl PRIVATE tests/Fixtures/validity` is empty). Decided by Maurice
van Loon on 2026-09-21: tooling may sign with throw-away keys; the product
never signs.

Re-running the script makes a *new* hierarchy and new bytes; the files
here are the ones measured. Every leaf has notBefore `240101000000Z`
(UTCTime) unless the table says otherwise.

| variant | notBefore / notAfter as written | c2patool 0.28.0 | c2patool 0.27.22 | SHA-256 of `<variant>.bin` |
|---|---|---|---|---|
| `resigned` | notAfter `20500101000000Z` — the control: the rewriting is right | `Trusted` | `Trusted` | `1f426a4394d15f1df7a96e73e193d9c6b340f199316e67c956bdc9fccc46b2cb` |
| `not-after-9999` | notAfter `99991231235959Z` | `Trusted` | `Trusted` | `da389752379a0708fa6acda6f8ed0ad47ed16e02c5389c7c3b9a9652184c079f` |
| `no-seconds` | notBefore `2401010000Z`, a UTCTime without seconds | `Valid`, `untrusted` | `Valid`, `untrusted` | `cf28a52c5baf816312244a1bcf47d3baaf8cbbd91bc8e05e364243baebd67e79` |
| `fraction` | notAfter `20500101000000.5Z` | `Valid`, `untrusted` | `Trusted` | `6a084d0c39e53512fd48fe853abcdf43d48a251cfb11ec55156fcc4e1de349b8` |
| `expired` | notAfter `250101000000Z` | `Invalid`, `expired` | `Invalid`, `expired` | `7803941e04448e2a48f3bc1ccba0e410a0c606259fb93ca20db6ff7e3b0a0ea7` |
| `expired-fraction` | notAfter `20250101000000.5Z` | `Invalid`, `expired` | `Invalid`, `expired` | `9091e41c160c51bb91ddf5ab6780e5c9d42ca11e6b50448c33463ea905470bb0` |

c2patool's answers are under `../c2patool/validity/`, one file per variant
and version, with `throw-away-root.settings.json` as settings.
