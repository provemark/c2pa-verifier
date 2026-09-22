<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Manifest;

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;

/**
 * The rules an update manifest lives under (SPEC-022; C2PA 2.4 §11.2.3),
 * and the one rule §15.11 puts on a standard manifest's parents.
 *
 * An update manifest adds assertions without touching the content: it
 * therefore carries no hard binding and no thumbnail, its actions may
 * only be `c2pa.edited.metadata`, `c2pa.opened`, `c2pa.published` or
 * `c2pa.redacted`, and it names exactly one ingredient, `parentOf`, the
 * manifest it updates. A standard manifest may have at most one
 * `parentOf` ingredient — an asset is derived from one thing.
 *
 * Where the asset's own bytes are bound is a question of the same shape:
 * `bindingManifest()` answers it by walking the `parentOf` chain (§15.12).
 */
final readonly class UpdateManifestCheck
{
    /** @var list<string> */
    public const array ALLOWED_ACTIONS = ['c2pa.edited.metadata', 'c2pa.opened', 'c2pa.published', 'c2pa.redacted'];

    /**
     * Every manifest in the store, each rule of §11.2.3 with c2pa-rs's code. A status for a manifest
     * that is not the active one is scoped to the ingredient assertion that named it (SPEC-021).
     *
     * @param  array<string, list<IngredientAssertion>>  $ingredients  per manifest label, from the graph
     * @param  array<string, string>  $scopes  manifest label => the assertion url that named it
     * @return list<ValidationStatus>
     */
    public function check(ManifestStore $store, array $ingredients, array $scopes = []): array
    {
        $statuses = [];
        foreach ($store->manifests as $label => $manifest) {
            $list = $ingredients[$label] ?? [];
            $parents = 0;
            foreach ($list as $ingredient) {
                $parents += $ingredient->relationship === Relationship::ParentOf ? 1 : 0;
            }
            $actions = [];
            $labels = [];
            foreach ($manifest->assertions as $assertionLabel => $assertion) {
                $labels[] = $assertionLabel;
                if (! ActionsCheck::isActionsLabel($assertionLabel) || ! is_array($assertion->data)) {
                    continue;
                }
                foreach ((array) ($assertion->data['actions'] ?? []) as $action) {
                    if (is_array($action) && isset($action['action']) && is_string($action['action'])) {
                        $actions[] = $action['action'];
                    }
                }
            }
            $url = sprintf('self#jumbf=/c2pa/%s/%s', $label, $manifest->claim->version === 2 ? 'c2pa.claim.v2' : 'c2pa.claim');
            foreach (self::rules($manifest->isUpdateManifest, $labels, $actions, count($list), $parents) as $status) {
                $statuses[] = new ValidationStatus($status->code, $url, $status->explanation, $label === $store->active->label ? null : ($scopes[$label] ?? null));
            }
        }

        return $statuses;
    }

    /**
     * The rules themselves, on what they need and nothing more — the seam a criterion with no fixture
     * is tested through (SPEC-022 AC4d). The returned statuses carry the explanation; `check()` puts
     * the url and the scope on them.
     *
     * @param  list<string>  $assertionLabels  every assertion label of the manifest
     * @param  list<string>  $actions  every action of every actions assertion, in order
     * @param  int  $ingredients  how many ingredient assertions the manifest has
     * @param  int  $parents  how many of them are parentOf
     * @return list<ValidationStatus>
     */
    public static function rules(bool $isUpdateManifest, array $assertionLabels, array $actions, int $ingredients, int $parents): array
    {
        $statuses = [];
        $say = static function (StatusCode $code, string $explanation) use (&$statuses): void {
            $statuses[] = new ValidationStatus($code, '', $explanation);
        };
        if (! $isUpdateManifest) {
            // §15.11: an asset is derived from one thing
            if ($parents > 1) {
                $say(StatusCode::ManifestMultipleParents, sprintf('the manifest names %d ingredients with the relationship parentOf; a manifest has at most one parent (C2PA 2.4 §15.11)', $parents));
            }

            return $statuses;
        }
        foreach ($assertionLabels as $label) {
            if (str_starts_with($label, 'c2pa.hash.')) {
                $say(StatusCode::ManifestUpdateInvalid, sprintf('an update manifest carries the hard binding %s; its content did not change, so the binding of the manifest it updates still holds (C2PA 2.4 §11.2.3)', $label));
            }
            if (str_starts_with($label, 'c2pa.thumbnail.claim')) {
                $say(StatusCode::ManifestUpdateInvalid, sprintf('an update manifest carries the thumbnail %s; a thumbnail implies the content changed (C2PA 2.4 §11.2.3)', $label));
            }
        }
        foreach ($actions as $action) {
            if (! in_array($action, self::ALLOWED_ACTIONS, true)) {
                $say(StatusCode::ManifestUpdateInvalid, sprintf('an update manifest records the action %s; only %s may appear in one (C2PA 2.4 §11.2.3)', $action, implode(', ', self::ALLOWED_ACTIONS)));
            }
        }
        if ($parents === 0) {
            $say(StatusCode::ManifestUpdateWrongParents, 'an update manifest must name exactly one ingredient with the relationship parentOf: the manifest it updates (C2PA 2.4 §11.2.3)');
        } elseif ($ingredients > 1) {
            $say(StatusCode::ManifestUpdateInvalid, sprintf('an update manifest names %d ingredients; it may name exactly one, its parent (C2PA 2.4 §11.2.3)', $ingredients));
        }

        return $statuses;
    }

    /**
     * The manifest whose hard binding covers the asset (C2PA 2.4 §15.12): the active manifest when it
     * is a standard manifest with a `c2pa.hash.data`, otherwise the first such manifest up the chain
     * of `parentOf` references. Null when the chain reaches none — the caller then says
     * `claim.hardBindings.missing`.
     *
     * @param  array<string, list<IngredientAssertion>>  $ingredients  per manifest label, from the graph
     */
    public static function bindingManifest(ManifestStore $store, array $ingredients): ?Manifest
    {
        $label = $store->active->label;
        $seen = [];
        while (! array_key_exists($label, $seen)) {
            $seen[$label] = true;
            $manifest = $store->manifests[$label] ?? null;
            if ($manifest === null) {
                return null;
            }
            // 'c2pa.hash.data' spelled out: the Manifest layer may not depend on Hash (Deptrac)
            if (! $manifest->isUpdateManifest && array_key_exists('c2pa.hash.data', $manifest->assertions)) {
                return $manifest;
            }
            $next = null;
            foreach ($ingredients[$label] ?? [] as $ingredient) {
                if ($ingredient->relationship === Relationship::ParentOf && $ingredient->manifestLabel() !== null) {
                    $next = $ingredient->manifestLabel();
                }
            }
            if ($next === null) {
                return null;
            }
            $label = $next;
        }

        return null;
    }
}
