<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationResult;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Timestamp\TimestampEvidence;
use Provemark\C2paVerifier\Trust\AnchorEvidence;
use Provemark\C2paVerifier\Trust\OcspEvidence;
use Provemark\C2paVerifier\Trust\TrustListIdentity;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\ManifestEvidence;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-089: the evidence as a document to keep. VerificationReport::evidenceToArray() and evidenceToJson() write
 * trustLists() and evidence() down in the format docs/evidence-format.md describes, version 1.
 */

const SPEC089_CONTROL = 'timestamp/tsa-matrix/control.png';

const SPEC089_OPENAI = 'writers/openai-20260826-c2pa_2x.png';

function spec089Verify(string $relative, ?TrustSettings $settings = null, ?DateTimeInterface $at = null): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }
    try {
        return (new Verifier)->verify($stream, $settings, $at);
    } finally {
        fclose($stream);
    }
}

function spec089VerifyBytes(string $bytes): VerificationReport
{
    $stream = fopen('php://memory', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('cannot open memory');
    }
    fwrite($stream, $bytes);
    rewind($stream);
    try {
        return (new Verifier)->verify($stream);
    } finally {
        fclose($stream);
    }
}

function spec089Settings(string $relative): TrustSettings
{
    return TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/'.$relative));
}

/** The control probe's two roots as trust lists, signers first (as SPEC-082 AC2 builds them). */
function spec089Lists(): TrustSettings
{
    $json = json_decode((string) file_get_contents(Corpus::fixtures().'/timestamp/tsa-matrix/control.settings.json'), true, 512, JSON_THROW_ON_ERROR);
    $by = [];
    foreach (is_array($json) && is_array($json['trust'] ?? null) && is_array($json['trust']['anchors'] ?? null) ? $json['trust']['anchors'] : [] as $entry) {
        if (is_array($entry) && is_string($entry['trust_kind'] ?? null) && is_string($entry['trust_anchors'] ?? null)) {
            $by[$entry['trust_kind']] = $entry['trust_anchors'];
        }
    }

    return TrustSettings::fromTrustLists($by['manifest'] ?? '', $by['tsa'] ?? '');
}

/** @return array<string, mixed> */
function spec089Map(mixed $value): array
{
    if (! is_array($value)) {
        throw new RuntimeException('not a map: '.get_debug_type($value));
    }
    $out = [];
    foreach ($value as $k => $v) {
        $out[(string) $k] = $v;
    }

    return $out;
}

/** @return list<mixed> */
function spec089List(mixed $value): array
{
    if (! is_array($value) || ! array_is_list($value)) {
        throw new RuntimeException('not a list: '.get_debug_type($value));
    }

    return $value;
}

/**
 * @param  array<string, mixed>  $document
 * @return array<string, mixed> the active manifest's entry
 */
function spec089Active(array $document): array
{
    $label = $document['active_manifest'] ?? null;

    return spec089Map(spec089Map($document['manifests'] ?? null)[is_string($label) ? $label : ''] ?? null);
}

/**
 * Where a value departs from the shape docs/evidence-format.md describes: every key present, no other, each of its
 * type (AC7). Version 1.
 *
 * @return list<string>
 */
function spec089ShapeFaults(mixed $document): array
{
    $faults = [];
    $keys = static function (mixed $value, array $expected, string $where) use (&$faults): ?array {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $faults[] = "{$where}: not an object";

            return null;
        }
        $got = array_map(static fn (int|string $k): string => (string) $k, array_keys($value));
        if ($got !== $expected) {
            $faults[] = "{$where}: keys ".json_encode($got).', expected '.json_encode($expected);
        }

        return $value;
    };
    $type = static function (mixed $value, string $kind, string $where) use (&$faults): void {
        $ok = match ($kind) {
            'string' => is_string($value),
            '?string' => $value === null || is_string($value),
            'int' => is_int($value),
            '?int' => $value === null || is_int($value),
            'base64' => is_string($value) && $value !== '' && base64_decode($value, true) !== false && base64_encode((string) base64_decode($value, true)) === $value,
            default => false,
        };
        if (! $ok) {
            $faults[] = "{$where}: not {$kind}";
        }
    };
    $list = static function (mixed $value, string $where) use (&$faults): array {
        if (! is_array($value) || ! array_is_list($value)) {
            $faults[] = "{$where}: not a list";

            return [];
        }

        return $value;
    };
    $identity = static function (mixed $value, string $where) use ($keys, $type): void {
        $v = $keys($value, ['kind', 'form', 'sha256', 'sequence_number', 'issue_date_time'], $where);
        if ($v !== null) {
            $type($v['kind'], 'string', "{$where}.kind");
            $type($v['form'], 'string', "{$where}.form");
            $type($v['sha256'], 'string', "{$where}.sha256");
            $type($v['sequence_number'], '?int', "{$where}.sequence_number");
            $type($v['issue_date_time'], '?string', "{$where}.issue_date_time");
        }
    };
    $anchor = static function (mixed $value, string $where) use ($keys, $type, $identity): void {
        if ($value === null) {
            return;
        }
        $v = $keys($value, ['route', 'sha256', 'depth', 'list', 'trusted_from', 'trusted_until'], $where);
        if ($v !== null) {
            $type($v['route'], 'string', "{$where}.route");
            $type($v['sha256'], 'string', "{$where}.sha256");
            $type($v['depth'], 'int', "{$where}.depth");
            if ($v['list'] !== null) {
                $identity($v['list'], "{$where}.list");
            }
            $type($v['trusted_from'], '?int', "{$where}.trusted_from");
            $type($v['trusted_until'], '?int', "{$where}.trusted_until");
        }
    };

    $top = $keys($document, ['format', 'version', 'verifier', 'validation_state', 'active_manifest', 'trust_lists', 'manifests'], 'document');
    if ($top === null) {
        return $faults;
    }
    if ($top['format'] !== 'c2pa-verifier/evidence' || $top['version'] !== 1) {
        $faults[] = 'document: format or version';
    }
    $type($top['verifier'], 'string', 'verifier');
    $type($top['validation_state'], '?string', 'validation_state');
    $type($top['active_manifest'], '?string', 'active_manifest');
    foreach ($list($top['trust_lists'], 'trust_lists') as $i => $entry) {
        $identity($entry, "trust_lists[{$i}]");
    }
    if (! is_array($top['manifests']) || ($top['manifests'] !== [] && array_is_list($top['manifests']))) {
        $faults[] = 'manifests: not an object';

        return $faults;
    }
    foreach ($top['manifests'] as $label => $manifest) {
        $m = $keys($manifest, ['judged_at', 'time_source', 'signer', 'tsa', 'certificates', 'timestamps', 'ocsp'], "manifests[{$label}]");
        if ($m === null) {
            continue;
        }
        $type($m['judged_at'], 'int', "{$label}.judged_at");
        if (! in_array($m['time_source'], [ManifestEvidence::TIMESTAMP, ManifestEvidence::GIVEN, ManifestEvidence::CLOCK], true)) {
            $faults[] = "{$label}.time_source";
        }
        $anchor($m['signer'], "{$label}.signer");
        $anchor($m['tsa'], "{$label}.tsa");
        foreach ($list($m['certificates'], "{$label}.certificates") as $i => $der) {
            $type($der, 'base64', "{$label}.certificates[{$i}]");
        }
        foreach ($list($m['timestamps'], "{$label}.timestamps") as $i => $t) {
            $t = $keys($t, ['source', 'url', 'token', 'gen_time', 'certificates', 'outcome', 'codes'], "{$label}.timestamps[{$i}]");
            if ($t === null) {
                continue;
            }
            $type($t['source'], 'string', "{$label}.timestamps[{$i}].source");
            $type($t['url'], 'string', "{$label}.timestamps[{$i}].url");
            $type($t['token'], 'base64', "{$label}.timestamps[{$i}].token");
            $type($t['gen_time'], '?int', "{$label}.timestamps[{$i}].gen_time");
            foreach ($list($t['certificates'], "{$label}.timestamps[{$i}].certificates") as $j => $der) {
                $type($der, 'base64', "{$label}.timestamps[{$i}].certificates[{$j}]");
            }
            $type($t['outcome'], 'string', "{$label}.timestamps[{$i}].outcome");
            foreach ($list($t['codes'], "{$label}.timestamps[{$i}].codes") as $j => $code) {
                $type($code, 'string', "{$label}.timestamps[{$i}].codes[{$j}]");
            }
        }
        foreach ($list($m['ocsp'], "{$label}.ocsp") as $i => $o) {
            $o = $keys($o, ['source', 'url', 'response', 'outcome', 'code', 'cert_status', 'produced_at', 'this_update', 'next_update'], "{$label}.ocsp[{$i}]");
            if ($o === null) {
                continue;
            }
            $type($o['source'], 'string', "{$label}.ocsp[{$i}].source");
            $type($o['url'], 'string', "{$label}.ocsp[{$i}].url");
            $type($o['response'], 'base64', "{$label}.ocsp[{$i}].response");
            $type($o['outcome'], 'string', "{$label}.ocsp[{$i}].outcome");
            $type($o['code'], '?string', "{$label}.ocsp[{$i}].code");
            $type($o['cert_status'], '?string', "{$label}.ocsp[{$i}].cert_status");
            foreach (['produced_at', 'this_update', 'next_update'] as $k) {
                $type($o[$k], '?int', "{$label}.ocsp[{$i}].{$k}");
            }
        }
    }

    return $faults;
}

it('AC1: the document for a trusted, time-stamped signer', function (): void {
    $report = spec089Verify(SPEC089_CONTROL, spec089Settings('timestamp/tsa-matrix/control.settings.json'));
    $document = $report->evidenceToArray();
    $label = $report->store->active->label ?? '';
    $evidence = $report->evidence()[$label];
    $active = spec089Active($document);
    $signer = spec089Map($active['signer'] ?? null);

    expect(spec089ShapeFaults($document))->toBe([])
        ->and([$document['format'], $document['version'], $document['verifier'], $document['validation_state'], $document['active_manifest'], $document['trust_lists']])
        ->toBe(['c2pa-verifier/evidence', 1, Verifier::VERSION, 'Trusted', $label, []])
        ->and(array_keys(spec089Map($document['manifests'])))->toBe(array_keys($report->evidence()))
        ->and([$active['judged_at'], $active['time_source']])->toBe([$evidence->judgedAt, 'timestamp'])
        ->and($signer)->toBe(['route' => 'anchor', 'sha256' => $evidence->signer?->sha256, 'depth' => 2, 'list' => null, 'trusted_from' => null, 'trusted_until' => null])
        ->and(spec089Map($active['tsa'] ?? null)['sha256'] ?? null)->toBe($evidence->tsa?->sha256)
        ->and(count(spec089List($active['certificates'])))->toBe(2)
        ->and(count(spec089List($active['timestamps'])))->toBe(1)
        ->and($active['ocsp'])->toBe([]);
})->group('SPEC-089');

it('AC1 (amendment 1): the trust lists, each with its identity, and the anchor\'s list', function (): void {
    $report = spec089Verify(SPEC089_CONTROL, spec089Lists());
    $document = $report->evidenceToArray();
    [$signers, $tsas] = $report->trustLists();
    $identity = static fn (TrustListIdentity $l): array => ['kind' => $l->kind, 'form' => $l->form, 'sha256' => $l->sha256, 'sequence_number' => $l->sequenceNumber, 'issue_date_time' => $l->issueDateTime];

    expect(spec089ShapeFaults($document))->toBe([])
        ->and($document['trust_lists'])->toBe([$identity($signers), $identity($tsas)])
        ->and(spec089Map(spec089Active($document)['signer'] ?? null)['list'] ?? null)->toBe($identity($signers))
        ->and(spec089Map(spec089Active($document)['tsa'] ?? null)['list'] ?? null)->toBe($identity($tsas));
})->group('SPEC-089');

// Written after the build, when mutation M9 (manifests reversed) survived: one manifest cannot show an order.
it('AC1: the manifests in the order evidence() gives, the active one first', function (): void {
    $report = spec089Verify('c2pa-rs/CACA.jpg', spec089Settings('trust/full.settings.json'));
    $document = $report->evidenceToArray();

    expect(count($report->evidence()))->toBe(2)
        ->and(array_keys(spec089Map($document['manifests'])))->toBe(array_keys($report->evidence()))
        ->and(array_key_first(spec089Map($document['manifests'])))->toBe($document['active_manifest'])
        ->and(spec089ShapeFaults($document))->toBe([]);
})->group('SPEC-089');

it('AC2: the bytes are the bytes', function (string $relative, ?string $settings, ?string $at): void {
    $report = spec089Verify($relative, $settings === null ? null : spec089Settings($settings), $at === null ? null : new DateTimeImmutable($at));
    $document = $report->evidenceToArray();
    $decode = static fn (mixed $b64): string => base64_decode(is_string($b64) ? $b64 : '', true) ?: '';
    $seen = 0;
    foreach ($report->evidence() as $label => $evidence) {
        $entry = spec089Map(spec089Map($document['manifests'])[$label] ?? null);
        expect(array_map($decode, spec089List($entry['certificates'])))->toBe($evidence->certificates);
        foreach ($evidence->timestamps as $i => $t) {
            $written = spec089Map(spec089List($entry['timestamps'])[$i] ?? null);
            expect($decode($written['token'] ?? null))->toBe($t->token)
                ->and(array_map($decode, spec089List($written['certificates'] ?? null)))->toBe($t->certificates);
            $seen++;
        }
        foreach ($evidence->ocsp as $i => $o) {
            expect($decode(spec089Map(spec089List($entry['ocsp'])[$i] ?? null)['response'] ?? null))->toBe($o->response);
            $seen++;
        }
        $seen += count($evidence->certificates);
    }

    expect($seen)->toBeGreaterThan(2);
})->with([
    'certificates and a token' => [SPEC089_CONTROL, 'timestamp/tsa-matrix/control.settings.json', null],
    'a stapled response' => [SPEC089_OPENAI, null, '2026-08-26T12:00:00Z'],
])->group('SPEC-089');

it('AC3: what was not trusted is null, not absent', function (): void {
    $untrusted = spec089Verify(SPEC089_CONTROL)->evidenceToArray();
    $none = spec089Verify('fixture-unsigned.png');

    expect(spec089ShapeFaults($untrusted))->toBe([])
        ->and($untrusted['trust_lists'])->toBe([])
        ->and(array_key_exists('signer', spec089Active($untrusted)) && spec089Active($untrusted)['signer'] === null)->toBeTrue()
        ->and(array_key_exists('tsa', spec089Active($untrusted)) && spec089Active($untrusted)['tsa'] === null)->toBeTrue()
        ->and(spec089ShapeFaults($none->evidenceToArray()))->toBe([])
        ->and([$none->evidenceToArray()['active_manifest'], $none->evidenceToArray()['manifests']])->toBe([null, []])
        ->and($none->evidenceToJson())->toContain('"manifests": {}');
})->group('SPEC-089');

it('AC4: codes by name', function (): void {
    $time = spec089List(spec089Active(spec089Verify(SPEC089_CONTROL, spec089Settings('timestamp/tsa-matrix/control.settings.json'))->evidenceToArray())['timestamps']);
    $ocsp = spec089List(spec089Active(spec089Verify(SPEC089_OPENAI, null, new DateTimeImmutable('2026-08-26T12:00:00Z'))->evidenceToArray())['ocsp']);
    $first = spec089Map($ocsp[0] ?? null);

    expect(spec089Map($time[0] ?? null)['codes'] ?? null)->toBe([StatusCode::TimeStampValidated->value, StatusCode::TimeStampTrusted->value])
        ->and(spec089Map($time[0] ?? null)['outcome'] ?? null)->toBe(TimestampEvidence::USED)
        ->and([$first['source'] ?? null, $first['outcome'] ?? null, $first['code'] ?? null, $first['cert_status'] ?? null])
        ->toBe([OcspEvidence::RVALS, OcspEvidence::CHOSEN, 'signingCredential.ocsp.notRevoked', 'good']);
})->group('SPEC-089');

it('AC5: deterministic', function (string $relative, ?string $settings): void {
    $at = new DateTimeImmutable('2026-10-28T12:00:00Z');
    $once = spec089Verify($relative, $settings === null ? null : spec089Settings($settings), $at)->evidenceToJson();
    $twice = spec089Verify($relative, $settings === null ? null : spec089Settings($settings), $at)->evidenceToJson();

    expect($once)->toBe($twice)
        ->and(json_decode($once, true, 512, JSON_THROW_ON_ERROR))->toBe(spec089Verify($relative, $settings === null ? null : spec089Settings($settings), $at)->evidenceToArray());
})->with([
    'trusted, time-stamped' => [SPEC089_CONTROL, 'timestamp/tsa-matrix/control.settings.json'],
    'a stapled response, no settings' => [SPEC089_OPENAI, null],
    'no manifest' => ['fixture-unsigned.png', null],
])->group('SPEC-089');

it('AC6: a label the JUMBF reader refuses never reaches the document', function (): void {
    // control characters and text that is not UTF-8 are refused in a label (SPEC-005), so nothing of them is judged
    $report = spec089Verify('jumbf/label-control.png');

    expect($report->evidence())->toBe([])
        ->and(spec089ShapeFaults($report->evidenceToArray()))->toBe([])
        ->and(json_decode($report->evidenceToJson(), true, 512, JSON_THROW_ON_ERROR))->toBe($report->evidenceToArray());
})->group('SPEC-089');

it('AC6 (amendment 1): a label with a bidi override, a quote and a backslash is refused before any evidence', function (): void {
    // the JUMBF reader allows these characters; the manifest reader then requires a C2PA or uuid URN, whose free
    // part is printable ASCII without a colon (C2PA 2.4 §8.1), so of the three only the quote and the backslash
    // could ever reach the document, and only in a longer label than this variant can carry
    $png = (string) file_get_contents(Corpus::fixtures().'/fixture-signed.png');
    $stream = fopen(Corpus::fixtures().'/fixture-signed.png', 'rb');
    $store = $stream === false ? null : (new PngManifestStoreExtractor)->extract($stream);
    $label = Corpus::manifestStore('fixture-signed.png')?->active->label ?? '';
    // five bytes of the label's uuid replaced by five others: the store keeps its length
    $hostile = substr($label, 0, 9)."\"\\\u{202E}".substr($label, 14);
    $bytes = str_replace($label, $hostile, $store->bytes ?? '');
    $chunk = pack('N', strlen($bytes)).'caBX'.$bytes.pack('N', crc32('caBX'.$bytes));
    $oldLength = unpack('N', substr($png, 33, 4))[1] ?? 0;
    $report = spec089VerifyBytes(substr($png, 0, 33).$chunk.substr($png, 33 + 12 + (is_int($oldLength) ? $oldLength : 0)));

    expect(strlen($hostile))->toBe(strlen($label))
        ->and(array_map(static fn ($s): string => $s->code->value, $report->result->statuses))->toContain('claim.malformed')
        ->and($report->evidence())->toBe([])
        ->and(spec089ShapeFaults($report->evidenceToArray()))->toBe([])
        ->and($report->evidenceToJson())->not->toContain("\u{202E}");
})->group('SPEC-089');

it('AC6: text the objects hold that is not UTF-8 or carries controls is written safely', function (): void {
    // not reachable from a file today (the JUMBF and manifest readers refuse such labels); the writer does not rely on that
    $label = "urn:c2pa:\x1b[2J\xff\xfe\"\\\u{202E}";
    $report = new VerificationReport('png', true, null, ValidationResult::fromStatuses([], []), evidence: [
        $label => new ManifestEvidence($label, 1, ManifestEvidence::CLOCK, null, null, ["\x00\xff"], [], [
            new OcspEvidence(OcspEvidence::RVALS, "self#jumbf=/c2pa/{$label}/c2pa.signature", "\x30\x00", OcspEvidence::UNUSABLE, null, null, null, null, null),
        ]),
    ]);
    $json = $report->evidenceToJson();
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    // amendment 2: U+FFFD for each ill-formed byte, not mb_scrub()'s `?`
    $scrubbed = "urn:c2pa:\x1b[2J\u{FFFD}\u{FFFD}\"\\\u{202E}";

    expect($json)->not->toContain("\x1b")
        ->and($json)->not->toContain("\u{202E}")
        ->and($json)->toContain('\\u001b[2J')
        ->and($json)->toContain('\\"\\\\\\u202e')
        ->and(array_keys(spec089Map(spec089Map($decoded)['manifests'] ?? null)))->toBe([$scrubbed])
        ->and(spec089Map(spec089Map(spec089Map($decoded)['manifests'] ?? null)[$scrubbed] ?? null)['certificates'] ?? null)->toBe([base64_encode("\x00\xff")])
        ->and($decoded)->toBe($report->evidenceToArray());
})->group('SPEC-089');

it('AC7: every report the verifier gives can be written', function (string $settings): void {
    $root = Corpus::fixtures();
    $l = "{$root}/trust/conformance-public";
    $trust = match ($settings) {
        'none' => null,
        'full' => TrustSettings::fromJson((string) file_get_contents("{$root}/trust/full.settings.json")),
        default => TrustSettings::fromTrustLists((string) file_get_contents("{$l}/C2PA-TRUST-LIST.json"), (string) file_get_contents("{$l}/C2PA-TSA-TRUST-LIST.json")),
    };
    $faults = [];
    $written = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || preg_match('/\.(json|pem|md|txt|cfg|php|key|der|crt)$/i', $file->getFilename()) === 1) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        $report = spec089Verify($relative, $trust);
        $decoded = json_decode($report->evidenceToJson(), true, 512, JSON_THROW_ON_ERROR);
        foreach (spec089ShapeFaults($decoded) as $fault) {
            $faults[] = "{$relative}: {$fault}";
        }
        if ($decoded !== $report->evidenceToArray()) {
            $faults[] = "{$relative}: the JSON does not decode to the array";
        }
        $written += count($report->evidence());
    }

    expect($faults)->toBe([])
        ->and($written)->toBeGreaterThan(500);
})->with(['none', 'full', 'lists'])->group('SPEC-089');

it('AC8: evidenceToArray and evidenceToJson are in the contract, and the other outputs carry no document', function (): void {
    $report = spec089Verify(SPEC089_CONTROL, spec089Settings('timestamp/tsa-matrix/control.settings.json'));

    expect((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/api/public-surface.txt'))
        ->toContain('Verifier\VerificationReport :: method evidenceToArray')
        ->toContain('Verifier\VerificationReport :: method evidenceToJson')
        ->and($report->toJson())->not->toContain('c2pa-verifier/evidence')
        ->and($report->toCrJson())->not->toContain('c2pa-verifier/evidence');
})->group('SPEC-089');

it('the anchor and evidence classes keep the names the document reads', function (): void {
    // a guard: the document is written from these constants, so a renamed one would change version 1
    expect([AnchorEvidence::ANCHOR, AnchorEvidence::ALLOWED_LIST, ManifestEvidence::TIMESTAMP, ManifestEvidence::GIVEN, ManifestEvidence::CLOCK])
        ->toBe(['anchor', 'allowedList', 'timestamp', 'given', 'clock'])
        ->and([TimestampEvidence::SIG_TST, TimestampEvidence::SIG_TST2, TimestampEvidence::ASSERTION, TimestampEvidence::USED, TimestampEvidence::REJECTED, TimestampEvidence::NOT_TRIED])
        ->toBe(['sigTst', 'sigTst2', 'assertion', 'used', 'rejected', 'notTried'])
        ->and([OcspEvidence::RVALS, OcspEvidence::ASSERTION, OcspEvidence::FETCHED, OcspEvidence::CHOSEN, OcspEvidence::NOT_CHOSEN, OcspEvidence::UNUSABLE, OcspEvidence::NOT_TRIED])
        ->toBe(['rVals', 'assertion', 'fetched', 'chosen', 'notChosen', 'unusable', 'notTried']);
})->group('SPEC-089');
