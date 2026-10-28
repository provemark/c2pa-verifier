<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

/**
 * The caller's source, asked at most once for each certificate and issuer in one verification (SPEC-086
 * amendment 6): every later need for the same pair — another manifest of the store, a CA question, the
 * Time-Stamping second walk — gets the first answer, the same bytes, the same null or the same exception
 * thrown again. Each manifest still judges the answer itself, at its own time. One is made per verify().
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be removed in any release.
 */
final class RememberedRevocationSource implements RevocationSource
{
    /** @var array<string, array{0: ?string, 1: ?\Throwable}> the answer or the exception, by pair */
    private array $answers = [];

    public function __construct(private readonly RevocationSource $source) {}

    public function ocspResponse(string $subject, string $issuer, array $urls): ?string
    {
        $key = hash('sha256', $subject).hash('sha256', $issuer).hash('sha256', implode("\n", $urls));
        if (! array_key_exists($key, $this->answers)) {
            try {
                $this->answers[$key] = [$this->source->ocspResponse($subject, $issuer, $urls), null];
            } catch (\Throwable $e) {
                $this->answers[$key] = [null, $e];
            }
        }
        [$answer, $thrown] = $this->answers[$key];
        if ($thrown !== null) {
            throw $thrown;
        }

        return $answer;
    }
}
