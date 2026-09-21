<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Verifier;

use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\ValidationResult;

/**
 * What Verifier::verify() answers (SPEC-013): the container format, whether
 * a manifest store was found, the store as parsed (null when there was
 * none, or it could not be read), and the validation result. toArray() is
 * c2patool's shape — active_manifest, manifests, validation_results,
 * validation_state, validation_status — plus format, has_manifest and
 * checks_performed, so that a consumer can tell a partial verdict from a
 * complete one and an unsigned file from a broken one.
 */
final readonly class VerificationReport
{
    public function __construct(
        public string $format,
        public bool $hasManifest,
        public ?ManifestStore $store,
        public ValidationResult $result,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $store = $this->store?->toArray() ?? ['active_manifest' => null, 'manifests' => []];
        $result = $this->result->toArray();

        return [
            'active_manifest' => $store['active_manifest'],
            'manifests' => $store['manifests'],
            'validation_results' => $result['validation_results'],
            'validation_state' => $result['validation_state'],
            'validation_status' => $result['validation_status'],
            'format' => $this->format,
            'has_manifest' => $this->hasManifest,
            'checks_performed' => $result['checks_performed'],
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
