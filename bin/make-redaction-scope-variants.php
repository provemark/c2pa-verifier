<?php

declare(strict_types=1);

/*
 * Build the fixtures of SPEC-035 amendment 5 (step 158): the signed PNG fixture with its
 * c2pa.thumbnail.claim box taken out of the active manifest's assertion store and, in front of
 * the active manifest, an unsigned manifest of exactly the removed length, which nothing
 * references. The active manifest's claim and signature are untouched and the store keeps its
 * length, so the claim signature and the data hash still match; nothing is re-signed.
 *
 *   unreferenced-redacts-thumbnail.png  the unreferenced manifest's claim redacts the thumbnail
 *   unreferenced-no-redaction.png       the same, with an empty redacted_assertions: the guard
 *
 * The unreferenced claim carries an `alg`: without one c2pa-rs stops at "unknown algorithm"
 * before any redaction is read (step 24). Found by the review of step 157.
 *
 * Both c2patool versions judge every variant with ../trust/full.settings.json, under which the
 * fixture itself is Trusted.
 *
 * Usage: php bin/make-redaction-scope-variants.php <c2patool-0.28.0> <c2patool-0.27.22>
 * Writes:
 *   tests/Fixtures/redaction-scope/<variant>.png
 *   tests/Fixtures/c2patool/redaction-scope/<variant>--<version>.json (or .error.txt)
 * Tooling.
 */

require __DIR__.'/variant-helpers.php';

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$new, $old] = [$argv[1] ?? '', $argv[2] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_file($new) || ! is_file($old) || $version($new) !== 'c2patool 0.28.0' || $version($old) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-redaction-scope-variants.php <c2patool-0.28.0> <c2patool-0.27.22>\n");
    exit(1);
}

const RS_MANIFEST = '63326d61-0011-0010-8000-00aa00389b71';
const RS_ASSERTIONS = '63326173-0011-0010-8000-00aa00389b71';
const RS_CLAIM = '6332636c-0011-0010-8000-00aa00389b71';
const RS_SIGNATURE = '63326373-0011-0010-8000-00aa00389b71';
const RS_CBOR = '63626f72-0011-0010-8000-00aa00389b71';

/** A CBOR head: major type and argument, shortest form. */
function rsHead(int $major, int $n): string
{
    $major <<= 5;

    return match (true) {
        $n < 24 => pack('C', $major | $n),
        $n < 0x100 => pack('CC', $major | 24, $n),
        $n < 0x10000 => pack('Cn', $major | 25, $n),
        default => pack('CN', $major | 26, $n),
    };
}

/** Text as a CBOR text string, or ['bytes' => …] as a byte string; lists and maps nest. */
function rsCbor(mixed $value): string
{
    if (is_string($value)) {
        return rsHead(3, strlen($value)).$value;
    }
    if (! is_array($value)) {
        throw new RuntimeException('rsCbor: '.get_debug_type($value));
    }
    if (array_keys($value) === ['bytes'] && is_string($value['bytes'])) {
        return rsHead(2, strlen($value['bytes'])).$value['bytes'];
    }
    if (array_is_list($value)) {
        return rsHead(4, count($value)).implode('', array_map(rsCbor(...), $value));
    }
    $out = rsHead(5, count($value));
    foreach ($value as $key => $item) {
        $out .= rsCbor((string) $key).rsCbor($item);
    }

    return $out;
}

function rsBox(string $type, string $payload): string
{
    return pack('N', 8 + strlen($payload)).$type.$payload;
}

function rsSuperbox(string $uuid, string $label, string $children): string
{
    return rsBox('jumb', rsBox('jumd', (string) hex2bin(str_replace('-', '', $uuid))."\x03".$label."\0").$children);
}

/** @return list<array{start: int, length: int}> the boxes laid end to end between $from and $to */
function rsBoxes(string $bytes, int $from, int $to): array
{
    $boxes = [];
    for ($p = $from; $p < $to; $p += bU32($bytes, $p)) {
        $boxes[] = ['start' => $p, 'length' => bU32($bytes, $p)];
    }

    return $boxes;
}

/** The label in a superbox's description box. */
function rsLabel(string $bytes, int $superbox): string
{
    $d = $superbox + 8;

    return (string) strstr(substr($bytes, $d + 8 + 17, bU32($bytes, $d) - 8 - 17)."\0", "\0", true);
}

/** @return list<array{start: int, length: int}> a superbox's children after its description box */
function rsChildren(string $bytes, int $superbox): array
{
    return array_slice(rsBoxes($bytes, $superbox + 8, $superbox + bU32($bytes, $superbox)), 1);
}

$root = dirname(__DIR__);
$png = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.png');
$store = substr($png, 33 + 8, bU32($png, 33));
$manifests = rsChildren($store, 0);
if (count($manifests) !== 1) {
    throw new RuntimeException('fixture-signed.png should hold one manifest');
}
$active = $manifests[0];
$label = rsLabel($store, $active['start']);
$target = 'c2pa.thumbnail.claim';

// the active manifest with the target taken out of its assertion store, every other byte kept
$removed = 0;
$activeChildren = '';
foreach (rsChildren($store, $active['start']) as $child) {
    if (rsLabel($store, $child['start']) !== 'c2pa.assertions') {
        $activeChildren .= substr($store, $child['start'], $child['length']);

        continue;
    }
    $assertions = substr($store, $child['start'] + 8, rsBoxes($store, $child['start'] + 8, $child['start'] + $child['length'])[0]['length']);
    foreach (rsChildren($store, $child['start']) as $assertion) {
        if (rsLabel($store, $assertion['start']) === $target) {
            $removed = $assertion['length'];

            continue;
        }
        $assertions .= substr($store, $assertion['start'], $assertion['length']);
    }
    $activeChildren .= rsBox('jumb', $assertions);
}
if ($removed === 0) {
    throw new RuntimeException("no {$target} in the active manifest");
}
$description = substr($store, $active['start'] + 8, bU32($store, $active['start'] + 8));
$newActive = rsBox('jumb', $description.$activeChildren);

/** An unsigned manifest nothing references, padded to $length bytes. */
$unreferenced = static function (array $redacted, int $length): string {
    $build = static function (int $pad) use ($redacted): string {
        $assertions = rsSuperbox(RS_ASSERTIONS, 'c2pa.assertions', rsSuperbox(RS_CBOR, 'org.example.pad', rsBox('cbor', rsCbor(['pad' => ['bytes' => str_repeat("\0", $pad)]]))));
        $claim = [
            'alg' => 'sha256',
            'instanceID' => 'xmp:iid:00000000-0000-0000-0000-000000000000',
            'claim_generator_info' => ['name' => 'step 158 probe'],
            'signature' => 'self#jumbf=c2pa.signature',
            'created_assertions' => [['url' => 'self#jumbf=c2pa.assertions/org.example.pad', 'hash' => ['bytes' => str_repeat("\0", 32)]]],
            'redacted_assertions' => $redacted,
        ];

        return rsSuperbox(RS_MANIFEST, 'urn:c2pa:00000000-0000-0000-0000-000000000000', $assertions
            .rsSuperbox(RS_CLAIM, 'c2pa.claim.v2', rsBox('cbor', rsCbor($claim)))
            .rsSuperbox(RS_SIGNATURE, 'c2pa.signature', rsBox('cbor', rsCbor(['bytes' => '']))));
    };
    $pad = 0;
    for ($i = 0; $i < 8; $i++) {
        $manifest = $build($pad);
        if (strlen($manifest) === $length) {
            return $manifest;
        }
        $pad += $length - strlen($manifest);
    }
    throw new RuntimeException("cannot size the unreferenced manifest to {$length} bytes");
};

$storeDescription = substr($store, 8, bU32($store, 8));
$variants = [
    'unreferenced-redacts-thumbnail' => ["self#jumbf=/c2pa/{$label}/c2pa.assertions/{$target}"],
    'unreferenced-no-redaction' => [],
];
$settings = $root.'/tests/Fixtures/trust/full.settings.json';
$out = $root.'/tests/Fixtures/redaction-scope';
$oracles = $root.'/tests/Fixtures/c2patool/redaction-scope';
foreach ([$out, $oracles] as $dir) {
    if (! is_dir($dir) && ! mkdir($dir, 0o755, true)) {
        throw new RuntimeException("cannot create {$dir}");
    }
}
foreach (glob("{$oracles}/*") ?: [] as $stale) {
    unlink($stale);
}
foreach ($variants as $name => $redacted) {
    $newStore = rsBox('jumb', $storeDescription.$unreferenced($redacted, $removed).$newActive);
    if (strlen($newStore) !== strlen($store)) {
        throw new RuntimeException("{$name}: the store changed length");
    }
    file_put_contents("{$out}/{$name}.png", pngWithStore($png, $newStore));
    foreach (['0.28.0' => $new, '0.27.22' => $old] as $tag => $tool) {
        $lines = [];
        exec(escapeshellarg($tool).' '.escapeshellarg("{$out}/{$name}.png").' --settings '.escapeshellarg($settings).' 2>&1', $lines, $code);
        file_put_contents("{$oracles}/{$name}--{$tag}".($code === 0 ? '.json' : '.error.txt'), implode("\n", $lines)."\n");
        $report = $code === 0 ? json_decode(implode("\n", $lines), true) : null;
        printf("%-32s %-8s %s\n", $name, $tag, is_array($report) && is_string($report['validation_state'] ?? null) ? $report['validation_state'] : "exit {$code}");
    }
}
