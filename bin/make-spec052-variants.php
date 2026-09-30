<?php

declare(strict_types=1);

/*
 * SPEC-052: signed variants whose ingredient reference names a hash algorithm, with the hash that
 * algorithm really gives, so a refusal can only come from the name.
 *
 *   crc32b-reference.jpg        c2pa-rs/CACA.jpg, the active ingredient's activeManifest reference
 *                               set to alg crc32b and the crc32b of the ingredient manifest box (AC1)
 *   sha384-reference.jpg        the same with sha384 (AC3)
 *   sha512-reference.jpg        the same with sha512 (AC3)
 *   crc32b-claim-signature.png  redactions/redacted-with-action.png, the parent claim's alg set to
 *                               crc32b and the child's claimSignature reference its crc32b (AC2)
 *
 * A hash of another length changes the ingredient assertion's size. The enclosing boxes are adjusted
 * and the difference is taken up by the pad of the re-signed COSE_Sign1 in the same manifest, so the
 * manifest box and the store keep their length and the asset's data hash still holds. (Between the two
 * splices the store and manifest boxes carry the difference too, so that every step parses.) Every hash above
 * an edit is recomputed: the claim's hashed URI for the ingredient assertion, and the signature.
 *
 * Keys live outside the repository for the run and are deleted before it ends. Decided by Maurice van
 * Loon on 2026-09-21: tooling may sign with throw-away keys; the product never signs.
 *
 * Usage: php bin/make-spec052-variants.php <scratch-dir> <c2patool-0.27.22>
 * Writes tests/Fixtures/spec052/<name>.{jpg,png}, throw-away-root.{pem,settings.json}, and
 *   tests/Fixtures/c2patool/spec052/<name>.json (or .error.txt)
 * Tooling, not product code.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Cose\SignatureVerifier;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
$scratch = $argv[1] ?? '';
$tool = $argv[2] ?? '';
$version = static function (string $tool): string {
    $lines = [];
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return trim(implode(' ', $lines));
};
if (! is_dir($scratch) || ! is_file($tool) || $version($tool) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-spec052-variants.php <scratch-dir outside the repository> <c2patool-0.27.22>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/spec052-keys-'.bin2hex(random_bytes(4));
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

function s52Run(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function s52Sh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

/** A CBOR byte string. */
function s52Bstr(string $bytes): string
{
    $n = strlen($bytes);

    return match (true) {
        $n < 24 => chr(0x40 | $n),
        $n < 256 => "\x58".chr($n),
        default => "\x59".pack('n', $n),
    }.$bytes;
}

/** A DER ECDSA signature as R‖S of 2 × $curveBytes (what COSE carries). */
function s52DerToRs(string $der, int $curveBytes): string
{
    $p = ord($der[1]) & 0x80 ? 2 + (ord($der[1]) & 0x7F) : 2;
    $rs = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$p + 1]);
        $rs .= str_pad(ltrim(substr($der, $p + 2, $len), "\0"), $curveBytes, "\0", STR_PAD_LEFT);
        $p += 2 + $len;
    }

    return $rs;
}

function s52Parse(string $store): ManifestStore
{
    return ManifestStore::fromTree((new JumbfParser)->parse($store));
}

// ---- the throw-away hierarchy: a P-256 root and a leaf on the C2PA profile ----
$root = dirname(__DIR__);
file_put_contents("{$keys}/ext.cnf", <<<'CNF'
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
CNF);
s52Run(s52Sh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
s52Run(s52Sh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (SPEC-052)', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'v3_root', '-out', "{$keys}/root.pem"));
s52Run(s52Sh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/leaf.key"));
s52Run(s52Sh('openssl', 'req', '-new', '-key', "{$keys}/leaf.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=SPEC-052 variants', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/leaf.csr"));
s52Run(s52Sh('openssl', 'x509', '-req', '-in', "{$keys}/leaf.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'good', '-out', "{$keys}/leaf.pem"));
$pemToDer = static fn (string $pem): string => (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $pem), true);
$rootPem = (string) file_get_contents("{$keys}/root.pem");
$rootDer = $pemToDer($rootPem);
$leafDer = $pemToDer((string) file_get_contents("{$keys}/leaf.pem"));

$dir = $root.'/tests/Fixtures/spec052';
$oracles = $root.'/tests/Fixtures/c2patool/spec052';
foreach ([$dir, $oracles] as $d) {
    if (! is_dir($d) && ! mkdir($d, 0o755, true)) {
        throw new RuntimeException("cannot create {$d}");
    }
}
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
$full = json_decode((string) file_get_contents($root.'/tests/Fixtures/trust/full.settings.json'), true, 512, JSON_THROW_ON_ERROR);
assert(is_array($full) && is_array($full['trust']) && is_string($full['trust']['trust_anchors']));
// the throw-away root and the test hierarchy: CACA's ingredient manifest is signed by the C2PA test
// certificates, and only the fault under test may make a variant Invalid
file_put_contents("{$dir}/throw-away-root.settings.json", json_encode([
    'trust' => ['trust_anchors' => $rootPem.$full['trust']['trust_anchors'], 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')],
    'verify' => ['verify_trust' => true],
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

/**
 * $manifest's claim signed again with the throw-away leaf, the COSE_Sign1 padded to $length bytes and
 * written in place of the old one; the signature box and its cbor box grow or shrink with it.
 */
function s52Resign(string $store, string $label, int $length, string $leafDer, string $rootDer, string $keys): string
{
    $manifest = s52Parse($store)->manifests[$label];
    $claimBytes = $manifest->claimBytes();
    $sigBox = $manifest->resolve('self#jumbf=c2pa.signature');
    $cborBox = $sigBox->contentBoxes()[0];
    $old = $manifest->signatureBytes();
    if (substr($store, $cborBox->offset + 8, strlen($old)) !== $old) {
        throw new RuntimeException("{$label}: the COSE_Sign1 is not in its cbor box");
    }

    $protected = "\xa2\x01\x26\x18\x21\x82".s52Bstr($leafDer).s52Bstr($rootDer);
    $draft = "\xd2\x84".s52Bstr($protected)."\xa1\x63pad\x40\xf6".s52Bstr(str_repeat("\0", 64));
    file_put_contents("{$keys}/tbs", CoseSign1::fromBytes($draft)->sigStructure($claimBytes));
    s52Run(s52Sh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/leaf.key", '-out', "{$keys}/sig", "{$keys}/tbs"));
    $signature = s52DerToRs((string) file_get_contents("{$keys}/sig"), 32);
    $fixed = 2 + strlen(s52Bstr($protected)) + 5 + 3 + 1 + strlen(s52Bstr($signature));
    $padLength = $length - $fixed;
    if ($padLength < 256) {
        throw new RuntimeException("{$label}: the pad would be {$padLength} bytes, too short for a 3-byte head");
    }
    $cose = "\xd2\x84".s52Bstr($protected)."\xa1\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength)."\xf6".s52Bstr($signature);
    if (strlen($cose) !== $length || ! (new SignatureVerifier)->verify(CoseSign1::fromBytes($cose), $claimBytes)) {
        throw new RuntimeException("{$label}: the new COSE_Sign1 is the wrong length or does not verify");
    }

    return bSplice($store, $cborBox->offset + 8, strlen($old), $cose, [0, $manifest->box->offset, $sigBox->offset, $cborBox->offset]);
}

/**
 * The active manifest's c2pa.ingredient.v3 with each named reference given a new alg (same length as
 * "sha256") and hash, the claim's hashed URI for the assertion recomputed, and the claim signed again
 * with a COSE_Sign1 that takes up the size difference.
 *
 * @param  array<string, array{0: string, 1: string}>  $edits  reference field => [alg, hash bytes]
 */
function s52EditReferences(string $store, array $edits, string $leafDer, string $rootDer, string $keys): string
{
    $parsed = s52Parse($store);
    $active = $parsed->active;
    $assertion = $active->assertions['c2pa.ingredient.v3'] ?? throw new RuntimeException('no c2pa.ingredient.v3 in the active manifest');
    $box = $assertion->box;
    $cbor = $box->contentBoxes()[0];
    $data = $assertion->data;
    assert(is_array($data));
    $oldUriHash = null;
    foreach ([...$active->claim->createdAssertions, ...$active->claim->gatheredAssertions] as $uri) {
        if (str_ends_with($uri->url, 'c2pa.ingredient.v3')) {
            $oldUriHash = $uri->hash->bytes;
        }
    }
    if ($oldUriHash === null) {
        throw new RuntimeException('no hashed URI for c2pa.ingredient.v3 in the active claim');
    }
    $oldCoseLength = strlen($active->signatureBytes());

    $delta = 0;
    foreach ($edits as $field => [$alg, $hash]) {
        if (strlen($alg) !== 6) {
            throw new RuntimeException("{$alg}: the edit replaces sha256 in place and needs six letters");
        }
        $reference = $data[$field] ?? null;
        if (! is_array($reference) || ($reference['alg'] ?? null) !== 'sha256' || ! ($reference['hash'] ?? null) instanceof CborBytes || strlen($field) > 23) {
            throw new RuntimeException("{$field}: not a sha256 hashed URI with a short key");
        }
        $from = $cbor->offset + 8;
        $to = $cbor->offset + $cbor->length + $delta;
        $key = bFind($store, chr(0x60 + strlen($field)).$field, $from, $to);
        $algAt = bFind($store, "\x63alg\x66sha256", $key, $to) + 5;
        $store = bReplace($store, $algAt, 'sha256', $alg);
        $oldHash = s52Bstr($reference['hash']->bytes);
        $hashAt = bFind($store, $oldHash, $key, $to);
        $store = bSplice($store, $hashAt, strlen($oldHash), s52Bstr($hash), [0, $active->box->offset, $active->assertionStore->offset, $box->offset, $cbor->offset]);
        $delta += strlen(s52Bstr($hash)) - strlen($oldHash);
    }

    // the claim's hashed URI for the assertion: the same 32 bytes, found by their old value
    $active = s52Parse($store)->active;
    $newUriHash = hash('sha256', $active->assertions['c2pa.ingredient.v3']->box->payload(), true);
    $claimAt = strpos($store, $active->claimBytes());
    if ($claimAt === false) {
        throw new RuntimeException('no claim bytes for the active manifest');
    }
    $store = bReplace($store, bFind($store, $oldUriHash, $claimAt, $claimAt + strlen($active->claimBytes())), $oldUriHash, $newUriHash);

    return s52Resign($store, $active->label, $oldCoseLength - $delta, $leafDer, $rootDer, $keys);
}

/** The JPEG with a store of the same length written back into its APP11 pieces (step 56's rebuild). */
function s52App11(string $jpeg, string $store): string
{
    $out = $jpeg;
    $offset = 0;
    $first = true;
    $p = 2;
    while ($p < strlen($jpeg) - 1 && $jpeg[$p] === "\xff" && ord($jpeg[$p + 1]) !== 0xDA) {
        $length = bU32("\0\0".substr($jpeg, $p + 2, 2), 0);
        if (ord($jpeg[$p + 1]) === 0xEB) {
            if ($first) {
                $out = substr_replace($out, substr($store, 0, 8), $p + 4 + 8, 8);
                $offset = 8;
                $first = false;
            }
            $take = min($length - 2 - 16, strlen($store) - $offset);
            $out = substr_replace($out, substr($store, $offset, $take), $p + 4 + 16, $take);
            $offset += $take;
        }
        $p += 2 + $length;
    }
    if ($offset !== strlen($store)) {
        throw new RuntimeException("the JPEG carried {$offset} store bytes, not ".strlen($store));
    }

    return $out;
}

$written = [];

// ============================================================================
// AC1 and AC3, from c2pa-rs/CACA.jpg
// ============================================================================
$jpeg = (string) file_get_contents($root.'/tests/Fixtures/c2pa-rs/CACA.jpg');
$stream = fopen($root.'/tests/Fixtures/c2pa-rs/CACA.jpg', 'rb');
$extracted = $stream === false ? null : (new JpegManifestStoreExtractor)->extract($stream);
if ($extracted === null) {
    throw new RuntimeException('no store in CACA.jpg');
}
$s = $extracted->bytes;
if (s52App11($jpeg, $s) !== $jpeg) {
    throw new RuntimeException('the APP11 rebuild is not byte-exact on the unchanged store');
}
$parsed = s52Parse($s);
$ingredientData = $parsed->active->assertions['c2pa.ingredient.v3']->data;
assert(is_array($ingredientData) && is_array($ingredientData['activeManifest']) && is_string($ingredientData['activeManifest']['url']));
$ingredientBox = $parsed->manifests[substr($ingredientData['activeManifest']['url'], strlen('self#jumbf=/c2pa/'))]->box;
foreach (['crc32b', 'sha384', 'sha512'] as $alg) {
    $edited = s52EditReferences($s, ['activeManifest' => [$alg, hash($alg, $ingredientBox->payload(), true)]], $leafDer, $rootDer, $keys);
    if (strlen($edited) !== strlen($s)) {
        throw new RuntimeException("{$alg}: the store changed length");
    }
    file_put_contents("{$dir}/{$alg}-reference.jpg", s52App11($jpeg, $edited));
    $written[] = "{$alg}-reference.jpg";
}

// ============================================================================
// AC2, from redactions/redacted-with-action.png
// ============================================================================
$png = (string) file_get_contents($root.'/tests/Fixtures/redactions/redacted-with-action.png');
$pngStream = fopen($root.'/tests/Fixtures/redactions/redacted-with-action.png', 'rb');
$pngExtracted = $pngStream === false ? null : (new PngManifestStoreExtractor)->extract($pngStream);
if ($pngExtracted === null || pngWithStore($png, $pngExtracted->bytes) !== $png) {
    throw new RuntimeException('the caBX rebuild is not byte-exact on the unchanged store');
}
$s = $pngExtracted->bytes;
$parsed = s52Parse($s);
$parent = array_values(array_filter($parsed->manifests, static fn (Manifest $m): bool => $m->redacted !== []))[0]
    ?? throw new RuntimeException('no manifest with redactions');
// the parent claim's alg, in place, and the parent signed again at its old length
$claimAt = strpos($s, $parent->claimBytes());
if ($claimAt === false) {
    throw new RuntimeException('no claim bytes for the parent manifest');
}
$algValue = bMapPairs($s, $claimAt)['alg'][1] ?? throw new RuntimeException('the parent claim has no alg');
$s = bReplace($s, $algValue, "\x66sha256", "\x66crc32b");
$s = s52Resign($s, $parent->label, strlen($parent->signatureBytes()), $leafDer, $rootDer, $keys);
$parent = s52Parse($s)->manifests[$parent->label];
$edited = s52EditReferences($s, [
    // the box hash is not tried for a redacted manifest, but it is kept true for the new parent box
    'activeManifest' => ['sha256', hash('sha256', $parent->box->payload(), true)],
    'claimSignature' => ['crc32b', hash('crc32b', $parent->resolve($parent->claim->signatureUri)->payload(), true)],
], $leafDer, $rootDer, $keys);
if (strlen($edited) !== strlen($s)) {
    throw new RuntimeException('crc32b-claim-signature: the store changed length');
}
file_put_contents("{$dir}/crc32b-claim-signature.png", pngWithStore($png, $edited));
$written[] = 'crc32b-claim-signature.png';

// ============================================================================
// the oracle
// ============================================================================
foreach ($written as $name) {
    $lines = [];
    $exit = 0;
    exec(s52Sh($tool, "{$dir}/{$name}", '--settings', "{$dir}/throw-away-root.settings.json").' 2>&1', $lines, $exit);
    $base = $oracles.'/'.pathinfo($name, PATHINFO_FILENAME);
    foreach (glob("{$base}.*") ?: [] as $stale) {
        unlink($stale);
    }
    file_put_contents($base.($exit === 0 ? '.json' : '.error.txt'), implode("\n", $lines)."\n");
    printf("%-28s %s\n", $name, hash_file('sha256', "{$dir}/{$name}"));
}
