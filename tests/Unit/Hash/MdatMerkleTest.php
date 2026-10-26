<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\IsobmffManifestStoreExtractor;
use Provemark\C2paVerifier\Hash\BmffHashCheck;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-080: a Merkle tree over the mdat of a non-fragmented MP4, as c2patool 0.28.3 writes it under
 * core.merkle_tree_chunk_size_in_kb. The files are bin/make-mdat-merkle-variants.php's, under
 * tests/Fixtures/mdat-merkle/, with both c2patool versions' answers beside them (step 446).
 */

function spec080Bytes(string $name): string
{
    return (string) file_get_contents(Corpus::fixtures()."/mdat-merkle/{$name}.mp4");
}

function spec080Verify(string $bytes): VerificationReport
{
    $stream = fopen('php://memory', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('no memory stream');
    }
    fwrite($stream, $bytes);
    rewind($stream);

    return (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/mdat-merkle/throw-away-root.settings.json')));
}

function spec080(string $name): VerificationReport
{
    return spec080Verify(spec080Bytes($name));
}

function spec080Oracle(string $name, string $version): ?string
{
    $json = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/mdat-merkle/{$name}--{$version}.json"), true);

    return is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : null;
}

/** @return list<string> */
function spec080Explanations(VerificationReport $report, string $code): array
{
    return array_values(array_map(
        static fn (ValidationStatus $s): string => $s->explanation,
        array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->value === $code),
    ));
}

/** The offset of the file's top-level box of $type. */
function spec080Box(string $bytes, string $type): int
{
    for ($o = 0; $o + 8 <= strlen($bytes);) {
        /** @var array{1: int} $u */
        $u = unpack('N', $bytes, $o);
        if (substr($bytes, $o + 4, 4) === $type) {
            return $o;
        }
        $o += max($u[1], 8);
    }
    throw new RuntimeException("no {$type}");
}

/** $bytes with the byte at $at changed. */
function spec080Flip(string $bytes, int $at): string
{
    $bytes[$at] = chr(ord($bytes[$at]) ^ 0x01);

    return $bytes;
}

/**
 * The BMFF hash check alone on $bytes, the manifest read from the store at offset 77 (the uuid box at 32, as
 * c2patool wrote it): no signature is checked, so the assertion may be edited in memory.
 *
 * @return list<string> each status as "code: explanation"
 */
function spec080Check(string $bytes): array
{
    $stream = fopen('php://memory', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('no memory stream');
    }
    fwrite($stream, $bytes);
    rewind($stream);
    /** @var array{1: int} $u */
    $u = unpack('N', $bytes, 32);
    $manifest = ManifestStore::fromTree((new JumbfParser)->parse(substr($bytes, 77, $u[1] - 45)))->active;

    return array_map(static fn (ValidationStatus $s): string => $s->code->value.': '.$s->explanation, (new BmffHashCheck)->check($manifest, $stream));
}

/**
 * Each file's state here and in both c2patool versions: [here, 0.28.3, 0.28.1].
 *
 * @return list<string|null>
 */
function spec080States(string $name): array
{
    return [spec080($name)->result->state->value, spec080Oracle($name, '0.28.3'), spec080Oracle($name, '0.28.1')];
}

it('AC1: six blocks with six proof boxes are Trusted, as c2patool says', function (): void {
    $report = spec080('six-blocks');

    expect(spec080States('six-blocks'))->toBe(['Trusted', 'Trusted', 'Trusted'])
        ->and(implode(' | ', spec080Explanations($report, 'assertion.bmffHash.match')))->toContain('6 blocks')
        ->and(spec080Explanations($report, 'assertion.bmffHash.additionalExclusionsPresent'))->not->toBe([]);
})->group('SPEC-080');

it('AC2: one block, the leaf stored and no proof boxes, is Trusted, as c2patool says', function (): void {
    expect(spec080States('one-block'))->toBe(['Trusted', 'Trusted', 'Trusted'])
        ->and(implode(' | ', spec080Explanations(spec080('one-block'), 'assertion.bmffHash.match')))->toContain('1 block');
})->group('SPEC-080');

it('AC2: the leaves stored as the row, with no proof boxes, are Trusted, as c2patool says', function (): void {
    expect(spec080States('row-of-six'))->toBe(['Trusted', 'Trusted', 'Trusted'])
        ->and(implode(' | ', spec080Explanations(spec080('row-of-six'), 'assertion.bmffHash.match')))->toContain('row of 6');
})->group('SPEC-080');

it('AC3: with no proof boxes, a changed block is found by the stored row', function (): void {
    // one-block with a byte of its block changed: Invalid in c2patool 0.28.3 and 0.28.1, "chunk hash did not match" (step 448)
    $bytes = spec080Bytes('one-block');
    $report = spec080Verify(spec080Flip($bytes, spec080Box($bytes, 'mdat') + 16 + 100));

    expect($report->result->state->value)->toBe('Invalid')
        ->and(implode(' | ', spec080Explanations($report, 'assertion.bmffHash.mismatch')))->toContain('block 0 does not match the stored row');
})->group('SPEC-080');

it('AC3: a changed byte in a block, before the first block or in moov is found, and named', function (string $name, string $says): void {
    $report = spec080($name);

    expect(spec080States($name))->toBe(['Invalid', 'Invalid', 'Invalid'])
        ->and(implode(' | ', spec080Explanations($report, 'assertion.bmffHash.mismatch')))->toContain($says)
        ->and(spec080Explanations($report, 'assertion.bmffHash.match'))->toBe([]);
})->with([
    'block 0' => ['block-0-changed', 'block 0'],
    'the last, short block' => ['block-5-changed', 'block 5'],
    'byte 12 of the mdat, under the file hash' => ['gap-changed', 'top-level box(es)'],
    'moov' => ['moov-changed', 'top-level box(es)'],
])->group('SPEC-080');

it('AC4: a proof box is checked, not trusted', function (string $name, string $says): void {
    $report = spec080($name);

    expect(spec080States($name))->toBe(['Invalid', 'Invalid', 'Invalid'])
        ->and(implode(' | ', spec080Explanations($report, 'assertion.bmffHash.mismatch')))->toContain($says)
        ->and(spec080Explanations($report, 'assertion.bmffHash.match'))->toBe([]);
})->with([
    'a sibling hash changed' => ['proof-sibling-changed', 'block 0'],
    'a location moved to another leaf\'s' => ['proof-location-moved', 'location 2'],
    'a proof box turned into free, nothing moving' => ['proof-freed', '5 proof boxes'],
    // these two move the boxes after them, so the file hash finds them first
    'a proof box removed' => ['proof-removed', 'top-level box(es)'],
    'a proof box twice' => ['proof-duplicated', 'top-level box(es)'],
])->group('SPEC-080');

it('AC4: what follows a proof in its box is not read, as c2patool does not read it', function (): void {
    // c2pa-rs pads every proof box to one size with zeros: blocks 4 and 5 need two siblings, the others three.
    // A last byte of the padding made 0x41 is Trusted in c2patool 0.28.3 and 0.28.1 (measured, step 448)
    $bytes = spec080Bytes('six-blocks');
    $last = strrpos($bytes, "merkle\0");
    expect($last)->toBeInt();
    $box = (int) $last - 28;
    /** @var array{1: int} $u */
    $u = unpack('N', $bytes, $box);
    $end = $box + $u[1] - 1;

    expect(ord($bytes[$end]))->toBe(0)
        ->and(spec080Verify(spec080Flip($bytes, $end))->result->state->value)->toBe('Trusted');
})->group('SPEC-080');

it('AC4: a proof box\'s ids and any hash past the climb are not read, as c2patool does not read them', function (): void {
    // both Trusted in c2patool 0.28.3 and 0.28.1 (step 448): the node a proof reaches is compared with the signed row
    $bytes = spec080Bytes('six-blocks');
    $last = (int) strrpos($bytes, "merkle\0") - 28;
    /** @var array{1: int} $u */
    $u = unpack('N', $bytes, $last);
    $two = strpos($bytes, "\x66hashes\x82", $last);
    expect($two)->toBeInt();
    // the last proof needs two siblings; a third goes where its zero padding was (34 bytes: the box keeps its size)
    $extra = substr_replace(substr_replace($bytes, "\x83", (int) $two + 7, 1), "\x58\x20".str_repeat("\x42", 32), $last + $u[1] - 34, 34);
    $first = strpos($bytes, "\x68uniqueId\x00", spec080Box($bytes, 'mdat'));
    expect($first)->toBeInt();
    $uniqueId = substr_replace($bytes, "\x01", (int) $first + 9, 1);

    expect(spec080Verify($extra)->result->state->value)->toBe('Trusted')
        ->and(spec080Verify($uniqueId)->result->state->value)->toBe('Trusted');
})->group('SPEC-080');

it('AC5: a hash beside a merkle list is read only in a file without moof', function (): void {
    // one-block (no proof boxes) with a moof box appended and its file hash computed again over that box, in memory
    $bytes = spec080Bytes('one-block').pack('N', 8).'moof';
    $stream = fopen('php://memory', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('no memory stream');
    }
    fwrite($stream, $bytes);
    rewind($stream);
    /** @var array{1: int} $u */
    $u = unpack('N', $bytes, 32);
    $data = ManifestStore::fromTree((new JumbfParser)->parse(substr($bytes, 77, $u[1] - 45)))->active->assertions[BmffHashCheck::LABEL]->data;
    $exclusions = is_array($data) && is_array($data['exclusions'] ?? null) ? $data['exclusions'] : throw new RuntimeException('no exclusions');
    /** @var list<array<string, mixed>> $exclusions */
    $context = hash_init('sha256');
    foreach (BmffHashCheck::plan((new IsobmffManifestStoreExtractor)->boxTree($stream), $exclusions, static fn (int $o, int $l): string => substr($bytes, $o, $l)) as $range) {
        hash_update($context, ($range['marker'] ? pack('J', $range['offset']) : '').substr($bytes, $range['offset'], $range['length']));
    }
    $at = strpos($bytes, "\x64hash\x58\x20");
    expect($at)->toBeInt();
    $statuses = implode(' | ', spec080Check(substr_replace($bytes, hash_final($context, true), (int) $at + 7, 32)));

    expect($statuses)->toContain('this one is fragmented')
        ->and($statuses)->not->toContain('assertion.bmffHash.match');
})->group('SPEC-080');

it('AC5: the proof boxes are not stores; a second manifest box, or a purpose this verifier does not read, still is refused', function (): void {
    $bytes = spec080Bytes('six-blocks');
    $open = static function (string $b) {
        $s = fopen('php://memory', 'w+b');
        if ($s === false) {
            throw new RuntimeException('no memory stream');
        }
        fwrite($s, $b);
        rewind($s);

        return $s;
    };
    $store = (new IsobmffManifestStoreExtractor)->extract($open($bytes));
    $uuid = spec080Box($bytes, 'uuid');
    /** @var array{1: int} $u */
    $u = unpack('N', $bytes, $uuid);

    expect($store?->bytes)->toBe(substr($bytes, $uuid + 45, $u[1] - 45));

    // a proof box whose purpose is not merkle: the first proof box after the mdat, "merkle" made "merklX"
    $proof = strpos($bytes, "merkle\0", spec080Box($bytes, 'mdat'));
    expect($proof)->toBeInt();
    $other = substr_replace($bytes, 'merklX', (int) $proof, 6);
    expect(fn () => (new IsobmffManifestStoreExtractor)->extract($open($other)))->toThrow(ContainerException::class);

    // the manifest box twice
    $twice = $bytes.substr($bytes, $uuid, $u[1]);
    expect(fn () => (new IsobmffManifestStoreExtractor)->extract($open($twice)))->toThrow(ContainerException::class, 'two C2PA uuid boxes');

    // proof boxes in a file with moof belong to fragments, not to an mdat: refused as before
    $moof = $bytes.pack('N', 8).'moof';
    expect(fn () => (new IsobmffManifestStoreExtractor)->extract($open($moof)))->toThrow(ContainerException::class, 'purpose merkle');
})->group('SPEC-080');

it('AC6: a merkle map or a proof that is not the shape SPEC-080 reads is refused, with the reason named', function (string $name, string $code, string $says, array $oracle): void {
    $report = spec080($name);

    expect($report->result->state->value)->toBe('Invalid', $name)
        ->and(implode(' | ', spec080Explanations($report, $code)))->toContain($says)
        ->and(spec080Explanations($report, 'assertion.bmffHash.match'))->toBe([])
        ->and([spec080Oracle($name, '0.28.3'), spec080Oracle($name, '0.28.1')])->toBe($oracle);
})->with([
    'both block fields' => ['fixed-and-variable', 'assertion.bmffHash.mismatch', 'fixedBlockSize and variableBlockSizes', ['Invalid', 'Invalid']],
    'a block size that is text' => ['block-size-text', 'assertion.bmffHash.malformed', 'fixedBlockSize', [null, null]],
    'a block size of 1' => ['block-size-one', 'assertion.bmffHash.mismatch', 'fixedBlockSize', ['Invalid', 'Invalid']],
    'a count of 5 for six blocks' => ['count-five', 'assertion.bmffHash.mismatch', 'count 5', ['Invalid', 'Invalid']],
    'an initHash without moof' => ['init-hash', 'assertion.bmffHash.mismatch', 'initHash', ['Invalid', 'Invalid']],
    'a location past the count' => ['proof-location-past-count', 'assertion.bmffHash.mismatch', 'location 6', ['Invalid', 'Invalid']],
    // stricter by design (amendment 1): a row the tree does not have; c2patool reads it some other way
    'a stored row the tree does not have' => ['hashes-four', 'assertion.bmffHash.mismatch', 'row', ['Trusted', 'Trusted']],
    // a row between the root and the leaves: c2pa-rs reads neither, without proofs (step 448)
    'a stored row between the root and the leaves' => ['row-of-three', 'assertion.bmffHash.mismatch', 'row of 3', ['Invalid', 'Invalid']],
])->group('SPEC-080');

it('AC6: a map\'s localId selects nothing when there is one map and one mdat, as c2patool reads it', function (): void {
    expect(spec080States('local-id-one'))->toBe(['Trusted', 'Trusted', 'Trusted']);
})->group('SPEC-080');

it('AC7: without the file hash, a merkle list is a fragmented stream\'s, and /mdat stays refused there', function (): void {
    // six-blocks with its hash key renamed: a merkle list alone, SPEC-028's form, where an /mdat exclusion would let
    // a fragment's media out of its leaf. The allow-list refuses it before anything is hashed
    $bytes = spec080Bytes('six-blocks');
    $at = strpos($bytes, "\x64hash\x58\x20");
    expect($at)->toBeInt();

    expect(implode(' | ', spec080Check(substr_replace($bytes, 'hasX', (int) $at + 1, 4))))->toContain('the exclusion /mdat is refused: a BMFF hash may exclude only');
})->group('SPEC-080');

it('AC7: an /mdat subset from offset 16 on leaves no byte unbound and is read, as c2patool reads it', function (): void {
    foreach (['mdat-offset-24', 'mdat-length-1'] as $name) {
        expect(spec080States($name))->toBe(['Trusted', 'Trusted', 'Trusted'], $name);
        $bytes = spec080Bytes($name);
        $mdat = spec080Box($bytes, 'mdat');
        foreach ([12, 16, 16 + 100, 16 + 5 * 1024 + 100] as $at) {
            expect(spec080Verify(spec080Flip($bytes, $mdat + $at))->result->state->value)->toBe('Invalid', "{$name} byte {$at}");
        }
    }
})->group('SPEC-080');

it('AC7: an /mdat exclusion is read only beside a merkle tree, and only from offset 16 on', function (): void {
    $subset = static fn (int ...$offsets): array => ['xpath' => '/mdat', 'subset' => array_map(static fn (int $o): array => ['offset' => $o, 'length' => 0], $offsets)];

    expect(BmffHashCheck::refusedExclusion($subset(16), true))->toBeNull()
        ->and(BmffHashCheck::refusedExclusion($subset(24), true))->toBeNull()
        ->and(BmffHashCheck::refusedExclusion($subset(16, 100), true))->toBeNull()
        ->and(BmffHashCheck::refusedExclusion($subset(8), true))->toContain('from offset 8')
        ->and(BmffHashCheck::refusedExclusion($subset(16, 8), true))->toContain('from offset 8')
        ->and(BmffHashCheck::refusedExclusion(['xpath' => '/mdat', 'subset' => []], true))->toContain('a list of subsets')
        ->and(BmffHashCheck::refusedExclusion(['xpath' => '/mdat'], true))->toContain('the whole mdat')
        // without a merkle tree, SPEC-071's allow-list as before
        ->and(BmffHashCheck::refusedExclusion($subset(16), false))->toContain('may exclude only');
})->group('SPEC-080');

it('AC7: the whole /mdat excluded leaves eight bytes under neither the hash nor a leaf, and is refused where c2patool trusts it', function (): void {
    $bytes = spec080Bytes('mdat-whole');
    $report = spec080Verify($bytes);
    $changed = spec080Verify(spec080Flip($bytes, spec080Box($bytes, 'mdat') + 12));

    expect(spec080States('mdat-whole'))->toBe(['Invalid', 'Trusted', 'Trusted'])
        ->and(implode(' | ', spec080Explanations($report, 'assertion.bmffHash.mismatch')))->toContain('/mdat')
        ->and($changed->result->state->value)->toBe('Invalid')
        ->and(spec080Explanations($changed, 'assertion.bmffHash.match'))->toBe([]);
})->group('SPEC-080');

it('AC8: the forms SPEC-080 leaves out are refused by name', function (string $name, string $says): void {
    $report = spec080($name);

    expect($report->result->state->value)->toBe('Invalid', $name)
        ->and(implode(' | ', spec080Explanations($report, 'assertion.bmffHash.mismatch')))->toContain($says)->toContain('this verifier does not read');
})->with([
    'variableBlockSizes' => ['variable-sizes', 'variableBlockSizes'],
    'neither block field' => ['no-block-field', 'neither fixedBlockSize nor variableBlockSizes'],
    'two mdat boxes, two maps' => ['two-mdat', '2 merkle maps'],
    'two mdat boxes, one map' => ['two-mdat-one-map', '2 mdat boxes'],
])->group('SPEC-080');

it('AC8: what c2patool says of those forms, kept beside the refusal', function (): void {
    expect([spec080Oracle('variable-sizes', '0.28.3'), spec080Oracle('variable-sizes', '0.28.1')])->toBe(['Trusted', 'Trusted'])
        ->and([spec080Oracle('no-block-field', '0.28.3'), spec080Oracle('no-block-field', '0.28.1')])->toBe(['Invalid', 'Invalid'])
        ->and([spec080Oracle('two-mdat-one-map', '0.28.3'), spec080Oracle('two-mdat-one-map', '0.28.1')])->toBe(['Invalid', 'Invalid']);
    // two-mdat: Trusted or Invalid from one run to the next (step 446); its stored answer is one run's, no oracle
})->group('SPEC-080');
