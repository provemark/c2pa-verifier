<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Manifest;

use Provemark\C2paVerifier\Cbor\CborBudget;
use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Cbor\CborException;
use Provemark\C2paVerifier\Cbor\CborTag;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Jumbf\Superbox;
use Provemark\C2paVerifier\Report\StatusCode;

/**
 * The manifest store as meaning (SPEC-007): its manifests by label, the
 * active one — the last in the store (C2PA 2.4 §11.1.4.2) — and a JSON
 * view in the shape c2patool prints, restricted to what M2 knows, so that
 * the sister library's ManifestStoreParser reads it.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class ManifestStore
{
    /**
     * @param  array<string, Manifest>  $manifests  by label, in store order
     */
    private function __construct(
        public array $manifests,
        public Manifest $active,
    ) {}

    public static function fromTree(Superbox $root): self
    {
        // every manifest read first, because a claim may redact an assertion of any other (SPEC-035
        // open question 1: the union of every claim's list); a manifest that cannot be read is
        // reported where it stands in the store, after the references of the ones before it
        // one CBOR budget for the whole store: what is decoded here stays in memory (SPEC-043 AC1)
        $budget = new CborBudget;
        $read = [];
        foreach ($root->superboxes() as $child) {
            if (in_array($child->description->uuid, [JumbfParser::UUID_MANIFEST, JumbfParser::UUID_UPDATE_MANIFEST], true)) {
                try {
                    $read[] = Manifest::read($child, $budget);
                } catch (ManifestException|CborException $e) {
                    $read[] = $e;
                    break;
                }
            }
        }
        $redactions = Manifest::redactionsOf(array_values(array_filter($read, static fn (Manifest|ManifestException|CborException $m): bool => $m instanceof Manifest)));
        $manifests = [];
        foreach ($read as $manifest) {
            if (! $manifest instanceof Manifest) {
                throw $manifest;
            }
            $manifest = $manifest->withRedactions($redactions);
            $manifests[$manifest->label] = $manifest;
        }
        if ($manifests === []) {
            throw new ManifestException('the store holds no manifest', StatusCode::ClaimMissing);
        }

        return new self($manifests, $manifests[array_key_last($manifests)]);
    }

    /**
     * c2patool's shape, without the fields the crypto layers fill
     * (`signature_info`, `validation_*`): `active_manifest` and, per
     * manifest, the claim's generator, title, instance id, the thumbnail
     * and the assertions — hard binding and thumbnail left out of the list,
     * as c2patool leaves them; labels as stored.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $manifests = [];
        foreach ($this->manifests as $label => $manifest) {
            $manifests[$label] = self::manifestArray($manifest, $this->manifests);
        }

        return ['active_manifest' => $this->active->label, 'manifests' => $manifests];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, Manifest>  $all  every manifest in the store, for the ingredients' references
     * @return array<string, mixed>
     */
    private static function manifestArray(Manifest $manifest, array $all = []): array
    {
        $claim = $manifest->claim;
        $out = [];
        // rendered through plain() like every value: a generator's icon is a hashed URI whose
        // hash is a byte string (OpenAI), and raw bytes would make toJson() throw (amendment 5)
        if ($claim->version === 1) {
            $out['claim_generator'] = $claim->claimGenerator;
            if ($claim->claimGeneratorInfo !== null) {
                $out['claim_generator_info'] = self::plain($claim->claimGeneratorInfo);
            }
        } else {
            $out['claim_generator_info'] = self::plain($claim->claimGeneratorInfo);
        }
        if ($claim->title !== null) {
            $out['title'] = $claim->title;
        }
        if ($claim->format !== null) {
            $out['format'] = $claim->format;
        }
        $out['instance_id'] = $claim->instanceId;

        $ingredients = self::ingredientsArray($manifest, $all);
        if ($ingredients !== []) {
            $out['ingredients'] = $ingredients;
        }

        $assertions = [];
        foreach ($manifest->assertions as $label => $assertion) {
            // the ingredients and their thumbnails are rendered above, as c2patool does, and left out here
            if (IngredientAssertion::isIngredientLabel($label) || str_starts_with($label, 'c2pa.thumbnail.ingredient')) {
                continue;
            }
            if ($assertion->data instanceof EmbeddedFile && str_starts_with($label, 'c2pa.thumbnail.claim')) {
                $out['thumbnail'] = [
                    'format' => $assertion->data->format,
                    'identifier' => sprintf('self#jumbf=/c2pa/%s/c2pa.assertions/%s', $manifest->label, $label),
                ];

                continue;
            }
            if (str_starts_with($label, 'c2pa.hash.')) {
                continue;
            }
            $assertions[] = ['label' => $label, 'data' => self::plain($assertion->data)];
        }
        $out['assertions'] = $assertions;
        $out['label'] = $manifest->label;
        $out['claim_version'] = $claim->version;

        return $out;
    }

    /**
     * The manifest's ingredients as c2patool prints them (SPEC-020): in claim order, each with the
     * fields it carries and, where it names one, the manifest it brought along. An assertion this
     * verifier cannot read is left out of the rendering — the report says so as a failure instead.
     *
     * @param  array<string, Manifest>  $all  every manifest in the store
     * @return list<array<string, mixed>>
     */
    private static function ingredientsArray(Manifest $manifest, array $all = []): array
    {
        $out = [];
        foreach (ManifestGraph::assertionLabels($manifest) as $label) {
            if (! IngredientAssertion::isIngredientLabel($label)) {
                continue;
            }
            try {
                $ingredient = IngredientAssertion::fromAssertion($manifest->label, $manifest->assertions[$label]);
            } catch (ManifestException) {
                continue;
            }
            $entry = [];
            foreach (['title' => $ingredient->title, 'format' => $ingredient->format, 'document_id' => $ingredient->documentId, 'instance_id' => $ingredient->instanceId] as $key => $value) {
                if ($value !== null) {
                    $entry[$key] = $value;
                }
            }
            if ($ingredient->thumbnail !== null) {
                // the thumbnail may live in this manifest (a relative URI) or in the ingredient's own
                // (an absolute one, as c2pa-rs writes since 2023) — c2patool prints it where it is
                $identifier = str_starts_with($ingredient->thumbnail->url, 'self#jumbf=/')
                    ? $ingredient->thumbnail->url
                    : sprintf('self#jumbf=/c2pa/%s/%s', $manifest->label, substr($ingredient->thumbnail->url, strlen('self#jumbf=')));
                $path = explode('/c2pa.assertions/', substr($identifier, strlen('self#jumbf=/c2pa/')), 2);
                $owner = $all[$path[0]] ?? $manifest;
                $data = $owner->assertions[$path[1] ?? '']->data ?? null;
                $entry['thumbnail'] = [
                    'format' => $data instanceof EmbeddedFile ? $data->format : 'application/octet-stream',
                    'identifier' => $identifier,
                ];
            }
            $entry['relationship'] = $ingredient->relationship->value;
            $referenced = $ingredient->manifestLabel();
            if ($referenced !== null) {
                $entry['active_manifest'] = $referenced;
            }
            if ($ingredient->validationStatus !== null && $ingredient->validationStatus !== []) {
                $entry['validation_status'] = self::plain($ingredient->validationStatus);
            }
            if ($ingredient->validationResults !== null) {
                $entry['validation_results'] = self::plain($ingredient->validationResults);
            }
            if (isset($ingredient->data['metadata'])) {
                $entry['metadata'] = self::plain($ingredient->data['metadata']);
            }
            // manifest_data names the manifest carried along; c2patool leaves it out when the label
            // is not in the store at all (adobe-20220124-E-clm-CAICAI points at a manifest that is not)
            if ($referenced !== null && array_key_exists($referenced, $all)) {
                $entry['manifest_data'] = ['format' => 'application/c2pa', 'identifier' => $referenced];
            }
            $entry['label'] = $ingredient->label;
            $out[] = $entry;
        }

        return $out;
    }

    /** Decoded data as JSON-able PHP: bytes as base64 (as c2patool prints them), tags as their content. */
    private static function plain(mixed $value): mixed
    {
        if ($value instanceof CborBytes) {
            return base64_encode($value->bytes);
        }
        if ($value instanceof CborTag) {
            return self::plain($value->value);
        }
        if ($value instanceof EmbeddedFile) {
            return ['format' => $value->format, 'bytes' => base64_encode($value->bytes)];
        }
        if (is_array($value)) {
            return array_map(self::plain(...), $value);
        }

        return $value;
    }
}
