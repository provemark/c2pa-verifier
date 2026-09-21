<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Verifier;

use Provemark\C2paVerifier\Cbor\CborException;
use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\FormatDetector;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\ManifestStoreBytes;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Container\WebpManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\ClaimSignatureCheck;
use Provemark\C2paVerifier\Hash\DataHashCheck;
use Provemark\C2paVerifier\Hash\HashedUriCheck;
use Provemark\C2paVerifier\Jumbf\JumbfException;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestException;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationResult;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Support\Bytes;
use Provemark\C2paVerifier\Trust\ChainCheck;
use Provemark\C2paVerifier\Trust\TrustSettings;

/**
 * One call from file to verdict (SPEC-013), in the order C2PA 2.4 §15.3
 * prescribes: the format from the magic bytes, the store from the
 * container, the manifest from the boxes, then the claim signature, the
 * trust of its certificate when settings were given (SPEC-014), the
 * hashed URIs, and the data hash — the last only when the claim's hashed
 * URI for c2pa.hash.data matched (SPEC-011 decision 1): a hash read from
 * an assertion the claim does not vouch for proves nothing. Every fault a
 * layer throws becomes a status with its code; nothing escapes, nothing
 * is guessed. checks_performed says what was done; the absence of a check
 * is the statement that it was not.
 */
final readonly class Verifier
{
    public const STORE_URL = 'self#jumbf=/c2pa';

    public function __construct(
        private FormatDetector $formats = new FormatDetector,
        private JpegManifestStoreExtractor $jpeg = new JpegManifestStoreExtractor,
        private PngManifestStoreExtractor $png = new PngManifestStoreExtractor,
        private WebpManifestStoreExtractor $webp = new WebpManifestStoreExtractor,
        private JumbfParser $jumbf = new JumbfParser,
        private ClaimSignatureCheck $signature = new ClaimSignatureCheck,
        private HashedUriCheck $hashedUris = new HashedUriCheck,
        private DataHashCheck $dataHash = new DataHashCheck,
        private ChainCheck $trust = new ChainCheck,
    ) {}

    /**
     * @param  resource  $stream  the asset, readable and seekable
     * @param  TrustSettings|null  $settings  with settings the trust check runs (SPEC-014); without, the report says so in checks_performed
     */
    public function verify($stream, ?TrustSettings $settings = null): VerificationReport
    {
        // 1. the format
        $format = $this->formats->detect($stream);
        if ($format === null) {
            $head = $this->formats->head($stream);

            return new VerificationReport('unknown', false, null, ValidationResult::fromStatuses([
                new ValidationStatus(StatusCode::GeneralError, self::STORE_URL, sprintf('unsupported file type: the file starts with %s, not a JPEG, PNG or WebP signature', Bytes::hex($head))),
            ], []));
        }

        // 2. the store
        try {
            $store = match ($format) {
                'jpeg' => $this->jpeg->extract($stream),
                'png' => $this->png->extract($stream),
                'webp' => $this->webp->extract($stream),
            };
        } catch (ContainerException $e) {
            return new VerificationReport($format, true, null, ValidationResult::fromStatuses([
                new ValidationStatus(StatusCode::GeneralError, self::STORE_URL, $e->getMessage()),
            ], []));
        }
        if ($store === null) {
            return new VerificationReport($format, false, null, ValidationResult::fromStatuses([], []));
        }

        // 3. the manifest
        try {
            $manifestStore = ManifestStore::fromTree($this->jumbf->parse($store->bytes));
        } catch (JumbfException|CborException $e) {
            return new VerificationReport($format, true, null, ValidationResult::fromStatuses([
                new ValidationStatus(StatusCode::GeneralError, self::STORE_URL, $e->getMessage()),
            ], []));
        } catch (ManifestException $e) {
            return new VerificationReport($format, true, null, ValidationResult::fromStatuses([
                new ValidationStatus($e->status, $e->url ?? self::STORE_URL, $e->getMessage()),
            ], []));
        }

        return new VerificationReport($format, true, $manifestStore, $this->check($manifestStore, $stream, $store, $settings));
    }

    /**
     * Steps 4–6 on the active manifest: the signature, the hashed URIs, and
     * the data hash when the claim vouched for its assertion.
     *
     * @param  resource  $stream
     */
    private function check(ManifestStore $manifestStore, $stream, ManifestStoreBytes $store, ?TrustSettings $settings): ValidationResult
    {
        $manifest = $manifestStore->active;
        $statuses = $this->signature->check($manifest);
        $checks = ['signature'];

        if ($settings !== null && $settings->verifyTrust) {
            $statuses = [...$statuses, ...$this->trust->check($manifest, $settings)];
            $checks[] = 'trust';
        }

        $hashedUris = $this->hashedUris->check($manifest);
        $statuses = [...$statuses, ...$hashedUris];
        $checks[] = 'hashedUris';

        $dataHashUrl = sprintf('self#jumbf=/c2pa/%s/c2pa.assertions/%s', $manifest->label, DataHashCheck::LABEL);
        $vouched = false;
        foreach ($hashedUris as $status) {
            if ($status->code === StatusCode::AssertionHashedUriMatch && $status->url === $dataHashUrl) {
                $vouched = true;
            }
        }
        if ($vouched) {
            $statuses = [...$statuses, ...$this->dataHash->check($manifest, $stream, $store)];
            $checks[] = 'dataHash';
        }

        return ValidationResult::fromStatuses($statuses, $checks);
    }
}
