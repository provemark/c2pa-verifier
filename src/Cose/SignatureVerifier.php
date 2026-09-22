<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cose;

use Provemark\C2paVerifier\Report\StatusCode;

/**
 * Does the claim signature verify under the leaf's public key? (SPEC-009;
 * C2PA 2.4 §13.2.1, §13.2.6.) Three outcomes: true; false — a mismatch, a
 * signature of the wrong shape, an OpenSSL refusal; CoseException — it
 * cannot be verified: an unsupported alg, a key that does not fit the
 * algorithm, a missing extension. The key is checked against the
 * algorithm before any arithmetic: a secp256k1 or 1024-bit key verifies
 * mathematically and must be refused first. Nothing here signs.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class SignatureVerifier
{
    public const ES256 = -7;

    public const ES384 = -35;

    public const ES512 = -36;

    public const PS256 = -37;

    public const PS384 = -38;

    public const PS512 = -39;

    public const EDDSA = -8;

    private const NAMES = [self::ES256 => 'ES256', self::ES384 => 'ES384', self::ES512 => 'ES512', self::PS256 => 'PS256', self::PS384 => 'PS384', self::PS512 => 'PS512', self::EDDSA => 'EdDSA'];

    private const HASHES = [self::ES256 => 'sha256', self::ES384 => 'sha384', self::ES512 => 'sha512', self::PS256 => 'sha256', self::PS384 => 'sha384', self::PS512 => 'sha512'];

    private const OPENSSL_ALGOS = ['sha256' => OPENSSL_ALGO_SHA256, 'sha384' => OPENSSL_ALGO_SHA384, 'sha512' => OPENSSL_ALGO_SHA512];

    /** The curves §13.2.1 allows for any ECDSA algorithm, with their coordinate size. */
    private const CURVES = ['prime256v1' => 32, 'secp384r1' => 48, 'secp521r1' => 66];

    private const RSA_MIN_BITS = 2048;

    private const RSA_MAX_BITS = 16384;

    public function __construct(
        public bool $useSodium = true,
        public bool $useOpensslEd25519 = true,
    ) {}

    /**
     * @throws CoseException
     */
    public function verify(CoseSign1 $cose, string $claimBytes): bool
    {
        $alg = $cose->alg;
        if (! isset(self::NAMES[$alg])) {
            throw new CoseException(sprintf('alg %d is not supported (C2PA 2.4 §13.2.1 allows ES256/384/512, PS256/384/512, EdDSA)', $alg), StatusCode::AlgorithmUnsupported);
        }
        $key = PublicKey::fromCertificateDer($cose->chain[0]->bytes);
        $this->requireFit($alg, $key);
        $message = $cose->sigStructure($claimBytes);

        return match ($alg) {
            self::ES256, self::ES384, self::ES512 => $this->ecdsa($message, $cose->signature, $key, self::HASHES[$alg]),
            self::PS256, self::PS384, self::PS512 => $this->rsaPss($message, $cose->signature, $key, self::HASHES[$alg]),
            default => $this->ed25519($message, $cose->signature, $key),
        };
    }

    /** C2PA 2.4 §13.2.1: refuse before verifying when the key is not right for the algorithm. */
    private function requireFit(int $alg, PublicKey $key): void
    {
        $name = self::NAMES[$alg];
        $fits = match ($alg) {
            self::ES256, self::ES384, self::ES512 => $key->kind === PublicKey::KIND_EC && isset(self::CURVES[(string) $key->curve]),
            self::PS256, self::PS384, self::PS512 => in_array($key->kind, [PublicKey::KIND_RSA, PublicKey::KIND_RSA_PSS], true)
                && $key->bits >= self::RSA_MIN_BITS && $key->bits <= self::RSA_MAX_BITS,
            default => $key->kind === PublicKey::KIND_ED25519,
        };
        if ($fits) {
            return;
        }
        $requires = match ($alg) {
            self::ES256, self::ES384, self::ES512 => 'an EC key on P-256, P-384 or P-521',
            self::PS256, self::PS384, self::PS512 => sprintf('an RSA key of %d to %d bits', self::RSA_MIN_BITS, self::RSA_MAX_BITS),
            default => 'an Ed25519 key',
        };
        throw new CoseException(sprintf('key does not fit %s (alg %d): %s; C2PA 2.4 §13.2.1 requires %s', $name, $alg, $key->describe(), $requires), StatusCode::SigningCredentialInvalid);
    }

    private function ecdsa(string $message, string $signature, PublicKey $key, string $hash): bool
    {
        $der = EcdsaSignature::toDer($signature, self::CURVES[(string) $key->curve]);
        if ($der === null) {
            return false;
        }

        return $this->opensslVerify($message, $der, $key, self::OPENSSL_ALGOS[$hash]);
    }

    private function rsaPss(string $message, string $signature, PublicKey $key, string $hash): bool
    {
        if ($key->kind === PublicKey::KIND_RSA_PSS) {
            // OpenSSL performs PSS itself for this key type, with the key's own
            // parameters, and answers −1 when they do not match the hash asked.
            return $this->opensslVerify($message, $signature, $key, self::OPENSSL_ALGOS[$hash]);
        }

        // An ordinary RSA key: openssl_verify would do PKCS#1 v1.5. Never that.
        return RsaPss::verify($message, $signature, $key->key, $hash, $key->bits);
    }

    private function ed25519(string $message, string $signature, PublicKey $key): bool
    {
        if ($this->useSodium && function_exists('sodium_crypto_sign_verify_detached')) {
            if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
                return false;
            }

            return sodium_crypto_sign_verify_detached($signature, $message, $key->rawEd25519());
        }
        if ($this->useOpensslEd25519) {
            try {
                $result = OpenSsl::quiet(static fn (): int|false => openssl_verify($message, $signature, $key->key, 0));
            } catch (\Throwable) {
                $result = -1;
            }
            if ($result === 1) {
                return true;
            }
            if ($result === 0) {
                return false;
            }
        }
        throw new CoseException('EdDSA cannot be verified: neither ext-sodium nor OpenSSL Ed25519 support is available on this PHP', StatusCode::AlgorithmUnsupported);
    }

    /** 1 is the only true; 0 a mismatch; −1 an OpenSSL refusal — false, never true. */
    private function opensslVerify(string $message, string $signature, PublicKey $key, int $algo): bool
    {
        $result = OpenSsl::quiet(static fn (): int|false => openssl_verify($message, $signature, $key->key, $algo));

        return $result === 1;
    }
}
