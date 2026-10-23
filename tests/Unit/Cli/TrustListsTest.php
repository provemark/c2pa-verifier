<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cli\Command;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustException;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-077: the conformance programme's two trust lists as input. The
 * oracle is this verifier's own --settings path (SPEC-031): the lists add
 * a way in, not a check. control.png's two throw-away roots show the
 * separation the official lists, which share 16 anchors, cannot.
 */

const SPEC077_LISTS = 'trust/conformance-public';
const SPEC077_CONTROL = 'timestamp/tsa-matrix/control';

/** @return array{int, string, string} exit status, stdout, stderr */
function spec077Run(string ...$arguments): array
{
    $out = fopen('php://memory', 'w+b');
    $err = fopen('php://memory', 'w+b');
    if ($out === false || $err === false) {
        throw new RuntimeException('cannot open php://memory');
    }
    $status = (new Command(new Verifier))->run(array_values($arguments), $out, $err);
    rewind($out);
    rewind($err);

    return [$status, (string) stream_get_contents($out), (string) stream_get_contents($err)];
}

/** A file in the system's temporary directory holding $contents, removed at shutdown. */
function spec077Temp(string $contents, string $suffix): string
{
    $path = tempnam(sys_get_temp_dir(), 'spec077-');
    if ($path === false) {
        throw new RuntimeException('cannot make a temporary file');
    }
    rename($path, $path.$suffix);
    $path .= $suffix;
    file_put_contents($path, $contents);
    register_shutdown_function(static fn () => @unlink($path));

    return $path;
}

/** The settings document --trust-list and --tsa-trust-list stand for, built from the same two files. */
function spec077Settings(?string $signers, ?string $tsas): string
{
    $anchors = [];
    if ($signers !== null) {
        $anchors[] = ['trust_anchors' => (string) file_get_contents($signers), 'trust_kind' => 'manifest'];
    }
    if ($tsas !== null) {
        $anchors[] = ['trust_anchors' => (string) file_get_contents($tsas), 'trust_kind' => 'tsa'];
    }

    return spec077Temp((string) json_encode(['verify' => ['verify_trust' => true], 'trust' => ['anchors' => $anchors]]), '.json');
}

/**
 * control.png's signer root and TSA root, each as a PEM file.
 *
 * @return array{string, string}
 */
function spec077ControlRoots(): array
{
    /** @var array{trust: array{anchors: list<array{trust_anchors: string, trust_kind: string}>}} $settings */
    $settings = json_decode((string) file_get_contents(Corpus::fixtures().'/'.SPEC077_CONTROL.'.settings.json'), true, 512, JSON_THROW_ON_ERROR);
    $by = array_column($settings['trust']['anchors'], 'trust_anchors', 'trust_kind');

    return [spec077Temp($by['manifest'], '.pem'), spec077Temp($by['tsa'], '.pem')];
}

/** @return list<string> the trust and time-stamp codes of the active manifest */
function spec077TrustCodes(string $json): array
{
    $report = spec075A(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    $active = spec075A(spec075A($report['validation_results'] ?? null)['activeManifest'] ?? null);
    $codes = [];
    foreach (['success', 'informational', 'failure'] as $group) {
        foreach (spec075A($active[$group] ?? null) as $status) {
            $code = spec075S(spec075A($status)['code'] ?? null);
            if (str_starts_with($code, 'signingCredential.trusted') || str_starts_with($code, 'signingCredential.untrusted') || $code === 'timeStamp.trusted' || $code === 'timeStamp.untrusted') {
                $codes[] = $code;
            }
        }
    }
    sort($codes);

    return $codes;
}

// AC1
it('AC1: the lists are the two entries, byte for byte as --settings built from the same files', function (string $relative, ?string $state): void {
    $signers = Corpus::fixtures().'/'.SPEC077_LISTS.'/C2PA-TRUST-LIST.pem';
    $tsas = Corpus::fixtures().'/'.SPEC077_LISTS.'/C2PA-TSA-TRUST-LIST.pem';
    $settings = spec077Settings($signers, $tsas);
    $file = Corpus::fixtures().'/'.$relative;

    // one fixed time for both runs: an explanation names the judged time to the second (an OCSP
    // response "not current" at now), and two runs a moment apart can fall in different seconds
    foreach ([['--at', '2026-10-23T12:00:00Z'], ['--at', '2026-10-23T12:00:00Z', '--crjson']] as $extra) {
        [$listStatus, $listOut, $listErr] = spec077Run(...[...$extra, '--trust-list', $signers, '--tsa-trust-list', $tsas, $file]);
        [$setStatus, $setOut] = spec077Run(...[...$extra, '--settings', $settings, $file]);
        expect($listErr)->toBe('')
            ->and($listStatus)->toBe($setStatus)
            ->and($listOut)->toBe($setOut);
    }
    if ($state !== null) {
        [, $out] = spec077Run('--trust-list', $signers, '--tsa-trust-list', $tsas, $file);
        expect(spec075A(json_decode($out, true))['validation_state'] ?? null)->toBe($state);
    }
})->with([
    'Pixel 10' => ['writers/google-20250919-pixel10-npld-picnic-table.jpg', 'Trusted'],
    'OpenAI' => ['writers/openai-20260826-c2pa_2x.png', 'Trusted'],
    'a test certificate' => ['fixture-signed.png', null],
])->group('SPEC-077');

// AC2
it('AC2: the lists stay apart: control.png in place, then swapped', function (): void {
    [$signerRoot, $tsaRoot] = spec077ControlRoots();
    $file = Corpus::fixtures().'/'.SPEC077_CONTROL.'.png';

    [$status, $out] = spec077Run('--trust-list', $signerRoot, '--tsa-trust-list', $tsaRoot, $file);
    expect($status)->toBe(0)
        ->and(spec075A(json_decode($out, true))['validation_state'] ?? null)->toBe('Trusted')
        ->and(spec077TrustCodes($out))->toBe(['signingCredential.trusted', 'timeStamp.trusted']);

    [$status, $out] = spec077Run('--trust-list', $tsaRoot, '--tsa-trust-list', $signerRoot, $file);
    expect($status)->toBe(0)
        ->and(spec075A(json_decode($out, true))['validation_state'] ?? null)->toBe('Valid')
        ->and(spec077TrustCodes($out))->toBe(['signingCredential.untrusted', 'timeStamp.untrusted']);
})->group('SPEC-077');

// AC3
it('AC3: one list alone anchors its own kind and nothing else', function (): void {
    [$signerRoot, $tsaRoot] = spec077ControlRoots();
    $file = Corpus::fixtures().'/'.SPEC077_CONTROL.'.png';

    [, $out] = spec077Run('--trust-list', $signerRoot, $file);
    expect(spec075A(json_decode($out, true))['validation_state'] ?? null)->toBe('Trusted')
        ->and(spec077TrustCodes($out))->toBe(['signingCredential.trusted', 'timeStamp.untrusted']);

    [, $out] = spec077Run('--tsa-trust-list', $tsaRoot, $file);
    expect(spec075A(json_decode($out, true))['validation_state'] ?? null)->toBe('Valid')
        ->and(spec077TrustCodes($out))->toBe(['signingCredential.untrusted', 'timeStamp.trusted']);
})->group('SPEC-077');

// AC4
it('AC4: the harness call is toCrJson() of verify() with the lists and the time', function (): void {
    [$signerRoot, $tsaRoot] = spec077ControlRoots();
    $relative = SPEC077_CONTROL.'.png';
    [$status, $out, $err] = spec077Run('--crjson', '--trust-list', $signerRoot, '--tsa-trust-list', $tsaRoot, '--at', '2026-10-09T05:31:00Z', Corpus::fixtures().'/'.$relative);

    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    expect($stream)->not->toBeFalse();
    if ($stream === false) {
        return;
    }
    $settings = TrustSettings::fromTrustLists((string) file_get_contents($signerRoot), (string) file_get_contents($tsaRoot));
    $expected = (new Verifier)->verify($stream, $settings, new DateTimeImmutable('2026-10-09T05:31:00Z'))->toCrJson();

    expect($status)->toBe(0)
        ->and($err)->toBe('')
        ->and($out)->toBe($expected."\n")
        ->and(substr_count($out, '"validationTime": "2026-10-09T05:31:00Z"'))->toBe(substr_count($out, '"validationTime"'));
})->group('SPEC-077');

// AC5
it('AC5: what the command line refuses, with exit 2 and no report', function (string $case, string $fault): void {
    [$signerRoot] = spec077ControlRoots();
    $file = Corpus::fixtures().'/'.SPEC077_CONTROL.'.png';
    $settings = Corpus::fixtures().'/'.SPEC077_CONTROL.'.settings.json';
    $arguments = match ($case) {
        'with --settings' => ['--settings', $settings, '--trust-list', $signerRoot, $file],
        'tsa list with --settings' => ['--tsa-trust-list', $signerRoot, '--settings', $settings, $file],
        'twice' => ['--trust-list', $signerRoot, '--trust-list', $signerRoot, $file],
        'last without a path' => [$file, '--tsa-trust-list'],
        'no such file' => ['--trust-list', sys_get_temp_dir().'/spec077-no-such-file.pem', $file],
        'a directory' => ['--trust-list', sys_get_temp_dir(), $file],
        'no certificate' => ['--trust-list', spec077Temp("nothing here\n", '.pem'), $file],
        'not a certificate' => ['--tsa-trust-list', spec077Temp("-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----\n", '.pem'), $file],
        default => throw new LogicException($case),
    };
    [$status, $out, $err] = spec077Run(...$arguments);

    expect($status)->toBe(2)
        ->and($out)->toBe('')
        ->and($err)->toContain(str_contains($case, 'tsa') || $case === 'not a certificate' || $case === 'last without a path' ? '--tsa-trust-list' : '--trust-list')
        ->and($err)->toContain($fault);
})->with([
    'with --settings' => ['with --settings', 'exclude each other'],
    'tsa list with --settings' => ['tsa list with --settings', 'exclude each other'],
    'twice' => ['twice', 'given twice'],
    'last without a path' => ['last without a path', 'needs a path'],
    'no such file' => ['no such file', 'cannot read'],
    'a directory' => ['a directory', 'cannot read'],
    'no certificate' => ['no certificate', 'no certificate'],
    'not a certificate' => ['not a certificate', 'not a CERTIFICATE'],
])->group('SPEC-077');

it('AC5: the library refuses a list without a certificate, and one that is not a certificate', function (): void {
    expect(fn () => TrustSettings::fromTrustLists("nothing here\n"))->toThrow(TrustException::class, 'no certificate')
        ->and(fn () => TrustSettings::fromTrustLists(null, "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----\n"))->toThrow(TrustException::class, 'not a CERTIFICATE')
        ->and(fn () => TrustSettings::fromTrustLists(null, null))->toThrow(TrustException::class, 'no list');
})->group('SPEC-077');
