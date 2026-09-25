<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborDecoder;
use Provemark\C2paVerifier\Cbor\CborException;
use Provemark\C2paVerifier\Cli\Command;
use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\IsobmffManifestStoreExtractor;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-043 (step 155): hostile input ends in a report or a refusal, never a crash. Six findings of the
 * security review of 2026-09-25, each with its own criterion. Inputs are built here where they can be;
 * the two that need a real file are in tests/Fixtures/hostile/ (bin/make-hostile-input-variants.php).
 */

/** @return resource */
function spec043Memory(string $bytes)
{
    $stream = fopen('php://memory', 'w+b');
    assert($stream !== false);
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

/** @param  resource  $stream */
function spec043Verify($stream, bool $trusted = true): VerificationReport
{
    $settings = $trusted ? TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/trust/full.settings.json')) : null;

    return (new Verifier)->verify($stream, $settings);
}

/** A JUMBF box, and a superbox with its description. */
function spec043Box(string $type, string $data): string
{
    return pack('N', 8 + strlen($data)).$type.$data;
}

/**
 * fixture-signed.png with $extra appended to the active manifest's assertion store; every enclosing
 * box length grows with it, and the caBX chunk is rebuilt.
 */
function spec043PngWithAssertions(string $extra): string
{
    $png = (string) file_get_contents(Corpus::fixtures().'/fixture-signed.png');
    $at = strpos($png, 'caBX');
    assert($at !== false);
    $length = unpack('N', substr($png, $at - 4, 4));
    assert(is_array($length) && is_int($length[1]));
    $store = substr($png, $at + 4, $length[1]);
    $root = (new JumbfParser)->parse($store);
    $manifest = $root->superboxes()[count($root->superboxes()) - 1];
    $assertions = $manifest->child('c2pa.assertions');
    assert($assertions !== null);
    $end = $assertions->offset + $assertions->length;
    $store = substr_replace($store, $extra, $end, 0);
    foreach ([0, $manifest->offset, $assertions->offset] as $box) {
        $old = unpack('N', substr($store, $box, 4));
        assert(is_array($old) && is_int($old[1]));
        $store = substr_replace($store, pack('N', $old[1] + strlen($extra)), $box, 4);
    }
    $chunk = pack('N', strlen($store)).'caBX'.$store.pack('N', crc32('caBX'.$store));

    return substr($png, 0, $at - 4).$chunk.substr($png, $at + 8 + $length[1]);
}

/** The thumbnail format a report array gives the manifest $label, or null. */
function spec043Format(mixed $report, string $label): mixed
{
    $manifests = is_array($report) ? ($report['manifests'] ?? null) : null;
    $manifest = is_array($manifests) ? ($manifests[$label] ?? null) : null;
    $thumbnail = is_array($manifest) ? ($manifest['thumbnail'] ?? null) : null;

    return is_array($thumbnail) ? ($thumbnail['format'] ?? null) : null;
}

/** @return list<string> the failure codes of a report, unique and sorted */
function spec043Failures(VerificationReport $report): array
{
    $codes = [];
    foreach ($report->result->statuses as $status) {
        if ($status->code->isFailure()) {
            $codes[$status->code->value] = true;
        }
    }
    $codes = array_keys($codes);
    sort($codes);

    return $codes;
}

/** @return list<string> the explanations of a report's statuses */
function spec043Explanations(VerificationReport $report): array
{
    return array_map(static fn (ValidationStatus $s): string => $s->explanation, $report->result->statuses);
}

/** @return array{0: int, 1: string, 2: string} the command's exit status, standard output and standard error */
function spec043Run(string ...$arguments): array
{
    $out = fopen('php://memory', 'w+b');
    $err = fopen('php://memory', 'w+b');
    assert($out !== false && $err !== false);
    $status = (new Command(new Verifier))->run(array_values($arguments), $out, $err);
    rewind($out);
    rewind($err);

    return [$status, (string) stream_get_contents($out), (string) stream_get_contents($err)];
}

it('AC1: the CBOR item total is bounded, per decode and across a store', function (): void {
    // two arrays of 65,536 items, each within the per-container limit: 131,074 items in all
    $inner = "\x9a".pack('N', 65536).str_repeat("\x00", 65536);
    expect(fn () => (new CborDecoder)->decode("\x82".$inner.$inner))->toThrow(CborException::class, '65536');

    // two unreferenced assertions of 40,000 items each: neither exceeds the limit, together they do
    $assertion = static function (string $label): string {
        $cbor = "\x9a".pack('N', 40000).str_repeat("\x00", 40000);
        $description = spec043Box('jumd', hex2bin('63626f7200110010800000aa00389b71')."\x03".$label."\0");

        return spec043Box('jumb', $description.spec043Box('cbor', $cbor));
    };
    $report = spec043Verify(spec043Memory(spec043PngWithAssertions($assertion('spec043.a').$assertion('spec043.b'))));

    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and(implode(' | ', spec043Explanations($report)))->toContain('65536');
})->group('SPEC-043');

it('AC2: an ISOBMFF purpose string and a merkle box are bounded', function (): void {
    $uuid = "\xd8\xfe\xc3\xd6\x1b\x0e\x48\x3c\x92\x97\x58\x28\x87\x7e\xc4\x81";
    $size = 20 * 1024 * 1024;
    $head = pack('N', 24).'ftypisom'.str_repeat("\0", 12).pack('N', $size).'uuid'.$uuid."\0\0\0\0";
    $started = hrtime(true);
    $report = spec043Verify(spec043Memory($head.str_repeat('a', $size - 28)), trusted: false);
    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(0.5)
        ->and(implode(' | ', spec043Explanations($report)))->toContain('purpose');

    // a fragment whose merkle box declares 200 MB: a sparse file, so the disk holds almost nothing
    $path = tempnam(sys_get_temp_dir(), 'spec043');
    assert(is_string($path));
    try {
        $file = fopen($path, 'w+b');
        assert($file !== false);
        fwrite($file, pack('N', 200 * 1024 * 1024).'uuid'.$uuid."\0\0\0\0merkle\0");
        ftruncate($file, 200 * 1024 * 1024);
        rewind($file);
        memory_reset_peak_usage();
        $before = memory_get_usage();
        expect(fn () => (new IsobmffManifestStoreExtractor)->merklePayload($file))->toThrow(ContainerException::class, 'limit');
        expect(memory_get_peak_usage() - $before)->toBeLessThan(16 * 1024 * 1024);
        fclose($file);
    } finally {
        unlink($path);
    }
})->group('SPEC-043');

it('AC3: a media type that is not UTF-8 reads as empty, as c2patool renders it', function (): void {
    $stream = fopen(Corpus::fixtures().'/hostile/mediatype-not-utf8.jpg', 'rb');
    assert($stream !== false);
    $report = spec043Verify($stream);
    $json = json_decode($report->toJson(), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($json));
    $oracle = spec020Oracle('hostile/mediatype-not-utf8.json');
    $label = $oracle['active_manifest'];
    assert(is_string($label));

    expect(spec043Format($json, $label))->toBe('')
        ->and(spec043Format(spec020Oracle('hostile/mediatype-not-utf8--0.28.0.json'), $label))->toBe('')
        ->and($report->result->state->value)->toBe($oracle['validation_state'])
        ->and(spec043Failures($report))->toBe(spec021OracleFailures('hostile/mediatype-not-utf8.json'))
        ->and(spec043Failures($report))->toBe(spec021OracleFailures('hostile/mediatype-not-utf8--0.28.0.json'));
})->group('SPEC-043');

it('AC4: no OpenSSL warning escapes, and a certificate that warns is unreadable', function (): void {
    $stream = fopen(Corpus::fixtures().'/hostile/certificate-time-nul.jpg', 'rb');
    assert($stream !== false);
    set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
        throw new ErrorException($message, 0, $no, $file, $line);
    });
    try {
        $report = spec043Verify($stream);
    } finally {
        restore_error_handler();
    }
    $invalid = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::SigningCredentialInvalid));

    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and($invalid)->not->toBe([])
        ->and($invalid[0]->explanation)->toContain('Illegal length in timestamp')
        ->and((string) file_get_contents(Corpus::fixtures().'/c2patool/hostile/certificate-time-nul--0.28.0.txt'))->toContain('error parsing certificate');
})->group('SPEC-043');

it('AC5: a stream that cannot seek is refused with exit 2', function (): void {
    // a FIFO (amendment 1): a real filesystem node that cannot seek, on Linux and macOS alike; a writer
    // process fills it, and is stopped afterwards whatever the command did, so the test cannot hang
    $dir = sys_get_temp_dir().'/spec043-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $fifo = $dir.'/input.jpg';
    $made = proc_open(['mkfifo', $fifo], [], $none);
    assert(is_resource($made));
    expect(proc_close($made))->toBe(0);
    $writer = proc_open(['sh', '-c', 'cat "$0" > "$1"', Corpus::fixtures().'/fixture-signed.jpg', $fifo], [], $none);
    assert(is_resource($writer));
    try {
        $bin = dirname(__DIR__, 3).'/bin/c2pa-verify';
        $process = proc_open([PHP_BINARY, $bin, $fifo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        assert(is_resource($process));
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
    } finally {
        proc_terminate($writer);
        proc_close($writer);
        @unlink($fifo);
        rmdir($dir);
    }

    expect($status)->toBe(2)
        ->and($out)->toBe('')
        ->and($err)->toContain('seek');
})->group('SPEC-043');

it('AC6: only local files are opened, for the input and for --settings', function (): void {
    $signed = (string) file_get_contents(Corpus::fixtures().'/fixture-signed.jpg');
    foreach ([['data:image/jpeg;base64,'.base64_encode($signed)], ['php://memory'], ['--settings', 'data:,{}', Corpus::fixtures().'/fixture-signed.jpg']] as $arguments) {
        [$status, $out, $err] = spec043Run(...$arguments);
        expect($status)->toBe(2, implode(' ', $arguments))
            ->and($out)->toBe('')
            ->and($err)->toContain('No such file');
    }

    // a real file whose name looks like a wrapper is a file, also as a relative path, which is how
    // PHP mistakes it for one (an absolute path starts with "/" and was always opened as a file)
    [$control, $expected] = spec043Run(Corpus::fixtures().'/fixture-signed.jpg');
    $dir = sys_get_temp_dir().'/spec043-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $cwd = getcwd();
    assert(is_string($cwd));
    try {
        file_put_contents($dir.'/data:,hello', $signed);
        chdir($dir);
        [$status, $out] = spec043Run('data:,hello');
        expect($status)->toBe($control)
            ->and($out)->toBe($expected);
    } finally {
        chdir($cwd);
        @unlink($dir.'/data:,hello');
        rmdir($dir);
    }
})->group('SPEC-043');
