<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Verifier;

use Provemark\C2paVerifier\Timestamp\TimestampEvidence;
use Provemark\C2paVerifier\Trust\AnchorEvidence;
use Provemark\C2paVerifier\Trust\OcspEvidence;
use Provemark\C2paVerifier\Trust\TrustListIdentity;

/**
 * A report's evidence as a document to keep (SPEC-089), in the format
 * docs/evidence-format.md describes: the verdict it is the evidence of, the
 * trust lists, and each judged manifest with its time, its anchors, its
 * x5chain, its time-stamp tokens and its OCSP responses. DER bytes are
 * standard base64 (RFC 4648 §4), times seconds since the epoch, codes their
 * C2PA strings. Nothing is checked here: it writes down what the checks
 * recorded (SPEC-082 to SPEC-085).
 *
 * Text comes from the file (a manifest label, a url built from it) or from
 * a trust list; whatever is not UTF-8 is written with U+FFFD (AC6), so that
 * the array and its JSON say the same.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is VerificationReport::evidenceToArray()
 * and evidenceToJson(), and the format they write.
 */
final class EvidenceDocument
{
    public const string FORMAT = 'c2pa-verifier/evidence';

    /** Raised by removing, renaming or changing the meaning of a key; adding one keeps it (SPEC-089 question 2). */
    public const int VERSION = 1;

    /** @return array<string, mixed> */
    public static function of(VerificationReport $report): array
    {
        $manifests = [];
        foreach ($report->evidence() as $label => $evidence) {
            $manifests[self::text($label)] = self::manifest($evidence);
        }
        $state = $report->result->toArray()['validation_state'] ?? null;

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'verifier' => Verifier::VERSION,
            'validation_state' => is_string($state) ? $state : null,
            'active_manifest' => $report->store === null ? null : self::text($report->store->active->label),
            'trust_lists' => array_map(self::identity(...), $report->trustLists()),
            'manifests' => $manifests,
        ];
    }

    /** of() as JSON: an empty `manifests` is an object, as every other value of it is (AC3). */
    public static function json(VerificationReport $report): string
    {
        $document = self::of($report);
        if ($document['manifests'] === []) {
            $document['manifests'] = new \stdClass;
        }

        return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /** @return array<string, mixed> */
    private static function manifest(ManifestEvidence $evidence): array
    {
        return [
            'judged_at' => $evidence->judgedAt,
            'time_source' => $evidence->timeSource,
            'signer' => self::anchor($evidence->signer),
            'tsa' => self::anchor($evidence->tsa),
            'certificates' => array_map(base64_encode(...), $evidence->certificates),
            'timestamps' => array_map(self::timestamp(...), $evidence->timestamps),
            'ocsp' => array_map(self::ocsp(...), $evidence->ocsp),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function anchor(?AnchorEvidence $anchor): ?array
    {
        return $anchor === null ? null : [
            'route' => $anchor->route,
            'sha256' => $anchor->sha256,
            'depth' => $anchor->depth,
            'list' => $anchor->list === null ? null : self::identity($anchor->list),
            'trusted_from' => $anchor->trustedFrom,
            'trusted_until' => $anchor->trustedUntil,
        ];
    }

    /** @return array<string, mixed> */
    private static function identity(TrustListIdentity $list): array
    {
        return [
            'kind' => $list->kind,
            'form' => $list->form,
            'sha256' => $list->sha256,
            'sequence_number' => $list->sequenceNumber,
            'issue_date_time' => $list->issueDateTime === null ? null : self::text($list->issueDateTime),
        ];
    }

    /** @return array<string, mixed> */
    private static function timestamp(TimestampEvidence $token): array
    {
        return [
            'source' => $token->source,
            'url' => self::text($token->url),
            'token' => base64_encode($token->token),
            'gen_time' => $token->genTime,
            'certificates' => array_map(base64_encode(...), $token->certificates),
            'outcome' => $token->outcome,
            'codes' => array_map(static fn ($code): string => $code->value, $token->codes),
        ];
    }

    /** @return array<string, mixed> */
    private static function ocsp(OcspEvidence $response): array
    {
        return [
            'source' => $response->source,
            'url' => self::text($response->url),
            'response' => base64_encode($response->response),
            'outcome' => $response->outcome,
            'code' => $response->code?->value,
            'cert_status' => $response->certStatus,
            'produced_at' => $response->producedAt,
            'this_update' => $response->thisUpdate,
            'next_update' => $response->nextUpdate,
        ];
    }

    /** U+FFFD for each ill-formed sequence (amendment 2): mb_scrub() would write mb_substitute_character(), a `?` by default. */
    private static function text(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $scrubbed = json_decode(json_encode($text, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), flags: JSON_THROW_ON_ERROR);

        return is_string($scrubbed) ? $scrubbed : '';
    }
}
