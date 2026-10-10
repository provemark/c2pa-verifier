<?php

declare(strict_types=1);

/*
 * SPEC-067 (step 342): builds the data-box variants under tests/Fixtures/databox/
 * from c2pasign-logo.png — an AI original made with c2patool 0.9.12, uploaded and
 * published through the Drupal module C2PA Sign 1.4.11 (which also signs with
 * c2patool 0.9.12) on a site with a logo, so every manifest C2PA Sign wrote
 * carries a c2pa.databoxes store holding the logo as `c2pa.data`, and its
 * claim_generator_info icon is a hashed URI to that box.
 *
 * Every variant edits only the active manifest's c2pa.databoxes store, and
 * only in place (same length): the claims, their signatures and the PNG's
 * hard binding stay valid, so each variant differs from the original in the
 * one thing its criterion is about. Each is written as .png with the caBX
 * chunk's CRC recomputed. Prints the SHA-256 of every file.
 * Run from the repository root. Tooling, not product code.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Jumbf\Superbox;

$root = dirname(__DIR__);
$dir = $root.'/tests/Fixtures/databox';
$source = $dir.'/c2pasign-logo.png';
$png = (string) file_get_contents($source);
$stream = fopen($source, 'rb');
if ($stream === false) {
    throw new RuntimeException('cannot open '.$source);
}
$extracted = (new PngManifestStoreExtractor)->extract($stream);
if ($extracted === null) {
    throw new RuntimeException('no manifest store in '.$source);
}
$store = $extracted->bytes;
$tree = (new JumbfParser)->parse($store);

// The active manifest is the last one in the store (C2PA 2.4 §11.1.4.4).
$manifests = $tree->superboxes();
$active = $manifests[count($manifests) - 1];
// Found by label in the raw children: before this spec the parser kept the
// c2db store as an unknown box, so it is located by bytes, not by child().
$dbLabel = bFind($store, "c2pa.databoxes\0", $active->offset, $active->offset + $active->length);
$dataLabel = bFind($store, "c2pa.data\0", $dbLabel + 15, $active->offset + $active->length);
// A superbox's label sits 8 (jumb header) + 8 (jumd header) + 16 (UUID) + 1 (toggles) bytes in.
$dataBox = $dataLabel - 33;
$dataLength = bU32($store, $dataBox);
if (substr($store, $dataBox + 4, 4) !== 'jumb') {
    throw new RuntimeException('c2pa.data is not where it was measured');
}

/** The PNG with its caBX chunk's store replaced (same length) and the chunk's CRC recomputed. */
function withStore(string $png, string $old, string $new): string
{
    $at = strpos($png, 'caBX'.$old);
    if ($at === false || strlen($old) !== strlen($new)) {
        throw new RuntimeException('caBX chunk not found, or the store changed length');
    }

    return substr($png, 0, $at + 4).$new.pack('N', crc32('caBX'.$new)).substr($png, $at + 4 + strlen($old) + 4);
}

$variants = [
    // AC2: the last byte of the box (inside the logo's bytes) changes.
    'databox-byte-changed' => bReplace($store, $dataBox + $dataLength - 1, $store[$dataBox + $dataLength - 1], chr(ord($store[$dataBox + $dataLength - 1]) ^ 0x01)),
    // AC3: no box carries the label the icon names.
    'databox-label-renamed' => bReplace($store, $dataLabel, 'c2pa.data', 'c2pa.datb'),
    // AC3: the manifest has no c2pa.databoxes store.
    'databox-store-renamed' => bReplace($store, $dbLabel, 'c2pa.databoxes', 'c2pa.databoxez'),
    // AC5: the named child is not a superbox.
    'databox-child-not-superbox' => bReplace($store, $dataBox + 4, 'jumb', 'jumx'),
];

foreach ($variants as $name => $bytes) {
    $out = withStore($png, $store, $bytes);
    file_put_contents($dir.'/'.$name.'.png', $out);
    echo $name, '.png ', hash('sha256', $out), "\n";
}
echo 'c2pasign-logo.png ', hash('sha256', $png), "\n";
