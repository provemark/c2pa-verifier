<?php

declare(strict_types=1);

/*
 * SPEC-044: builds the certificate-validity variants. A throw-away P-256 root is made for the run and,
 * per variant, a leaf that is the SPEC-015 `good` profile with its validity rewritten by hand: the
 * tbsCertificate's validity SEQUENCE is replaced with the variant's bytes, and the tbsCertificate is
 * signed again with the root key, so that OpenSSL reads the result as a well-signed certificate. The PNG
 * fixture's manifest is then re-signed with each leaf as bin/make-profile-variants.php does it (new
 * protected header, new signature, the pad resized so that the store keeps its length). Both c2patool
 * versions judge every variant with the root as anchor.
 *
 * Keys live in a directory outside the repository for the run and are deleted before the script ends;
 * only public certificates, the variants, the settings and c2patool's answers are written under tests/.
 * Decided by Maurice van Loon on 2026-09-21: tooling may sign with throw-away keys; the product never signs.
 *
 * Usage: php bin/make-spec044-variants.php <scratch-dir> <c2patool-0.28.0> <c2patool-0.27.22>
 * Writes tests/Fixtures/validity/<name>.{leaf.pem,bin,png}, throw-away-root.{pem,settings.json}, and
 *   tests/Fixtures/c2patool/validity/<name>--<version>.json (or .error.txt)
 * Tooling, not product code.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Cose\SignatureVerifier;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;

[$scratch, $new, $old] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_dir($scratch) || ! is_file($new) || ! is_file($old) || $version($new) !== 'c2patool 0.28.0' || $version($old) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-spec044-variants.php <scratch-dir outside the repository> <c2patool-0.28.0> <c2patool-0.27.22>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/validity-keys-'.bin2hex(random_bytes(4));
if (! mkdir($keys, 0o700)) {
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

/**
 * A DER TLV, definite length.
 *
 * @param  int<0, 255>  $tag
 */
function tlv(int $tag, string $contents): string
{
    $n = strlen($contents);
    if ($n < 128) {
        return pack('CC', $tag, $n).$contents;
    }
    $length = ltrim(pack('N', $n), "\0");

    return pack('CC', $tag, 0x80 | strlen($length)).$length.$contents;
}

/**
 * The children of the constructed TLV at the start of $der, each as its whole TLV.
 *
 * @return list<string>
 */
function children(string $der): array
{
    $at = static function (int $p) use ($der): array {
        $length = ord($der[$p + 1]);
        $header = 2;
        if ($length & 0x80) {
            $width = $length & 0x7F;
            $length = (int) hexdec(bin2hex(substr($der, $p + 2, $width)));
            $header += $width;
        }

        return [$p + $header, $length];
    };
    [$start, $length] = $at(0);
    $out = [];
    for ($p = $start; $p < $start + $length;) {
        [$contents, $childLength] = $at($p);
        $out[] = substr($der, $p, $contents - $p + $childLength);
        $p = $contents + $childLength;
    }

    return $out;
}

function pemToDer(string $pem): string
{
    return (string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem) ?? '', true);
}

function derToPem(string $der): string
{
    return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
}

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

// name => [notBefore TLV, notAfter TLV]; 0x17 UTCTime, 0x18 GeneralizedTime (RFC 5280 §4.1.2.5)
$notBefore = tlv(0x17, '240101000000Z');
$notAfter = tlv(0x18, '20500101000000Z');
$variants = [
    'resigned' => [$notBefore, $notAfter],                              // the control: the rewriting is right
    'not-after-9999' => [$notBefore, tlv(0x18, '99991231235959Z')],     // AC3: "no well-defined expiration date"
    'no-seconds' => [tlv(0x17, '2401010000Z'), $notAfter],              // AC4: UTCTime without seconds (BER, not DER)
    'fraction' => [$notBefore, tlv(0x18, '20500101000000.5Z')],         // open question 1: a fraction RFC 5280 forbids
    'expired' => [$notBefore, tlv(0x17, '250101000000Z')],              // the control for the next: expired on 2025-01-01
    'expired-fraction' => [$notBefore, tlv(0x18, '20250101000000.5Z')], // the same day with a fraction; PHP reads 2500-12-31
];

// ---- the throw-away root ----
run(sh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
run(sh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'v3_root', '-out', "{$keys}/root.pem"));
$rootPem = (string) file_get_contents("{$keys}/root.pem");
$rootDer = pemToDer($rootPem);

// ---- the fixture's store and the signature box (step 31 offsets) ----
$root = dirname(__DIR__);
$fixtures = $root.'/tests/Fixtures';
$png = (string) file_get_contents($fixtures.'/fixture-signed.png');
$stream = fopen($fixtures.'/fixture-signed.png', 'rb');
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

$dir = $fixtures.'/validity';
$oracles = $fixtures.'/c2patool/validity';
foreach ([$dir, $oracles] as $d) {
    if (! is_dir($d) && ! mkdir($d, 0o755, true)) {
        throw new RuntimeException("cannot create {$d}");
    }
}
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
$settings = ['trust' => ['trust_anchors' => $rootPem, 'trust_config' => (string) file_get_contents($fixtures.'/trust/store.cfg')], 'verify' => ['verify_trust' => true]];
file_put_contents("{$dir}/throw-away-root.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

$verifier = new SignatureVerifier;
foreach ($variants as $name => [$from, $to]) {
    // the SPEC-015 `good` leaf, as OpenSSL makes it
    $key = "{$keys}/{$name}.key";
    run(sh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', $key));
    run(sh('openssl', 'req', '-new', '-key', $key, '-subj', "/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=validity {$name}", '-config', "{$keys}/ext.cnf", '-out', "{$keys}/{$name}.csr"));
    run(sh('openssl', 'x509', '-req', '-in', "{$keys}/{$name}.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'good', '-out', "{$keys}/{$name}.made.pem"));

    // its validity rewritten, and the tbsCertificate signed again by the root
    [$tbs, $signatureAlgorithm] = children(pemToDer((string) file_get_contents("{$keys}/{$name}.made.pem")));
    $fields = children($tbs);
    $validity = ord($fields[0][0]) === 0xA0 ? 4 : 3;   // after [0] version, serialNumber, signature, issuer
    $fields[$validity] = tlv(0x30, $from.$to);
    $tbs = tlv(0x30, implode('', $fields));
    file_put_contents("{$keys}/{$name}.tbs-cert", $tbs);
    run(sh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/root.key", '-out', "{$keys}/{$name}.cert-sig", "{$keys}/{$name}.tbs-cert"));
    $leafDer = tlv(0x30, $tbs.$signatureAlgorithm.tlv(0x03, "\0".file_get_contents("{$keys}/{$name}.cert-sig")));
    $leafPem = derToPem($leafDer);
    file_put_contents("{$keys}/{$name}.pem", $leafPem);
    // OpenSSL's own opinion of the certificate, recorded, not required: it may refuse to read it at all
    exec(sh('openssl', 'verify', '-CAfile', "{$keys}/root.pem", '-no_check_time', "{$keys}/{$name}.pem").' 2>&1', $said);
    $chain = implode(' ', $said);
    unset($said);
    file_put_contents("{$dir}/{$name}.leaf.pem", $leafPem);

    // the protected header: {1: alg, 33: [leaf, root]}; ES256
    $protected = "\xa2\x01\x26\x18\x21\x82".bstr($leafDer).bstr($rootDer);
    // Sig_structure (RFC 9052 §4.4) by hand: CoseSign1 refuses a leaf OpenSSL cannot read, which is the point
    file_put_contents("{$keys}/{$name}.tbs", "\x84".head(3, 10).'Signature1'.bstr($protected).bstr('').bstr($claimBytes));
    run(sh('openssl', 'dgst', '-sha256', '-sign', $key, '-out', "{$keys}/{$name}.sig", "{$keys}/{$name}.tbs"));
    $signature = derToRs((string) file_get_contents("{$keys}/{$name}.sig"), 32);
    $fixed = 2 + strlen(bstr($protected)) + 5 + 3 + 1 + strlen(bstr($signature));
    $padLength = $COSE_LENGTH - $fixed;
    if ($padLength < 256) {
        throw new RuntimeException("{$name}: the pad would be {$padLength} bytes, too short for a 3-byte head");
    }
    $cose = "\xd2\x84".bstr($protected)."\xa1\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength)."\xf6".bstr($signature);
    $store = substr($s, 0, $COSE).$cose.substr($s, $COSE + $COSE_LENGTH);
    if (strlen($cose) !== $COSE_LENGTH || strlen($store) !== strlen($s)) {
        throw new RuntimeException("{$name}: the store changed length");
    }
    try {
        $verifies = $verifier->verify(CoseSign1::fromBytes($cose), $claimBytes) ? 'verifies' : 'DOES NOT VERIFY';
    } catch (Throwable $e) {
        $verifies = 'refused: '.$e->getMessage();
    }

    file_put_contents("{$dir}/{$name}.bin", $store);
    file_put_contents("{$dir}/{$name}.png", pngWithStore($png, $store));
    printf("%s  %-15s %s; chain: %s\n", hash('sha256', $store), $name, $verifies, str_contains($chain, ': OK') ? 'OK' : 'refused ('.substr(trim($chain), 0, 60).'…)');

    foreach (['0.28.0' => $new, '0.27.22' => $old] as $v => $tool) {
        exec(sh($tool, "{$dir}/{$name}.png", '--settings', "{$dir}/throw-away-root.settings.json").' 2>&1', $lines, $exit);
        $text = implode("\n", $lines)."\n";
        foreach (glob("{$oracles}/{$name}--{$v}.*") ?: [] as $stale) {
            unlink($stale);
        }
        file_put_contents("{$oracles}/{$name}--{$v}".($exit === 0 ? '.json' : '.error.txt'), $text);
        $json = json_decode($text, true);
        $state = is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : '?';
        printf("   %-8s %s\n", $v, $exit === 0 ? $state : trim($lines[0] ?? ''));
        unset($lines);
    }
}
