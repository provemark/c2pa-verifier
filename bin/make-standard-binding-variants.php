<?php

declare(strict_types=1);

/*
 * Step 150 (SPEC-022 amendment 6): two variants of c2pa-rs/update_manifest.jpg in which the active
 * manifest is a *standard* manifest without a hard binding of its own.
 *
 *   standard-no-binding            the active manifest's box UUID c2um -> c2ma (same length); no other
 *                                  update manifest is left in the store
 *   standard-borrows-with-update   the same, with a copy of the original update manifest (its label
 *                                  changed in one character) placed before it, so the store still
 *                                  holds a c2um that nothing references
 *
 * Nothing is signed. A JUMBF box's type UUID lies outside the claim, and nothing references the
 * active manifest, so its claim signature stays valid; the copy is never reached by the ingredient
 * graph. The second store no longer fits one APP11 segment and is split over several, each
 * repeating LBox and TBox (C2PA 2.4 §A.3.3.1).
 *
 * Usage: php bin/make-standard-binding-variants.php <out-dir>. Tooling.
 */

require __DIR__.'/../vendor/autoload.php';

use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Jumbf\JumbfParser;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
$dir = $argv[1] ?? null;
if (! is_string($dir) || ! is_dir($dir)) {
    fwrite(STDERR, "usage: php bin/make-standard-binding-variants.php <out-dir>\n");
    exit(2);
}

$jpeg = (string) file_get_contents(__DIR__.'/../tests/Fixtures/c2pa-rs/update_manifest.jpg');
$stream = fopen('php://memory', 'w+b');
if ($stream === false) {
    throw new RuntimeException('cannot open a memory stream');
}
fwrite($stream, $jpeg);
rewind($stream);
$extracted = (new JpegManifestStoreExtractor)->extract($stream);
if ($extracted === null || strlen($extracted->bytes) !== 43595) {
    throw new RuntimeException('the update-manifest store is not the one measured in step 57');
}
$store = $extracted->bytes;
$segmentAt = $extracted->ranges[0]['start'];

$manifests = (new JumbfParser)->parse($store)->superboxes();
if (count($manifests) !== 2) {
    throw new RuntimeException('expected two manifests: the parent and the update manifest');
}
[$parent, $update] = $manifests;
$head = substr($store, 0, $parent->offset);
$parentBytes = substr($store, $parent->offset, $parent->length);
$updateBytes = substr($store, $update->offset, $update->length);

$c2um = (string) hex2bin('6332756d00110010800000aa00389b71');
$c2ma = (string) hex2bin('63326d6100110010800000aa00389b71');
$at = strpos($updateBytes, $c2um);
if ($at === false || strpos($updateBytes, $c2um, $at + 1) !== false) {
    throw new RuntimeException('expected exactly one c2um UUID in the update manifest');
}
$standard = substr_replace($updateBytes, $c2ma, $at, 16);

$label = $update->description->label;
$other = substr($label, 0, -1).($label[strlen($label) - 1] === 'a' ? 'b' : 'a');
$copy = str_replace($label, $other, $updateBytes);

/** The store with its outer LBox set to its new length. */
$rebox = static fn (string $s): string => substr_replace($s, pack('N', strlen($s)), 0, 4);

/**
 * The JPEG with its one APP11 segment replaced by as many as the store needs: CI "JP", the box
 * instance number kept, packet sequence numbers from 1, and in each segment the store's LBox and
 * TBox followed by the next piece of its payload.
 */
$withStore = static function (string $store) use ($jpeg, $segmentAt): string {
    $header = unpack('n', substr($jpeg, $segmentAt + 2, 2));
    if ($header === false || ! is_int($header[1])) {
        throw new RuntimeException('cannot read the APP11 segment length');
    }
    $ciAndEn = substr($jpeg, $segmentAt + 4, 4);
    $boxHeader = substr($store, 0, 8);
    $segments = '';
    foreach (str_split(substr($store, 8), 65000) as $i => $piece) {
        $body = $ciAndEn.pack('N', $i + 1).$boxHeader.$piece;
        $segments .= "\xff\xeb".pack('n', 2 + strlen($body)).$body;
    }

    return substr($jpeg, 0, $segmentAt).$segments.substr($jpeg, $segmentAt + 2 + $header[1]);
};

if ($withStore($store) !== $jpeg) {
    throw new RuntimeException('the APP11 rebuild is not byte-exact on the unchanged store');
}

$variants = [
    'standard-no-binding' => $rebox($head.$parentBytes.$standard),
    'standard-borrows-with-update' => $rebox($head.$parentBytes.$copy.$standard),
];
foreach ($variants as $name => $variant) {
    $file = $withStore($variant);
    file_put_contents("{$dir}/{$name}.jpg", $file);
    printf("%s  %d bytes  %s\n", hash('sha256', $file), strlen($file), $name);
}
