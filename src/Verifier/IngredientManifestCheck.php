<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Verifier;

use Provemark\C2paVerifier\Cose\ClaimSignatureCheck;
use Provemark\C2paVerifier\Hash\HashedUriCheck;
use Provemark\C2paVerifier\Manifest\ActionsCheck;
use Provemark\C2paVerifier\Manifest\IngredientAssertion;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestGraph;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Timestamp\TimestampCheck;
use Provemark\C2paVerifier\Trust\CertificateProfileCheck;
use Provemark\C2paVerifier\Trust\ChainCheck;
use Provemark\C2paVerifier\Trust\TrustSettings;

/**
 * The manifests the graph found, validated (SPEC-021; C2PA 2.4 §15.11).
 *
 * For each manifest an ingredient assertion names: the hash that assertion
 * recorded over its box (§15.11.3.3.2 — a match is
 * `ingredient.manifest.validated`; the pre-1.3 hash over the claim's CBOR
 * bytes is accepted silently, as c2pa-rs; neither is
 * `ingredient.manifest.mismatch`), and then the manifest itself: its
 * timestamp, signature, certificate profile, chain and trust, hashed URIs
 * and actions. Never the data hash — an ingredient's hard binding covers
 * *its* asset, which is not the file being verified (§15.11.3.3.1).
 *
 * Every status carries the URI of the ingredient assertion that named the
 * manifest, so the report groups it under `ingredientDeltas`. A status the
 * assertion itself already recorded is dropped (§18.16.12.4: the writer
 * acknowledged it and went on) — except when its url names the active
 * manifest, which no ingredient assertion may speak for (CAI-12751).
 *
 * This lives in the Verifier layer, not in Manifest: it needs Cose, Trust,
 * Hash and Timestamp, and the parsers know nothing of cryptography.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class IngredientManifestCheck
{
    public function __construct(
        private ClaimSignatureCheck $signature = new ClaimSignatureCheck,
        private HashedUriCheck $hashedUris = new HashedUriCheck,
        private ChainCheck $trust = new ChainCheck,
        private CertificateProfileCheck $certificate = new CertificateProfileCheck,
        private TimestampCheck $timestamp = new TimestampCheck,
        private ActionsCheck $actions = new ActionsCheck,
    ) {}

    /**
     * Every manifest the graph reached, in walk order, each scoped to the assertion that named it first.
     *
     * @return list<ValidationStatus>
     */
    public function check(ManifestStore $store, ManifestGraph $graph, ?TrustSettings $settings): array
    {
        $byUrl = [];
        foreach ($graph->ingredients as $list) {
            foreach ($list as $ingredient) {
                $byUrl[$ingredient->url] = $ingredient;
            }
        }
        $statuses = [];
        foreach ($graph->referenced as $label => $urls) {
            $ingredient = $byUrl[$urls[0]] ?? null;
            if ($ingredient === null || ! array_key_exists($label, $store->manifests)) {
                continue;
            }
            $manifest = $store->manifests[$label];
            $mine = $this->hash($manifest, $ingredient);
            $mine = [...$mine, ...$this->manifest($manifest, $ingredient->url, $settings)];
            $statuses = [...$statuses, ...$mine];
        }

        return $statuses;
    }

    /**
     * The box hash one reference states (C2PA 2.4 §8.4.2.3): the manifest superbox's payload under the
     * reference's algorithm, or the claim's, or SHA-256. The pre-1.3 form — the same hash over the
     * claim's CBOR bytes — is accepted without a word, exactly as c2pa-rs does.
     *
     * @return list<ValidationStatus>
     */
    public function hash(Manifest $manifest, IngredientAssertion $ingredient): array
    {
        $reference = $ingredient->manifest;
        if ($reference === null) {
            return [];
        }
        $alg = $reference->alg ?? $manifest->claim->alg ?? 'sha256';
        if (! in_array($alg, hash_algos(), true)) {
            return [new ValidationStatus(
                StatusCode::AlgorithmUnsupported,
                $reference->url,
                sprintf('the ingredient reference names the hash algorithm %s, which this verifier cannot compute', $alg),
                $ingredient->url,
            )];
        }
        $expected = $reference->hash->bytes;
        if (hash_equals(hash($alg, $manifest->box->payload(), true), $expected)) {
            return [new ValidationStatus(StatusCode::IngredientManifestValidated, $reference->url, 'ingredient hash matched', $ingredient->url)];
        }
        if (hash_equals(hash($alg, $manifest->claimBytes(), true), $expected)) {
            // the pre-1.3 hash: the ingredient is not refused, but nothing is claimed for it either —
            // the manifest below decides, as it does at c2patool (eleven corpus files, step 55)
            return [];
        }

        return [new ValidationStatus(
            StatusCode::IngredientManifestMismatch,
            $reference->url,
            sprintf('the ingredient manifest %s hashes to neither the value the assertion recorded over its box nor the one over its claim', $manifest->label),
            $ingredient->url,
        )];
    }

    /**
     * The ingredient manifest itself: everything the active manifest gets except the data hash.
     *
     * @return list<ValidationStatus>
     */
    private function manifest(Manifest $manifest, string $scope, ?TrustSettings $settings): array
    {
        $timestamp = $this->timestamp->check($manifest, $settings);
        $statuses = $timestamp->present ? $timestamp->statuses : [];
        $statuses = [...$statuses, ...$this->signature->check($manifest)];

        $at = $timestamp->trustedTime();
        $reason = match (true) {
            $at !== null => 'from the trusted timestamp',
            ! $timestamp->present => 'no timestamp',
            $timestamp->time === null => 'the timestamp did not validate',
            default => "the timestamp's TSA is not trusted",
        };
        $statuses = [...$statuses, ...$this->certificate->check($manifest, $settings, $at, $reason)];

        $trustSettings = $settings ?? new TrustSettings([], []);
        if ($trustSettings->verifyTrust) {
            $statuses = [...$statuses, ...$this->trust->check($manifest, $trustSettings)];
        }

        $hashedUris = $this->hashedUris->check($manifest);
        $statuses = [...$statuses, ...$hashedUris];
        $unreadable = [];
        foreach ($hashedUris as $status) {
            if ($status->code === StatusCode::AssertionHashedUriMismatch) {
                $unreadable[] = $status->url;
            }
        }
        $statuses = [...$statuses, ...$this->actions->check($manifest, $unreadable)];

        // the hard binding is not checked: it covers the ingredient's own asset, not this file (§15.11.3.3.1)
        return array_map(
            static fn (ValidationStatus $status): ValidationStatus => new ValidationStatus($status->code, $status->url, $status->explanation, $scope),
            $statuses,
        );
    }

    /**
     * Everything the store's ingredient assertions recorded, as one set of keys: c2pa-rs compares a
     * scoped status against all of them, not only against the assertion it was found under, because a
     * v3 assertion records the whole tree it validated (`update_manifest.jpg`: the active assertion's
     * `validationResults` carries the parent's two `ingredient.unknownProvenance` entries, and
     * c2patool drops both).
     *
     * @param  array<string, list<IngredientAssertion>>  $ingredients  per manifest label, from the graph
     * @return list<string>
     */
    public static function recordedInStore(array $ingredients): array
    {
        $keys = [];
        foreach ($ingredients as $list) {
            foreach ($list as $ingredient) {
                $keys = [...$keys, ...self::recorded($ingredient)];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * What the ingredient assertion recorded, as "code url" keys: v1 and v2 read `validationStatus`,
     * v3 the whole `validationResults` map (its active manifest and every ingredient delta). A
     * recorded url that is relative is made absolute against the manifest the assertion references,
     * as c2pa-rs does before comparing.
     *
     * @return list<string>
     */
    public static function recorded(IngredientAssertion $ingredient): array
    {
        $label = $ingredient->manifestLabel();
        $keys = [];
        $add = static function (mixed $entry) use (&$keys, $label): void {
            if (! is_array($entry) || ! isset($entry['code'], $entry['url']) || ! is_string($entry['code']) || ! is_string($entry['url'])) {
                return;
            }
            $url = $entry['url'];
            if ($label !== null && str_starts_with($url, 'self#jumbf=') && ! str_starts_with($url, 'self#jumbf=/')) {
                $url = sprintf('self#jumbf=/c2pa/%s/%s', $label, substr($url, strlen('self#jumbf=')));
            }
            $keys[] = $entry['code'].' '.$url;
        };
        foreach ((array) ($ingredient->validationStatus ?? []) as $entry) {
            $add($entry);
        }
        foreach (self::kinds($ingredient->validationResults['activeManifest'] ?? null) as $entry) {
            $add($entry);
        }
        foreach ((array) ($ingredient->validationResults['ingredientDeltas'] ?? []) as $delta) {
            if (is_array($delta)) {
                foreach (self::kinds($delta['validationDeltas'] ?? null) as $entry) {
                    $add($entry);
                }
            }
        }

        return $keys;
    }

    /**
     * The statuses a `{success, informational, failure}` map holds, in that order.
     *
     * @return list<mixed>
     */
    private static function kinds(mixed $map): array
    {
        if (! is_array($map)) {
            return [];
        }
        $out = [];
        foreach (['success', 'informational', 'failure'] as $kind) {
            foreach ((array) ($map[$kind] ?? []) as $entry) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Statuses minus the ones the assertion recorded — never one whose url names the active manifest.
     *
     * @param  list<ValidationStatus>  $statuses
     * @param  list<string>  $recorded  the keys of self::recorded()
     * @return list<ValidationStatus>
     */
    public function drop(array $statuses, array $recorded, string $activeLabel): array
    {
        $active = sprintf('self#jumbf=/c2pa/%s', $activeLabel);

        return array_values(array_filter($statuses, static function (ValidationStatus $status) use ($recorded, $active): bool {
            if ($status->ingredientUri === null) {
                return true;   // the active manifest's own line, never dropped
            }
            if ($status->url === $active || str_starts_with($status->url, $active.'/')) {
                return true;   // the guard: no ingredient assertion speaks for the manifest being verified
            }

            return ! in_array($status->code->value.' '.$status->url, $recorded, true);
        }));
    }
}
