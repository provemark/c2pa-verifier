<?php

declare(strict_types=1);

/*
 * Build the OCSP fixtures of SPEC-030 (step 92a).
 *
 * No public file carries a `revoked` stapled response — step 90 looked — so
 * the only failure path of SPEC-030 needs one that is made here. What is
 * committed is three DER responses and two **public certificates**; the keys
 * that sign them live in a temporary directory for the length of this script
 * and are deleted before it ends, in the shape `bin/make-*-variants.php`
 * already use. This repository holds no private key, not even a test key.
 *
 * Run:  php bin/make-ocsp-variants.php
 * Needs: the `openssl` command (this is tooling, not the verification path —
 * the verifier itself runs no process and this script is never loaded by it).
 *
 * What it writes, into tests/Fixtures/ocsp/:
 *   ca.crt          the issuer, public
 *   signer.crt      the subject of the responses, public
 *   other.crt       a second signer, for the CertID mismatch of AC4
 *   revoked.der     an OCSP response: signer.crt is revoked (keyCompromise)
 *   good.der        the same certificate, answered good
 *   removed.der     revoked with reason removeFromCRL, which is not a
 *                   revocation at all (RFC 6960 §4.2.1) — AC8
 *   other-good.der  a good response about other.crt — AC4
 */

$out = dirname(__DIR__).'/tests/Fixtures/ocsp';
$tmp = sys_get_temp_dir().'/c2pa-ocsp-'.bin2hex(random_bytes(6));

if (! is_dir($out) && ! mkdir($out, 0o755, true)) {
    fwrite(STDERR, "cannot create {$out}\n");
    exit(1);
}
if (! mkdir($tmp, 0o700, true)) {
    fwrite(STDERR, "cannot create {$tmp}\n");
    exit(1);
}

/** Every key this script makes, so that the shutdown handler can remove them whatever happens. */
$shred = static function () use ($tmp): void {
    foreach (glob($tmp.'/*') ?: [] as $path) {
        if (is_file($path)) {
            // overwrite before unlinking: a deleted key is still a key until the bytes are gone
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
    $full = $command.' 2>&1';
    exec($full, $lines, $code);
    if ($code !== 0) {
        fwrite(STDERR, "failed: {$command}\n".implode("\n", $lines)."\n");
        exit(1);
    }
};

$q = static fn (string $path): string => escapeshellarg($path);

// ---- a certificate authority, and two end-entity certificates under it ----

$run(sprintf(
    'openssl req -x509 -newkey rsa:2048 -nodes -keyout %s -out %s -days 3650 -subj %s',
    $q($tmp.'/ca.key'), $q($tmp.'/ca.crt'), $q('/CN=SPEC-030 Test Root CA/O=provemark fixtures'),
));

foreach (['signer' => '01', 'other' => '02'] as $name => $serial) {
    $run(sprintf(
        'openssl req -newkey rsa:2048 -nodes -keyout %s -out %s -subj %s',
        $q("{$tmp}/{$name}.key"), $q("{$tmp}/{$name}.csr"), $q("/CN=SPEC-030 {$name}/O=provemark fixtures"),
    ));
    $run(sprintf(
        'openssl x509 -req -in %s -CA %s -CAkey %s -set_serial 0x%s -days 3650 -out %s',
        $q("{$tmp}/{$name}.csr"), $q($tmp.'/ca.crt'), $q($tmp.'/ca.key'), $serial, $q("{$tmp}/{$name}.crt"),
    ));
}

// ---- the OCSP index: one line per certificate, V(alid) or R(evoked) ----
// Format: status, expiry, revocation date[,reason], serial, filename, subject.

$expires = gmdate('ymdHis\Z', time() + 3650 * 86400);
$revokedAt = gmdate('ymdHis\Z', time() - 30 * 86400);
$subject = static fn (string $name): string => "/CN=SPEC-030 {$name}/O=provemark fixtures";

$indexes = [
    'revoked' => "R\t{$expires}\t{$revokedAt},keyCompromise\t01\tunknown\t".$subject('signer')."\n",
    'good' => "V\t{$expires}\t\t01\tunknown\t".$subject('signer')."\n",
    'removed' => "R\t{$expires}\t{$revokedAt},removeFromCRL\t01\tunknown\t".$subject('signer')."\n",
    'other-good' => "V\t{$expires}\t\t02\tunknown\t".$subject('other')."\n",
];

// -ndays 3650 rather than the week a responder would really give: `openssl ocsp`
// sets thisUpdate to now and there is no flag to fix it, so a short window would
// make these tests fail on a date nobody chose. The stale case is not simulated
// here at all — SPEC-030 AC6 uses a real one, the Adobe response inside
// tests/Fixtures/c2pa-rs/ocsp.jpg, which expired on 2025-08-18.
$requestFor = static function (string $cert) use ($run, $q, $tmp): string {
    $req = $tmp.'/req.der';
    $run(sprintf(
        'openssl ocsp -issuer %s -cert %s -reqout %s -no_nonce',
        $q($tmp.'/ca.crt'), $q($cert), $q($req),
    ));

    return $req;
};

foreach ($indexes as $name => $line) {
    file_put_contents($tmp.'/index.txt', $line);
    $cert = $tmp.'/'.(str_starts_with($name, 'other') ? 'other' : 'signer').'.crt';
    $req = $requestFor($cert);
    $run(sprintf(
        'openssl ocsp -index %s -CA %s -rsigner %s -rkey %s -reqin %s -respout %s -ndays 3650',
        $q($tmp.'/index.txt'), $q($tmp.'/ca.crt'), $q($tmp.'/ca.crt'), $q($tmp.'/ca.key'),
        $q($req), $q("{$out}/{$name}.der"),
    ));
    printf("%-16s %6d bytes\n", $name.'.der', (int) filesize("{$out}/{$name}.der"));
}

foreach (['ca', 'signer', 'other'] as $name) {
    copy("{$tmp}/{$name}.crt", "{$out}/{$name}.crt");
    printf("%-16s %6d bytes\n", $name.'.crt', (int) filesize("{$out}/{$name}.crt"));
}

// ---- and the keys go, now, not when the process happens to end ----

$shred();

$leftKeys = glob($out.'/*.key') ?: [];
if ($leftKeys !== []) {
    fwrite(STDERR, "refusing to finish: a key is in the fixtures\n");
    exit(1);
}
echo 'keys shredded; ', $out, " holds public certificates and DER responses only\n";
