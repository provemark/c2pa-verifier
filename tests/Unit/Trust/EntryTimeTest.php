<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\Certificate;
use Provemark\C2paVerifier\Trust\CertificateProfileCheck;
use Provemark\C2paVerifier\Trust\ChainCheck;
use Provemark\C2paVerifier\Trust\TrustAnchorSet;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-079 amendment 2 (step 392): whether a chain reaches an entry, for
 * the EKUs of its trust_config (SPEC-031 AC8) and for the note naming an
 * entry of the other kind (SPEC-031 AC6), is judged at the time the trust
 * check uses and with the entry's dates, as the trust check itself.
 */

/** control.png's throw-away signer root, as a chain of one: it is its own anchor. */
function spec079Root(): Certificate
{
    $settings = json_decode((string) file_get_contents(Corpus::fixtures().'/timestamp/tsa-matrix/control.settings.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach (spec075A(spec075A(spec075A($settings)['trust'] ?? null)['anchors'] ?? null) as $entry) {
        $entry = spec075A($entry);
        if (($entry['trust_kind'] ?? null) === 'manifest') {
            return TrustSettings::certificatesFromPem(spec075S($entry['trust_anchors'] ?? null), 'probe', 4)[0];
        }
    }
    throw new RuntimeException('no manifest root');
}

/**
 * An entry holding the root, trusted until 2026-10-09T05:00:00Z, or for good when $dated is false.
 *
 * @param  list<string>  $trustConfig
 */
function spec079Entry(string $kind, bool $dated, array $trustConfig = []): TrustAnchorSet
{
    $root = spec079Root();
    $windows = $dated ? [bin2hex($root->sha256) => [['from' => PHP_INT_MIN, 'until' => strtotime('2026-10-09T05:00:00Z')]]] : [];

    return new TrustAnchorSet($kind, [$root], trustConfig: $trustConfig, windows: $windows);
}

// AC8 (amendment 2, EKU choice)
it('AC8: an entry\'s EKUs count only where the chain reaches it at the judged time, with its dates', function (bool $dated, string $at, bool $counts): void {
    $settings = new TrustSettings([], [], [], true, [spec079Entry(TrustAnchorSet::MANIFEST, $dated, ['1.2.3.4'])]);

    expect(in_array('1.2.3.4', (new CertificateProfileCheck)->acceptedEkus([spec079Root()], $settings, (int) strtotime($at)), true))->toBe($counts);
})->with([
    'undated, judged in 2026' => [false, '2026-10-09T05:30:00Z', true],
    'trusted until 05:00, judged before' => [true, '2026-10-09T04:59:59Z', true],
    'trusted until 05:00, judged at 05:00' => [true, '2026-10-09T05:00:00Z', false],
    'trusted until 05:00, judged later' => [true, '2026-10-09T05:30:00Z', false],
])->group('SPEC-079');

it('AC8: the note on an entry of the other kind names it only where the chain reaches it then, with its dates', function (bool $dated, string $at, bool $named): void {
    $settings = new TrustSettings([], [], [], true, [spec079Entry(TrustAnchorSet::TSA, $dated)]);

    expect(ChainCheck::kindNote($settings, TrustAnchorSet::MANIFEST, [spec079Root()], (int) strtotime($at)) !== '')->toBe($named);
})->with([
    'undated' => [false, '2026-10-09T05:30:00Z', true],
    'trusted until 05:00, judged before' => [true, '2026-10-09T04:59:59Z', true],
    'trusted until 05:00, judged later' => [true, '2026-10-09T05:30:00Z', false],
])->group('SPEC-079');

/** Every entry of $settings, its anchors trusted until $until and not after; $kind relabels them. */
function spec079Until(TrustSettings $settings, string $until, ?string $kind = null): TrustSettings
{
    $sets = [];
    foreach ($settings->anchorSets as $set) {
        $windows = [];
        foreach ($set->anchors as $anchor) {
            $windows[bin2hex($anchor->sha256)] = [['from' => PHP_INT_MIN, 'until' => (int) strtotime($until)]];
        }
        $sets[] = new TrustAnchorSet($kind ?? $set->kind, $set->anchors, $kind === null ? $set->allowedList : [], $set->trustConfig, $set->uri, $windows);
    }

    return new TrustSettings($settings->trustAnchors, $settings->allowedList, $settings->trustConfig, $settings->verifyTrust, $sets);
}

function spec079At(string $relative, TrustSettings $settings, ?string $at): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }

    return (new Verifier)->verify($stream, $settings, $at === null ? null : new DateTimeImmutable($at));
}

/** @return list<string> the active manifest's explanations of statuses with this code */
function spec079Explanations(VerificationReport $report, string $code): array
{
    $found = [];
    foreach ($report->result->statuses as $status) {
        if ($status->code->value === $code) {
            $found[] = $status->explanation;
        }
    }

    return $found;
}

it('AC8: the verifier judges an entry\'s EKUs and the other-kind note at the time it judges trust', function (): void {
    // eku-probe.jpg: its leaf's EKU counts only through e4's own trust_config; no time-stamp, so the validation time judges
    $e4 = TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/trust/anchors/e4-entry-own-config.settings.json'));
    $report = spec079At('trust/anchors/eku-probe.jpg', spec079Until($e4, '2026-10-10T00:00:00Z'), '2026-10-01T00:00:00Z');
    expect($report->result->state->value)->toBe('Trusted');

    // the same root as a "tsa" entry: the untrusted signer is told the chain reaches it, at that time
    $report = spec079At('trust/anchors/eku-probe.jpg', spec079Until($e4, '2026-10-10T00:00:00Z', TrustAnchorSet::TSA), '2026-10-01T00:00:00Z');
    expect(implode("\n", spec079Explanations($report, 'signingCredential.untrusted')))->toContain('trust_kind');

    // C.jpg's TSA chain reaches DigiCert's root as a "manifest" entry at the token's time, before the entry's end
    $digicert = TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/trust/anchors/digicert-as-manifest.settings.json'));
    $report = spec079At('c2pa-rs/C.jpg', spec079Until($digicert, '2026-01-01T00:00:00Z'), null);
    expect(implode("\n", spec079Explanations($report, 'timeStamp.untrusted')))->toContain('trust_kind');
})->group('SPEC-079');
