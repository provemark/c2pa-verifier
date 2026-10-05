# FLAC variants (step 234)

Built by `bin/make-flac-variants.php` from `../fixture-signed.flac` and
`../fixture-unsigned.flac`. `c2patool` puts an ID3v2.4 tag holding the C2PA
GEOB in front of the unchanged FLAC stream (C2PA 2.4 §A.3.4). The tag is
MP3's, measured in steps 225–232 (`../mp3/`); these variants are about the
stream marker `fLaC` and what stands before it. Measured with `c2patool`
0.27.22 and 0.28.1 on 2026-10-05, without trust settings. There is no FLAC
spec yet; this verifier reads none of them (`unknown`).

| file | what it is | `c2patool` 0.27.22 | 0.28.1 |
|---|---|---|---|
| `zeros-after-tag.flac` | 16 zero bytes between the tag and `fLaC` | read, `assertion.dataHash.mismatch` | the same |
| `marker-damaged.flac` | `fLaD` instead of `fLaC` | read, `assertion.dataHash.mismatch` (the marker is not checked) | the same |
| `tag-then-other.flac` | `XXXX` instead of `fLaC` | read, `assertion.dataHash.mismatch` | the same |
| `tag-at-end.flac` | the C2PA tag after the stream instead of before it | *No claim found* | the same |
| `unsigned-with-id3.flac` | an unsigned FLAC with an ID3 tag (`TIT2`) before it; the source of the next | *No claim found* | the same |
| `signed-with-id3.flac` | that file signed by `c2patool` 0.27.22 (not built by the script): one tag, `TIT2` then `GEOB`, then `fLaC` | **`Valid`** | **`Valid`** |
| `unsigned-zeros-after-tag.flac` | an ID3 tag, 16 zero bytes, then the FLAC | *No claim found* | the same |

`c2patool` could not sign `unsigned-zeros-after-tag.flac`: both versions
stop with *Error: embedding manifest*.

SHA-256 (as printed by the script):

```
44863e067b39e81d055a24481b377ec8029c0e4d5133f202819b506932dd3e2e  zeros-after-tag.flac
f2542b9f99aad901155d6bd2793af6d86efa51e7ae46cb4370ec69cf905692ee  marker-damaged.flac
cb954fc512673543b860715143665ac0f982d9c7422b8a3853e468fb76d3a506  tag-then-other.flac
8632a082b9f18441d919794d464d4d4894729da8d59162eec2df355498e2286b  tag-at-end.flac
780b332f80de2ff17ec6f3dabb115b903a17b516759beea6c847a56cce804caf  unsigned-with-id3.flac
cb24559890ef799988d0a92866518ac82cb480b5490329d0ccd8ea0537953c54  unsigned-zeros-after-tag.flac
```
