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
        $failed = $statuses === [];
        foreach ($statuses as $status) {
            $failed = $failed || $status->code->isFailure();
        }

        return new self($statuses, $failed ? ValidationState::Invalid : ValidationState::Valid, $checksPerformed);
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
            'validation_status' => [...$failure, ...$informational],
            'validation_results' => ['activeManifest' => ['success' => $success, 'informational' => $informational, 'failure' => $failure]],
            'validation_state' => $this->state->value,
            'checks_performed' => $this->checksPerformed,
        ];
    }
}
