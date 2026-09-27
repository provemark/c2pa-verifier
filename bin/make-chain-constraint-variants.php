<?php

declare(strict_types=1);

/*
 * Step 164 (measurement): the lower chain findings of step 157, on a throw-away hierarchy. A P-256
 * root, intermediates and leaves under it, and the PNG fixture's manifest re-signed with each leaf,
 * as bin/make-tsa-signer-variants.php does (a new COSE, the pad resized so that the store keeps its
 * length). The root is the one anchor, in the legacy trust_anchors field, so that c2patool 0.27.22
 * reads it too.
 *
 *   nc-inside            an intermediate whose nameConstraints permit O=Permitted Org; the leaf is inside
 *   nc-outside           the same intermediate; the leaf's subject is O=Other Org, outside the constraint
 *   policy-required      an intermediate with a critical policyConstraints requireExplicitPolicy:0; the
 *                        leaf carries no certificatePolicies, so no policy is valid (RFC 5280 §6.1.5)
 *   critical-leaf        the leaf carries a critical extension of an unknown OID (RFC 5280 §4.2)
 *   critical-intermediate  the intermediate carries it
 *   plain                the leaf directly under the root, x5chain in the protected header: the guard
 *   x5chain-unprotected  the same leaf and signature, x5chain moved to the unprotected header
 *   x5chain-swapped      the x5chain-unprotected file with its unprotected x5chain replaced, after
 *                        signing, by a second certificate for the same key naming O=Adobe Inc
 *   x5chain-text-unprotected, x5chain-text-swapped  the same two under the text label "x5chain", the
 *                        one c2pa-rs reads in the unprotected header ("permitted in older versions")
 *   x5chain-both         x5chain in the protected header (33) and in the unprotected ("x5chain")
 *
 * Keys live in a directory outside the repository for the run and are deleted before it ends.
 * Usage: php bin/make-chain-constraint-variants.php <scratch-dir> <c2patool-0.28.0> <c2patool-0.27.22>
 * Writes tests/Fixtures/chain-constraints/ and tests/Fixtures/c2patool/chain-constraints/. Tooling.
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
[$scratch, $new, $old] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_dir($scratch) || ! is_file($new) || ! is_file($old) || $version($new) !== 'c2patool 0.28.0' || $version($old) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-chain-constraint-variants.php <scratch-dir outside the repository> <c2patool-0.28.0> <c2patool-0.27.22>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/chain-constraint-keys-'.bin2hex(random_bytes(4));
if (! mkdir($keys, 0o700)) {
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

function ccRun(string $command, bool $mayFail = false): string
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0 && ! $mayFail) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }

    return implode("\n", $lines);
}

function ccSh(string ...$parts): string
{
    return implode(' ', array_map(escapeshellarg(...), $parts));
}

/** @param  int<0, 7>  $mt */
function ccHead(int $mt, int $n): string
{
    $ib = $mt << 5;

    return match (true) {
        $n < 24 => pack('C', $ib | $n),
        $n < 256 => pack('CC', $ib | 24, $n),
        $n < 65536 => pack('Cn', $ib | 25, $n),
        default => pack('CN', $ib | 26, $n),
    };
}

function ccBstr(string $b): string
{
    return ccHead(2, strlen($b)).$b;
}

function ccDer(string $pem): string
{
    return (string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem) ?? '', true);
}

/** DER ECDSA-Sig-Value → R || S, each 32 bytes wide. */
function ccDerToRs(string $der): string
{
    $p = 2;
    $rs = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$p + 1]);
        $rs .= str_pad(ltrim(substr($der, $p + 2, $len), "\0"), 32, "\0", STR_PAD_LEFT);
        $p += 2 + $len;
    }

    return $rs;
}

/** The x5chain value: an array of DER certificates. */
function ccChain(string ...$ders): string
{
    return ccHead(4, count($ders)).implode('', array_map(ccBstr(...), $ders));
}

/**
 * A COSE_Sign1 of exactly $length bytes: the protected map, the unprotected map with its pad
 * filling the rest, a null payload, the signature.
 *
 * @param  list<string>  $unprotectedPairs  encoded key/value pairs before the pad
 */
function ccCose(string $protected, array $unprotectedPairs, string $signature, int $length): string
{
    $fixed = 2 + strlen(ccBstr($protected)) + 1 + strlen(implode('', $unprotectedPairs)) + 4 + 3 + 1 + strlen(ccBstr($signature));
    $pad = $length - $fixed;
    if ($pad < 256 || $pad > 65535) {
        throw new RuntimeException("the pad would be {$pad} bytes");
    }
    $cose = "\xd2\x84".ccBstr($protected).ccHead(5, count($unprotectedPairs) + 1).implode('', $unprotectedPairs)."\x63pad\x59".pack('n', $pad).str_repeat("\0", $pad)."\xf6".ccBstr($signature);
    if (strlen($cose) !== $length) {
        throw new RuntimeException('the COSE is '.strlen($cose)." bytes, not {$length}");
    }

    return $cose;
}

$org = '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY';
file_put_contents("{$keys}/ext.cnf", <<<'CNF'
[req]
distinguished_name = dn
[dn]
[v3_root]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign, digitalSignature
subjectKeyIdentifier = hash
[v3_intermediate]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
[v3_nc]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
nameConstraints = critical, permitted;dirName:nc_permitted
[nc_permitted]
O = Permitted Org
[v3_policy]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
policyConstraints = critical, requireExplicitPolicy:0
[v3_critical_intermediate]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
1.3.6.1.4.1.99999.7 = critical, DER:0500
[v3_leaf]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature
extendedKeyUsage = emailProtection
authorityKeyIdentifier = keyid
[v3_critical_leaf]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature
extendedKeyUsage = emailProtection
authorityKeyIdentifier = keyid
1.3.6.1.4.1.99999.7 = critical, DER:0500
CNF);

/** A key, a CSR and a certificate for $name, issued by $issuer (null: self-signed root). */
$issue = static function (string $name, string $subject, string $section, ?string $issuer, ?string $keyOf = null) use ($keys): string {
    $key = "{$keys}/".($keyOf ?? $name).'.key';
    if (! is_file($key)) {
        ccRun(ccSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', $key));
    }
    if ($issuer === null) {
        ccRun(ccSh('openssl', 'req', '-x509', '-new', '-key', $key, '-subj', $subject, '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', $section, '-out', "{$keys}/{$name}.pem"));
    } else {
        ccRun(ccSh('openssl', 'req', '-new', '-key', $key, '-subj', $subject, '-config', "{$keys}/ext.cnf", '-out', "{$keys}/{$name}.csr"));
        ccRun(ccSh('openssl', 'x509', '-req', '-in', "{$keys}/{$name}.csr", '-CA', "{$keys}/{$issuer}.pem", '-CAkey', "{$keys}/{$issuer}.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', $section, '-out', "{$keys}/{$name}.pem"));
    }

    return (string) file_get_contents("{$keys}/{$name}.pem");
};

$pem = [];
$pem['root'] = $issue('root', "{$org}/CN=Throw-away Chain-constraint Root", 'v3_root', null);
$pem['int-nc'] = $issue('int-nc', "{$org}/CN=Name-constrained Intermediate", 'v3_nc', 'root');
$pem['int-policy'] = $issue('int-policy', "{$org}/CN=Policy-constrained Intermediate", 'v3_policy', 'root');
$pem['int-critical'] = $issue('int-critical', "{$org}/CN=Intermediate with an unknown critical extension", 'v3_critical_intermediate', 'root');
$pem['int-plain'] = $issue('int-plain', "{$org}/CN=Plain Intermediate", 'v3_intermediate', 'root');
$pem['nc-inside'] = $issue('nc-inside', '/O=Permitted Org/CN=Inside the constraint', 'v3_leaf', 'int-nc');
$pem['nc-outside'] = $issue('nc-outside', '/O=Other Org/CN=Outside the constraint', 'v3_leaf', 'int-nc');
$pem['policy-required'] = $issue('policy-required', "{$org}/CN=Leaf without a policy", 'v3_leaf', 'int-policy');
$pem['critical-leaf'] = $issue('critical-leaf', "{$org}/CN=Leaf with an unknown critical extension", 'v3_critical_leaf', 'int-plain');
$pem['critical-intermediate'] = $issue('critical-intermediate', "{$org}/CN=Leaf under a critical intermediate", 'v3_leaf', 'int-critical');
$pem['plain'] = $issue('plain', "{$org}/CN=Plain leaf", 'v3_leaf', 'root');
$pem['swapped'] = $issue('swapped', '/O=Adobe Inc/CN=Adobe Content Credentials', 'v3_leaf', 'root', 'plain');

// the fixture's store and the signature box (step 31 offsets)
$root = dirname(__DIR__);
$png = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.png');
$stream = fopen($root.'/tests/Fixtures/fixture-signed.png', 'rb');
$extracted = $stream === false ? null : (new PngManifestStoreExtractor)->extract($stream);
if ($extracted === null || strlen($extracted->bytes) !== 46025) {
    throw new RuntimeException('the PNG store is not the one measured in step 09');
}
$s = $extracted->bytes;
$COSE = 33728;
$COSE_LENGTH = 12305 - 8;
if (substr($s, $COSE, 2) !== "\xd2\x84") {
    throw new RuntimeException('the COSE_Sign1 is not where step 31 measured it');
}
$claimBytes = ManifestStore::fromTree((new JumbfParser)->parse($s))->active->claimBytes();

$dir = $root.'/tests/Fixtures/chain-constraints';
$oracles = $root.'/tests/Fixtures/c2patool/chain-constraints';
foreach ([$dir, $oracles] as $d) {
    if (! is_dir($d) && ! mkdir($d, 0o755, true)) {
        throw new RuntimeException("cannot create {$d}");
    }
}
foreach (glob("{$dir}/*") ?: [] as $stale) {
    unlink($stale);
}
foreach ($pem as $name => $certificate) {
    file_put_contents("{$dir}/{$name}.pem", $certificate);
}
$settings = ['trust' => ['trust_anchors' => $pem['root'], 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')], 'verify' => ['verify_trust' => true]];
file_put_contents("{$dir}/root.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

/** Sign $claimBytes with $key over a COSE whose headers are given (the unprotected pairs last); return the COSE. */
$sign = static function (string $keyName, string $protected, string ...$unprotectedPairs) use ($keys, $claimBytes, $COSE_LENGTH): string {
    $unprotectedPairs = array_values($unprotectedPairs);
    $draft = ccCose($protected, $unprotectedPairs, str_repeat("\0", 64), $COSE_LENGTH);
    file_put_contents("{$keys}/tbs", CoseSign1::fromBytes($draft)->sigStructure($claimBytes));
    ccRun(ccSh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/{$keyName}.key", '-out', "{$keys}/sig", "{$keys}/tbs"));

    return ccCose($protected, $unprotectedPairs, ccDerToRs((string) file_get_contents("{$keys}/sig")), $COSE_LENGTH);
};

$x5chainLabel = "\x18\x21";
$files = [];
// leaf => the intermediate that issued it (null: the root)
foreach (['nc-inside' => 'int-nc', 'nc-outside' => 'int-nc', 'policy-required' => 'int-policy', 'critical-leaf' => 'int-plain', 'critical-intermediate' => 'int-critical', 'plain' => null] as $leaf => $intermediate) {
    $chain = $intermediate === null ? ccChain(ccDer($pem[$leaf])) : ccChain(ccDer($pem[$leaf]), ccDer($pem[$intermediate]));
    $files[$leaf] = $sign($leaf, "\xa2\x01\x26".$x5chainLabel.$chain);
}
$files['x5chain-unprotected'] = $sign('plain', "\xa1\x01\x26", $x5chainLabel.ccChain(ccDer($pem['plain'])));
// the same signature, the unprotected chain swapped for the other certificate of the same key
$unprotectedCose = CoseSign1::fromBytes($files['x5chain-unprotected']);
$files['x5chain-swapped'] = ccCose("\xa1\x01\x26", [$x5chainLabel.ccChain(ccDer($pem['swapped']))], $unprotectedCose->signature, $COSE_LENGTH);

$text = "\x67x5chain";
$files['x5chain-text-unprotected'] = $sign('plain', "\xa1\x01\x26", $text.ccChain(ccDer($pem['plain'])));
$files['x5chain-text-swapped'] = ccCose("\xa1\x01\x26", [$text.ccChain(ccDer($pem['swapped']))], CoseSign1::fromBytes($files['x5chain-text-unprotected'])->signature, $COSE_LENGTH);
$files['x5chain-both'] = $sign('plain', "\xa2\x01\x26".$x5chainLabel.ccChain(ccDer($pem['plain'])), $text.ccChain(ccDer($pem['swapped'])));

$verifier = new SignatureVerifier;
foreach ($files as $name => $cose) {
    if (! $verifier->verify(CoseSign1::fromBytes($cose), $claimBytes)) {
        throw new RuntimeException("{$name}: the signature does not verify");
    }
    file_put_contents("{$dir}/{$name}.png", pngWithStore($png, substr($s, 0, $COSE).$cose.substr($s, $COSE + $COSE_LENGTH)));
}

// RFC 5280 path validation by OpenSSL, as a reference: the leaf, its intermediate untrusted, the root trusted
echo "openssl verify (RFC 5280 path validation):\n";
foreach (['nc-inside' => 'int-nc', 'nc-outside' => 'int-nc', 'policy-required' => 'int-policy', 'critical-leaf' => 'int-plain', 'critical-intermediate' => 'int-critical', 'plain' => null, 'swapped' => null] as $leaf => $intermediate) {
    $args = ['openssl', 'verify', '-CAfile', "{$dir}/root.pem", '-purpose', 'any'];
    if ($intermediate !== null) {
        $args = [...$args, '-untrusted', "{$dir}/{$intermediate}.pem"];
    }
    $plainOut = ccRun(ccSh(...[...$args, "{$dir}/{$leaf}.pem"]), true);
    $policyOut = ccRun(ccSh(...[...$args, '-policy_check', "{$dir}/{$leaf}.pem"]), true);
    printf("  %-22s %s | -policy_check: %s\n", $leaf, str_replace("\n", ' / ', $plainOut), str_replace("\n", ' / ', $policyOut));
}

// what each c2patool says, with the root as anchor
foreach (glob("{$oracles}/*") ?: [] as $stale) {
    unlink($stale);
}
foreach (array_keys($files) as $name) {
    foreach (['0.28.0' => $new, '0.27.22' => $old] as $tag => $tool) {
        $lines = [];
        exec(ccSh($tool, "{$dir}/{$name}.png", '--settings', "{$dir}/root.settings.json").' 2>&1', $lines, $code);
        file_put_contents("{$oracles}/{$name}--{$tag}".($code === 0 ? '.json' : '.error.txt'), implode("\n", $lines)."\n");
        $report = $code === 0 ? json_decode(implode("\n", $lines), true) : null;
        $failures = [];
        $results = is_array($report) && is_array($report['validation_results'] ?? null) ? $report['validation_results'] : [];
        $active = is_array($results['activeManifest'] ?? null) ? $results['activeManifest'] : [];
        foreach (is_array($active['failure'] ?? null) ? $active['failure'] : [] as $f) {
            if (is_array($f) && is_string($f['code'] ?? null)) {
                $failures[] = $f['code'];
            }
        }
        printf("%-22s %-8s %s %s\n", $name, $tag, is_array($report) && is_string($report['validation_state'] ?? null) ? $report['validation_state'] : "exit {$code}", implode(',', $failures));
    }
}
