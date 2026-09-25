<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cli;

use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Trust\TrustException;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\Verifier;

/**
 * The command line (SPEC-019): `c2pa-verify [--settings <path>] [--] <file>`.
 *
 * A thin shell around the public API. Standard output carries exactly
 * `VerificationReport::toJson()` plus one newline and nothing else, ever;
 * diagnostics go to standard error as one `Error: …` line. The exit status
 * carries the verdict: 0 for `Trusted` or `Valid`, 1 for `Invalid` (the
 * report is still printed), 2 when no report could be made — a usage
 * fault, a file that cannot be opened, settings that cannot be read or are
 * not trust settings. c2patool exits 0 on an `Invalid` report and ignores
 * a settings file it cannot find; both are fail-open and are not copied
 * (SPEC-019, Problem).
 *
 * No network, no `exec`, no temporary file, no environment variable: the
 * command reads the file and, when asked, the one settings file it is
 * given, and hands both to the verifier unchanged.
 */
final readonly class Command
{
    public const string USAGE = "Usage: c2pa-verify [--settings <path>] [--] <file>\n"
        ."  Prints the verification report as JSON on standard output.\n"
        ."  --settings <path>  trust settings in c2patool's JSON shape (anchors, EKUs, allowed list)\n"
        ."  --help             this text\n"
        ."  Exit status: 0 Trusted or Valid, 1 Invalid, 2 no report (usage, unreadable file or settings).\n";

    public function __construct(private Verifier $verifier) {}

    /**
     * @param  list<string>  $arguments  argv without the program name
     * @param  resource  $stdout
     * @param  resource  $stderr
     * @return int 0, 1 or 2 — never anything else
     */
    public function run(array $arguments, $stdout, $stderr): int
    {
        // 1. the arguments: four cases, no short options, no repeats
        $file = null;
        $settingsPath = null;
        $optionsEnded = false;
        for ($i = 0, $n = count($arguments); $i < $n; $i++) {
            $argument = $arguments[$i];
            if (! $optionsEnded && $argument === '--help') {
                fwrite($stdout, self::USAGE);

                return 0;
            }
            if (! $optionsEnded && $argument === '--') {
                $optionsEnded = true;

                continue;
            }
            if (! $optionsEnded && ($argument === '--settings' || str_starts_with($argument, '--settings='))) {
                if ($settingsPath !== null) {
                    return $this->usage($stderr, '--settings given twice');
                }
                if ($argument === '--settings') {
                    if ($i + 1 >= $n) {
                        return $this->usage($stderr, '--settings needs a path');
                    }
                    $settingsPath = $arguments[++$i];
                } else {
                    $settingsPath = substr($argument, strlen('--settings='));
                }

                continue;
            }
            if (! $optionsEnded && str_starts_with($argument, '-') && $argument !== '-') {
                return $this->usage($stderr, sprintf('unknown option %s', $argument));
            }
            if ($file !== null) {
                return $this->usage($stderr, 'more than one file given; the command verifies one file');
            }
            $file = $argument;
        }
        if ($file === null) {
            return $this->usage($stderr, 'no file given');
        }

        // 2. the settings, before the file: the caller asked for trust and gets it or a refusal
        $settings = null;
        if ($settingsPath !== null) {
            $json = $this->read($settingsPath);
            if (is_array($json)) {
                fwrite($stderr, sprintf("Error: cannot read settings %s: %s\n", $settingsPath, $json['reason']));

                return 2;
            }
            try {
                $settings = TrustSettings::fromJson($json);
            } catch (TrustException $e) {
                fwrite($stderr, sprintf("Error: settings %s: %s\n", $settingsPath, $e->getMessage()));

                return 2;
            }
        }

        // 3. the file as a stream; a directory opens without complaint on macOS and Linux
        //    and then fails on every read, so it is refused here, before the verifier
        if (is_dir($file)) {
            fwrite($stderr, sprintf("Error: cannot open %s: Is a directory\n", $file));

            return 2;
        }
        $stream = $this->open($file);
        if (is_array($stream)) {
            fwrite($stderr, sprintf("Error: cannot open %s: %s\n", $file, $stream['reason']));

            return 2;
        }

        // 4. the report, and the verdict as the exit status
        try {
            $report = $this->verifier->verify($stream, $settings);
        } finally {
            fclose($stream);
        }
        fwrite($stdout, $report->toJson()."\n");

        return $report->result->state === ValidationState::Invalid ? 1 : 0;
    }

    /** @param  resource  $stderr */
    private function usage($stderr, string $fault): int
    {
        fwrite($stderr, sprintf("Error: %s\n%s", $fault, self::USAGE));

        return 2;
    }

    /**
     * Open the file read-only as a stream; on failure, PHP's own reason instead of a warning.
     *
     * @return resource|array{reason: string}
     */
    private function open(string $path)
    {
        $local = self::local($path);
        if ($local === null) {
            return ['reason' => 'No such file or directory'];
        }
        $reason = 'unknown reason';
        set_error_handler(static function (int $severity, string $message) use (&$reason): bool {
            $reason = self::reason($message);

            return true;
        });
        try {
            $stream = fopen($local, 'rb');
        } finally {
            restore_error_handler();
        }
        if ($stream === false) {
            return ['reason' => $reason];
        }
        // SPEC-043 AC5: the verifier reads a file twice (the store, then the hashed bytes); a pipe or a
        // FIFO cannot be read twice, and copying one would need a bound of its own on disk
        if (stream_get_meta_data($stream)['seekable'] !== true) {
            fclose($stream);

            return ['reason' => 'the input cannot seek (a pipe, a FIFO or a terminal); save it to a file and verify that'];
        }

        return $stream;
    }

    /**
     * The path as a local file, or null (SPEC-043 AC6). fopen() on the argument itself would honour
     * PHP's wrappers — data:, php://, phar://, and http:// with allow_url_fopen, a network request
     * in the verification path — and would read a file named "data:,x" as the text "x". The path is
     * made absolute against the working directory and opened with "file://" in front, which leaves no
     * wrapper to choose. Symlinks are not resolved: on Linux /dev/stdin leads to "pipe:[…]", which
     * realpath() cannot resolve, and the refusal must then say that the input cannot seek.
     */
    private static function local(string $path): ?string
    {
        if ($path === '') {
            return null;
        }
        $cwd = getcwd();
        $absolute = str_starts_with($path, '/') || $cwd === false ? $path : $cwd.'/'.$path;
        $local = 'file://'.$absolute;

        return file_exists($local) ? $local : null;
    }

    /**
     * Read the settings file whole (a small JSON document, SPEC-014 bounds the certificates).
     *
     * @return string|array{reason: string}
     */
    private function read(string $path): string|array
    {
        $local = self::local($path);
        if ($local === null) {
            return ['reason' => 'No such file or directory'];
        }
        if (is_dir($local)) {
            return ['reason' => 'Is a directory'];
        }
        $reason = 'unknown reason';
        set_error_handler(static function (int $severity, string $message) use (&$reason): bool {
            $reason = self::reason($message);

            return true;
        });
        try {
            $json = file_get_contents($local);
        } finally {
            restore_error_handler();
        }

        return $json === false ? ['reason' => $reason] : $json;
    }

    /** "fopen(x): Failed to open stream: No such file or directory" → "No such file or directory". */
    private static function reason(string $message): string
    {
        $marker = 'Failed to open stream: ';
        $at = strpos($message, $marker);

        return $at === false ? $message : substr($message, $at + strlen($marker));
    }
}
