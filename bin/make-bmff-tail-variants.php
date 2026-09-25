<?php

declare(strict_types=1);

/*
 * Step 152 (SPEC-027 amendment 4): ISOBMFF files with fewer than eight bytes after their last
 * top-level box — too short for a box header, so the box walk never saw them.
 *
 *   appended.mp4               ../fixture-signed.mp4 with 7 bytes appended after signing (the
 *                              review's shape: the bytes were never hashed here)
 *   signed-tail.mp4            ../fixture-unsigned.mp4 with 7 bytes after mdat, then signed: c2pa-rs
 *                              hashes the tail, so this is a genuine file
 *   signed-free-tail.mp4       the same with an 8-byte free box before the tail, so the last box is
 *                              one c2pa-rs excludes (/free); the tail is still hashed
 *   signed-free-tail-changed.mp4   signed-free-tail.mp4 with the tail's last byte changed
 *   ../bmff-fragmented/broken/seg_3-tail.m4s   seg_3 with 7 bytes appended
 *
 * Signing runs c2patool with a manifest definition whose private_key and sign_cert point at the
 * c2pa-rs ES256 test pair outside this repository (fixture-signed.manifest.json shows the shape).
 *
 * Usage: php bin/make-bmff-tail-variants.php <c2patool> <manifest.json> <scratch-dir>. Tooling.
 */

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$c2patool, $manifest, $scratch] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
if (! is_executable($c2patool) || ! is_file($manifest) || ! is_dir($scratch)) {
    fwrite(STDERR, "usage: php bin/make-bmff-tail-variants.php <c2patool> <manifest.json> <scratch-dir>\n");
    exit(2);
}

$fixtures = dirname(__DIR__).'/tests/Fixtures';
$dir = $fixtures.'/bmff-tail';
if (! is_dir($dir) && ! mkdir($dir, 0o755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
$tail = 'ABCDEFG';   // seven bytes: one short of a box header

/** Sign an unsigned file with c2patool into $to. */
$sign = static function (string $from, string $to) use ($c2patool, $manifest): void {
    $command = sprintf('%s %s -m %s -o %s -f 2>&1', escapeshellarg($c2patool), escapeshellarg($from), escapeshellarg($manifest), escapeshellarg($to));
    exec($command, $output, $status);
    if ($status !== 0) {
        throw new RuntimeException("c2patool failed on {$from}: ".implode("\n", $output));
    }
};

$unsigned = (string) file_get_contents($fixtures.'/fixture-unsigned.mp4');

file_put_contents($dir.'/appended.mp4', file_get_contents($fixtures.'/fixture-signed.mp4').$tail);

file_put_contents($scratch.'/tail-unsigned.mp4', $unsigned.$tail);
$sign($scratch.'/tail-unsigned.mp4', $dir.'/signed-tail.mp4');

file_put_contents($scratch.'/free-tail-unsigned.mp4', $unsigned."\x00\x00\x00\x08free".$tail);
$sign($scratch.'/free-tail-unsigned.mp4', $dir.'/signed-free-tail.mp4');

$changed = (string) file_get_contents($dir.'/signed-free-tail.mp4');
if (substr($changed, -strlen($tail)) !== $tail) {
    throw new RuntimeException('c2patool did not keep the tail at the end of the file');
}
$changed[strlen($changed) - 1] = 'X';
file_put_contents($dir.'/signed-free-tail-changed.mp4', $changed);

file_put_contents($fixtures.'/bmff-fragmented/broken/seg_3-tail.m4s', file_get_contents($fixtures.'/bmff-fragmented/seg_3.m4s').$tail);

foreach (['appended.mp4', 'signed-tail.mp4', 'signed-free-tail.mp4', 'signed-free-tail-changed.mp4'] as $name) {
    printf("%s  %s\n", hash_file('sha256', $dir.'/'.$name), $name);
}
