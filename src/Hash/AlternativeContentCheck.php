<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Hash;

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Manifest\HashedUri;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;

/**
 * The alternative content representation's original preservation image (SPEC-065; C2PA 2.4 §15.10.3.2.7,
 * §18.14): at most one per manifest; exactly one of a multi-asset part index and an embedded reference; the
 * index inside the active manifest's `c2pa.hash.multi-asset` parts; the embedded reference's hash matching
 * the box it names. A representation of another type is generic and not checked. Nothing is fetched.
 *
 * @internal SPEC-025: not part of the public API.
 */
final readonly class AlternativeContentCheck
{
    public const LABEL = 'c2pa.alternative-content-representation';

    public const OPI = 'exif.originalPreservationImage';

    public function __construct(
        private HashedUriCheck $hashedUris = new HashedUriCheck,
    ) {}

    /**
     * @param  bool  $active  whether this is the active manifest: the index is checked only there (§18.14.2.1)
     * @param  list<string>  $unreadable  hashed URIs that did not match: those assertions are left unread
     * @return list<ValidationStatus>
     */
    public function check(Manifest $manifest, bool $active, array $unreadable = []): array
    {
        $opis = array_filter(self::assertions($manifest), static fn (mixed $data, string $url): bool => ! in_array($url, $unreadable, true) && is_array($data) && ($data['type'] ?? null) === self::OPI, ARRAY_FILTER_USE_BOTH);
        if (count($opis) > 1) {
            return array_map(static fn (string $url): ValidationStatus => self::malformed($url, sprintf('the manifest carries %d original preservation image representations; C2PA 2.4 §18.14.2.1 allows at most one', count($opis))), array_keys($opis));
        }
        $statuses = [];
        $parts = $active ? self::multiAssetParts($manifest) : null;
        foreach (self::assertions($manifest) as $url => $data) {
            if (in_array($url, $unreadable, true)) {
                continue;
            }
            $faults = self::shapeFaults($data, $parts, $active);
            if ($faults !== []) {
                $statuses[] = self::malformed($url, implode('; ', $faults));

                continue;
            }
            if (! is_array($data) || ($data['type'] ?? null) !== self::OPI || ! is_array($data['parameters'])) {
                continue;   // a generic representation
            }
            $embedded = $data['parameters']['embeddedOriginalPreservationImage'] ?? null;
            if (! is_array($embedded)) {
                if ($active) {
                    $statuses[] = new ValidationStatus(StatusCode::AssertionAlternativeContentRepresentationMatch, $url, 'original preservation image: the part index is inside the multi-asset hash\'s parts (C2PA 2.4 §15.10.3.2.7)');
                }

                continue;   // an index in an ingredient manifest cannot be validated there (§18.14.2.1)
            }
            /** @var array{url: string, hash: CborBytes, alg?: mixed} $embedded */
            $reference = new HashedUri($embedded['url'], $embedded['hash'], is_string($embedded['alg'] ?? null) ? $embedded['alg'] : null);
            $status = $this->hashedUris->checkEntry($manifest, $reference);
            $statuses[] = $status->code === StatusCode::AssertionHashedUriMatch
                ? new ValidationStatus(StatusCode::AssertionAlternativeContentRepresentationMatch, $url, sprintf('original preservation image: the embedded reference %s matches its hash (C2PA 2.4 §15.10.3.2.7)', $embedded['url']))
                : new ValidationStatus(StatusCode::AssertionAlternativeContentRepresentationHashMismatch, $url, sprintf('original preservation image: the embedded reference does not match: %s (C2PA 2.4 §15.10.3.2.7)', $status->explanation));
        }

        return $statuses;
    }

    /** Whether the claim lists any alternative content representation: the report names the check only then. */
    public static function present(Manifest $manifest): bool
    {
        return self::assertions($manifest) !== [];
    }

    /**
     * The shape faults of one decoded assertion; none for a generic representation.
     *
     * @param  int|null  $parts  the number of the active manifest's multi-asset parts, null without that assertion
     * @param  bool  $checkIndex  false in an ingredient manifest, where the index cannot be validated
     * @return list<string>
     */
    public static function shapeFaults(mixed $data, ?int $parts, bool $checkIndex = true): array
    {
        if (! is_array($data) || array_is_list($data)) {
            return ['the assertion is not a map'];
        }
        if (($data['type'] ?? null) !== self::OPI) {
            return [];
        }
        $parameters = $data['parameters'] ?? null;
        if (! is_array($parameters) || (array_is_list($parameters) && $parameters !== [])) {
            return ['parameters is not a map'];
        }
        $hasIndex = array_key_exists('multiAssetPartIndex', $parameters);
        $hasEmbedded = array_key_exists('embeddedOriginalPreservationImage', $parameters);
        if ($hasIndex === $hasEmbedded) {
            return [sprintf('parameters holds exactly one of multiAssetPartIndex and embeddedOriginalPreservationImage; it holds %s', $hasIndex ? 'both' : 'neither')];
        }
        if ($hasIndex) {
            $index = $parameters['multiAssetPartIndex'];
            if (! is_int($index) || $index < 0) {
                return ['multiAssetPartIndex is not an unsigned integer'];
            }
            if ($checkIndex && $parts === null) {
                return ['multiAssetPartIndex needs a c2pa.hash.multi-asset assertion with parts in the active manifest, and there is none'];
            }
            if ($checkIndex && $index >= $parts) {
                return [sprintf('multiAssetPartIndex %d is outside the %d parts of the multi-asset hash', $index, $parts)];
            }

            return [];
        }
        $embedded = $parameters['embeddedOriginalPreservationImage'];
        if (! is_array($embedded) || array_is_list($embedded)) {
            return ['embeddedOriginalPreservationImage is not a hashed URI map'];
        }
        if (! is_string($embedded['url'] ?? null) || $embedded['url'] === '') {
            return ['embeddedOriginalPreservationImage has no url as text'];
        }
        if (! array_key_exists('hash', $embedded)) {
            return ['embeddedOriginalPreservationImage has no hash'];
        }
        if (! $embedded['hash'] instanceof CborBytes) {
            return ['embeddedOriginalPreservationImage\'s hash is not a byte string'];
        }

        return [];
    }

    /** The parts count of the manifest's multi-asset hash, or null when the claim lists none with a parts list. */
    private static function multiAssetParts(Manifest $manifest): ?int
    {
        foreach ([...$manifest->claim->createdAssertions, ...$manifest->claim->gatheredAssertions] as $entry) {
            $label = substr($entry->url, strrpos($entry->url, '/') + 1);
            if ((preg_replace('/__\d+\z/', '', $label) ?? $label) === 'c2pa.hash.multi-asset' && isset($manifest->assertions[$label])) {
                $data = $manifest->assertions[$label]->data;
                if (is_array($data) && is_array($data['parts'] ?? null) && array_is_list($data['parts'])) {
                    return count($data['parts']);
                }
            }
        }

        return null;
    }

    private static function malformed(string $url, string $fault): ValidationStatus
    {
        return new ValidationStatus(StatusCode::AssertionAlternativeContentRepresentationMalformed, $url, sprintf('original preservation image malformed: %s (C2PA 2.4 §15.10.3.2.7)', $fault));
    }

    /**
     * The alternative content representations the claim lists, created and gathered, any instance.
     *
     * @return array<string, mixed> the assertion's url => its decoded data
     */
    private static function assertions(Manifest $manifest): array
    {
        $found = [];
        foreach ([...$manifest->claim->createdAssertions, ...$manifest->claim->gatheredAssertions] as $entry) {
            $label = substr($entry->url, strrpos($entry->url, '/') + 1);
            if ((preg_replace('/__\d+\z/', '', $label) ?? $label) === self::LABEL && isset($manifest->assertions[$label])) {
                $found[sprintf('self#jumbf=/c2pa/%s/c2pa.assertions/%s', $manifest->label, $label)] = $manifest->assertions[$label]->data;
            }
        }

        return $found;
    }
}
