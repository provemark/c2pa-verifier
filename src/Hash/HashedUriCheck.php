<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Hash;

use Provemark\C2paVerifier\Jumbf\ContentBox;
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
 * no entry resolved to is reported undeclared, unknown boxes included.
 * Then the redactions (SPEC-035): an entry the store declares redacted
 * whose box is gone is skipped, a redacted box still holding content is
 * `assertion.notRedacted`, and the claim's own list is read for
 * self-redaction, redacted actions and a redacted hard binding (SPEC-036).
 * Every entry is reported; nothing
 * stops at the first mismatch.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class HashedUriCheck
{
    /** The algorithms C2PA 2.4 §13.1 allows, as PHP's hash() knows them, with their digest lengths. */
    private const ALGORITHMS = ['sha256' => 32, 'sha384' => 48, 'sha512' => 64];

    /** The hard-binding labels c2pa-rs will not see redacted (its HASH_LABELS). */
    private const HARD_BINDINGS = ['c2pa.hash.data', 'c2pa.hash.boxes', 'c2pa.hash.bmff', 'c2pa.hash.collection.data'];

    /** @return list<ValidationStatus> */
    public function check(Manifest $manifest): array
    {
        $statuses = [];
        $resolved = [];
        foreach ([...$manifest->claim->createdAssertions, ...$manifest->claim->gatheredAssertions] as $entry) {
            if ($manifest->isRedacted($entry->url) && ! $this->resolves($manifest, $entry->url)) {
                continue;   // redacted and removed: nothing left to hash (SPEC-035; §15.11.3.3.1)
            }
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

        return [...$statuses, ...$this->notRedacted($manifest), ...$this->redactions($manifest)];
    }

    /**
     * A box the store declares redacted that is still here must hold nothing but zero bytes
     * (C2PA 2.4 §15.11.3.3.1; c2pa-rs `verify_store`): otherwise its content would stand unverified.
     *
     * @return list<ValidationStatus>
     */
    private function notRedacted(Manifest $manifest): array
    {
        $statuses = [];
        foreach ($manifest->redacted as $uri) {
            if (! $this->resolves($manifest, $uri)) {
                continue;   // removed: a valid form of redaction
            }
            $content = implode('', array_map(static fn (ContentBox $box): string => $box->data, $manifest->resolve($uri)->contentBoxes()));
            if (trim($content, "\0") !== '') {
                $statuses[] = new ValidationStatus(StatusCode::AssertionNotRedacted, $uri, sprintf('redacted assertion data must be zeros or empty: %s still holds %d bytes of content', $uri, strlen($content)));
            }
        }

        return $statuses;
    }

    /**
     * The claim's own `redacted_assertions`, entry by entry, as c2pa-rs reads them: the entry verbatim
     * as the url, the claim's own label inside it a self-redaction, `c2pa.actions` inside it a
     * redacted actions assertion (§15.10.3.1), a hard-binding label inside it a redacted hard binding
     * (§6.8; SPEC-036, any claim, as c2pa-rs). An entry that is not a string is refused.
     *
     * @return list<ValidationStatus>
     */
    private function redactions(Manifest $manifest): array
    {
        $entries = $manifest->claim->other['redacted_assertions'] ?? [];
        $claimUrl = sprintf('self#jumbf=/c2pa/%s/%s', $manifest->label, $manifest->claim->version === 2 ? 'c2pa.claim.v2' : 'c2pa.claim');
        if (! is_array($entries) || ! array_is_list($entries)) {
            return [new ValidationStatus(StatusCode::GeneralError, $claimUrl, 'the claim\'s redacted_assertions is not a list; refused rather than read')];
        }
        $statuses = [];
        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                $statuses[] = new ValidationStatus(StatusCode::GeneralError, $claimUrl, 'an entry of the claim\'s redacted_assertions is not a URI; refused rather than read');

                continue;
            }
            if (str_contains($entry, $manifest->label)) {
                $statuses[] = new ValidationStatus(StatusCode::AssertionSelfRedacted, $entry, 'claim contains self redaction');
            }
            if (str_contains($entry, 'c2pa.actions')) {
                $statuses[] = new ValidationStatus(StatusCode::AssertionActionRedacted, $entry, 'redaction of action assertions disallowed');
            }
            foreach (self::HARD_BINDINGS as $label) {
                if (str_contains($entry, $label)) {
                    $statuses[] = new ValidationStatus(StatusCode::AssertionHardBindingRedacted, $entry, sprintf('redaction of disallowed hash assertion %s (C2PA 2.4 §6.8)', $label));

                    break;
                }
            }
        }

        return $statuses;
    }

    private function resolves(Manifest $manifest, string $uri): bool
    {
        try {
            $manifest->resolve($uri);
        } catch (ManifestException) {
            return false;
        }

        return true;
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
