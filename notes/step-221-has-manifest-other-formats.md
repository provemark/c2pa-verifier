# Step 221 — `has_manifest` after a container fault in JPEG, PNG and ISOBMFF

*2026-10-05. `c2patool` 0.27.22 and 0.28.1. A measurement; nothing built.*

## Why

SPEC-013 amendment 16 made `has_manifest` follow `storeReached` for the
RIFF walk only, and named the other extractors as an open point: does an
unsigned JPEG, PNG or MP4 with a container fault also report a manifest
it does not have?

## How

Fifteen variants of the three unsigned fixtures and six of the signed
ones, built in a scratch directory, each verified here and read with both
`c2patool` versions (without settings).

## Measured

**Unsigned files, harmless quirks: equal everywhere** (no manifest here,
*No claim found* in both versions): JPEG with 128 bytes after EOI, cut in
half, with an APP11 segment that is not JUMBF, with a 4-byte APP11; PNG
with 128 bytes after IEND, an unknown ancillary chunk, another chunk's CRC
wrong; MP4 with a `free` box appended, with a 3-byte tail.

**Unsigned files, real container faults:**

| file | here | `c2patool` (both) |
|---|---|---|
| JPEG, an APP1 length running past the end | `has_manifest: true`, `Invalid`, `general.error` | *Could not parse input JPEG* |
| PNG cut in half; a chunk length running past the end | `has_manifest: true`, `Invalid`, `general.error` | *PNG out of range* |
| MP4 with 128 bytes `AA` appended (read as a box of 2.8 GB); a box length running past the end | `has_manifest: true`, `Invalid`, `general.error` | *Box size extends beyond asset* |
| **MP4 cut in half** | `has_manifest: true`, `Invalid`, `general.error` | ***No claim found*** |

**Signed files cut short:**

| file | here | `c2patool` (both) |
|---|---|---|
| JPEG, half / last 10 bytes cut | `Invalid`, `general.error` / `assertion.dataHash.mismatch` | parse error / `assertion.dataHash.mismatch` |
| PNG, half / last 10 bytes cut | `Invalid`, `general.error` | *PNG out of range* |
| MP4, half | `Invalid`, `general.error` | *Box size extends beyond asset* |
| **MP4, last 10 bytes cut** | `Invalid`, `general.error` | `Invalid`, **`assertion.bmffHash.mismatch`** |

## What it means (reasoned)

- **No wrong `Valid` anywhere.**
- **JPEG and PNG: the verdict agrees in effect**; only the flag misleads.
  An unsigned file that is malformed says it has a manifest. `c2patool`
  errors on the same files.
- **ISOBMFF has the RIFF pattern once:** a truncated last box. An
  unsigned MP4 cut short is "manifest, `Invalid`" here and "no claim" in
  `c2patool`; a signed one cut by 10 bytes is `general.error` here and a
  BMFF hash mismatch there. Both `Invalid` when signed.
- **A small, consistent fix** is the flag alone: the JPEG, PNG and ISOBMFF
  extractors report `storeReached` as the RIFF walk does (an APP11 JUMBF
  segment, a `caBX` chunk, a C2PA `uuid` box seen or not). That changes
  no verdict. Relaxing the ISOBMFF walk for a truncated last box is a
  larger question (the BMFF hash and the Merkle trees depend on the box
  structure) and is not proposed now.

## Not done

Nothing built. The proposal goes to Maurice.
