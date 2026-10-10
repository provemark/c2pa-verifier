<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Cbor\CborDecoder;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Hash\HashedUriCheck;
use Provemark\C2paVerifier\Jumbf\JumbfException;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\IconReferenceCheck;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-067 — icons in data boxes: read, and checked.
 *
 * `c2pasign-logo.png` is an AI original made with c2patool 0.9.12, uploaded
 * and published through the Drupal module C2PA Sign 1.4.11 on a site with a
 * logo: three manifests, the last two written by C2PA Sign, each with a
 * c2pa.databoxes store holding the logo as `c2pa.data` and an icon that is a
 * hashed URI to it. The variants, from bin/make-databox-variants.php, edit
 * only the active manifest's store, in place. Read under the C2PA test roots.
 * c2patool 0.28.1 reads the original and three of the four variants as
 * Trusted: it does not check a data box.
 */

function spec067Verify(string $name): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/databox/{$name}.png", 'rb');
    assert($stream !== false);

    return (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/databox/c2pa-test-roots.settings.json')));
}

/**
 * The statuses on a data-box url, as "code url".
 *
 * @return list<string>
 */
function spec067DataBoxStatuses(VerificationReport $report): array
{
    $out = [];
    foreach ($report->result->statuses as $status) {
        if (str_contains($status->url, '/c2pa.databoxes/')) {
            $out[] = $status->code->value.' '.$status->url;
        }
    }

    return $out;
}

/**
 * The parsed manifests of a store (the original fixture's by default), by label.
 *
 * @return array<string, Manifest>
 */
function spec067Manifests(?string $store = null): array
{
    if ($store === null) {
        $stream = fopen(Corpus::fixtures().'/databox/c2pasign-logo.png', 'rb');
        assert($stream !== false);
        $extracted = (new PngManifestStoreExtractor)->extract($stream);
        assert($extracted !== null);
        $store = $extracted->bytes;
    }
    $out = [];
    foreach ((new JumbfParser)->parse($store)->superboxes() as $box) {
        $manifest = Manifest::read($box);
        $out[$manifest->label] = $manifest;
    }

    return $out;
}

const SPEC067_ACTIVE = ':urn:uuid:f8274d66-382e-4eda-b93e-596e5273fed5';
const SPEC067_UPLOAD = ':urn:uuid:6601324c-f7e4-46e8-8904-3849704bc033';

// --- AC1 ------------------------------------------------------------------------

it('resolves an icon in a data box, and reads the file as the oracle does', function () {
    $report = spec067Verify('c2pasign-logo');

    expect(spec067DataBoxStatuses($report))->toBe([])
        ->and($report->result->state->value)->toBe('Trusted');
})->group('SPEC-067');

// --- AC2 ------------------------------------------------------------------------

it('reports a data box whose bytes changed as a mismatch', function () {
    $report = spec067Verify('databox-byte-changed');

    expect(spec067DataBoxStatuses($report))->toBe([
        'assertion.hashedURI.mismatch self#jumbf=/c2pa/'.SPEC067_ACTIVE.'/c2pa.databoxes/c2pa.data',
    ])->and($report->result->state->value)->toBe('Invalid');
})->group('SPEC-067');

// --- AC3 ------------------------------------------------------------------------

it('still reports a data box that is not there as missing', function (string $variant) {
    $report = spec067Verify($variant);

    expect(spec067DataBoxStatuses($report))->toBe([
        'assertion.missing self#jumbf=/c2pa/'.SPEC067_ACTIVE.'/c2pa.databoxes/c2pa.data',
    ])->and($report->result->state->value)->toBe('Invalid');
})->with(['databox-label-renamed', 'databox-store-renamed'])->group('SPEC-067');

// --- AC4 ------------------------------------------------------------------------

it('resolves only the manifest\'s own data boxes', function () {
    $manifests = spec067Manifests();
    $upload = $manifests[SPEC067_UPLOAD];

    // Its own box resolves; the active manifest's box, which exists, does not.
    expect(IconReferenceCheck::dataBox($upload, 'self#jumbf=/c2pa/'.SPEC067_UPLOAD.'/c2pa.databoxes/c2pa.data'))->not->toBeNull()
        ->and(IconReferenceCheck::dataBox($upload, 'self#jumbf=/c2pa/'.SPEC067_ACTIVE.'/c2pa.databoxes/c2pa.data'))->toBeNull()
        // Matched as a whole url, not by its last segment, and only one segment deep.
        ->and(IconReferenceCheck::dataBox($upload, 'self#jumbf=/c2pa.databoxes/c2pa.data'))->toBeNull()
        ->and(IconReferenceCheck::dataBox($upload, 'self#jumbf=/c2pa/'.SPEC067_UPLOAD.'/c2pa.databoxes/c2pa.data/x'))->toBeNull();
})->group('SPEC-067');

// --- AC5 ------------------------------------------------------------------------

it('resolves nothing for a child that is not a superbox', function () {
    $report = spec067Verify('databox-child-not-superbox');

    expect(spec067DataBoxStatuses($report))->toBe([
        'assertion.missing self#jumbf=/c2pa/'.SPEC067_ACTIVE.'/c2pa.databoxes/c2pa.data',
    ]);
})->group('SPEC-067');

it('resolves nothing when two data boxes share the label', function () {
    // Built here rather than as a fixture: a second box changes the store's
    // length, which a PNG's hard binding would then also flag. The parsed
    // manifest is all this criterion needs.
    $stream = fopen(Corpus::fixtures().'/databox/c2pasign-logo.png', 'rb');
    assert($stream !== false);
    $extracted = (new PngManifestStoreExtractor)->extract($stream);
    assert($extracted !== null);
    $store = $extracted->bytes;

    $manifest = spec067Manifests($store)[SPEC067_ACTIVE];
    $databoxes = null;
    foreach ($manifest->box->superboxes() as $child) {
        if ($child->description->label === 'c2pa.databoxes') {
            $databoxes = $child;
        }
    }
    assert($databoxes !== null);
    $data = $databoxes->superboxes()[0];
    $copy = substr($store, $data->offset, $data->length);
    $grow = strlen($copy);

    // Insert the copy after the original, and grow every enclosing superbox.
    $at = $data->offset + $data->length;
    $store = substr($store, 0, $at).$copy.substr($store, $at);
    foreach ([0, $manifest->box->offset, $databoxes->offset] as $enclosing) {
        $length = unpack('N', substr($store, $enclosing, 4));
        assert(is_array($length) && is_int($length[1]));
        $store = substr_replace($store, pack('N', $length[1] + $grow), $enclosing, 4);
    }

    $grown = spec067Manifests($store)[SPEC067_ACTIVE];
    expect(IconReferenceCheck::dataBox($grown, 'self#jumbf=/c2pa/'.SPEC067_ACTIVE.'/c2pa.databoxes/c2pa.data'))->toBeNull();
})->group('SPEC-067');

/** The original fixture's store bytes. */
function spec067Store(): string
{
    $stream = fopen(Corpus::fixtures().'/databox/c2pasign-logo.png', 'rb');
    assert($stream !== false);
    $extracted = (new PngManifestStoreExtractor)->extract($stream);
    assert($extracted !== null);

    return $extracted->bytes;
}

/** The store with $from replaced by $to (same length) inside the active manifest's data box store. */
function spec067EditDataBoxStore(string $from, string $to): string
{
    $store = spec067Store();
    $manifest = spec067Manifests($store)[SPEC067_ACTIVE];
    $at = strpos($store, 'c2pa.databoxes', $manifest->box->offset + strlen($manifest->label));
    // The first occurrence after the label is the claim's url; the store's own label follows it.
    $at = strpos($store, "c2pa.databoxes\0", (int) $at);
    assert(is_int($at) && strlen($from) === strlen($to));
    $edit = strpos($store, $from, $at - 17);
    assert(is_int($edit) && $edit < $manifest->box->offset + $manifest->box->length);

    return substr_replace($store, $to, $edit, strlen($from));
}

it('reads a data box only from a store of the data box type', function () {
    // The store's UUID becomes the CBOR assertion's, a superbox type the parser
    // walks: labelled c2pa.databoxes, but not a data box store.
    $c2db = (string) hex2bin('63326462');
    $cbor = (string) hex2bin('63626f72');
    $manifest = spec067Manifests(spec067EditDataBoxStore($c2db, $cbor))[SPEC067_ACTIVE];

    expect(IconReferenceCheck::dataBox($manifest, 'self#jumbf=/c2pa/'.SPEC067_ACTIVE.'/c2pa.databoxes/c2pa.data'))->toBeNull();
})->group('SPEC-067');

it('resolves nothing when the manifest has two data box stores', function () {
    $store = spec067Store();
    $manifest = spec067Manifests($store)[SPEC067_ACTIVE];
    $databoxes = null;
    foreach ($manifest->box->superboxes() as $child) {
        if ($child->description->label === 'c2pa.databoxes') {
            $databoxes = $child;
        }
    }
    assert($databoxes !== null);
    $copy = substr($store, $databoxes->offset, $databoxes->length);
    $at = $databoxes->offset + $databoxes->length;
    $store = substr($store, 0, $at).$copy.substr($store, $at);
    foreach ([0, $manifest->box->offset] as $enclosing) {
        $length = unpack('N', substr($store, $enclosing, 4));
        assert(is_array($length) && is_int($length[1]));
        $store = substr_replace($store, pack('N', $length[1] + strlen($copy)), $enclosing, 4);
    }

    $grown = spec067Manifests($store)[SPEC067_ACTIVE];
    expect(IconReferenceCheck::dataBox($grown, 'self#jumbf=/c2pa/'.SPEC067_ACTIVE.'/c2pa.databoxes/c2pa.data'))->toBeNull();
})->group('SPEC-067');

it('resolves nothing, and does not throw, for a manifest without a data box store', function () {
    $first = array_values(spec067Manifests())[0];

    expect(IconReferenceCheck::dataBox($first, 'self#jumbf=/c2pa/'.$first->label.'/c2pa.databoxes/c2pa.data'))->toBeNull();
})->group('SPEC-067');

// --- Amendment 1 -----------------------------------------------------------------

it('hashes one data box once, however many icons name it', function () {
    // The review's probe, built here: the active claim's one
    // claim_generator_info entry repeated 300 times, and its data box grown by
    // 4 MB. Before amendment 1 each icon hashed the whole box again (300 x 4 MB).
    require_once dirname(__DIR__, 3).'/bin/variant-helpers.php';
    $store = spec067Store();
    $active = spec067Manifests($store)[SPEC067_ACTIVE];
    $claim = null;
    foreach ($active->box->superboxes() as $child) {
        if (str_starts_with($child->description->label, 'c2pa.claim')) {
            $claim = $child;
        }
    }
    assert($claim !== null);
    $content = $claim->contentBoxes()[0];
    $at = strpos($store, 'claim_generator_info', $content->offset + 8);
    assert(is_int($at));
    $at += 20;
    expect(ord($store[$at]))->toBe(0x81);   // a one-entry CBOR array, as measured
    $end = null;
    for ($j = $at + 2; $j < $content->offset + $content->length; $j++) {
        try {
            (new CborDecoder)->decode(substr($store, $at + 1, $j + 1 - $at - 1));
            $end = $j + 1;
            break;
        } catch (Throwable) {
        }
    }
    assert(is_int($end));
    $entry = substr($store, $at + 1, $end - $at - 1);
    $store = bSplice($store, $at, $end - $at, "\x99".pack('n', 300).str_repeat($entry, 300), [0, $active->box->offset, $claim->offset, $content->offset]);

    $active = spec067Manifests($store)[SPEC067_ACTIVE];
    $databoxes = null;
    foreach ($active->box->superboxes() as $child) {
        if ($child->description->label === 'c2pa.databoxes') {
            $databoxes = $child;
        }
    }
    assert($databoxes !== null);
    $box = $databoxes->superboxes()[0];
    $boxContent = $box->contentBoxes()[0];
    $store = bSplice($store, $boxContent->offset + $boxContent->length, 0, str_repeat("\0", 4 * 1024 * 1024), [0, $active->box->offset, $databoxes->offset, $box->offset, $boxContent->offset]);
    $grown = spec067Manifests($store)[SPEC067_ACTIVE];

    $started = hrtime(true);
    $statuses = (new IconReferenceCheck)->check($grown);
    $seconds = (hrtime(true) - $started) / 1e9;

    // 300 icons, each a mismatch now that the box grew: all were checked.
    expect(count(array_filter($statuses, fn ($s) => $s->code === StatusCode::AssertionHashedUriMismatch)))->toBe(300)
        ->and($seconds)->toBeLessThan(1.0);
})->group('SPEC-067');

it('checks a redaction that names a data box', function () {
    $upload = spec067Manifests()[SPEC067_UPLOAD];
    $url = 'self#jumbf=/c2pa/'.SPEC067_UPLOAD.'/c2pa.databoxes/c2pa.data';

    $codes = array_map(fn ($s) => $s->code->value.' '.$s->url, (new HashedUriCheck)->check($upload->withRedactions([$url])));

    expect($codes)->toContain('assertion.notRedacted '.$url);
})->group('SPEC-067');

it('refuses the store when a data box breaks the JUMBF rules, named or not', function () {
    // Requestable (bit 0x01 of the toggles, measured 0x13) cleared on the active manifest's c2pa.data description box.
    $store = spec067EditDataBoxStore("\x13c2pa.data\0", "\x12c2pa.data\0");

    expect(fn () => spec067Manifests($store))->toThrow(JumbfException::class, 'Requestable is not set');
})->group('SPEC-067');

it('handles the icon\'s alg as a claim entry\'s', function (array $icon, ?string $expected) {
    $upload = spec067Manifests()[SPEC067_UPLOAD];
    $url = 'self#jumbf=/c2pa/'.SPEC067_UPLOAD.'/c2pa.databoxes/c2pa.data';
    $box = IconReferenceCheck::dataBox($upload, $url);
    assert($box !== null);
    $good = new CborBytes(hash('sha256', $box->payload(), true));
    $icon = array_map(fn ($v) => $v === 'GOOD' ? $good : $v, ['url' => $url, ...$icon]);

    $digests = [];
    $status = IconReferenceCheck::checkDataBox($upload, $url, $icon, $digests);

    expect($status?->code->value)->toBe($expected);
})->with([
    'sha256, the right hash' => [['alg' => 'sha256', 'hash' => 'GOOD'], null],
    'no alg: the claim\'s' => [['hash' => 'GOOD'], null],
    'sha1' => [['alg' => 'sha1', 'hash' => 'GOOD'], 'algorithm.unsupported'],
    'a non-string alg' => [['alg' => 5, 'hash' => 'GOOD'], 'algorithm.unsupported'],
    'sha384 over a 32-byte hash' => [['alg' => 'sha384', 'hash' => 'GOOD'], 'assertion.hashedURI.mismatch'],
    'a hash that is not bytes' => [['alg' => 'sha256', 'hash' => 'text'], 'assertion.hashedURI.mismatch'],
])->group('SPEC-067');

it('reports the codes as named', function () {
    // Pins the enum values the expectations above spell out as strings.
    expect(StatusCode::AssertionMissing->value)->toBe('assertion.missing')
        ->and(StatusCode::AssertionHashedUriMismatch->value)->toBe('assertion.hashedURI.mismatch');
})->group('SPEC-067');
