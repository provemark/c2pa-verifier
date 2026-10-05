# A name constraint over names that are not UTF-8 (SPEC-046 amendment 1)

Built in step 247 by `bin/make-name-encoding-variants.php` on a throw-away
hierarchy whose keys were deleted when the run ended; only public
certificates are here. The PNG fixture's claim is re-signed by each leaf,
as `../chain-constraints/` does.

| file | what it is |
|---|---|
| `root.pem` | the throw-away P-256 root, the one anchor in `root.settings.json` |
| `int-t61.pem` | an intermediate whose critical `nameConstraints` permit only directoryName `O = T61String "Caf\xE9"` |
| `t61-inside.pem`, `t61-inside.png` | a leaf whose `O` is T61String `Caf\xE9`: the permitted bytes |
| `t61-outside.pem`, `t61-outside.png` | a leaf whose `O` is T61String `Other\xFF`: outside |

Neither byte string is UTF-8 (`openssl x509 -subject` shows them as Latin-1).

| file | OpenSSL path validation | c2patool 0.28.1 | c2patool 0.27.22 | before step 248 here |
|---|---|---|---|---|
| `t61-inside.png` | OK | `Trusted` | `Invalid`, `claimSignature.mismatch` | `Trusted` |
| `t61-outside.png` | *permitted subtree violation* | `Valid`, `signingCredential.untrusted` | `Invalid`, `signingCredential.untrusted`, `claimSignature.mismatch` | **`Trusted`** |

The answers are under `../c2patool/name-encoding/`. SHA-256:

```
acd0f1f777bdbd63423e453034e7d29b3936bdb06ea12c6a6e2bb20492fbb754  t61-inside.png
fb5ff480114c046a52eef8194d0e308762241bf51f57077206c50b4bbda210f4  t61-outside.png
```
