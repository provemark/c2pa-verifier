<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

/**
 * Which list a trust anchor set was read from (SPEC-081): its kind, the form
 * read, the SHA-256 of the string exactly as given, and for a LoTE list its
 * `LoTESequenceNumber` and `ListIssueDateTime`. The digest is what
 * identifies: the programme has published versions of a list with different
 * content and the same number and date (measured in `conformance-public`).
 * A number or date that is not one is null, never a value that was not
 * checked (SPEC-081 AC7).
 */
final readonly class TrustListIdentity
{
    public const PEM = 'pem';

    public const LOTE = 'lote';

    /**
     * @param  string  $kind  TrustAnchorSet::MANIFEST or TrustAnchorSet::TSA
     * @param  string  $form  self::PEM or self::LOTE
     * @param  string  $sha256  lowercase hex, of the list as given, a byte order mark included
     * @param  int|null  $sequenceNumber  a LoTE list's, when it is an integer of at least 1
     * @param  string|null  $issueDateTime  a LoTE list's, verbatim, when it is an RFC 3339 date-time
     */
    public function __construct(
        public string $kind,
        public string $form,
        public string $sha256,
        public ?int $sequenceNumber = null,
        public ?string $issueDateTime = null,
    ) {}
}
