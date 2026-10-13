<?php
// Prints the five drift-alarm corpora as JSON [{corpus, rel}], read from tests/Pest.php's lists.
$root = $argv[1];
$fx = "$root/tests/Fixtures";
$src = file_get_contents("$root/tests/Pest.php");
$const = function (string $name) use ($src): array {
    preg_match('/const '.$name.' = (\[.*?\]);/s', $src, $m) or exit("missing $name\n");
    return eval('return '.$m[1].';');
};
$find = function (string $dir, string $name) use ($fx): string {
    $hits = glob("$fx/$dir/$name.*");
    $hits = array_values(array_filter($hits, fn ($h) => ! preg_match('/\.(json|md|pem)$/', $h)));
    count($hits) === 1 or exit("ambiguous $dir/$name: ".json_encode($hits)."\n");
    return substr($hits[0], strlen($fx) + 1);
};
$out = [];
foreach ($const('SPEC013_CORPUS') as $rel) {
    $out[] = ['corpus' => 'own', 'rel' => $rel];
}
foreach ($const('SPEC013_PUBLIC_CORPUS') as $n) {
    $out[] = ['corpus' => 'public-testfiles', 'rel' => $find('public-testfiles', $n)];
}
foreach ($const('SPEC013_RS_CORPUS') as $n) {
    $out[] = ['corpus' => 'c2pa-rs', 'rel' => $find('c2pa-rs', $n)];
}
foreach ($const('SPEC013_WRITERS_CORPUS') as $n) {
    $out[] = ['corpus' => 'writers', 'rel' => $find('writers', $n)];
}
foreach (glob("$fx/matrix/*") as $f) {
    if (! preg_match('/\.(json|md|pem)$/', $f)) {
        $out[] = ['corpus' => 'matrix', 'rel' => substr($f, strlen($fx) + 1)];
    }
}
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";
