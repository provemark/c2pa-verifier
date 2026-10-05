# MP3 variants (step 225)

Built by `bin/make-mp3-variants.php` from `../fixture-signed.mp3` and
`../fixture-unsigned.mp3`. The store sits in an ID3v2.4 tag at offset 0,
as the encapsulated object of a GEOB frame (C2PA 2.4 §A.3.4). Each
variant changes one thing about the tag or the frame and rebuilds the
sizes around it, unless the variant is about a size. Measured with
`c2patool` 0.27.22 and 0.28.1 on 2026-10-05, without trust settings. There
is no MP3 spec yet; this verifier reads none of them (`unknown`).

"read" means: the store is extracted, `claimSignature.validated`, then
`assertion.dataHash.mismatch` (the change touched hashed bytes); 0.28.1
sometimes lists the mismatch twice.

| file | what changed | `c2patool` 0.27.22 | 0.28.1 |
|---|---|---|---|
| `two-geob.mp3` | the C2PA GEOB frame twice | read | read |
| `mime-octet-stream.mp3` | MIME `application/octet-stream` | *No claim found* | the same |
| `mime-upper-case.mp3` | MIME `APPLICATION/C2PA` | *No claim found* | the same |
| `other-geob-first.mp3` | a `text/plain` GEOB before the C2PA one | read | read |
| `encoding-latin1.mp3` | text encoding 0 (one-byte terminators) | read | read |
| `encoding-utf16.mp3` | text encoding 1 (UTF-16 with BOM, two-byte terminators) | read | read |
| `encoding-utf16be.mp3` | text encoding 2 (UTF-16BE) | read | read |
| `version-2-3.mp3` | the tag as ID3v2.3 (plain frame sizes) | read | read |
| `flag-unsynchronisation.mp3` | the header's unsynchronisation flag, bytes unchanged | read | read |
| `flag-extended-header.mp3` | a minimal extended header | read | read |
| `flag-footer.mp3` | the footer flag and a `3DI` footer | read | read |
| `padding-after.mp3` | 64 zero bytes of padding inside the tag | read | read |
| `frame-flags-compressed.mp3` | the GEOB's compression and data-length flags | *No claim found* | the same |
| `lbox-differs.mp3` | LBox +1 | **`Valid`** | **`Valid`** |
| `object-too-short.mp3` | a 4-byte object | *unexpected end of file* | the same |
| `object-empty.mp3` | an empty object | *No claim found* | the same |
| `frame-overruns-tag.mp3` | the GEOB size +1,000, past the tag | read | read |
| `tag-size-plus-one.mp3` | the tag size +1 | *No claim found* | the same |
| `tag-size-not-syncsafe.mp3` | a tag size byte with its top bit set | *No claim found* | the same |
| `truncated-in-store.mp3` | the file cut 1,000 bytes into the store | *invalid CBOR box* | the same |
| `id3v1-appended.mp3` | a 128-byte ID3v1 tag appended | read | read |
| `store-in-appended-tag.mp3` | the C2PA GEOB in a second tag at the end | *No claim found* | the same |
| `junk-before-tag.mp3` | 16 zero bytes before the tag | *No claim found* | the same |
| `unsigned-no-tag.mp3` | the unsigned file without its tag | *No claim found* | the same |
| `unsigned-id3v1.mp3` | the unsigned file + an ID3v1 tag | *No claim found* | the same |

SHA-256 (as printed by the script):

```
5e8eef66e84d307a0020d082dbfb43bd7aec1a4436cd0ee7ec982ef53b8867ba  two-geob.mp3
ba52df1a5d3ad4cfab219ed84098e2b7fbc1a9e84282cbd5e717691ce5b3b852  mime-octet-stream.mp3
5fedbc9eb1094634a02a014d64694052df5616fcb0f5371a750b2944f21e1eb6  mime-upper-case.mp3
78441042a3284a8ceec877c21bd21262f043542fa9d93a15f80b905bf33e4650  other-geob-first.mp3
9724aaa21e15e9e889576714db29fa3cfe52d3766ae14ec6862ac995ebac0d5c  encoding-latin1.mp3
0096308aac80aa6a0e112bc4a095f0adf583e6dca0714ab017f07e867dad7106  encoding-utf16.mp3
b6d60026c08a2258e5f23dadd72234e5b4609f62cf317e240c989cec8e434107  encoding-utf16be.mp3
05467dba9d716d98f916ad4b8a3ba9d315a919b11d9454ef446b5ecef6825d73  version-2-3.mp3
4931ea0cf2db6a5d6962eabe15ed6a45d5e8f5684b156645afbf91376e225473  flag-unsynchronisation.mp3
18613565bd6009ef17ab8881453477a3dcbccfd341907cc8f534a74bae5fa14d  flag-extended-header.mp3
bfa89b917d5361b0766e4f3670f779ca8218906428883b3be2cb2d4463d68a56  flag-footer.mp3
18d644bbc0746e4d6929de830f36a8b48693fdec4153370b94862713d6d32bb2  padding-after.mp3
9b3fa1ec2a41b8d7e540d3307e37bb9001c797bb32d4e447a5c9cdaf744436e7  frame-flags-compressed.mp3
7e2ad84806d8b487a5111df52a0726ca0304e303df4243f7fa4100a625a198c4  lbox-differs.mp3
e148a6f7ab7e743053ce9f079829d25052fc6d0df76168e5875d3a73d0c459e4  object-too-short.mp3
9be2d89c83aaccba3301628c9d47f2276173e4c5d19e5065f239f8857826c1de  object-empty.mp3
2320814bc1d0e91ae4bf07163b1387110ead1c5382c803dd9b2c82b84655e42c  frame-overruns-tag.mp3
eefdf4f1d00d84fce56a3ee365ad4509876bb2e2d3145fe74a8aaa6aef93a7ad  tag-size-plus-one.mp3
3bad84d56c26926b61e1fd4648e45d31422d467e401fdbcf460446db44cf8cf8  tag-size-not-syncsafe.mp3
4ebbd9b1645e28cc35648023331b088d1d0486a449f82a5549a53a9bc80c1908  truncated-in-store.mp3
8a0abf9539fcfd020ac872e050ac660756421c2daa91d9d561d8219642ef8b05  id3v1-appended.mp3
894cf94462eb9904553a306a025b963168529fe1a8787b2266245ab8a0d35147  store-in-appended-tag.mp3
7a0fcce093f24be2f2c478efc7762f1112a1024899ebdf23fb95d49973dbaf58  junk-before-tag.mp3
8f873b7bfe0b51615bafff17cf46b34fb9531c7bb5e9c50ba5860d40307f45c9  unsigned-no-tag.mp3
508ae6da4216b56ef19867837f808d787fad8cbb55bd00bfef4b9c307b4fc963  unsigned-id3v1.mp3
```
