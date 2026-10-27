<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

/**
 * What made a chain trusted (SPEC-082): the anchor it reached, or the leaf on
 * the allowed list (C2PA 2.4 §14.4.1, §14.4.3, §15.7), as the walk itself
 * found it. The SHA-256 identifies the certificate where a common name does
 * not: two of the test roots share one subject and differ in key. The list is
 * the one that made the anchor count (SPEC-081), null for the legacy
 * `trust_anchors` and for a settings document; the stretch is the part of
 * that list's dates (SPEC-079) that held the time judged at.
 */
final readonly class AnchorEvidence
{
    public const ANCHOR = 'anchor';

    public const ALLOWED_LIST = 'allowedList';

    /**
     * @param  string  $route  self::ANCHOR or self::ALLOWED_LIST
     * @param  string  $sha256  lowercase hex, of the anchor's DER, or of the listed leaf's
     * @param  int  $depth  links from the leaf to the anchor: 0 when the leaf is itself an anchor or on the allowed list
     * @param  int|null  $trustedFrom  null for an open start, or a list without dates
     * @param  int|null  $trustedUntil  null for an open end, or a list without dates
     */
    public function __construct(
        public string $route,
        public string $sha256,
        public int $depth,
        public ?TrustListIdentity $list,
        public ?int $trustedFrom,
        public ?int $trustedUntil,
    ) {}
}
