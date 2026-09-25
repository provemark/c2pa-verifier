<?php

declare(strict_types=1);

/*
 * Step 155 (SPEC-043): two files for the criteria that need a real file. One byte each, nothing
 * re-signed.
 *
 *   mediatype-not-utf8.jpg     ../fixture-signed.jpg with the first byte of the thumbnail's media type
 *                              (the embedded-file description box, `bfdb`) set to 0xFF: not UTF-8
 *   certificate-time-nul.jpg   ../matrix/es256.jpg with one digit of the signer's UTCTime set to NUL,
 *                              which makes openssl_x509_parse() warn "Illegal length in timestamp"
 *
 * Found by the security review of 2026-09-25 (the second as a fuzzer mutation of es256.jpg, reduced
 * here to the one byte that matters). Usage: php bin/make-hostile-input-variants.php. Tooling.
 */

$fixtures = dirname(__DIR__).'/tests/Fixtures';
$dir = $fixtures.'/hostile';
if (! is_dir($dir) && ! mkdir($dir, 0o755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}

/** $from with the byte at $at, which must be $expect, set to $to. */
$patch = static function (string $from, int $at, string $expect, string $to): string {
    $bytes = (string) file_get_contents($from);
    if (substr($bytes, $at, 1) !== $expect) {
        throw new RuntimeException(sprintf('%s: byte %d is %s, not %s', $from, $at, bin2hex(substr($bytes, $at, 1)), bin2hex($expect)));
    }
    $bytes[$at] = $to;

    return $bytes;
};

$signed = $fixtures.'/fixture-signed.jpg';
$at = strpos((string) file_get_contents($signed), "bfdb\0image/jpeg");
if ($at === false) {
    throw new RuntimeException('no bfdb box with image/jpeg in fixture-signed.jpg');
}
file_put_contents($dir.'/mediatype-not-utf8.jpg', $patch($signed, $at + 5, 'i', "\xFF"));

// `170d 3330 3038 ...`: the UTCTime 300826184640Z, the signer's notAfter; its first digit at 1410
file_put_contents($dir.'/certificate-time-nul.jpg', $patch($fixtures.'/matrix/es256.jpg', 1410, '0', "\0"));

foreach (['mediatype-not-utf8.jpg', 'certificate-time-nul.jpg'] as $name) {
    printf("%s  %s\n", hash_file('sha256', $dir.'/'.$name), $name);
}
