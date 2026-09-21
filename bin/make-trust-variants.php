<?php

declare(strict_types=1);

/*
 * SPEC-014 (step 31): builds the trust variants. One store variant —
 * x5chain-leaf-only: the PNG fixture's COSE_Sign1 with the intermediate
 * removed from the protected header's x5chain, and the unprotected `pad`
 * grown by exactly the same number of bytes, so that no box length, no
 * exclusion and no hashed URI changes: only the signature breaks (the
 * Sig_structure covers the protected header) and the chain no longer
 * reaches an anchor. Two settings variants — intermediate-anchor (the EC
 * intermediate as the only anchor) and allowed-plus-wrong-root (the allowed
 * list next to the RSA root as the only anchor). Prints the SHA-256 of the
 * store. Run from the repository root. Tooling, not product code.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;

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

// Step 31 offsets (PNG store): signature superbox 33672, its cbor box 33720, the COSE_Sign1 at 33728:
// d2 84 | 59 0505 <protected: a2 01 26 18 21 82 <cert1 654> <cert2 625>> | a1 63 pad 59 2ab4 <10932 zero bytes> | f6 | <signature>.
$COSE = 33728;
$PROTECTED = $COSE + 2;                                  // 59 05 05
$ARRAY = 33738;                                          // 82
$CERT2 = 34393;                                          // 625 bytes, to 35018
$CERT2_LENGTH = 35018 - 34393;
if (substr($s, $PROTECTED, 3) !== "\x59\x05\x05" || $s[$ARRAY] !== "\x82" || substr($s, 35018, 8) !== "\xa1\x63pad\x59\x2a\xb4") {
    throw new RuntimeException('the COSE_Sign1 is not laid out as measured in step 31');
}

$v = $s;
$v = bReplace($v, $ARRAY, "\x82", "\x81");                                                   // one certificate in x5chain
$v = bSplice($v, $CERT2, $CERT2_LENGTH, '', []);                                             // the intermediate gone; nothing else adjusted yet
$v = bReplace($v, $PROTECTED, "\x59\x05\x05", "\x59".pack('n', 0x0505 - $CERT2_LENGTH));    // the protected bstr is 625 bytes shorter
$padHeader = 35018 - $CERT2_LENGTH + 5;                                                      // a1 63 pad, then 59 2a b4
$v = bReplace($v, $padHeader, "\x59\x2a\xb4", "\x59".pack('n', 0x2AB4 + $CERT2_LENGTH));    // the pad 625 bytes longer
$v = bSplice($v, $padHeader + 3 + 0x2AB4, 0, str_repeat("\0", $CERT2_LENGTH), []);         // the bytes themselves
if (strlen($v) !== strlen($s)) {
    throw new RuntimeException('the store changed length');
}

$dir = $root.'/tests/Fixtures/binding';
file_put_contents("{$dir}/x5chain-leaf-only.bin", $v);
file_put_contents("{$dir}/x5chain-leaf-only.png", pngWithStore($png, $v));
printf("%s  %7d  x5chain-leaf-only.bin\n", hash('sha256', $v), strlen($v));

// ---- settings variants, from the public material ----
$trust = $root.'/tests/Fixtures/trust';
$blocks = static function (string $file): array {
    preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----\n/s', (string) file_get_contents($file), $m);

    return $m[0];
};
$full = json_decode((string) file_get_contents("{$trust}/full.settings.json"), true, 512, JSON_THROW_ON_ERROR);
assert(is_array($full) && is_array($full['trust']));
$write = static function (string $name, array $settings) use ($trust): void {
    file_put_contents("{$trust}/{$name}.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
};
$intermediate = $full;
$intermediate['trust']['trust_anchors'] = $blocks("{$trust}/es256_certs.pem")[1];          // the EC intermediate alone
$write('intermediate-anchor', $intermediate);
$allowedWrongRoot = $full;
$allowedWrongRoot['trust']['trust_anchors'] = $blocks("{$trust}/trust_anchors.pem")[1];    // the RSA root alone
$allowedWrongRoot['trust']['allowed_list'] = (string) file_get_contents("{$trust}/allowed_list.pem");
$write('allowed-plus-wrong-root', $allowedWrongRoot);
