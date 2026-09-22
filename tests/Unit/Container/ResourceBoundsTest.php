<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\IsobmffManifestStoreExtractor;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Container\WebpManifestStoreExtractor;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Tests\Support\Corpus;

/*
 * SPEC-024: what this verifier costs, and what it does at the edge of the host's
 * memory. Step 66 measured a 63 MiB manifest store — inside the verifier's own
 * 64 MiB bound — ending a 128 MB process with a PHP fatal error instead of
 * returning `Invalid`. A fatal cannot be caught, so the caller gets no report at
 * all; that is not failing closed.
 *
 * Two things shape these tests.
 *
 * The files are generated, not committed. A store of 16 MiB is a file of 16 MiB,
 * and the fixture corpus is already 63 MB that every clone carries forever. These
 * are deterministic — a chunk with a declared length and filler — so nothing is
 * lost by building them in the temporary directory and deleting them after.
 *
 * Each verification runs in a process of its own, because `memory_limit` cannot be
 * lowered reliably from inside a running process and the limit is the subject. That
 * is the same exception SPEC-023 was given for `git`, and it is written into
 * SPEC-024 for this group.
 */

const SPEC024_BOUND = 16 * 1024 * 1024;

/** A PNG whose caBX chunk holds a JUMBF superbox of about $bytes, built in the temporary directory. */
function spec024StorePng(int $bytes): string
{
    $box = static fn (string $type, string $payload): string => pack('N', 8 + strlen($payload)).$type.$payload;
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    $filler = $box('xxxx', str_repeat("\x41", $bytes - 16 - 29));
    $superbox = $box('jumb', $box('jumd', str_repeat("\x00", 16).'c2pa'."\x00").$filler);
    $png = "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NN', 1, 1)."\x08\x02\x00\x00\x00")
        .$chunk('caBX', $superbox)
        .$chunk('IEND', '');

    $path = spec024TempPath('store');
    file_put_contents($path, $png);

    return $path;
}

function spec024TempPath(string $prefix): string
{
    $path = sys_get_temp_dir().'/c2pa-spec024-'.$prefix.'-'.bin2hex(random_bytes(6)).'.png';
    register_shutdown_function(static function () use ($path): void {
        if (is_file($path)) {
            unlink($path);
        }
    });

    return $path;
}

/**
 * One verification in a process with the given memory limit.
 *
 * @return array{state: ?string, codes: list<string>, explanations: list<string>, thrown: ?string, peak_bytes: int, ms: float, limit: string, died: bool}
 */
function spec024Probe(string $file, string $memoryLimit, ?string $settings = null): array
{
    $probe = dirname(__DIR__, 2).'/Support/verify-probe.php';
    $command = [PHP_BINARY, '-d', 'memory_limit='.$memoryLimit, $probe, $file];
    if ($settings !== null) {
        $command[] = $settings;
    }
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('cannot start the probe');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    // A process killed by the memory limit prints PHP's fatal-error text, and on the CLI
    // that goes to stdout beside anything the probe managed to write. So the probe's own
    // line is the last one that parses as JSON; no such line means the process died, which
    // is the outcome AC2 exists to forbid.
    $decoded = null;
    foreach (array_reverse(explode("\n", trim($stdout))) as $line) {
        $line = trim($line);
        if ($line === '' || ! str_starts_with($line, '{')) {
            continue;
        }
        $candidate = json_decode($line, true);
        if (is_array($candidate)) {
            $decoded = $candidate;
            break;
        }
    }
    if ($decoded === null) {
        return ['state' => null, 'codes' => [], 'explanations' => [], 'thrown' => trim($stderr.' '.$stdout),
            'peak_bytes' => 0, 'ms' => 0.0, 'limit' => $memoryLimit, 'died' => true];
    }

    /** @var array{state: ?string, codes: list<string>, explanations: list<string>, thrown: ?string, peak_bytes: int, ms: float, limit: string} $decoded */
    return $decoded + ['died' => false];
}

it('AC1: the default bound is 16 MiB in every container this verifier reads', function (): void {
    // SPEC-024 amendment 1: four since SPEC-026 added ISOBMFF, which carries the
    // same bound and consults the same budget
    expect(PngManifestStoreExtractor::DEFAULT_MAX_CHUNK_LENGTH)->toBe(SPEC024_BOUND)
        ->and(WebpManifestStoreExtractor::DEFAULT_MAX_CHUNK_LENGTH)->toBe(SPEC024_BOUND)
        ->and(JpegManifestStoreExtractor::DEFAULT_MAX_LBOX)->toBe(SPEC024_BOUND)
        ->and(IsobmffManifestStoreExtractor::DEFAULT_MAX_BOX_LENGTH)->toBe(SPEC024_BOUND);
})->group('SPEC-024');

it('AC1: a store above the bound is refused without being read', function (): void {
    $file = spec024StorePng(20 * 1024 * 1024);
    $result = spec024Probe($file, '512M');

    // the refusal names both numbers, so that a reader can tell it from a parse failure
    $explanations = implode(' | ', $result['explanations']);

    expect($result['died'])->toBeFalse($result['thrown'] ?? '')
        ->and($result['state'])->toBe(ValidationState::Invalid->value)
        ->and($explanations)->toContain('20971520')
        ->and($explanations)->toContain('16777216')
        // and it was never held: the peak stays near a file with no manifest at all
        ->and($result['peak_bytes'])->toBeLessThan(12 * 1024 * 1024);
})->group('SPEC-024');

it('AC2: a store that does not fit the host is refused, and the process survives', function (): void {
    // 15 MiB is under the absolute bound, so only the host's limit can refuse it
    $file = spec024StorePng(15 * 1024 * 1024);
    $tight = spec024Probe($file, '32M');
    $roomy = spec024Probe($file, '512M');

    $explanations = implode(' | ', $tight['explanations']);

    expect($tight['died'])->toBeFalse($tight['thrown'] ?? '')
        ->and($tight['state'])->toBe(ValidationState::Invalid->value)
        ->and($tight['codes'])->not->toBe([])
        // the explanation must say the file was not judged — a refusal is not a verdict about it
        ->and(strtolower($explanations))->toContain('not')
        ->and($explanations)->toContain('memory')
        ->and($tight['peak_bytes'])->toBeLessThan(20 * 1024 * 1024)
        // and with room, the same file gets as far as the parser: a different answer, not this one
        ->and($roomy['died'])->toBeFalse()
        ->and($roomy['explanations'])->not->toBe($tight['explanations']);
})->group('SPEC-024');

it('AC3: with no memory limit only the absolute bound applies', function (): void {
    $under = spec024StorePng(8 * 1024 * 1024);
    $over = spec024StorePng(20 * 1024 * 1024);

    $readIt = spec024Probe($under, '-1');
    $refuseIt = spec024Probe($over, '-1');

    // a store the verifier is willing to hold is read, however little PHP says about its limit:
    // an unreadable or absent limit may never become a reason to refuse a valid file
    expect($readIt['died'])->toBeFalse()
        ->and(implode(' | ', $readIt['explanations']))->not->toContain('memory')
        ->and($readIt['peak_bytes'])->toBeGreaterThan(8 * 1024 * 1024)
        // while the absolute bound still holds
        ->and($refuseIt['died'])->toBeFalse()
        ->and(implode(' | ', $refuseIt['explanations']))->toContain('16777216');
})->group('SPEC-024');

it('AC4: every signed fixture verifies the same under a 128 MB limit as under a generous one', function (): void {
    $settings = Corpus::fixtures().'/trust/full.settings.json';
    $checked = 0;
    foreach (['fixture-signed.jpg', 'fixture-signed.png', 'fixture-signed.webp',
        'c2pa-rs/exp-test1.png', 'public-testfiles/truepic-20230212-landscape.jpg',
        'writers/google-20250919-pixel10-npld-picnic-table.jpg',
        'writers/openai-20260826-c2pa_2x.png', 'public-testfiles/adobe-20220124-CAIAIIICAICIICAIICICA.jpg'] as $relative) {
        $file = Corpus::fixtures().'/'.$relative;
        $tight = spec024Probe($file, '128M', $settings);
        $roomy = spec024Probe($file, '512M', $settings);

        expect($tight['died'])->toBeFalse($relative.': '.($tight['thrown'] ?? ''))
            ->and($tight['state'])->toBe($roomy['state'], $relative)
            ->and($tight['codes'])->toBe($roomy['codes'], $relative)
            ->and($tight['peak_bytes'])->toBeLessThan(64 * 1024 * 1024, $relative);
        $checked++;
    }
    expect($checked)->toBe(8);
})->group('SPEC-024');

it('AC5: every refusal arrives in the report, never as an exception past the public API', function (): void {
    foreach ([['20M store, roomy host', spec024StorePng(20 * 1024 * 1024), '512M'],
        ['15M store, tight host', spec024StorePng(15 * 1024 * 1024), '32M']] as [$name, $file, $limit]) {
        $result = spec024Probe($file, $limit);

        expect($result['died'])->toBeFalse($name.': '.($result['thrown'] ?? ''))
            ->and($result['thrown'])->toBeNull($name)
            ->and($result['state'])->toBe(ValidationState::Invalid->value, $name);
    }
})->group('SPEC-024');
