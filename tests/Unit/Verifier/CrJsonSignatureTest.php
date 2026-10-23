<?php

declare(strict_types=1);
use Provemark\C2paVerifier\Cbor\CrJsonValue;
use Provemark\C2paVerifier\Cbor\CrJsonValueException;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\CrJsonDocument;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-076: the signature in crJSON (C2PA 2.4 crJSON §3.7). The oracle is
 * c2patool 0.28.2 --crjson under c2patool/crjson/, with the differences the
 * spec names: upper-case algorithm names, `Z` times, and the time-stamp
 * time c2pa-rs leaves out of six failed tokens. Helpers are SPEC-075's.
 */

/**
 * Each manifest's signature object, ours and c2patool's, for the 59 files.
 *
 * @return list<array{string, string, array<mixed>, array<mixed>}> file, label, ours, theirs
 */
function spec076Pairs(): array
{
    static $pairs = null;
    if (is_array($pairs)) {
        /** @var list<array{string, string, array<mixed>, array<mixed>}> */
        return $pairs;
    }
    $pairs = [];
    foreach (spec075Oracle() as $relative => $theirs) {
        $their = [];
        foreach (spec075Manifests($theirs) as $manifest) {
            $their[spec075S($manifest['label'])] = spec075A($manifest['signature'] ?? null);
        }
        foreach (spec075Manifests(spec075Doc(spec075Report($relative))) as $manifest) {
            $label = spec075S($manifest['label']);
            $pairs[] = [$relative, $label, spec075A($manifest['signature'] ?? null, $label), $their[$label] ?? []];
        }
    }

    return $pairs;
}

/**
 * Where c2pa-rs's timeStampInfo and this verifier's differ by design (amendment 1): a header token
 * this verifier cannot read, and a token from a c2pa.time-stamp assertion (SPEC-064) c2pa-rs does not use.
 */
const SPEC076_TOKEN_DIFFERS = [
    'c2pa-rs/CA_ct.jpg contentauth:urn:uuid:c2677d4b-0a93-4444-876f-ed2f2d40b8cf' => 'theirs only',
    'c2pa-rs/update_manifest.jpg adobe:urn:uuid:6a6b4dbe-5ef9-40ec-81ec-d8955db3e95c' => 'ours only',
];

/** Our certificateInfo without the `E` that c2pa-rs drops, where c2patool's lacks it (amendment 1). */
function spec076WithoutE(mixed $ours, mixed $theirs): mixed
{
    if (! is_array($ours) || ! is_array($theirs)) {
        return $ours;
    }
    foreach (['issuer', 'subject'] as $part) {
        if (is_array($ours[$part] ?? null) && is_array($theirs[$part] ?? null) && ! array_key_exists('E', $theirs[$part])) {
            unset($ours[$part]['E']);
        }
    }

    return $ours;
}

/** A certificateInfo with its times as instants, so that `Z` and `+00:00` compare equal. */
function spec076Instants(mixed $info): mixed
{
    if (! is_array($info) || ! is_array($info['validity'] ?? null)) {
        return $info;
    }
    $info['validity'] = array_map(static fn (mixed $t): mixed => is_string($t) ? (new DateTimeImmutable($t))->getTimestamp() : $t, $info['validity']);

    return $info;
}

// AC1
it('AC1: the algorithm by its C2PA name, equal to c2patool\'s once upper-cased', function (): void {
    $names = [];
    foreach (spec076Pairs() as [$relative, $label, $ours, $theirs]) {
        expect($ours['algorithm'] ?? null)->toBe(strtoupper(spec075S($theirs['algorithm'] ?? null)), "{$relative} {$label}");
        $names[] = spec075S($ours['algorithm'] ?? null);
    }
    expect(array_count_values($names))->toBe(['PS256' => 70, 'ES256' => 21, 'ES384' => 1]);
})->group('SPEC-076');

// AC2
it('AC2: certificateInfo of the leaf, equal to c2patool\'s with times as instants', function (): void {
    $checked = 0;
    foreach (spec076Pairs() as [$relative, $label, $ours, $theirs]) {
        $info = spec075A($ours['certificateInfo'] ?? null, "{$relative} {$label}");
        expect(spec075S($info['serialNumber'] ?? null))->toMatch('/^(0|[1-9a-f][0-9a-f]*)$/')
            ->and(spec075A($info['validity'] ?? null))->toHaveKeys(['notBefore', 'notAfter']);
        foreach (spec075A($info['validity'] ?? null) as $time) {
            expect(spec075S($time))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
        }
        expect(spec076Instants(spec076WithoutE($info, $theirs['certificateInfo'] ?? null)))->toBe(spec076Instants($theirs['certificateInfo'] ?? null), "{$relative} {$label}");
        $checked++;
    }
    expect($checked)->toBe(92);
})->group('SPEC-076');

// AC3
it('AC3: a DN holds the schema\'s eight keys only, in the certificate\'s order, a repeat\'s last value', function (): void {
    $dn = CrJsonDocument::distinguishedName([
        'C' => 'US', 'OU' => ['first', 'second'], 'serialNumber' => '42', 'CN' => 'Signer', 'emailAddress' => 'a@example.org', 'organizationIdentifier' => 'VATNL-1',
    ]);

    expect($dn)->toBe(['C' => 'US', 'OU' => 'second', 'CN' => 'Signer', 'E' => 'a@example.org', '2.5.4.97' => 'VATNL-1']);
})->group('SPEC-076');

// AC4
it('AC4: timeStampInfo wherever the token can be read, its time whether or not it validated', function (): void {
    $present = 0;
    $timed = 0;
    $added = 0;
    $differs = [];
    foreach (spec076Pairs() as [$relative, $label, $ours, $theirs]) {
        $theirTsa = $theirs['timeStampInfo'] ?? null;
        $ourTsa = $ours['timeStampInfo'] ?? null;
        if (($ourTsa === null) !== ($theirTsa === null)) {
            $differs["{$relative} {$label}"] = $ourTsa === null ? 'theirs only' : 'ours only';

            continue;
        }
        if ($ourTsa === null || $theirTsa === null) {
            continue;
        }
        $ourTsa = spec075A($ourTsa);
        $theirTsa = spec075A($theirTsa);
        $present++;
        expect(spec075S($ourTsa['timestamp'] ?? null))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and(spec076Instants(spec076WithoutE($ourTsa['certificateInfo'] ?? null, $theirTsa['certificateInfo'] ?? null)))->toBe(spec076Instants($theirTsa['certificateInfo'] ?? null), "{$relative} {$label}");
        if (array_key_exists('timestamp', $theirTsa)) {
            expect((new DateTimeImmutable(spec075S($ourTsa['timestamp'])))->getTimestamp())->toBe((new DateTimeImmutable(spec075S($theirTsa['timestamp'])))->getTimestamp(), "{$relative} {$label}");
            $timed++;
        } else {
            $added++;
        }
    }
    expect($differs)->toBe(SPEC076_TOKEN_DIFFERS)
        ->and([$present, $timed, $added])->toBe([74, 69, 5]);
})->group('SPEC-076');

it('AC4: a validated, an untrusted and a mismatched token each give their time and their TSA', function (string $probe, string $code): void {
    $base = "timestamp/tsa-matrix/{$probe}";
    $report = spec075Report("{$base}.png", "{$base}.settings.json");
    $codes = array_map(static fn ($s): string => $s->code->value, $report->result->statuses);
    $signature = spec075A(spec075Manifests(spec075Doc($report))[0]['signature'] ?? null);
    $tsa = spec075A($signature['timeStampInfo'] ?? null, $probe);

    expect($codes)->toContain($code)
        ->and(spec075S($tsa['timestamp'] ?? null))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and(spec075A($tsa['certificateInfo'] ?? null))->toHaveKeys(['serialNumber', 'issuer', 'subject', 'validity']);
})->with([
    'validated and trusted' => ['control', 'timeStamp.trusted'],
    'untrusted' => ['expired-signer-untrusted-tsa', 'timeStamp.untrusted'],
    'over the wrong bytes' => ['token-over-wrong-bytes', 'timeStamp.mismatch'],
])->group('SPEC-076');

it('AC4: no token, no timeStampInfo', function (): void {
    $base = 'timestamp/tsa-matrix/no-timestamp';
    $signature = spec075A(spec075Manifests(spec075Doc(spec075Report("{$base}.png", "{$base}.settings.json")))[0]['signature'] ?? null);

    expect($signature)->toHaveKeys(['algorithm', 'certificateInfo'])
        ->and(array_key_exists('timeStampInfo', $signature))->toBeFalse();
})->group('SPEC-076');

/** The fixtures whose claim signature cannot be read (AC5). */
const SPEC076_UNREADABLE = ['cose/chain-empty.png', 'cose/leaf-der-broken.png', 'cose/protected-not-map.png'];

// AC5
it('AC5: a signature that cannot be read is {}', function (string $relative): void {
    $report = spec075Report($relative);
    expect($report->store === null)->toBeFalse($relative);
    $json = $report->toCrJson();

    expect(spec075Manifests(spec075Doc($report))[0]['signature'] ?? null)->toBe([])
        ->and($json)->toContain('"signature": {}');
})->with(SPEC076_UNREADABLE)->group('SPEC-076');

// AC6
it('AC6: every document passes the whole schema but for the named exceptions', function (): void {
    $cases = [
        ...array_map(static fn (string $r): array => [$r, null], array_keys(spec075Oracle())),
        ...array_map(static fn (string $p): array => ["timestamp/tsa-matrix/{$p}.png", "timestamp/tsa-matrix/{$p}.settings.json"], ['control', 'expired-signer-untrusted-tsa', 'token-over-wrong-bytes', 'no-timestamp']),
        ['cose/chain-empty.png', null], ['cose/leaf-der-broken.png', null], ['cose/protected-not-map.png', null],
        ['fixture-unsigned.png', null], ['jumbf/child-overruns.png', null],
    ];
    $documents = 0;
    foreach ($cases as [$relative, $settings]) {
        $report = spec075Report($relative, $settings);
        $manifests = spec075Manifests(spec075Doc($report));
        $v1 = array_filter($manifests, static fn (array $m): bool => array_key_exists('claim', $m)) !== [];
        $unread = in_array($relative, SPEC076_UNREADABLE, true);
        foreach (spec075SchemaErrors($report->toCrJson()) as $error) {
            $accepted = ($v1 && (str_starts_with($error, '/manifests/*/claim :: ') || str_starts_with($error, '/manifests/*/claim/')))
                || ($unread && $error === '/manifests/*/signature :: The required properties (algorithm, certificateInfo) are missing');
            expect($accepted)->toBeTrue("{$relative}: {$error}");
        }
        $documents++;
    }
    expect($documents)->toBe(68);
})->group('SPEC-076');

// SPEC-075 AC10 (amendment 2): CrJsonDocument writes {} for an assertion fromJson() refuses
it('AC10: a JSON assertion is written as it is, where it can be written back the same', function (string $json, ?string $written): void {
    $out = static fn (): string => json_encode(CrJsonValue::fromJson($json), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($written === null) {
        expect($out)->toThrow(CrJsonValueException::class);
    } else {
        expect($out())->toBe($written);
    }
})->with([
    'as it is' => ['{"a": [1, 2.5, 1.0, "x/y", null, true], "b": {}}', '{"a":[1,2.5,1.0,"x/y",null,true],"b":{}}'],
    'the largest 64-bit integer' => ['{"n": 9223372036854775807}', '{"n":9223372036854775807}'],
    'a number beyond a double' => ['{"n": 1e99999999}', null],
    'an integer beyond 64 bits' => ['{"n": 123456789012345678901234567890}', null],
    'a negative integer beyond 64 bits, nested' => ['{"a": [{"n": -99999999999999999999}]}', null],
    'not JSON' => ['{"n": ', null],
    'a key starting with NUL' => ['{"\\u0000n": 1}', null],
])->group('SPEC-075');

// SPEC-076 AC7 (amendment 1)
/**
 * header-wins.png under its own settings, or with the parent's header TSA left out, as crJSON:
 * the parent manifest's timeStampInfo issuer and the url of its timeStamp.trusted.
 *
 * @return array{string, string}
 */
function spec076ParentToken(bool $headerTsaTrusted): array
{
    $settings = spec075A(json_decode((string) file_get_contents(Corpus::fixtures().'/timestamp/assertion/header-wins.settings.json'), true, 512, JSON_THROW_ON_ERROR));
    $trust = spec075A($settings['trust'] ?? null);
    $anchors = spec075A($trust['anchors'] ?? null);
    if (! $headerTsaTrusted) {
        unset($anchors[1]);   // the TSA Matrix tsa-root: the header token's TSA
    }
    $trust['anchors'] = array_values($anchors);
    $settings['trust'] = $trust;
    $stream = fopen(Corpus::fixtures().'/timestamp/assertion/header-wins.png', 'rb');
    assert($stream !== false);
    /** @var array<string, mixed> $settings */
    $report = (new Verifier)->verify($stream, TrustSettings::fromArray($settings), new DateTimeImmutable('2026-10-23T12:00:00Z'));
    $parent = spec075A(spec075A(spec075A(json_decode($report->toCrJson(), true, 512, JSON_THROW_ON_ERROR))['manifests'] ?? null)[1] ?? null);
    $trusted = '';
    foreach (['success', 'informational', 'failure'] as $group) {
        foreach (spec075A(spec075A($parent['validationResults'] ?? null)[$group] ?? null) as $status) {
            $status = spec075A($status);
            if (($status['code'] ?? null) === 'timeStamp.trusted') {
                $trusted = spec075S($status['url'] ?? null);
            }
        }
    }
    $issuer = spec075A(spec075A(spec075A(spec075A($parent['signature'] ?? null)['timeStampInfo'] ?? null)['certificateInfo'] ?? null)['issuer'] ?? null);

    return [spec075S($issuer['CN'] ?? ''), $trusted];
}

it('AC7: timeStampInfo shows the token whose time the verdict used', function (): void {
    [$issuer, $url] = spec076ParentToken(true);
    expect($url)->toEndWith('/c2pa.signature')
        ->and($issuer)->toContain('TSA Matrix');

    [$issuer, $url] = spec076ParentToken(false);
    expect($url)->toEndWith('/c2pa.assertions/c2pa.time-stamp')
        ->and($issuer)->toContain('Throw-away TSA Root');
})->group('SPEC-076');
