<?php

declare(strict_types=1);

/*
 * Step 154 (SPEC-015 amendment 6, SPEC-016 amendment 5): certificates whose serial number is longer
 * than RFC 5280 allows (20 octets), on both sides of this verifier's bound of 256 octets for an
 * INTEGER read as a decimal number.
 *
 *   serial-200.jpg   a leaf with a 200-octet serial: c2patool 0.27.22 and 0.28.0 say Trusted
 *   serial-256.jpg   256 octets, the longest this verifier converts
 *   serial-257.jpg   257 octets, refused here as a resource bound
 *
 * A throw-away P-256 hierarchy is made for the run (keys in the scratch directory, deleted at the end;
 * the public root goes into root.pem and root.settings.json beside the files). c2patool 0.28.0 signs
 * ../fixture-unsigned.jpg with each leaf. Decided by Maurice van Loon on 2026-09-21: tooling may sign
 * with throw-away keys.
 *
 * Usage: php bin/make-integer-bound-variants.php <c2patool> <scratch-dir>. Tooling.
 */

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$c2patool, $scratch] = [$argv[1] ?? '', $argv[2] ?? ''];
if (! is_executable($c2patool) || ! is_dir($scratch)) {
    fwrite(STDERR, "usage: php bin/make-integer-bound-variants.php <c2patool> <scratch-dir>\n");
    exit(2);
}

$root = dirname(__DIR__);
$dir = $root.'/tests/Fixtures/integer-bound';
if (! is_dir($dir) && ! mkdir($dir, 0o755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
$keys = $scratch.'/integer-bound-keys';
if (! is_dir($keys) && ! mkdir($keys, 0o700, true)) {
    throw new RuntimeException("cannot create {$keys}");
}
register_shutdown_function(static function () use ($keys): void {
    foreach (glob($keys.'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($keys);
});

$run = static function (string ...$parts): void {
    exec(implode(' ', array_map('escapeshellarg', $parts)).' 2>&1', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException(implode(' ', $parts).': '.implode("\n", $output));
    }
};

file_put_contents($keys.'/ext.cnf', <<<'CNF'
[req]
distinguished_name = dn
[dn]
[v3_root]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign, digitalSignature
subjectKeyIdentifier = hash
[leaf]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, nonRepudiation
extendedKeyUsage = emailProtection
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
CNF);
$run('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', $keys.'/root.key');
$run('openssl', 'req', '-x509', '-new', '-key', $keys.'/root.key', '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (integer bound)', '-config', $keys.'/ext.cnf', '-extensions', 'v3_root', '-days', '3650', '-out', $dir.'/root.pem');

$settings = json_decode((string) file_get_contents($root.'/tests/Fixtures/trust/full.settings.json'), true, 512, JSON_THROW_ON_ERROR);
assert(is_array($settings) && is_array($settings['trust']));
$settings['trust']['trust_anchors'] = file_get_contents($dir.'/root.pem');
file_put_contents($dir.'/root.settings.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

$manifest = json_decode((string) file_get_contents($root.'/tests/Fixtures/fixture-signed.manifest.json'), true, 512, JSON_THROW_ON_ERROR);
assert(is_array($manifest));

foreach ([200, 256, 257] as $octets) {
    // the first octet 0x7f keeps the value positive with no leading 0x00, so the magnitude is exactly $octets
    $serial = '7f'.bin2hex(random_bytes($octets - 1));
    $run('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/leaf.key");
    $run('openssl', 'req', '-new', '-key', "{$keys}/leaf.key", '-subj', "/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=serial of {$octets} octets", '-config', $keys.'/ext.cnf', '-out', "{$keys}/leaf.csr");
    $run('openssl', 'x509', '-req', '-in', "{$keys}/leaf.csr", '-CA', $dir.'/root.pem', '-CAkey', $keys.'/root.key', '-set_serial', '0x'.$serial, '-days', '3650', '-extfile', $keys.'/ext.cnf', '-extensions', 'leaf', '-out', "{$keys}/leaf.pem");
    file_put_contents("{$keys}/chain.pem", file_get_contents("{$keys}/leaf.pem").file_get_contents($dir.'/root.pem'));
    $manifest['private_key'] = "{$keys}/leaf.key";
    $manifest['sign_cert'] = "{$keys}/chain.pem";
    $manifest['title'] = "serial-{$octets}.jpg";
    file_put_contents("{$keys}/manifest.json", json_encode($manifest));
    $run($c2patool, $root.'/tests/Fixtures/fixture-unsigned.jpg', '-m', "{$keys}/manifest.json", '-o', "{$dir}/serial-{$octets}.jpg", '-f');
    printf("%s  serial-%d.jpg\n", hash_file('sha256', "{$dir}/serial-{$octets}.jpg"), $octets);
}
