# A second opinion: the Go verifier as an oracle

Everything this project measures itself against comes from one
implementation: `c2patool`, which is `c2pa-rs`. Agreeing with it twice is
not independent confirmation — a fault `c2pa-rs` and this verifier share
would be invisible. `richardwooding/c2pa` is a pure-Go C2PA verifier
written independently (full validation: COSE, chain, RFC 3161, bindings,
ingredients), and this directory runs it over the same corpora so that
every disagreement becomes a finding — for them, for us, or for the
specification.

`main.go` is a dozen lines around `c2pa.Validate`: it prints one file's
verdict as JSON (`valid`, `binding`, `active_manifest`, the status codes
with their severity). It is **tooling**: it needs a container runtime and
the network, which the verifier itself never does, and it is not part of
`composer check`.

## Running it

```sh
# any container runtime: Docker Desktop, colima, OrbStack …
docker run --rm -v "$PWD/tools/go-oracle":/w -v "$PWD":/repo -w /w golang:1.26 \
  sh -c 'go build -o oracle . && ./oracle /repo/tests/Fixtures/fixture-signed.png png'
```

Pass a PEM of trust anchors as the third argument to compare like for
like with our own settings (the Go library otherwise uses the official
C2PA conformance trust list, which does not contain test certificates):

```sh
./oracle /repo/tests/Fixtures/fixture-signed.png png /w/anchors.pem
```

`notes/step-61-second-oracle.md` records what the comparison found on
2026-09-22 over 257 files, and `docs/comparison.md` names the three
places where the two implementations answer differently.
