<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Manifest;

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;

/**
 * The graph the ingredient assertions draw over a manifest store
 * (SPEC-020; C2PA 2.4 §15.11.3.3): from the active manifest, depth-first,
 * each manifest entered once, every ingredient assertion visited in claim
 * order — the order c2patool's `ingredientDeltas` follow.
 *
 * What the graph alone can say, without a single hash: an ingredient
 * without a manifest reference is `ingredient.unknownProvenance` unless
 * it was `inputTo`; a reference to a manifest that is not in the store is
 * `ingredient.manifest.missing`; an assertion this verifier cannot read
 * is `assertion.ingredient.malformed`; a reference that leads back to a
 * manifest on the path is a cycle, and malformed too. Each status carries
 * the URI of the assertion it was found under.
 *
 * Validating the manifests it found — their box hash, signature, chain,
 * timestamp and assertions — is SPEC-021; until then the Verifier still
 * refuses a store with more than one manifest (SPEC-013 amendment 5).
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class ManifestGraph
{
    /** The deepest chain of ingredient manifests this verifier walks (c2pa-rs allows 200). */
    public const int MAX_DEPTH = 32;

    /** The most ingredient assertions a store may hold in all (c2pa-rs has no count). */
    public const int MAX_ASSERTIONS = 256;

    /**
     * @param  array<string, list<IngredientAssertion>>  $ingredients  per manifest label, claim order
     * @param  array<string, list<string>>  $referenced  referenced manifest label => the assertion urls naming it
     * @param  list<array{label: string, by: string}>  $missing  references to labels not in the store
     * @param  list<string>  $unreferenced  manifests the walk never reaches ("should be ignored", §15.11.3.3)
     * @param  list<string>  $redactedAssertions  every claim's redacted_assertions, collected (SPEC-021)
     * @param  list<ValidationStatus>  $statuses  each scoped to its ingredient assertion
     * @param  list<string>  $walk  the assertion urls in walk order
     */
    public function __construct(
        public string $active,
        public array $ingredients,
        public array $referenced,
        public array $missing,
        public array $unreferenced,
        public array $redactedAssertions,
        public array $statuses,
        public array $walk,
    ) {}

    /**
     * The graph of a store: every manifest's ingredient assertions decoded, then the walk.
     *
     * An assertion this verifier cannot read becomes a malformed status rather than an exception —
     * the report says what is wrong with which assertion, and the walk does not follow a reference
     * it could not read.
     *
     * @throws ManifestException general.error when a bound is exceeded
     */
    public static function fromStore(ManifestStore $store): self
    {
        $ingredients = [];
        $malformed = [];
        foreach ($store->manifests as $label => $manifest) {
            $ingredients[$label] = [];
            foreach (self::assertionLabels($manifest) as $assertionLabel) {
                if (! IngredientAssertion::isIngredientLabel($assertionLabel)) {
                    continue;
                }
                try {
                    $ingredients[$label][] = IngredientAssertion::fromAssertion($label, $manifest->assertions[$assertionLabel]);
                } catch (ManifestException $e) {
                    $url = $e->url ?? sprintf('self#jumbf=/c2pa/%s/c2pa.assertions/%s', $label, $assertionLabel);
                    $malformed[$label][] = new ValidationStatus($e->status, $url, $e->getMessage(), $url);
                }
            }
        }

        return self::fromIngredients($store->active->label, $ingredients, self::redactions($store), $malformed);
    }

    /**
     * The walk itself, over ingredient assertions already decoded — the seam the bounds and cycle
     * criteria are tested through (SPEC-020 AC7).
     *
     * @param  array<string, list<IngredientAssertion>>  $ingredients  per manifest label
     * @param  list<string>  $redactedAssertions
     * @param  array<string, list<ValidationStatus>>  $malformed  per manifest label, in assertion order
     *
     * @throws ManifestException general.error when a bound is exceeded
     */
    public static function fromIngredients(string $active, array $ingredients, array $redactedAssertions, array $malformed = []): self
    {
        $total = array_sum(array_map('count', $ingredients)) + array_sum(array_map('count', $malformed));
        if ($total > self::MAX_ASSERTIONS) {
            throw new ManifestException(sprintf('the store holds %d ingredient assertions; this verifier reads at most %d', $total, self::MAX_ASSERTIONS), StatusCode::GeneralError);
        }

        $referenced = [];
        $missing = [];
        $statuses = [];
        $walk = [];
        $visited = [];

        self::descend($active, 0, [], $ingredients, $malformed, $referenced, $missing, $statuses, $walk, $visited);

        $unreferenced = [];
        foreach (array_keys($ingredients) as $label) {
            if (! array_key_exists($label, $visited)) {
                $unreferenced[] = $label;
            }
        }

        return new self($active, $ingredients, $referenced, $missing, $unreferenced, $redactedAssertions, $statuses, $walk);
    }

    /**
     * One manifest of the walk: its malformed assertions, then its ingredients in claim order, each
     * followed at once into the manifest it names — the order c2pa-rs logs and c2patool prints.
     *
     * @param  list<string>  $path  the manifest labels on the way here, for the cycle rule
     * @param  array<string, list<IngredientAssertion>>  $ingredients
     * @param  array<string, list<ValidationStatus>>  $malformed
     * @param  array<string, list<string>>  $referenced
     * @param  list<array{label: string, by: string}>  $missing
     * @param  list<ValidationStatus>  $statuses
     * @param  list<string>  $walk
     * @param  array<string, true>  $visited
     *
     * @throws ManifestException general.error when the depth bound is exceeded
     */
    private static function descend(string $label, int $depth, array $path, array $ingredients, array $malformed, array &$referenced, array &$missing, array &$statuses, array &$walk, array &$visited): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new ManifestException(sprintf('the chain of ingredient manifests is deeper than %d', self::MAX_DEPTH), StatusCode::GeneralError);
        }
        $visited[$label] = true;
        $path[] = $label;
        foreach ($malformed[$label] ?? [] as $status) {
            $walk[] = $status->url;
            $statuses[] = $status;
        }
        foreach ($ingredients[$label] ?? [] as $ingredient) {
            $walk[] = $ingredient->url;
            $target = $ingredient->manifestLabel();
            if ($target === null) {
                // no manifest reference: unknown provenance, unless the ingredient was only an input (§15.11.3.3)
                if ($ingredient->relationship !== Relationship::InputTo) {
                    $statuses[] = new ValidationStatus(
                        StatusCode::IngredientUnknownProvenance,
                        $ingredient->url,
                        sprintf('%s: ingredient does not have provenance', $ingredient->title ?? 'no title'),
                        $ingredient->url,
                    );
                }

                continue;
            }
            if (in_array($target, $path, true)) {
                // a reference back into the path: the tree is not a tree (c2pa-rs: "ingredient cannot be cyclic")
                $statuses[] = new ValidationStatus(
                    StatusCode::AssertionIngredientMalformed,
                    $ingredient->url,
                    sprintf('ingredient assertion %s: the reference to %s is cyclic', $ingredient->label, $target),
                    $ingredient->url,
                );

                continue;
            }
            if (! array_key_exists($target, $ingredients)) {
                $missing[] = ['label' => $target, 'by' => $ingredient->url];
                $statuses[] = new ValidationStatus(StatusCode::IngredientManifestMissing, $target, 'ingredient not found', $ingredient->url);

                continue;
            }
            $referenced[$target][] = $ingredient->url;
            if (! array_key_exists($target, $visited)) {
                self::descend($target, $depth + 1, $path, $ingredients, $malformed, $referenced, $missing, $statuses, $walk, $visited);
            }
        }
    }

    /**
     * The labels of a manifest's assertions in claim order — created first, then gathered, then any the
     * claim does not name (SPEC-011 refuses those; the graph still sees them).
     *
     * @return list<string>
     */
    public static function assertionLabels(Manifest $manifest): array
    {
        $labels = [];
        foreach ([...$manifest->claim->createdAssertions, ...$manifest->claim->gatheredAssertions] as $uri) {
            $label = substr($uri->url, (int) strrpos($uri->url, '/') + 1);
            if (array_key_exists($label, $manifest->assertions) && ! in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }
        foreach (array_keys($manifest->assertions) as $label) {
            if (! in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /** @return list<string> every claim's redacted_assertions, in store order */
    private static function redactions(ManifestStore $store): array
    {
        $redactions = [];
        foreach ($store->manifests as $manifest) {
            foreach ((array) ($manifest->claim->other['redacted_assertions'] ?? []) as $uri) {
                if (is_string($uri)) {
                    $redactions[] = $uri;
                }
            }
        }

        return $redactions;
    }
}
