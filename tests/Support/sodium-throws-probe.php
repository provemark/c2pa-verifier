<?php

declare(strict_types=1);

/*
 * SPEC-009 AC12: what SignatureVerifier answers when sodium_crypto_sign_verify_detached() throws, as
 * sodium_compat 1.21.1 (the polyfill WordPress loads where ext-sodium is absent) throws SodiumException
 * "S < L - Invalid signature" for a non-canonical S. Run through PHP_BINARY by SignatureVerifierTest: the
 * replacement function below would otherwise stand in for the extension in every later test of the process.
 *
 * Prints JSON on stdout: {"called": int, "result": bool} or {"called": int, "thrown": class, "message": string}.
 */

namespace Provemark\C2paVerifier\Cose {
    // an unqualified call in this namespace finds this function before the global one
    function sodium_crypto_sign_verify_detached(string $signature, string $message, string $publicKey): bool
    {
        $called = $GLOBALS['spec009Called'] ?? 0;
        $GLOBALS['spec009Called'] = (is_int($called) ? $called : 0) + 1;

        throw new \SodiumException('S < L - Invalid signature');
    }
}

namespace {
    use Provemark\C2paVerifier\Cose\CoseSign1;
    use Provemark\C2paVerifier\Cose\SignatureVerifier;

    require dirname(__DIR__, 2).'/vendor/autoload.php';

    // where ext-sodium is absent, what sodium_compat declares in its place
    if (! class_exists('SodiumException')) {
        class SodiumException extends Exception {}
    }
    if (! defined('SODIUM_CRYPTO_SIGN_BYTES')) {
        define('SODIUM_CRYPTO_SIGN_BYTES', 64);
    }
    if (! function_exists('sodium_crypto_sign_verify_detached')) {
        function sodium_crypto_sign_verify_detached(string $signature, string $message, string $publicKey): bool
        {
            throw new SodiumException('S < L - Invalid signature');
        }
    }

    $bstr = static function (string $bytes): string {
        $n = strlen($bytes);

        return match (true) {
            $n < 24 => chr(0x40 + $n),
            $n < 256 => "\x58".chr($n),
            default => "\x59".pack('n', $n),
        }.$bytes;
    };
    /** @var array{claim_hex: string, protected_hex: string} $v */
    $v = json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/signatures/eddsa-ed25519.json'), true, 512, JSON_THROW_ON_ERROR);
    // AC8's vector, its signature replaced by 64 0xff bytes: S is not below the group order
    $cose = CoseSign1::fromBytes("\xd2\x84".$bstr((string) hex2bin($v['protected_hex']))."\xa0\xf6".$bstr(str_repeat("\xff", 64)));

    try {
        $result = (new SignatureVerifier(useSodium: true, useOpensslEd25519: false))->verify($cose, (string) hex2bin($v['claim_hex']));
        echo json_encode(['called' => $GLOBALS['spec009Called'] ?? 0, 'result' => $result]);
    } catch (Throwable $e) {
        echo json_encode(['called' => $GLOBALS['spec009Called'] ?? 0, 'thrown' => $e::class, 'message' => $e->getMessage()]);
    }
}
