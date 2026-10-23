<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborDecoder;
use Provemark\C2paVerifier\Cbor\CrJsonValue;
use Provemark\C2paVerifier\Cbor\CrJsonValueException;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\Assertion;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-074: CBOR values as crJSON values, by crJSON Table 1 (C2PA 2.4).
 * The vectors are CBOR in hex, decoded by the verifier's own decoder with
 * keepMaps (amendment 1, SPEC-006 amendment 3); the oracle for AC7 is
 * c2patool 0.28.2 --crjson under c2patool/crjson/.
 */

/** The decoded CBOR of bytes, maps kept as maps. */
function spec074Decode(string $bytes): mixed
{
    return (new CborDecoder(keepMaps: true))->decode($bytes);
}

/** An assertion's own CBOR, decoded again with keepMaps: its one `cbor` content box. */
function spec074AssertionValue(Assertion $assertion): mixed
{
    $boxes = $assertion->box->contentBoxes();
    expect($boxes)->toHaveCount(1);

    return CrJsonValue::of(spec074Decode($boxes[0]->data));
}

/** The decoded CBOR of a hex string, mapped and encoded as crJSON writes it. */
function spec074Json(string $hex): string
{
    return json_encode(CrJsonValue::of(spec074Decode((string) hex2bin($hex))), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
}

// AC1
it('AC1: a byte string is Base64 between b64\' and \'', function (string $hex, string $json): void {
    expect(spec074Json($hex))->toBe($json);
})->with([
    'empty' => ['40', '"b64\'\'"'],
    'one zero byte' => ['4100', '"b64\'AA==\'"'],
    'two bytes' => ['420102', '"b64\'AQI=\'"'],
    'the two non-alphanumeric letters' => ['42fbff', '"b64\'+/8=\'"'],
    'a SHA-256' => ['5820'.hash('sha256', ''), '"b64\''.base64_encode(hash('sha256', '', true)).'\'"'],
])->group('SPEC-074');

// AC2
it('AC2: the JSON-native types pass unchanged', function (string $hex, string $json): void {
    expect(spec074Json($hex))->toBe($json);
})->with([
    'text' => ['6461c3a90a', '"aé\n"'],
    'zero' => ['00', '0'],
    '23' => ['17', '23'],
    '24' => ['1818', '24'],
    'the largest int' => ['1b7fffffffffffffff', '9223372036854775807'],
    'minus one' => ['20', '-1'],
    'the smallest int' => ['3b7fffffffffffffff', '-9223372036854775808'],
    'false, true, null' => ['83f4f5f6', '[false,true,null]'],
    'an empty array' => ['80', '[]'],
    'an empty map' => ['a0', '{}'],
    'nested, order kept' => ['a2617a82a16162f580616101', '{"z":[{"b":true},[]],"a":1}'],
])->group('SPEC-074');

// AC3
it('AC3: tag 0 is its text', function (): void {
    expect(spec074Json('c074323032362d31302d30395430353a33313a30305a'))->toBe('"2026-10-09T05:31:00Z"');
})->group('SPEC-074');

it('AC3: the when of a real c2pa.actions.v2 is its text', function (): void {
    $store = spec074Store('writers/openai-20260826-c2pa_2x.png');   // its actions carry 0("…") dates
    $whens = [];
    foreach ($store->manifests as $manifest) {
        foreach ($manifest->assertions as $assertion) {
            if (str_starts_with($assertion->label, 'c2pa.actions')) {
                $mapped = json_decode((string) json_encode(spec074AssertionValue($assertion)), true);
                foreach (is_array($mapped) && is_array($mapped['actions'] ?? null) ? $mapped['actions'] : [] as $action) {
                    if (is_array($action) && isset($action['when'])) {
                        $whens[] = $action['when'];
                    }
                }
            }
        }
    }
    expect($whens)->not->toBe([]);
    foreach ($whens as $when) {
        expect($when)->toBeString()->toMatch('/^\d{4}-\d{2}-\d{2}T/');
    }
})->group('SPEC-074');

// AC4
it('AC4: a float stays a float', function (string $hex, string $json): void {
    expect(spec074Json($hex))->toBe($json);
})->with([
    'half 1.0' => ['f93c00', '1.0'],
    'half 1.5' => ['f93e00', '1.5'],
    'half -0.0' => ['f98000', '-0.0'],
    'half 65504.0' => ['f97bff', '65504.0'],
    'double 1.0e-7' => ['fb3e7ad7f29abcaf48', '1.0e-7'],
])->group('SPEC-074');

// AC5
it('AC5: an integer map key becomes its decimal string', function (): void {
    expect(spec074Json('a2016161216162'))->toBe('{"1":"a","-2":"b"}');
})->group('SPEC-074');

// AC6
it('AC6: what Table 1 cannot express is refused, naming why and where', function (string $hex, string $why, string $where): void {
    try {
        CrJsonValue::of(spec074Decode((string) hex2bin($hex)));
    } catch (CrJsonValueException $e) {
        expect($e->getMessage())->toContain($why)
            ->and($e->getMessage())->toContain($where);

        return;
    }
    expect('no exception')->toBe('a CrJsonValueException');
})->with([
    'tag 1' => ['c11a6553f100', 'tag 1', '$'],
    'tag 32' => ['d8206968747470733a2f2f78', 'tag 32', '$'],
    'tag 0 over an integer' => ['c001', 'tag 0', '$'],
    'NaN' => ['f97e00', 'NaN', '$'],
    'plus infinity' => ['f97c00', 'infinit', '$'],
    'minus infinity' => ['f9fc00', 'infinit', '$'],
    'nested three deep' => ['a167616374696f6e7381a1647768656ec100', 'tag 1', 'actions[0].when'],
])->group('SPEC-074');

it('AC6: a map decoded without keepMaps is refused, not guessed at', function (): void {
    expect(fn () => CrJsonValue::of((new CborDecoder)->decode((string) hex2bin('a1616101'))))
        ->toThrow(CrJsonValueException::class, 'keepMaps');
    expect(fn () => CrJsonValue::of(spec074Decode((string) hex2bin('a16178a1617901'))))->not->toThrow(CrJsonValueException::class);
})->group('SPEC-074');

// AC7
it('AC7: every CBOR assertion of 59 real files equals c2patool --crjson, under three named normalisations', function (): void {
    $root = Corpus::fixtures().'/c2patool/crjson';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $compared = 0;
    $answers = 0;
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo) {
            continue;
        }
        $path = $file->getPathname();
        if (! str_ends_with($path, '.json')) {
            continue;
        }
        $answers++;
        $relative = substr($path, strlen($root) + 1, -strlen('.json'));
        /** @var array{manifests: list<array{label: string, assertions: array<string, mixed>}>} $theirs */
        $theirs = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $byLabel = array_column($theirs['manifests'], 'assertions', 'label');
        $store = spec074Store($relative);
        foreach ($store->manifests as $manifest) {
            expect(array_key_exists($manifest->label, $byLabel))->toBeTrue("{$relative}: {$manifest->label}");
            foreach ($manifest->assertions as $assertion) {
                if ($assertion->box->description->uuid !== JumbfParser::UUID_CBOR_ASSERTION || preg_match('/^cawg\.identity(__\d+)?$/', $assertion->label) === 1) {
                    continue;
                }
                expect(array_key_exists($assertion->label, $byLabel[$manifest->label]))->toBeTrue("{$relative}: {$assertion->label}");
                $ours = json_decode((string) json_encode(spec074AssertionValue($assertion), JSON_PRESERVE_ZERO_FRACTION), true);
                $other = spec074DropAddedPad($byLabel[$manifest->label][$assertion->label], $ours);
                expect(spec074Canon($ours))->toBe(spec074Canon($other), "{$relative}: {$manifest->label} {$assertion->label}");
                $compared++;
            }
        }
    }
    expect($answers)->toBe(59)
        ->and($compared)->toBe(298);
})->group('SPEC-074');
