<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Timestamp;

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;

/**
 * The time-stamp assertions of a store (SPEC-064; C2PA 2.4 §18.18, §15.10.3.2.6): every `c2pa.time-stamp`
 * the claim of any manifest lists, read into a map from a manifest's label to the tokens offered for it,
 * as c2pa-rs collects them (`store.rs`). A token is self-authenticating, the TSA's signature over the named
 * manifest's signature, so the manifest that carries it need not be trusted itself. A malformed
 * assertion, or a second one in one manifest (§18.18.3: at most one), is `assertion.timestamp.malformed`
 * for the manifest that holds it, and none of that manifest's tokens is used.
 *
 * @internal SPEC-025: not part of the public API.
 */
final readonly class TimestampAssertions
{
    public const LABEL = 'c2pa.time-stamp';

    /**
     * @param  array<string, list<array{0: string, 1: string}>>  $tokens  manifest label => [token DER, the assertion's url]
     * @param  array<string, list<ValidationStatus>>  $faults  label of the manifest that holds the assertion => statuses
     */
    private function __construct(
        private array $tokens,
        private array $faults,
    ) {}

    public static function none(): self
    {
        return new self([], []);
    }

    public static function collect(ManifestStore $store): self
    {
        $tokens = [];
        $faults = [];
        foreach ($store->manifests as $manifest) {
            $found = self::assertions($manifest);
            if ($found === []) {
                continue;
            }
            $mine = [];
            if (count($found) > 1) {
                $mine[] = new ValidationStatus(StatusCode::AssertionTimestampMalformed, array_key_first($found), sprintf('the manifest carries %d time-stamp assertions; C2PA 2.4 §18.18.3 allows at most one, so none is used', count($found)));
            }
            $offered = [];
            foreach ($found as $url => $data) {
                $fault = self::fault($data);
                if ($fault !== null) {
                    $mine[] = new ValidationStatus(StatusCode::AssertionTimestampMalformed, $url, sprintf('time-stamp assertion malformed: %s (C2PA 2.4 §15.10.3.2.6, §18.18.2); none of its tokens is used', $fault));

                    continue;
                }
                /** @var array<string, CborBytes> $data */
                foreach ($data as $label => $token) {
                    $offered[$label][] = [$token->bytes, $url];
                }
            }
            if ($mine !== []) {
                $faults[$manifest->label] = $mine;

                continue;
            }
            foreach ($offered as $label => $list) {
                $tokens[$label] = [...($tokens[$label] ?? []), ...$list];
            }
        }

        return new self($tokens, $faults);
    }

    /**
     * The tokens offered for a manifest, in store order.
     *
     * @return list<array{0: string, 1: string}> [token DER, the url of the assertion that holds it]
     */
    public function tokensFor(string $manifestLabel): array
    {
        return $this->tokens[$manifestLabel] ?? [];
    }

    /**
     * The malformed time-stamp assertions of one manifest.
     *
     * @return list<ValidationStatus>
     */
    public function faultsOf(string $manifestLabel): array
    {
        return $this->faults[$manifestLabel] ?? [];
    }

    /** Why the decoded assertion is not one map of at least one manifest label to a byte string, or null. */
    public static function fault(mixed $data): ?string
    {
        if (! is_array($data) || array_is_list($data)) {
            return is_array($data) && $data === [] ? 'the map is empty' : 'it is not a map';
        }
        foreach ($data as $label => $token) {
            if (! is_string($label) || trim($label) === '') {
                return 'a key is not a manifest label';
            }
            if (! $token instanceof CborBytes || $token->bytes === '') {
                return sprintf('the value for %s is not a byte string', $label);
            }
        }

        return null;
    }

    /**
     * The time-stamp assertions the claim lists, created and gathered (version 1: assertions), any instance.
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
