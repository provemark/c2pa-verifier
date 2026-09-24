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
     * @return list<ValidationStatus>
     */
    public function check(Manifest $manifest, array $unreadable = []): array
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

        // c2patool's url for the manifest-level faults of this rule is the bare manifest label (measured, SPEC-018 amendment 2)
        return $this->checkAssertions($manifest->label, $manifest->claim->version, $actions, $manifest->isUpdateManifest);
    }

    /**
     * The seam: the ordered actions assertions as check() collects them. The
     * manifest-level faults carry the bare manifest label as their url — what
     * c2patool prints for this rule (its hard-binding faults carry the JUMBF
     * form; measured in step 49b).
     *
     * @param  list<array{url: string, data: mixed}>  $actions
     * @return list<ValidationStatus>
     */
    public function checkAssertions(string $manifestLabel, int $version, array $actions, bool $isUpdateManifest = false): array
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
            return $statuses;
        }
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

        return $statuses;
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
