<?php

declare(strict_types=1);

/*
 * Build the certificates of SPEC-086 AC8: signer certificates whose
 * authority information access (RFC 5280 §4.2.2.1) holds urls the verifier
 * must not pass to a revocation source, and one whose AIA is not DER.
 *
 * Each is self-signed by a throwaway P-256 key that exists only in this
 * process's memory; no key is written anywhere. What is committed is the
 * public certificates. Only their extensions matter to the test.
 *
 * Run:   php bin/make-aia-certificates.php [name ...]   (no name: all)
 * Needs: ext-openssl. Tooling, not the verification path.
 *
 * Writes:
 *   tests/Fixtures/aia/filter.crt   ldap, caIssuers, five http(s) OCSP urls and one over 2,048 bytes
 *   tests/Fixtures/aia/broken.crt   an AIA extension whose value is truncated DER
 *   tests/Fixtures/aia/control.crt  OCSP urls with CR LF, a final LF, NUL, a space, a tab, DEL and a non-ASCII byte, then
 *                                   one plain url (SPEC-086 amendment 4, step 487); openssl's config refuses
 *                                   those bytes in a URI, so the AIA is written as DER
 */

$root = dirname(__DIR__);
$out = $root.'/tests/Fixtures/aia';
if (! is_dir($out) && ! mkdir($out, 0o755, true)) {
    fwrite(STDERR, "cannot create {$out}\n");
    exit(1);
}

/**
 * A DER element with a definite length.
 *
 * @param  int<0, 255>  $tag
 */
function aiaDer(int $tag, string $contents): string
{
    $n = strlen($contents);

    return chr($tag).($n < 128 ? chr($n) : ($n < 256 ? "\x81".chr($n) : "\x82".pack('n', $n))).$contents;
}

/** An AIA extension value holding these OCSP urls, as openssl's DER: syntax. */
function aiaOcsp(string ...$urls): string
{
    // AccessDescription { id-ad-ocsp, uniformResourceIdentifier [6] IA5String }
    $descriptions = implode('', array_map(static fn (string $url): string => aiaDer(0x30, aiaDer(0x06, "\x2b\x06\x01\x05\x05\x07\x30\x01").aiaDer(0x86, $url)), $urls));

    return '1.3.6.1.5.5.7.1.1 = DER:'.implode(':', str_split(strtoupper(bin2hex(aiaDer(0x30, $descriptions))), 2));
}

$long = 'http://ocsp-long.example/'.str_repeat('a', 2050);
$probes = [
    'filter' => 'authorityInfoAccess = OCSP;URI:ldap://ocsp.example/, caIssuers;URI:http://ca.example/ca.crt, '
        .'OCSP;URI:http://ocsp1.example/, OCSP;URI:'.$long.', OCSP;URI:HTTPS://ocsp2.example/, '
        .'OCSP;URI:http://ocsp3.example/, OCSP;URI:http://ocsp4.example/, OCSP;URI:http://ocsp5.example/',
    // SEQUENCE of length 5 holding three bytes: the reader runs out
    'broken' => '1.3.6.1.5.5.7.1.1 = DER:30:05:06:01:2B',
    'control' => aiaOcsp(
        "http://ocsp-crlf.example/\r\nX-Injected: 1",
        "http://ocsp-lf.example/\n",
        "http://ocsp-nul.example/\0",
        'http://ocsp space.example/',
        "http://ocsp-tab.example/\t",
        "http://ocsp-del.example/\x7f",
        "http://ocsp-high.example/\xc3\xa9",
        'http://ocsp-plain.example/',
    ),
];
/** @var list<string> $argv */
$argv = $_SERVER['argv'];
$only = array_slice($argv, 1);
if ($only !== []) {
    $probes = array_intersect_key($probes, array_flip($only));
}

foreach ($probes as $name => $extension) {
    $config = tempnam(sys_get_temp_dir(), 'aia');
    if ($config === false) {
        fwrite(STDERR, "cannot create a config file\n");
        exit(1);
    }
    file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[probe]\nbasicConstraints = CA:FALSE\n{$extension}\n");
    $options = ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'probe'];
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $config]);
    if ($key === false) {
        fwrite(STDERR, 'no key: '.openssl_error_string()."\n");
        exit(1);
    }
    $byReference = $key;   // openssl_csr_new takes the key by reference
    $csr = openssl_csr_new(['commonName' => "SPEC-086 AIA {$name}", 'organizationName' => 'provemark fixtures'], $byReference, $options);
    $certificate = $csr === false || $csr === true ? false : openssl_csr_sign($csr, null, $key, 3650, $options, 86);
    unlink($config);
    if ($certificate === false || ! openssl_x509_export($certificate, $pem)) {
        fwrite(STDERR, "{$name}: ".openssl_error_string()."\n");
        exit(1);
    }
    file_put_contents("{$out}/{$name}.crt", $pem);
    echo "{$out}/{$name}.crt\n";
}
