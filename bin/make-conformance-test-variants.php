<?php

declare(strict_types=1);

/*
 * SPEC-078: files signed with the C2PA conformance programme's public test certificate.
 *
 * contentauth/c2pa-conformance-tool (6d10ee0, Apache-2.0) publishes a "C2PA Conformance Test
 * Root" (embedded in src/lib/CertificateManager.svelte) and, in
 * public/test-certs/test-signing-bundle.zip, a leaf "C2PA Conformance Test Signing" with its
 * private key, on purpose. This script signs fixture-unsigned.{jpg,png,webp} with that leaf
 * using c2patool 0.28.2, makes one variant of each with one byte of the image data changed
 * after signing (outside the manifest store, which it checks), writes the two public
 * certificates and two settings files next to them, and records c2patool's answers with the
 * root alone and with the root and store.cfg.
 *
 * Usage: php bin/make-conformance-test-variants.php <scratch-dir outside the repository>
 *            <c2patool-0.28.2> <test-signing-bundle.pem> <test-root.pem>
 *
 * The key is copied into a directory of its own under the scratch directory, converted to
 * PKCS #8 there for c2patool, and deleted when the script ends. No key is written to the
 * repository. Tooling, not the verification path.
 */

require __DIR__.'/../vendor/autoload.php';

use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Container\WebpManifestStoreExtractor;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$scratch, $tool, $bundlePath, $rootPath] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''];
$repo = (string) realpath(__DIR__.'/..');
$usage = "usage: php bin/make-conformance-test-variants.php <scratch-dir outside the repository> <c2patool-0.28.2> <test-signing-bundle.pem> <test-root.pem>\n";

// the scratch directory first: the key goes there, and never under the repository (AC5)
$scratchReal = realpath($scratch);
if ($scratchReal === false || ! is_dir($scratchReal) || $scratchReal === $repo || str_starts_with($scratchReal.'/', $repo.'/')) {
    fwrite(STDERR, "the scratch directory must exist and lie outside the repository\n".$usage);
    exit(1);
}
exec(escapeshellarg($tool).' --version 2>&1', $versionLines);
if (($versionLines[0] ?? '') !== 'c2patool 0.28.2') {
    fwrite(STDERR, "the second argument must be c2patool 0.28.2\n".$usage);
    exit(1);
}
$bundle = is_file($bundlePath) ? (string) file_get_contents($bundlePath) : '';
$rootPem = is_file($rootPath) ? (string) file_get_contents($rootPath) : '';
if (preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----\n?/s', $bundle, $leafMatch) !== 1
    || preg_match('/-----BEGIN (EC )?PRIVATE KEY-----.*?-----END (EC )?PRIVATE KEY-----\n?/s', $bundle, $keyMatch) !== 1
    || preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----\n?/s', $rootPem, $rootMatch) !== 1) {
    fwrite(STDERR, "the bundle must hold a certificate and its key, and the root file a certificate\n".$usage);
    exit(1);
}
$leafPem = $leafMatch[0];
$rootPem = $rootMatch[0];

$keys = $scratchReal.'/conformance-test-keys-'.bin2hex(random_bytes(4));
if (! mkdir($keys, 0700)) {
    throw new RuntimeException("cannot create {$keys}");
}
register_shutdown_function(static function () use ($keys): void {
    foreach (glob("{$keys}/*") ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($keys)) {
        rmdir($keys);
    }
});
$key = openssl_pkey_get_private($keyMatch[0]);
if ($key === false || ! openssl_pkey_export($key, $pkcs8)) {
    throw new RuntimeException('the bundle key could not be read');
}
file_put_contents("{$keys}/key.pk8", $pkcs8);
file_put_contents("{$keys}/chain.pem", $leafPem);

$fixtures = $repo.'/tests/Fixtures';
$out = $fixtures.'/conformance-test';
$oracles = $fixtures.'/c2patool/conformance-test';
foreach ([$out, $oracles] as $dir) {
    if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
        throw new RuntimeException("cannot create {$dir}");
    }
}
file_put_contents("{$out}/test-root.pem", $rootPem);
file_put_contents("{$out}/test-signing.pem", $leafPem);
$settings = [
    'root' => ['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $rootPem]],
    'root-store-cfg' => ['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $rootPem, 'trust_config' => (string) file_get_contents($fixtures.'/trust/store.cfg')]],
];
foreach ($settings as $name => $document) {
    file_put_contents("{$out}/{$name}.settings.json", json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

file_put_contents("{$keys}/manifest.json", (string) json_encode([
    'alg' => 'es256', 'private_key' => "{$keys}/key.pk8", 'sign_cert' => "{$keys}/chain.pem",
    'claim_generator_info' => [['name' => 'c2pa-verifier conformance test probes', 'version' => '1']],
    'assertions' => [
        ['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture']], 'allActionsIncluded' => true]],
    ],
], JSON_UNESCAPED_SLASHES));

/** @return array{int, string} the exit status and the output of a command */
function ctRun(string $command): array
{
    exec($command.' 2>&1', $lines, $status);

    return [$status, implode("\n", $lines)];
}

/**
 * The manifest store's byte ranges of a signed file, by its own extractor.
 *
 * @return list<array{start: int, length: int}>
 */
function ctStoreRanges(string $path, string $type): array
{
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$path}");
    }
    $store = match ($type) {
        'jpg' => (new JpegManifestStoreExtractor)->extract($stream),
        'png' => (new PngManifestStoreExtractor)->extract($stream),
        default => (new WebpManifestStoreExtractor)->extract($stream),
    };
    fclose($stream);
    if ($store === null) {
        throw new RuntimeException("{$path} holds no manifest store");
    }

    return $store->ranges;
}

$settingsFile = "{$out}/root-store-cfg.settings.json";
$sha = [];
foreach (['jpg', 'png', 'webp'] as $type) {
    $signed = "{$out}/signed.{$type}";
    [$status, $text] = ctRun(sprintf('%s %s -m %s --settings %s -o %s -f', escapeshellarg($tool), escapeshellarg("{$fixtures}/fixture-unsigned.{$type}"), escapeshellarg("{$keys}/manifest.json"), escapeshellarg($settingsFile), escapeshellarg($signed)));
    if ($status !== 0 || ! is_file($signed)) {
        throw new RuntimeException("c2patool did not sign {$type}: {$text}");
    }
    // one byte of the image data, outside the store: JPEG and PNG 40 before the end, WebP at 40 (VP8L; the C2PA chunk is last)
    $bytes = (string) file_get_contents($signed);
    $at = $type === 'webp' ? 40 : strlen($bytes) - 40;
    foreach (ctStoreRanges($signed, $type) as $range) {
        if ($at >= $range['start'] && $at < $range['start'] + $range['length']) {
            throw new RuntimeException("offset {$at} of {$type} lies inside the manifest store");
        }
    }
    $bytes[$at] = chr(ord($bytes[$at]) ^ 0x01);
    file_put_contents("{$out}/edited.{$type}", $bytes);

    foreach (["signed.{$type}", "edited.{$type}"] as $name) {
        $sha[$name] = hash_file('sha256', "{$out}/{$name}");
        foreach (array_keys($settings) as $settingsName) {
            [, $answer] = ctRun(sprintf('%s %s --settings %s', escapeshellarg($tool), escapeshellarg("{$out}/{$name}"), escapeshellarg("{$out}/{$settingsName}.settings.json")));
            file_put_contents("{$oracles}/{$name}--{$settingsName}.json", $answer."\n");
        }
    }
}

$date = gmdate('Y-m-d');
$rows = [];
foreach ($sha as $name => $hash) {
    $rows[] = "| `{$name}` | `{$hash}` |";
}
$table = implode("\n", $rows);
file_put_contents("{$out}/README.md", <<<MD
    # Files signed with the conformance programme's test certificate (SPEC-078)

    Made by `bin/make-conformance-test-variants.php` with `c2patool` 0.28.2 on
    {$date}. The signer is "C2PA Conformance Test
    Signing" (`test-signing.pem`), issued by "C2PA Conformance Test Root"
    (`test-root.pem`), both from `contentauth/c2pa-conformance-tool` `6d10ee0`
    (Apache-2.0): the root embedded in `src/lib/CertificateManager.svelte`, the
    leaf and its key in `public/test-certs/test-signing-bundle.zip`, published on
    purpose for testing. The key was used in a scratch directory outside the
    repository and deleted; **no key is here**.

    `signed.*` are `fixture-unsigned.*` signed with one `c2pa.actions.v2`
    (`c2pa.created`, `digitalCapture`, `allActionsIncluded`); `edited.*` have one
    byte of the image data changed after signing (JPEG and PNG 40 bytes before
    the end, WebP at offset 40, inside `VP8L`). No time-stamp: the bundle has no
    time-stamp authority. Running the script again makes new bytes (an ECDSA
    signature is randomised); the files here are the ones measured.

    `root.settings.json` holds the root as `trust_anchors`; `root-store-cfg.settings.json`
    adds `../trust/store.cfg` as `trust_config`. `c2patool`'s answers with each are in
    `../c2patool/conformance-test/`.

    | file | SHA-256 |
    |---|---|
    {$table}

    MD);
printf("wrote %d files to %s and %d answers to %s\n", count($sha), $out, count($sha) * 2, $oracles);
