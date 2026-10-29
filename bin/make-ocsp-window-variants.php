<?php

declare(strict_types=1);

/*
 * Build the OCSP fixtures of SPEC-030 amendment 7 (step 504): a `good` response without
 * `nextUpdate`, whose window C2PA 2.4 §15.9.1 bounds by producedAt + 24 hours, and a `revoked`
 * one about the same certificate for the caller's revocation source to answer.
 *
 * Made as bin/make-ocsp-variants.php makes step 92a's: a certificate authority and one
 * end-entity certificate under it, `openssl ocsp` answering from an index. Without `-ndays`
 * `openssl ocsp` writes no `nextUpdate`. The keys live in a temporary directory for the length
 * of this script and are deleted before it ends; this repository holds no private key.
 *
 * Run:  php bin/make-ocsp-window-variants.php
 * Needs: the `openssl` command (tooling, never loaded by the verifier).
 *
 * What it writes, into tests/Fixtures/ocsp-window/:
 *   ca.crt            the issuer, public
 *   signer.crt        the subject of the responses, public
 *   good-no-next.der  signer.crt answered good, no nextUpdate
 *   revoked.der       signer.crt revoked (keyCompromise) a day before the run, nextUpdate in ten years
 */

$out = dirname(__DIR__).'/tests/Fixtures/ocsp-window';
$tmp = sys_get_temp_dir().'/c2pa-ocsp-window-'.bin2hex(random_bytes(6));
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

// ---- a certificate authority, and one end-entity certificate under it ----
$run(sprintf(
    'openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -keyout %s -out %s -days 3650 -subj %s',
    $q($tmp.'/ca.key'), $q($tmp.'/ca.crt'), $q('/CN=SPEC-030 window Test Root CA/O=provemark fixtures'),
));
$run(sprintf(
    'openssl req -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -keyout %s -out %s -subj %s',
    $q($tmp.'/signer.key'), $q($tmp.'/signer.csr'), $q('/CN=SPEC-030 window signer/O=provemark fixtures'),
));
$run(sprintf(
    'openssl x509 -req -in %s -CA %s -CAkey %s -set_serial 0x01 -days 3650 -out %s',
    $q($tmp.'/signer.csr'), $q($tmp.'/ca.crt'), $q($tmp.'/ca.key'), $q($tmp.'/signer.crt'),
));

// ---- the index, the request, and the two responses ----
$expires = gmdate('ymdHis\Z', time() + 3650 * 86400);
$revokedAt = gmdate('ymdHis\Z', time() - 86400);
$subject = '/CN=SPEC-030 window signer/O=provemark fixtures';
$run(sprintf('openssl ocsp -issuer %s -cert %s -reqout %s -no_nonce', $q($tmp.'/ca.crt'), $q($tmp.'/signer.crt'), $q($tmp.'/req.der')));
$responses = [
    // no -ndays: openssl writes thisUpdate = producedAt = now and no nextUpdate
    'good-no-next' => ["V\t{$expires}\t\t01\tunknown\t{$subject}\n", ''],
    'revoked' => ["R\t{$expires}\t{$revokedAt},keyCompromise\t01\tunknown\t{$subject}\n", ' -ndays 3650'],
];
foreach ($responses as $name => [$line, $days]) {
    file_put_contents($tmp.'/index.txt', $line);
    $run(sprintf(
        'openssl ocsp -index %s -CA %s -rsigner %s -rkey %s -reqin %s -respout %s',
        $q($tmp.'/index.txt'), $q($tmp.'/ca.crt'), $q($tmp.'/ca.crt'), $q($tmp.'/ca.key'), $q($tmp.'/req.der'), $q("{$out}/{$name}.der"),
    ).$days);
    printf("%-18s %6d bytes\n", $name.'.der', (int) filesize("{$out}/{$name}.der"));
}
foreach (['ca', 'signer'] as $name) {
    copy("{$tmp}/{$name}.crt", "{$out}/{$name}.crt");
}

$shred();
if ((glob($out.'/*.key') ?: []) !== []) {
    fwrite(STDERR, "refusing to finish: a key is in the fixtures\n");
    exit(1);
}
