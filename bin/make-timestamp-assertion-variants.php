<?php

declare(strict_types=1);

/*
 * SPEC-064 (step 332): probes for the time-stamp assertion of C2PA 2.4 §18.18. A parent P is signed by
 * `c2patool` 0.28.1 with a signer that expires a few minutes later and no timestamp in its header. A
 * child U is signed by `c2patool` with P as its parent and a `c2pa.time-stamp` assertion whose value is a
 * placeholder of the token's length; the placeholder is then replaced by an RFC 3161 token from a
 * throw-away TSA (`openssl ts`), made a byte string, and U's claim is re-hashed and signed again. The
 * probes are judged once P's signer has expired, so only the assertion's token can keep P valid.
 *
 * Variants (tests/Fixtures/timestamp/assertion/<probe>.png and .settings.json):
 *   raw                a token over P's COSE signature field, as c2pa-rs writes it (store.rs, refresh_timestamp)
 *   structure          a token over the CounterSignature structure a sigTst2 header token covers (§10.3.2.5)
 *   other-label        the token keyed by another label: P has no token (the control)
 *   wrong-data         a token over other bytes: timeStamp.mismatch
 *   untrusted          raw, with settings that hold no TSA anchor: timeStamp.untrusted
 *   array              the assertion a CBOR array instead of a map: assertion.timestamp.malformed
 *   two-assertions     two c2pa.time-stamp assertions in U, each valid
 *   update-raw         U an update manifest on P itself (c2patool --update), with raw's token
 *   update-other-label the same, the token keyed by another label (the control)
 *   header-wins        P is timestamp/tsa-matrix/expired-signer-trusted-tsa.png (a trusted header token); U's
 *                      assertion holds a token for it taken now, after its signer expired (§15.8.1.2: the header stands)
 *
 * Usage: php bin/make-timestamp-assertion-variants.php <scratch-dir> <c2patool-0.28.1> <c2patool-0.27.22>
 * Keys live in a scratch directory for the run and are deleted before it ends. Tooling, not the
 * verification path.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
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
    fwrite(STDERR, "usage: php bin/make-timestamp-assertion-variants.php <scratch-dir outside the repository> <c2patool-0.28.1> <c2patool-0.27.22>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/ts-assertion-keys-'.bin2hex(random_bytes(4));
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

function taRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function taSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

/** A CBOR byte string head and its bytes. */
function taBstr(string $b): string
{
    $n = strlen($b);

    return ($n < 24 ? chr(0x40 + $n) : ($n < 256 ? "\x58".chr($n) : "\x59".pack('n', $n))).$b;
}

/** @return array{0: string, 1: int} the caBX store and the chunk's offset */
function taStore(string $png): array
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

/** The caBX chunk at $chunk replaced by one holding $s, its length and CRC set. */
function taChunk(string $png, int $chunk, string $s): string
{
    return substr($png, 0, $chunk).pack('N', strlen($s)).'caBX'.$s.pack('N', crc32('caBX'.$s)).substr($png, $chunk + 12 + bU32($png, $chunk));
}

/** The content of the cbor box of the last superbox labelled $label: the active manifest's. */
function taCbor(string $s, string $label): string
{
    $at = strrpos($s, $label."\0");
    $cbor = $at === false ? false : strpos($s, 'cbor', $at);
    if ($cbor === false) {
        throw new RuntimeException("no cbor box after {$label}");
    }

    return substr($s, $cbor + 4, bU32($s, $cbor - 4) - 8);
}

/**
 * $from replaced by $to (the same length) inside the last assertion box labelled $label, the claim's hashed URI
 * for it re-hashed (SHA-256), and the claim signed again (ES256) with $key; the Sig_structure built here.
 */
function taEdit(string $s, string $label, string $from, string $to, string $key): string
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
    $claim = taCbor($s, 'c2pa.claim.v2');
    $q = strpos($claim, hash('sha256', substr($s, $box + 8, $length - 8), true));
    if ($q === false) {
        throw new RuntimeException("the claim holds no hash of {$label}");
    }
    $signature = taCbor($s, 'c2pa.signature');
    $claimAt = (int) strrpos($s, $claim);
    $s = substr_replace($s, $to, $p, strlen($to));
    $newClaim = substr_replace($claim, hash('sha256', substr($s, $box + 8, $length - 8), true), $q, 32);
    $s = substr_replace($s, $newClaim, $claimAt, strlen($newClaim));
    // the Sig_structure (RFC 9052 §4.4): ["Signature1", protected, h'', payload]
    $head = ord($signature[2]);
    [$plen, $pat] = $head === 0x58 ? [ord($signature[3]), 4] : ($head === 0x59 ? [bU32("\0\0".substr($signature, 3, 2), 0), 5] : [$head - 0x40, 3]);
    file_put_contents(dirname($key).'/tbs', "\x84\x6aSignature1".taBstr(substr($signature, $pat, $plen))."\x40".taBstr($newClaim));
    taRun(taSh('openssl', 'dgst', '-sha256', '-sign', $key, '-out', dirname($key).'/sig', dirname($key).'/tbs'));
    $der = (string) file_get_contents(dirname($key).'/sig');
    $rs = '';
    for ($i = 0, $o = 2; $i < 2; $i++) {
        $l = ord($der[$o + 1]);
        $rs .= str_pad(ltrim(substr($der, $o + 2, $l), "\0"), 32, "\0", STR_PAD_LEFT);
        $o += 2 + $l;
    }
    $coseAt = (int) strrpos($s, $signature);
    if (substr($signature, -66, 2) !== "\x58\x40") {
        throw new RuntimeException('the COSE_Sign1 does not end in a 64-byte signature');
    }

    return substr_replace($s, $rs, $coseAt + strlen($signature) - 64, 64);
}

/**
 * The active manifest's label and its COSE_Sign1's signature field (the raw bytes after the last bstr head).
 *
 * @return array{0: string, 1: string, 2: string} the label, the signature field, the whole COSE_Sign1
 */
function taSigned(string $png): array
{
    [$s] = taStore($png);
    $active = ManifestStore::fromTree((new JumbfParser)->parse($s))->active;
    $cose = $active->signatureBytes();
    $tail = substr($cose, -66);
    if (substr($tail, 0, 2) !== "\x58\x40") {
        throw new RuntimeException('the COSE_Sign1 does not end in a 64-byte signature');
    }

    return [$active->label, substr($tail, 2), $cose];
}

/**
 * A throw-away P-256 certificate: self-signed when $issuer is null.
 *
 * @param  list<string>  $dates
 */
function taCert(string $keys, string $name, string $cn, string $section, ?string $issuer, array $dates): void
{
    taRun(taSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/{$name}.key"));
    if ($issuer === null) {
        taRun(taSh(...array_merge(['openssl', 'req', '-x509', '-new', '-key', "{$keys}/{$name}.key", '-subj', "/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN={$cn}", '-config', "{$keys}/ext.cnf", '-extensions', $section], $dates, ['-out', "{$keys}/{$name}.pem"])));

        return;
    }
    taRun(taSh('openssl', 'req', '-new', '-key', "{$keys}/{$name}.key", '-subj', "/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN={$cn}", '-config', "{$keys}/ext.cnf", '-out', "{$keys}/{$name}.csr"));
    taRun(taSh(...array_merge(['openssl', 'x509', '-req', '-in', "{$keys}/{$name}.csr", '-CA', "{$keys}/{$issuer}.pem", '-CAkey', "{$keys}/{$issuer}.key", '-set_serial', (string) random_int(1000, 999999), '-extfile', "{$keys}/ext.cnf", '-extensions', $section], $dates, ['-out', "{$keys}/{$name}.pem"])));
}

/**
 * U: fixture-unsigned.png signed by the long-lived signer with $parent as its parent (or, $update, an update
 * manifest on $parent itself) and one time-stamp assertion per token; each placeholder is then replaced by
 * $tokens[$i] keyed by $labels[$i], and the result written.
 *
 * @param  Closure(string, list<array<string, mixed>>, bool): string  $definition
 * @param  list<string>  $tokens
 * @param  list<string>  $labels
 */
function taChild(Closure $definition, string $keys, string $new, string $root, string $dir, string $name, string $parent, array $tokens, array $labels, bool $asArray = false, bool $update = false): void
{
    $extra = [];
    foreach ($tokens as $i => $t) {
        $extra[] = ['label' => 'c2pa.time-stamp', 'data' => [$labels[$i] => str_repeat(pack('C', 0x41 + $i), strlen($t))]];
    }
    file_put_contents("{$keys}/m-child.json", $definition('long', $extra, $update));
    // an update manifest is added to the parent itself; a standard one names it with -p
    taRun($update
        ? taSh($new, $parent, '-m', "{$keys}/m-child.json", '--update', '-o', "{$keys}/child.png", '-f')
        : taSh($new, $root.'/tests/Fixtures/fixture-unsigned.png', '-m', "{$keys}/m-child.json", '-p', $parent, '-o', "{$keys}/child.png", '-f'));
    $png = (string) file_get_contents("{$keys}/child.png");
    [$s, $chunk] = taStore($png);
    foreach ($tokens as $i => $t) {
        $n = strlen($t);
        $textHead = $n < 256 ? "\x78".pack('C', $n) : "\x79".pack('n', $n);
        $byteHead = $n < 256 ? "\x58".pack('C', $n) : "\x59".pack('n', $n);
        $label = $i === 0 ? 'c2pa.time-stamp' : 'c2pa.time-stamp__'.$i;
        $placeholder = $textHead.str_repeat(pack('C', 0x41 + $i), $n);
        if ($asArray) {
            // the map of one pair made an array of two: the same bytes, another major type
            $key = "\x78".pack('C', strlen($labels[$i])).$labels[$i];
            $s = taEdit($s, $label, "\xa1".$key.$placeholder, "\x82".$key.$byteHead.$t, "{$keys}/long.key");

            continue;
        }
        $s = taEdit($s, $label, $placeholder, $byteHead.$t, "{$keys}/long.key");
    }
    file_put_contents("{$dir}/{$name}.png", taChunk($png, $chunk, $s));
}

$root = dirname(__DIR__);
$dir = $root.'/tests/Fixtures/timestamp/assertion';
$oracles = $root.'/tests/Fixtures/c2patool/timestamp-assertion';
foreach ([$dir, $oracles] as $d) {
    if (! is_dir($d) && ! mkdir($d, 0755, true)) {
        throw new RuntimeException("cannot create {$d}");
    }
}

// ---- the throw-away hierarchies: a signer root with a short-lived leaf (P) and a long-lived leaf (U); a TSA ----
$ext = "[req]\ndistinguished_name=dn\n[dn]\n[root]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n"
    ."[leaf]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=emailProtection\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n"
    ."[tsa]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=critical,timeStamping\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n";
file_put_contents("{$keys}/ext.cnf", $ext);
$expires = time() + 150;
taCert($keys, 'root', 'Throw-away Root (time-stamp assertion)', 'root', null, ['-days', '3650']);
taCert($keys, 'short', 'Short-lived signer (time-stamp assertion)', 'leaf', 'root', ['-not_before', gmdate('YmdHis\Z', time() - 86400), '-not_after', gmdate('YmdHis\Z', $expires)]);
taCert($keys, 'long', 'Signer (time-stamp assertion)', 'leaf', 'root', ['-days', '3650']);
taCert($keys, 'tsa-root', 'Throw-away TSA Root (time-stamp assertion)', 'root', null, ['-days', '3650']);
taCert($keys, 'tsa', 'Throw-away TSA (time-stamp assertion)', 'tsa', 'tsa-root', ['-days', '3650']);
foreach (['short', 'long'] as $leaf) {
    taRun(taSh('openssl', 'pkcs8', '-topk8', '-nocrypt', '-in', "{$keys}/{$leaf}.key", '-out', "{$keys}/{$leaf}.pk8"));
}
file_put_contents("{$keys}/tsa.cnf", "[tsa]\ndefault_tsa = t\n[t]\nserial = {$keys}/tsa-serial\ncrypto_device = builtin\nsigner_digest = sha256\ndefault_policy = 1.3.6.1.4.1.99999.10\ndigests = sha256\naccuracy = secs:1\ness_cert_id_alg = sha256\ness_cert_id_chain = no\n");
file_put_contents("{$keys}/tsa-serial", "01\n");
/** An RFC 3161 token (the TimeStampToken, DER) over the SHA-256 of $data, from the throw-away TSA, now. */
$token = static function (string $data) use ($keys): string {
    taRun(taSh('openssl', 'ts', '-query', '-digest', hash('sha256', $data), '-sha256', '-cert', '-out', "{$keys}/q.tsq"));
    taRun(taSh('openssl', 'ts', '-reply', '-config', "{$keys}/tsa.cnf", '-queryfile', "{$keys}/q.tsq", '-signer', "{$keys}/tsa.pem", '-inkey', "{$keys}/tsa.key", '-token_out', '-out', "{$keys}/tok.der"));

    return (string) file_get_contents("{$keys}/tok.der");
};
$storeCfg = (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg');
$entry = static fn (string $pem, string $kind): array => ['trust_anchors' => $pem, 'trust_kind' => $kind];
/** @param list<mixed> $anchors */
$settings = static function (array $anchors) use ($storeCfg): string {
    return json_encode(['verify' => ['verify_trust' => true], 'trust' => ['anchors' => $anchors, 'trust_config' => $storeCfg]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
};
$signerRoot = (string) file_get_contents("{$keys}/root.pem");
$tsaRoot = (string) file_get_contents("{$keys}/tsa-root.pem");
$both = $settings([$entry($signerRoot, 'manifest'), $entry($tsaRoot, 'tsa')]);

/** @param list<array<string, mixed>> $extra */
$definition = static function (string $leaf, array $extra, bool $update = false) use ($keys): string {
    return (string) json_encode([
        'alg' => 'es256', 'private_key' => "{$keys}/{$leaf}.pk8", 'sign_cert' => "{$keys}/{$leaf}.pem",
        'claim_generator_info' => [['name' => 'c2pa-verifier time-stamp assertion probes', 'version' => '1']],
        // an update manifest carries no c2pa.created (§11.2.3)
        'assertions' => $update ? $extra : [
            ['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture']]]],
            ...$extra,
        ],
    ], JSON_UNESCAPED_SLASHES);
};

// ---- the parent P, signed by the short-lived signer, no header timestamp ----
file_put_contents("{$keys}/m-parent.json", $definition('short', []));
taRun(taSh($new, $root.'/tests/Fixtures/fixture-unsigned.png', '-m', "{$keys}/m-parent.json", '-o', "{$dir}/parent.png", '-f'));
[$pLabel, $pSignature, $pCose] = taSigned((string) file_get_contents("{$dir}/parent.png"));
$protected = substr($pCose, 0, 3) === "\xd2\x84\x59" ? substr($pCose, 5, bU32("\0\0".substr($pCose, 3, 2), 0)) : throw new RuntimeException('the protected header is not a long byte string');

// ---- the tokens, all taken now, while P's signer is valid ----
$raw = $token($pSignature);
$structure = $token("\x84\x70CounterSignature".taBstr($protected)."\x40".taBstr(taBstr($pSignature)));
$wrong = $token($pSignature.'!');
$otherLabel = substr($pLabel, 0, -1).(substr($pLabel, -1) === '0' ? '1' : '0');

taChild($definition, $keys, $new, $root, $dir, 'raw', "{$dir}/parent.png", [$raw], [$pLabel]);
taChild($definition, $keys, $new, $root, $dir, 'structure', "{$dir}/parent.png", [$structure], [$pLabel]);
taChild($definition, $keys, $new, $root, $dir, 'other-label', "{$dir}/parent.png", [$raw], [$otherLabel]);
taChild($definition, $keys, $new, $root, $dir, 'wrong-data', "{$dir}/parent.png", [$wrong], [$pLabel]);
copy("{$dir}/raw.png", "{$dir}/untrusted.png");
taChild($definition, $keys, $new, $root, $dir, 'update-raw', "{$dir}/parent.png", [$raw], [$pLabel], update: true);
taChild($definition, $keys, $new, $root, $dir, 'update-other-label', "{$dir}/parent.png", [$raw], [$otherLabel], update: true);
taChild($definition, $keys, $new, $root, $dir, 'array', "{$dir}/parent.png", [$raw], [$pLabel], true);
taChild($definition, $keys, $new, $root, $dir, 'two-assertions', "{$dir}/parent.png", [$raw, $token($pSignature)], [$pLabel, $pLabel]);
foreach (['raw', 'structure', 'other-label', 'wrong-data', 'array', 'two-assertions', 'update-raw', 'update-other-label'] as $name) {
    file_put_contents("{$dir}/{$name}.settings.json", $both);
}
file_put_contents("{$dir}/untrusted.settings.json", $settings([$entry($signerRoot, 'manifest')]));

// ---- header-wins: a parent whose header token is trusted and whose signer has expired; a token for it now ----
$headerParent = $root.'/tests/Fixtures/timestamp/tsa-matrix/expired-signer-trusted-tsa.png';
[$hLabel, $hSignature] = taSigned((string) file_get_contents($headerParent));
taChild($definition, $keys, $new, $root, $dir, 'header-wins', $headerParent, [$token($hSignature)], [$hLabel]);
/** @var array{trust: array{anchors: list<mixed>}} $hSettings */
$hSettings = json_decode((string) file_get_contents($root.'/tests/Fixtures/timestamp/tsa-matrix/expired-signer-trusted-tsa.settings.json'), true, 512, JSON_THROW_ON_ERROR);
file_put_contents("{$dir}/header-wins.settings.json", $settings([...$hSettings['trust']['anchors'], $entry($signerRoot, 'manifest'), $entry($tsaRoot, 'tsa')]));
file_put_contents("{$dir}/throw-away-roots.pem", $signerRoot.$tsaRoot);

// ---- judged once P's signer has expired ----
$wait = $expires + 5 - time();
if ($wait > 0) {
    echo "waiting {$wait} s for the short-lived signer to expire\n";
    sleep($wait);
}
foreach (['raw', 'structure', 'other-label', 'wrong-data', 'untrusted', 'array', 'two-assertions', 'update-raw', 'update-other-label', 'header-wins'] as $name) {
    foreach (['0.28.1' => $new, '0.27.22' => $old] as $v => $tool) {
        $lines = [];
        exec(taSh($tool, "{$dir}/{$name}.png", '--settings', "{$dir}/{$name}.settings.json").' 2>&1', $lines);
        file_put_contents("{$oracles}/{$name}--{$v}.json", implode("\n", $lines)."\n");
        /** @var array{validation_state?: string, validation_results?: array{activeManifest?: array{failure?: list<array{code: string}>}, ingredientDeltas?: list<array{validationDeltas: array{failure?: list<array{code: string}>}}>}}|null $json */
        $json = json_decode(implode("\n", $lines), true);
        $state = $json['validation_state'] ?? 'error: '.substr(implode(' ', $lines), 0, 80);
        $deltas = [];
        foreach ($json['validation_results']['ingredientDeltas'] ?? [] as $d) {
            foreach ($d['validationDeltas']['failure'] ?? [] as $f) {
                $deltas[] = $f['code'];
            }
        }
        $own = array_column($json['validation_results']['activeManifest']['failure'] ?? [], 'code');
        printf("  %-18s %-8s %-8s %s | %s\n", $name, $v, $state, implode(',', $own), implode(',', $deltas));
    }
}
