<?php

declare(strict_types=1);

/*
 * Build the delegated-responder OCSP fixtures (step 434).
 *
 * RFC 6960 §4.2.2.2 lets the issuer answer for its certificates itself, or a
 * responder it issued with id-kp-OCSPSigning. The fixtures of
 * bin/make-ocsp-variants.php are all signed by the issuer itself; these three
 * are signed by a responder whose certificate rides in the response's `certs`,
 * and each says the signer is revoked:
 *
 *   delegated.der  the responder is issued by the CA and has OCSPSigning: believed
 *   no-eku.der     the responder is issued by the CA, without OCSPSigning: not a responder
 *   stranger.der   the responder has OCSPSigning but is self-signed: not the CA's
 *
 * What is committed is the three responses and two public certificates, into
 * tests/Fixtures/ocsp/responder/. The keys live in a temporary directory for the
 * length of the script and are deleted before it ends.
 *
 * Run:  php bin/make-ocsp-responder-variants.php
 * Needs: the `openssl` command. Tooling, not the verification path.
 */

$out = dirname(__DIR__).'/tests/Fixtures/ocsp/responder';
$tmp = sys_get_temp_dir().'/c2pa-ocsp-responder-'.bin2hex(random_bytes(6));

if (! is_dir($out) && ! mkdir($out, 0o755, true)) {
    fwrite(STDERR, "cannot create {$out}\n");
    exit(1);
}
if (! mkdir($tmp, 0o700, true)) {
    fwrite(STDERR, "cannot create {$tmp}\n");
    exit(1);
}

$shred = static function () use ($tmp): void {
    foreach (glob($tmp.'/*') ?: [] as $path) {
        if (is_file($path)) {
            $size = filesize($path);
            if (is_int($size) && $size > 0 && str_ends_with($path, '.key')) {
                file_put_contents($path, str_repeat("\0", $size));
            }
            unlink($path);
        }
    }
    @rmdir($tmp);
};
register_shutdown_function($shred);

$run = static function (string $command): void {
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        fwrite(STDERR, "failed: {$command}\n".implode("\n", $lines)."\n");
        exit(1);
    }
};

$q = static fn (string $path): string => escapeshellarg($path);
$subject = static fn (string $name): string => "/CN=Step 434 {$name}/O=provemark fixtures";

// ---- the CA, the signer it issued, and three responders ----

$run(sprintf(
    'openssl req -x509 -newkey rsa:2048 -nodes -keyout %s -out %s -days 3650 -subj %s',
    $q($tmp.'/ca.key'), $q($tmp.'/ca.crt'), $q($subject('Root CA')),
));

$responder = "keyUsage=critical,digitalSignature\nextendedKeyUsage=OCSPSigning\n";
$issued = [
    'signer' => ['01', "keyUsage=critical,digitalSignature\nextendedKeyUsage=emailProtection\n"],
    'delegated' => ['10', $responder],
    'no-eku' => ['11', "keyUsage=critical,digitalSignature\n"],
];
foreach ($issued as $name => [$serial, $extensions]) {
    file_put_contents("{$tmp}/{$name}.ext", $extensions);
    $run(sprintf(
        'openssl req -newkey rsa:2048 -nodes -keyout %s -out %s -subj %s',
        $q("{$tmp}/{$name}.key"), $q("{$tmp}/{$name}.csr"), $q($subject($name)),
    ));
    $run(sprintf(
        'openssl x509 -req -in %s -CA %s -CAkey %s -set_serial 0x%s -days 3650 -extfile %s -out %s',
        $q("{$tmp}/{$name}.csr"), $q($tmp.'/ca.crt'), $q($tmp.'/ca.key'), $serial, $q("{$tmp}/{$name}.ext"), $q("{$tmp}/{$name}.crt"),
    ));
}
// the stranger: the same extensions as the delegated responder, but its own issuer
$run(sprintf(
    'openssl req -x509 -newkey rsa:2048 -nodes -keyout %s -out %s -days 3650 -subj %s -addext %s -addext %s',
    $q($tmp.'/stranger.key'), $q($tmp.'/stranger.crt'), $q($subject('stranger')),
    $q('keyUsage=critical,digitalSignature'), $q('extendedKeyUsage=OCSPSigning'),
));

// ---- one index, the signer revoked; one response per responder ----

$expires = gmdate('ymdHis\Z', time() + 3650 * 86400);
$revokedAt = gmdate('ymdHis\Z', time() - 30 * 86400);
file_put_contents($tmp.'/index.txt', "R\t{$expires}\t{$revokedAt},keyCompromise\t01\tunknown\t".$subject('signer')."\n");
$run(sprintf(
    'openssl ocsp -issuer %s -cert %s -reqout %s -no_nonce',
    $q($tmp.'/ca.crt'), $q($tmp.'/signer.crt'), $q($tmp.'/req.der'),
));
foreach (['delegated', 'no-eku', 'stranger'] as $name) {
    // -ndays 3650 for the reason bin/make-ocsp-variants.php gives; the responder's certificate rides in `certs`
    $run(sprintf(
        'openssl ocsp -index %s -CA %s -rsigner %s -rkey %s -reqin %s -respout %s -ndays 3650',
        $q($tmp.'/index.txt'), $q($tmp.'/ca.crt'), $q("{$tmp}/{$name}.crt"), $q("{$tmp}/{$name}.key"),
        $q($tmp.'/req.der'), $q("{$out}/{$name}.der"),
    ));
    printf("%-16s %6d bytes\n", $name.'.der', (int) filesize("{$out}/{$name}.der"));
}
foreach (['ca', 'signer'] as $name) {
    copy("{$tmp}/{$name}.crt", "{$out}/{$name}.crt");
    printf("%-16s %6d bytes\n", $name.'.crt', (int) filesize("{$out}/{$name}.crt"));
}

$shred();

if ((glob($out.'/*.key') ?: []) !== []) {
    fwrite(STDERR, "refusing to finish: a key is in the fixtures\n");
    exit(1);
}
echo 'keys shredded; ', $out, " holds public certificates and DER responses only\n";
