<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Manifest;

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;

/**
 * The actions assertion (SPEC-018, C2PA 2.4 §18.x): a 2.x manifest opens
 * with `c2pa.created` or `c2pa.opened`, or it is not valid. Three rules
 * and no more — for a claim v2 the first actions assertion (created list
 * first, then gathered) must exist with a non-empty `actions` list whose
 * first action opens; every actions assertion of a v2 claim must be
 * well-formed; a v1 claim carries at most one — each fault
 * `assertion.action.malformed` on the manifest's url (the opening) or the
 * assertion's (its shape), as c2pa-rs's `verify_actions`. The content
 * family (ingredient parameters, icons, templates) is not read here.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class ActionsCheck
{
    public const LABEL_V2 = 'c2pa.actions.v2';

    public const LABEL_V1 = 'c2pa.actions';

    public const OPENING_ACTIONS = ['c2pa.created', 'c2pa.opened'];

    public const DEFAULT_MAX_ACTIONS = 10000;

    public function __construct(private int $maxActions = self::DEFAULT_MAX_ACTIONS) {}

    /**
     * The manifest's actions assertions in the order the claim lists them —
     * created first, then gathered — judged; those in $unreadable (hashed
     * URIs that did not match) are left unread.
     *
     * @param  list<string>  $unreadable  assertion urls whose hashed URI failed
     * @param  array<string, list<string>>  $storeLabels  every manifest in the store => the assertion labels its claim lists (SPEC-037, claimLabels())
     * @return list<ValidationStatus>
     */
    public function check(Manifest $manifest, array $unreadable = [], array $storeLabels = []): array
    {
        $manifestUrl = sprintf('self#jumbf=/c2pa/%s', $manifest->label);
        $actions = [];
        foreach ([...$manifest->claim->createdAssertions, ...$manifest->claim->gatheredAssertions] as $entry) {
            $label = substr($entry->url, strrpos($entry->url, '/') + 1);
            if (! self::isActionsLabel($label)) {
                continue;
            }
            $url = $manifestUrl.'/c2pa.assertions/'.$label;
            if (in_array($url, $unreadable, true) || ! isset($manifest->assertions[$label])) {
                continue;   // not vouched for by the claim, or not in the store: the hashed-URI check has refused the file
            }
            $actions[] = ['url' => $url, 'data' => $manifest->assertions[$label]->data];
        }

        // the claim's own assertions, for SPEC-033's references: every label it lists, and each ingredient's relationship
        $labels = [];
        $ingredients = [];
        foreach ([...$manifest->claim->createdAssertions, ...$manifest->claim->gatheredAssertions] as $entry) {
            $label = substr($entry->url, strrpos($entry->url, '/') + 1);
            $labels[] = $label;
            $data = $manifest->assertions[$label]->data ?? null;
            if (self::base($label) === 'c2pa.ingredient' && is_array($data) && is_string($data['relationship'] ?? null)) {
                $ingredients[$label] = $data['relationship'];
            }
        }

        // c2patool's url for the manifest-level faults of this rule is the bare manifest label (measured, SPEC-018 amendment 2)
        return $this->checkAssertions($manifest->label, $manifest->claim->version, $actions, $manifest->isUpdateManifest, $labels, $ingredients, $storeLabels);
    }

    /**
     * The seam: the ordered actions assertions as check() collects them. The
     * manifest-level faults carry the bare manifest label as their url — what
     * c2patool prints for this rule (its hard-binding faults carry the JUMBF
     * form; measured in step 49b).
     *
     * @param  list<array{url: string, data: mixed}>  $actions
     * @param  list<string>  $labels  the labels of every assertion the claim lists (SPEC-033: relatedAssertions)
     * @param  array<string, string>  $ingredients  ingredient assertion label => its relationship (SPEC-033: references)
     * @param  array<string, list<string>>  $storeLabels  every manifest in the store => the assertion labels its claim lists (SPEC-037)
     * @return list<ValidationStatus>
     */
    public function checkAssertions(string $manifestLabel, int $version, array $actions, bool $isUpdateManifest = false, array $labels = [], array $ingredients = [], array $storeLabels = []): array
    {
        $malformed = static fn (string $url, string $why): ValidationStatus => new ValidationStatus(StatusCode::AssertionActionMalformed, $url, $why);
        if ($version < 2) {
            // rule 3: a v1 claim carries at most one actions assertion; nothing else without strict_v1_validation (c2pa-rs)
            return count($actions) > 1
                ? [$malformed($manifestLabel, sprintf('a v1 claim carries at most one actions assertion, this one %d (%s)', count($actions), implode(', ', array_map(static fn (array $a): string => $a['url'], $actions))))]
                : [];
        }

        // rule 2: every actions assertion of a v2 claim is well-formed
        $statuses = [];
        foreach ($actions as $assertion) {
            foreach ($this->checkData($assertion['data'], $version) as $fault) {
                $statuses[] = $malformed($assertion['url'], 'actions assertion malformed: '.$fault);
            }
        }
        // SPEC-032 rule A: every c2pa.created carries a digitalSourceType. c2patool's rule (c2pa-rs 2.b.v);
        // C2PA 2.4 states it as the claim generator's duty (§18.15.2), and §15's validation steps are silent.
        foreach ($actions as $assertion) {
            if ($this->checkData($assertion['data'], $version) !== [] || ! is_array($assertion['data']) || ! is_array($assertion['data']['actions'] ?? null)) {
                continue;   // its shape was reported above
            }
            foreach ($assertion['data']['actions'] as $i => $action) {
                if (is_array($action) && ($action['action'] ?? null) === 'c2pa.created' && ! is_string($action['digitalSourceType'] ?? null)) {
                    $statuses[] = $malformed($assertion['url'], sprintf('c2pa.created action must have a digitalSourceType: actions[%d] has none (c2patool\'s rule; C2PA 2.4 §18.15.2 states it for the claim generator)', $i));
                }
            }
        }
        // rule 1: the first one opens with c2pa.created or c2pa.opened — an update manifest is exempt
        // (C2PA 2.4 §11.2.3 gives it four actions of its own, none of them an opening; measured on
        // update_manifest.jpg's variant, where c2patool reports only the update rule — SPEC-022)
        if ($isUpdateManifest) {
            return [...$statuses, ...$this->contentRules($manifestLabel, $actions, $labels, $ingredients, $storeLabels)];
        }
        $before = count($statuses);
        if ($actions === []) {
            $statuses[] = $malformed($manifestLabel, 'first action must be created or opened: the manifest has no actions assertion (C2PA 2.4 §18, a 2.x manifest opens with c2pa.created or c2pa.opened)');
        } else {
            $first = self::firstAction($actions[0]['data']);
            if ($first === null) {
                // its shape was reported above; the opening cannot be judged
                if ($statuses === []) {
                    $statuses[] = $malformed($manifestLabel, 'first action must be created or opened: the first actions assertion has none');
                }
            } elseif (! in_array($first, self::OPENING_ACTIONS, true)) {
                $statuses[] = $malformed($manifestLabel, sprintf('first action must be created or opened: the first action is %s (%s)', $first, $actions[0]['url']));
            }
        }
        // SPEC-033 amendment 1: once the opening rule has refused the manifest, c2pa-rs reads no further
        if (count($statuses) > $before) {
            return $statuses;
        }

        return [...$statuses, ...$this->contentRules($manifestLabel, $actions, $labels, $ingredients, $storeLabels)];
    }

    /**
     * SPEC-033: the actions content rules of C2PA 2.4 §15.10.3.2.3 and
     * §18.15.4.7, as c2pa 0.91.0's verify_actions() applies them — one
     * opening; ingredient references for opened, placed, removed,
     * transcoded and repackaged, resolved by label in this claim (open
     * question 2); c2pa.translated's languages; relatedAssertions; a
     * watermark's soft binding. Only well-formed assertions are read; rule
     * 2 has reported the others.
     *
     * @param  list<array{url: string, data: mixed}>  $actions
     * @param  list<string>  $labels
     * @param  array<string, string>  $ingredients
     * @param  array<string, list<string>>  $storeLabels
     * @return list<ValidationStatus>
     */
    private function contentRules(string $manifestLabel, array $actions, array $labels, array $ingredients, array $storeLabels): array
    {
        $statuses = [];
        $malformed = static fn (string $url, string $why): ValidationStatus => new ValidationStatus(StatusCode::AssertionActionMalformed, $url, $why);
        $mismatch = static fn (string $url, string $why): ValidationStatus => new ValidationStatus(StatusCode::AssertionActionIngredientMismatch, $url, $why);
        $readable = [];
        foreach ($actions as $assertion) {
            if ($this->checkData($assertion['data'], 2) === [] && is_array($assertion['data']) && is_array($assertion['data']['actions'] ?? null)) {
                $readable[] = ['url' => $assertion['url'], 'actions' => $assertion['data']['actions']];
            }
        }

        // one opening across all actions assertions (c2pa-rs: the inception count), on the claim's bare label
        $openings = 0;
        foreach ($readable as $assertion) {
            foreach ($assertion['actions'] as $action) {
                $openings += is_array($action) && in_array($action['action'] ?? null, self::OPENING_ACTIONS, true) ? 1 : 0;
            }
        }
        if ($openings > 1) {
            $statuses[] = $malformed($manifestLabel, sprintf('cannot have more than one c2pa.created or c2pa.opened action: the claim has %d', $openings));
        }

        $softBinding = array_filter($labels, static fn (string $label): bool => self::base($label) === 'c2pa.soft-binding') !== [];
        $resolves = static function (mixed $reference, string $relationship) use ($ingredients): bool {
            $url = is_array($reference) && is_string($reference['url'] ?? null) ? $reference['url'] : null;
            if ($url === null) {
                return false;
            }
            $label = substr($url, strrpos($url, '/') + 1);

            return ($ingredients[$label] ?? null) === $relationship;
        };
        foreach ($readable as $assertion) {
            $url = $assertion['url'];
            foreach ($assertion['actions'] as $i => $action) {
                if (! is_array($action)) {
                    continue;
                }
                $name = $action['action'] ?? '';
                $parameters = $action['parameters'] ?? null;
                $parameters = is_array($parameters) && ! array_is_list($parameters) ? $parameters : null;

                // opened, placed, removed: references of the right relationship (§15.10.3.2.3; c2pa-rs 2.b)
                if (in_array($name, ['c2pa.opened', 'c2pa.placed', 'c2pa.removed'], true)) {
                    if ($parameters === null || (! array_key_exists('ingredients', $parameters) && ! array_key_exists('ingredient', $parameters))) {
                        $statuses[] = $mismatch($url, 'opened, placed and removed items must have ingredient(s) parameters');

                        continue;
                    }
                    $references = array_key_exists('ingredient', $parameters) ? [$parameters['ingredient']] : $parameters['ingredients'];
                    if (! is_array($references) || ! array_is_list($references) || $references === []) {
                        $statuses[] = $mismatch($url, 'opened, placed and removed items must have ingredients parameter must be non empty array');
                        $references = [];
                    }
                    $relationship = $name === 'c2pa.opened' ? 'parentOf' : 'componentOf';
                    $good = count(array_filter($references, static fn (mixed $r): bool => $resolves($r, $relationship)));
                    if ($name === 'c2pa.opened' ? $good !== 1 : $good === 0) {
                        $statuses[] = $mismatch($url, sprintf("action[%d] ('%s') must have valid ingredient with %s relationship", $i, $name, $relationship));
                    }
                }

                // transcoded, repackaged: a reference, if given, is a parentOf (§15.10.3.2.3; c2pa-rs 2.c)
                if (in_array($name, ['c2pa.transcoded', 'c2pa.repackaged'], true) && $parameters !== null) {
                    $references = array_key_exists('ingredient', $parameters) ? [$parameters['ingredient']] : (is_array($parameters['ingredients'] ?? null) ? $parameters['ingredients'] : []);
                    if ($references !== [] && array_filter($references, static fn (mixed $r): bool => $resolves($r, 'parentOf')) === []) {
                        $statuses[] = $mismatch($url, sprintf("action[%d] ('%s') must have valid ingredient with parentOf relationship", $i, $name));
                    }
                }

                // c2pa.translated: both languages (§18.15.4.7, as c2pa-rs reads it)
                if ($name === 'c2pa.translated') {
                    $source = $parameters['sourceLanguage'] ?? null;
                    $target = $parameters['targetLanguage'] ?? null;
                    if (! is_string($source) || $source === '' || ! is_string($target) || $target === '') {
                        $statuses[] = $malformed($url, 'c2pa.translated action must have sourceLanguage and targetLanguage parameters');
                    }
                }

                // relatedAssertions (§15.10.3.2.3; c2pa-rs 2.f): non-empty, resolvable here, never actions or ingredients
                if ($parameters !== null && array_key_exists('relatedAssertions', $parameters)) {
                    $related = $parameters['relatedAssertions'];
                    if (! is_array($related) || ! array_is_list($related) || $related === []) {
                        $statuses[] = $malformed($url, 'relatedAssertions must contain at least one entry');
                        $related = [];
                    }
                    foreach ($related as $reference) {
                        $target = is_array($reference) && is_string($reference['url'] ?? null) ? $reference['url'] : '';
                        $label = substr($target, strrpos($target, '/') + 1);
                        $elsewhere = str_starts_with($target, 'self#jumbf=/c2pa/') && ! str_starts_with($target, "self#jumbf=/c2pa/{$manifestLabel}/");
                        if ($target === '' || $elsewhere || ! in_array($label, $labels, true)) {
                            $statuses[] = $malformed($target === '' ? $url : $target, sprintf('relatedAssertions reference could not be resolved within the current manifest: %s', $target));
                        }
                        if (in_array(self::base($label), ['c2pa.actions', 'c2pa.ingredient'], true)) {
                            $statuses[] = $malformed($target, sprintf('relatedAssertions must not reference an actions or ingredient assertion: %s', $target));
                        }
                    }
                }

                // c2pa.redacted: its reference names an assertion the named manifest's claim lists (§15.10.3.2.3;
                // c2pa-rs 2.d, SPEC-037). As c2patool, only an action that has parameters is read: a bare one passes.
                if ($name === 'c2pa.redacted' && array_key_exists('parameters', $action)) {
                    $fault = self::redactionFault($parameters['redacted'] ?? null, $storeLabels);
                    if ($fault !== null) {
                        $statuses[] = new ValidationStatus($fault, $url, $fault === StatusCode::AssertionNotRedacted ? 'The assertion was not redacted' : 'redaction uri must be a valid reference');
                    }
                }

                // a watermark needs a soft binding in the claim (§15.10.3.2.3)
                if (in_array($name, ['c2pa.watermarked', 'c2pa.watermarked.bound'], true) && ! $softBinding) {
                    $statuses[] = new ValidationStatus(StatusCode::AssertionActionSoftBindingMissing, $url, 'watermark action missing soft binding assertion');
                }
            }
        }

        return $statuses;
    }

    /**
     * SPEC-037: what is wrong with a c2pa.redacted action's `redacted`, as c2pa-rs's rule 2.d reads it.
     * Not an absolute URI into a manifest of this store: `assertion.action.redactionMismatch`. A manifest
     * whose claim lists no assertion with that label, or a URI naming no assertion (a data box
     * included, open question 3): `assertion.notRedacted`. The label is matched by substring against
     * the listed urls, as c2pa-rs matches it.
     *
     * @param  array<string, list<string>>  $storeLabels
     */
    private static function redactionFault(mixed $redacted, array $storeLabels): ?StatusCode
    {
        if (! is_string($redacted) || preg_match('#\Aself\#jumbf=/c2pa/([^/]+)#', $redacted, $m) !== 1 || ! array_key_exists($m[1], $storeLabels)) {
            return StatusCode::AssertionActionRedactionMismatch;
        }
        $at = strpos($redacted, '/c2pa.assertions/');
        $label = $at === false ? '' : substr($redacted, $at + strlen('/c2pa.assertions/'));
        foreach ($storeLabels[$m[1]] as $listed) {
            if ($label !== '' && str_contains("self#jumbf=c2pa.assertions/{$listed}", $label)) {
                return null;
            }
        }

        return StatusCode::AssertionNotRedacted;
    }

    /**
     * Every manifest of the store with the assertion labels its claim lists, created and gathered: what
     * rule 2.d resolves a c2pa.redacted reference against (SPEC-037).
     *
     * @param  array<string, Manifest>  $manifests
     * @return array<string, list<string>>
     */
    public static function claimLabels(array $manifests): array
    {
        $labels = [];
        foreach ($manifests as $label => $manifest) {
            $labels[$label] = array_map(static fn (HashedUri $entry): string => substr($entry->url, strrpos($entry->url, '/') + 1), [...$manifest->claim->createdAssertions, ...$manifest->claim->gatheredAssertions]);
        }

        return $labels;
    }

    /** A label without its `__n` instance and its `.vN` version: c2pa.actions.v2__1 → c2pa.actions. */
    private static function base(string $label): string
    {
        $label = preg_replace('/__\d+\z/', '', $label) ?? $label;

        return preg_replace('/\.v\d+\z/', '', $label) ?? $label;
    }

    /**
     * The seam: one decoded actions assertion, as claim $version — the faults
     * of rule 2, named by field. A v1 claim's assertion is not judged.
     *
     * @return list<string>
     */
    public function checkData(mixed $data, int $version): array
    {
        if ($version < 2) {
            return [];
        }
        if (! is_array($data) || array_is_list($data)) {
            return ['the assertion is not a map'];
        }
        if (! array_key_exists('actions', $data)) {
            return ['actions is missing'];
        }
        $list = $data['actions'];
        if (! is_array($list) || ! array_is_list($list)) {
            return ['actions is not a list'];
        }
        if ($list === []) {
            return ['actions is empty'];
        }
        if (count($list) > $this->maxActions) {
            return [sprintf('actions holds %d entries, above the limit of %d', count($list), $this->maxActions)];
        }
        foreach ($list as $i => $entry) {
            if (! is_array($entry) || array_is_list($entry)) {
                return [sprintf('actions[%d] is not a map', $i)];
            }
            $action = $entry['action'] ?? null;
            if (! is_string($action) || $action === '') {
                return [sprintf('actions[%d]: action is %s', $i, $action === null ? 'missing' : (is_string($action) ? 'empty' : 'not text'))];
            }
        }

        return [];
    }

    public static function isActionsLabel(string $label): bool
    {
        // c2pa.actions.v2, c2pa.actions, and their __n duplicates (C2PA 2.4 §7.2.2)
        $base = preg_replace('/__\d+\z/', '', $label) ?? $label;

        return $base === self::LABEL_V2 || $base === self::LABEL_V1;
    }

    /** The first action's `action` of a well-formed assertion, else null. */
    private static function firstAction(mixed $data): ?string
    {
        if (! is_array($data) || ! is_array($data['actions'] ?? null) || ! is_array($data['actions'][0] ?? null)) {
            return null;
        }
        $action = $data['actions'][0]['action'] ?? null;

        return is_string($action) && $action !== '' ? $action : null;
    }
}
