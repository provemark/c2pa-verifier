<?php

declare(strict_types=1);

/*
 * Step 59: the coverage matrix. Measured on 2026-09-22, the fifty corpus files with a manifest
 * covered jpeg 45 / png 4 / webp 1, signature algorithms Es256 11 / Es384 1 / Ps256 38, and
 * sha256 as the hash in all fifty — so four of the seven algorithms this verifier implements, and
 * two of the three hash algorithms, rested on hand-made vectors and on no file at all.
 *
 * This script fills that in the only honest way available: it *signs* the three unsigned fixtures
 * (jpg, png, webp, all three already in the repository) with every algorithm, using c2patool
 * 0.27.22 and c2pa-rs's public test certificates — 21 files whose expected answer comes from the
 * oracle itself. The claim's hash algorithm follows the signature's in c2pa-rs (es384/ps384 ->
 * sha384, es512/ps512 -> sha512), so the matrix covers the hash algorithms too.
 *
 * The private keys are c2pa-rs's published test keys. They are downloaded into the scratch
 * directory, used there, and deleted before this script ends: no key ever enters this repository,
 * not even a test key (the project's rule; Maurice van Loon, 2026-09-21). What is kept is the
 * signed files, the trust settings built from the matching root bundle (certificates only), and
 * c2patool's JSON for each.
 *
 * Usage: php bin/make-matrix-fixtures.php <scratch-dir> [path-to-c2patool]. Tooling: it runs
 * c2patool and curl, which the verification path never does.
 */

require __DIR__.'/../vendor/autoload.php';

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
$scratch = $argv[1] ?? null;
$c2patool = $argv[2] ?? dirname(__DIR__, 2).'/C2PA_Content_Credentials/tools/c2patool';
if (! is_string($scratch) || ! is_dir($scratch)) {
    fwrite(STDERR, "usage: php bin/make-matrix-fixtures.php <scratch-dir outside the repository> [path-to-c2patool]\n");
    exit(1);
}
if (! is_file($c2patool)) {
    fwrite(STDERR, "c2patool not found at {$c2patool}\n");
    exit(1);
}

const MATRIX_TAG = 'c2pa-v0.90.22';
const MATRIX_CERTS = 'https://raw.githubusercontent.com/contentauth/c2pa-rs/'.MATRIX_TAG.'/sdk/tests/fixtures/certs';
const MATRIX_ALGORITHMS = ['es256', 'es384', 'es512', 'ps256', 'ps384', 'ps512', 'ed25519'];
const MATRIX_FORMATS = ['jpg', 'png', 'webp'];

$work = rtrim($scratch, '/').'/matrix-'.bin2hex(random_bytes(4));
if (! mkdir($work.'/certs', 0700, true)) {
    throw new RuntimeException("cannot create {$work}/certs");
}
register_shutdown_function(static function () use ($work): void {
    foreach (glob($work.'/certs/*') ?: [] as $file) {
        unlink($file);
    }
    foreach (glob($work.'/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($work.'/certs')) {
        rmdir($work.'/certs');
    }
    if (is_dir($work)) {
        rmdir($work);
        echo "keys deleted: {$work}\n";
    }
});

function matrixRun(string $command): string
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }

    return implode("\n", $lines);
}

function matrixSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

// ---- c2pa-rs's public test certificates and keys, pinned to the tag this project measures against ----
foreach ([...MATRIX_ALGORITHMS, 'trust/test_cert_root_bundle', 'trust/store.cfg'] as $name) {
    foreach ($name === 'trust/store.cfg' ? [''] : (str_contains($name, '/') ? ['.pem'] : ['.pem', '.pub']) as $extension) {
        $target = $work.'/certs/'.basename($name).$extension;
        $url = MATRIX_CERTS.'/'.$name.$extension;
        matrixRun(matrixSh('curl', '-sfL', '-o', $target, $url));
        if (filesize($target) === 0) {
            throw new RuntimeException("empty download: {$url}");
        }
    }
}
$root = dirname(__DIR__);
$dir = $root.'/tests/Fixtures/matrix';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}

// the trust settings: the root bundle (certificates only) and the EKU list, both c2pa-rs's
file_put_contents($dir.'/test-roots.settings.json', json_encode([
    'trust' => [
        'trust_anchors' => (string) file_get_contents($work.'/certs/test_cert_root_bundle.pem'),
        'trust_config' => (string) file_get_contents($work.'/certs/store.cfg'),
    ],
    'verify' => ['verify_trust' => true],
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

$oracles = $root.'/tests/Fixtures/c2patool/matrix';
if (! is_dir($oracles) && ! mkdir($oracles, 0755, true)) {
    throw new RuntimeException("cannot create {$oracles}");
}

$made = [];
foreach (MATRIX_ALGORITHMS as $alg) {
    foreach (MATRIX_FORMATS as $format) {
        $name = "{$alg}.{$format}";
        $manifest = $work.'/manifest.json';
        file_put_contents($manifest, json_encode([
            'claim_generator_info' => [['name' => 'c2pa-verifier matrix', 'version' => '0.0.0']],
            'title' => $name,
            'alg' => $alg,
            'private_key' => $work.'/certs/'.$alg.'.pem',
            'sign_cert' => $work.'/certs/'.$alg.'.pub',
            'assertions' => [[
                'label' => 'c2pa.actions',
                'data' => ['actions' => [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/algorithmicMedia']]],
            ]],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $target = "{$dir}/{$name}";
        if (is_file($target)) {
            unlink($target);
        }
        // no generated thumbnail: it would add ~80 KB of JPEG preview to each of the 21 files, and
        // what this matrix is evidence for is the signature and the hash, not the preview
        $signing = $work.'/signing.json';
        file_put_contents($signing, json_encode(['builder' => ['thumbnail' => ['enabled' => false]]], JSON_THROW_ON_ERROR));
        matrixRun(matrixSh($c2patool, $root."/tests/Fixtures/fixture-unsigned.{$format}", '-m', $manifest, '--settings', $signing, '-o', $target));

        // the oracle, twice: without settings (untrusted, as every file is) and with the roots
        matrixRun(matrixSh($c2patool, $target).' > '.escapeshellarg("{$oracles}/{$name}.json"));
        matrixRun(matrixSh($c2patool, $target, '--settings', $dir.'/test-roots.settings.json').' > '.escapeshellarg("{$oracles}/{$name}.trusted.json"));
        /** @var array<string, mixed> $json */
        $json = json_decode((string) file_get_contents("{$oracles}/{$name}.trusted.json"), true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($json['manifests']) && is_string($json['active_manifest']));
        $active = $json['manifests'][$json['active_manifest']];
        assert(is_array($active) && is_array($active['signature_info']));
        $signatureAlg = $active['signature_info']['alg'];
        $state = $json['validation_state'];
        assert(is_string($signatureAlg) && is_string($state));
        $made[] = [$name, filesize($target), $signatureAlg, $state];
    }
}

printf("%-14s %8s %-8s %s\n", 'file', 'bytes', 'alg', 'state (with the test roots)');
foreach ($made as [$name, $size, $alg, $state]) {
    printf("%-14s %8d %-8s %s\n", $name, $size, $alg, $state);
}
printf("\n%d files in %s, oracles in %s\n", count($made), $dir, $oracles);

// ============================================================================
// The hash algorithms. c2patool writes sha256 into the claim whatever the signature algorithm is
// (measured: all 21 files above), so sha384 and sha512 data hashes cannot be produced with it. They
// are made here by surgery on the es256 PNG: the assertion's `alg` and digest replaced, its
// exclusion re-lengthened, the digest recomputed over the file, the claim's hashed URI for the
// assertion recomputed, and the claim re-signed with the same test key — a file c2patool validates,
// so the oracle still answers for it.
// ============================================================================
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Jumbf\Superbox;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/**
 * The offsets of every superbox that encloses $offset, outermost first — what bSplice must adjust.
 *
 * @return list<int>
 */
function matrixEnclosing(Superbox $box, int $offset): array
{
    if ($offset < $box->offset || $offset >= $box->offset + $box->length) {
        return [];
    }
    $chain = [$box->offset];
    foreach ($box->superboxes() as $child) {
        $chain = [...$chain, ...matrixEnclosing($child, $offset)];
    }

    return $chain;
}

$source = $dir.'/es256.png';
$png = (string) file_get_contents($source);
$stream = fopen($source, 'rb');
if ($stream === false) {
    throw new RuntimeException('cannot open the es256 PNG');
}
$extracted = (new PngManifestStoreExtractor)->extract($stream);
if ($extracted === null) {
    throw new RuntimeException('no store in the es256 PNG');
}
foreach (['sha384' => 48, 'sha512' => 64] as $alg => $digestLength) {
    $s = $extracted->bytes;
    $tree = (new JumbfParser)->parse($s);
    $store = ManifestStore::fromTree($tree);
    $manifest = $store->active;
    $hashBox = $manifest->assertions['c2pa.hash.data']->box;
    $payload = $hashBox->offset + 8 + bU32($s, $hashBox->offset + 8);          // after the description box
    $cbor = $payload + 8;
    $enclosing = matrixEnclosing($tree, $cbor);
    $enclosing[] = $payload;                                                   // the cbor box itself

    // the digest: 58 20 <32 bytes> becomes 58 30 / 58 40 <48 or 64 bytes>
    $data = $manifest->assertions['c2pa.hash.data']->data;
    assert(is_array($data) && $data['hash'] instanceof CborBytes && is_array($data['exclusions']) && is_array($data['exclusions'][0]));
    $oldDigest = $data['hash']->bytes;
    $digestAt = strpos($s, "\x58\x20".$oldDigest, $cbor);
    if ($digestAt === false || $digestAt > $hashBox->offset + $hashBox->length) {
        throw new RuntimeException('the data hash is not in its assertion');
    }
    $grown = $digestLength - 32;
    $s = bSplice($s, $digestAt, 2 + 32, pack('CC', 0x58, $digestLength).str_repeat("\0", $digestLength), $enclosing);

    // the algorithm: six characters for six, so nothing moves
    $algAt = strpos($s, "\x66".'sha256', $cbor);
    if ($algAt === false || $algAt > $hashBox->offset + $hashBox->length + $grown) {
        throw new RuntimeException('the assertion does not name sha256');
    }
    $s = bReplace($s, $algAt, "\x66".'sha256', "\x66".$alg);

    // the exclusion covers the whole caBX chunk, which grew with the store
    $exclusions = $data['exclusions'];
    $oldLength = $exclusions[0]['length'];
    $start = $exclusions[0]['start'];
    assert(is_int($oldLength) && is_int($start));
    $lengthAt = strpos($s, "\x19".pack('n', $oldLength), $cbor);
    if ($lengthAt === false) {
        throw new RuntimeException('the exclusion length is not a two-byte CBOR uint');
    }
    $s = bReplace($s, $lengthAt, "\x19".pack('n', $oldLength), "\x19".pack('n', $oldLength + $grown));

    // the digest itself, over the file the store will sit in, minus that exclusion
    $file = pngWithStore($png, $s);
    $digest = hash($alg, substr($file, 0, $start).substr($file, $start + $oldLength + $grown), true);
    $s = bReplace($s, $digestAt + 2, str_repeat("\0", $digestLength), $digest);

    // the claim's hashed URI for the assertion, and the claim re-signed with the same test key
    $boxLength = bU32($s, $hashBox->offset);
    $uri = hash('sha256', substr($s, $hashBox->offset + 8, $boxLength - 8), true);
    $claimAt = strpos($s, $manifest->claimBytes());
    if ($claimAt === false) {
        throw new RuntimeException('the claim bytes moved');
    }
    $oldUri = hash('sha256', substr($extracted->bytes, $hashBox->offset + 8, bU32($extracted->bytes, $hashBox->offset) - 8), true);
    $entryAt = strpos($s, $oldUri, $claimAt);
    if ($entryAt === false) {
        throw new RuntimeException('the claim does not carry the hashed URI for the data hash');
    }
    $s = bReplace($s, $entryAt, substr($s, $entryAt, 32), $uri);

    $claimBytes = substr($s, $claimAt, strlen($manifest->claimBytes()));
    $coseAt = strpos($s, $manifest->signatureBytes());
    if ($coseAt === false) {
        throw new RuntimeException('the COSE moved');
    }
    $coseLength = strlen($manifest->signatureBytes());
    $tbs = $work.'/tbs';
    file_put_contents($tbs, CoseSign1::fromBytes(substr($s, $coseAt, $coseLength))->sigStructure($claimBytes));
    matrixRun(matrixSh('openssl', 'dgst', '-sha256', '-sign', $work.'/certs/es256.pem', '-out', $work.'/sig', $tbs));
    $der = (string) file_get_contents($work.'/sig');
    $p = ord($der[1]) & 0x80 ? 2 + (ord($der[1]) & 0x7F) : 2;
    $rs = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$p + 1]);
        $rs .= str_pad(ltrim(substr($der, $p + 2, $len), "\0"), 32, "\0", STR_PAD_LEFT);
        $p += 2 + $len;
    }
    $cose = substr($s, $coseAt, $coseLength);
    $signatureAt = strrpos($cose, "\x58\x40");
    if ($signatureAt === false) {
        throw new RuntimeException('no 64-byte signature in the COSE');
    }
    $s = bReplace($s, $coseAt + $signatureAt + 2, substr($cose, $signatureAt + 2, 64), $rs);

    $target = $dir."/es256-{$alg}.png";
    file_put_contents($target, pngWithStore($png, $s));
    matrixRun(matrixSh($c2patool, $target).' > '.escapeshellarg("{$oracles}/es256-{$alg}.png.json"));
    matrixRun(matrixSh($c2patool, $target, '--settings', $dir.'/test-roots.settings.json').' > '.escapeshellarg("{$oracles}/es256-{$alg}.png.trusted.json"));
    /** @var array<string, mixed> $json */
    $json = json_decode((string) file_get_contents("{$oracles}/es256-{$alg}.png.trusted.json"), true, 512, JSON_THROW_ON_ERROR);
    $state = $json['validation_state'];
    assert(is_string($state));
    printf("es256-%-8s %8d %-8s %s\n", $alg.'.png', filesize($target), 'Es256', $state);
}
