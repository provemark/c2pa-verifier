<?php

declare(strict_types=1);

/*
 * Steps 313, 315 and 318 to 322: PNG probes for the manifest and its signature, from the reading of C2PA 2.4
 * (docs/reading-c2pa-2.4.md, L2, L9, L10, L11, L13 and C1). `c2patool` 0.28.1 signs with a throw-away P-256 hierarchy;
 * a probe `c2patool` will not write is made by editing the store: the bytes replaced, every enclosing box resized,
 * the data hash's exclusion re-lengthened to the new caBX chunk and its hashed URI recomputed, the claim signed
 * again with the throw-away leaf, and the chunk's length and CRC set. Each edit is first run on a change that keeps
 * the file valid. Keys live in a scratch directory for the run and are deleted before it ends.
 *
 * Variants:
 *   control                  fixture-unsigned.png signed by c2patool
 *   cgi-shorter              claim_generator_info replaced by a shorter valid map {name: p}: the edit itself
 *   cgi-empty                claim_generator_info replaced by an empty map (L13)
 *   label-not-urn            the manifest label urn:c2pa:… changed to urx:c2pa:…, the claim's signature reference too (L11)
 *   type-c2md                the manifest box's type UUID c2ma changed to c2md, which §11.2.2 says to accept (L9)
 *   datahash-no-pad          the data hash's pad key renamed paX: no pad (L2, §18.5.2)
 *   datahash-pad-text        the data hash's pad a text string of the same length
 *   parent                   fixture-unsigned.png signed with control.png as its parent: [X, Y]
 *   duplicate-label-last     the same with a copy of the parent's manifest appended: [X, Y, X'] (L10)
 *   duplicate-label-middle   the copy right after the parent's manifest: [X, X', Y]
 *   cloud-ok                 a c2pa.cloud-data assertion as c2patool writes it, its location.hash as text (SPEC-063)
 *   cloud-hash-bytes         the same, location.hash a byte string
 *   cloud-hash-data          its label c2pa.hash.data (a hard binding)
 *   cloud-size-zero          its size 0
 *   cloud-actions            its label c2pa.actions.v2
 *   cloud-no-location        its location key renamed
 *   cloud-in-ingredient      fixture-unsigned.png signed with cloud-hash-data.png as its parent (0.28.1 records the failure)
 *   cloud-in-ingredient-unrecorded  the same signed by 0.27.22, which records no cloud-data failure
 *   unlisted-control         actions (when "123"), metadata, certificate status and soft binding, well-formed
 *   unlisted-metadata-no-context            its metadata without @context (L4)
 *   unlisted-certificate-status-no-ocspvals its certificate status without ocspVals (L6)
 *   unlisted-soft-binding-no-blocks         its soft binding without blocks (L8)
 *   unlisted-action-when-integer            its action's when the integer 123 (P08-3)
 *   x5chain-unprotected-too  the signer's chain under label 33 in the unprotected header as well (C1)
 *
 * Usage: php bin/make-manifest-probe-variants.php <scratch-dir> <c2patool-0.28.1> <c2patool-0.27.22>
 * Tooling, not the verification path.
 *
 * Writes:
 *   tests/Fixtures/manifest-probes/<variant>.png, throw-away-root.pem, throw-away-root.settings.json
 *   tests/Fixtures/c2patool/manifest-probes/<variant>--<version>.json
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$scratch, $new, $old] = [$argv[1] ?? null, $argv[2] ?? '', $argv[3] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_string($scratch) || ! is_dir($scratch) || $version($new) !== 'c2patool 0.28.1' || $version($old) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-manifest-probe-variants.php <scratch-dir outside the repository> <c2patool-0.28.1> <c2patool-0.27.22>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/manifest-probe-keys-'.bin2hex(random_bytes(4));
if (! mkdir($keys, 0700)) {
    throw new RuntimeException("cannot create {$keys}");
}
register_shutdown_function(static function () use ($keys): void {
    foreach (glob("{$keys}/*") ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($keys)) {
        rmdir($keys);
        echo "keys deleted: {$keys}\n";
    }
});

function mqRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function mqSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

/** @return array{0: string, 1: int} the caBX store and the chunk's offset */
function mqStore(string $png): array
{
    $stream = fopen('php://memory', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('no memory stream');
    }
    fwrite($stream, $png);
    rewind($stream);
    $store = (new PngManifestStoreExtractor)->extract($stream);
    if ($store === null) {
        throw new RuntimeException('no store');
    }

    return [$store->bytes, $store->ranges[0]['start']];
}

/** $length bytes at $at replaced by $with; every box that holds $at grows or shrinks with it. */
function mqSplice(string $s, int $at, int $length, string $with): string
{
    $holders = [];
    $walk = static function (int $from, int $to) use (&$walk, &$holders, $s, $at): void {
        for ($p = $from; $p + 8 <= $to;) {
            $len = bU32($s, $p);
            if ($len < 8 || $p + $len > $to) {
                return;
            }
            if ($at >= $p && $at < $p + $len) {
                $holders[] = $p;
                if (substr($s, $p + 4, 4) === 'jumb') {
                    $walk($p + 8, $p + $len);
                }
            }
            $p += $len;
        }
    };
    $walk(0, strlen($s));
    $delta = strlen($with) - $length;
    $s = substr($s, 0, $at).$with.substr($s, $at + $length);
    foreach ($holders as $box) {
        $s = substr_replace($s, pack('N', bU32($s, $box) + $delta), $box, 4);
    }

    return $s;
}

/**
 * The store put back into the PNG with the active manifest made whole again: its data hash's exclusion
 * re-lengthened to the new chunk, its hashed URI for the data hash recomputed, its claim signed again.
 */
function mqFinish(string $png, int $chunk, string $s, string $key, ?Closure $editDataHash = null): string
{
    // found in the bytes, not through ManifestStore, which refuses some of the probes this builds
    [$claim, $signature] = [mqCbor($s, 'c2pa.claim.v2'), mqCbor($s, 'c2pa.signature')];
    $claimAt = (int) strrpos($s, $claim);
    $label = (int) strrpos(substr($s, 0, $claimAt), "c2pa.hash.data\0");
    $box = (int) strrpos(substr($s, 0, $label), 'jumb') - 4;
    $boxLength = bU32($s, $box);
    $before = substr($s, $box + 8, $boxLength - 8);
    $lengthAt = (int) strpos($s, "\x66length", $label) + 7;
    $total = 12 + strlen($s);
    $s = match (ord($s[$lengthAt])) {
        0x19 => substr_replace($s, pack('n', $total), $lengthAt + 1, 2),
        0x1A => substr_replace($s, pack('N', $total), $lengthAt + 1, 4),
        default => throw new RuntimeException('the exclusion length is neither a 2- nor a 4-byte CBOR uint'),
    };
    if ($editDataHash !== null) {
        $content = $editDataHash(substr($s, $box + 8, $boxLength - 8));
        if (! is_string($content) || strlen($content) !== $boxLength - 8) {
            throw new RuntimeException('an edit of the data hash keeps its length');
        }
        $s = substr_replace($s, $content, $box + 8, $boxLength - 8);
    }
    $q = strpos($claim, hash('sha256', $before, true));
    if ($q === false) {
        throw new RuntimeException('the claim holds no hash of its data hash');
    }
    $claim = substr_replace($claim, hash('sha256', substr($s, $box + 8, $boxLength - 8), true), $q, 32);
    $s = substr_replace($s, $claim, $claimAt, strlen($claim));

    return mqChunk($png, $chunk, mqSign($s, $claim, $signature, $key));
}

/** The content of the cbor box of the last superbox labelled $label: the active manifest's, the store's last. */
function mqCbor(string $s, string $label): string
{
    $at = strrpos($s, $label."\0");
    $cbor = $at === false ? false : strpos($s, 'cbor', $at);
    if ($cbor === false) {
        throw new RuntimeException("no cbor box after {$label}");
    }

    return substr($s, $cbor + 4, bU32($s, $cbor - 4) - 8);
}

/** The COSE_Sign1 $signature inside $s signed again (ES256) over $claim with $key. */
function mqSign(string $s, string $claim, string $signature, string $key): string
{
    $coseAt = strrpos($s, $signature);
    if ($coseAt === false || substr($signature, -66, 2) !== "\x58\x40") {
        throw new RuntimeException('the COSE_Sign1 does not end in a 64-byte signature');
    }
    file_put_contents(dirname($key).'/tbs', CoseSign1::fromBytes($signature)->sigStructure($claim));
    mqRun(mqSh('openssl', 'dgst', '-sha256', '-sign', $key, '-out', dirname($key).'/sig', dirname($key).'/tbs'));
    $der = (string) file_get_contents(dirname($key).'/sig');
    $rs = '';
    for ($i = 0, $p = 2; $i < 2; $i++) {
        $l = ord($der[$p + 1]);
        $rs .= str_pad(ltrim(substr($der, $p + 2, $l), "\0"), 32, "\0", STR_PAD_LEFT);
        $p += 2 + $l;
    }

    return substr_replace($s, $rs, $coseAt + strlen($signature) - 64, 64);
}

/** The caBX chunk at $chunk replaced by one holding $s, its length and CRC set. */
function mqChunk(string $png, int $chunk, string $s): string
{
    return substr($png, 0, $chunk).pack('N', strlen($s)).'caBX'.$s.pack('N', crc32('caBX'.$s)).substr($png, $chunk + 12 + bU32($png, $chunk));
}

/**
 * $from replaced by $to (the same length) inside the last assertion box labelled $label, the claim's hashed URI
 * for it re-hashed (SHA-256) and the claim signed again. The store keeps its length, so the data hash still holds.
 */
function mqEditAssertion(string $s, string $label, string $from, string $to, string $key): string
{
    if (strlen($from) !== strlen($to)) {
        throw new RuntimeException('an edit keeps its length');
    }
    $at = strrpos($s, $label."\0");
    $box = $at === false ? -1 : (int) strrpos(substr($s, 0, $at), 'jumb') - 4;
    if ($box < 0) {
        throw new RuntimeException("no {$label} box");
    }
    $length = bU32($s, $box);
    $p = strpos($s, $from, $at);
    if ($p === false || $p >= $box + $length) {
        throw new RuntimeException("the bytes to change are not in {$label}");
    }
    $claim = mqCbor($s, 'c2pa.claim.v2');
    $q = strpos($claim, hash('sha256', substr($s, $box + 8, $length - 8), true));
    if ($q === false) {
        throw new RuntimeException("the claim holds no hash of {$label}");
    }
    $signature = mqCbor($s, 'c2pa.signature');
    $claimAt = (int) strrpos($s, $claim);
    $s = substr_replace($s, $to, $p, strlen($to));
    $newClaim = substr_replace($claim, hash('sha256', substr($s, $box + 8, $length - 8), true), $q, 32);
    $s = substr_replace($s, $newClaim, $claimAt, strlen($newClaim));

    return mqSign($s, $newClaim, $signature, $key);
}

/** A CBOR byte string head and its bytes. */
function mqBstr(string $b): string
{
    $n = strlen($b);

    return ($n < 24 ? chr(0x40 + $n) : ($n < 256 ? "\x58".chr($n) : "\x59".pack('n', $n))).$b;
}

$root = dirname(__DIR__);
$dir = $root.'/tests/Fixtures/manifest-probes';
$oracles = $root.'/tests/Fixtures/c2patool/manifest-probes';
foreach ([$dir, $oracles] as $d) {
    if (! is_dir($d) && ! mkdir($d, 0755, true)) {
        throw new RuntimeException("cannot create {$d}");
    }
}

// ---- the throw-away hierarchy ----
file_put_contents("{$keys}/ext.cnf", "[req]\ndistinguished_name=dn\n[dn]\n[root]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n[leaf]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=emailProtection\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
mqRun(mqSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
mqRun(mqSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (manifest probes)', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'root', '-out', "{$keys}/root.pem"));
mqRun(mqSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/leaf.key"));
mqRun(mqSh('openssl', 'req', '-new', '-key', "{$keys}/leaf.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Manifest probe signer', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/leaf.csr"));
mqRun(mqSh('openssl', 'x509', '-req', '-in', "{$keys}/leaf.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'leaf', '-out', "{$keys}/leaf.pem"));
mqRun(mqSh('openssl', 'pkcs8', '-topk8', '-nocrypt', '-in', "{$keys}/leaf.key", '-out', "{$keys}/leaf.pk8"));
$rootPem = (string) file_get_contents("{$keys}/root.pem");
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
$settings = ['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $rootPem, 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')]];
file_put_contents("{$dir}/throw-away-root.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
file_put_contents("{$keys}/m.json", json_encode([
    'alg' => 'es256', 'private_key' => "{$keys}/leaf.pk8", 'sign_cert' => "{$keys}/leaf.pem",
    'claim_generator_info' => [['name' => 'c2pa-verifier manifest probes', 'version' => '1']],
    'assertions' => [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture']]]]],
], JSON_UNESCAPED_SLASHES));

// ---- c2patool's own files ----
mqRun(mqSh($new, $root.'/tests/Fixtures/fixture-unsigned.png', '-m', "{$keys}/m.json", '-o', "{$dir}/control.png", '-f'));
mqRun(mqSh($new, $root.'/tests/Fixtures/fixture-unsigned.png', '-m', "{$keys}/m.json", '-p', "{$dir}/control.png", '-o', "{$dir}/parent.png", '-f'));
$png = (string) file_get_contents("{$dir}/control.png");
[$s, $chunk] = mqStore($png);
$claim = ManifestStore::fromTree((new JumbfParser)->parse($s))->active->claimBytes();
$claimAt = (int) strpos($s, $claim);

// ---- claim_generator_info (L13), and the edit itself on a valid map ----
$k = strpos($claim, "\x74claim_generator_info");
if ($k === false) {
    throw new RuntimeException('no claim_generator_info in the claim');
}
$value = $claimAt + $k + 21;
$valueLength = bCborEnd($s, $value) - $value;
file_put_contents("{$dir}/cgi-shorter.png", mqFinish($png, $chunk, mqSplice($s, $value, $valueLength, "\xa1\x64name\x61p"), "{$keys}/leaf.key"));
file_put_contents("{$dir}/cgi-empty.png", mqFinish($png, $chunk, mqSplice($s, $value, $valueLength, "\xa0"), "{$keys}/leaf.key"));

// ---- the data hash's pad (L2): removed (its key renamed), and given as text ----
$padKey = static function (string $content): int {
    $at = strpos($content, "\x63pad");
    if ($at === false || strpos($content, "\x63pad", $at + 1) !== false) {
        throw new RuntimeException('the data hash does not hold one pad key');
    }

    return $at;
};
file_put_contents("{$dir}/datahash-no-pad.png", mqFinish($png, $chunk, $s, "{$keys}/leaf.key", static fn (string $c): string => substr_replace($c, "\x63paX", $padKey($c), 4)));
file_put_contents("{$dir}/datahash-pad-text.png", mqFinish($png, $chunk, $s, "{$keys}/leaf.key", static function (string $c) use ($padKey): string {
    $at = $padKey($c) + 4;
    $head = ord($c[$at]);
    if ($head < 0x40 || $head > 0x57) {
        throw new RuntimeException('the pad is not a short byte string');
    }

    return substr_replace($c, chr($head + 0x20), $at, 1);
}));

// ---- the manifest label (L11): every occurrence, the claim's own reference to its signature too ----
$active = ManifestStore::fromTree((new JumbfParser)->parse($s))->active;
$label = $active->label;
$renamed = str_replace($label, 'urx'.substr($label, 3), $s);
$newClaim = str_replace($label, 'urx'.substr($label, 3), $claim);
if ($newClaim === $claim) {
    throw new RuntimeException('the claim does not name its own manifest');
}
file_put_contents("{$dir}/label-not-urn.png", mqChunk($png, $chunk, mqSign($renamed, $newClaim, $active->signatureBytes(), "{$keys}/leaf.key")));

// ---- the manifest box typed c2md (L9): outside the claim, so no signature changes ----
$c2ma = hex2bin('63326d6100110010800000aa00389b71');
$typeAt = strpos($s, (string) $c2ma);
if ($c2ma === false || $typeAt === false) {
    throw new RuntimeException('no c2ma manifest box');
}
file_put_contents("{$dir}/type-c2md.png", mqChunk($png, $chunk, substr_replace($s, (string) hex2bin('63326d6400110010800000aa00389b71'), $typeAt, 16)));

// ---- a duplicated manifest label (L10): [X, Y] becomes [X, Y, X'] and [X, X', Y]; Y's binding is made whole ----
$parentPng = (string) file_get_contents("{$dir}/parent.png");
[$ps, $pchunk] = mqStore($parentPng);
$first = 8 + bU32($ps, 8);                  // the store's first manifest box follows its description box
$copy = substr($ps, $first, bU32($ps, $first));
foreach (['duplicate-label-last' => $ps.$copy, 'duplicate-label-middle' => substr($ps, 0, $first + strlen($copy)).$copy.substr($ps, $first + strlen($copy))] as $name => $grown) {
    $grown = substr_replace($grown, pack('N', strlen($grown)), 0, 4);
    file_put_contents("{$dir}/{$name}.png", mqFinish($parentPng, $pchunk, $grown, "{$keys}/leaf.key"));
}

// ---- the signer's chain under label 33 in both headers (C1): the unprotected header rebuilt ----
$signature = ManifestStore::fromTree((new JumbfParser)->parse($s))->active->signatureBytes();
$cose = CoseSign1::fromBytes($signature);
if (count($cose->chain) > 23) {
    throw new RuntimeException('a chain of more than 23 certificates needs a longer CBOR array head');
}
$chain = count($cose->chain) === 1 ? mqBstr($cose->chain[0]->bytes) : pack('C', 0x80 + count($cose->chain)).implode('', array_map(static fn ($c): string => mqBstr($c->bytes), $cose->chain));
$rebuilt = "\xd2\x84".mqBstr($cose->protectedBytes)."\xa2\x18\x21".$chain."\x63pad".mqBstr(str_repeat("\0", 16))."\xf6".mqBstr($cose->signature);
$coseAt = (int) strpos($s, $signature);
file_put_contents("{$dir}/x5chain-unprotected-too.png", mqFinish($png, $chunk, mqSplice($s, $coseAt, strlen($signature), $rebuilt), "{$keys}/leaf.key"));

// ---- cloud data (SPEC-063): c2patool signs a well-formed one for a placeholder label, the probe edits it ----
foreach (['c2pa.xxxxxxx.v2', 'c2pa.xxxx.data'] as $placeholder) {
    file_put_contents("{$keys}/m-cloud.json", json_encode([
        'alg' => 'es256', 'private_key' => "{$keys}/leaf.pk8", 'sign_cert' => "{$keys}/leaf.pem",
        'claim_generator_info' => [['name' => 'c2pa-verifier manifest probes', 'version' => '1']],
        'assertions' => [
            ['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture']]]],
            ['label' => 'c2pa.cloud-data', 'data' => ['label' => $placeholder, 'size' => 5, 'location' => ['url' => 'https://cloud.example.invalid/remote.jumbf', 'alg' => 'sha256', 'hash' => base64_encode(hash('sha256', 'remote', true))]]],
        ],
    ], JSON_UNESCAPED_SLASHES));
    mqRun(mqSh($new, $root.'/tests/Fixtures/fixture-unsigned.png', '-m', "{$keys}/m-cloud.json", '-o', "{$keys}/cloud.png", '-f'));
    $cloudPng = (string) file_get_contents("{$keys}/cloud.png");
    [$cs, $cchunk] = mqStore($cloudPng);
    $edits = $placeholder === 'c2pa.xxxx.data'
        ? ['cloud-hash-data' => ['c2pa.xxxx.data', 'c2pa.hash.data']]
        : [
            'cloud-actions' => ['c2pa.xxxxxxx.v2', 'c2pa.actions.v2'],
            'cloud-size-zero' => ["\x64size\x05", "\x64size\x00"],
            'cloud-no-location' => ["\x68location", "\x68locatioX"],
            'cloud-hash-bytes' => ["\x64hash\x78\x2c", "\x64hash\x58\x2c"],
        ];
    if ($placeholder === 'c2pa.xxxxxxx.v2') {
        copy("{$keys}/cloud.png", "{$dir}/cloud-ok.png");
    }
    foreach ($edits as $name => [$from, $to]) {
        file_put_contents("{$dir}/{$name}.png", mqChunk($cloudPng, $cchunk, mqEditAssertion($cs, 'c2pa.cloud-data', $from, $to, "{$keys}/leaf.key")));
    }
}

// ---- by design (§15.10.3.2: no validation beyond the listed assertions): c2patool signs well-formed ones, the
// probe breaks one shape each; c2patool cannot read three of them and refuses the fourth ----
file_put_contents("{$keys}/m-unlisted.json", json_encode([
    'alg' => 'es256', 'private_key' => "{$keys}/leaf.pk8", 'sign_cert' => "{$keys}/leaf.pem",
    'claim_generator_info' => [['name' => 'c2pa-verifier manifest probes', 'version' => '1']],
    'assertions' => [
        ['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.created', 'when' => '123', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture']]]],
        ['label' => 'c2pa.metadata', 'data' => ['@context' => ['dc' => 'http://purl.org/dc/elements/1.1/'], 'dc:title' => 'probe']],
        ['label' => 'c2pa.certificate-status', 'data' => ['ocspVals' => ['AAECAwQF']]],
        ['label' => 'c2pa.soft-binding', 'data' => ['alg' => 'com.example.watermark', 'blocks' => [['scope' => (object) [], 'value' => 'AAECAwQF']]]],
    ],
], JSON_UNESCAPED_SLASHES));
mqRun(mqSh($new, $root.'/tests/Fixtures/fixture-unsigned.png', '-m', "{$keys}/m-unlisted.json", '-o', "{$dir}/unlisted-control.png", '-f'));
$unlistedPng = (string) file_get_contents("{$dir}/unlisted-control.png");
[$us, $uchunk] = mqStore($unlistedPng);
foreach ([
    'unlisted-metadata-no-context' => ['c2pa.metadata', '"@context"', '"@contexX"'],
    'unlisted-certificate-status-no-ocspvals' => ['c2pa.certificate-status', "\x68ocspVals", "\x68ocspValX"],
    'unlisted-soft-binding-no-blocks' => ['c2pa.soft-binding', "\x66blocks", "\x66blockX"],
    'unlisted-action-when-integer' => ['c2pa.actions.v2', "\x64when\xc0\x63123", "\x64when\x1a\x00\x00\x00\x7b"],
] as $name => [$label, $from, $to]) {
    file_put_contents("{$dir}/{$name}.png", mqChunk($unlistedPng, $uchunk, mqEditAssertion($us, $label, $from, $to, "{$keys}/leaf.key")));
}

mqRun(mqSh($new, $root.'/tests/Fixtures/fixture-unsigned.png', '-m', "{$keys}/m.json", '-p', "{$dir}/cloud-hash-data.png", '-o', "{$dir}/cloud-in-ingredient.png", '-f'));
// 0.27.22 does not check cloud data, so the ingredient it records holds no cloud-data failure: a delta for a validator
mqRun(mqSh($old, $root.'/tests/Fixtures/fixture-unsigned.png', '-m', "{$keys}/m.json", '-p', "{$dir}/cloud-hash-data.png", '-o', "{$dir}/cloud-in-ingredient-unrecorded.png", '-f'));

// ---- the oracles ----
foreach (['control', 'cgi-shorter', 'cgi-empty', 'label-not-urn', 'type-c2md', 'datahash-no-pad', 'datahash-pad-text', 'parent', 'duplicate-label-last', 'duplicate-label-middle', 'x5chain-unprotected-too', 'cloud-ok', 'cloud-hash-bytes', 'cloud-hash-data', 'cloud-size-zero', 'cloud-actions', 'cloud-no-location', 'cloud-in-ingredient', 'cloud-in-ingredient-unrecorded', 'unlisted-control', 'unlisted-metadata-no-context', 'unlisted-certificate-status-no-ocspvals', 'unlisted-soft-binding-no-blocks', 'unlisted-action-when-integer'] as $name) {
    foreach (['0.28.1' => $new, '0.27.22' => $old] as $v => $tool) {
        $lines = [];
        exec(mqSh($tool, "{$dir}/{$name}.png", '--settings', "{$dir}/throw-away-root.settings.json").' 2>&1', $lines);
        file_put_contents("{$oracles}/{$name}--{$v}.json", implode("\n", $lines)."\n");
        $json = json_decode(implode("\n", $lines), true);
        printf("  %-24s %-8s %s\n", $name, $v, is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : 'error: '.substr(implode(' ', $lines), 0, 90));
    }
}
