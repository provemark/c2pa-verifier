<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Verifier;

use Provemark\C2paVerifier\Cbor\CborDecoder;
use Provemark\C2paVerifier\Cbor\CborException;
use Provemark\C2paVerifier\Cbor\CrJsonValue;
use Provemark\C2paVerifier\Cbor\CrJsonValueException;
use Provemark\C2paVerifier\Cose\CoseException;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Manifest\Assertion;
use Provemark\C2paVerifier\Manifest\EmbeddedFile;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestException;
use Provemark\C2paVerifier\Manifest\ManifestGraph;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Timestamp\TimestampAssertions;
use Provemark\C2paVerifier\Timestamp\TimestampCheck;
use Provemark\C2paVerifier\Trust\Certificate;
use Provemark\C2paVerifier\Trust\TrustException;

/**
 * A report as a crJSON document (C2PA 2.4 crJSON §3; SPEC-075): every
 * manifest of the store, the active one first, its claim and assertions
 * mapped by Table 1 (SPEC-074), and its own validation results. Nothing is
 * checked here: the statuses are the report's, placed where crJSON wants
 * them. The signature is SPEC-076's: the algorithm by its C2PA name, the leaf
 * certificate, and the time-stamp token as read.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class CrJsonDocument
{
    public const string SPEC_VERSION = '2.4.0';

    /** COSE algorithm identifiers by the names crJSON §3.7 uses (C2PA 2.4 §13.2.1; SPEC-076 AC1). */
    private const array ALGORITHMS = [-7 => 'ES256', -35 => 'ES384', -36 => 'ES512', -37 => 'PS256', -38 => 'PS384', -39 => 'PS512', -8 => 'Ed25519'];

    /** The schema's distinguishedName keys, by OpenSSL's short names (SPEC-076 AC3). */
    private const array DN_KEYS = ['C' => 'C', 'ST' => 'ST', 'L' => 'L', 'O' => 'O', 'OU' => 'OU', 'CN' => 'CN', 'emailAddress' => 'E', 'organizationIdentifier' => '2.5.4.97'];

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
        $assertions = TimestampAssertions::collect($store);

        foreach (array_reverse($store->manifests, true) as $label => $manifest) {
            $codes = $manifest === $store->active ? $active : ($own[$label] ?? $empty);
            $document['manifests'][] = [
                'label' => (string) $label,
                ($manifest->claim->version === 2 ? 'claim.v2' : 'claim') => self::claim($manifest),
                'assertions' => self::assertions($manifest),
                'signature' => self::signature($manifest, $assertions, $codes),
                'validationResults' => $codes + ['specVersion' => self::SPEC_VERSION, 'validationTime' => $time],
            ] + (isset($held[$label]) ? ['ingredientDeltas' => $held[$label]] : []);
        }

        return $document;
    }

    /**
     * §3.7: the claim signature, or {} where it cannot be read (SPEC-076 AC5).
     *
     * @param  array<string, list<mixed>>  $results  the manifest's own validation results, as written below
     * @return array<string, mixed>|\stdClass
     */
    private static function signature(Manifest $manifest, TimestampAssertions $assertions, array $results = []): array|\stdClass
    {
        try {
            $cose = CoseSign1::ofManifest($manifest);
            $leaf = Certificate::chainOf($cose)[0] ?? null;
        } catch (CoseException|TrustException) {
            return new \stdClass;
        }
        if ($leaf === null) {
            return new \stdClass;
        }
        $signature = ['algorithm' => self::ALGORITHMS[$cose->alg] ?? 'Unknown', 'certificateInfo' => self::certificateInfo($leaf)];
        $judged = null;
        foreach ($results['success'] ?? [] as $status) {
            if (is_array($status) && ($status['code'] ?? null) === 'timeStamp.trusted' && is_string($status['url'] ?? null)) {
                $judged = $status['url'];   // the token the verdict took its time from (SPEC-076 amendment 1)
            }
        }
        $token = (new TimestampCheck)->readToken($manifest, $assertions, $judged);
        if ($token !== null) {
            $signature['timeStampInfo'] = ['timestamp' => gmdate('Y-m-d\TH:i:s\Z', $token[0]), 'certificateInfo' => self::certificateInfo($token[1])];
        }

        return $signature;
    }

    /** @return array{serialNumber: string, issuer: array<string, string>, subject: array<string, string>, validity: array{notBefore: string, notAfter: string}} */
    private static function certificateInfo(Certificate $certificate): array
    {
        return [
            'serialNumber' => $certificate->serialHex,
            'issuer' => self::distinguishedName($certificate->issuer),
            'subject' => self::distinguishedName($certificate->subject),
            'validity' => ['notBefore' => gmdate('Y-m-d\TH:i:s\Z', $certificate->validFrom), 'notAfter' => gmdate('Y-m-d\TH:i:s\Z', $certificate->validTo)],
        ];
    }

    /**
     * A name as the schema's distinguishedName: its eight keys only, in the certificate's order,
     * a repeated attribute's last value (as c2pa-rs writes it; SPEC-076 AC3).
     *
     * @param  array<string, mixed>  $name  openssl_x509_parse()'s subject or issuer
     * @return array<string, string>
     */
    public static function distinguishedName(array $name): array
    {
        $out = [];
        foreach ($name as $attribute => $value) {
            $key = self::DN_KEYS[$attribute] ?? null;
            $last = is_array($value) ? end($value) : $value;
            if ($key !== null && is_string($last)) {
                $out[$key] = $last;
            }
        }

        return $out;
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

        return self::ownResultsOf($graph, $byUri);
    }

    /**
     * The delta of the reference the checker scoped each manifest's own statuses under: the first
     * in walk order (`IngredientManifestCheck::check()`; SPEC-075 amendment 3). A manifest whose
     * first reference has no delta has none, whatever another reference holds.
     *
     * @param  array<string, array<string, list<mixed>>>  $byUri
     * @return array<string, array<string, list<mixed>>>
     */
    public static function ownResultsOf(ManifestGraph $graph, array $byUri): array
    {
        $own = [];
        foreach ($graph->referenced as $label => $urls) {
            if (isset($urls[0], $byUri[$urls[0]])) {
                $own[(string) $label] = $byUri[$urls[0]];
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
                'json' => CrJsonValue::fromJson($boxes[0]->data),   // §3.6.2: as it is, where it can be (SPEC-075 amendment 2)
                default => new \stdClass,   // §3.6.1: nothing to derive
            };
        } catch (CborException|CrJsonValueException) {
            return new \stdClass;
        }
    }
}
