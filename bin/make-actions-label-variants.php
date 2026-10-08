<?php

declare(strict_types=1);

/*
 * Step 281, SPEC-018 amendment 6: the actions assertion of a version 2 claim
 * under the version 1 label `c2pa.actions`. Neither `c2patool` version can
 * write one (both rewrite the label to `c2pa.actions.v2` when they sign), so
 * the PNG fixture's store is edited instead, in the shape
 * bin/make-absence-variants.php (step 48) uses: the label changed in the
 * assertion's description box and in the claim's hashed URI, the hashes
 * recomputed, the claim re-signed with a throw-away P-256 hierarchy. Keys
 * live outside the repository for the run and are deleted before it ends;
 * the public root goes into a settings file. Both `c2patool` versions then
 * judge each file; their JSON is the oracle.
 *
 * Variants:
 *   control-resigned               the store unchanged, re-signed: the pipeline itself
 *   actions-v1-label-in-v2-claim   the actions assertion labelled c2pa.actions
 *   actions-v1-label-first-edited  the same, its first action c2pa.edited: do the v2 rules still apply?
 *
 * Usage: php bin/make-actions-label-variants.php <scratch-dir> <c2patool-0.28.1> <c2patool-0.27.22>.
 * Tooling, not the verification path.
 *
 * Writes:
 *   tests/Fixtures/actions-label/<variant>.png, throw-away-root.pem, throw-away-root.settings.json
 *   tests/Fixtures/c2patool/actions-label/<variant>--<version>.json
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Cose\SignatureVerifier;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestException;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$scratch, $new, $old] = [$argv[1] ?? null, $argv[2] ?? '', $argv[3] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_string($scratch) || ! is_dir($scratch) || ! is_file($new) || ! is_file($old) || $version($new) !== 'c2patool 0.28.1' || $version($old) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-actions-label-variants.php <scratch-dir outside the repository> <c2patool-0.28.1> <c2patool-0.27.22>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/actions-label-keys-'.bin2hex(random_bytes(4));
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

function alRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function alSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

function alBstr(string $b): string
{
    $n = strlen($b);

    return ($n < 24 ? chr(0x40 + $n) : ($n < 256 ? "\x58".chr($n) : "\x59".pack('n', $n))).$b;
}

/** A DER ECDSA signature as R‖S of 2 × $curveBytes (what COSE carries). */
function alDerToRs(string $der, int $curveBytes): string
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

// ---- the fixture's store (step 09 offsets) ----
$root = dirname(__DIR__);
$png = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.png');
$stream = fopen($root.'/tests/Fixtures/fixture-signed.png', 'rb');
if ($stream === false) {
    throw new RuntimeException('cannot open the PNG fixture');
}
$extracted = (new PngManifestStoreExtractor)->extract($stream);
if ($extracted === null || strlen($extracted->bytes) !== 46025) {
    throw new RuntimeException('the PNG store is not the one measured in step 09');
}
$s = $extracted->bytes;
$HASH_DATA_BOX = 32831;      // the c2pa.hash.data assertion box, 195 bytes
$CLAIM = 33081;              // the claim's CBOR map (7 pairs, 591 bytes)
$storeBox = [0, 38, 117];    // the boxes enclosing an assertion: store superbox, c2pa manifest superbox, assertion store superbox
$claimBox = [0, 38, 33026, 33073];
if (substr($s, $HASH_DATA_BOX + 4, 4) !== 'jumb' || ord($s[$CLAIM]) !== 0xA7) {
    throw new RuntimeException('the store is not laid out as step 09 measured');
}

// ---- the fixture's assertion entries and boxes (step 09 offsets) ----
$THUMBNAIL_BOX = 166;        // c2pa.thumbnail.claim.png, 32470 bytes
$ACTIONS_BOX = 32636;        // c2pa.actions.v2, 195 bytes
$claim = bMapPairs($s, $CLAIM);
[, $createdStart, $createdEnd] = $claim['created_assertions'];     // 81 { c2pa.hash.data }
[, $gatheredStart, $gatheredEnd] = $claim['gathered_assertions'];  // 82 { thumbnail } { actions }
$hashDataEntry = substr($s, $createdStart + 1, $createdEnd - $createdStart - 1);
$thumbEntryEnd = bCborEnd($s, $gatheredStart + 1);
$thumbEntry = substr($s, $gatheredStart + 1, $thumbEntryEnd - $gatheredStart - 1);
$actionsEntry = substr($s, $thumbEntryEnd, $gatheredEnd - $thumbEntryEnd);
if (! str_contains($hashDataEntry, 'c2pa.hash.data') || ! str_contains($thumbEntry, 'c2pa.thumbnail') || ! str_contains($actionsEntry, 'c2pa.actions.v2')) {
    throw new RuntimeException('the claim lists are not [hash.data] + [thumbnail, actions] as step 09 measured');
}
$list = static fn (string ...$entries): string => pack('C', 0x80 + count($entries)).implode('', $entries);

/**
 * The hash of $bytes with the [start, length] ranges skipped — what c2pa.hash.data covers (C2PA 2.4 §15.12.1).
 *
 * @param  list<array{0: int, 1: int}>  $exclusions
 */
function alAbsenceDataHash(string $bytes, array $exclusions): string
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
function alEntryFor(string $s, int $list, string $label): ?int
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

/**
 * A store whose boxes moved: the hash.data assertion's exclusion re-lengthened to the new store, its data hash
 * recomputed over the PNG that will carry the store, and the claim's hashed URI for it recomputed — so that only
 * the absence under test differs from a valid file. $shift = bytes removed before the hash.data box and the claim.
 */
function alRebind(string $store, string $png, int $shift, int $hashDataBox, int $claimAt): string
{
    $box = $hashDataBox - $shift;
    $payload = $box + 80;   // the cbor box's payload inside the assertion superbox (step 26 offsets)
    $hd = bMapPairs($store, $payload);
    $exclusionMap = $hd['exclusions'][1] + 1;   // 81 a2 …
    $ex = bMapPairs($store, $exclusionMap);
    $lengthValue = $ex['length'][1];            // 19 xx xx
    if ($store[$lengthValue] !== "\x19") {
        throw new RuntimeException('the exclusion length is not a two-byte CBOR uint');
    }
    $store = bReplace($store, $lengthValue + 1, substr($store, $lengthValue + 1, 2), pack('n', 12 + strlen($store)));
    $digest = alAbsenceDataHash(pngWithStore($png, $store), [[33, 12 + strlen($store)]]);
    $hashValue = $hd['hash'][1] + 2;
    $store = bReplace($store, $hashValue, substr($store, $hashValue, 32), $digest);
    $boxLength = bU32($store, $box);
    $uri = hash('sha256', substr($store, $box + 8, $boxLength - 8), true);
    $claim = bMapPairs($store, $claimAt - $shift);
    $entry = alEntryFor($store, $claim['created_assertions'][1], 'c2pa.hash.data') ?? alEntryFor($store, $claim['gathered_assertions'][1], 'c2pa.hash.data');
    if ($entry === null) {
        throw new RuntimeException('no c2pa.hash.data entry in the claim');
    }
    $claimHash = bMapPairs($store, $entry)['hash'][1] + 2;

    return bReplace($store, $claimHash, substr($store, $claimHash, 32), $uri);
}

/** The claim's hashed URI for the assertion box at $box recomputed (sha256 over the box minus its 8-byte header, §8.4.2.3). */
function alRehashEntry(string $store, int $box, int $claimAt, string $label): string
{
    $boxLength = bU32($store, $box);
    $uri = hash('sha256', substr($store, $box + 8, $boxLength - 8), true);
    $claim = bMapPairs($store, $claimAt);
    $entry = alEntryFor($store, $claim['created_assertions'][1], $label) ?? alEntryFor($store, $claim['gathered_assertions'][1], $label);
    if ($entry === null) {
        throw new RuntimeException("no {$label} entry in the claim");
    }
    $claimHash = bMapPairs($store, $entry)['hash'][1] + 2;

    return bReplace($store, $claimHash, substr($store, $claimHash, 32), $uri);
}

// ---- step 281: the actions assertion relabelled c2pa.actions (the v1 label) in this v2 claim ----
$v2 = 'self#jumbf=c2pa.assertions/c2pa.actions.v2';
$v1 = 'self#jumbf=c2pa.assertions/c2pa.actions';
$at = strpos($s, $v2, $CLAIM);
if ($at === false || $s[$at - 2] !== "\x78" || ord($s[$at - 1]) !== strlen($v2)) {
    throw new RuntimeException('the claim\'s actions url is not where step 09 measured');
}
$relabelled = bSplice($s, $at - 2, 2 + strlen($v2), "\x78".chr(strlen($v1)).$v1, $claimBox);   // the claim first: it lies after the box
$label = strpos($relabelled, "c2pa.actions.v2\0", $ACTIONS_BOX);
if ($label === false || $label > $ACTIONS_BOX + 73) {
    throw new RuntimeException('the actions label is not in its description box');
}
$relabelled = bSplice($relabelled, $label, strlen("c2pa.actions.v2\0"), "c2pa.actions\0", [0, 38, 117, $ACTIONS_BOX, $ACTIONS_BOX + 8]);
$relabelled = alRehashEntry($relabelled, $ACTIONS_BOX, $CLAIM - 3, 'c2pa.actions');
// the same, with the first action c2pa.edited: do the version 2 rules (§15.10.3.2.3) still apply to it?
$edited = $relabelled;
$editedFirst = strpos($edited, "\x6cc2pa.created", $ACTIONS_BOX);
if ($editedFirst === false || $editedFirst > $ACTIONS_BOX + 192) {
    throw new RuntimeException('no c2pa.created in the relabelled actions box');
}
$edited = bSplice($edited, $editedFirst, 13, "\x6bc2pa.edited", [0, 38, 117, $ACTIONS_BOX, $ACTIONS_BOX + 70]);
$edited = alRehashEntry($edited, $ACTIONS_BOX, $CLAIM - 4, 'c2pa.actions');
$variants = [
    'actions-v1-label-first-edited' => alRebind($edited, $png, 4, $HASH_DATA_BOX, $CLAIM),
    'control-resigned' => $s,
    'actions-v1-label-in-v2-claim' => alRebind($relabelled, $png, 3, $HASH_DATA_BOX, $CLAIM),
];
// ---- the throw-away hierarchy: a P-256 root and a leaf on the C2PA profile ----
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
alRun(alSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
alRun(alSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (step 281)', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'v3_root', '-out', "{$keys}/root.pem"));
alRun(alSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/leaf.key"));
alRun(alSh('openssl', 'req', '-new', '-key', "{$keys}/leaf.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=step 281 actions label', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/leaf.csr"));
alRun(alSh('openssl', 'x509', '-req', '-in', "{$keys}/leaf.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'good', '-out', "{$keys}/leaf.pem"));
$pemToDer = static fn (string $pem): string => (string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem) ?? '', true);
$rootPem = (string) file_get_contents("{$keys}/root.pem");
$rootDer = $pemToDer($rootPem);
$leafDer = $pemToDer((string) file_get_contents("{$keys}/leaf.pem"));

$dir = $root.'/tests/Fixtures/actions-label';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
$settings = ['trust' => ['trust_anchors' => $rootPem, 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')], 'verify' => ['verify_trust' => true]];
file_put_contents("{$dir}/throw-away-root.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

foreach ($variants as $name => $edited) {
    // the store still parses as the edit intends — or, when this verifier's own claim reader refuses the
    // edit (created-empty: a v2 claim must create at least one assertion here), the claim is cut out by
    // offset so that c2patool can still be asked what *it* makes of the signed file
    try {
        $manifest = ManifestStore::fromTree((new JumbfParser)->parse($edited))->active;
        $claimBytes = $manifest->claimBytes();
        $named = array_map(static fn ($u): string => $u->url, [...$manifest->claim->createdAssertions, ...$manifest->claim->gatheredAssertions]);
        $COSE = strpos($edited, $manifest->signatureBytes());
    } catch (ManifestException $e) {
        $claimBytes = substr($edited, $CLAIM, bCborEnd($edited, $CLAIM) - $CLAIM);
        $named = ['(this verifier refuses the claim: '.$e->getMessage().')'];
        $COSE = strpos($edited, "\xd2\x84", $CLAIM);
    }
    if ($COSE === false) {
        throw new RuntimeException("{$name}: no COSE_Sign1");
    }
    $COSE_LENGTH = bU32($edited, $COSE - 8) - 8;

    // the new COSE_Sign1: {1: -7, 33: [leaf, root]}, signed over the edited claim
    $protected = "\xa2\x01\x26\x18\x21\x82".alBstr($leafDer).alBstr($rootDer);
    $draft = "\xd2\x84".alBstr($protected)."\xa1\x63pad".alBstr('')."\xf6".alBstr(str_repeat("\0", 64));
    $sigStructure = CoseSign1::fromBytes($draft)->sigStructure($claimBytes);
    file_put_contents("{$keys}/tbs", $sigStructure);
    alRun(alSh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/leaf.key", '-out', "{$keys}/sig", "{$keys}/tbs"));
    $signature = alDerToRs((string) file_get_contents("{$keys}/sig"), 32);
    $fixed = 2 + strlen(alBstr($protected)) + 5 + 3 + 1 + strlen(alBstr($signature));
    $padLength = $COSE_LENGTH - $fixed;
    if ($padLength < 256) {
        throw new RuntimeException("{$name}: the pad would be {$padLength} bytes, too short for a 3-byte head");
    }
    $cose = "\xd2\x84".alBstr($protected)."\xa1\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength)."\xf6".alBstr($signature);
    if (strlen($cose) !== $COSE_LENGTH) {
        throw new RuntimeException("{$name}: COSE is ".strlen($cose)." bytes, not {$COSE_LENGTH}");
    }
    $store = substr($edited, 0, $COSE).$cose.substr($edited, $COSE + $COSE_LENGTH);
    if (! (new SignatureVerifier)->verify(CoseSign1::fromBytes($cose), $claimBytes)) {
        throw new RuntimeException("{$name}: the new signature does not verify under its own leaf");
    }
    file_put_contents("{$dir}/{$name}.png", pngWithStore($png, $store));
    printf("%s  %d bytes  %-20s claim names: %s\n", hash('sha256', $store), strlen($store), $name, implode(', ', array_map(static fn (string $u): string => substr($u, strlen('self#jumbf=c2pa.assertions/')), $named)));
}

// ---- the oracles ----
$oracles = $root.'/tests/Fixtures/c2patool/actions-label';
if (! is_dir($oracles) && ! mkdir($oracles, 0755, true)) {
    throw new RuntimeException("cannot create {$oracles}");
}
foreach (array_keys($variants) as $name) {
    foreach (['0.28.1' => $new, '0.27.22' => $old] as $v => $tool) {
        $lines = [];
        exec(alSh($tool, "{$dir}/{$name}.png", '--settings', "{$dir}/throw-away-root.settings.json").' 2>&1', $lines);
        file_put_contents("{$oracles}/{$name}--{$v}.json", implode("\n", $lines)."\n");
        $json = json_decode(implode("\n", $lines), true);
        printf("  %-30s %-8s %s\n", $name, $v, is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : 'error');
    }
}
