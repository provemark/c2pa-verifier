<?php

declare(strict_types=1);

/*
 * Step 161a (SPEC-045): the three files for the criteria that use a file. Each is
 * ../fixture-signed.png with one unreferenced assertion appended to the active manifest's
 * assertion store (every enclosing box length grown, the caBX chunk rebuilt); nothing is re-signed.
 *
 *   json-string-200k.png     a JSON object of one 200 KiB string: within MAX_JSON_BYTES, two items
 *   json-numbers-2x150k.png  two JSON arrays of 150 KiB of `10,` each: about 51,200 items each,
 *                            within the store's budget alone, over it together (SPEC-045 amendment 1)
 *   bfdb-empty.png           an embedded-file assertion whose bfdb box is empty
 *
 * The inputs larger than about 1 MB are built by the tests in memory. Found by the review of
 * step 157. Both c2patool versions refuse all three for the undeclared assertion first (step 160).
 *
 * Usage: php bin/make-hostile-input-2-variants.php. Tooling.
 */

require __DIR__.'/../vendor/autoload.php';

use Provemark\C2paVerifier\Jumbf\JumbfParser;

$fixtures = dirname(__DIR__).'/tests/Fixtures';
$dir = $fixtures.'/hostile-2';
if (! is_dir($dir) && ! mkdir($dir, 0o755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}

function h2Box(string $type, string $data): string
{
    return pack('N', 8 + strlen($data)).$type.$data;
}

function h2Assertion(string $uuidHex, string $label, string $contents): string
{
    return h2Box('jumb', h2Box('jumd', (string) hex2bin($uuidHex)."\x03".$label."\0").$contents);
}

/** fixture-signed.png with $extra appended to the active manifest's assertion store. */
function h2PngWith(string $png, string $extra): string
{
    $at = (int) strpos($png, 'caBX');
    /** @var array{1: int} $length */
    $length = unpack('N', substr($png, $at - 4, 4));
    $store = substr($png, $at + 4, $length[1]);
    $root = (new JumbfParser)->parse($store);
    $manifest = $root->superboxes()[count($root->superboxes()) - 1];
    $assertions = $manifest->child('c2pa.assertions') ?? throw new RuntimeException('no assertion store');
    $store = substr_replace($store, $extra, $assertions->offset + $assertions->length, 0);
    foreach ([0, $manifest->offset, $assertions->offset] as $box) {
        /** @var array{1: int} $old */
        $old = unpack('N', substr($store, $box, 4));
        $store = substr_replace($store, pack('N', $old[1] + strlen($extra)), $box, 4);
    }

    return substr($png, 0, $at - 4).pack('N', strlen($store)).'caBX'.$store.pack('N', crc32('caBX'.$store)).substr($png, $at + 8 + $length[1]);
}

const H2_JSON = '6a736f6e00110010800000aa00389b71';
const H2_EMBEDDED = '40cb0c32bb8a489da70b2ad6f47f4369';

$png = (string) file_get_contents($fixtures.'/fixture-signed.png');
$numbers = static fn (int $bytes): string => '['.rtrim(str_repeat('10,', intdiv($bytes, 3)), ',').']';

$files = [
    'json-string-200k' => h2Assertion(H2_JSON, 'org.example.string', h2Box('json', '{"s":"'.str_repeat('a', 200 * 1024).'"}')),
    'json-numbers-2x150k' => h2Assertion(H2_JSON, 'org.example.numbers.1', h2Box('json', $numbers(150 * 1024)))
        .h2Assertion(H2_JSON, 'org.example.numbers.2', h2Box('json', $numbers(150 * 1024))),
    'bfdb-empty' => h2Assertion(H2_EMBEDDED, 'c2pa.thumbnail.claim.jpeg.x', h2Box('bfdb', '').h2Box('bidb', 'x')),
];
foreach ($files as $name => $extra) {
    file_put_contents("{$dir}/{$name}.png", h2PngWith($png, $extra));
    printf("%-22s %8d bytes\n", $name, filesize("{$dir}/{$name}.png"));
}
