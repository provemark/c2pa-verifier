<?php

declare(strict_types=1);

/*
 * Step 446: the fixtures of SPEC-080, a Merkle tree over the mdat of a non-fragmented MP4.
 *
 * `c2patool` 0.28.3 writes that tree when `core.merkle_tree_chunk_size_in_kb` is set (step 443). It signs with a
 * throw-away P-256 hierarchy whose keys live in a directory under <scratch> for the run, and are overwritten and
 * deleted before it ends; the public root goes into a settings file. Three kinds of variant follow from the signed
 * files:
 *
 *   - a byte changed after signing (AC3), or a proof box changed, moved, removed or doubled (AC4, AC6): the proof
 *     boxes are top-level C2PA uuid boxes, excluded from the file `hash`, so none of this needs a new signature;
 *   - the BMFF hash assertion changed and the claim signed again (AC6, AC7, AC8). The CBOR is edited in place,
 *     every JUMBF box around the edit and the manifest's uuid box take the new length, the file `hash` is computed
 *     again (from BMFF hash v2 on, it binds the offset of every box it hashes, and a manifest of another length
 *     moves them all), the claim's hashed URI for the assertion is hashed again and the claim is signed again with
 *     the same throw-away leaf. That `c2patool` then finds no file level mismatch is the check on the recomputed
 *     `hash`. The chunk offsets in `stco` are left as they were: a player would notice, neither verifier reads them;
 *   - a file with two mdat boxes, signed by `c2patool`, which then writes two merkle maps (AC8).
 *
 * Usage: php bin/make-mdat-merkle-variants.php <scratch-dir> <c2patool-0.28.3> <c2patool-0.28.1>
 * Needs `openssl` and `ffmpeg` (9.0.2 measured). Tooling, not the verification path.
 *
 * Writes:
 *   tests/Fixtures/mdat-merkle/<variant>.mp4, throw-away-root.pem, throw-away-root.settings.json
 *   tests/Fixtures/c2patool/mdat-merkle/<variant>--<version>.json
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\IsobmffManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Hash\BmffHashCheck;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$scratch, $new, $old] = [$argv[1] ?? null, $argv[2] ?? '', $argv[3] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_string($scratch) || ! is_dir($scratch) || $version($new) !== 'c2patool 0.28.3' || $version($old) !== 'c2patool 0.28.1') {
    fwrite(STDERR, "usage: php bin/make-mdat-merkle-variants.php <scratch-dir outside the repository> <c2patool-0.28.3> <c2patool-0.28.1>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/mdat-merkle-keys-'.bin2hex(random_bytes(4));
if (! mkdir($keys, 0700)) {
    throw new RuntimeException("cannot create {$keys}");
}
register_shutdown_function(static function () use ($keys): void {
    if (! is_dir($keys)) {
        return;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($keys, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file instanceof SplFileInfo) {
            if ($file->isDir()) {
                rmdir($file->getPathname());

                continue;
            }
            if (in_array($file->getExtension(), ['key', 'pk8'], true) && $file->getSize() > 0) {
                file_put_contents($file->getPathname(), str_repeat("\0", (int) $file->getSize()));
            }
            unlink($file->getPathname());
        }
    }
    rmdir($keys);
    echo "keys overwritten and deleted: {$keys}\n";
});

function mmRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function mmSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

/** @return list<array{offset: int, length: int, type: string}> the boxes from $from to $to, one level */
function mmBoxes(string $bytes, int $from, int $to): array
{
    $boxes = [];
    for ($o = $from; $o + 8 <= $to;) {
        $length = bU32($bytes, $o);
        if ($length < 8 || $o + $length > $to) {
            throw new RuntimeException("a box at {$o} runs past {$to}");
        }
        $boxes[] = ['offset' => $o, 'length' => $length, 'type' => substr($bytes, $o + 4, 4)];
        $o += $length;
    }

    return $boxes;
}

/** @return array{offset: int, length: int, type: string} the top-level box of $type number $n, from 0 */
function mmTop(string $bytes, string $type, int $n = 0): array
{
    $found = array_values(array_filter(mmBoxes($bytes, 0, strlen($bytes)), static fn (array $b): bool => $b['type'] === $type));

    return $found[$n] ?? throw new RuntimeException("no {$type} number {$n}");
}

/** @return list<array{offset: int, length: int, type: string}> the top-level C2PA uuid boxes of purpose $purpose */
function mmC2paBoxes(string $bytes, string $purpose): array
{
    return array_values(array_filter(
        mmBoxes($bytes, 0, strlen($bytes)),
        static fn (array $b): bool => $b['type'] === 'uuid' && substr($bytes, $b['offset'] + 28, strlen($purpose) + 1) === $purpose."\0",
    ));
}

/**
 * The LBox offsets of every box around $at: the manifest's uuid box and each JUMBF box, superbox or not.
 *
 * @return list<int>
 */
function mmEnclosing(string $bytes, int $at): array
{
    $uuid = mmC2paBoxes($bytes, 'manifest')[0];
    $enclosing = [$uuid['offset']];
    $from = $uuid['offset'] + 28 + strlen("manifest\0") + 8;
    $to = $uuid['offset'] + $uuid['length'];
    while (true) {
        $inside = null;
        foreach (mmBoxes($bytes, $from, $to) as $box) {
            if ($at >= $box['offset'] && $at < $box['offset'] + $box['length']) {
                $inside = $box;
            }
        }
        if ($inside === null) {
            return $enclosing;
        }
        $enclosing[] = $inside['offset'];
        if ($inside['type'] !== 'jumb') {
            return $enclosing;
        }
        [$from, $to] = [$inside['offset'] + 8, $inside['offset'] + $inside['length']];
    }
}

/** @return array{0: int, 1: int} the start and the end of the BMFF hash assertion's jumb box */
function mmAssertion(string $bytes): array
{
    $at = bFind($bytes, "c2pa.hash.bmff.v3\0", 0, strlen($bytes));
    $box = (int) strrpos(substr($bytes, 0, $at), 'jumb') - 4;

    return [$box, $box + bU32($bytes, $box)];
}

/**
 * The file with the edits $edit returns made inside the BMFF hash assertion, every enclosing length adjusted, the
 * claim's hashed URI for the assertion hashed again and the claim signed again (ES256) with $key.
 *
 * @param  callable(string, int, int): list<array{0: int, 1: int, 2: string}>  $edit  ($bytes, $start, $end) => [at, length, with]
 */
function mmResign(string $bytes, callable $edit, string $key): string
{
    $uuid = mmC2paBoxes($bytes, 'manifest')[0];
    $storeAt = $uuid['offset'] + 28 + strlen("manifest\0") + 8;
    $store = substr($bytes, $storeAt, $uuid['offset'] + $uuid['length'] - $storeAt);
    $manifest = ManifestStore::fromTree((new JumbfParser)->parse($store))->active;
    $claim = $manifest->claimBytes();
    $signature = $manifest->signatureBytes();
    [$start, $end] = mmAssertion($bytes);
    $digest = hash('sha256', substr($bytes, $start + 8, $end - $start - 8), true);
    if (substr_count($claim, $digest) !== 1) {
        throw new RuntimeException('the claim does not hold the assertion\'s SHA-256 once');
    }

    $edits = $edit($bytes, $start, $end);
    usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
    foreach ($edits as [$at, $length, $with]) {
        if ($at < $start || $at + $length > $end) {
            throw new RuntimeException('an edit leaves the assertion');
        }
        $bytes = bSplice($bytes, $at, $length, $with, mmEnclosing($bytes, $at));
    }

    $bytes = mmRehash($bytes);
    [$start, $end] = mmAssertion($bytes);
    $newClaim = str_replace($digest, hash('sha256', substr($bytes, $start + 8, $end - $start - 8), true), $claim);
    $claimAt = strpos($bytes, $claim);
    $coseAt = strpos($bytes, $signature);
    if ($claimAt === false || $coseAt === false || substr($signature, -66, 2) !== "\x58\x40") {
        throw new RuntimeException('the claim or a COSE_Sign1 ending in a 64-byte signature is not found');
    }
    $bytes = substr_replace($bytes, $newClaim, $claimAt, strlen($newClaim));
    file_put_contents(dirname($key).'/tbs', CoseSign1::fromBytes($signature)->sigStructure($newClaim));
    mmRun(mmSh('openssl', 'dgst', '-sha256', '-sign', $key, '-out', dirname($key).'/sig', dirname($key).'/tbs'));
    $der = (string) file_get_contents(dirname($key).'/sig');
    $rs = '';
    for ($i = 0, $q = 2; $i < 2; $i++) {
        $l = ord($der[$q + 1]);
        $rs .= str_pad(ltrim(substr($der, $q + 2, $l), "\0"), 32, "\0", STR_PAD_LEFT);
        $q += 2 + $l;
    }

    return substr_replace($bytes, $rs, $coseAt + strlen($signature) - 64, 64);
}

/**
 * The file with its BMFF hash assertion's `hash` computed again over the file as it now is: the ranges
 * BmffHashCheck::plan() leaves, each top-level box's offset first as a big-endian uint64, as c2pa-rs hashes them.
 */
function mmRehash(string $bytes): string
{
    $uuid = mmC2paBoxes($bytes, 'manifest')[0];
    $storeAt = $uuid['offset'] + 28 + strlen("manifest\0") + 8;
    $manifest = ManifestStore::fromTree((new JumbfParser)->parse(substr($bytes, $storeAt, $uuid['offset'] + $uuid['length'] - $storeAt)))->active;
    $data = $manifest->assertions['c2pa.hash.bmff.v3']->data ?? null;
    if (! is_array($data) || ! is_array($data['exclusions'] ?? null) || ($data['alg'] ?? 'sha256') !== 'sha256') {
        throw new RuntimeException('no sha256 BMFF hash with exclusions');
    }
    $stream = fopen('php://memory', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('no memory stream');
    }
    fwrite($stream, $bytes);
    rewind($stream);
    /** @var list<array<string, mixed>> $exclusions */
    $exclusions = $data['exclusions'];
    $context = hash_init('sha256');
    foreach (BmffHashCheck::plan((new IsobmffManifestStoreExtractor)->boxTree($stream), $exclusions, static fn (int $at, int $length): string => substr($bytes, $at, $length)) as $range) {
        if ($range['marker']) {
            hash_update($context, pack('J', $range['offset']));
        }
        hash_update($context, substr($bytes, $range['offset'], $range['length']));
    }
    [$start, $end] = mmAssertion($bytes);
    $at = bFind($bytes, "\x64hash\x58\x20", $start, $end) + 7;

    return substr_replace($bytes, hash_final($context, true), $at, 32);
}

/** @return int the offset of the merkle map's header (one map, in an array of one) */
function mmMerkleMap(string $bytes, int $start, int $end): int
{
    $at = bFind($bytes, "\x66merkle\x81", $start, $end) + 8;
    if (ord($bytes[$at]) >> 5 !== 5) {
        throw new RuntimeException('the merkle list does not hold a map');
    }

    return $at;
}

/** @return int the offset of the header of the exclusion map whose xpath is /mdat */
function mmMdatExclusion(string $bytes, int $start, int $end): int
{
    return bFind($bytes, "\x65xpath\x65/mdat", $start, $end) - 1;
}

/**
 * One pair of the map at $map replaced by $with ('' removes it); $countDelta added to the map's (small) count.
 *
 * @return list<array{0: int, 1: int, 2: string}>
 */
function mmPair(string $bytes, int $map, string $key, string $with, int $countDelta = 0): array
{
    $pair = bMapPairs($bytes, $map)[$key] ?? throw new RuntimeException("no {$key}");
    $edits = [[$pair[0], $pair[2] - $pair[0], $with]];
    if ($countDelta !== 0) {
        $edits[] = [$map, 1, mmByte(ord($bytes[$map]) + $countDelta)];
    }

    return $edits;
}

/** @return list<array{0: int, 1: int, 2: string}> a pair added at the end of the map at $map */
function mmAdd(string $bytes, int $map, string $pair): array
{
    return [[bCborEnd($bytes, $map), 0, $pair], [$map, 1, mmByte(ord($bytes[$map]) + 1)]];
}

function mmByte(int $value): string
{
    if ($value < 0 || $value > 255) {
        throw new RuntimeException("{$value} is no byte");
    }

    return chr($value);
}

/** A CBOR text string of fewer than 24 bytes, its length in the initial byte. */
function mmText(string $s): string
{
    if (strlen($s) >= 24) {
        throw new RuntimeException("{$s} needs a length byte of its own");
    }

    return mmByte(0x60 + strlen($s)).$s;
}

function mmFlip(string $bytes, int $at): string
{
    $bytes[$at] = chr(ord($bytes[$at]) ^ 0x01);

    return $bytes;
}

/**
 * @param  callable(string, int): list<array{0: int, 1: int, 2: string}>  $f  ($bytes, the merkle map's offset) => edits
 * @return callable(string, int, int): list<array{0: int, 1: int, 2: string}>
 */
function mmOnMap(callable $f): callable
{
    return static fn (string $b, int $s, int $e): array => $f($b, mmMerkleMap($b, $s, $e));
}

/**
 * @param  callable(string, int): list<array{0: int, 1: int, 2: string}>  $f  ($bytes, the /mdat exclusion's offset) => edits
 * @return callable(string, int, int): list<array{0: int, 1: int, 2: string}>
 */
function mmOnMdat(callable $f): callable
{
    return static fn (string $b, int $s, int $e): array => $f($b, mmMdatExclusion($b, $s, $e));
}

$root = dirname(__DIR__);
$dir = $root.'/tests/Fixtures/mdat-merkle';
$oracles = $root.'/tests/Fixtures/c2patool/mdat-merkle';
foreach ([$dir, $oracles] as $d) {
    if (! is_dir($d) && ! mkdir($d, 0755, true)) {
        throw new RuntimeException("cannot create {$d}");
    }
}

// ---- the throw-away hierarchy: a P-256 root and a leaf on the C2PA profile ----
file_put_contents("{$keys}/ext.cnf", "[req]\ndistinguished_name=dn\n[dn]\n[root]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n[leaf]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=emailProtection\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
mmRun(mmSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
mmRun(mmSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (mdat Merkle)', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'root', '-out', "{$keys}/root.pem"));
mmRun(mmSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/leaf.key"));
mmRun(mmSh('openssl', 'req', '-new', '-key', "{$keys}/leaf.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=mdat Merkle signer', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/leaf.csr"));
mmRun(mmSh('openssl', 'x509', '-req', '-in', "{$keys}/leaf.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'leaf', '-out', "{$keys}/leaf.pem"));
mmRun(mmSh('openssl', 'pkcs8', '-topk8', '-nocrypt', '-in', "{$keys}/leaf.key", '-out', "{$keys}/leaf.pk8"));
$rootPem = (string) file_get_contents("{$keys}/root.pem");
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
$settings = ['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $rootPem, 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')]];
file_put_contents("{$dir}/throw-away-root.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
file_put_contents("{$keys}/m.json", json_encode([
    'alg' => 'es256', 'private_key' => "{$keys}/leaf.pk8", 'sign_cert' => "{$keys}/leaf.pem",
    'claim_generator_info' => [['name' => 'c2pa-verifier mdat Merkle fixtures', 'version' => '1']],
    'assertions' => [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture']]]]],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
foreach ([1, 8] as $kb) {
    file_put_contents("{$keys}/chunk-{$kb}.json", json_encode(['core' => ['merkle_tree_chunk_size_in_kb' => $kb]], JSON_THROW_ON_ERROR));
}

// ---- the unsigned sources: three times fixture-unsigned.mp4 (an mdat of six 1 KB blocks), and that mdat in two ----
mmRun(mmSh('ffmpeg', '-loglevel', 'error', '-y', '-stream_loop', '2', '-i', $root.'/tests/Fixtures/fixture-unsigned.mp4', '-c', 'copy', '-map_metadata', '-1', '-fflags', '+bitexact', "{$keys}/six.mp4"));
$six = (string) file_get_contents("{$keys}/six.mp4");
$mdat = mmTop($six, 'mdat');
$blocks = (int) ceil(($mdat['length'] - 16) / 1024);
if ($blocks !== 6) {
    throw new RuntimeException("ffmpeg wrote an mdat of {$blocks} blocks, not 6");
}
$payload = substr($six, $mdat['offset'] + 8, $mdat['length'] - 8);
$half = 2800;
file_put_contents("{$keys}/two.mp4", substr($six, 0, $mdat['offset'])
    .pack('N', 8 + $half).'mdat'.substr($payload, 0, $half)
    .pack('N', 8 + strlen($payload) - $half).'mdat'.substr($payload, $half)
    .substr($six, $mdat['offset'] + $mdat['length']));

// ---- signed by c2patool 0.28.3 ----
$sign = static function (string $in, int $kb, string $out) use ($new, $keys): string {
    mmRun(mmSh($new, $in, '-m', "{$keys}/m.json", '--settings', "{$keys}/chunk-{$kb}.json", '-o', $out, '-f'));

    return (string) file_get_contents($out);
};
$variants = [];
$variants['six-blocks'] = $sign("{$keys}/six.mp4", 1, "{$dir}/six-blocks.mp4");
$variants['one-block'] = $sign("{$keys}/six.mp4", 8, "{$dir}/one-block.mp4");
$variants['two-mdat'] = $sign("{$keys}/two.mp4", 1, "{$dir}/two-mdat.mp4");
$good = $variants['six-blocks'];
$mdat = mmTop($good, 'mdat');
$proofs = mmC2paBoxes($good, 'merkle');
if (count($proofs) !== 6 || count(mmC2paBoxes($variants['one-block'], 'merkle')) !== 0) {
    throw new RuntimeException('c2patool did not write one proof box per block');
}

// ---- AC3: a byte changed after signing ----
$variants['block-0-changed'] = mmFlip($good, $mdat['offset'] + 16 + 100);
$variants['block-5-changed'] = mmFlip($good, $mdat['offset'] + 16 + 5 * 1024 + 100);
$variants['gap-changed'] = mmFlip($good, $mdat['offset'] + 12);
$variants['moov-changed'] = mmFlip($good, bFind($good, 'mvhd', mmTop($good, 'moov')['offset'], strlen($good)) + 4 + 4 + 4 + 3);

// ---- AC4 and AC6: the proof boxes, which no signature covers ----
$proof = static fn (int $n): string => substr($good, $proofs[$n]['offset'], $proofs[$n]['length']);
$location = static fn (int $n): int => bFind($good, "\x68location", $proofs[$n]['offset'], $proofs[$n]['offset'] + $proofs[$n]['length']) + 9;
$variants['proof-sibling-changed'] = mmFlip($good, bFind($good, "\x58\x20", $location(0), $proofs[0]['offset'] + $proofs[0]['length']) + 2);
$variants['proof-location-moved'] = bReplace($good, $location(0), "\x00", "\x02");
$variants['proof-location-past-count'] = bReplace($good, $location(5), "\x05", "\x06");
$variants['proof-removed'] = substr($good, 0, $proofs[2]['offset']).substr($good, $proofs[2]['offset'] + $proofs[2]['length']);
$variants['proof-freed'] = bReplace($good, $proofs[2]['offset'] + 4, 'uuid', 'free');
$variants['proof-duplicated'] = substr($good, 0, $proofs[3]['offset']).$proof(2).substr($good, $proofs[3]['offset']);

// ---- AC6, AC7, AC8: the assertion changed and the claim signed again ----
$key = "{$keys}/leaf.key";
$sizes = "\x86".str_repeat("\x19\x04\x00", 5)."\x19".pack('n', $mdat['length'] - 16 - 5 * 1024);
$edits = [
    'fixed-and-variable' => mmOnMap(static fn (string $b, int $m): array => mmAdd($b, $m, mmText('variableBlockSizes').$sizes)),
    'block-size-text' => mmOnMap(static fn (string $b, int $m): array => mmPair($b, $m, 'fixedBlockSize', mmText('fixedBlockSize').mmText('1024'))),
    'block-size-one' => mmOnMap(static fn (string $b, int $m): array => mmPair($b, $m, 'fixedBlockSize', mmText('fixedBlockSize')."\x01")),
    'count-five' => mmOnMap(static fn (string $b, int $m): array => mmPair($b, $m, 'count', mmText('count')."\x05")),
    'init-hash' => mmOnMap(static fn (string $b, int $m): array => mmAdd($b, $m, mmText('initHash')."\x58\x20".str_repeat("\0", 32))),
    'hashes-four' => mmOnMap(static function (string $b, int $m): array {
        $root = substr($b, bMapPairs($b, $m)['hashes'][1] + 1, 34);

        return mmPair($b, $m, 'hashes', mmText('hashes')."\x84".str_repeat($root, 4));
    }),
    'mdat-whole' => mmOnMdat(static fn (string $b, int $x): array => mmPair($b, $x, 'subset', '', -1)),
    'mdat-offset-24' => mmOnMdat(static fn (string $b, int $x): array => mmPair($b, $x, 'subset', mmText('subset')."\x81\xa2".mmText('offset')."\x18\x18".mmText('length')."\x00")),
    'mdat-length-1' => mmOnMdat(static fn (string $b, int $x): array => mmPair($b, $x, 'subset', mmText('subset')."\x81\xa2".mmText('offset')."\x10".mmText('length')."\x01")),
    'variable-sizes' => mmOnMap(static fn (string $b, int $m): array => mmPair($b, $m, 'fixedBlockSize', mmText('variableBlockSizes').$sizes)),
    'no-block-field' => mmOnMap(static fn (string $b, int $m): array => mmPair($b, $m, 'fixedBlockSize', '', -1)),
];
foreach ($edits as $name => $edit) {
    $variants[$name] = mmResign($good, $edit, $key);
}

// ---- written, and asked of c2patool ----
printf("  %-26s %-9s %-9s\n", 'file', '0.28.3', '0.28.1');
foreach ($variants as $name => $bytes) {
    file_put_contents("{$dir}/{$name}.mp4", $bytes);
    $states = [];
    foreach (['0.28.3' => $new, '0.28.1' => $old] as $v => $tool) {
        $lines = [];
        exec(mmSh($tool, "{$dir}/{$name}.mp4", '--settings', "{$dir}/throw-away-root.settings.json").' 2>&1', $lines);
        file_put_contents("{$oracles}/{$name}--{$v}.json", implode("\n", $lines)."\n");
        $json = json_decode(implode("\n", $lines), true);
        $states[] = is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : 'error';
    }
    printf("  %-26s %-9s %-9s\n", $name, ...$states);
}
