<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Manifest;

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;

/**
 * The cloud-data assertion's structure (SPEC-063; C2PA 2.4 §15.10.3.2.1, §18.11): a `label` that is
 * non-empty text and names no assertion cloud data may stand for, a `size` of at least 1, and a hashed
 * `location` (`url`, `alg`, `hash`). The remote assertion is never retrieved: the `url` is data here,
 * never a destination.
 *
 * @internal SPEC-025: not part of the public API.
 */
final readonly class CloudDataCheck
{
    public const LABEL = 'c2pa.cloud-data';

    /** The hard bindings, as c2pa-rs 0.91.1's is_hard_binding_label(): assertion.cloud-data.hardBinding. */
    public const HARD_BINDING_LABELS = [
        'c2pa.hash.data', 'c2pa.hash.boxes', 'c2pa.hash.collection.data', 'c2pa.hash.multi-asset',
        'c2pa.hash.bmff.v2', 'c2pa.hash.bmff.v3',
    ];

    /** The rest of §15.10.3.2.1's list, as c2pa-rs 0.91.1's is_forbidden(): assertion.cloud-data.malformed. */
    public const FORBIDDEN_LABELS = [
        'c2pa.action', 'c2pa.actions', 'c2pa.actions.v2', 'c2pa.cloud-data',
        'c2pa.ingredient', 'c2pa.ingredient.v2', 'c2pa.ingredient.v3',
    ];

    /**
     * The statuses of the cloud-data assertions the claim lists; those in $unreadable (hashed URIs that did
     * not match) are left unread.
     *
     * @param  list<string>  $unreadable
     * @return list<ValidationStatus>
     */
    public function check(Manifest $manifest, array $unreadable = []): array
    {
        $statuses = [];
        foreach (self::assertions($manifest) as $label => $data) {
            $url = sprintf('self#jumbf=/c2pa/%s/c2pa.assertions/%s', $manifest->label, $label);
            if (in_array($url, $unreadable, true)) {
                continue;
            }
            foreach (self::faults($data, $manifest->isUpdateManifest) as [$code, $fault]) {
                $statuses[] = new ValidationStatus($code, $url, sprintf('cloud data: %s (C2PA 2.4 §15.10.3.2.1); the remote assertion is never retrieved', $fault));
            }
        }

        return $statuses;
    }

    /** Whether the claim lists any cloud-data assertion: the report names the check only then. */
    public static function present(Manifest $manifest): bool
    {
        return self::assertions($manifest) !== [];
    }

    /**
     * The faults of one decoded assertion, in this order: the structure, then the label.
     *
     * @return list<array{0: StatusCode, 1: string}>
     */
    public static function faults(mixed $data, bool $updateManifest): array
    {
        $malformed = static fn (string $fault): array => [[StatusCode::AssertionCloudDataMalformed, $fault]];
        if (! is_array($data) || array_is_list($data)) {
            return $malformed('the assertion is not a map');
        }
        $label = $data['label'] ?? null;
        if (! is_string($label) || trim($label) === '') {
            return $malformed($label === null ? 'label is missing' : 'label is not non-empty text');
        }
        $size = $data['size'] ?? null;
        if (! is_int($size) || $size < 1) {
            return $malformed($size === null ? 'size is missing' : (is_int($size) ? sprintf('size is %d, not at least 1', $size) : 'size is not an integer'));
        }
        $location = $data['location'] ?? null;
        if (! is_array($location) || array_is_list($location)) {
            return $malformed($location === null ? 'location is missing' : 'location is not a map');
        }
        $url = $location['url'] ?? null;
        if (! is_string($url) || trim($url) === '') {
            return $malformed($url === null ? 'location.url is missing' : 'location.url is not non-empty text');
        }
        if (! is_string($location['alg'] ?? null) || trim($location['alg']) === '') {
            return $malformed('location.alg is missing or not text');
        }
        // a byte string, or the text c2patool writes from a manifest definition, which c2pa-rs reads (amendment 1)
        $hash = $location['hash'] ?? null;
        if (! $hash instanceof CborBytes && ! (is_string($hash) && $hash !== '')) {
            return $malformed($hash === null ? 'location.hash is missing' : 'location.hash is neither a byte string nor text');
        }

        $faults = [];
        if (in_array($label, self::HARD_BINDING_LABELS, true)) {
            $faults[] = [StatusCode::AssertionCloudDataHardBinding, sprintf('label %s is a hard binding, which cloud data shall not stand for', $label)];
        }
        if ($updateManifest && in_array($label, ['c2pa.actions', 'c2pa.actions.v2'], true)) {
            $faults[] = [StatusCode::AssertionCloudDataActions, sprintf('label %s in an update manifest', $label)];
        }
        if (in_array($label, self::FORBIDDEN_LABELS, true)) {
            $faults[] = [StatusCode::AssertionCloudDataMalformed, sprintf('label %s names an assertion cloud data shall not stand for', $label)];
        }

        return $faults;
    }

    /**
     * The cloud-data assertions the claim lists, created and gathered (v1: assertions), any instance.
     *
     * @return array<string, mixed> assertion label => decoded data
     */
    private static function assertions(Manifest $manifest): array
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
