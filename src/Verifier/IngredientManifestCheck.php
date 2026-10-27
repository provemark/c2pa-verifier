<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Verifier;

use Provemark\C2paVerifier\Cose\ClaimSignatureCheck;
use Provemark\C2paVerifier\Cose\CoseException;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Hash\AlternativeContentCheck;
use Provemark\C2paVerifier\Hash\HashedUriCheck;
use Provemark\C2paVerifier\Manifest\ActionsCheck;
use Provemark\C2paVerifier\Manifest\CloudDataCheck;
use Provemark\C2paVerifier\Manifest\ExternalReferenceCheck;
use Provemark\C2paVerifier\Manifest\IconReferenceCheck;
use Provemark\C2paVerifier\Manifest\IngredientAssertion;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestException;
use Provemark\C2paVerifier\Manifest\ManifestGraph;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Timestamp\TimestampAssertions;
use Provemark\C2paVerifier\Timestamp\TimestampCheck;
use Provemark\C2paVerifier\Timestamp\TimestampResult;
use Provemark\C2paVerifier\Trust\AnchorEvidence;
use Provemark\C2paVerifier\Trust\Certificate;
use Provemark\C2paVerifier\Trust\CertificateProfileCheck;
use Provemark\C2paVerifier\Trust\ChainCheck;
use Provemark\C2paVerifier\Trust\OcspCheck;
use Provemark\C2paVerifier\Trust\OcspEvidence;
use Provemark\C2paVerifier\Trust\RevocationSource;
use Provemark\C2paVerifier\Trust\TrustException;
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
 * assertion itself already recorded is marked acknowledged (SPEC-070; §18.16.12.4: the writer
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
    /** The hash algorithms C2PA 2.4 §13.1 allows (SPEC-052); a local list, as HashedUriCheck keeps its own. */
    private const ALGORITHMS = ['sha256', 'sha384', 'sha512'];

    public function __construct(
        private ClaimSignatureCheck $signature = new ClaimSignatureCheck,
        private HashedUriCheck $hashedUris = new HashedUriCheck,
        private ChainCheck $trust = new ChainCheck,
        private CertificateProfileCheck $certificate = new CertificateProfileCheck,
        private TimestampCheck $timestamp = new TimestampCheck,
        private ActionsCheck $actions = new ActionsCheck,
    ) {}

    /**
     * Every manifest the graph reached, in walk order, each scoped to the assertion that named it first;
     * the hash of every assertion that names it, each under that assertion (SPEC-021 amendment 7).
     *
     * @param  array<string, ManifestEvidence>  $evidence  each validated manifest's, added by label in walk order (SPEC-082)
     * @return list<ValidationStatus>
     */
    public function check(ManifestStore $store, ManifestGraph $graph, ?TrustSettings $settings, ?int $now = null, ?int $clock = null, array &$evidence = [], ?RevocationSource $source = null): array
    {
        $byUrl = [];
        foreach ($graph->ingredients as $list) {
            foreach ($list as $ingredient) {
                $byUrl[$ingredient->url] = $ingredient;
            }
        }
        $statuses = [];
        $assertions = TimestampAssertions::collect($store);   // SPEC-064: a later manifest's token for this one
        $responses = OcspCheck::assertionResponses($store);   // SPEC-066: certificate-status assertions of the store
        foreach ($graph->referenced as $label => $urls) {
            $ingredient = $byUrl[$urls[0]] ?? null;
            if ($ingredient === null || ! array_key_exists($label, $store->manifests)) {
                continue;
            }
            $manifest = $store->manifests[$label];
            $mine = $this->hash($manifest, $ingredient);
            $mine = [...$mine, ...$this->manifest($manifest, $ingredient->url, $settings, ActionsCheck::claimLabels($store->manifests), $assertions, $responses, $now, $clock, $evidence, $source)];
            // every later reference states a hash of its own, and each is checked under the assertion that
            // states it (amendment 7; C2PA 2.4 §15.11.3.3.2); the manifest itself is validated once (AC9)
            foreach (array_slice($urls, 1) as $url) {
                if (isset($byUrl[$url])) {
                    $mine = [...$mine, ...$this->hash($manifest, $byUrl[$url])];
                }
            }
            $statuses = [...$statuses, ...$mine];
        }

        return $statuses;
    }

    /**
     * The box hash one reference states (C2PA 2.4 §8.4.2.3): the manifest superbox's payload under the
     * ingredient claim's algorithm, or SHA-256 — never the reference's, as c2pa-rs computes it
     * (SPEC-052 amendment 1). The pre-1.3 form — a hash over the claim's CBOR bytes, under the
     * reference's algorithm or else the claim's — is accepted without a word, exactly as c2pa-rs does.
     * An algorithm outside the three computes nothing, so its route cannot match.
     *
     * @return list<ValidationStatus>
     */
    public function hash(Manifest $manifest, IngredientAssertion $ingredient): array
    {
        $reference = $ingredient->manifest;
        if ($reference === null) {
            return [];
        }
        if ($manifest->redacted !== [] && $manifest->claim->version >= 2) {
            return $this->claimSignature($manifest, $ingredient, $reference->url);
        }
        $boxAlg = $manifest->claim->alg ?? 'sha256';
        $legacyAlg = $reference->alg ?? $boxAlg;
        $expected = $reference->hash->bytes;
        if (self::implemented($boxAlg) && hash_equals(hash($boxAlg, $manifest->box->payload(), true), $expected)) {
            return [new ValidationStatus(StatusCode::IngredientManifestValidated, $reference->url, 'ingredient hash matched', $ingredient->url)];
        }
        if (self::implemented($legacyAlg) && hash_equals(hash($legacyAlg, $manifest->claimBytes(), true), $expected)) {
            // the pre-1.3 hash: the ingredient is not refused, but nothing is claimed for it either —
            // the manifest below decides, as it does at c2patool (eleven corpus files, step 55)
            return [];
        }

        return [new ValidationStatus(
            StatusCode::IngredientManifestMismatch,
            $reference->url,
            sprintf(
                'the ingredient manifest %s hashes to neither the value the assertion recorded over its box nor the one over its claim%s',
                $manifest->label,
                self::why($boxAlg, $legacyAlg),
            ),
            $ingredient->url,
        )];
    }

    /** C2PA 2.4 §13.1, as HashedUriCheck and DataHashCheck hold them (SPEC-052). */
    private static function implemented(string $alg): bool
    {
        return in_array($alg, self::ALGORITHMS, true);
    }

    /** What an algorithm outside the three, or a reference naming another than the box hash uses, adds to a mismatch. */
    private static function why(string $boxAlg, string $legacyAlg): string
    {
        foreach (array_unique([$boxAlg, $legacyAlg]) as $alg) {
            if (! self::implemented($alg)) {
                return sprintf('; the hash algorithm %s is not one of sha256, sha384, sha512 (C2PA 2.4 §13.1)', $alg);
            }
        }

        return $legacyAlg === $boxAlg ? '' : sprintf('; the box hash uses the ingredient claim\'s %s, and the reference names %s', $boxAlg, $legacyAlg);
    }

    /**
     * A manifest the store has redacted from no longer hashes to what its ingredient recorded, and
     * cannot: the box hash is not tried. A v2 claim is checked instead by the hash the ingredient
     * recorded over its signature box (C2PA 2.4 §15.11.3.3.1; c2pa-rs `ingredient_checks`), which the
     * signature binds to the claim and the claim to every assertion that is left. A v1 claim has no
     * such route and stays with the box hash, which then fails (SPEC-035 amendment 3).
     *
     * @return list<ValidationStatus>
     */
    private function claimSignature(Manifest $manifest, IngredientAssertion $ingredient, string $url): array
    {
        $recorded = $ingredient->claimSignature;
        if ($recorded === null) {
            return [new ValidationStatus(StatusCode::IngredientClaimSignatureMissing, $url, sprintf('ingredient claimSignature missing: the manifest %s has redacted assertions, so only the claim-signature hash can bind it, and the ingredient assertion records none', $manifest->label), $ingredient->url)];
        }
        $alg = $manifest->claim->alg ?? 'sha256';
        if (! self::implemented($alg)) {
            // nothing is computed, as at c2pa-rs, whose comparison then fails (SPEC-052 amendment 1)
            return [new ValidationStatus(StatusCode::IngredientClaimSignatureMismatch, $url, sprintf('ingredient claimSignature mismatch: the signature box of %s cannot be hashed; the hash algorithm %s is not one of sha256, sha384, sha512 (C2PA 2.4 §13.1)', $manifest->label, $alg), $ingredient->url)];
        }
        $actual = hash($alg, $manifest->resolve($manifest->claim->signatureUri)->payload(), true);

        return hash_equals($recorded->hash->bytes, $actual)
            ? [new ValidationStatus(StatusCode::IngredientClaimSignatureValidated, $url, 'ingredient claimSignature validated', $ingredient->url)]
            : [new ValidationStatus(StatusCode::IngredientClaimSignatureMismatch, $url, sprintf('ingredient claimSignature mismatch: the signature box of %s hashes (%s) to %s, the ingredient recorded %s', $manifest->label, $alg, bin2hex($actual), bin2hex($recorded->hash->bytes)), $ingredient->url)];
    }

    /**
     * The ingredient manifest itself: everything the active manifest gets except the data hash.
     *
     * @param  array<string, list<string>>  $storeLabels  the store's claims, for SPEC-037's c2pa.redacted rule
     * @param  list<array{0: string, 1: string}>  $responses  the store's certificate-status responses (SPEC-066)
     * @param  array<string, ManifestEvidence>  $evidence
     * @return list<ValidationStatus>
     */
    private function manifest(Manifest $manifest, string $scope, ?TrustSettings $settings, array $storeLabels, ?TimestampAssertions $assertions = null, array $responses = [], ?int $now = null, ?int $clock = null, array &$evidence = [], ?RevocationSource $source = null): array
    {
        $timestamp = $this->timestamp->forManifest($manifest, $settings, $assertions ?? TimestampAssertions::none());
        $statuses = $timestamp->present ? $timestamp->statuses : [];
        $statuses = [...$statuses, ...$this->signature->check($manifest)];

        $trusted = $timestamp->trustedTime();
        $at = $trusted ?? $now ?? $clock;   // SPEC-073: a given validation time stands in for now; the clock read once (amendment 1)
        $reason = match (true) {
            $trusted !== null => 'from the trusted timestamp',
            ! $timestamp->present => 'no timestamp',
            $timestamp->time === null => 'the timestamp did not validate',
            default => "the timestamp's TSA is not trusted",
        };
        $statuses = [...$statuses, ...$this->certificate->check($manifest, $settings, $at, $reason, $trusted === null ? ($now !== null ? 'the given validation time' : 'now') : null)];

        $trustSettings = $settings ?? new TrustSettings([], []);
        $anchor = null;
        $trust = [];
        if ($trustSettings->verifyTrust) {
            [$trust, $anchor] = $this->trust->checkWithAnchor($manifest, $trustSettings, $at);
            $statuses = [...$statuses, ...$trust];
        }
        // revocation of this manifest's signer (SPEC-066): where the store carries certificate-status
        // responses, which may name it, its own stapled responses join them. Without any, its own staple is
        // read for a revoked only (amendment 2): notRevoked and skipped stay unreported, as c2patool reports it
        try {
            $cose = CoseSign1::ofManifest($manifest);
            $chain = Certificate::chainOf($cose);
        } catch (CoseException|TrustException) {
            $cose = null;
            $chain = [];
        }
        $ocsp = [];
        if ($cose !== null) {
            [$revocation, $ocsp] = (new OcspCheck)->checkWithEvidence($cose->unprotected, $chain, $at, sprintf('self#jumbf=/c2pa/%s/c2pa.signature', $manifest->label), $responses, self::trusted($trust) ? $source : null);   // SPEC-086: a trusted signer only
            $statuses = [...$statuses, ...($responses !== [] ? $revocation : array_filter($revocation, static fn (ValidationStatus $s): bool => $s->code === StatusCode::SigningCredentialOcspRevoked))];
        }
        $evidence[$manifest->label] = self::evidenceOf($manifest, $at ?? time(), $timestamp, $now !== null, $anchor, $ocsp);   // no clock given: the checks read it themselves

        $hashedUris = $this->hashedUris->check($manifest);
        $statuses = [...$statuses, ...$hashedUris];
        $unreadable = [];
        foreach ($hashedUris as $status) {
            if ($status->code === StatusCode::AssertionHashedUriMismatch) {
                $unreadable[] = $status->url;
            }
        }
        $statuses = [...$statuses, ...$this->actions->check($manifest, $unreadable, $storeLabels)];
        $statuses = [...$statuses, ...(new ExternalReferenceCheck)->check($manifest, $unreadable)];   // SPEC-032 rule B
        $statuses = [...$statuses, ...(new CloudDataCheck)->check($manifest, $unreadable)];   // SPEC-063
        $statuses = [...$statuses, ...(new AlternativeContentCheck)->check($manifest, false, $unreadable)];   // SPEC-065
        $statuses = [...$statuses, ...(new IconReferenceCheck)->check($manifest)];   // SPEC-034

        // the hard binding is not checked: it covers the ingredient's own asset, not this file (§15.11.3.3.1)
        return array_map(
            static fn (ValidationStatus $status): ValidationStatus => new ValidationStatus($status->code, $status->url, $status->explanation, $scope),
            $statuses,
        );
    }

    /**
     * Whether the trust check reached an anchor: only then is a revocation source asked (SPEC-086).
     *
     * @param  list<ValidationStatus>  $trust
     */
    public static function trusted(array $trust): bool
    {
        foreach ($trust as $status) {
            if ($status->code === StatusCode::SigningCredentialTrusted) {
                return true;
            }
        }

        return false;
    }

    /**
     * A manifest's evidence (SPEC-082): judged at $at, whose source is the trusted time-stamp when it gave
     * the time, else the caller's validation time, else the clock; the TSA's anchor only in the first case.
     * Its certificates are x5chain's DER as the COSE reader took them (SPEC-083), none when it refused; its
     * time-stamps every token offered, as the timestamp check judged them (SPEC-084); its ocsp the responses
     * the signer's revocation check walked (SPEC-085).
     *
     * @param  list<OcspEvidence>  $ocsp
     */
    public static function evidenceOf(Manifest $manifest, int $at, TimestampResult $timestamp, bool $given, ?AnchorEvidence $signer, array $ocsp = []): ManifestEvidence
    {
        $fromTimestamp = $timestamp->trustedTime() !== null;
        try {
            $certificates = array_map(static fn ($certificate): string => $certificate->bytes, CoseSign1::ofManifest($manifest)->chain);
        } catch (CoseException|ManifestException) {
            $certificates = [];   // the signature check has already said why
        }

        return new ManifestEvidence($manifest->label, $at, $fromTimestamp ? ManifestEvidence::TIMESTAMP : ($given ? ManifestEvidence::GIVEN : ManifestEvidence::CLOCK), $signer, $fromTimestamp ? $timestamp->anchor : null, $certificates, $timestamp->evidence, $ocsp);
    }

    /**
     * Everything the validated manifests' ingredient assertions recorded, as one set of keys: c2pa-rs
     * compares a scoped status against all of them, not only against the assertion it was found under,
     * because a v3 assertion records the whole tree it validated (`update_manifest.jpg`: the active
     * assertion's `validationResults` carries the parent's two `ingredient.unknownProvenance` entries,
     * and c2patool drops both). Only the active manifest and the manifests the graph reaches count
     * (amendment 6): a manifest nothing names is never validated, so what it recorded vouches for
     * nothing. Each record carries the manifests that lie below the one holding it (amendment 3): a
     * record speaks only for those, never for the manifest that holds it nor for one above or beside it
     * (C2PA 2.4 §18.16.12.4: a statement by the manifest that takes the ingredient, about it). A record
     * of a reference that closes a cycle is left out (SPEC-070 amendment 4): a cycle is the store's own
     * shape, not an ingredient's error that a signer could have acknowledged, and c2patool refuses such
     * a store outright.
     *
     * @return list<array{key: string, below: list<string>}>
     */
    public static function recordedInStore(ManifestGraph $graph): array
    {
        $records = [];
        $cyclic = array_map(static fn (string $url): string => StatusCode::AssertionIngredientMalformed->value.' '.$url, $graph->cyclic);
        foreach ([$graph->active, ...array_map('strval', array_keys($graph->referenced))] as $label) {
            $below = null;
            foreach ($graph->ingredients[$label] ?? [] as $ingredient) {
                foreach (self::recorded($ingredient) as $key) {
                    if (in_array($key, $cyclic, true)) {
                        continue;
                    }
                    $below ??= $graph->below($label);
                    $records[$key."\n".$label] = ['key' => $key, 'below' => $below];
                }
            }
        }

        return array_values($records);
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
            $add($entry);   // v1/v2: no lists, so the kind is the code's own
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
     * The statuses a `{success, informational, failure}` map holds, in that order, each only where its
     * list is its code's own kind (SPEC-070 amendment 2). The comparison considers the type (C2PA 2.4
     * §18.16.12.4.3) and the reader's own status always has its code's kind, so an entry filed under
     * another list matches nothing: a failure recorded under `success` acknowledges nothing. A code this
     * verifier does not know is kept; no status of its own can carry it.
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
                $code = is_array($entry) && is_string($entry['code'] ?? null) ? StatusCode::tryFrom($entry['code']) : null;
                $own = match (true) {
                    $code === null => $kind,
                    $code->isSuccess() => 'success',
                    $code->isInformational() => 'informational',
                    default => 'failure',
                };
                if ($own === $kind) {
                    $out[] = $entry;
                }
            }
        }

        return $out;
    }

    /**
     * Whether a record matches the status and is held by a proper ancestor of the manifest the status is
     * about (amendment 3). A status whose url names no manifest matches nothing.
     *
     * @param  list<array{key: string, below: list<string>}>  $recorded
     */
    private static function recordedBelow(ValidationStatus $status, array $recorded): bool
    {
        if (preg_match('#^self\#jumbf=/?c2pa/([^/]+)#', $status->url, $m) !== 1) {
            return false;
        }
        $key = $status->code->value.' '.$status->url;
        foreach ($recorded as $record) {
            if ($record['key'] === $key && in_array($m[1], $record['below'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Statuses with the failures the assertion recorded marked as acknowledged (SPEC-070; SPEC-021 AC4
     * dropped them, as c2pa-rs does): reported as informational, counted for nothing. A recorded success
     * or informational line is still dropped (SPEC-070 amendment 1): it hides nothing. Never one whose
     * url names the active manifest, and never a failure of the manifest whose hard binding an update
     * manifest borrows (amendment 6): that binding is what ties the asset to the credential, so no
     * record may excuse its fault. Those stay as they are.
     *
     * @param  list<ValidationStatus>  $statuses
     * @param  list<array{key: string, below: list<string>}>  $recorded  self::recordedInStore(): each record's key and the
     *                                                                   manifests it may speak for (amendment 3)
     * @param  string|null  $bindingLabel  the binding manifest of an update manifest, null without one
     * @return list<ValidationStatus>
     */
    public function acknowledge(array $statuses, array $recorded, string $activeLabel, ?string $bindingLabel): array
    {
        $active = sprintf('self#jumbf=/c2pa/%s', $activeLabel);
        $binding = $bindingLabel === null ? null : sprintf('self#jumbf=/c2pa/%s', $bindingLabel);

        $out = [];
        foreach ($statuses as $status) {
            if ($status->ingredientUri === null
                || $status->url === $active || str_starts_with($status->url, $active.'/')
                || ($binding !== null && $status->code->isFailure() && ($status->url === $binding || str_starts_with($status->url, $binding.'/')))
                || ! self::recordedBelow($status, $recorded)) {
                // the active manifest's own line; the guard (no ingredient assertion speaks for the manifest
                // being verified, nor for the one that binds the asset in its stead); or nothing that may speak
                // for the manifest it is about recorded it (amendment 3)
                $out[] = $status;

                continue;
            }
            if (! $status->code->isFailure()) {
                continue;   // a recorded success or informational line hides nothing: dropped, as c2pa-rs does (amendment 1)
            }
            // the manifest whose ingredient assertion recorded it: the label in the assertion's URI
            $by = preg_match('#^self\#jumbf=/?c2pa/([^/]+)/#', $status->ingredientUri, $m) === 1 ? $m[1] : $status->ingredientUri;
            $out[] = new ValidationStatus($status->code, $status->url, sprintf('acknowledged by the signer of %s in its ingredient assertion (C2PA 2.4 §18.16.12.4), not counted in the verdict: %s', $by, $status->explanation), $status->ingredientUri, true);
        }

        return $out;
    }
}
