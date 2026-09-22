# Step 66 — What this verifier costs, and where that breaks a 128 MB host

*2026-09-22.* The fuzzer (70 870 mutated files) proves that no exception
escapes. It says nothing about memory or time. This package exists for
shared hosting — the hosts that can run no second process, no extension and
no binary — and those hosts typically give PHP **128 MB** and thirty
seconds. So the question is not "does it crash" but "what does it cost, and
what happens at the edge".

Everything below was measured on PHP 8.5 with the memory limit stated.
Peak is `memory_get_peak_usage(true)`.

## The baseline is comfortable

| file | size | peak | time | state |
|---|---|---|---|---|
| `fixture-signed.jpg` | 95 kB | 6.0 MB | 5.5 ms | Trusted |
| `fixture-signed.png` | 47 kB | 6.0 MB | 1.3 ms | Trusted |
| `fixture-signed.webp` | 99 kB | 6.0 MB | 1.4 ms | Trusted |
| `writers/google-…-pixel10….jpg` | 5.7 MB | 6.0 MB | 18.3 ms | Invalid |
| `c2pa-rs/exp-test1.png` | 5.7 MB | 13.3 MB | 38.0 ms | Invalid |
| `public-testfiles/truepic-…-landscape.jpg` | 3.9 MB | 15.3 MB | 13.3 ms | Invalid |

## The asset streams, as it should

A 256 MB JPEG — `fixture-signed.jpg` with 256 MB appended, so the data hash
runs over the whole thing:

```
256 MB file -> peak 6.0 MB, 667 ms, Invalid
```

Six megabytes for a quarter-gigabyte file. `c2pa.hash.data` really does
stream; SPEC-012's chunked reader does what it says. Reassembly is linear
in time as well: 128, 512 and 960 APP11 pieces cost 4, 10 and 18 ms.

## The manifest store does not stream, and there is the hole

The store is held whole in memory — it has to be; it is parsed. What is
wrong is the size the verifier is willing to hold. The bound is 64 MiB in
all three containers (`DEFAULT_MAX_LBOX`, `DEFAULT_MAX_CHUNK_LENGTH`).

| store | peak | time | outcome |
|---|---|---|---|
| PNG, 8 MiB | 22.0 MB | 21 ms | `general.error` |
| PNG, 32 MiB | 70.0 MB | 76 ms | `general.error` |
| **PNG, 63 MiB** | **132.0 MB** | 149 ms | **fatal under 128 MB** |
| PNG, 64 MiB | 6.0 MB | 2 ms | refused by the bound |
| JPEG, 60 MiB in 961 pieces | 66.0 MB | 18 ms | `general.error` |

A 63 MiB store is **inside** the verifier's own declared limits, and under
`memory_limit=128M` it does not return `Invalid`. It ends the process:

```
PHP Fatal error:  Allowed memory size of 134217728 bytes exhausted
(tried to allocate 66060344 bytes) in src/Container/PngManifestStoreExtractor.php on line 121
```

That is the finding that matters. **A fatal error is not failing closed.**
It cannot be caught, the caller gets no report and no status code, and on a
web host it is a blank 500 for a file the verifier had every opportunity to
refuse cheaply — the length is declared in the chunk header, before a byte
of it is read.

## Why PNG costs twice what JPEG costs

Line 121 is `$data = $lBoxBytes.$reader->readExactly($chunk['length'] - 4, …)`.
The read allocates the chunk; the concatenation allocates it again. Four
lines later `crc32(self::TYPE_CABX.$data)` allocates a third copy to hash
it. JPEG, which assembles its pieces into one buffer, costs about 1.1× the
store; PNG costs about 2.1× measured, and momentarily more.

Both are avoidable: read the chunk once (LBox is its first four bytes, not
a separate read), and compute the CRC through `hash_init('crc32b')` +
`hash_update()`, which needs no concatenation at all.

## How big is a real manifest store?

Measured over every JPEG, PNG and WebP fixture in this repository — 212
stores from nine writers:

| | |
|---|---|
| median | **45 kB** |
| 90th percentile | 241 kB |
| largest ever seen | **3.36 MB** (`c2pa-rs/exp-test1.png`) |
| above 1 MiB | 2 of 212 |

The bound is **19× the largest store this project has ever met** and about
1 400× the median. It was chosen (SPEC-001) as a sane ceiling for a box
length field, not as a memory budget, and nothing measured it against the
hosts this package targets until now.

## What follows, and what is not decided here

Three things, and two of them change rules, so they are the maintainer's:

1. **Fix the PNG double copy.** No rule changes, no verdict changes: the
   same bytes, read once. Brings PNG in line with JPEG.
2. **Lower the default bound.** A proposal: **16 MiB**, which is still
   4.8× the largest real store and 350× the median, and whose worst case
   fits a 128 MB host with room to spare. A file with a larger store would
   be refused where today it is read — a rule change, touching SPEC-001,
   SPEC-002 and SPEC-003.
3. **Make the bound relative to the host, not absolute.** 16 MiB still
   kills a 64 MB host. The declared length is known before the read, and
   `memory_get_usage()` and the `memory_limit` are both readable, so the
   verifier could refuse what it cannot hold and say so with a status code
   instead of dying. That is what failing closed means here, and it is the
   only one of the three that fixes the class rather than an instance.

Nothing in `src/` was changed in this step.
