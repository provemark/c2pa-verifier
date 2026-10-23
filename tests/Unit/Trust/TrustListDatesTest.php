<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cli\Command;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustException;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-079: the programme's JSON trust lists (ETSI TS 119 602 LoTE), with
 * their dates (C2PA 2.4 §14.4.1, §15.7). No implementation reads them, so
 * the criteria rest on the text; the LoTE documents below are built from
 * the tsa-matrix probes' throw-away roots, whose times were measured:
 * control.png is time-stamped at 2026-10-09T05:30:24Z, no-timestamp.png's
 * signer is valid from 2026-10-09T05:30:24Z.
 */

const SPEC079_TRUSTED = 'http://c2pa.org/conformance/trust-list/trusted';
const SPEC079_UNTRUSTED = 'http://c2pa.org/conformance/trust-list/untrusted';
const SPEC079_LISTS = 'trust/conformance-public';

/** @return array{int, string, string} exit status, stdout, stderr */
function spec079Run(string ...$arguments): array
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

function spec079Temp(string $contents, string $suffix): string
{
    $path = tempnam(sys_get_temp_dir(), 'spec079-');
    if ($path === false) {
        throw new RuntimeException('cannot make a temporary file');
    }
    rename($path, $path.$suffix);
    $path .= $suffix;
    file_put_contents($path, $contents);
    register_shutdown_function(static fn () => @unlink($path));

    return $path;
}

/**
 * A probe's anchors from its settings file, as PEM text, by kind.
 *
 * @return array{manifest: string, tsa: string}
 */
function spec079Roots(string $probe): array
{
    $settings = spec075A(json_decode((string) file_get_contents(Corpus::fixtures()."/timestamp/tsa-matrix/{$probe}.settings.json"), true, 512, JSON_THROW_ON_ERROR));
    $by = [];
    foreach (spec075A(spec075A($settings['trust'] ?? null)['anchors'] ?? null) as $entry) {
        $entry = spec075A($entry);
        $by[spec075S($entry['trust_kind'] ?? null)] = spec075S($entry['trust_anchors'] ?? null);
    }

    return ['manifest' => $by['manifest'] ?? '', 'tsa' => $by['tsa'] ?? ''];
}

/** The base64 DER of a PEM's first certificate. */
function spec079Der(string $pem): string
{
    if (preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $pem, $m) !== 1) {
        throw new RuntimeException('no certificate');
    }

    return (string) preg_replace('/\s+/', '', $m[1]);
}

/**
 * A LoTE document, one service per entry: a PEM, its current status and time, and its history.
 *
 * @param  list<array{pem: string, status: ?string, time: ?string, history?: list<array{status: string, time: string, pem?: string}>}>  $services
 */
function spec079Lote(array $services): string
{
    $entities = [];
    foreach ($services as $i => $service) {
        $information = ['ServiceName' => [['lang' => 'en', 'value' => "probe service {$i}"]], 'ServiceDigitalIdentity' => ['X509Certificates' => [['val' => spec079Der($service['pem'])]]]];
        if ($service['status'] !== null) {
            $information['ServiceStatus'] = $service['status'];
        }
        if ($service['time'] !== null) {
            $information['StatusStartingTime'] = $service['time'];
        }
        $entry = ['ServiceInformation' => $information];
        foreach ($service['history'] ?? [] as $old) {
            $instance = ['ServiceName' => [['lang' => 'en', 'value' => "probe service {$i}"]], 'ServiceStatus' => $old['status'], 'StatusStartingTime' => $old['time']];
            if (isset($old['pem'])) {
                $instance['ServiceDigitalIdentity'] = ['X509Certificates' => [['val' => spec079Der($old['pem'])]]];
            }
            $entry['ServiceHistory'][] = $instance;
        }
        $entities[] = ['TrustedEntityInformation' => ['TEName' => [['lang' => 'en', 'value' => "probe {$i}"]]], 'TrustedEntityServices' => [$entry]];
    }

    return spec079Temp((string) json_encode(['LoTE' => ['ListAndSchemeInformation' => ['LoTEVersionIdentifier' => 1], 'TrustedEntitiesList' => $entities]]), '.json');
}

/** @return array{string, list<string>, string} the state, the active manifest's trust and time-stamp codes, and every explanation joined */
function spec079Outcome(string $out): array
{
    $report = spec075A(json_decode($out, true, 512, JSON_THROW_ON_ERROR));
    $active = spec075A(spec075A($report['validation_results'] ?? null)['activeManifest'] ?? null);
    $codes = [];
    $text = '';
    foreach (['success', 'informational', 'failure'] as $group) {
        foreach (spec075A($active[$group] ?? null) as $status) {
            $status = spec075A($status);
            $code = spec075S($status['code'] ?? null);
            if (in_array($code, ['signingCredential.trusted', 'signingCredential.untrusted', 'timeStamp.trusted', 'timeStamp.untrusted'], true)) {
                $codes[] = $code;
            }
            $text .= spec075S($status['explanation'] ?? '')."\n";
        }
    }
    sort($codes);

    return [spec075S($report['validation_state'] ?? null), $codes, $text];
}

/**
 * control.png with the given signer list and its TSA root as a PEM list.
 *
 * @return array{string, list<string>, string}
 */
function spec079Control(string $signerList, ?string $tsaList = null): array
{
    $roots = spec079Roots('control');
    [, $out] = spec079Run('--trust-list', $signerList, '--tsa-trust-list', $tsaList ?? spec079Temp($roots['tsa'], '.pem'), Corpus::fixtures().'/timestamp/tsa-matrix/control.png');

    return spec079Outcome($out);
}

// AC1
it('AC1: the JSON lists give the PEM bundles\' anchors but the three NETLOCK ones, and the same reports', function (): void {
    foreach ([['C2PA-TRUST-LIST', 32, 30], ['C2PA-TSA-TRUST-LIST', 23, 22]] as [$name, $pemCount, $usable]) {
        $pem = (string) file_get_contents(Corpus::fixtures().'/'.SPEC079_LISTS."/{$name}.pem");
        $json = (string) file_get_contents(Corpus::fixtures().'/'.SPEC079_LISTS."/{$name}.json");
        $fromPem = TrustSettings::fromTrustLists($pem)->anchorSets[0];
        $fromJson = TrustSettings::fromTrustLists($json)->anchorSets[0];
        $usableHashes = array_keys(array_filter($fromJson->windows, static fn (array $w): bool => $w !== []));
        expect(count($fromPem->anchors))->toBe($pemCount)
            ->and(count($usableHashes))->toBe($usable)
            ->and(array_diff($usableHashes, array_map(static fn ($c): string => bin2hex($c->sha256), $fromPem->anchors)))->toBe([]);
    }
    foreach (['writers/google-20250919-pixel10-npld-picnic-table.jpg', 'writers/openai-20260826-c2pa_2x.png'] as $relative) {
        $at = ['--at', '2026-10-23T12:00:00Z'];
        [, $viaPem] = spec079Run(...[...$at, '--trust-list', Corpus::fixtures().'/'.SPEC079_LISTS.'/C2PA-TRUST-LIST.pem', '--tsa-trust-list', Corpus::fixtures().'/'.SPEC079_LISTS.'/C2PA-TSA-TRUST-LIST.pem', Corpus::fixtures().'/'.$relative]);
        [, $viaJson] = spec079Run(...[...$at, '--trust-list', Corpus::fixtures().'/'.SPEC079_LISTS.'/C2PA-TRUST-LIST.json', '--tsa-trust-list', Corpus::fixtures().'/'.SPEC079_LISTS.'/C2PA-TSA-TRUST-LIST.json', Corpus::fixtures().'/'.$relative]);
        expect($viaJson)->toBe($viaPem)
            ->and(spec079Outcome($viaJson)[0])->toBe('Trusted');
    }
})->group('SPEC-079');

// AC2
it('AC2: a signer anchor listed after the signature is not used; listed before, it is', function (): void {
    $root = spec079Roots('control')['manifest'];

    [$state, $codes, $text] = spec079Control(spec079Lote([['pem' => $root, 'status' => SPEC079_TRUSTED, 'time' => '2026-10-09T06:00:00Z']]));
    expect($state)->toBe('Valid')
        ->and($codes)->toBe(['signingCredential.untrusted', 'timeStamp.trusted'])
        ->and($text)->toContain('2026-10-09T06:00:00Z')
        ->and($text)->toContain('2026-10-09T05:30:24Z');

    [$state, $codes] = spec079Control(spec079Lote([['pem' => $root, 'status' => SPEC079_TRUSTED, 'time' => '2026-10-09T05:00:00Z']]));
    expect($state)->toBe('Trusted')
        ->and($codes)->toBe(['signingCredential.trusted', 'timeStamp.trusted']);
})->group('SPEC-079');

// AC3
it('AC3: a signer anchor untrusted after the signature still counts; untrusted before, it does not', function (): void {
    $root = spec079Roots('control')['manifest'];
    $history = [['status' => SPEC079_TRUSTED, 'time' => '2026-10-01T00:00:00Z']];

    [$state, $codes] = spec079Control(spec079Lote([['pem' => $root, 'status' => SPEC079_UNTRUSTED, 'time' => '2026-10-09T06:00:00Z', 'history' => $history]]));
    expect($state)->toBe('Trusted')
        ->and($codes)->toBe(['signingCredential.trusted', 'timeStamp.trusted']);

    [$state, $codes] = spec079Control(spec079Lote([['pem' => $root, 'status' => SPEC079_UNTRUSTED, 'time' => '2026-10-09T05:00:00Z', 'history' => $history]]));
    expect($state)->toBe('Valid')
        ->and($codes)->toBe(['signingCredential.untrusted', 'timeStamp.trusted']);
})->group('SPEC-079');

// AC4
it('AC4: without a time-stamp, the validation time decides', function (): void {
    $roots = spec079Roots('no-timestamp');
    $list = spec079Lote([['pem' => $roots['manifest'], 'status' => SPEC079_TRUSTED, 'time' => '2026-10-12T00:00:00Z']]);
    $file = Corpus::fixtures().'/timestamp/tsa-matrix/no-timestamp.png';

    [, $out] = spec079Run('--trust-list', $list, '--at', '2026-10-11T00:00:00Z', $file);
    [, $codes, $text] = spec079Outcome($out);
    expect($codes)->toBe(['signingCredential.untrusted'])
        ->and($text)->not->toContain('not yet valid');

    [, $out] = spec079Run('--trust-list', $list, '--at', '2026-10-13T00:00:00Z', $file);
    expect(spec079Outcome($out)[1])->toBe(['signingCredential.trusted']);
})->group('SPEC-079');

// AC5
it('AC5: a TSA anchor is judged at the token\'s time', function (): void {
    $roots = spec079Roots('control');
    $tsaList = spec079Lote([['pem' => $roots['tsa'], 'status' => SPEC079_TRUSTED, 'time' => '2026-10-09T06:00:00Z']]);

    [, $codes, $text] = spec079Control(spec079Temp($roots['manifest'], '.pem'), $tsaList);
    expect($codes)->toBe(['signingCredential.trusted', 'timeStamp.untrusted'])
        ->and($text)->toContain('2026-10-09T06:00:00Z');

    [, $codes] = spec079Control(spec079Temp($roots['manifest'], '.pem'), spec079Lote([['pem' => $roots['tsa'], 'status' => SPEC079_TRUSTED, 'time' => '2026-10-09T05:00:00Z']]));
    expect($codes)->toBe(['signingCredential.trusted', 'timeStamp.trusted']);
})->group('SPEC-079');

// AC6
it('AC6: an entry that cannot be placed is not used, and the report says why', function (?string $status, ?string $time, bool $otherInHistory, string $why): void {
    $roots = spec079Roots('control');
    // a second, usable service, so that the list itself is not refused
    $other = spec079Roots('no-timestamp')['manifest'];
    $history = $otherInHistory ? [['status' => SPEC079_TRUSTED, 'time' => '2026-09-01T00:00:00Z', 'pem' => $other]] : [];
    $list = spec079Lote([
        ['pem' => $roots['manifest'], 'status' => $status, 'time' => $time, 'history' => $history],
        ['pem' => $other, 'status' => SPEC079_TRUSTED, 'time' => '2026-01-01T00:00:00Z'],
    ]);

    [$state, $codes, $text] = spec079Control($list);
    expect($state)->toBe('Valid')
        ->and($codes)->toBe(['signingCredential.untrusted', 'timeStamp.trusted'])
        ->and($text)->toContain($why);
})->with([
    'an empty status' => ['', '', false, 'empty'],
    'no status at all' => [null, '2026-10-01T00:00:00Z', false, 'no status'],
    'an unknown status' => ['http://example.org/granted', '2026-10-01T00:00:00Z', false, 'http://example.org/granted'],
    'no starting time' => [SPEC079_TRUSTED, null, false, 'no starting time'],
    'a malformed time' => [SPEC079_TRUSTED, '1 October 2026', false, '1 October 2026'],
    'a history entry with other certificates' => [SPEC079_TRUSTED, '2026-10-01T00:00:00Z', true, 'other certificates'],
])->group('SPEC-079');

it('AC6: a list in which no entry is usable is refused, like a PEM file without a certificate', function (): void {
    $root = spec079Roots('control')['manifest'];
    $list = spec079Lote([['pem' => $root, 'status' => '', 'time' => '']]);
    [$status, $out, $err] = spec079Run('--trust-list', $list, Corpus::fixtures().'/timestamp/tsa-matrix/control.png');

    expect($status)->toBe(2)
        ->and($out)->toBe('')
        ->and($err)->toContain('no usable anchor');
    expect(fn () => TrustSettings::fromTrustLists((string) file_get_contents($list)))->toThrow(TrustException::class, 'no usable anchor');
})->group('SPEC-079');

// AC7
it('AC7: malformed JSON lists are refused, naming the option and the fault', function (string $contents, string $fault): void {
    [$status, $out, $err] = spec079Run('--tsa-trust-list', spec079Temp($contents, '.json'), Corpus::fixtures().'/timestamp/tsa-matrix/control.png');

    expect($status)->toBe(2)
        ->and($out)->toBe('')
        ->and($err)->toContain('--tsa-trust-list')
        ->and($err)->toContain($fault);
    expect(fn () => TrustSettings::fromTrustLists(null, $contents))->toThrow(TrustException::class, $fault);
})->with([
    'not JSON after all' => ['{"LoTE": ', 'not valid JSON'],
    'JSON without LoTE' => ['{"trust": {}}', 'not a List of Trusted Entities'],
    'a value that is not base64' => ['{"LoTE": {"TrustedEntitiesList": [{"TrustedEntityServices": [{"ServiceInformation": {"ServiceDigitalIdentity": {"X509Certificates": [{"val": "%%%"}]}, "ServiceStatus": "http://c2pa.org/conformance/trust-list/trusted", "StatusStartingTime": "2026-01-01T00:00:00Z"}}]}]}}', 'base64'],
    'a value that is not a certificate' => ['{"LoTE": {"TrustedEntitiesList": [{"TrustedEntityServices": [{"ServiceInformation": {"ServiceDigitalIdentity": {"X509Certificates": [{"val": "AAAA"}]}, "ServiceStatus": "http://c2pa.org/conformance/trust-list/trusted", "StatusStartingTime": "2026-01-01T00:00:00Z"}}]}]}}', 'could not be read'],
])->group('SPEC-079');
