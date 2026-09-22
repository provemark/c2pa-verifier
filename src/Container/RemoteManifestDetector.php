<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

/**
 * A file with no embedded manifest store may still declare one by URL:
 * XMP `dcterms:provenance` (C2PA 2.4 §11.4, remote manifests). This
 * verifier never fetches it — no network in the verification path — but
 * says that it is there (SPEC-013 amendment 9): the caller must be able to
 * tell "no Content Credentials" from "Content Credentials elsewhere".
 * A note, not a verdict: the first `maxScan` bytes are searched for the
 * attribute, and only an http(s) URL of printable ASCII is reported;
 * anything else is left unreported rather than guessed at.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class RemoteManifestDetector
{
    public const DEFAULT_MAX_SCAN = 8388608;   // 8 MiB: XMP sits in the head of a JPEG and a PNG, and inside a RIFF chunk of a WebP

    public const MAX_URL_LENGTH = 2048;

    public function __construct(private int $maxScan = self::DEFAULT_MAX_SCAN) {}

    /**
     * @param  resource  $stream
     * @return string|null the declared URL, or null when none is declared (or none this verifier would repeat)
     */
    public function detect($stream): ?string
    {
        rewind($stream);
        $head = stream_get_contents($stream, $this->maxScan);
        if ($head === false || preg_match('/dcterms:provenance\s*=\s*"([^"]{1,'.self::MAX_URL_LENGTH.'})"/', $head, $m) !== 1) {
            return null;
        }
        $url = $m[1];
        if (preg_match('#\Ahttps?://[\x21-\x7E]+\z#', $url) !== 1) {
            return null;   // not a URL this verifier would print: unreported, never guessed at
        }

        return $url;
    }
}
