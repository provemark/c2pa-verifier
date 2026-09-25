<?php

declare(strict_types=1);

/*
 * Build the fixtures of SPEC-041 (step 142a): JPEG files whose APP11 pieces carry a different packet
 * sequence number Z, nothing else changed. The Z field lies inside the data hash's exclusion, so no
 * variant needs re-signing. Both c2patool versions judge every variant and the Bing writer file.
 *
 * Usage: php bin/make-spec041-variants.php <c2patool-0.28.0> <c2patool-0.27.22>
 * Writes:
 *   tests/Fixtures/first-piece-z/<variant>.jpg
 *   tests/Fixtures/c2patool/first-piece-z/<file>--<version>.json (or .error.txt)
 *   tests/Fixtures/c2patool/writers/microsoft-20260609-bing-fast-heartbeat.json (0.27.22, as its neighbours)
 * Tooling.
 */

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$new, $old] = [$argv[1] ?? '', $argv[2] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_file($new) || ! is_file($old) || $version($new) !== 'c2patool 0.28.0' || $version($old) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-spec041-variants.php <c2patool-0.28.0> <c2patool-0.27.22>\n");
    exit(1);
}

/**
 * Rewrites the Z field of the JP APP11 pieces, in file order, and nothing else.
 *
 * @param  list<int>  $zs  one value per piece
 */
function spec041Renumber(string $jpeg, array $zs): string
{
    $p = 2;
    $piece = 0;
    while ($jpeg[$p] === "\xFF" && ord($jpeg[$p + 1]) !== 0xDA) {
        $length = (ord($jpeg[$p + 2]) << 8) | ord($jpeg[$p + 3]);
        // In an APP11 segment: marker(2) length(2) CI(2) En(2) Z(4) LBox(4) TBox(4) data.
        if (ord($jpeg[$p + 1]) === 0xEB && substr($jpeg, $p + 4, 2) === 'JP') {
            if (! array_key_exists($piece, $zs)) {
                throw new RuntimeException("no Z given for piece {$piece}");
            }
            $jpeg = substr_replace($jpeg, pack('N', $zs[$piece]), $p + 8, 4);
            $piece++;
        }
        $p += 2 + $length;
    }
    if ($piece !== count($zs)) {
        throw new RuntimeException(sprintf('%d Z value(s) for %d piece(s)', count($zs), $piece));
    }

    return $jpeg;
}

$root = dirname(__DIR__);
$fixtures = $root.'/tests/Fixtures';
$twoPieces = (string) file_get_contents($fixtures.'/fixture-signed.jpg');
$onePiece = (string) file_get_contents($fixtures.'/public-testfiles/adobe-20220124-C.jpg');

$variants = [
    'zero-two.jpg' => spec041Renumber($twoPieces, [0, 2]),          // AC2
    'zero-one.jpg' => spec041Renumber($twoPieces, [0, 1]),          // AC3
    'seven-eight.jpg' => spec041Renumber($twoPieces, [7, 8]),       // AC4
    'one-piece-seven.jpg' => spec041Renumber($onePiece, [7]),       // AC4
];

$dir = $fixtures.'/first-piece-z';
$oracles = $fixtures.'/c2patool/first-piece-z';
foreach ([$dir, $oracles] as $d) {
    if (! is_dir($d) && ! mkdir($d, 0o755, true)) {
        throw new RuntimeException("cannot create {$d}");
    }
}

$judge = static function (string $path, string $base) use ($new, $old, $oracles): void {
    foreach (['0.28.0' => $new, '0.27.22' => $old] as $v => $tool) {
        exec(escapeshellarg($tool).' '.escapeshellarg($path).' 2>&1', $lines, $exit);
        $text = implode("\n", $lines)."\n";
        $target = "{$oracles}/{$base}--{$v}".($exit === 0 ? '.json' : '.error.txt');
        foreach (glob("{$oracles}/{$base}--{$v}.*") ?: [] as $stale) {
            unlink($stale);
        }
        file_put_contents($target, $text);
        $json = json_decode($text, true);
        $state = is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : '?';
        printf("   %-8s %s\n", $v, $exit === 0 ? $state : trim($lines[0] ?? ''));
        unset($lines);
    }
};

foreach ($variants as $name => $bytes) {
    file_put_contents("{$dir}/{$name}", $bytes);
    printf("%s  %7d  %s\n", hash('sha256', $bytes), strlen($bytes), $name);
    $judge("{$dir}/{$name}", basename($name, '.jpg'));
}

$bing = 'microsoft-20260609-bing-fast-heartbeat';
$bingPath = "{$fixtures}/writers/{$bing}.jpg";
printf("%s  %7d  writers/%s.jpg\n", hash('sha256', (string) file_get_contents($bingPath)), filesize($bingPath), $bing);
$judge($bingPath, $bing);
copy("{$oracles}/{$bing}--0.27.22.json", "{$fixtures}/c2patool/writers/{$bing}.json");
