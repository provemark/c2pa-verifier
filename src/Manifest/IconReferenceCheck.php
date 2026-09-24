<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Manifest;

use Provemark\C2paVerifier\Cbor\CborBytes;
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
    /** @return list<ValidationStatus> */
    public function check(Manifest $manifest): array
    {
        $recorded = [];
        foreach ([...$manifest->claim->createdAssertions, ...$manifest->claim->gatheredAssertions] as $entry) {
            $recorded[substr($entry->url, strrpos($entry->url, '/') + 1)] = $entry->hash->bytes;
        }
        $statuses = [];
        foreach (self::icons($manifest) as $icon) {
            if (! is_array($icon) || ! is_string($icon['url'] ?? null)) {
                continue;   // a resource reference, or not an icon map: not a hashed URI (amendment 2)
            }
            $url = $icon['url'];
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
