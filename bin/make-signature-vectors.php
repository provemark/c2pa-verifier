<?php

declare(strict_types=1);

/*
 * SPEC-009: produces the synthetic signature vectors under
 * tests/Fixtures/signatures/ — the algorithms and key kinds the four real
 * fixtures do not cover. Throw-away keys are generated in a temporary
 * directory with the OpenSSL command line, a self-signed certificate is
 * made for each, the PNG fixture's real claim bytes are signed over a
 * Sig_structure built by CoseSign1::sigStructure(), every signature is
 * checked with `openssl dgst -verify` (or `pkeyutl -verify`) before it is
 * recorded, and the keys are deleted. Only public certificates, signatures
 * and the bytes they cover reach the repository. Keys are random, so the
 * vectors differ per run; the committed files are the data, and their
 * SHA-256s are in the README. Run from the repository root. Tooling, not
 * product code — this is the one place the project calls `openssl` as a
 * process, and it is never the verifier.
 */

require __DIR__.'/../vendor/autoload.php';

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;

function run(string $command): string
{
    $lines = [];
    $status = 1;
    exec($command.' 2>&1', $lines, $status);
    if ($status !== 0) {
        throw new RuntimeException("failed ({$status}): {$command}\n".implode("\n", $lines));
    }

    return implode("\n", $lines);
}

/** A CBOR head, shortest form (major type 0..5). */
function head(int $majorType, int $n): string
{
    $mt = $majorType << 5;

    return match (true) {
        $n < 24 => pack('C', $mt | $n),
        $n < 256 => pack('CC', $mt | 24, $n),
        $n < 65536 => pack('Cn', $mt | 25, $n),
        default => pack('CN', $mt | 26, $n),
    };
}

/** A negative CBOR integer (major type 1). */
function negative(int $value): string
{
    return head(1, -1 - $value);
}

/** DER ECDSA-Sig-Value → R||S of 2 × $bytes. */
function derToRs(string $der, int $bytes): string
{
    $p = 2 + (ord($der[1]) & 0x80 ? (ord($der[1]) & 0x7F) : 0);
    $ints = [];
    for ($i = 0; $i < 2; $i++) {
        if ($der[$p] !== "\x02") {
            throw new RuntimeException('not an INTEGER');
        }
        $len = ord($der[$p + 1]);
        $int = ltrim(substr($der, $p + 2, $len), "\0");
        $ints[] = str_pad($int, $bytes, "\0", STR_PAD_LEFT);
        $p += 2 + $len;
    }

    return implode('', $ints);
}

/** The protected header {1: alg, 33: [cert]} as bytes. */
function protectedHeader(int $alg, string $certDer): string
{
    return "\xa2\x01".negative($alg)."\x18\x21\x81".head(2, strlen($certDer)).$certDer;
}

$root = dirname(__DIR__);
$stream = fopen($root.'/tests/Fixtures/fixture-signed.png', 'rb');
if ($stream === false) {
    throw new RuntimeException('cannot open the PNG fixture');
}
$extracted = (new PngManifestStoreExtractor)->extract($stream);
if ($extracted === null) {
    throw new RuntimeException('no store');
}
$manifest = ManifestStore::fromTree((new JumbfParser)->parse($extracted->bytes))->active;
$claim = $manifest->claimBytes();
$realCose = CoseSign1::fromBytes($manifest->signatureBytes());

$tmp = sys_get_temp_dir().'/c2pa-vectors-'.bin2hex(random_bytes(6));
if (! mkdir($tmp, 0700)) {
    throw new RuntimeException("cannot create {$tmp}");
}
$dir = $root.'/tests/Fixtures/signatures';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}

/**
 * @param  array{genkey: string, alg: int, hash: string, kind: string, expect: string, why: string, sign?: string, verify?: string, rsBytes?: int}  $v
 */
function makeVector(string $name, array $v, string $tmp, string $dir, string $claim): void
{
    $key = "{$tmp}/{$name}.key";
    $cert = "{$tmp}/{$name}.crt";
    run($v['genkey'].' -out '.escapeshellarg($key));
    run('openssl req -x509 -new -key '.escapeshellarg($key).' -subj /CN=SPEC-009-'.escapeshellarg($name).' -days 30 -out '.escapeshellarg($cert));
    $pem = (string) file_get_contents($cert);
    $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $pem), true);
    if ($der === false) {
        throw new RuntimeException('bad PEM');
    }
    $protected = protectedHeader($v['alg'], $der);
    $cose = new CoseSign1($protected, [], [], '', $v['alg'], [new CborBytes($der)], true, null, []);
    $message = $cose->sigStructure($claim);
    $msgFile = "{$tmp}/{$name}.msg";
    $sigFile = "{$tmp}/{$name}.sig";
    file_put_contents($msgFile, $message);

    $sign = $v['sign'] ?? "openssl dgst -{$v['hash']} -sign %key% -out %sig% %msg%";
    $verify = $v['verify'] ?? "openssl dgst -{$v['hash']} -verify %pub% -signature %sig% %msg%";
    $pub = "{$tmp}/{$name}.pub";
    run('openssl x509 -in '.escapeshellarg($cert).' -pubkey -noout -out '.escapeshellarg($pub));
    $fill = static fn (string $t): string => strtr($t, ['%key%' => escapeshellarg($key), '%pub%' => escapeshellarg($pub), '%sig%' => escapeshellarg($sigFile), '%msg%' => escapeshellarg($msgFile)]);
    run($fill($sign));
    $check = trim(run($fill($verify)));
    if (! str_contains($check, 'Verified OK') && ! str_contains($check, 'Signature Verified Successfully')) {
        throw new RuntimeException("{$name}: OpenSSL does not verify its own signature: {$check}");
    }
    $signature = (string) file_get_contents($sigFile);
    if (isset($v['rsBytes'])) {
        $signature = derToRs($signature, $v['rsBytes']);
    }

    $record = [
        'name' => $name,
        'purpose' => $v['why'],
        'alg' => $v['alg'],
        'key' => $v['kind'],
        'expect' => $v['expect'],
        'made_with' => ['genkey' => $v['genkey'], 'sign' => $sign, 'verified_with' => $verify],
        'claim_sha256' => hash('sha256', $claim),
        'claim_hex' => bin2hex($claim),
        'protected_hex' => bin2hex($protected),
        'signature_hex' => bin2hex($signature),
        'leaf_pem' => $pem,
    ];
    file_put_contents("{$dir}/{$name}.json", json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    printf("%-30s alg %-6d %-28s expect %-9s sig %4d bytes\n", $name, $v['alg'], $v['kind'], $v['expect'], strlen($signature));
}

$ec = static fn (string $curve): string => 'openssl ecparam -genkey -name '.$curve;
$rsa = static fn (int $bits): string => 'openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:'.$bits;
$pss = static fn (string $hash): string => "openssl dgst -{$hash} -sign %key% -sigopt rsa_padding_mode:pss -sigopt rsa_pss_saltlen:digest -out %sig% %msg%";
$pssVerify = static fn (string $hash): string => "openssl dgst -{$hash} -verify %pub% -sigopt rsa_padding_mode:pss -sigopt rsa_pss_saltlen:digest -signature %sig% %msg%";

$vectors = [
    // ---- ECDSA: the curves the fixtures lack, and a curve crossing §13.2.1 allows ----
    'es384-p384' => ['genkey' => $ec('secp384r1'), 'alg' => -35, 'hash' => 'sha384', 'kind' => 'EC P-384', 'rsBytes' => 48, 'expect' => 'true', 'why' => 'ES384 under a P-384 key'],
    'es512-p521' => ['genkey' => $ec('secp521r1'), 'alg' => -36, 'hash' => 'sha512', 'kind' => 'EC P-521', 'rsBytes' => 66, 'expect' => 'true', 'why' => 'ES512 under a P-521 key'],
    'es256-p384' => ['genkey' => $ec('secp384r1'), 'alg' => -7, 'hash' => 'sha256', 'kind' => 'EC P-384', 'rsBytes' => 48, 'expect' => 'true', 'why' => 'ES256 under a P-384 key: allowed by C2PA 2.4 §13.2.1 ("shall accept keys on any of these curves for all ECDSA algorithm choices")'],
    'es256-p256k1' => ['genkey' => $ec('secp256k1'), 'alg' => -7, 'hash' => 'sha256', 'kind' => 'EC secp256k1', 'rsBytes' => 32, 'expect' => 'exception', 'why' => 'ES256 under a secp256k1 key: not P-256/384/521, so the key does not fit (§13.2.1)'],
    // ---- RSASSA-PSS under ordinary rsaEncryption keys ----
    'ps256-rsa2048' => ['genkey' => $rsa(2048), 'alg' => -37, 'hash' => 'sha256', 'kind' => 'RSA 2048', 'sign' => $pss('sha256'), 'verify' => $pssVerify('sha256'), 'expect' => 'true', 'why' => 'PS256 under a plain RSA key: the EMSA-PSS path'],
    'ps256-rsa2048-v15' => ['genkey' => $rsa(2048), 'alg' => -37, 'hash' => 'sha256', 'kind' => 'RSA 2048', 'expect' => 'false', 'why' => 'alg says PS256 but the signature is PKCS#1 v1.5: what a verifier that calls openssl_verify would wrongly accept'],
    'ps384-rsa3072' => ['genkey' => $rsa(3072), 'alg' => -38, 'hash' => 'sha384', 'kind' => 'RSA 3072', 'sign' => $pss('sha384'), 'verify' => $pssVerify('sha384'), 'expect' => 'true', 'why' => 'PS384 under a plain RSA key'],
    'ps512-rsa4096' => ['genkey' => $rsa(4096), 'alg' => -39, 'hash' => 'sha512', 'kind' => 'RSA 4096', 'sign' => $pss('sha512'), 'verify' => $pssVerify('sha512'), 'expect' => 'true', 'why' => 'PS512 under a plain RSA key'],
    'ps256-rsa1024' => ['genkey' => $rsa(1024), 'alg' => -37, 'hash' => 'sha256', 'kind' => 'RSA 1024', 'sign' => $pss('sha256'), 'verify' => $pssVerify('sha256'), 'expect' => 'exception', 'why' => 'a valid PSS signature under a 1024-bit key: below the 2048-bit minimum of §13.2.1, so the key does not fit'],
    'eddsa-rsa' => ['genkey' => $rsa(2048), 'alg' => -8, 'hash' => 'sha256', 'kind' => 'RSA 2048', 'expect' => 'exception', 'why' => 'alg says EdDSA but the key is RSA: the key does not fit'],
    // ---- EdDSA ----
    'eddsa-ed25519' => ['genkey' => 'openssl genpkey -algorithm ed25519', 'alg' => -8, 'hash' => '', 'kind' => 'Ed25519', 'sign' => 'openssl pkeyutl -sign -inkey %key% -rawin -in %msg% -out %sig%', 'verify' => 'openssl pkeyutl -verify -pubin -inkey %pub% -rawin -in %msg% -sigfile %sig%', 'expect' => 'true', 'why' => 'EdDSA (Ed25519)'],
    // ---- an unsupported algorithm ----
    'alg-unsupported' => ['genkey' => $ec('prime256v1'), 'alg' => -65535, 'hash' => 'sha256', 'kind' => 'EC P-256', 'rsBytes' => 32, 'expect' => 'exception', 'why' => 'alg -65535 is not in C2PA 2.4 §13.2.1'],
    // ---- an id-RSASSA-PSS key restricted to SHA-256, asked to verify as PS384 ----
    'ps384-under-rsapss-sha256-key' => ['genkey' => 'openssl genpkey -algorithm RSA-PSS -pkeyopt rsa_keygen_bits:2048 -pkeyopt rsa_pss_keygen_md:sha256 -pkeyopt rsa_pss_keygen_mgf1_md:sha256 -pkeyopt rsa_pss_keygen_saltlen:32', 'alg' => -38, 'hash' => 'sha256', 'kind' => 'RSA-PSS 2048 (params: SHA-256, salt 32)', 'sign' => $pss('sha256'), 'verify' => $pssVerify('sha256'), 'expect' => 'false', 'why' => 'the key\'s PSS parameters say SHA-256, the claim says PS384: does OpenSSL refuse? (measured in step 19)'],
];

foreach ($vectors as $name => $v) {
    makeVector($name, $v, $tmp, $dir, $claim);
}

// ---- the PNG's real chain reversed: the intermediate's key cannot verify the signature ----
$reversedChain = array_reverse($realCose->chain);
$protected = "\xa2\x01".negative(-7)."\x18\x21\x82".implode('', array_map(static fn (CborBytes $c): string => head(2, strlen($c->bytes)).$c->bytes, $reversedChain));
file_put_contents("{$dir}/chain-reversed.json", json_encode([
    'name' => 'chain-reversed',
    'purpose' => 'the PNG fixture\'s real signature with its x5chain reversed: the leaf must be read from chain[0], which is now the intermediate, whose key does not verify',
    'alg' => -7, 'key' => 'EC P-256 (the intermediate CA\'s)', 'expect' => 'false',
    'made_with' => ['source' => 'tests/Fixtures/fixture-signed.png, chain reversed, signature unchanged'],
    'claim_sha256' => hash('sha256', $claim), 'claim_hex' => bin2hex($claim),
    'protected_hex' => bin2hex($protected), 'signature_hex' => bin2hex($realCose->signature),
    'leaf_pem' => "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($reversedChain[0]->bytes), 64, "\n")."-----END CERTIFICATE-----\n",
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo "chain-reversed                 alg -7     EC P-256 (intermediate)      expect false\n";

// ---- the keys go ----
foreach (glob("{$tmp}/*") ?: [] as $file) {
    unlink($file);
}
rmdir($tmp);
echo 'keys deleted: '.(is_dir($tmp) ? 'NO' : 'yes')."\n";
foreach (glob("{$dir}/*.json") ?: [] as $file) {
    printf("%s  %s\n", hash_file('sha256', $file), basename($file));
}
