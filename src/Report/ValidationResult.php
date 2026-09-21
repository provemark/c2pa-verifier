<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Report;

/**
 * The statuses a run of checks produced, the state they add up to, and the
 * names of the checks that ran (SPEC-010). `toArray()` is c2patool's shape
 * — `validation_status` with the failures and informational statuses (what
 * the sister library reads), `validation_results.activeManifest` with all
 * three kinds, `validation_state` — plus `checks_performed`, the one key
 * c2patool lacks, so that a partial report can never pass for a verdict.
 * An empty report is Invalid: nothing checked is nothing proven.
 */
final readonly class ValidationResult
{
    /**
     * @param  list<ValidationStatus>  $statuses
     * @param  list<string>  $checksPerformed
     */
    private function __construct(
        public array $statuses,
        public ValidationState $state,
        public array $checksPerformed,
    ) {}

    /**
     * @param  list<ValidationStatus>  $statuses
     * @param  list<string>  $checksPerformed
     */
    public static function fromStatuses(array $statuses, array $checksPerformed): self
    {
        // Valid needs at least one success and no failure: an empty report, or
        // one of informational statuses alone, is not a clean one (SPEC-010
        // AC10, SPEC-012 AC10).
        $succeeded = false;
        $failed = false;
        foreach ($statuses as $status) {
            $succeeded = $succeeded || $status->code->isSuccess();
            $failed = $failed || $status->code->isFailure();
        }

        return new self($statuses, $succeeded && ! $failed ? ValidationState::Valid : ValidationState::Invalid, $checksPerformed);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $success = $informational = $failure = [];
        foreach ($this->statuses as $status) {
            if ($status->code->isSuccess()) {
                $success[] = $status->toArray();
            } elseif ($status->code->isInformational()) {
                $informational[] = $status->toArray();
            } else {
                $failure[] = $status->toArray();
            }
        }

        return [
            'validation_status' => $failure,   // failures only, as c2patool 0.27.22 (SPEC-010 amendment 2, measured in step 26)
            'validation_results' => ['activeManifest' => ['success' => $success, 'informational' => $informational, 'failure' => $failure]],
            'validation_state' => $this->state->value,
            'checks_performed' => $this->checksPerformed,
        ];
    }
}
