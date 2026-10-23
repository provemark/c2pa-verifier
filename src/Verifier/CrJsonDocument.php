<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Verifier;

use Provemark\C2paVerifier\Cbor\CborDecoder;
use Provemark\C2paVerifier\Cbor\CborException;
use Provemark\C2paVerifier\Cbor\CrJsonValue;
use Provemark\C2paVerifier\Cbor\CrJsonValueException;
use Provemark\C2paVerifier\Manifest\Assertion;
use Provemark\C2paVerifier\Manifest\EmbeddedFile;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestException;
use Provemark\C2paVerifier\Manifest\ManifestGraph;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/**
 * A report as a crJSON document (C2PA 2.4 crJSON §3; SPEC-075): every
 * manifest of the store, the active one first, its claim and assertions
 * mapped by Table 1 (SPEC-074), and its own validation results. Nothing is
 * checked here: the statuses are the report's, placed where crJSON wants
 * them. The signature is SPEC-076's; until then it is `{}`.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class CrJsonDocument
{
    public const string SPEC_VERSION = '2.4.0';

    /** The form of the crJSON page's minimal example, and of c2pa-rs (SPEC-075 AC1). */
    private const array CONTEXT = ['@vocab' => 'https://c2pa.org/crjson', 'extras' => 'https://c2pa.org/crjson/extras'];

    /** @return array<string, mixed> the document, ready for json_encode with JSON_PRESERVE_ZERO_FRACTION */
    public static function of(VerificationReport $report): array
    {
        $document = [
            '@context' => self::CONTEXT,
            'jsonGenerator' => ['name' => 'provemark/c2pa-verifier', 'version' => Verifier::VERSION],
            'manifests' => [],
        ];
        $store = $report->store;
        if ($store === null) {
            return $document;   // AC8: crJSON describes a store; the report's failure goes elsewhere
        }

        $time = gmdate('Y-m-d\TH:i:s\Z', ($report->validationTime ?? new \DateTimeImmutable)->getTimestamp());
        $results = $report->result->toArray()['validation_results'];
        $empty = ['success' => [], 'informational' => [], 'failure' => []];
        /** @var array{success: list<mixed>, informational: list<mixed>, failure: list<mixed>} $active */
        $active = is_array($results) && is_array($results['activeManifest'] ?? null) ? $results['activeManifest'] : $empty;
        /** @var list<array{ingredientAssertionURI: string, validationDeltas: array<string, list<mixed>>}> $deltas */
        $deltas = is_array($results) && is_array($results['ingredientDeltas'] ?? null) ? $results['ingredientDeltas'] : [];

        // each delta goes to the manifest whose label its ingredient assertion's URI names (§3.8)
        $held = [];
        $byUri = [];
        foreach ($deltas as $delta) {
            $byUri[$delta['ingredientAssertionURI']] = $delta['validationDeltas'];
            if (preg_match('#^self\#jumbf=/c2pa/([^/]+)/#', $delta['ingredientAssertionURI'], $m) === 1) {
                $held[$m[1]][] = $delta;
            }
        }
        $own = self::ownResults($store, $byUri);

        foreach (array_reverse($store->manifests, true) as $label => $manifest) {
            $codes = $manifest === $store->active ? $active : ($own[$label] ?? $empty);
            $document['manifests'][] = [
                'label' => (string) $label,
                ($manifest->claim->version === 2 ? 'claim.v2' : 'claim') => self::claim($manifest),
                'assertions' => self::assertions($manifest),
                'signature' => new \stdClass,   // SPEC-076
                'validationResults' => $codes + ['specVersion' => self::SPEC_VERSION, 'validationTime' => $time],
            ] + (isset($held[$label]) ? ['ingredientDeltas' => $held[$label]] : []);
        }

        return $document;
    }

    /**
     * An ingredient manifest's own results: the delta of the ingredient assertion that names it
     * (step 370: 31 of 31 in c2pa-rs's crJSON); a manifest named by none has none.
     *
     * @param  array<string, array<string, list<mixed>>>  $byUri
     * @return array<string, array<string, list<mixed>>>
     */
    private static function ownResults(ManifestStore $store, array $byUri): array
    {
        try {
            $graph = ManifestGraph::fromStore($store);
        } catch (ManifestException) {
            return [];   // the report says why; no delta could have been placed either
        }
        $own = [];
        foreach ($graph->ingredients as $list) {
            foreach ($list as $ingredient) {
                $named = $ingredient->manifestLabel();
                if ($named !== null && ! isset($own[$named]) && isset($byUri[$ingredient->url])) {
                    $own[$named] = $byUri[$ingredient->url];
                }
            }
        }

        return $own;
    }

    /** §3.5: the claim's own CBOR, faithfully; an absent gathered or redacted list written as []. */
    private static function claim(Manifest $manifest): mixed
    {
        try {
            $claim = CrJsonValue::of((new CborDecoder(keepMaps: true))->decode($manifest->claimBytes()));
        } catch (CborException|CrJsonValueException) {
            return new \stdClass;
        }
        if (! $claim instanceof \stdClass) {
            return $claim;
        }
        $lists = $manifest->claim->version === 2 ? ['gathered_assertions', 'redacted_assertions'] : ['redacted_assertions'];
        foreach ($lists as $list) {
            $claim->{$list} ??= [];
        }

        return $claim;
    }

    /** §3.6: one key per assertion, in store order. */
    private static function assertions(Manifest $manifest): \stdClass
    {
        $hashes = [];
        foreach ([...$manifest->claim->createdAssertions, ...$manifest->claim->gatheredAssertions] as $reference) {
            $hashes[$manifest->absoluteUri($reference->url)] ??= $reference->hash->bytes;
        }
        $out = new \stdClass;
        foreach ($manifest->assertions as $assertion) {
            $out->{$assertion->label} = self::assertion($manifest, $assertion, $hashes);
        }

        return $out;
    }

    /** @param  array<string, string>  $hashes  the claim's hash of each assertion, by absolute URI */
    private static function assertion(Manifest $manifest, Assertion $assertion, array $hashes): mixed
    {
        if ($assertion->data instanceof EmbeddedFile) {
            // the crJSON page is silent on embedded files; this is c2pa-rs's shape (SPEC-075 AC4)
            $identifier = sprintf('self#jumbf=/c2pa/%s/c2pa.assertions/%s', $manifest->label, $assertion->label);

            return ['format' => $assertion->data->format, 'identifier' => $identifier]
                + (isset($hashes[$identifier]) ? ['hash' => "b64'".base64_encode($hashes[$identifier])."'"] : []);
        }
        $boxes = $assertion->box->contentBoxes();
        if (count($boxes) !== 1) {
            return new \stdClass;
        }
        try {
            return match ($boxes[0]->type) {
                'cbor' => CrJsonValue::of((new CborDecoder(keepMaps: true))->decode($boxes[0]->data)),
                'json' => json_decode($boxes[0]->data, false, 64, JSON_THROW_ON_ERROR),   // §3.6.2: as it is
                default => new \stdClass,   // §3.6.1: nothing to derive
            };
        } catch (CborException|CrJsonValueException|\JsonException) {
            return new \stdClass;
        }
    }
}
