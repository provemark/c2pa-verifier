<?php

declare(strict_types=1);

/*
 * SPEC-054: can the verifier run on this PHP? Require this file before anything
 * in src/, and load src/ only when the answer is yes.
 *
 *     $check = (require '/path/to/c2pa-verifier/requirements.php')();
 *     if ($check['supported']) { ... register the autoloader for src/ ... }
 *
 * This is the one file in the package written for PHP 7.4 and later; everything
 * in src/ needs 8.3. Before 8.3, requiring a file from src/ is a fatal parse
 * error, not an exception, so the question has to be asked here, first. CI runs
 * this file on 7.4, 8.0, 8.1 and 8.2 to keep it readable there.
 *
 * It declares nothing: no namespace, class, function or constant. It returns a
 * closure, so two bundled copies can each require their own without a clash.
 *
 * The closure returns:
 *   supported  bool          PHP 8.3 or later, with openssl and mbstring loaded
 *   missing    list<string>  what fails, in this order: php>=8.3, ext-openssl,
 *                            ext-mbstring
 *   ed25519    bool          Ed25519 signatures can be checked here: the
 *                            verifier is supported, and sodium is loaded or PHP
 *                            is 8.4 or later (whose openssl checks them). Without
 *                            it the verifier still runs; an Ed25519 manifest
 *                            comes back Invalid with algorithm.unsupported.
 *
 * Both arguments default to this PHP; they exist so every branch can be tested.
 * Anything it cannot read counts as not there: an unreadable version is not 8.3,
 * a name that is not a string is not an extension. It never throws.
 */

return static function ($version = null, $extensions = null): array {
    if ($version === null) {
        $version = PHP_VERSION;
    }
    if ($extensions === null) {
        $extensions = get_loaded_extensions();
    }

    // A version that does not start with major.minor.patch is not read at all.
    $version = is_string($version) && preg_match('/^\d+\.\d+\.\d+/', $version) === 1 ? $version : null;
    $atLeast = static function (string $floor) use ($version): bool {
        return $version !== null && version_compare($version, $floor, '>=');
    };

    $loaded = [];
    if (is_array($extensions)) {
        foreach ($extensions as $name) {
            if (is_string($name)) {
                $loaded[strtolower($name)] = true;
            }
        }
    }

    $missing = [];
    if (! $atLeast('8.3.0')) {
        $missing[] = 'php>=8.3';
    }
    if (! isset($loaded['openssl'])) {
        $missing[] = 'ext-openssl';
    }
    if (! isset($loaded['mbstring'])) {
        $missing[] = 'ext-mbstring';
    }

    return [
        'supported' => $missing === [],
        'missing' => $missing,
        'ed25519' => $missing === [] && (isset($loaded['sodium']) || $atLeast('8.4.0')),
    ];
};
