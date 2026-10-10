<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Manifest;

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Jumbf\Superbox;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;

/**
 * Icon references (SPEC-034; C2PA 2.4 §10.2.3.2, §15.6.2, §15.10.3.2.3,
 * §15.10.3.3), as c2pa 0.91.0's verify_icons() checks them: every icon that
 * is a hashed URI — in claim_generator_info (any claim version), and in a
 * v2 actions assertion's softwareAgents, templates and an action's
 * softwareAgent — must name an assertion the claim lists, with the hash the
 * claim records for it. A url that names nothing the claim lists (an
 * external url, a data box of earlier versions) is assertion.missing. An
 * icon map without a url is a resource reference, not a hashed URI, and is
 * not checked (amendment 2). Nothing is ever fetched.
 *
 * @internal SPEC-025: not part of the public API.
 */
final readonly class IconReferenceCheck
{
    /** The hash algorithms C2PA 2.4 §13.1 allows; a local list, as HashedUriCheck keeps its own. */
    private const ALGORITHMS = ['sha256', 'sha384', 'sha512'];

    /** @return list<ValidationStatus> */
    public function check(Manifest $manifest): array
    {
        $recorded = [];
        foreach ([...$manifest->claim->createdAssertions, ...$manifest->claim->gatheredAssertions] as $entry) {
            $recorded[substr($entry->url, strrpos($entry->url, '/') + 1)] = $entry->hash->bytes;
        }
        $statuses = [];
        $digests = [];   // SPEC-067 amendment 1: each data box hashed once per algorithm
        foreach (self::icons($manifest) as $icon) {
            if (! is_array($icon) || ! is_string($icon['url'] ?? null)) {
                continue;   // a resource reference, or not an icon map: not a hashed URI (amendment 2)
            }
            $url = $icon['url'];
            // SPEC-067: an icon in a data box of earlier versions is read, and its hash checked.
            if (str_contains($url, '/c2pa.databoxes/')) {
                $status = self::checkDataBox($manifest, $url, $icon, $digests);
                if ($status !== null) {
                    $statuses[] = $status;
                }

                continue;
            }
            $label = substr($url, strrpos($url, '/') + 1);
            if (! str_starts_with($url, 'self#jumbf=') || ! array_key_exists($label, $recorded)) {
                $statuses[] = new ValidationStatus(StatusCode::AssertionMissing, $url, sprintf('could not resolve icon address: %s names no assertion this claim lists; an icon shall be a hashed URI to an embedded c2pa.icon (C2PA 2.4 §10.2.3.2), and data boxes of earlier versions are not read', $url));

                continue;
            }
            $hash = $icon['hash'] ?? null;
            if (! $hash instanceof CborBytes || ! hash_equals($recorded[$label], $hash->bytes)) {
                $statuses[] = new ValidationStatus(StatusCode::AssertionHashedUriMismatch, $url, sprintf('icon hash does not match the hash the claim records for %s (C2PA 2.4 §15.10.3.3)', $label));
            }
        }

        return $statuses;
    }

    /**
     * The data box an icon url names, or null (SPEC-067 AC3–AC5).
     *
     * Only `self#jumbf=/c2pa/<this manifest's label>/c2pa.databoxes/<label>`,
     * matched as a whole: another manifest's box, a deeper path, a store that
     * is absent or not a superbox, a child that is not one, and two boxes under
     * the label all resolve to nothing.
     *
     * @internal SPEC-025: not part of the public API.
     */
    public static function dataBox(Manifest $manifest, string $url): ?Superbox
    {
        $prefix = 'self#jumbf=/c2pa/'.$manifest->label.'/c2pa.databoxes/';
        if (! str_starts_with($url, $prefix)) {
            return null;
        }
        // A deeper path cannot match: JumbfParser refuses a label holding '/'.
        $label = substr($url, strlen($prefix));

        $stores = array_values(array_filter(
            $manifest->box->superboxes(),
            static fn (Superbox $box): bool => $box->description->label === 'c2pa.databoxes' && $box->description->uuid === JumbfParser::UUID_DATABOX_STORE,
        ));
        if (count($stores) !== 1) {
            return null;
        }
        $boxes = array_values(array_filter(
            $stores[0]->superboxes(),
            static fn (Superbox $box): bool => $box->description->label === $label,
        ));

        return count($boxes) === 1 ? $boxes[0] : null;
    }

    /**
     * An icon that names a data box: missing when it resolves to nothing, a
     * mismatch when the box's payload does not hash to the icon's hash, and
     * `algorithm.unsupported` as `HashedUriCheck` reports it for a claim entry:
     * the icon's `alg`, or the claim's; none, a non-string one, or one outside
     * sha256, sha384, sha512 (SPEC-067 amendment 1). Each box is hashed once
     * per algorithm, in $digests, however many icons name it.
     *
     * @internal SPEC-025: not part of the public API.
     *
     * @param  array<array-key, mixed>  $icon
     * @param  array<string, string>  $digests
     */
    public static function checkDataBox(Manifest $manifest, string $url, array $icon, array &$digests): ?ValidationStatus
    {
        $box = self::dataBox($manifest, $url);
        if ($box === null) {
            return new ValidationStatus(StatusCode::AssertionMissing, $url, sprintf('could not resolve icon address: %s names no data box of this manifest (C2PA 2.4 §10.2.3.2, §18.12.1)', $url));
        }
        $alg = array_key_exists('alg', $icon) ? $icon['alg'] : $manifest->claim->alg;
        if (! is_string($alg) || ! in_array($alg, self::ALGORITHMS, true)) {
            return new ValidationStatus(StatusCode::AlgorithmUnsupported, $url, sprintf('the icon names no hash algorithm this verifier supports (sha256, sha384, sha512; C2PA 2.4 §13.1, §15.4.2)'));
        }
        $hash = $icon['hash'] ?? null;
        // A hash of the wrong length cannot be equal, so it needs no check of its own.
        if (! $hash instanceof CborBytes || ! hash_equals($digests[spl_object_id($box).':'.$alg] ??= hash($alg, $box->payload(), true), $hash->bytes)) {
            return new ValidationStatus(StatusCode::AssertionHashedUriMismatch, $url, 'icon hash does not match the data box it names (C2PA 2.4 §8.4.2.3, §15.10.3.3)');
        }

        return null;
    }

    /** Whether the manifest carries any icon — the report names the check only then. */
    public static function present(Manifest $manifest): bool
    {
        return self::icons($manifest) !== [];
    }

    /**
     * The seam for one actions assertion's icons: softwareAgents, templates, and each action's softwareAgent.
     * A v1 claim's actions are not read (c2pa-rs leaves verify_actions early for v1).
     *
     * @return list<mixed>
     */
    public static function actionsIcons(mixed $data, int $version): array
    {
        if ($version < 2 || ! is_array($data)) {
            return [];
        }
        $icons = [];
        foreach (['softwareAgents', 'templates'] as $field) {
            foreach (is_array($data[$field] ?? null) ? $data[$field] : [] as $item) {
                if (is_array($item) && array_key_exists('icon', $item)) {
                    $icons[] = $item['icon'];
                }
            }
        }
        foreach (is_array($data['actions'] ?? null) ? $data['actions'] : [] as $action) {
            $agent = is_array($action) ? ($action['softwareAgent'] ?? null) : null;
            if (is_array($agent) && array_key_exists('icon', $agent)) {
                $icons[] = $agent['icon'];
            }
        }

        return $icons;
    }

    /** @return list<mixed> */
    private static function icons(Manifest $manifest): array
    {
        $icons = [];
        foreach ($manifest->claim->claimGeneratorInfo ?? [] as $info) {
            if (array_key_exists('icon', $info)) {
                $icons[] = $info['icon'];
            }
        }
        foreach ($manifest->assertions as $label => $assertion) {
            if (ActionsCheck::isActionsLabel($label)) {
                $icons = [...$icons, ...self::actionsIcons($assertion->data, $manifest->claim->version)];
            }
        }

        return $icons;
    }
}
