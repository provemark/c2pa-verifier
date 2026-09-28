<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * Step 178: AI origin in a parentOf ingredient, built by bin/make-ai-history-variants.php. The
 * oracle is c2patool 0.27.22 beside the file (tests/Fixtures/c2patool/ai-history/).
 */

function aiHistoryVerify(bool $withSettings): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/ai-history/parent-chain.png', 'rb');
    if ($stream === false) {
        throw new RuntimeException('cannot open parent-chain.png');
    }
    $settings = $withSettings
        ? TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/ai-history/both-roots.settings.json'))
        : null;

    return (new Verifier)->verify($stream, $settings);
}

/** The value at $path inside $value, or null when any step is missing. */
function aiHistoryAt(mixed $value, string|int ...$path): mixed
{
    foreach ($path as $key) {
        if (! is_array($value) || ! array_key_exists($key, $value)) {
            return null;
        }
        $value = $value[$key];
    }

    return $value;
}

/**
 * @param  array<mixed>  $report
 * @return list<mixed> the digitalSourceType values of one manifest's actions
 */
function aiHistorySourceTypes(array $report, mixed $label): array
{
    $types = [];
    $assertions = is_string($label) ? aiHistoryAt($report, 'manifests', $label, 'assertions') : null;
    foreach (is_array($assertions) ? $assertions : [] as $assertion) {
        $name = aiHistoryAt($assertion, 'label');
        $actions = aiHistoryAt($assertion, 'data', 'actions');
        if (is_string($name) && str_starts_with($name, 'c2pa.actions') && is_array($actions)) {
            foreach ($actions as $action) {
                $types[] = aiHistoryAt($action, 'digitalSourceType');
            }
        }
    }

    return $types;
}

/**
 * @param  array<mixed>  $report
 * @return list<mixed> the failure codes of the first ingredient delta
 */
function aiHistoryDeltaCodes(array $report): array
{
    $failures = aiHistoryAt($report, 'validation_results', 'ingredientDeltas', 0, 'validationDeltas', 'failure');

    return is_array($failures) ? array_values(array_map(fn (mixed $f): mixed => aiHistoryAt($f, 'code'), $failures)) : [];
}

it('is Trusted with its settings, as c2patool says, and the AI origin sits only in the parent', function (): void {
    $report = aiHistoryVerify(true);
    $array = $report->toArray();
    $oracle = json_decode((string) file_get_contents(Corpus::fixtures().'/c2patool/ai-history/parent-chain.json'), true);
    $active = aiHistoryAt($array, 'active_manifest');
    $ingredient = is_string($active) ? aiHistoryAt($array, 'manifests', $active, 'ingredients', 0) : null;

    expect($report->result->state)->toBe(ValidationState::Trusted)
        ->and(aiHistoryAt($oracle, 'validation_state'))->toBe('Trusted')
        ->and(aiHistoryAt($ingredient, 'relationship'))->toBe('parentOf')
        ->and(aiHistorySourceTypes($array, $active))->toBe(['http://cv.iptc.org/newscodes/digitalsourcetype/algorithmicMedia'])
        ->and(aiHistorySourceTypes($array, aiHistoryAt($ingredient, 'active_manifest')))->toBe(['http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia'])
        ->and(aiHistoryDeltaCodes($array))->toBe([]);
})->group('SPEC-021');

it('is Valid without settings, and only the delta says the ingredient signer is unknown', function (): void {
    $array = aiHistoryVerify(false)->toArray();
    $active = aiHistoryAt($array, 'active_manifest');

    expect(aiHistoryAt($array, 'validation_state'))->toBe('Valid')
        ->and(is_string($active) ? aiHistoryAt($array, 'manifests', $active, 'ingredients', 0, 'validation_results', 'activeManifest', 'failure') : null)->toBe([])
        ->and(aiHistoryDeltaCodes($array))->toBe(['signingCredential.untrusted']);
})->group('SPEC-021');
