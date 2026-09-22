<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Verifier;

use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\ValidationResult;

/**
 * What Verifier::verify() answers (SPEC-013): the container format, whether
 * a manifest store was found, the store as parsed (null when there was
 * none, or it could not be read), the validation result, and the active
 * manifest's signature_info (SPEC-015). toArray() is c2patool's shape — active_manifest, manifests, validation_results,
 * validation_state, validation_status — plus format, has_manifest and
 * checks_performed, so that a consumer can tell a partial verdict from a
 * complete one and an unsigned file from a broken one.
 */
final readonly class VerificationReport
{
    /**
     * @param  array{alg: string, issuer: ?string, common_name: string, cert_serial_number: string, time?: string}|null  $signatureInfo  the active manifest's signer as c2patool prints it (SPEC-015), with `time` when the timestamp validated (SPEC-017); null when the chain could not be read
     * @param  string|null  $remoteManifestUrl  a manifest declared by URL in the file's XMP, never fetched (SPEC-013 amendment 9)
     */
    public function __construct(
        public string $format,
        public bool $hasManifest,
        public ?ManifestStore $store,
        public ValidationResult $result,
        public ?array $signatureInfo = null,
        public ?string $remoteManifestUrl = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $store = $this->store?->toArray() ?? ['active_manifest' => null, 'manifests' => []];
        if ($this->signatureInfo !== null && $this->store !== null && is_array($store['manifests']) && is_array($store['manifests'][$this->store->active->label] ?? null)) {
            $store['manifests'][$this->store->active->label]['signature_info'] = $this->signatureInfo;
        }
        $result = $this->result->toArray();

        return [
            'active_manifest' => $store['active_manifest'],
            'manifests' => $store['manifests'],
            'validation_results' => $result['validation_results'],
            'validation_state' => $result['validation_state'],
        ] + (array_key_exists('validation_status', $result) ? ['validation_status' => $result['validation_status']] : []) + [
            'format' => $this->format,
            'has_manifest' => $this->hasManifest,
        ] + ($this->remoteManifestUrl === null ? [] : ['remote_manifest' => $this->remoteManifestUrl]) + [
            'checks_performed' => $result['checks_performed'],
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
