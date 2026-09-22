<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Manifest;

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Cbor\CborTag;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Jumbf\Superbox;
use Provemark\C2paVerifier\Report\StatusCode;

/**
 * The manifest store as meaning (SPEC-007): its manifests by label, the
 * active one — the last in the store (C2PA 2.4 §11.1.4.2) — and a JSON
 * view in the shape c2patool prints, restricted to what M2 knows, so that
 * the sister library's ManifestStoreParser reads it.
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
        $manifests = [];
        foreach ($root->superboxes() as $child) {
            if ($child->description->uuid === JumbfParser::UUID_MANIFEST) {
                $manifest = Manifest::fromBox($child);
                $manifests[$manifest->label] = $manifest;
            }
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
            $manifests[$label] = self::manifestArray($manifest);
        }

        return ['active_manifest' => $this->active->label, 'manifests' => $manifests];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function manifestArray(Manifest $manifest): array
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

        $assertions = [];
        foreach ($manifest->assertions as $label => $assertion) {
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
