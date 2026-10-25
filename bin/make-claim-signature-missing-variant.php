<?php

declare(strict_types=1);

/*
 * Step 432: a redacted ingredient whose ingredient assertion records no `claimSignature` (C2PA 2.4
 * §15.11.3.3.1, `ingredient.claimSignature.missing`). From tests/Fixtures/redactions/redacted-with-action.png:
 * the key `claimSignature` in the active manifest's c2pa.ingredient.v3 is renamed `claimSignaturf`, a
 * same-length patch, so the field is absent; its hash is recomputed where it is recorded (the claim and the
 * c2pa.opened action), and so is the actions assertion's. The
 * claim is not re-signed (no key exists any more), so the active signature is left broken, as in the
 * binding variants. Tooling, not the verification path.
 *
 * Run:   php bin/make-claim-signature-missing-variant.php
 *
 * Writes: tests/Fixtures/redactions/claim-signature-missing.png
 */

require __DIR__.'/../vendor/autoload.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Jumbf\Superbox;
use Provemark\C2paVerifier\Manifest\ManifestStore;

$dir = dirname(__DIR__).'/tests/Fixtures/redactions';
$png = (string) file_get_contents("{$dir}/redacted-with-action.png");
$stream = fopen("{$dir}/redacted-with-action.png", 'rb');
if ($stream === false) {
    throw new RuntimeException('cannot open redacted-with-action.png');
}
$extracted = (new PngManifestStoreExtractor)->extract($stream);
if ($extracted === null) {
    throw new RuntimeException('no store in redacted-with-action.png');
}
$store = $extracted->bytes;
$active = ManifestStore::fromTree((new JumbfParser)->parse($store))->active;

$box = null;
foreach ($active->assertionStore->children as $child) {
    if ($child instanceof Superbox && $child->description->label === 'c2pa.ingredient.v3') {
        $box = $child;
    }
}
$entry = null;
foreach ($active->claim->gatheredAssertions as $reference) {
    if ($reference->url === 'self#jumbf=c2pa.assertions/c2pa.ingredient.v3') {
        $entry = $reference;
    }
}
if ($box === null || $entry === null) {
    throw new RuntimeException('the active manifest has no c2pa.ingredient.v3, or its claim does not list it');
}

// the key, once, inside the ingredient box
$key = "\x6eclaimSignature";
$at = strpos($store, $key, $box->offset);
if ($at === false || $at >= $box->offset + $box->length || substr_count($store, $key) !== 1) {
    throw new RuntimeException('expected one claimSignature key, inside the ingredient box');
}
$store = substr_replace($store, "\x6eclaimSignaturf", $at, strlen($key));

// the hash of the ingredient box, its payload after LBox and TBox, wherever it is recorded: the claim and the
// c2pa.opened action's reference. The actions box changes with it, so its hash in the claim is recomputed too
$rehash = static function (string $store, Superbox $box, string $old): string {
    $new = hash('sha256', substr($store, $box->offset + 8, $box->length - 8), true);

    return str_replace($old, $new, $store);
};
$store = $rehash($store, $box, $entry->hash->bytes);
$actions = $actionsEntry = null;
foreach ($active->assertionStore->children as $child) {
    if ($child instanceof Superbox && $child->description->label === 'c2pa.actions.v2') {
        $actions = $child;
    }
}
foreach ($active->claim->gatheredAssertions as $reference) {
    if ($reference->url === 'self#jumbf=c2pa.assertions/c2pa.actions.v2') {
        $actionsEntry = $reference;
    }
}
if ($actions === null || $actionsEntry === null || substr_count($store, $actionsEntry->hash->bytes) !== 1) {
    throw new RuntimeException('expected one c2pa.actions.v2, listed once in the claim');
}
$store = $rehash($store, $actions, $actionsEntry->hash->bytes);

// the caBX chunk: its data replaced, the same length, the CRC recomputed
$data = strpos($png, $extracted->bytes);
if ($data === false || substr($png, $data - 4, 4) !== 'caBX') {
    throw new RuntimeException('the store is not the data of a caBX chunk');
}
$png = substr_replace($png, $store.pack('N', crc32('caBX'.$store)), $data, strlen($store) + 4);
file_put_contents("{$dir}/claim-signature-missing.png", $png);
printf("%s  claim-signature-missing.png\n", hash('sha256', $png));
