# Hostile input, the third round (step 249, SPEC-043 amendment 3)

| file | what it is | this verifier | `c2patool` 0.27.22 and 0.28.1 |
|---|---|---|---|
| `keyusage-not-utf8.jpg` | `../c2pa-rs/no_alg.jpg` with 8 bytes of its manifest store changed by `bin/fuzz.php 20261005 60` (round 29, `store8`, at offsets 1331, 1813, 2887, 3484, 5510, 5731, 6484, 7389); one lands in the signer's KeyUsage extension, which `openssl_x509_parse()` then returns as its raw bytes `03 02 46 c0` | `Invalid`, `signingCredential.invalid` naming the KeyUsage, its bytes that are not UTF-8 replaced by `?`; before step 249, `toJson()` threw | `Error: unknown algorithm` |

SHA-256:

```
644bc2ce06c1459bab43b51492ce5d79037e3740a1dedf94f90c55a6a552d67c  keyusage-not-utf8.jpg
```
