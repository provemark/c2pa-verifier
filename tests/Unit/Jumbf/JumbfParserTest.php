<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Container\WebpManifestStoreExtractor;
use Provemark\C2paVerifier\Jumbf\ContentBox;
use Provemark\C2paVerifier\Jumbf\JumbfException;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Jumbf\Superbox;
use Provemark\C2paVerifier\Jumbf\UnknownBox;

/*
 * SPEC-005: JUMBF, the manifest store as a tree of boxes. Every offset,
 * length, UUID and hash below was measured in notes/step-09 on the stores
 * the M1 extractors take from the fixtures; the malformed variants and
 * c2patool's verdict on each are in tests/Fixtures/jumbf/README.md
 * (notes/step-10).
 */

const SPEC005_UUID_C2PA = '63327061-0011-0010-8000-00aa00389b71';
const SPEC005_UUID_C2MA = '63326d61-0011-0010-8000-00aa00389b71';
const SPEC005_UUID_C2AS = '63326173-0011-0010-8000-00aa00389b71';
const SPEC005_UUID_C2CL = '6332636c-0011-0010-8000-00aa00389b71';
const SPEC005_UUID_C2CS = '63326373-0011-0010-8000-00aa00389b71';

/** The manifest store of a fixture, as the M1 extractor of its container yields it. */
function spec005Store(string $fixture): string
{
    $path = dirname(__DIR__, 2).'/Fixtures/'.$fixture;
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$path}");
    }
    $extractor = match (pathinfo($fixture, PATHINFO_EXTENSION)) {
        'jpg' => new JpegManifestStoreExtractor,
        'png' => new PngManifestStoreExtractor,
        'webp' => new WebpManifestStoreExtractor,
        default => throw new RuntimeException("no extractor for {$fixture}"),
    };
    $store = $extractor->extract($stream);
    if ($store === null) {
        throw new RuntimeException("no manifest store in {$fixture}");
    }

    return $store->bytes;
}

/** A variant store from tests/Fixtures/jumbf/. */
function spec005Variant(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/jumbf/{$name}.bin");
}

function spec005Parse(string $bytes): Superbox
{
    return (new JumbfParser)->parse($bytes);
}

/**
 * Counts what the tree holds: superboxes, description boxes, content boxes
 * by type, unknown boxes, and the superbox nesting depth (root = 1).
 *
 * @return array{superboxes: int, descriptions: int, content: array<string, int>, unknown: int, depth: int}
 */
function spec005Count(Superbox $box, int $depth = 1): array
{
    $count = ['superboxes' => 1, 'descriptions' => 1, 'content' => [], 'unknown' => 0, 'depth' => $depth];
    foreach ($box->children as $child) {
        if ($child instanceof Superbox) {
            $sub = spec005Count($child, $depth + 1);
            $count['superboxes'] += $sub['superboxes'];
            $count['descriptions'] += $sub['descriptions'];
            $count['unknown'] += $sub['unknown'];
            $count['depth'] = max($count['depth'], $sub['depth']);
            foreach ($sub['content'] as $type => $n) {
                $count['content'][$type] = ($count['content'][$type] ?? 0) + $n;
            }
        } elseif ($child instanceof ContentBox) {
            $count['content'][$child->type] = ($count['content'][$child->type] ?? 0) + 1;
        } else {
            $count['unknown']++;
        }
    }
    ksort($count['content']);

    return $count;
}

/** @return list<string> the labels of a superbox's superbox children, in order */
function spec005Labels(Superbox $box): array
{
    return array_map(static fn (Superbox $child): string => $child->description->label, $box->superboxes());
}

it('AC1: the PNG store parses to the measured tree', function (): void {
    $root = spec005Parse(spec005Store('fixture-signed.png'));

    expect($root->offset)->toBe(0)
        ->and($root->length)->toBe(46025)
        ->and($root->description->uuid)->toBe(SPEC005_UUID_C2PA)
        ->and($root->description->label)->toBe('c2pa')
        ->and($root->description->toggles)->toBe(3)
        ->and($root->children)->toHaveCount(1);

    $manifest = $root->children[0];
    expect($manifest)->toBeInstanceOf(Superbox::class);
    assert($manifest instanceof Superbox);
    expect($manifest->offset)->toBe(38)
        ->and($manifest->length)->toBe(45987)
        ->and($manifest->description->uuid)->toBe(SPEC005_UUID_C2MA)
        ->and($manifest->description->label)->toBe('urn:c2pa:488bf983-c973-465d-a0eb-1597392cc5d0')
        ->and(spec005Labels($manifest))->toBe(['c2pa.assertions', 'c2pa.claim.v2', 'c2pa.signature']);

    [$assertions, $claim, $signature] = $manifest->superboxes();
    expect($assertions->offset)->toBe(117)
        ->and($assertions->description->uuid)->toBe(SPEC005_UUID_C2AS)
        ->and($assertions->superboxes())->toHaveCount(3);
    expect($claim->offset)->toBe(33026)
        ->and($claim->description->uuid)->toBe(SPEC005_UUID_C2CL)
        ->and($claim->contentBoxes())->toHaveCount(1)
        ->and($claim->contentBoxes()[0]->type)->toBe('cbor')
        ->and($claim->contentBoxes()[0]->offset)->toBe(33073)
        ->and(strlen($claim->contentBoxes()[0]->data))->toBe(591);
    expect($signature->offset)->toBe(33672)
        ->and($signature->description->uuid)->toBe(SPEC005_UUID_C2CS)
        ->and($signature->contentBoxes()[0]->type)->toBe('cbor')
        ->and($signature->contentBoxes()[0]->offset)->toBe(33720)
        ->and(strlen($signature->contentBoxes()[0]->data))->toBe(12297);

    expect(spec005Count($root))->toBe([
        'superboxes' => 8, 'descriptions' => 8,
        'content' => ['bfdb' => 1, 'bidb' => 1, 'cbor' => 4],
        'unknown' => 0, 'depth' => 4,
    ]);
})->group('SPEC-005');

it('AC2: the JPEG and WebP stores have the same shape as the PNG store', function (): void {
    $png = spec005Parse(spec005Store('fixture-signed.png'));
    $shape = static function (Superbox $root): array {
        $manifest = $root->superboxes()[0];
        $assertions = $manifest->superboxes()[0];

        return [
            spec005Count($root),
            $root->description->label,
            $manifest->description->uuid,
            spec005Labels($manifest),
            spec005Labels($assertions),
        ];
    };

    foreach (['fixture-signed.jpg' => 81079, 'fixture-signed.webp' => 86972] as $fixture => $thumbnailBytes) {
        $root = spec005Parse(spec005Store($fixture));
        expect($shape($root))->toBe($shape($png), $fixture);

        $thumbnail = $root->superboxes()[0]->superboxes()[0]->superboxes()[0];
        $bidb = array_values(array_filter($thumbnail->contentBoxes(), static fn (ContentBox $box): bool => $box->type === 'bidb'));
        expect($bidb)->toHaveCount(1)
            ->and(strlen($bidb[0]->data))->toBe($thumbnailBytes, $fixture);
    }
})->group('SPEC-005');

it('AC3: description boxes carry their fields', function (): void {
    $manifest = spec005Parse(spec005Store('fixture-signed.png'))->superboxes()[0];
    $hashData = $manifest->child('c2pa.assertions')?->child('c2pa.hash.data');
    $claim = $manifest->child('c2pa.claim.v2');

    expect($hashData)->toBeInstanceOf(Superbox::class);
    assert($hashData instanceof Superbox);
    expect($hashData->offset)->toBe(32831)
        ->and($hashData->length)->toBe(195)
        ->and($hashData->description->toggles)->toBe(19)
        ->and($hashData->description->requestable())->toBeTrue()
        ->and(bin2hex($hashData->description->salt ?? ''))->toBe('e15885c19cc788b35f6233d5312997b9')
        ->and($hashData->description->id)->toBeNull()
        ->and($hashData->description->signature)->toBeNull();

    expect($claim)->toBeInstanceOf(Superbox::class);
    assert($claim instanceof Superbox);
    expect($claim->description->toggles)->toBe(3)
        ->and($claim->description->requestable())->toBeTrue()
        ->and($claim->description->salt)->toBeNull();
})->group('SPEC-005');

it('AC3: a 32-byte salt parses', function (): void {
    $manifest = spec005Parse(spec005Variant('salt-32'))->superboxes()[0];
    $hashData = $manifest->child('c2pa.assertions')?->child('c2pa.hash.data');

    expect($hashData)->toBeInstanceOf(Superbox::class);
    assert($hashData instanceof Superbox);
    expect(bin2hex($hashData->description->salt ?? ''))
        ->toBe('e15885c19cc788b35f6233d5312997b9'.str_repeat('ab', 16));
})->group('SPEC-005');

it('AC4: a label path finds a box, and a missing label is null', function (): void {
    $manifest = spec005Parse(spec005Store('fixture-signed.png'))->superboxes()[0];
    $assertions = $manifest->child('c2pa.assertions');

    expect($assertions)->toBeInstanceOf(Superbox::class);
    assert($assertions instanceof Superbox);
    $hashData = $assertions->child('c2pa.hash.data');
    expect($hashData)->toBeInstanceOf(Superbox::class);
    assert($hashData instanceof Superbox);
    expect($hashData->offset)->toBe(32831)
        ->and(bin2hex(substr($hashData->contentBoxes()[0]->data, 0, 4)))->toBe('a56a6578')
        ->and($assertions->child('c2pa.no.such.label'))->toBeNull();
})->group('SPEC-005');

it('AC5: payload() is exactly what the claim hashes', function (): void {
    $assertions = spec005Parse(spec005Store('fixture-signed.png'))->superboxes()[0]->superboxes()[0];
    $hashes = [];
    foreach ($assertions->superboxes() as $assertion) {
        $hashes[$assertion->description->label] = base64_encode(hash('sha256', $assertion->payload(), true));
    }

    expect(strlen($assertions->child('c2pa.hash.data')?->payload() ?? ''))->toBe(187)
        ->and($hashes)->toBe([
            'c2pa.thumbnail.claim' => 'ECufvnM+XDu9kkr+8gORHq3qpBNJ/+ehOVOhPzFeTR0=',
            'c2pa.actions.v2' => 'O1ACO/r/WGO/TG5sR8iEz54Tf1uiS5Vk8rjhNvWGYXA=',
            'c2pa.hash.data' => 'Cxd9XpB7zgi4c3qUdT2c4DM6BpUCH16Yyn43iCeZ5LI=',
        ]);
})->group('SPEC-005');

it('AC6: a foreign v1 store parses, json box included', function (): void {
    $root = spec005Parse(spec005Store('public-testfiles/adobe-20220124-C.jpg'));
    $manifest = $root->superboxes()[0];
    $assertions = $manifest->superboxes()[0];
    $creativeWork = $assertions->child('stds.schema-org.CreativeWork');

    expect(spec005Count($root)['superboxes'])->toBe(9)
        ->and(spec005Count($root)['unknown'])->toBe(0)
        ->and($manifest->description->label)->toBe('contentauth:urn:uuid:4d971750-1db4-4492-a87c-5c3e7ed33efc')
        ->and($manifest->description->uuid)->toBe(SPEC005_UUID_C2MA)
        ->and(spec005Labels($assertions))->toBe(['c2pa.thumbnail.claim.jpeg', 'stds.schema-org.CreativeWork', 'c2pa.actions', 'c2pa.hash.data'])
        ->and(spec005Labels($manifest))->toBe(['c2pa.assertions', 'c2pa.claim', 'c2pa.signature']);

    expect($creativeWork)->toBeInstanceOf(Superbox::class);
    assert($creativeWork instanceof Superbox);
    expect($creativeWork->contentBoxes())->toHaveCount(1)
        ->and($creativeWork->contentBoxes()[0]->type)->toBe('json')
        ->and(strlen($creativeWork->contentBoxes()[0]->data))->toBe(111)
        ->and($creativeWork->description->salt)->not->toBeNull();
})->group('SPEC-005');

it('AC7: an unknown type UUID is kept as an UnknownBox, not walked, not an error', function (): void {
    $root = spec005Parse(spec005Variant('unknown-uuid'));
    $manifest = $root->superboxes()[0];
    $assertions = $manifest->superboxes()[0];
    $first = $assertions->children[0];

    expect($first)->toBeInstanceOf(UnknownBox::class);
    assert($first instanceof UnknownBox);
    expect($first->offset)->toBe(166)
        ->and($first->length)->toBe(32470)
        ->and($first->uuid)->toBe('ffffffff-ffff-ffff-ffff-ffffffffffff')
        ->and($first->label)->toBe('c2pa.thumbnail.claim')
        ->and(spec005Labels($assertions))->toBe(['c2pa.actions.v2', 'c2pa.hash.data'])
        ->and(spec005Labels($manifest))->toBe(['c2pa.assertions', 'c2pa.claim.v2', 'c2pa.signature'])
        ->and(spec005Count($root)['content'])->toBe(['cbor' => 4]);
})->group('SPEC-005');

it('AC8: LBox 0, 1 or below 8 is an error naming the offset and the LBox', function (): void {
    expect(fn () => spec005Parse(spec005Variant('lbox-zero')))
        ->toThrow(JumbfException::class, 'offset 33026: LBox 0');
    expect(fn () => spec005Parse(spec005Variant('lbox-one')))
        ->toThrow(JumbfException::class, 'offset 33026: LBox 1');
    expect(fn () => spec005Parse(spec005Variant('lbox-seven')))
        ->toThrow(JumbfException::class, 'offset 33026: LBox 7');
})->group('SPEC-005');

it('AC9: a box that overruns its parent is an error naming both ends', function (): void {
    expect(fn () => spec005Parse(spec005Variant('child-overruns')))
        ->toThrow(JumbfException::class, 'offset 32831 ends at 33036, past its parent, which ends at 33026');
})->group('SPEC-005');

it('AC10: children that do not end on their parent\'s LBox are an error', function (): void {
    expect(fn () => spec005Parse(spec005Variant('root-lbox-plus-one')))
        ->toThrow(JumbfException::class, 'offset 0: its children end at 46025 but its LBox ends it at 46026');
})->group('SPEC-005');

it('AC11: a superbox whose first child is not a description box is an error', function (): void {
    expect(fn () => spec005Parse(spec005Variant('first-child-not-jumd')))
        ->toThrow(JumbfException::class, 'offset 33026: first child is jumx, not a description box');
})->group('SPEC-005');

it('AC12: description-box faults are errors naming the box and the fault', function (): void {
    expect(fn () => spec005Parse(spec005Variant('toggles-bit5')))
        ->toThrow(JumbfException::class, 'offset 33034: toggles 35 set unknown bits');
    expect(fn () => spec005Parse(spec005Variant('toggles-no-label')))
        ->toThrow(JumbfException::class, 'offset 33034: Label Present is not set');
    expect(fn () => spec005Parse(spec005Variant('label-no-nul')))
        ->toThrow(JumbfException::class, 'offset 33034: label is not NUL-terminated');
    expect(fn () => spec005Parse(spec005Variant('label-slash')))
        ->toThrow(JumbfException::class, 'offset 33034: label 63 32 70 61 2F 63 6C 61 69 6D 2E 76 32 contains a character that is not permitted');
    expect(fn () => spec005Parse(spec005Variant('label-control')))
        ->toThrow(JumbfException::class, 'offset 33034: label 63 32 70 61 01 63 6C 61 69 6D 2E 76 32 contains a character that is not permitted');
    expect(fn () => spec005Parse(spec005Variant('salt-20')))
        ->toThrow(JumbfException::class, 'offset 32839: salt of 20 bytes, expected 16 or 32');
    expect(fn () => spec005Parse(spec005Variant('private-not-c2sh')))
        ->toThrow(JumbfException::class, 'offset 32839: private box c2sx is not c2sh');
})->group('SPEC-005');

it('AC13: compressed and update manifests are errors, not skips', function (): void {
    expect(fn () => spec005Parse(spec005Variant('uuid-c2cm')))
        ->toThrow(JumbfException::class, 'offset 38: compressed manifests (c2cm) are not supported');
    expect(fn () => spec005Parse(spec005Variant('uuid-c2um')))
        ->toThrow(JumbfException::class, 'offset 38: update manifests (c2um) are not supported');
    expect(fn () => spec005Parse(spec005Variant('brob')))
        ->toThrow(JumbfException::class, 'offset 33073: compressed boxes (brob) are not supported');
})->group('SPEC-005');

it('AC14: bfdb without bidb is an error naming the bfdb box', function (): void {
    expect(fn () => spec005Parse(spec005Variant('bidb-missing')))
        ->toThrow(JumbfException::class, 'bfdb at offset 244 is not followed by bidb');
})->group('SPEC-005');

it('AC15: the root must be a c2pa superbox labelled c2pa', function (): void {
    expect(fn () => spec005Parse(spec005Variant('not-a-superbox')))
        ->toThrow(JumbfException::class, 'expected a jumb superbox at offset 0, found cbor');
    expect(fn () => spec005Parse(spec005Variant('root-uuid-c2ma')))
        ->toThrow(JumbfException::class, 'expected the manifest store UUID '.SPEC005_UUID_C2PA.' at the root, found '.SPEC005_UUID_C2MA);
    expect(fn () => spec005Parse(spec005Variant('root-label')))
        ->toThrow(JumbfException::class, 'expected the root label c2pa, found c2pb');
})->group('SPEC-005');

it('AC16: the depth limit is enforced at the level that exceeds it', function (): void {
    expect(fn () => spec005Parse(spec005Variant('depth-17')))
        ->toThrow(JumbfException::class, 'depth 17 exceeds the limit of 16');
})->group('SPEC-005');

it('AC16: the box limit is enforced at the box that exceeds it', function (): void {
    expect(fn () => (new JumbfParser(maxBoxes: 10))->parse(spec005Store('fixture-signed.png')))
        ->toThrow(JumbfException::class, 'box 11 exceeds the limit of 10 boxes');
})->group('SPEC-005');

it('AC17: the default limits are 16 and 4096, and sufficient for the four stores', function (): void {
    $parser = new JumbfParser;

    expect($parser->maxDepth)->toBe(16)
        ->and($parser->maxBoxes)->toBe(4096);
    foreach (['fixture-signed.jpg', 'fixture-signed.png', 'fixture-signed.webp', 'public-testfiles/adobe-20220124-C.jpg'] as $fixture) {
        expect($parser->parse(spec005Store($fixture))->description->label)->toBe('c2pa', $fixture);
    }
})->group('SPEC-005');
