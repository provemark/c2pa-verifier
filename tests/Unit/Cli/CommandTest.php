<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cli\Command;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-019: the command line. The oracle for the report is the library
 * itself — stdout must be VerificationReport::toJson() of the same call
 * plus one newline, byte for byte; the oracle for the exit status is the
 * table in the spec (c2patool 0.27.22 measured, with the two named
 * departures: Invalid exits 1 here, an unreadable settings file exits 2).
 * AC1–AC9, AC11 and AC12 run Command::run() in-process on php://memory
 * streams; AC10 runs bin/c2pa-verify as a process, which is allowed in a
 * test and nowhere in src/.
 */

const SPEC019_SETTINGS = 'trust/full-plus-digicert-g4.settings.json';

/** @return array{int, string, string} exit status, stdout, stderr */
function spec019Run(string ...$arguments): array
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

function spec019Fixture(string $relative): string
{
    return Corpus::fixtures().'/'.$relative;
}

/** What the library says about the file: the byte-exact expectation for stdout. */
function spec019Expected(string $relative, ?string $settings = null): string
{
    $stream = fopen(spec019Fixture($relative), 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }
    $trust = $settings === null ? null : TrustSettings::fromJson((string) file_get_contents(spec019Fixture($settings)));

    return (new Verifier)->verify($stream, $trust)->toJson()."\n";
}

function spec019State(string $json): mixed
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    return $decoded['validation_state'] ?? null;
}

/**
 * Every file of the four corpora as fixture-relative paths, keyed by that path: the own
 * corpus names `public-testfiles/adobe-20220124-C.jpg` as well, and the c2pa-rs corpus
 * carries its own copy of `adobe-20220124-E-clm-CAICAI` under the same name.
 *
 * @return array<string, string> relative path => corpus name
 */
function spec019Corpora(): array
{
    $files = [];
    foreach (SPEC013_CORPUS as $name => $relative) {
        $files[$relative] = (string) $name;
    }
    foreach ([['public-testfiles', SPEC013_PUBLIC_CORPUS], ['c2pa-rs', SPEC013_RS_CORPUS], ['writers', SPEC013_WRITERS_CORPUS]] as [$dir, $names]) {
        foreach ($names as $name) {
            $path = glob(spec019Fixture("{$dir}/{$name}.*"))[0] ?? throw new RuntimeException("no file for {$name}");
            $files["{$dir}/".basename($path)] = $name;
        }
    }

    return $files;
}

// AC1
test('a valid file: the report on stdout, exit 0, stderr empty', function (): void {
    [$status, $out, $err] = spec019Run(spec019Fixture('fixture-signed.png'));

    expect($status)->toBe(0)
        ->and($out)->toBe(spec019Expected('fixture-signed.png'))
        ->and(spec019State($out))->toBe('Valid')
        ->and(str_contains($out, '"signingCredential.untrusted"'))->toBeTrue('without settings the report says untrusted (SPEC-014 amendment 1)')
        ->and($err)->toBe('');
})->group('SPEC-019');

// AC2
test('a trusted file: --settings reaches the verifier, in both spellings and either order', function (): void {
    $file = spec019Fixture('fixture-signed.png');
    $settings = spec019Fixture(SPEC019_SETTINGS);
    $expected = spec019Expected('fixture-signed.png', SPEC019_SETTINGS);
    expect(spec019State($expected))->toBe('Trusted');

    foreach ([
        [$file, '--settings', $settings],
        ['--settings', $settings, $file],
        [$file, "--settings={$settings}"],
        ["--settings={$settings}", $file],
    ] as $arguments) {
        [$status, $out, $err] = spec019Run(...$arguments);
        $label = implode(' ', $arguments);
        expect($status)->toBe(0, $label)
            ->and($out)->toBe($expected, $label)
            ->and($err)->toBe('', $label);
    }
})->group('SPEC-019');

// AC3 — the first departure from c2patool, which exits 0 on an Invalid report
test('an invalid file: the report on stdout, exit 1', function (): void {
    [$status, $out, $err] = spec019Run(spec019Fixture('binding/pixel-changed.png'));

    expect($status)->toBe(1)
        ->and($out)->toBe(spec019Expected('binding/pixel-changed.png'))
        ->and(spec019State($out))->toBe('Invalid')
        ->and(str_contains($out, '"assertion.dataHash.mismatch"'))->toBeTrue()
        ->and($err)->toBe('');
})->group('SPEC-019');

// AC4
test('no manifest: a report with has_manifest false, exit 1', function (): void {
    [$status, $out, $err] = spec019Run(spec019Fixture('fixture-unsigned.png'));

    expect($status)->toBe(1)
        ->and($out)->toBe(spec019Expected('fixture-unsigned.png'))
        ->and(str_contains($out, '"has_manifest": false'))->toBeTrue()
        ->and(spec019State($out))->toBe('Invalid')
        ->and($err)->toBe('');
})->group('SPEC-019');

// AC5
test('not an image: the verifier\'s report, exit 1', function (): void {
    $readme = dirname(__DIR__, 3).'/README.md';
    $stream = fopen($readme, 'rb');
    if ($stream === false) {
        throw new RuntimeException('cannot open README.md');
    }
    $expected = (new Verifier)->verify($stream)->toJson()."\n";

    [$status, $out, $err] = spec019Run($readme);

    expect($status)->toBe(1)
        ->and($out)->toBe($expected)
        ->and(str_contains($out, 'unsupported file type'))->toBeTrue()
        ->and($err)->toBe('');
})->group('SPEC-019');

// AC6
test('a file that cannot be opened: no report, exit 2, PHP\'s reason on stderr', function (): void {
    $missing = spec019Fixture('does-not-exist.png');
    [$status, $out, $err] = spec019Run($missing);

    expect($status)->toBe(2)
        ->and($out)->toBe('')
        ->and(str_starts_with($err, "Error: cannot open {$missing}: "))->toBeTrue($err)
        ->and(str_contains($err, 'No such file or directory'))->toBeTrue($err)
        ->and(substr_count($err, "\n"))->toBe(1)
        ->and(str_ends_with($err, "\n"))->toBeTrue();

    // fopen() on a directory succeeds on macOS and Linux and every read then fails with a notice
    // (measured: "fread(): Read of 8192 bytes failed with errno=21 Is a directory") — the command
    // refuses it before the verifier sees it
    $dir = Corpus::fixtures();
    [$status, $out, $err] = spec019Run($dir);

    expect($status)->toBe(2)
        ->and($out)->toBe('')
        ->and($err)->toBe("Error: cannot open {$dir}: Is a directory\n");
})->group('SPEC-019');

// AC7 — the second departure from c2patool, which ignores a missing settings file (exit 0, untrusted report)
test('settings that cannot be read: no report, exit 2', function (): void {
    $missing = spec019Fixture('trust/does-not-exist.settings.json');
    [$status, $out, $err] = spec019Run(spec019Fixture('fixture-signed.png'), '--settings', $missing);

    expect($status)->toBe(2)
        ->and($out)->toBe('')
        ->and(str_starts_with($err, "Error: cannot read settings {$missing}: "))->toBeTrue($err)
        ->and(str_contains($err, 'No such file or directory'))->toBeTrue($err)
        ->and(str_ends_with($err, "\n"))->toBeTrue();
})->group('SPEC-019');

// AC8
test('settings that are not trust settings: no report, exit 2, SPEC-014\'s message verbatim', function (): void {
    $file = spec019Fixture('fixture-signed.png');

    $readme = dirname(__DIR__, 3).'/README.md';
    [$status, $out, $err] = spec019Run($file, '--settings', $readme);
    expect($status)->toBe(2)
        ->and($out)->toBe('')
        ->and(str_starts_with($err, "Error: settings {$readme}: the trust settings are not valid JSON: "))->toBeTrue($err);

    $unknown = spec019Fixture('trust/unknown-key.settings.json'); // {"foo": 1}
    [$status, $out, $err] = spec019Run($file, '--settings', $unknown);
    expect($status)->toBe(2)
        ->and($out)->toBe('')
        ->and($err)->toBe("Error: settings {$unknown}: unknown top-level key foo in the trust settings (known: trust, verify)\n");
})->group('SPEC-019');

// AC9
test('usage faults: exit 2 with the fault and the usage on stderr; --help: exit 0 with the usage on stdout', function (): void {
    $file = spec019Fixture('fixture-signed.png');
    $usage = 'Usage: c2pa-verify [--settings <path>] [--] <file>';

    foreach ([
        'no arguments' => [],
        'two paths' => [$file, $file],
        '--settings last' => [$file, '--settings'],
        '--bogus' => ['--bogus', $file],
        '-x' => ['-x', $file],
        '--settings twice' => ['--settings', $file, '--settings', $file, $file],
    ] as $case => $arguments) {
        [$status, $out, $err] = spec019Run(...$arguments);
        expect($status)->toBe(2, $case)
            ->and($out)->toBe('', $case)
            ->and(str_starts_with($err, 'Error: '))->toBeTrue("{$case}: {$err}")
            ->and(str_contains($err, $usage))->toBeTrue("{$case}: {$err}");
    }

    foreach ([['--help'], [$file, '--help'], ['--help', '--bogus']] as $arguments) {
        [$status, $out, $err] = spec019Run(...$arguments);
        expect($status)->toBe(0)
            ->and(str_starts_with($out, $usage))->toBeTrue($out)
            ->and($err)->toBe('');
    }

    // "--" ends the options: a file literally named "--settings" (which does not exist)
    [$status, $out, $err] = spec019Run('--', '--settings');
    expect($status)->toBe(2)
        ->and($out)->toBe('')
        ->and(str_starts_with($err, 'Error: cannot open --settings: '))->toBeTrue($err);
})->group('SPEC-019');

// AC10 — the shim, run as a process
test('bin/c2pa-verify is the command: exit 0, 1, 2 and the same bytes', function (): void {
    $bin = dirname(__DIR__, 3).'/bin/c2pa-verify';
    expect(is_file($bin))->toBeTrue("{$bin} exists");

    $run = static function (string ...$arguments) use ($bin): array {
        $process = proc_open([PHP_BINARY, $bin, ...array_values($arguments)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($process === false) {
            throw new RuntimeException('cannot start the process');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    };

    [$status, $out, $err] = $run(spec019Fixture('fixture-signed.png'));
    expect($status)->toBe(0)
        ->and($out)->toBe(spec019Expected('fixture-signed.png'))
        ->and($err)->toBe('');

    [$status, $out, $err] = $run(spec019Fixture('binding/pixel-changed.png'), '--settings', spec019Fixture(SPEC019_SETTINGS));
    expect($status)->toBe(1)
        ->and($out)->toBe(spec019Expected('binding/pixel-changed.png', SPEC019_SETTINGS))
        ->and($err)->toBe('');

    $missing = spec019Fixture('does-not-exist.png');
    [$status, $out, $err] = $run($missing);
    expect($status)->toBe(2)
        ->and($out)->toBe('')
        ->and(str_starts_with($err, "Error: cannot open {$missing}: "))->toBeTrue($err);
})->group('SPEC-019');

// AC11 — the drift alarm for the command
test('every corpus file, with and without settings: stdout equals the API, the exit status follows the state, stderr empty', function (): void {
    $files = spec019Corpora();
    expect(count($files))->toBe(22 + 24 + 17 + 7 - 1); // one path named twice (adobe-20220124-C)

    $seen = ['Trusted' => 0, 'Valid' => 0, 'Invalid' => 0];
    foreach ($files as $relative => $name) {
        foreach ([null, SPEC019_SETTINGS] as $settings) {
            $expected = spec019Expected($relative, $settings);
            $arguments = $settings === null ? [spec019Fixture($relative)] : [spec019Fixture($relative), '--settings', spec019Fixture($settings)];
            [$status, $out, $err] = spec019Run(...$arguments);
            $label = $name.($settings === null ? '' : ' (settings)');
            $state = spec019State($out);
            $state = is_string($state) ? $state : 'Invalid';
            // an expired signer "checked at now" names the second of the check; the expectation and the
            // command run one after the other and may straddle a second (seen on CI, run 35714422755)
            $now = '/expired at \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z: /';
            expect(preg_replace($now, 'expired at <now>: ', $out))->toBe(preg_replace($now, 'expired at <now>: ', $expected), $label)
                ->and($status)->toBe(in_array($state, ['Trusted', 'Valid'], true) ? 0 : 1, "{$label}: {$state}")
                ->and($err)->toBe('', $label);
            $seen[$state]++;
        }
    }
    // all three states occur, so the exit-status rule is exercised on every branch
    expect($seen['Trusted'])->toBeGreaterThan(0)
        ->and($seen['Valid'])->toBeGreaterThan(0)
        ->and($seen['Invalid'])->toBeGreaterThan(0);
})->group('SPEC-019');

// AC12
test('nothing but one JSON document on stdout, and no control byte in it', function (): void {
    $variants = [];
    foreach (['jumbf', 'cose', 'binding', 'claim'] as $dir) {
        foreach (glob(spec019Fixture("{$dir}/*.png")) ?: [] as $path) {
            $variants[] = "{$dir}/".basename($path);
        }
    }
    expect(count($variants))->toBeGreaterThan(40);
    // one whose message carries bytes of the file (SPEC-005: a label with a control character, rendered as hex)
    expect(in_array('jumbf/label-control.png', $variants, true))->toBeTrue();

    foreach ($variants as $relative) {
        [$status, $out, $err] = spec019Run(spec019Fixture($relative));
        expect($err)->toBe('', $relative)
            ->and(in_array($status, [0, 1], true))->toBeTrue("{$relative}: {$status}")
            ->and(str_ends_with($out, "\n") && ! str_ends_with($out, "\n\n"))->toBeTrue("{$relative}: exactly one trailing newline")
            ->and(preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $out))->toBe(0, "{$relative}: a control byte reached stdout");
        $decoded = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        expect($decoded)->toBeArray($relative);
    }
})->group('SPEC-019');
