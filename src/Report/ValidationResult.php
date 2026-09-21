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
        // The three states, as c2patool's JSON shows them (SPEC-014, measured in
        // steps 14 and 30): Trusted = a signingCredential.trusted success and no
        // failure; Valid = at least one success and no failure other than
        // signingCredential.untrusted; Invalid otherwise — an empty report and
        // one of informational statuses alone included (SPEC-010/012 AC10).
        $succeeded = false;
        $trusted = false;
        $failed = false;
        foreach ($statuses as $status) {
            $succeeded = $succeeded || $status->code->isSuccess();
            $trusted = $trusted || $status->code === StatusCode::SigningCredentialTrusted;
            $failed = $failed || ($status->code->isFailure() && $status->code !== StatusCode::SigningCredentialUntrusted);
        }
        $untrusted = false;
        foreach ($statuses as $status) {
            $untrusted = $untrusted || $status->code === StatusCode::SigningCredentialUntrusted;
        }

        return new self($statuses, match (true) {
            ! $succeeded || $failed => ValidationState::Invalid,
            $trusted && ! $untrusted => ValidationState::Trusted,
            default => ValidationState::Valid,
        }, $checksPerformed);
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

        // validation_status holds failures only and is absent when there are
        // none, as c2patool 0.27.22 (SPEC-010 amendment 2, SPEC-013 amendment 3)
        return ($failure === [] ? [] : ['validation_status' => $failure]) + [
            'validation_results' => ['activeManifest' => ['success' => $success, 'informational' => $informational, 'failure' => $failure]],
            'validation_state' => $this->state->value,
            'checks_performed' => $this->checksPerformed,
        ];
    }
}
