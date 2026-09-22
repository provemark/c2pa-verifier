<?php

declare(strict_types=1);

/*
 * Step 56 (SPEC-021 AC2, AC5, AC6): three signed variants for the rules that
 * decide whether an ingredient manifest can hide a fault.
 *
 *   ingredient-signature-broken   c2pa-rs/CACA.jpg with the *ingredient* manifest's COSE signature
 *                                 flipped and every hash above it recomputed — the referring
 *                                 activeManifest hash, the active claim's hashed URI for that
 *                                 assertion — so that the box hash still matches and only the
 *                                 ingredient's signature is wrong (AC2: a matching box hash must
 *                                 never stand in for validating the manifest)
 *   records-active-fault          the PNG fixture with an ingredient assertion whose validationStatus
 *                                 records claimSignature.mismatch *for the active manifest's own
 *                                 signature box*, and the active signature then broken (AC5: the
 *                                 CAI-12751 guard — a recorded status never cancels the active
 *                                 manifest's own failure)
 *   redacted                      the PNG fixture whose claim carries a non-empty
 *                                 redacted_assertions (AC6: refused until a fixture exists)
 *
 * Keys live outside the repository for the run and are deleted before it ends; the public root goes
 * into a settings file. Decided by Maurice van Loon on 2026-09-21: tooling may sign with throw-away
 * keys; the product never signs.
 *
 * Usage: php bin/make-ingredient-manifest-variants.php <scratch-dir>. Tooling.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Cose\SignatureVerifier;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
$scratch = $argv[1] ?? null;
if (! is_string($scratch) || ! is_dir($scratch)) {
    fwrite(STDERR, "usage: php bin/make-ingredient-manifest-variants.php <scratch-dir outside the repository>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/ingredient-manifest-keys-'.bin2hex(random_bytes(4));
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

function imRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function imSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

/** A byte string to be encoded as CBOR major type 2 (a PHP string encodes as text). */
final class ImBytes
{
    public function __construct(public string $bytes) {}
}

/** The CBOR head for major type $mt and argument $n. */
function imHead(int $mt, int $n): string
{
    $mt <<= 5;

    return match (true) {
        $n < 24 => pack('C', $mt | $n),
        $n < 256 => pack('CC', $mt | 24, $n),
        $n < 65536 => pack('Cn', $mt | 25, $n),
        default => pack('CN', $mt | 26, $n),
    };
}

/** A small CBOR encoder: ints, text, bytes (ImBytes), null, bool, lists and text-keyed maps in the order given. */
function imCbor(mixed $v): string
{
    return match (true) {
        $v instanceof ImBytes => imHead(2, strlen($v->bytes)).$v->bytes,
        is_string($v) => imHead(3, strlen($v)).$v,
        is_int($v) => $v >= 0 ? imHead(0, $v) : imHead(1, -1 - $v),
        $v === null => "\xf6",
        is_bool($v) => $v ? "\xf5" : "\xf4",
        is_array($v) && array_is_list($v) => imHead(4, count($v)).implode('', array_map('imCbor', $v)),
        is_array($v) => imHead(5, count($v)).implode('', array_map(static fn (string $k, mixed $x): string => imCbor($k).imCbor($x), array_keys($v), array_values($v))),
        default => throw new RuntimeException('cannot encode '.get_debug_type($v)),
    };
}

/** An assertion superbox: jumd (cbor UUID, toggles 3: requestable + label) and a cbor content box. */
function imAssertionBox(string $label, string $payload): string
{
    $uuid = (string) hex2bin('63626f72001100108000'.'00aa00389b71');
    $jumd = 'jumd'.$uuid."\x03".$label."\0";
    $jumd = pack('N', 4 + strlen($jumd)).$jumd;
    $cbor = pack('N', 8 + strlen($payload)).'cbor'.$payload;
    $box = 'jumb'.$jumd.$cbor;

    return pack('N', 4 + strlen($box)).$box;
}

/** A DER ECDSA signature as R‖S of 2 × $curveBytes (what COSE carries). */
function imDerToRs(string $der, int $curveBytes): string
{
    if ($der[0] !== "\x30") {
        throw new RuntimeException('not a DER ECDSA signature');
    }
    $p = ord($der[1]) & 0x80 ? 2 + (ord($der[1]) & 0x7F) : 2;
    $rs = '';
    for ($i = 0; $i < 2; $i++) {
        if ($der[$p] !== "\x02") {
            throw new RuntimeException('not a DER ECDSA signature');
        }
        $len = ord($der[$p + 1]);
        $int = ltrim(substr($der, $p + 2, $len), "\0");
        $rs .= str_pad($int, $curveBytes, "\0", STR_PAD_LEFT);
        $p += 2 + $len;
    }

    return $rs;
}

/**
 * The hash of $bytes with the [start, length] ranges skipped — what c2pa.hash.data covers.
 *
 * @param  list<array{0: int, 1: int}>  $exclusions
 */
function imDataHash(string $bytes, array $exclusions): string
{
    usort($exclusions, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    $ctx = hash_init('sha256');
    $p = 0;
    foreach ($exclusions as [$start, $length]) {
        hash_update($ctx, substr($bytes, $p, $start - $p));
        $p = $start + $length;
    }
    hash_update($ctx, substr($bytes, $p));

    return hash_final($ctx, true);
}

/** The offset of the entry map naming $label in a CBOR list of hashed-URI maps at $list, or null. */
function imEntryFor(string $s, int $list, string $label): ?int
{
    $count = ord($s[$list]) & 0x1F;
    $p = $list + 1;
    for ($i = 0; $i < $count; $i++) {
        $end = bCborEnd($s, $p);
        if (str_contains(substr($s, $p, $end - $p), $label)) {
            return $p;
        }
        $p = $end;
    }

    return null;
}

/** The PNG fixture's hard binding re-bound after the store grew by $grown bytes before the hash.data box. */
function imRebind(string $store, string $png, int $grown, int $hashDataBox, int $claimAt): string
{
    $box = $hashDataBox + $grown;
    $hd = bMapPairs($store, $box + 80);
    $ex = bMapPairs($store, $hd['exclusions'][1] + 1);
    $lengthValue = $ex['length'][1];
    if ($store[$lengthValue] !== "\x19") {
        throw new RuntimeException('the exclusion length is not a two-byte CBOR uint');
    }
    $store = bReplace($store, $lengthValue + 1, substr($store, $lengthValue + 1, 2), pack('n', 12 + strlen($store)));
    $digest = imDataHash(pngWithStore($png, $store), [[33, 12 + strlen($store)]]);
    $hashValue = $hd['hash'][1] + 2;
    $store = bReplace($store, $hashValue, substr($store, $hashValue, 32), $digest);
    $uri = hash('sha256', substr($store, $box + 8, bU32($store, $box) - 8), true);
    $claim = bMapPairs($store, $claimAt + $grown);
    $entry = imEntryFor($store, $claim['created_assertions'][1], 'c2pa.hash.data');
    if ($entry === null) {
        throw new RuntimeException('no c2pa.hash.data entry in the claim');
    }
    $claimHash = bMapPairs($store, $entry)['hash'][1] + 2;

    return bReplace($store, $claimHash, substr($store, $claimHash, 32), $uri);
}

// ---- the throw-away hierarchy: a P-256 root and a leaf on the C2PA profile ----
$root = dirname(__DIR__);
$cnf = <<<'CNF'
[req]
distinguished_name = dn
[dn]
[v3_root]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign, digitalSignature
subjectKeyIdentifier = hash
[good]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, nonRepudiation
extendedKeyUsage = emailProtection
CNF;
file_put_contents("{$keys}/ext.cnf", $cnf);
imRun(imSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
imRun(imSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (ingredient manifests)', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'v3_root', '-out', "{$keys}/root.pem"));
imRun(imSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/leaf.key"));
imRun(imSh('openssl', 'req', '-new', '-key', "{$keys}/leaf.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=ingredient manifests', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/leaf.csr"));
imRun(imSh('openssl', 'x509', '-req', '-in', "{$keys}/leaf.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'good', '-out', "{$keys}/leaf.pem"));
$pemToDer = static fn (string $pem): string => (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $pem), true);
$rootPem = (string) file_get_contents("{$keys}/root.pem");
$rootDer = $pemToDer($rootPem);
$leafDer = $pemToDer((string) file_get_contents("{$keys}/leaf.pem"));

$dir = $root.'/tests/Fixtures/ingredient-manifest';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
$storeCfg = (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg');
$full = json_decode((string) file_get_contents($root.'/tests/Fixtures/trust/full.settings.json'), true, 512, JSON_THROW_ON_ERROR);
assert(is_array($full) && is_array($full['trust']) && is_string($full['trust']['trust_anchors']));
$testAnchors = $full['trust']['trust_anchors'];
// the throw-away root *and* the test hierarchy: the ingredient manifests of CACA are signed by the
// C2PA test certificates, and only the fault under test may make a variant Invalid
file_put_contents("{$dir}/throw-away-root.settings.json", json_encode([
    'trust' => ['trust_anchors' => $rootPem.$testAnchors, 'trust_config' => $storeCfg],
    'verify' => ['verify_trust' => true],
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

/** Sign $claimBytes with the throw-away leaf and pad the COSE_Sign1 to exactly $length bytes. */
function imCose(string $claimBytes, int $length, string $leafDer, string $rootDer, string $keys, string $name): string
{
    $protected = "\xa2\x01\x26\x18\x21\x82".imCbor(new ImBytes($leafDer)).imCbor(new ImBytes($rootDer));
    $draft = "\xd2\x84".imCbor(new ImBytes($protected))."\xa1\x63pad".imCbor(new ImBytes(''))."\xf6".imCbor(new ImBytes(str_repeat("\0", 64)));
    file_put_contents("{$keys}/tbs", CoseSign1::fromBytes($draft)->sigStructure($claimBytes));
    imRun(imSh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/leaf.key", '-out', "{$keys}/sig", "{$keys}/tbs"));
    $signature = imDerToRs((string) file_get_contents("{$keys}/sig"), 32);
    $fixed = 2 + strlen(imCbor(new ImBytes($protected))) + 5 + 3 + 1 + strlen(imCbor(new ImBytes($signature)));
    $padLength = $length - $fixed;
    if ($padLength < 256) {
        throw new RuntimeException("{$name}: the pad would be {$padLength} bytes, too short for a 3-byte head");
    }
    $cose = "\xd2\x84".imCbor(new ImBytes($protected))."\xa1\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength)."\xf6".imCbor(new ImBytes($signature));
    if (strlen($cose) !== $length) {
        throw new RuntimeException("{$name}: COSE is ".strlen($cose)." bytes, not {$length}");
    }
    if (! (new SignatureVerifier)->verify(CoseSign1::fromBytes($cose), $claimBytes)) {
        throw new RuntimeException("{$name}: the new signature does not verify under its own leaf");
    }

    return $cose;
}

// ============================================================================
// 1. ingredient-signature-broken, from c2pa-rs/CACA.jpg
// ============================================================================
$jpeg = (string) file_get_contents($root.'/tests/Fixtures/c2pa-rs/CACA.jpg');
$stream = fopen($root.'/tests/Fixtures/c2pa-rs/CACA.jpg', 'rb');
if ($stream === false) {
    throw new RuntimeException('cannot open CACA.jpg');
}
$extracted = (new JpegManifestStoreExtractor)->extract($stream);
if ($extracted === null) {
    throw new RuntimeException('no store in CACA.jpg');
}
$s = $extracted->bytes;
$parsed = ManifestStore::fromTree((new JumbfParser)->parse($s));
$labels = array_keys($parsed->manifests);
$ingredientLabel = $labels[0];
$activeLabel = $parsed->active->label;
$ingredient = $parsed->manifests[$ingredientLabel];
$active = $parsed->active;

// the ingredient's COSE signature, one byte flipped: the manifest box hash changes with it
$coseAt = strpos($s, $ingredient->signatureBytes());
if ($coseAt === false) {
    throw new RuntimeException('no COSE for the ingredient manifest');
}
$flipAt = $coseAt + strlen($ingredient->signatureBytes()) - 8;   // inside the signature bstr at the end
$edited = $s;
$edited[$flipAt] = chr(ord($edited[$flipAt]) ^ 0x01);

// the referring activeManifest hash in the active manifest's c2pa.ingredient.v3 assertion
$ingredientBoxHash = hash('sha256', substr($edited, $ingredient->box->offset + 8, $ingredient->box->length - 8), true);
$assertionBox = $active->assertionStore->child('c2pa.ingredient.v3') ?? throw new RuntimeException('no c2pa.ingredient.v3 in the active manifest');
$ingredientData = $active->assertions['c2pa.ingredient.v3']->data;
assert(is_array($ingredientData) && is_array($ingredientData['activeManifest']) && $ingredientData['activeManifest']['hash'] instanceof CborBytes);
$oldReference = $ingredientData['activeManifest']['hash']->bytes;
$at = strpos($edited, $oldReference, $assertionBox->offset);
if ($at === false || $at > $assertionBox->offset + $assertionBox->length) {
    throw new RuntimeException('the activeManifest hash is not in the ingredient assertion box');
}
$edited = bReplace($edited, $at, $oldReference, $ingredientBoxHash);

// the active claim's hashed URI for that assertion — found by its old hash value, since this claim's CBOR
// uses indefinite lengths in places and the byte-walking helpers only read definite ones (SPEC-006 #2)
$assertionHash = hash('sha256', substr($edited, $assertionBox->offset + 8, $assertionBox->length - 8), true);
$claimAt = strpos($edited, $active->claimBytes());
if ($claimAt === false) {
    throw new RuntimeException('no claim bytes for the active manifest');
}
$oldEntry = null;
foreach ([...$active->claim->createdAssertions, ...$active->claim->gatheredAssertions] as $uri) {
    if (str_ends_with($uri->url, 'c2pa.ingredient.v3')) {
        $oldEntry = $uri->hash->bytes;
    }
}
if ($oldEntry === null) {
    throw new RuntimeException('no c2pa.ingredient.v3 entry in the active claim');
}
$entryAt = strpos($edited, $oldEntry, $claimAt);
if ($entryAt === false || $entryAt > $claimAt + strlen($active->claimBytes())) {
    throw new RuntimeException('the hashed URI for the ingredient assertion is not in the claim');
}
$edited = bReplace($edited, $entryAt, $oldEntry, $assertionHash);

// the active claim, re-signed (its own bytes changed) — the store's length is unchanged, so the data hash holds
$claimBytes = substr($edited, $claimAt, strlen($active->claimBytes()));
$activeCoseAt = strpos($edited, $active->signatureBytes());
if ($activeCoseAt === false) {
    throw new RuntimeException('no COSE for the active manifest');
}
$activeCoseLength = strlen($active->signatureBytes());
$cose = imCose($claimBytes, $activeCoseLength, $leafDer, $rootDer, $keys, 'ingredient-signature-broken');
$edited = substr($edited, 0, $activeCoseAt).$cose.substr($edited, $activeCoseAt + $activeCoseLength);
if (strlen($edited) !== strlen($s)) {
    throw new RuntimeException('the store changed length: the data hash would no longer hold');
}
file_put_contents("{$dir}/ingredient-signature-broken.bin", $edited);
$app11 = static function (string $jpeg, string $store): string {
    // Each APP11 piece: FF EB, length(2), CI "JP"(2), En instance(2), Z sequence(4), LBox(4), TBox(4),
    // then the piece's data. The reassembled store is LBox|TBox once (from the first piece) followed by
    // every piece's data in order (SPEC-001, notes/step-02-jpeg-fixture.md), so writing an edited store
    // of the same length back means: its first 8 bytes into the first piece's LBox/TBox, the rest into
    // the pieces' data.
    $out = $jpeg;
    $offset = 0;
    $first = true;
    $p = 2;
    while ($p < strlen($jpeg) - 1) {
        if ($jpeg[$p] !== "\xff") {
            break;
        }
        $marker = ord($jpeg[$p + 1]);
        if ($marker === 0xDA) {
            break;
        }
        $header = unpack('n', substr($jpeg, $p + 2, 2));
        if ($header === false || ! is_int($header[1])) {
            throw new RuntimeException("cannot read the segment length at {$p}");
        }
        $length = $header[1];
        if ($marker === 0xEB) {
            if ($first) {
                $out = substr_replace($out, substr($store, 0, 8), $p + 4 + 8, 8);   // LBox and TBox
                $offset = 8;
                $first = false;
            }
            $data = $length - 2 - 16;
            $take = min($data, strlen($store) - $offset);
            $out = substr_replace($out, substr($store, $offset, $take), $p + 4 + 16, $take);
            $offset += $take;
        }
        $p += 2 + $length;
    }
    if ($offset !== strlen($store)) {
        throw new RuntimeException("the JPEG carried {$offset} store bytes, not ".strlen($store));
    }

    return $out;
};

// the rebuild must be exact: putting the *unchanged* store back has to give the original file
if ($app11($jpeg, $s) !== $jpeg) {
    throw new RuntimeException('the APP11 rebuild is not byte-exact on the unchanged store');
}
file_put_contents("{$dir}/ingredient-signature-broken.jpg", $app11($jpeg, $edited));
printf("%s  %d bytes  ingredient-signature-broken (ingredient %s)\n", hash('sha256', $edited), strlen($edited), substr($ingredientLabel, -12));

// ============================================================================
// 2 and 3, from the PNG fixture
// ============================================================================
$png = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.png');
$pngStream = fopen($root.'/tests/Fixtures/fixture-signed.png', 'rb');
if ($pngStream === false) {
    throw new RuntimeException('cannot open the PNG fixture');
}
$pngExtracted = (new PngManifestStoreExtractor)->extract($pngStream);
if ($pngExtracted === null || strlen($pngExtracted->bytes) !== 46025) {
    throw new RuntimeException('the PNG store is not the one measured in step 09');
}
$p = $pngExtracted->bytes;
$PNG_LABEL = ManifestStore::fromTree((new JumbfParser)->parse($p))->active->label;
$FIRST_ASSERTION_BOX = 166;
$HASH_DATA_BOX = 32831;
$CLAIM = 33081;
$storeBox = [0, 38, 117];
$claimBox = [0, 38, 33026, 33073];
$pngClaim = bMapPairs($p, $CLAIM);
[, $createdStart, $createdEnd] = $pngClaim['created_assertions'];

// --- records-active-fault: an ingredient assertion that "acknowledges" the active manifest's own broken signature
// two recorded statuses: one about the *active* manifest's signature box, which the guard must never
// drop, and one about this assertion itself, which the dropping rule must remove (SPEC-021 AC5)
$recorded = [
    [
        'code' => 'claimSignature.mismatch',
        'url' => sprintf('self#jumbf=/c2pa/%s/c2pa.signature', $PNG_LABEL),
        'explanation' => 'claim signature is not valid',
    ],
    [
        'code' => 'ingredient.unknownProvenance',
        'url' => sprintf('self#jumbf=/c2pa/%s/c2pa.assertions/c2pa.ingredient', $PNG_LABEL),
        'explanation' => 'acknowledged.png: ingredient does not have provenance',
    ],
];
$payload = imCbor([
    'dc:title' => 'acknowledged.png',
    'dc:format' => 'image/png',
    'instanceID' => 'xmp:iid:00000000-0000-0000-0000-000000000002',
    'relationship' => 'componentOf',
    'validationStatus' => $recorded,
]);
$box = imAssertionBox('c2pa.ingredient', $payload);
$entryCbor = imCbor(['url' => 'self#jumbf=c2pa.assertions/c2pa.ingredient', 'hash' => new ImBytes(hash('sha256', substr($box, 8), true))]);
$guard = bSplice($p, $createdStart, $createdEnd - $createdStart, "\x82".substr($p, $createdStart + 1, $createdEnd - $createdStart - 1).$entryCbor, $claimBox);
$guard = bSplice($guard, $FIRST_ASSERTION_BOX, 0, $box, $storeBox);
$guard = imRebind($guard, $png, strlen($box), $HASH_DATA_BOX, $CLAIM);

// --- redacted: the claim grows one pair, redacted_assertions naming an assertion of this manifest
$redactionPair = imCbor('redacted_assertions').imCbor([sprintf('self#jumbf=/c2pa/%s/c2pa.assertions/c2pa.actions.v2', $PNG_LABEL)]);
$claimEnd = bCborEnd($p, $CLAIM);
$redacted = bSplice($p, $claimEnd, 0, $redactionPair, $claimBox);
$redacted = bReplace($redacted, $CLAIM, "\xa7", "\xa8");
$redacted = imRebind($redacted, $png, 0, $HASH_DATA_BOX, $CLAIM);

foreach (['records-active-fault' => [$guard, true], 'redacted' => [$redacted, false]] as $name => [$store, $breakSignature]) {
    $parsed = ManifestStore::fromTree((new JumbfParser)->parse($store));
    $manifest = $parsed->active;
    $claimBytes = $manifest->claimBytes();
    $coseAt = strpos($store, $manifest->signatureBytes());
    if ($coseAt === false) {
        throw new RuntimeException("{$name}: no COSE_Sign1");
    }
    $length = strlen($manifest->signatureBytes());
    $cose = imCose($claimBytes, $length, $leafDer, $rootDer, $keys, $name);
    $store = substr($store, 0, $coseAt).$cose.substr($store, $coseAt + $length);
    if ($breakSignature) {
        // one byte of the signature flipped *after* signing: the claim is unchanged, the signature is not its
        $flip = $coseAt + $length - 8;
        $store[$flip] = chr(ord($store[$flip]) ^ 0x01);
    }
    file_put_contents("{$dir}/{$name}.bin", $store);
    file_put_contents("{$dir}/{$name}.png", pngWithStore($png, $store));
    printf("%s  %d bytes  %s\n", hash('sha256', $store), strlen($store), $name);
}
