<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

/**
 * One entry of `trust.anchors` (SPEC-031), the shape `c2pa` 0.91.0 reads:
 * a kind, the anchors as certificates, the entry's own allowed list and its
 * own EKUs. Every anchor counts only for its own kind (C2PA 2.4 §14.4.1,
 * §14.4.2): a "manifest" entry anchors signers, a "tsa" entry anchors
 * time-stamping authorities, a "cawg" entry anchors nothing here. An
 * entry's `trust_config` widens the accepted EKUs only for a chain that
 * reaches that entry (§14.4.1: anchor configurations per EKU).
 */
final readonly class TrustAnchorSet
{
    public const MANIFEST = 'manifest';

    public const TSA = 'tsa';

    public const CAWG = 'cawg';

    public const KINDS = [self::MANIFEST, self::TSA, self::CAWG];

    /**
     * @param  string  $kind  one of KINDS
     * @param  list<Certificate>  $anchors
     * @param  list<Certificate>  $allowedList  only ever non-empty for a "manifest" entry (§14.4.3)
     * @param  list<string>  $trustConfig  EKU OIDs for chains that reach this entry
     */
    public function __construct(
        public string $kind,
        public array $anchors,
        public array $allowedList = [],
        public array $trustConfig = [],
        public ?string $uri = null,
    ) {
        if (! in_array($kind, self::KINDS, true)) {
            throw new TrustException(sprintf('trust_kind %s is not one of %s', $kind, implode(', ', self::KINDS)));
        }
        if ($kind !== self::MANIFEST && $allowedList !== []) {
            throw new TrustException(sprintf('a "%s" entry cannot carry an allowed_list: the private credential store applies to signers only, never to time-stamps (C2PA 2.4 §14.4.3)', $kind));
        }
    }
}
