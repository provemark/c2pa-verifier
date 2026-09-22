<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Hash;

use Provemark\C2paVerifier\Jumbf\Superbox;
use Provemark\C2paVerifier\Jumbf\UnknownBox;
use Provemark\C2paVerifier\Manifest\HashedUri;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestException;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;

/**
 * The hashed-URI check as a list of statuses (SPEC-011; C2PA 2.4 §15.10.3):
 * every assertion the claim names is resolved, its box payload hashed with
 * the entry's algorithm — else the claim's (§15.4.2) — and compared with
 * the hash the claim carries. Then every box in the assertion store that
 * no entry resolved to is reported undeclared, unknown boxes included, and
 * a claim that declares redactions is refused outright. Every entry is
 * reported; nothing stops at the first mismatch.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class HashedUriCheck
{
    /** The algorithms C2PA 2.4 §13.1 allows, as PHP's hash() knows them, with their digest lengths. */
    private const ALGORITHMS = ['sha256' => 32, 'sha384' => 48, 'sha512' => 64];

    /** @return list<ValidationStatus> */
    public function check(Manifest $manifest): array
    {
        $statuses = [];
        $resolved = [];
        foreach ([...$manifest->claim->createdAssertions, ...$manifest->claim->gatheredAssertions] as $entry) {
            [$status, $box] = $this->entry($manifest, $entry);
            $statuses[] = $status;
            if ($box !== null) {
                $resolved[] = $box;
            }
        }

        $storeUrl = sprintf('self#jumbf=/c2pa/%s/c2pa.assertions', $manifest->label);
        foreach ($manifest->assertionStore->children as $child) {
            if ($child instanceof Superbox && ! in_array($child, $resolved, true)) {
                $statuses[] = new ValidationStatus(
                    StatusCode::AssertionUndeclared,
                    sprintf('%s/%s', $storeUrl, $child->description->label),
                    sprintf('the assertion store holds a box %s at offset %d (%d bytes) that no entry of the claim names', $child->description->label, $child->offset, $child->length),
                );
            } elseif ($child instanceof UnknownBox) {
                $statuses[] = new ValidationStatus(
                    StatusCode::AssertionUndeclared,
                    $storeUrl,
                    sprintf('the assertion store holds an unknown box (type %s, UUID %s, label %s) at offset %d (%d bytes) that no entry of the claim names', $child->type, $child->uuid ?? '-', $child->label ?? '-', $child->offset, $child->length),
                );
            }
        }

        $redacted = $manifest->claim->other['redacted_assertions'] ?? null;
        if (is_array($redacted) && $redacted !== []) {
            $statuses[] = new ValidationStatus(
                StatusCode::GeneralError,
                sprintf('self#jumbf=/c2pa/%s/%s', $manifest->label, $manifest->claim->version === 2 ? 'c2pa.claim.v2' : 'c2pa.claim'),
                sprintf(
                    'the claim declares %d redacted_assertions; this verifier refuses such a claim rather than guessing at it. '
                    .'Validating a redaction needs the claim-signature hash method (C2PA 2.4 §15.11.3.3.1) and the '
                    .'assertion.notRedacted check, neither of which this verifier implements, so a claim that says '
                    .'"redacted" is not passed on trust',
                    count($redacted),
                ),
            );
        }

        return $statuses;
    }

    /**
     * One entry of the claim against its box: the seam for an entry the
     * manifest could not have been built with (SPEC-011 AC10).
     */
    public function checkEntry(Manifest $manifest, HashedUri $entry): ValidationStatus
    {
        return $this->entry($manifest, $entry)[0];
    }

    /**
     * The entry's status and, when the url resolved, its box — so that
     * check() knows which boxes are spoken for.
     *
     * @return array{0: ValidationStatus, 1: ?Superbox}
     */
    private function entry(Manifest $manifest, HashedUri $entry): array
    {
        try {
            $box = $manifest->resolve($entry->url);
        } catch (ManifestException $e) {
            return [new ValidationStatus($e->status, $entry->url, $e->getMessage()), null];
        }
        $url = sprintf('self#jumbf=/c2pa/%s/c2pa.assertions/%s', $manifest->label, $box->description->label);

        $alg = $entry->alg ?? $manifest->claim->alg;
        if ($alg === null) {
            return [new ValidationStatus(StatusCode::AlgorithmUnsupported, $url, 'no algorithm is specified for this hashed URI: neither the entry nor the claim carries an alg (C2PA 2.4 §15.4.2)'), $box];
        }
        if (! array_key_exists($alg, self::ALGORITHMS)) {
            return [new ValidationStatus(StatusCode::AlgorithmUnsupported, $url, sprintf('the hash algorithm %s is not one of sha256, sha384, sha512 (C2PA 2.4 §13.1)', $alg)), $box];
        }

        $expected = $entry->hash->bytes;
        $length = strlen($expected);
        if ($length !== self::ALGORITHMS[$alg]) {
            return [new ValidationStatus(StatusCode::AssertionHashedUriMismatch, $url, sprintf('the claim carries a %d-byte hash for this assertion, but %s produces %d bytes', $length, $alg, self::ALGORITHMS[$alg])), $box];
        }
        $actual = hash($alg, $box->payload(), true);

        $status = hash_equals($expected, $actual)
            ? new ValidationStatus(StatusCode::AssertionHashedUriMatch, $url, sprintf('hashed uri matched: %s (%s over %d bytes)', $entry->url, $alg, $box->length - 8))
            : new ValidationStatus(StatusCode::AssertionHashedUriMismatch, $url, sprintf('hash does not match assertion data: %s (%s over %d bytes gives %s, the claim carries %s)', $entry->url, $alg, $box->length - 8, bin2hex($actual), bin2hex($expected)));

        return [$status, $box];
    }
}
