<?php

declare(strict_types=1);

/*
 * SPEC-070 amendment 2 (AC5, AC6): three signed variants that differ only in
 * the list the active manifest's v3 ingredient assertion files one status in.
 *
 * The base is tests/Fixtures/ingredient-manifest/ingredient-signature-broken.jpg
 * (step 56): its ingredient manifest's claim signature is genuinely broken, and
 * nothing records that, so it reads Invalid with claimSignature.mismatch. Each
 * variant adds that same mismatch, with the same url, to the assertion's
 * validationResults.activeManifest, under one list:
 *
 *   recorded-failure         under failure: acknowledged (C2PA 2.4 §18.16.12.4.1)
 *   recorded-success         under success: not an acknowledgement (§18.16.12.4.3)
 *   recorded-informational   under informational: not one either
 *
 * The assertion keeps its length: the explanations of the recorded successes
 * are shortened, and the new entry's explanation is padded to fill exactly the
 * bytes that frees. So the store keeps its length and the data hash holds. The
 * assertion's hashed URI in the active claim is recomputed and the active claim
 * re-signed with a throw-away P-256 hierarchy; keys live outside the repository
 * for the run and are deleted before it ends (Maurice's decision of 2026-09-21:
 * tooling may sign with throw-away keys; the product never signs).
 *
 * Usage: php bin/make-acknowledged-kind-variants.php <scratch-dir>. Tooling.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Cbor\CborDecoder;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Cose\SignatureVerifier;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
$scratch = $argv[1] ?? null;
if (! is_string($scratch) || ! is_dir($scratch)) {
    fwrite(STDERR, "usage: php bin/make-acknowledged-kind-variants.php <scratch-dir outside the repository>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/acknowledged-kind-keys-'.bin2hex(random_bytes(4));
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

function akRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function akSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

/** The CBOR head for major type $mt and argument $n. */
function akHead(int $mt, int $n): string
{
    $mt <<= 5;

    return match (true) {
        $n < 24 => pack('C', $mt | $n),
        $n < 256 => pack('CC', $mt | 24, $n),
        $n < 65536 => pack('Cn', $mt | 25, $n),
        default => pack('CN', $mt | 26, $n),
    };
}

/** A small CBOR encoder: ints, text, byte strings (CborBytes), null, bool, lists and text-keyed maps in the order given. */
function akCbor(mixed $v): string
{
    return match (true) {
        $v instanceof CborBytes => akHead(2, strlen($v->bytes)).$v->bytes,
        is_string($v) => akHead(3, strlen($v)).$v,
        is_int($v) => $v >= 0 ? akHead(0, $v) : akHead(1, -1 - $v),
        $v === null => "\xf6",
        is_bool($v) => $v ? "\xf5" : "\xf4",
        is_array($v) && array_is_list($v) && $v !== [] => akHead(4, count($v)).implode('', array_map('akCbor', $v)),
        $v === [] => akHead(4, 0),
        is_array($v) => akHead(5, count($v)).implode('', array_map(static fn (int|string $k, mixed $x): string => akCbor((string) $k).akCbor($x), array_keys($v), array_values($v))),
        default => throw new RuntimeException('cannot encode '.get_debug_type($v)),
    };
}

/** A DER ECDSA signature as R‖S of 2 × $curveBytes (what COSE carries). */
function akDerToRs(string $der, int $curveBytes): string
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

/** Sign $claimBytes with the throw-away leaf and pad the COSE_Sign1 to exactly $length bytes. */
function akCose(string $claimBytes, int $length, string $leafDer, string $rootDer, string $keys, string $name): string
{
    $protected = "\xa2\x01\x26\x18\x21\x82".akCbor(new CborBytes($leafDer)).akCbor(new CborBytes($rootDer));
    $draft = "\xd2\x84".akCbor(new CborBytes($protected))."\xa1\x63pad".akCbor(new CborBytes(''))."\xf6".akCbor(new CborBytes(str_repeat("\0", 64)));
    file_put_contents("{$keys}/tbs", CoseSign1::fromBytes($draft)->sigStructure($claimBytes));
    akRun(akSh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/leaf.key", '-out', "{$keys}/sig", "{$keys}/tbs"));
    $signature = akDerToRs((string) file_get_contents("{$keys}/sig"), 32);
    $fixed = 2 + strlen(akCbor(new CborBytes($protected))) + 5 + 3 + 1 + strlen(akCbor(new CborBytes($signature)));
    $padLength = $length - $fixed;
    if ($padLength < 256) {
        throw new RuntimeException("{$name}: the pad would be {$padLength} bytes, too short for a 3-byte head");
    }
    $cose = "\xd2\x84".akCbor(new CborBytes($protected))."\xa1\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength)."\xf6".akCbor(new CborBytes($signature));
    if (strlen($cose) !== $length) {
        throw new RuntimeException("{$name}: COSE is ".strlen($cose)." bytes, not {$length}");
    }
    if (! (new SignatureVerifier)->verify(CoseSign1::fromBytes($cose), $claimBytes)) {
        throw new RuntimeException("{$name}: the new signature does not verify under its own leaf");
    }

    return $cose;
}

/**
 * The JPEG with an edited store of the same length written back into its APP11 pieces: the first 8
 * bytes into the first piece's LBox/TBox, the rest into the pieces' data (SPEC-001; the same rebuild
 * as bin/make-ingredient-manifest-variants.php).
 */
function akApp11(string $jpeg, string $store): string
{
    $out = $jpeg;
    $offset = 0;
    $first = true;
    $p = 2;
    while ($p < strlen($jpeg) - 1) {
        if ($jpeg[$p] !== "\xff") {
            break;
        }
        $marker = ord($jpeg[$p + 1]);
        if ($marker === 0xDA) {
            break;
        }
        $header = unpack('n', substr($jpeg, $p + 2, 2));
        if ($header === false || ! is_int($header[1])) {
            throw new RuntimeException("cannot read the segment length at {$p}");
        }
        $length = $header[1];
        if ($marker === 0xEB) {
            if ($first) {
                $out = substr_replace($out, substr($store, 0, 8), $p + 4 + 8, 8);
                $offset = 8;
                $first = false;
            }
            $data = $length - 2 - 16;
            $take = min($data, strlen($store) - $offset);
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

// ---- the throw-away hierarchy: a P-256 root and a leaf on the C2PA profile ----
$root = dirname(__DIR__);
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
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
CNF;
file_put_contents("{$keys}/ext.cnf", $cnf);
akRun(akSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
akRun(akSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (acknowledged kind)', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'v3_root', '-out', "{$keys}/root.pem"));
akRun(akSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/leaf.key"));
akRun(akSh('openssl', 'req', '-new', '-key', "{$keys}/leaf.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=acknowledged kind', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/leaf.csr"));
akRun(akSh('openssl', 'x509', '-req', '-in', "{$keys}/leaf.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'good', '-out', "{$keys}/leaf.pem"));
$pemToDer = static fn (string $pem): string => (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $pem), true);
$rootPem = (string) file_get_contents("{$keys}/root.pem");
$rootDer = $pemToDer($rootPem);
$leafDer = $pemToDer((string) file_get_contents("{$keys}/leaf.pem"));

$dir = $root.'/tests/Fixtures/acknowledged-kind';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
$storeCfg = (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg');
$full = json_decode((string) file_get_contents($root.'/tests/Fixtures/trust/full.settings.json'), true, 512, JSON_THROW_ON_ERROR);
assert(is_array($full) && is_array($full['trust']) && is_string($full['trust']['trust_anchors']));
// the throw-away root *and* the test hierarchy: the ingredient manifest is signed by the C2PA test
// certificates, and only the recorded list may decide a variant's state
file_put_contents("{$dir}/throw-away-root.settings.json", json_encode([
    'trust' => ['trust_anchors' => $rootPem.$full['trust']['trust_anchors'], 'trust_config' => $storeCfg],
    'verify' => ['verify_trust' => true],
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

// ---- the base: the store, the active manifest, its ingredient assertion ----
$basePath = $root.'/tests/Fixtures/ingredient-manifest/ingredient-signature-broken.jpg';
$jpeg = (string) file_get_contents($basePath);
$stream = fopen($basePath, 'rb');
if ($stream === false) {
    throw new RuntimeException('cannot open the base');
}
$extracted = (new JpegManifestStoreExtractor)->extract($stream);
if ($extracted === null) {
    throw new RuntimeException('no store in the base');
}
$s = $extracted->bytes;
if (akApp11($jpeg, $s) !== $jpeg) {
    throw new RuntimeException('the APP11 rebuild is not byte-exact on the unchanged store');
}
$parsed = ManifestStore::fromTree((new JumbfParser)->parse($s));
$active = $parsed->active;
$box = $active->assertionStore->child('c2pa.ingredient.v3') ?? throw new RuntimeException('no c2pa.ingredient.v3 in the active manifest');
$boxBytes = substr($s, $box->offset, $box->length);
$cborAt = strrpos($boxBytes, 'cbor');
if ($cborAt === false) {
    throw new RuntimeException('no cbor content box in the ingredient assertion');
}
$payloadAt = $box->offset + $cborAt + 4;   // the content box's payload, after LBox and TBox
$payload = substr($s, $payloadAt, $box->offset + $box->length - $payloadAt);
$data = (new CborDecoder)->decode($payload);
assert(is_array($data));
if (akCbor($data) !== $payload) {
    throw new RuntimeException('re-encoding the unchanged assertion does not give its bytes back');
}
$results = $data['validationResults'];
assert(is_array($results) && is_array($results['activeManifest']) && is_array($data['activeManifest']) && is_string($data['activeManifest']['url']));
$ingredientSignature = $data['activeManifest']['url'].'/c2pa.signature';

$claimAt = strpos($s, $active->claimBytes());
$activeCoseAt = strpos($s, $active->signatureBytes());
if ($claimAt === false || $activeCoseAt === false) {
    throw new RuntimeException('no claim or COSE for the active manifest');
}
$oldEntry = null;
foreach ([...$active->claim->createdAssertions, ...$active->claim->gatheredAssertions] as $uri) {
    if (str_ends_with($uri->url, 'c2pa.ingredient.v3')) {
        $oldEntry = $uri->hash->bytes;
    }
}
if ($oldEntry === null) {
    throw new RuntimeException('no c2pa.ingredient.v3 entry in the active claim');
}

foreach (['failure', 'success', 'informational'] as $list) {
    $name = "recorded-{$list}";
    $edited = $data;
    assert(is_array($edited['validationResults']));
    $am = $results['activeManifest'];
    assert(is_array($am['success']));
    // free room: the recorded successes keep their code and url, and lose their explanation (ignored by every reader)
    $am['success'] = array_map(static function (mixed $entry): mixed {
        assert(is_array($entry));
        $entry['explanation'] = 'ok';

        return $entry;
    }, $am['success']);
    assert(is_array($am[$list]));
    $last = count($am[$list]);
    $with = static function (string $explanation) use ($edited, $am, $list, $last, $ingredientSignature): array {
        $am[$list][$last] = ['code' => 'claimSignature.mismatch', 'url' => $ingredientSignature, 'explanation' => $explanation];
        $edited['validationResults']['activeManifest'] = $am;

        return $edited;
    };
    // pad the new entry's explanation until the assertion is as long as it was; a text head grows by
    // one byte at 24 and again at 256, so the first guess may overshoot by up to two
    $text = 'claim signature is not valid';
    $short = strlen($payload) - strlen(akCbor($with($text)));
    if ($short < 0) {
        throw new RuntimeException("{$name}: the edited assertion is ".(-$short).' bytes too long');
    }
    $newPayload = '';
    foreach ([$short, $short - 1, $short - 2] as $pad) {
        $newPayload = akCbor($with($text.str_repeat('.', max(0, $pad))));
        if (strlen($newPayload) === strlen($payload)) {
            break;
        }
    }
    if (strlen($newPayload) !== strlen($payload)) {
        throw new RuntimeException("{$name}: cannot make the assertion exactly ".strlen($payload).' bytes');
    }
    $store = substr($s, 0, $payloadAt).$newPayload.substr($s, $payloadAt + strlen($payload));

    // the active claim's hashed URI for the assertion, then the claim re-signed
    $assertionHash = hash('sha256', substr($store, $box->offset + 8, $box->length - 8), true);
    $entryAt = strpos($store, $oldEntry, $claimAt);
    if ($entryAt === false || $entryAt > $claimAt + strlen($active->claimBytes())) {
        throw new RuntimeException('the hashed URI for the ingredient assertion is not in the claim');
    }
    $store = bReplace($store, $entryAt, $oldEntry, $assertionHash);
    $claimBytes = substr($store, $claimAt, strlen($active->claimBytes()));
    $coseLength = strlen($active->signatureBytes());
    $store = substr($store, 0, $activeCoseAt).akCose($claimBytes, $coseLength, $leafDer, $rootDer, $keys, $name).substr($store, $activeCoseAt + $coseLength);
    if (strlen($store) !== strlen($s)) {
        throw new RuntimeException("{$name}: the store changed length: the data hash would no longer hold");
    }
    file_put_contents("{$dir}/{$name}.jpg", akApp11($jpeg, $store));
    printf("%s  %d bytes  %s\n", hash('sha256', $store), strlen($store), $name);
}
