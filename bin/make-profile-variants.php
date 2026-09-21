<?php

declare(strict_types=1);

/*
 * SPEC-015 (step 33): builds the certificate-profile variants. A throw-away
 * hierarchy is made for the run — a P-256 root and, per variant, a leaf that
 * departs from the C2PA 2.4 §14.5 profile in exactly one way — and the PNG
 * fixture's manifest is re-signed with each leaf: a new protected header
 * (alg, x5chain = leaf + root), a new signature over the Sig_structure, and
 * the unprotected pad resized so that the store keeps its length and nothing
 * but the signature box changes. Keys live in a directory outside the
 * repository for the duration of the run (the openssl CLI needs files) and
 * are deleted before the script ends; only public certificates, the
 * re-signed variants and the settings that name the root as anchor are
 * written under tests/. Decided by Maurice van Loon on 2026-09-21: tooling
 * may sign with throw-away keys; the product never signs.
 *
 * Usage: php bin/make-profile-variants.php <scratch-dir>. Tooling, not
 * product code.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Cose\SignatureVerifier;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;

$scratch = $argv[1] ?? null;
if (! is_string($scratch) || ! is_dir($scratch)) {
    fwrite(STDERR, "usage: php bin/make-profile-variants.php <scratch-dir outside the repository>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/profile-keys-'.bin2hex(random_bytes(4));
if (! mkdir($keys, 0700)) {
    throw new RuntimeException("cannot create {$keys}");
}
// the keys go, whatever happens
register_shutdown_function(static function () use ($keys): void {
    foreach (glob("{$keys}/*") ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($keys)) {
        rmdir($keys);
        echo "keys deleted: {$keys}\n";
    }
});

function run(string $command): string
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }

    return implode("\n", $lines);
}

function sh(string ...$parts): string
{
    return implode(' ', array_map(escapeshellarg(...), $parts));
}

/**
 * CBOR head for a major type and argument, shortest form.
 *
 * @param  int<0, 7>  $mt
 */
function head(int $mt, int $n): string
{
    if ($n < 0) {
        throw new RuntimeException('a CBOR argument cannot be negative');
    }
    $ib = $mt << 5;

    return match (true) {
        $n < 24 => pack('C', $ib | $n),
        $n < 256 => pack('CC', $ib | 24, $n),
        $n < 65536 => pack('Cn', $ib | 25, $n),
        default => pack('CN', $ib | 26, $n),
    };
}

function bstr(string $b): string
{
    return head(2, strlen($b)).$b;
}

/** DER ECDSA-Sig-Value → R || S, each $curveBytes wide. */
function derToRs(string $der, int $curveBytes): string
{
    $p = 2;
    if (ord($der[1]) & 0x80) {
        $p = 2 + (ord($der[1]) & 0x7F);
    }
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

// ---- the extension sections, one per variant ----
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
[no-digital-signature]
basicConstraints = critical, CA:FALSE
keyUsage = critical, nonRepudiation
extendedKeyUsage = emailProtection
[ca-as-leaf]
basicConstraints = critical, CA:TRUE
keyUsage = critical, digitalSignature, keyCertSign
extendedKeyUsage = emailProtection
[eku-outside-list]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature
extendedKeyUsage = codeSigning
[eku-any]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature
extendedKeyUsage = anyExtendedKeyUsage
[eku-mixed]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature
extendedKeyUsage = timeStamping, emailProtection
[eku-c2pa]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature
extendedKeyUsage = 1.3.6.1.4.1.62558.2.1
[no-eku]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature
CNF;
file_put_contents("{$keys}/ext.cnf", $cnf);

// name => [key kind, extension section (null = v1, no extensions), COSE alg, 'notBefore:notAfter' or null]
$variants = [
    'good' => ['p256', 'good', -7, null],
    'no-digital-signature' => ['p256', 'no-digital-signature', -7, null],
    'expired' => ['p256', 'good', -7, '20240101000000Z:20250101000000Z'],
    'ca-as-leaf' => ['p256', 'ca-as-leaf', -7, null],
    'eku-outside-list' => ['p256', 'eku-outside-list', -7, null],
    'eku-any' => ['p256', 'eku-any', -7, null],
    'eku-mixed' => ['p256', 'eku-mixed', -7, null],
    'eku-c2pa' => ['p256', 'eku-c2pa', -7, null],
    'no-eku' => ['p256', 'no-eku', -7, null],
    'v1' => ['p256', null, -7, null],
    'rsa-1024' => ['rsa1024', 'good', -37, null],
    'curve-secp256k1' => ['secp256k1', 'good', -7, null],
];

// ---- the throw-away root ----
run(sh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
run(sh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'v3_root', '-out', "{$keys}/root.pem"));
$rootPem = (string) file_get_contents("{$keys}/root.pem");
$rootDer = (string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $rootPem) ?? '', true);

// ---- the fixture's store and the signature box (step 31 offsets) ----
$root = dirname(__DIR__);
$png = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.png');
$stream = fopen($root.'/tests/Fixtures/fixture-signed.png', 'rb');
if ($stream === false) {
    throw new RuntimeException('cannot open the PNG fixture');
}
$extracted = (new PngManifestStoreExtractor)->extract($stream);
if ($extracted === null || strlen($extracted->bytes) !== 46025) {
    throw new RuntimeException('the PNG store is not the one measured in step 09');
}
$s = $extracted->bytes;
$COSE = 33728;
$COSE_LENGTH = 12305 - 8;   // the signature cbor box's contents
if (substr($s, $COSE, 2) !== "\xd2\x84") {
    throw new RuntimeException('the COSE_Sign1 is not where step 31 measured it');
}
$claimBytes = ManifestStore::fromTree((new JumbfParser)->parse($s))->active->claimBytes();

$dir = $root.'/tests/Fixtures/profile';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
$settings = ['trust' => ['trust_anchors' => $rootPem, 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')], 'verify' => ['verify_trust' => true]];
file_put_contents("{$dir}/throw-away-root.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

$verifier = new SignatureVerifier;
foreach ($variants as $name => [$kind, $section, $alg, $notAfter]) {
    $key = "{$keys}/{$name}.key";
    match ($kind) {
        'p256' => run(sh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', $key)),
        'secp256k1' => run(sh('openssl', 'ecparam', '-genkey', '-name', 'secp256k1', '-noout', '-out', $key)),
        'rsa1024' => run(sh('openssl', 'genrsa', '-out', $key, '1024')),
    };
    run(sh('openssl', 'req', '-new', '-key', $key, '-subj', "/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=profile {$name}", '-config', "{$keys}/ext.cnf", '-out', "{$keys}/{$name}.csr"));
    $sign = ['openssl', 'x509', '-req', '-in', "{$keys}/{$name}.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-out', "{$keys}/{$name}.pem"];
    if ($section !== null) {
        $sign = [...$sign, '-extfile', "{$keys}/ext.cnf", '-extensions', $section];
    }
    if ($notAfter !== null) {
        [$from, $to] = explode(':', $notAfter);
        $sign = [...$sign, '-not_before', $from, '-not_after', $to];
    }
    run(sh(...$sign));
    $leafPem = (string) file_get_contents("{$keys}/{$name}.pem");
    $leafDer = (string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $leafPem) ?? '', true);
    file_put_contents("{$dir}/{$name}.leaf.pem", $leafPem);

    // the protected header: {1: alg, 33: [leaf, root]}
    $protected = "\xa2\x01".head(1, -1 - $alg)."\x18\x21\x82".bstr($leafDer).bstr($rootDer);   // every C2PA alg is negative
    // a first COSE with an empty signature, to borrow sigStructure() from the verifier's own parser
    $draft = "\xd2\x84".bstr($protected)."\xa1\x63pad".bstr('')."\xf6".bstr(str_repeat("\0", 64));
    $sigStructure = CoseSign1::fromBytes($draft)->sigStructure($claimBytes);
    file_put_contents("{$keys}/{$name}.tbs", $sigStructure);
    if ($alg === -37) {
        run(sh('openssl', 'dgst', '-sha256', '-sigopt', 'rsa_padding_mode:pss', '-sigopt', 'rsa_pss_saltlen:32', '-sigopt', 'rsa_mgf1_md:sha256', '-sign', $key, '-out', "{$keys}/{$name}.sig", "{$keys}/{$name}.tbs"));
        $signature = (string) file_get_contents("{$keys}/{$name}.sig");
    } else {
        run(sh('openssl', 'dgst', '-sha256', '-sign', $key, '-out', "{$keys}/{$name}.sig", "{$keys}/{$name}.tbs"));
        $signature = derToRs((string) file_get_contents("{$keys}/{$name}.sig"), 32);
    }
    // the pad fills the box to the original length: 2 + protected + (a1 63 pad + 59 xxxx + pad) + f6 + signature
    $fixed = 2 + strlen(bstr($protected)) + 5 + 3 + 1 + strlen(bstr($signature));
    $padLength = $COSE_LENGTH - $fixed;
    if ($padLength < 256) {
        throw new RuntimeException("{$name}: the pad would be {$padLength} bytes, too short for a 3-byte head");
    }
    $cose = "\xd2\x84".bstr($protected)."\xa1\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength)."\xf6".bstr($signature);
    if (strlen($cose) !== $COSE_LENGTH) {
        throw new RuntimeException("{$name}: COSE is ".strlen($cose)." bytes, not {$COSE_LENGTH}");
    }
    $store = substr($s, 0, $COSE).$cose.substr($s, $COSE + $COSE_LENGTH);
    if (strlen($store) !== strlen($s)) {
        throw new RuntimeException("{$name}: the store changed length");
    }

    // does the verifier's own SignatureVerifier accept the new signature? (curve-secp256k1: it must refuse the key)
    try {
        $verifies = $verifier->verify(CoseSign1::fromBytes($cose), $claimBytes) ? 'verifies' : 'DOES NOT VERIFY';
    } catch (Throwable $e) {
        $verifies = 'refused: '.$e->getMessage();
    }

    file_put_contents("{$dir}/{$name}.bin", $store);
    file_put_contents("{$dir}/{$name}.png", pngWithStore($png, $store));
    printf("%s  %7d  %-22s alg %d  %s\n", hash('sha256', $store), strlen($store), $name, $alg, $verifies);
}
