<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Timestamp;

use Provemark\C2paVerifier\Report\StatusCode;

/**
 * One time-stamp token offered for a manifest, and what became of it
 * (SPEC-084): from the claim signature's `sigTst` or `sigTst2` header, or
 * from a `c2pa.time-stamp` assertion (C2PA 2.4 §10.3.2.5, §18.18). The token
 * is the file's own bytes; its time and the certificates inside it are what
 * could be read of it. It was used when it gave the time the signer was
 * judged at (§15.8.2), rejected when it was judged and did not, and not tried
 * when an earlier token already gave the time (§15.8.1's order) or the claim
 * signature could not be read (SPEC-084 amendment 1).
 */
final readonly class TimestampEvidence
{
    public const SIG_TST = 'sigTst';

    public const SIG_TST2 = 'sigTst2';

    public const ASSERTION = 'assertion';

    public const USED = 'used';

    public const REJECTED = 'rejected';

    public const NOT_TRIED = 'notTried';

    /**
     * @param  string  $source  self::SIG_TST, self::SIG_TST2 or self::ASSERTION
     * @param  string  $url  the claim signature's for a header token, the assertion's for an assertion token
     * @param  string  $token  DER, as the file carries it
     * @param  int|null  $genTime  seconds since the epoch; null when the token cannot be read
     * @param  list<string>  $certificates  DER, in the token's SignedData order; empty when the token cannot be read
     * @param  string  $outcome  self::USED, self::REJECTED or self::NOT_TRIED
     * @param  list<StatusCode>  $codes  the timeStamp.* codes the check gave this token, in order, before the report scopes or drops any; empty when not tried
     */
    public function __construct(
        public string $source,
        public string $url,
        public string $token,
        public ?int $genTime,
        public array $certificates,
        public string $outcome,
        public array $codes,
    ) {}
}
