<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Manifest;

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;

/**
 * The external-reference assertion's structure (SPEC-032 rule B; C2PA 2.4
 * §15.10.3.2.2): a `location` with a non-empty `url`, `alg` and `hash`
 * together or not at all, and no `label` naming an assertion an external
 * reference may not stand for. The referenced data is never retrieved:
 * the `url` is data here, never a destination.
 *
 * @internal SPEC-025: not part of the public API.
 */
final readonly class ExternalReferenceCheck
{
    public const LABEL = 'c2pa.external-reference';

    /**
     * §15.10.3.2.2's thirteen, plus `c2pa.action`, which `c2pa` 0.91.0 also
     * refuses (SPEC-032 open question 1): a label that does not exist, so
     * refusing it costs no real file.
     */
    public const FORBIDDEN_LABELS = [
        'c2pa.action', 'c2pa.actions', 'c2pa.actions.v2', 'c2pa.cloud-data', 'c2pa.external-reference',
        'c2pa.hash.bmff.v2', 'c2pa.hash.bmff.v3', 'c2pa.hash.boxes', 'c2pa.hash.collection.data', 'c2pa.hash.data',
        'c2pa.hash.multi-asset', 'c2pa.ingredient', 'c2pa.ingredient.v2', 'c2pa.ingredient.v3',
    ];

    /**
     * One status per malformed external-reference assertion the claim lists;
     * those in $unreadable (hashed URIs that did not match) are left unread.
     *
     * @param  list<string>  $unreadable
     * @return list<ValidationStatus>
     */
    public function check(Manifest $manifest, array $unreadable = []): array
    {
        $statuses = [];
        foreach (self::references($manifest) as $label => $data) {
            $url = sprintf('self#jumbf=/c2pa/%s/c2pa.assertions/%s', $manifest->label, $label);
            if (in_array($url, $unreadable, true)) {
                continue;
            }
            $fault = self::fault($data);
            if ($fault !== null) {
                $statuses[] = new ValidationStatus(StatusCode::AssertionExternalReferenceMalformed, $url, sprintf('external reference malformed: %s (C2PA 2.4 §15.10.3.2.2); the referenced data is never retrieved', $fault));
            }
        }

        return $statuses;
    }

    /** Whether the claim lists any external-reference assertion — the report names the check only then (SPEC-032 open question 4). */
    public static function present(Manifest $manifest): bool
    {
        return self::references($manifest) !== [];
    }

    /**
     * The fault of one decoded assertion, or null.
     */
    public static function fault(mixed $data): ?string
    {
        if (! is_array($data) || array_is_list($data)) {
            return 'the assertion is not a map';
        }
        $location = $data['location'] ?? null;
        if (! is_array($location) || (array_is_list($location) && $location !== [])) {
            return $location === null ? 'location is missing' : 'location is not a map';
        }
        $url = $location['url'] ?? null;
        if (! is_string($url)) {
            return $url === null ? 'location.url is missing' : 'location.url is not text';
        }
        if (trim($url) === '') {
            return 'location.url is empty';
        }
        $hasAlg = array_key_exists('alg', $location);
        $hasHash = array_key_exists('hash', $location);
        if ($hasAlg !== $hasHash) {
            return $hasAlg ? 'location carries alg without hash' : 'location carries hash without alg';
        }
        if ($hasAlg && (! is_string($location['alg']) || trim($location['alg']) === '' || in_array($location['hash'], ['', null], true))) {
            return 'location carries an empty alg or hash';
        }
        if (array_key_exists('label', $data)) {
            if (! is_string($data['label'])) {
                return 'label is not text';
            }
            if (in_array($data['label'], self::FORBIDDEN_LABELS, true)) {
                return sprintf('label %s names an assertion an external reference shall not reference', $data['label']);
            }
        }

        return null;
    }

    /**
     * The external-reference assertions the claim lists, created and gathered, any instance.
     *
     * @return array<string, mixed> assertion label => decoded data
     */
    private static function references(Manifest $manifest): array
    {
        $found = [];
        foreach ([...$manifest->claim->createdAssertions, ...$manifest->claim->gatheredAssertions] as $entry) {
            $label = substr($entry->url, strrpos($entry->url, '/') + 1);
            if ((preg_replace('/__\d+\z/', '', $label) ?? $label) === self::LABEL && isset($manifest->assertions[$label])) {
                $found[$label] = $manifest->assertions[$label]->data;
            }
        }

        return $found;
    }
}
