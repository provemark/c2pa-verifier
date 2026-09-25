<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Manifest;

use Provemark\C2paVerifier\Cbor\CborBudget;
use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Cbor\CborDecoder;
use Provemark\C2paVerifier\Cbor\CborException;
use Provemark\C2paVerifier\Jumbf\ContentBox;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Jumbf\Superbox;
use Provemark\C2paVerifier\Jumbf\UnknownBox;
use Provemark\C2paVerifier\Report\StatusCode;

/**
 * One C2PA manifest (SPEC-007): its claim, its assertions decoded by
 * content type, and the boxes M3 and M4 will work on. Built from the
 * manifest superbox; every reference the claim makes is resolved here and
 * refused if it points nowhere, outside the assertion store, or at an
 * unknown box.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class Manifest
{
    private const URI_PREFIX = 'self#jumbf=';

    /**
     * @param  array<string, Assertion>  $assertions  by label, in store order; $assertionStore is the superbox itself, unknown boxes included (SPEC-007 amendment 2, for SPEC-011)
     */
    private function __construct(
        public string $label,
        public Claim $claim,
        public array $assertions,
        public Superbox $box,
        public Superbox $assertionStore,
        private Superbox $claimBox,
        private Superbox $signatureBox,
        /** A c2um box: it adds assertions without touching the content, and its rules differ (SPEC-022, C2PA 2.4 §11.2.3). */
        public bool $isUpdateManifest = false,
        /**
         * The absolute URIs of this manifest's assertions that a claim in the store declares redacted
         * (SPEC-035; C2PA 2.4 §6.8). A reference among them may name a box that is gone.
         *
         * @var list<string>
         */
        public array $redacted = [],
    ) {}

    /** One manifest on its own: the redactions its own claim declares are the only ones it knows. */
    public static function fromBox(Superbox $box): self
    {
        $manifest = self::read($box);

        return $manifest->withRedactions(self::redactionsOf([$manifest]));
    }

    /**
     * Every absolute entry of the claims' `redacted_assertions`, in order and once each. A relative
     * entry names no manifest; c2pa-rs neither resolves it nor lets it excuse a missing box
     * (SPEC-035 amendment 2), and neither does this reader.
     *
     * @param  list<self>  $manifests
     * @return list<string>
     */
    public static function redactionsOf(array $manifests): array
    {
        $redactions = [];
        foreach ($manifests as $manifest) {
            $entries = $manifest->claim->other['redacted_assertions'] ?? [];
            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (is_string($entry) && str_starts_with($entry, self::URI_PREFIX.'/c2pa/')) {
                    $redactions[] = $entry;
                }
            }
        }

        return array_values(array_unique($redactions));
    }

    /**
     * This manifest with the store's redactions that name it, its references checked against them.
     *
     * @param  list<string>  $redactions  every absolute entry in the store (redactionsOf())
     */
    public function withRedactions(array $redactions): self
    {
        $prefix = sprintf('%s/c2pa/%s/', self::URI_PREFIX, $this->label);
        $mine = array_values(array_filter($redactions, static fn (string $uri): bool => str_starts_with($uri, $prefix)));
        $manifest = new self($this->label, $this->claim, $this->assertions, $this->box, $this->assertionStore, $this->claimBox, $this->signatureBox, $this->isUpdateManifest, $mine);
        $manifest->checkReferences();

        return $manifest;
    }

    /** A URI of this manifest in its absolute form: a relative one is read against this manifest (§14.2). */
    public function absoluteUri(string $uri): string
    {
        if (! str_starts_with($uri, self::URI_PREFIX) || str_starts_with($uri, self::URI_PREFIX.'/')) {
            return $uri;
        }

        return sprintf('%s/c2pa/%s/%s', self::URI_PREFIX, $this->label, substr($uri, strlen(self::URI_PREFIX)));
    }

    /** Whether a reference names an assertion the store declares redacted. */
    public function isRedacted(string $uri): bool
    {
        return in_array($this->absoluteUri($uri), $this->redacted, true);
    }

    /** The manifest as read, its references not yet checked: ManifestStore needs every claim before it can check any. */
    public static function read(Superbox $box, ?CborBudget $budget = null): self
    {
        $budget ??= new CborBudget;
        $label = $box->description->label;
        $manifestUrl = sprintf('self#jumbf=/c2pa/%s', $label);
        $assertionStore = self::theOne($box, JumbfParser::UUID_ASSERTION_STORE, 'c2pa.assertions', 'assertion store', $label, StatusCode::ClaimMalformed, $manifestUrl);
        $claims = array_values(array_filter($box->superboxes(), static fn (Superbox $child): bool => $child->description->uuid === JumbfParser::UUID_CLAIM));
        if (count($claims) !== 1) {
            throw new ManifestException(
                sprintf('manifest %s: %d claim boxes, expected one', $label, count($claims)),
                count($claims) === 0 ? StatusCode::ClaimMissing : StatusCode::ClaimMultiple,
                null,
                count($claims) === 0 ? $manifestUrl : sprintf('%s/%s', $manifestUrl, $claims[0]->description->label),
            );
        }
        $claimBox = $claims[0];
        $claimUrl = sprintf('%s/%s', $manifestUrl, $claimBox->description->label);
        $signatureUrl = sprintf('%s/c2pa.signature', $manifestUrl);
        $signatureBox = self::theOne($box, JumbfParser::UUID_CLAIM_SIGNATURE, 'c2pa.signature', 'signature box', $label, StatusCode::ClaimSignatureMissing, $signatureUrl);

        $claim = self::at($claimUrl, static function () use ($claimBox, $label, $budget): Claim {
            $version = match ($claimBox->description->label) {
                'c2pa.claim' => 1,
                'c2pa.claim.v2' => 2,
                default => throw new ManifestException(sprintf(
                    'claim label %s at offset %d is neither c2pa.claim nor c2pa.claim.v2',
                    $claimBox->description->label,
                    $claimBox->description->offset,
                ), StatusCode::ClaimMalformed),
            };
            $claimData = self::singleCbor($claimBox, 'claim box', $label, StatusCode::ClaimMalformed);
            $claimMap = self::decodeCbor($claimData, sprintf('manifest %s: the claim', $label), $budget, StatusCode::ClaimCborInvalid);
            if (! is_array($claimMap) || array_is_list($claimMap)) {
                throw new ManifestException(sprintf('manifest %s: the claim is not a CBOR map', $label), StatusCode::ClaimCborInvalid);
            }

            return Claim::fromMap($version, $claimMap);
        });
        self::at($signatureUrl, static fn (): string => self::singleCbor($signatureBox, 'signature box', $label, StatusCode::ClaimSignatureMissing));

        $assertions = [];
        foreach ($assertionStore->superboxes() as $assertionBox) {
            $assertionLabel = $assertionBox->description->label;
            $data = self::at(sprintf('%s/c2pa.assertions/%s', $manifestUrl, $assertionLabel), static fn (): mixed => self::assertionData($assertionBox, $budget));
            $assertions[$assertionLabel] = new Assertion($assertionLabel, $assertionBox, $data);
        }

        return new self($label, $claim, $assertions, $box, $assertionStore, $claimBox, $signatureBox, $box->description->uuid === JumbfParser::UUID_UPDATE_MANIFEST);
    }

    /** The bytes of the claim's cbor box: what the signature covers (M3). */
    public function claimBytes(): string
    {
        return $this->claimBox->contentBoxes()[0]->data;
    }

    /** The bytes of the signature's cbor box: the COSE_Sign1 (M3). */
    public function signatureBytes(): string
    {
        return $this->signatureBox->contentBoxes()[0]->data;
    }

    /**
     * The superbox a JUMBF URI names: `self#jumbf=/c2pa/<manifest>/…` from
     * the store's root, anything else relative to this manifest; each
     * segment a superbox label.
     */
    public function resolve(string $uri): Superbox
    {
        if (! str_starts_with($uri, self::URI_PREFIX)) {
            throw new ManifestException(sprintf('URI %s does not start with %s', $uri, self::URI_PREFIX), StatusCode::AssertionMissing);
        }
        $path = substr($uri, strlen(self::URI_PREFIX));
        if (str_starts_with($path, '/c2pa/')) {
            $segments = explode('/', substr($path, strlen('/c2pa/')));
            $first = array_shift($segments);
            if ($first !== $this->label) {
                throw new ManifestException(sprintf('URI %s refers to another manifest (%s); cross-manifest references are not supported yet', $uri, (string) $first), StatusCode::AssertionMissing);
            }
        } else {
            $segments = explode('/', $path);
        }

        $box = $this->box;
        foreach ($segments as $segment) {
            $next = $box->child($segment);
            if ($next === null) {
                foreach ($box->children as $child) {
                    if ($child instanceof UnknownBox && $child->label === $segment) {
                        throw new ManifestException(sprintf('URI %s resolves to an unknown box (UUID %s)', $uri, (string) $child->uuid), StatusCode::AssertionMissing);
                    }
                }
                throw new ManifestException(sprintf('URI %s does not resolve to a box (no %s)', $uri, $segment), StatusCode::AssertionMissing);
            }
            $box = $next;
        }

        return $box;
    }

    /** Every reference in the claim must land where the spec says (SPEC-007 AC4, AC10). */
    private function checkReferences(): void
    {
        try {
            $signatureTarget = $this->resolve($this->claim->signatureUri);
        } catch (ManifestException $e) {
            throw new ManifestException($e->getMessage(), StatusCode::ClaimSignatureMissing, $e);
        }
        if ($signatureTarget !== $this->signatureBox) {
            throw new ManifestException(sprintf('manifest %s: signature URI %s does not name the signature box', $this->label, $this->claim->signatureUri), StatusCode::ClaimSignatureMissing);
        }
        foreach ([...$this->claim->createdAssertions, ...$this->claim->gatheredAssertions] as $reference) {
            // SPEC-040: an entry naming another manifest's assertion store (C2PA 2.4 §15.10.3.1), whether
            // or not that manifest is in the store, on the entry as written — as c2pa-rs's assertion loop
            if (preg_match('#\A'.preg_quote(self::URI_PREFIX, '#').'/c2pa/([^/]+)/#', $reference->url, $m) === 1 && $m[1] !== $this->label) {
                throw new ManifestException(sprintf('assertion reference to external assertion store: %s', $reference->url), StatusCode::AssertionOutsideManifest, null, $reference->url);
            }
            try {
                $target = $this->resolve($reference->url);
            } catch (ManifestException $e) {
                if ($this->isRedacted($reference->url)) {
                    continue;   // redacted and removed: a valid form of redaction (SPEC-035; §15.11.3.3.1)
                }
                throw $e;
            }
            if (! in_array($target, $this->assertionStore->superboxes(), true)) {
                throw new ManifestException(sprintf('URI %s is not in the assertion store', $reference->url), StatusCode::AssertionMissing);
            }
        }
    }

    /**
     * Runs $build; a ManifestException thrown inside leaves with the URI of the
     * box being read, unless it already names one (SPEC-007 amendment 3).
     *
     * @template T
     *
     * @param  callable(): T  $build
     * @return T
     */
    private static function at(string $url, callable $build): mixed
    {
        try {
            return $build();
        } catch (ManifestException $e) {
            throw $e->at($url);
        }
    }

    /** The one child superbox with this UUID and label. */
    private static function theOne(Superbox $box, string $uuid, string $label, string $what, string $manifestLabel, StatusCode $status, string $url): Superbox
    {
        $matches = array_values(array_filter(
            $box->superboxes(),
            static fn (Superbox $child): bool => $child->description->uuid === $uuid && $child->description->label === $label,
        ));
        if (count($matches) === 0) {
            throw new ManifestException(sprintf('manifest %s: no %s (%s)', $manifestLabel, $what, $label), $status, null, $url);
        }
        if (count($matches) > 1) {
            throw new ManifestException(sprintf('manifest %s: %d %ses (%s), expected one', $manifestLabel, count($matches), $what, $label), $status, null, $url);
        }

        return $matches[0];
    }

    /** The data of a superbox that must hold exactly one cbor content box (C2PA 2.4 §11.1.4.4). */
    private static function singleCbor(Superbox $box, string $what, string $manifestLabel, StatusCode $status): string
    {
        $content = $box->contentBoxes();
        if (count($content) !== 1 || $content[0]->type !== 'cbor') {
            throw new ManifestException(sprintf(
                'manifest %s: the %s holds %d content boxes, expected one cbor box',
                $manifestLabel,
                $what,
                count($content),
            ), $status);
        }

        return $content[0]->data;
    }

    private static function decodeCbor(string $data, string $what, CborBudget $budget, StatusCode $status = StatusCode::GeneralError): mixed
    {
        try {
            return (new CborDecoder)->decode($data, $budget);
        } catch (CborException $e) {
            throw new ManifestException(sprintf('%s: invalid CBOR: %s', $what, $e->getMessage()), $status, $e);
        }
    }

    /** An assertion's data by the kind of content box it holds: one kind, one box. */
    private static function assertionData(Superbox $box, CborBudget $budget): mixed
    {
        $label = $box->description->label;
        $byType = [];
        foreach ($box->contentBoxes() as $content) {
            $byType[$content->type][] = $content;
        }
        $kinds = array_keys($byType);
        if ($kinds === ['cbor']) {
            return self::decodeCbor(self::only($byType['cbor'], $label), sprintf('assertion %s', $label), $budget);
        }
        if ($kinds === ['json']) {
            try {
                return json_decode(self::only($byType['json'], $label), true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new ManifestException(sprintf('assertion %s: invalid JSON: %s', $label, $e->getMessage()), StatusCode::AssertionJsonInvalid, $e);
            }
        }
        if ($kinds === ['bfdb', 'bidb']) {
            return new EmbeddedFile(self::mediaType(self::only($byType['bfdb'], $label), $label), self::only($byType['bidb'], $label));
        }
        if ($kinds === ['uuid']) {
            return new CborBytes(self::only($byType['uuid'], $label));
        }
        throw new ManifestException(sprintf('assertion %s: content boxes of kind %s are not a known assertion shape', $label, $kinds === [] ? '(none)' : implode('+', $kinds)));
    }

    /** @param list<ContentBox> $boxes */
    private static function only(array $boxes, string $label): string
    {
        if (count($boxes) !== 1) {
            throw new ManifestException(sprintf('assertion %s: %d %s boxes, expected one', $label, count($boxes), $boxes[0]->type));
        }

        return $boxes[0]->data;
    }

    /** The media type of an embedded-file description box: a toggles byte, then the type, NUL-terminated. */
    private static function mediaType(string $bfdb, string $label): string
    {
        $end = strpos($bfdb, "\0", 1);
        if (strlen($bfdb) < 2 || $end === false) {
            throw new ManifestException(sprintf('assertion %s: the embedded-file description has no media type', $label));
        }

        $type = substr($bfdb, 1, $end - 1);

        // SPEC-043 AC3: a media type that is not UTF-8 reads as empty, as c2patool renders it; it goes
        // into the report, which is JSON, and it binds nothing (the assertion's hash covers the box)
        return mb_check_encoding($type, 'UTF-8') ? $type : '';
    }
}
