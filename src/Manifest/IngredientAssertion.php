<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Manifest;

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Report\StatusCode;

/**
 * An ingredient assertion (SPEC-020; C2PA 2.4 §18.16): the asset this one
 * was made from, and — when that asset had Content Credentials — the
 * hashed URI of the manifest carried along with it (`c2pa_manifest` in v1
 * and v2, `activeManifest` in v3) plus, in v3, one to its signature box.
 * `validationStatus` (v1/v2) and `validationResults` (v3) are what the
 * claim generator recorded when it used the ingredient (§18.16.12.4);
 * they are carried as data here and read in SPEC-021.
 *
 * Every rule that makes an assertion malformed is in `fromAssertion()`:
 * the specification's (§15.11.3.2, §18.16.12.3, §15.11.3.3) and the
 * fields c2pa-rs requires per version. Unknown input is an error.
 */
final readonly class IngredientAssertion
{
    /**
     * @param  list<mixed>|null  $validationStatus  v1/v2, as recorded
     * @param  array<string, mixed>|null  $validationResults  v3, as recorded
     * @param  array<string, mixed>  $data  the whole decoded map
     */
    public function __construct(
        public string $label,
        public string $url,
        public int $version,
        public Relationship $relationship,
        public ?string $title,
        public ?string $format,
        public ?string $documentId,
        public ?string $instanceId,
        public ?HashedUri $manifest,
        public ?HashedUri $claimSignature,
        public ?HashedUri $thumbnail,
        public ?array $validationStatus,
        public ?array $validationResults,
        public ?string $digitalSourceType,
        public array $data,
    ) {}

    /** Whether the label names an ingredient assertion, with or without a `__N` suffix (C2PA 2.4 §18.16.1). */
    public static function isIngredientLabel(string $label): bool
    {
        return self::labelVersion($label) !== null;
    }

    /**
     * The version an ingredient label names — 1 for `c2pa.ingredient`, N for `c2pa.ingredient.vN` — or null
     * when the label is not an ingredient's at all.
     */
    private static function labelVersion(string $label): ?int
    {
        $base = explode('__', $label, 2)[0];
        if ($base === 'c2pa.ingredient') {
            return 1;
        }
        if (preg_match('/^c2pa\.ingredient\.v([1-9][0-9]*)$/', $base, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /** @throws ManifestException assertion.ingredient.malformed, with the assertion's absolute url */
    public static function fromAssertion(string $manifestLabel, Assertion $assertion): self
    {
        $url = sprintf('self#jumbf=/c2pa/%s/c2pa.assertions/%s', $manifestLabel, $assertion->label);
        $version = self::labelVersion($assertion->label);
        $fail = static function (string $message) use ($url): never {
            throw new ManifestException($message, StatusCode::AssertionIngredientMalformed, null, $url);
        };
        if ($version === null) {
            $fail(sprintf('%s is not an ingredient assertion', $assertion->label));
        }
        if ($version > 3) {
            // c2pa-rs: "Ingredient version to new" (exit 1); the reader of a v4 assertion cannot know its rules
            $fail(sprintf('ingredient assertion %s: version %d is not one this verifier reads (1, 2 or 3)', $assertion->label, $version));
        }
        $data = $assertion->data;
        if (! is_array($data) || array_is_list($data)) {
            $fail(sprintf('ingredient assertion %s: the content is not a CBOR map', $assertion->label));
        }
        /** @var array<string, mixed> $data */
        $text = static function (string $field) use ($data, $fail, $assertion): ?string {
            $value = $data[$field] ?? null;
            if ($value === null) {
                return null;
            }
            if (! is_string($value)) {
                $fail(sprintf('ingredient assertion %s: %s is %s, not text', $assertion->label, $field, get_debug_type($value)));
            }

            return $value;
        };
        $hashedUri = static function (string $field) use ($data, $fail, $assertion): ?HashedUri {
            $value = $data[$field] ?? null;
            if ($value === null) {
                return null;
            }
            if (! is_array($value) || ! isset($value['url']) || ! is_string($value['url'])) {
                $fail(sprintf('ingredient assertion %s: %s is not a hashed URI with a url', $assertion->label, $field));
            }
            /** @var array<string, mixed> $value */
            if (! ($value['hash'] ?? null) instanceof CborBytes) {
                $fail(sprintf('ingredient assertion %s: %s: hash is %s, not a byte string', $assertion->label, $field, get_debug_type($value['hash'] ?? null)));
            }
            $alg = $value['alg'] ?? null;
            if ($alg !== null && ! is_string($alg)) {
                $fail(sprintf('ingredient assertion %s: %s: alg is not text', $assertion->label, $field));
            }
            /** @var CborBytes $hash */
            $hash = $value['hash'];
            /** @var string $uri */
            $uri = $value['url'];

            return new HashedUri($uri, $hash, $alg);
        };

        // the relationship: required in every version, one of the three (C2PA 2.4 §15.11.3.2)
        if (! array_key_exists('relationship', $data)) {
            $fail(sprintf('ingredient assertion %s: relationship is missing', $assertion->label));
        }
        $relationshipValue = $data['relationship'];
        if (! is_string($relationshipValue)) {
            $fail(sprintf('ingredient assertion %s: relationship is %s, not text', $assertion->label, get_debug_type($relationshipValue)));
        }
        /** @var string $relationshipValue */
        $relationship = Relationship::tryFrom($relationshipValue);
        if ($relationship === null) {
            $fail(sprintf('ingredient assertion %s: relationship %s is not parentOf, componentOf or inputTo', $assertion->label, $relationshipValue));
        }

        // the fields the CDDL requires per version (c2pa-rs Ingredient::from_assertion)
        $title = $text('dc:title');
        $format = $text('dc:format');
        $instanceId = $text('instanceID');
        foreach ($version === 1 ? ['dc:title' => $title, 'dc:format' => $format, 'instanceID' => $instanceId] : ($version === 2 ? ['dc:title' => $title, 'dc:format' => $format] : []) as $field => $value) {
            if ($value === null) {
                $fail(sprintf('ingredient assertion %s (version %d): %s is missing', $assertion->label, $version, $field));
            }
        }

        $manifest = $hashedUri($version === 3 ? 'activeManifest' : 'c2pa_manifest');
        $claimSignature = $version === 3 ? $hashedUri('claimSignature') : null;
        $digitalSourceType = $text('digitalSourceType');
        if ($manifest !== null && $digitalSourceType !== null) {
            // §18.16.12.3: "An ingredient assertion shall not contain both an activeManifest and a
            // digitalSourceType key" — the signer would be saying two different things about the source.
            // c2pa-rs has no rule for the pair; this verifier follows the specification (docs/comparison.md)
            $fail(sprintf('ingredient assertion %s: a manifest reference and a digitalSourceType together', $assertion->label));
        }

        $validationStatus = null;
        $validationResults = null;
        if ($version === 3) {
            $recorded = $data['validationResults'] ?? null;
            if ($recorded !== null && (! is_array($recorded) || array_is_list($recorded))) {
                $fail(sprintf('ingredient assertion %s: validationResults is not a map', $assertion->label));
            }
            if ($recorded !== null) {
                /** @var array<string, mixed> $map */
                $map = $recorded;
                $validationResults = $map;
            }
            if ($manifest !== null && $validationResults === null) {
                // §15.11.3.3 and c2pa-rs: "ingredient V3 must have validation results"
                $fail(sprintf('ingredient assertion %s: a v3 assertion with an activeManifest must record validationResults', $assertion->label));
            }
        } else {
            $recorded = $data['validationStatus'] ?? null;
            if ($recorded !== null && (! is_array($recorded) || ! array_is_list($recorded))) {
                $fail(sprintf('ingredient assertion %s: validationStatus is not a list', $assertion->label));
            }
            /** @var list<mixed>|null $recorded */
            $validationStatus = $recorded;
        }

        return new self(
            $assertion->label,
            $url,
            $version,
            $relationship,
            $title,
            $format,
            $text('documentID'),
            $instanceId,
            $manifest,
            $claimSignature,
            $hashedUri('thumbnail'),
            $validationStatus,
            $validationResults,
            $digitalSourceType,
            $data,
        );
    }

    /** The label of the manifest this ingredient names, or null when it names none. */
    public function manifestLabel(): ?string
    {
        if ($this->manifest === null) {
            return null;
        }
        $path = preg_replace('~^self#jumbf=/c2pa/~', '', $this->manifest->url) ?? '';

        return explode('/', $path)[0];
    }
}
