<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Verifier;

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Cbor\CborException;
use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\FormatDetector;
use Provemark\C2paVerifier\Container\IsobmffManifestStoreExtractor;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\ManifestStoreBytes;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Container\RemoteManifestDetector;
use Provemark\C2paVerifier\Container\WebpManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\ClaimSignatureCheck;
use Provemark\C2paVerifier\Cose\CoseException;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Hash\BmffHashCheck;
use Provemark\C2paVerifier\Hash\DataHashCheck;
use Provemark\C2paVerifier\Hash\HashedUriCheck;
use Provemark\C2paVerifier\Jumbf\JumbfException;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ActionsCheck;
use Provemark\C2paVerifier\Manifest\ExternalReferenceCheck;
use Provemark\C2paVerifier\Manifest\HashedUri;
use Provemark\C2paVerifier\Manifest\IconReferenceCheck;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestException;
use Provemark\C2paVerifier\Manifest\ManifestGraph;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Manifest\UpdateManifestCheck;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationResult;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Support\Bytes;
use Provemark\C2paVerifier\Timestamp\TimestampCheck;
use Provemark\C2paVerifier\Timestamp\TimestampResult;
use Provemark\C2paVerifier\Trust\Certificate;
use Provemark\C2paVerifier\Trust\CertificateProfileCheck;
use Provemark\C2paVerifier\Trust\ChainCheck;
use Provemark\C2paVerifier\Trust\OcspCheck;
use Provemark\C2paVerifier\Trust\TrustException;
use Provemark\C2paVerifier\Trust\TrustSettings;

/**
 * One call from file to verdict (SPEC-013), in the order C2PA 2.4 §15.3
 * prescribes: the format from the magic bytes, the store from the
 * container, the manifest from the boxes, then the claim signature, its
 * certificate's profile (SPEC-015), the trust of its chain (SPEC-014 —
 * untrusted without settings, as c2patool), the hashed URIs, and the data
 * hash — the last only when the claim's hashed
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
        private IsobmffManifestStoreExtractor $isobmff = new IsobmffManifestStoreExtractor,
        private BmffHashCheck $bmffHash = new BmffHashCheck,
        private JumbfParser $jumbf = new JumbfParser,
        private ClaimSignatureCheck $signature = new ClaimSignatureCheck,
        private HashedUriCheck $hashedUris = new HashedUriCheck,
        private DataHashCheck $dataHash = new DataHashCheck,
        private ChainCheck $trust = new ChainCheck,
        private CertificateProfileCheck $certificate = new CertificateProfileCheck,
        private TimestampCheck $timestamp = new TimestampCheck,
        private OcspCheck $revocation = new OcspCheck,
        private RemoteManifestDetector $remote = new RemoteManifestDetector,
        private ActionsCheck $actions = new ActionsCheck,
        private IngredientManifestCheck $ingredients = new IngredientManifestCheck,
        private UpdateManifestCheck $updateManifests = new UpdateManifestCheck,
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
                new ValidationStatus(StatusCode::GeneralError, self::STORE_URL, sprintf('unsupported file type: the file starts with %s, not a JPEG, PNG, WebP or ISOBMFF signature', Bytes::hex($head))),
            ], []));
        }

        // 2. the store
        try {
            $store = match ($format) {
                'jpeg' => $this->jpeg->extract($stream),
                'png' => $this->png->extract($stream),
                'webp' => $this->webp->extract($stream),
                // SPEC-026: the container only. There is no BMFF hard-binding check yet,
                // so the data hash finds no `c2pa.hash.data` and says
                // claim.hardBindings.missing — Invalid, named, and never a silent Valid.
                'isobmff' => $this->isobmff->extract($stream),
            };
        } catch (ContainerException $e) {
            return new VerificationReport($format, true, null, ValidationResult::fromStatuses([
                new ValidationStatus(StatusCode::GeneralError, self::STORE_URL, $e->getMessage()),
            ], []));
        }
        if ($store === null) {
            // no store in the file: say whether one is declared by URL (never fetched; SPEC-013 amendment 9)
            return new VerificationReport($format, false, null, ValidationResult::fromStatuses([], []), null, $this->remote->detect($stream));
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

        // the ingredient graph: what the assertions say about the other manifests in the store, and
        // what needs no cryptography to judge — unknown provenance, a malformed assertion, a
        // reference to a manifest that is not here (SPEC-020). Validating those manifests is SPEC-021.
        try {
            $graph = ManifestGraph::fromStore($manifestStore);
            $graphStatuses = $graph->statuses;
        } catch (ManifestException $e) {
            $graph = null;
            $graphStatuses = [new ValidationStatus($e->status, $e->url ?? self::STORE_URL, $e->getMessage())];
        }

        $timestamp = $this->timestamp->check($manifestStore->active, $settings);
        $result = $this->check($manifestStore, $stream, $store, $settings, $timestamp, $graphStatuses, $graph);
        $refusals = [];
        // a CAWG identity assertion carries a credential of its own that c2pa-rs validates; this verifier
        // does not yet, and will not call Trusted what it has not looked at (SPEC-013 amendment 7)
        foreach (array_keys($manifestStore->active->assertions) as $label) {
            if ($label === 'cawg.identity' || str_starts_with($label, 'cawg.identity.')) {
                $refusals[] = new ValidationStatus(StatusCode::GeneralError, sprintf('self#jumbf=/c2pa/%s/c2pa.assertions/%s', $manifestStore->active->label, $label), sprintf('the assertion %s carries an identity credential of its own that this verifier does not validate yet; refused rather than trusted unseen', $label));
            }
        }
        if ($refusals !== []) {
            $result = ValidationResult::fromStatuses([...$result->statuses, ...$refusals], $result->checksPerformed);
        }

        return new VerificationReport($format, true, $manifestStore, $result, $this->signatureInfo($manifestStore, $timestamp));
    }

    /**
     * The active manifest's signer as c2patool prints it: the COSE alg's
     * name, the leaf's O as "issuer", its CN, its serial in decimal
     * (SPEC-015). Null when the chain cannot be read — the checks say why.
     *
     * With `time` — the timestamp's genTime as c2patool renders it — when the
     * token validated (SPEC-017).
     *
     * @return array{alg: string, issuer: ?string, common_name: string, cert_serial_number: string, time?: string}|null
     */
    private function signatureInfo(ManifestStore $manifestStore, TimestampResult $timestamp): ?array
    {
        try {
            $cose = CoseSign1::fromBytes($manifestStore->active->signatureBytes());
            if ($cose->chain === []) {
                return null;
            }
            $leaf = Certificate::fromDer($cose->chain[0]->bytes);
        } catch (CoseException|TrustException) {
            return null;
        }
        $alg = match ($cose->alg) {
            -7 => 'Es256', -35 => 'Es384', -36 => 'Es512',
            -37 => 'Ps256', -38 => 'Ps384', -39 => 'Ps512',
            -8 => 'Ed25519',
            default => sprintf('alg %d', $cose->alg),
        };

        $info = ['alg' => $alg, 'issuer' => $leaf->organization, 'common_name' => $leaf->subjectCn(), 'cert_serial_number' => $leaf->serialDecimal];
        $time = $timestamp->timeIso();
        if ($time !== null) {
            $info['time'] = $time;
        }

        return $info;
    }

    /**
     * Steps 4–6 on the active manifest: the signature, the hashed URIs, and
     * the data hash — always, unless its assertion is declared and its hashed
     * URI failed (SPEC-013 amendment 10).
     *
     * @param  resource  $stream
     * @param  list<ValidationStatus>  $graphStatuses  the ingredient graph's, each scoped (SPEC-020)
     * @param  ManifestGraph|null  $graph  null when the graph could not be built (a bound was exceeded)
     */
    private function check(ManifestStore $manifestStore, $stream, ManifestStoreBytes $store, ?TrustSettings $settings, TimestampResult $timestamp, array $graphStatuses, ?ManifestGraph $graph): ValidationResult
    {
        $manifest = $manifestStore->active;
        // the timestamp first, as c2patool lists it; informational only, but it supplies the time below (SPEC-017)
        $statuses = [];
        $checks = [];
        if ($timestamp->present) {
            $statuses = $timestamp->statuses;
            $checks[] = 'timestamp';
        }
        $statuses = [...$statuses, ...$this->signature->check($manifest)];
        $checks[] = 'signature';

        // the certificate's profile, always: it is the signature's, not the operator's (SPEC-015);
        // validity at a validated, trusted timestamp's time, else at now (C2PA 2.4 §14.6.1)
        $at = $timestamp->trustedTime();
        $reason = match (true) {
            $at !== null => 'from the trusted timestamp',
            ! $timestamp->present => 'no timestamp',
            $timestamp->time === null => 'the timestamp did not validate',
            default => "the timestamp's TSA is not trusted",
        };
        $statuses = [...$statuses, ...$this->certificate->check($manifest, $settings, $at, $reason)];
        $checks[] = 'certificate';

        // trust: without settings there are no anchors and the answer is untrusted, as c2patool's;
        // only verify_trust false keeps quiet (SPEC-014 amendment 1)
        $trustSettings = $settings ?? new TrustSettings([], []);
        if ($trustSettings->verifyTrust) {
            $statuses = [...$statuses, ...$this->trust->check($manifest, $trustSettings, $at)];
            $checks[] = 'trust';
        }

        // revocation, as far as it can be known without a network: the OCSP responses the
        // signer stapled into its own signature (SPEC-030). It runs after the chain, which
        // supplies the issuer, and after the timestamp, which supplies the judged time. It
        // speaks on every file, including those with nothing stapled: what was not checked
        // has to be visible, or a caller cannot tell silence from a clean answer.
        $statuses = [...$statuses, ...$this->revocation->check(
            $this->unprotectedHeader($manifest),
            $this->chainOf($manifest),
            $at,
            sprintf('self#jumbf=/c2pa/%s/c2pa.signature', $manifest->label),
        )];
        $checks[] = 'revocation';

        $hashedUris = $this->hashedUris->check($manifest);
        $statuses = [...$statuses, ...$hashedUris];
        $checks[] = 'hashedUris';

        // the actions assertion (SPEC-018): read only where the claim vouched for it — an assertion whose
        // hashed URI mismatched is not what the signer saw, and the file is already refused
        $unreadable = [];
        foreach ($hashedUris as $status) {
            if ($status->code === StatusCode::AssertionHashedUriMismatch) {
                $unreadable[] = $status->url;
            }
        }
        $statuses = [...$statuses, ...$this->actions->check($manifest, $unreadable, ActionsCheck::claimLabels($manifestStore->manifests))];
        $checks[] = 'actions';
        // SPEC-032 rule B: named only where the claim carries an external reference
        if (ExternalReferenceCheck::present($manifest)) {
            $statuses = [...$statuses, ...(new ExternalReferenceCheck)->check($manifest, $unreadable)];
            $checks[] = 'externalReferences';
        }
        // SPEC-034: icon references, named only where the manifest carries an icon
        if (IconReferenceCheck::present($manifest)) {
            $statuses = [...$statuses, ...(new IconReferenceCheck)->check($manifest)];
            $checks[] = 'icons';
        }

        // an update manifest lives under §11.2.3's rules, and a standard manifest under §15.11's
        // one-parent rule — both need the graph's ingredients (SPEC-022)
        if ($graph !== null) {
            $scopes = [];
            foreach ($graph->referenced as $referenced => $urls) {
                $scopes[$referenced] = $urls[0];
            }
            $statuses = [...$statuses, ...$this->updateManifests->check($manifestStore, $graph->ingredients, $scopes)];
        }

        // the manifests the graph found: their box hash and everything the active manifest gets
        // except the data hash, scoped to the assertion that named them (SPEC-021)
        if ($graph !== null && $graph->referenced !== []) {
            $statuses = [...$statuses, ...$this->ingredients->check($manifestStore, $graph, $settings)];
            $checks[] = 'ingredients';
        }

        // the data hash runs unless the claim declares a c2pa.hash.data whose hashed URI failed — then the
        // assertion is not what the signer saw and hashedURI.mismatch already refuses the file. With no
        // c2pa.hash.data at all it runs and says claim.hardBindings.missing: a signed manifest without a
        // hard binding was Valid here until step 47 (SPEC-013 amendment 10)
        $dataHashUrl = sprintf('self#jumbf=/c2pa/%s/c2pa.assertions/%s', $manifest->label, DataHashCheck::LABEL);
        $declaredAndFailed = false;
        foreach ($hashedUris as $status) {
            if ($status->url === $dataHashUrl && $status->code === StatusCode::AssertionHashedUriMismatch) {
                $declaredAndFailed = true;
            }
        }
        if (! $declaredAndFailed) {
            // the binding covers the asset's bytes, and it lives in the active manifest unless that is an
            // update manifest — then it is found up the parentOf chain (§15.12). The exclusion it carries
            // was written before the update manifest was appended, so it is adjusted to the store's
            // current range (§15.12.1.1) — see DataHashCheck.
            $hasUpdate = false;
            foreach ($manifestStore->manifests as $other) {
                $hasUpdate = $hasUpdate || $other->isUpdateManifest;
            }
            $binding = $hasUpdate && $graph !== null
                ? UpdateManifestCheck::bindingManifest($manifestStore, $graph->ingredients)
                : $manifest;
            $gatheredOnly = $binding === null ? null : self::hardBindingGatheredOnly($binding);
            if ($binding === null) {
                $statuses[] = new ValidationStatus(StatusCode::ClaimHardBindingsMissing, sprintf('self#jumbf=/c2pa/%s/%s', $manifest->label, $manifest->claim->version === 2 ? 'c2pa.claim.v2' : 'c2pa.claim'), 'the active manifest is an update manifest and no manifest up its parentOf chain carries a hard binding (C2PA 2.4 §15.12)');
            } elseif ($gatheredOnly !== null) {
                // SPEC-013 amendment 13: a hard binding the signer lists only among gathered assertions is not
                // one the claim makes (§10.2.2); c2pa 0.91.0 refuses such a file outright. It is not read.
                $statuses[] = new ValidationStatus(StatusCode::ClaimHardBindingsMissing, sprintf('self#jumbf=/c2pa/%s', $binding->label), sprintf('the hard binding %s is listed only in gathered_assertions; created_assertions "shall contain, at minimum, a reference to an assertion that represents a hard binding" (C2PA 2.4 §10.2.2), so the manifest has none of its own', $gatheredOnly));
            } else {
                // SPEC-027: ISOBMFF binds through c2pa.hash.bmff.v3, whose exclusions are
                // box paths rather than byte ranges. Which check runs follows the assertion
                // the manifest actually carries, not the container it arrived in.
                $statuses = [...$statuses, ...(BmffHashCheck::labelOf($binding) !== null
                    ? $this->bmffHash->check($binding, $stream)
                    : $this->dataHash->check($binding, $stream, $store, $hasUpdate))];
            }
            // SPEC-027 amendment 1: `checks_performed` says which hard binding ran, so
            // that a caller reading it cannot mistake a BMFF file for one whose data hash
            // was verified. Naming both `dataHash` would be shorter and untrue. With no
            // binding manifest at all, nothing ran and the name stays the older one.
            $checks[] = $binding !== null && BmffHashCheck::labelOf($binding) !== null
                ? 'bmffHash'
                : 'dataHash';
        }

        // the graph's statuses last: they are scoped to their ingredient assertions and render
        // under `ingredientDeltas`, so their place in this list does not change the report.
        // Every scoped status — the graph's and the ingredient manifests' — is then weighed against
        // what the store's ingredient assertions recorded: a fault a writer acknowledged is not
        // re-reported, unless it names the active manifest (SPEC-021, CAI-12751)
        $statuses = [...$statuses, ...$graphStatuses];
        if ($graph !== null) {
            $statuses = $this->ingredients->drop($statuses, IngredientManifestCheck::recordedInStore($graph->ingredients), $manifestStore->active->label);
        }

        return ValidationResult::fromStatuses($statuses, $checks);
    }

    /**
     * The label of the manifest's hard binding when the claim references it
     * only from `gathered_assertions` and never from `created_assertions`,
     * else null (SPEC-013 amendment 13; C2PA 2.4 §10.2.2). A v1 claim has one
     * list, read as created, so it never answers here.
     */
    private static function hardBindingGatheredOnly(Manifest $manifest): ?string
    {
        $isBinding = static fn (string $label): bool => $label === DataHashCheck::LABEL || in_array($label, BmffHashCheck::LABELS, true);
        $labelOf = static function (HashedUri $reference) use ($manifest): ?string {
            try {
                return $manifest->resolve($reference->url)->description->label;
            } catch (ManifestException) {
                return null;   // an unresolvable reference has already failed as assertion.missing
            }
        };
        foreach ($manifest->claim->createdAssertions as $reference) {
            $label = $labelOf($reference);
            if ($label !== null && $isBinding($label)) {
                return null;
            }
        }
        foreach ($manifest->claim->gatheredAssertions as $reference) {
            $label = $labelOf($reference);
            if ($label !== null && $isBinding($label)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * The signature's unprotected header, or an empty one when it cannot be read.
     *
     * A signature this verifier cannot parse has already failed elsewhere, with its
     * own status; there is nothing for revocation to add to that (SPEC-030).
     *
     * @return array<int|string, mixed>
     */
    private function unprotectedHeader(Manifest $manifest): array
    {
        try {
            return CoseSign1::fromBytes($manifest->signatureBytes())->unprotected;
        } catch (CoseException|ManifestException) {
            return [];
        }
    }

    /** @return list<Certificate> the signer's chain, leaf first; empty when it cannot be read */
    private function chainOf(Manifest $manifest): array
    {
        try {
            return array_map(
                static fn (CborBytes $c): Certificate => Certificate::fromDer($c->bytes),
                CoseSign1::fromBytes($manifest->signatureBytes())->chain,
            );
        } catch (CoseException|ManifestException|TrustException) {
            return [];
        }
    }
}
