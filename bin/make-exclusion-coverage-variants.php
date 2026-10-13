<?php

declare(strict_types=1);

/*
 * Step 349, a measurement: how much of the file may the data hash leave out?
 * D. Buchanan, "How to Hack Time, With C2PA" (2 October 2026), signs a
 * c2pa.hash.data whose one exclusion is the whole file, so that the hash covers
 * nothing and the pixels can change under a valid signature and timestamp.
 * SPEC-012 amendment 7 refuses that exact shape (the range holding the store
 * holds nothing else). Every exclusion that holds no part of the store is
 * honoured as signed with an informational code (VAL-ASSE-0045), so the same
 * effect may survive as three ranges: before the store, the store, after it.
 * Neither c2patool version writes such exclusions, so the PNG fixture's store
 * is edited in the shape bin/make-actions-label-variants.php (step 281) uses:
 * the exclusion list replaced, the hash and the claim's hashed URI recomputed,
 * the claim re-signed with a throw-away P-256 hierarchy. Keys live outside the
 * repository for the run and are deleted before it ends; the public root goes
 * into a settings file. Both c2patool versions and this verifier then judge
 * each file.
 *
 * Variants:
 *   control-resigned            the store unchanged, re-signed: the pipeline itself
 *   whole-file                  one exclusion [0, file length]: the article's shape
 *   all-but-store               [0, 33] + the store + [store end, file end]: zero bytes hashed
 *   all-but-store-pixel-edited  the same file with one IDAT byte changed after signing
 *   all-but-one-byte            the same three ranges, the last one byte short: one byte hashed (step 351, SPEC-068 AC3)
 *   idat-excluded               the store + [first IDAT, IEND): only the image data left out
 *   idat-excluded-pixel-edited  the same file with one IDAT byte changed after signing
 *
 * Usage: php bin/make-exclusion-coverage-variants.php <scratch-dir> <c2patool-0.28.1> <c2patool-0.28.2>.
 * Tooling, not the verification path.
 *
 * Writes:
 *   tests/Fixtures/exclusion-coverage/<variant>.png, throw-away-root.pem, throw-away-root.settings.json
 *   tests/Fixtures/c2patool/exclusion-coverage/<variant>--<version>.json
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Cose\SignatureVerifier;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$scratch, $old, $new] = [$argv[1] ?? null, $argv[2] ?? '', $argv[3] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_string($scratch) || ! is_dir($scratch) || ! is_file($old) || ! is_file($new) || $version($old) !== 'c2patool 0.28.1' || $version($new) !== 'c2patool 0.28.2') {
    fwrite(STDERR, "usage: php bin/make-exclusion-coverage-variants.php <scratch-dir outside the repository> <c2patool-0.28.1> <c2patool-0.28.2>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/exclusion-coverage-keys-'.bin2hex(random_bytes(4));
if (! mkdir($keys, 0700)) {
    throw new RuntimeException("cannot create {$keys}");
}
register_shutdown_function(static function () use ($keys): void {
    foreach (glob("{$keys}/*") ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($keys)) {
        rmdir($keys);
        echo "keys deleted: {$keys}\n";
    }
});

function ecRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function ecSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

function ecBstr(string $b): string
{
    $n = strlen($b);

    return ($n < 24 ? chr(0x40 + $n) : ($n < 256 ? "\x58".chr($n) : "\x59".pack('n', $n))).$b;
}

function ecUint(int $n): string
{
    return match (true) {
        $n < 0 => throw new RuntimeException('a CBOR unsigned integer cannot be negative'),
        $n < 24 => chr($n),
        $n < 256 => "\x18".chr($n),
        $n < 65536 => "\x19".pack('n', $n),
        default => "\x1a".pack('N', $n),
    };
}

/** A DER ECDSA signature as R‖S of 2 × $curveBytes (what COSE carries). */
function ecDerToRs(string $der, int $curveBytes): string
{
    if ($der[0] !== "\x30") {
        throw new RuntimeException('not a DER ECDSA signature');
    }
    $p = ord($der[1]) & 0x80 ? 2 + (ord($der[1]) & 0x7F) : 2;
    $rs = '';
    for ($i = 0; $i < 2; $i++) {
        if ($der[$p] !== "\x02") {
            throw new RuntimeException('not a DER ECDSA signature');
        }
        $len = ord($der[$p + 1]);
        $int = ltrim(substr($der, $p + 2, $len), "\0");
        $rs .= str_pad($int, $curveBytes, "\0", STR_PAD_LEFT);
        $p += 2 + $len;
    }

    return $rs;
}

/**
 * The hash of $bytes with the [start, length] ranges skipped (C2PA 2.4 §15.12.1).
 *
 * @param  list<array{0: int, 1: int}>  $exclusions
 */
function ecDataHash(string $bytes, array $exclusions): string
{
    usort($exclusions, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    $ctx = hash_init('sha256');
    $p = 0;
    foreach ($exclusions as [$start, $length]) {
        hash_update($ctx, substr($bytes, $p, $start - $p));
        $p = $start + $length;
    }
    hash_update($ctx, substr($bytes, $p));

    return hash_final($ctx, true);
}

// ---- the fixture's store (step 09 offsets, as bin/make-data-hash-variants.php measures them) ----
$root = dirname(__DIR__);
$png = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.png');
$stream = fopen($root.'/tests/Fixtures/fixture-signed.png', 'rb');
if ($stream === false) {
    throw new RuntimeException('cannot open the PNG fixture');
}
$extracted = (new PngManifestStoreExtractor)->extract($stream);
if ($extracted === null || strlen($extracted->bytes) !== 46025 || strlen($png) !== 47736) {
    throw new RuntimeException('the PNG fixture is not the one measured in step 09');
}
$s = $extracted->bytes;
$HASH_DATA_BOX = 32831;
$HASH_DATA = 32911;
$CLAIM = 33081;
$hashBox = [0, 38, 117, 32831, 32903];
$hd = bMapPairs($s, $HASH_DATA);
[, $exStart, $exEnd] = $hd['exclusions'];                           // 81 a2 65 start 18 21 66 length 19 b3 d5
[, $hashStart] = $hd['hash'];                                       // 58 20 <32>, after the exclusions
$claim = bMapPairs($s, $CLAIM);
$claimHashValue = bMapPairs($s, $claim['created_assertions'][1] + 1)['hash'][1] + 2;
$tail = strlen($png) - 33 - 12 - strlen($s);                         // pHYs, IDAT, IEND after the caBX chunk: 1,666 bytes
$idatFromTail = 46091 - (33 + 12 + 46025);                           // IDAT's offset inside the tail
$idatSpan = 47724 - 46091;                                           // IDAT up to IEND, header and CRC included

/**
 * The store with its exclusion list made by $ranges (from the store's length), the
 * hash recomputed over the PNG that carries it and the claim's hashed URI with it.
 * The list's length depends on the store's and the other way round, so it is
 * rebuilt until it no longer moves.
 *
 * @param  Closure(int): list<array{0: int, 1: int}>  $ranges
 * @param  list<int>  $hashBox
 * @return array{0: string, 1: list<array{0: int, 1: int}>}
 */
function ecRebound(Closure $ranges, string $s, string $png, int $exStart, int $exEnd, int $hashStart, int $claimHashValue, array $hashBox, int $hashDataBox): array
{
    $length = strlen($s);
    for ($round = 0; $round < 4; $round++) {
        $list = $ranges($length);
        if (count($list) > 23) {
            throw new RuntimeException('more exclusions than a one-byte CBOR list head holds');
        }
        $cbor = chr(0x80 | count($list)).implode('', array_map(static fn (array $r): string => "\xa2\x65start".ecUint($r[0])."\x66length".ecUint($r[1]), $list));
        $store = bSplice($s, $exStart, $exEnd - $exStart, $cbor, $hashBox);
        if (strlen($store) === $length) {
            $delta = strlen($cbor) - ($exEnd - $exStart);
            $digest = ecDataHash(pngWithStore($png, $store), $list);
            $store = bReplace($store, $hashStart + $delta + 2, substr($store, $hashStart + $delta + 2, 32), $digest);
            $boxLength = bU32($store, $hashDataBox);
            $uri = hash('sha256', substr($store, $hashDataBox + 8, $boxLength - 8), true);

            return [bReplace($store, $claimHashValue + $delta, substr($store, $claimHashValue + $delta, 32), $uri), $list];
        }
        $length = strlen($store);
    }
    throw new RuntimeException('the exclusion list did not settle');
}
$rebound = static fn (Closure $ranges): array => ecRebound($ranges, $s, $png, $exStart, $exEnd, $hashStart, $claimHashValue, $hashBox, $HASH_DATA_BOX);

$variants = [
    'control-resigned' => [$s, [[33, 12 + strlen($s)]]],
    'whole-file' => $rebound(static fn (int $n): array => [[0, 33 + 12 + $n + $tail]]),
    'all-but-store' => $rebound(static fn (int $n): array => [[0, 33], [33, 12 + $n], [33 + 12 + $n, $tail]]),
    'all-but-one-byte' => $rebound(static fn (int $n): array => [[0, 33], [33, 12 + $n], [33 + 12 + $n, $tail - 1]]),
    'idat-excluded' => $rebound(static fn (int $n): array => [[33, 12 + $n], [33 + 12 + $n + $idatFromTail, $idatSpan]]),
];

// ---- the throw-away hierarchy: a P-256 root and a leaf on the C2PA profile ----
$cnf = <<<'CNF'
[req]
distinguished_name = dn
[dn]
[v3_root]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign, digitalSignature
subjectKeyIdentifier = hash
[good]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, nonRepudiation
extendedKeyUsage = emailProtection
CNF;
file_put_contents("{$keys}/ext.cnf", $cnf);
ecRun(ecSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
ecRun(ecSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (step 349)', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'v3_root', '-out', "{$keys}/root.pem"));
ecRun(ecSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/leaf.key"));
ecRun(ecSh('openssl', 'req', '-new', '-key', "{$keys}/leaf.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=step 349 exclusion coverage', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/leaf.csr"));
ecRun(ecSh('openssl', 'x509', '-req', '-in', "{$keys}/leaf.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'good', '-out', "{$keys}/leaf.pem"));
$pemToDer = static fn (string $pem): string => (string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem) ?? '', true);
$rootPem = (string) file_get_contents("{$keys}/root.pem");
$rootDer = $pemToDer($rootPem);
$leafDer = $pemToDer((string) file_get_contents("{$keys}/leaf.pem"));

$dir = $root.'/tests/Fixtures/exclusion-coverage';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
$settings = ['trust' => ['trust_anchors' => $rootPem, 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')], 'verify' => ['verify_trust' => true]];
file_put_contents("{$dir}/throw-away-root.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

$files = [];
foreach ($variants as $name => [$edited, $ranges]) {
    $manifest = ManifestStore::fromTree((new JumbfParser)->parse($edited))->active;
    $claimBytes = $manifest->claimBytes();
    $COSE = strpos($edited, $manifest->signatureBytes());
    if ($COSE === false) {
        throw new RuntimeException("{$name}: no COSE_Sign1");
    }
    $COSE_LENGTH = bU32($edited, $COSE - 8) - 8;

    // the new COSE_Sign1: {1: -7, 33: [leaf, root]}, signed over the edited claim
    $protected = "\xa2\x01\x26\x18\x21\x82".ecBstr($leafDer).ecBstr($rootDer);
    $draft = "\xd2\x84".ecBstr($protected)."\xa1\x63pad".ecBstr('')."\xf6".ecBstr(str_repeat("\0", 64));
    $sigStructure = CoseSign1::fromBytes($draft)->sigStructure($claimBytes);
    file_put_contents("{$keys}/tbs", $sigStructure);
    ecRun(ecSh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/leaf.key", '-out', "{$keys}/sig", "{$keys}/tbs"));
    $signature = ecDerToRs((string) file_get_contents("{$keys}/sig"), 32);
    $fixed = 2 + strlen(ecBstr($protected)) + 5 + 3 + 1 + strlen(ecBstr($signature));
    $padLength = $COSE_LENGTH - $fixed;
    if ($padLength < 256) {
        throw new RuntimeException("{$name}: the pad would be {$padLength} bytes, too short for a 3-byte head");
    }
    $cose = "\xd2\x84".ecBstr($protected)."\xa1\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength)."\xf6".ecBstr($signature);
    if (strlen($cose) !== $COSE_LENGTH) {
        throw new RuntimeException("{$name}: COSE is ".strlen($cose)." bytes, not {$COSE_LENGTH}");
    }
    $store = substr($edited, 0, $COSE).$cose.substr($edited, $COSE + $COSE_LENGTH);
    if (! (new SignatureVerifier)->verify(CoseSign1::fromBytes($cose), $claimBytes)) {
        throw new RuntimeException("{$name}: the new signature does not verify under its own leaf");
    }
    $signed = pngWithStore($png, $store);
    $hashed = strlen($signed) - array_sum(array_column($ranges, 1));
    $files[$name] = $signed;
    printf("%s  %-28s %d of %d bytes hashed, exclusions %s\n", hash('sha256', $signed), $name, $hashed, strlen($signed), implode(' ', array_map(static fn (array $r): string => "[{$r[0]}, {$r[1]}]", $ranges)));
    if (in_array($name, ['all-but-store', 'idat-excluded'], true)) {
        $files["{$name}-pixel-edited"] = pngPixelChanged($signed);
    }
}
foreach ($files as $name => $bytes) {
    file_put_contents("{$dir}/{$name}.png", $bytes);
}

// ---- the oracles, and this verifier beside them ----
$oracles = $root.'/tests/Fixtures/c2patool/exclusion-coverage';
if (! is_dir($oracles) && ! mkdir($oracles, 0755, true)) {
    throw new RuntimeException("cannot create {$oracles}");
}
$state = static function (string $output): string {
    $json = json_decode($output, true);
    if (! is_array($json)) {
        return 'error: '.strtok($output, "\n");
    }
    $active = is_array($json['validation_results'] ?? null) && is_array($json['validation_results']['activeManifest'] ?? null) ? $json['validation_results']['activeManifest'] : [];
    $codes = [];
    foreach (['success', 'informational', 'failure'] as $kind) {
        foreach (is_array($active[$kind] ?? null) ? $active[$kind] : [] as $status) {
            if (is_array($status) && is_string($status['code'] ?? null) && (str_starts_with($status['code'], 'assertion.dataHash') || $kind === 'failure')) {
                $codes[] = ($kind === 'failure' ? '!' : '').$status['code'];
            }
        }
    }

    return (is_string($json['validation_state'] ?? null) ? $json['validation_state'] : '?').'  '.implode(' ', array_unique($codes));
};
foreach (array_keys($files) as $name) {
    foreach (['0.28.1' => $old, '0.28.2' => $new] as $v => $tool) {
        $lines = [];
        exec(ecSh($tool, "{$dir}/{$name}.png", '--settings', "{$dir}/throw-away-root.settings.json").' 2>&1', $lines);
        file_put_contents("{$oracles}/{$name}--{$v}.json", implode("\n", $lines)."\n");
        printf("  %-28s c2patool %-7s %s\n", $name, $v, $state(implode("\n", $lines)));
    }
    $lines = [];
    exec(ecSh(PHP_BINARY, $root.'/bin/c2pa-verify', '--settings', "{$dir}/throw-away-root.settings.json", "{$dir}/{$name}.png").' 2>&1', $lines);
    printf("  %-28s %-17s %s\n", $name, 'this verifier', $state(implode("\n", $lines)));
}
