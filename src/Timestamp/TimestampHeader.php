<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Timestamp;

use Provemark\C2paVerifier\Cbor\CborBytes;

/**
 * The `sigTst` / `sigTst2` header of a COSE_Sign1 as C2PA 2.4 §14.6 shapes
 * it — `{tstTokens: [{val: bstr}, …]}` — read out of the decoded unprotected
 * header (SPEC-016 AC8). Which name a claim version may carry is SPEC-017's
 * rule; here both are read, and a header carrying both is refused.
 */
final readonly class TimestampHeader
{
    public const DEFAULT_MAX_TOKENS = 8;

    /**
     * @param  'sigTst'|'sigTst2'  $header
     * @param  list<string>  $tokens  the raw values, in header order
     */
    public function __construct(
        public string $header,
        public array $tokens,
    ) {}

    /**
     * @param  array<int|string, mixed>  $unprotected  the decoded unprotected header
     * @return self|null null when neither header is present (no timestamp)
     *
     * @throws TimestampException
     */
    public static function fromUnprotected(array $unprotected, int $maxTokens = self::DEFAULT_MAX_TOKENS): ?self
    {
        $present = array_values(array_filter(['sigTst', 'sigTst2'], static fn (string $name): bool => array_key_exists($name, $unprotected)));
        if ($present === []) {
            return null;
        }
        if (count($present) === 2) {
            throw new TimestampException('the unprotected header carries both sigTst and sigTst2; a signature has one timestamp header');
        }
        $name = $present[0];
        $header = $unprotected[$name];
        if (! is_array($header) || array_is_list($header)) {
            throw new TimestampException(sprintf('%s is not a map', $name));
        }
        $list = $header['tstTokens'] ?? null;
        if (! is_array($list) || ! array_is_list($list)) {
            throw new TimestampException(sprintf('%s: tstTokens is missing or not a list', $name));
        }
        if ($list === []) {
            throw new TimestampException(sprintf('%s: tstTokens is empty', $name));
        }
        if (count($list) > $maxTokens) {
            throw new TimestampException(sprintf('%s holds %d tokens, above the limit of %d', $name, count($list), $maxTokens));
        }
        $tokens = [];
        foreach ($list as $i => $entry) {
            if (! is_array($entry) || ! isset($entry['val']) || ! $entry['val'] instanceof CborBytes) {
                throw new TimestampException(sprintf('%s: tstTokens[%d] has no byte-string val', $name, $i));
            }
            $tokens[] = $entry['val']->bytes;
        }

        return new self($name, $tokens);
    }
}
