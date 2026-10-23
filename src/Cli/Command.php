<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cli;

use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Trust\TrustException;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\Verifier;

/**
 * The command line (SPEC-019): `c2pa-verify [--settings <path>] [--text] [--] <file>`.
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
    public const string USAGE = "Usage: c2pa-verify [--settings <path>] [--text] [--] <file>\n"
        ."  Prints the verification report as JSON on standard output.\n"
        ."  --settings <path>  trust settings in c2patool's JSON shape (anchors, EKUs, allowed list)\n"
        ."  --trust-list <pem>      the signers' trust anchors, PEM (the C2PA Trust List); not with --settings\n"
        ."  --tsa-trust-list <pem>  the time-stamp authorities' trust anchors, PEM (the C2PA TSA Trust List); not with --settings\n"
        ."  --at <time>        judge at this time (RFC 3339) where no trusted timestamp gives one; default now\n"
        ."  --crjson           print the report as crJSON (C2PA 2.4) instead; a failure crJSON has no place for goes to standard error\n"
        ."  --text             also read plain text (C2PA 2.4 §A.8; experimental upstream, off by default)\n"
        ."  --help             this text\n"
        ."  Exit status: 0 Trusted or Valid, 1 Invalid, 2 no report (usage, unreadable file or settings).\n";

    /** @param  Verifier|null  $textVerifier  the verifier with text on, for `--text` (SPEC-019 amendment 3); the shim passes it */
    public function __construct(private Verifier $verifier, private ?Verifier $textVerifier = null) {}

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
        $at = null;
        $lists = ['--trust-list' => null, '--tsa-trust-list' => null];
        $crJson = false;
        $text = false;
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
            if (! $optionsEnded && ($argument === '--at' || str_starts_with($argument, '--at='))) {
                if ($at !== null) {
                    return $this->usage($stderr, '--at given twice');
                }
                if ($argument === '--at' && $i + 1 >= $n) {
                    return $this->usage($stderr, '--at needs a time (RFC 3339, e.g. 2026-10-09T05:31:00Z)');
                }
                $value = $argument === '--at' ? $arguments[++$i] : substr($argument, strlen('--at='));
                $at = self::rfc3339($value);
                if ($at === null) {
                    return $this->usage($stderr, sprintf("--at: '%s' is not an RFC 3339 date-time (e.g. 2026-10-09T05:31:00Z)", $value));
                }

                continue;
            }
            $listOption = $optionsEnded ? null : (array_key_exists($argument, $lists) ? $argument : (str_starts_with($argument, '--trust-list=') ? '--trust-list' : (str_starts_with($argument, '--tsa-trust-list=') ? '--tsa-trust-list' : null)));
            if ($listOption !== null) {
                // SPEC-077: the conformance programme's lists, as published
                if ($lists[$listOption] !== null) {
                    return $this->usage($stderr, "{$listOption} given twice");
                }
                if ($argument === $listOption && $i + 1 >= $n) {
                    return $this->usage($stderr, "{$listOption} needs a path");
                }
                $lists[$listOption] = $argument === $listOption ? $arguments[++$i] : substr($argument, strlen($listOption) + 1);

                continue;
            }
            if (! $optionsEnded && $argument === '--crjson') {
                if ($crJson) {
                    return $this->usage($stderr, '--crjson given twice');
                }
                $crJson = true;

                continue;
            }
            if (! $optionsEnded && $argument === '--text') {
                if ($text) {
                    return $this->usage($stderr, '--text given twice');
                }
                $text = true;

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

        $verifier = $this->verifier;
        if ($text) {
            if ($this->textVerifier === null) {
                fwrite($stderr, "Error: --text: this command was made without the text reader\n");

                return 2;
            }
            $verifier = $this->textVerifier;
        }

        if ($settingsPath !== null && ($lists['--trust-list'] !== null || $lists['--tsa-trust-list'] !== null)) {
            return $this->usage($stderr, '--settings and --trust-list/--tsa-trust-list exclude each other: one source of trust');
        }

        // 2. the settings, before the file: the caller asked for trust and gets it or a refusal
        $settings = null;
        if ($lists['--trust-list'] !== null || $lists['--tsa-trust-list'] !== null) {
            $pems = [];
            foreach ($lists as $option => $path) {
                if ($path === null) {
                    $pems[$option] = null;

                    continue;
                }
                $pem = $this->read($path);
                if (is_array($pem)) {
                    return $this->usage($stderr, sprintf('cannot read %s %s: %s', $option, $path, $pem['reason']));
                }
                $pems[$option] = $pem;
            }
            foreach ($pems as $option => $pem) {
                if ($pem === null) {
                    continue;
                }
                try {
                    TrustSettings::fromTrustLists($option === '--trust-list' ? $pem : null, $option === '--tsa-trust-list' ? $pem : null);
                } catch (TrustException $e) {
                    return $this->usage($stderr, sprintf('%s %s: %s', $option, (string) $lists[$option], $e->getMessage()));
                }
            }
            $settings = TrustSettings::fromTrustLists($pems['--trust-list'], $pems['--tsa-trust-list']);
        }
        if ($settingsPath !== null) {
            $json = $this->read($settingsPath);
            if (is_array($json)) {
                fwrite($stderr, self::shown(sprintf("Error: cannot read settings %s: %s\n", $settingsPath, $json['reason'])));

                return 2;
            }
            try {
                $settings = TrustSettings::fromJson($json);
            } catch (TrustException $e) {
                fwrite($stderr, self::shown(sprintf("Error: settings %s: %s\n", $settingsPath, $e->getMessage())));

                return 2;
            }
        }

        // 3. the file as a stream; a directory opens without complaint on macOS and Linux
        //    and then fails on every read, so it is refused here, before the verifier
        if (is_dir($file)) {
            fwrite($stderr, self::shown(sprintf("Error: cannot open %s: Is a directory\n", $file)));

            return 2;
        }
        $stream = $this->open($file);
        if (is_array($stream)) {
            fwrite($stderr, self::shown(sprintf("Error: cannot open %s: %s\n", $file, $stream['reason'])));

            return 2;
        }

        // 4. the report, and the verdict as the exit status
        try {
            $report = $verifier->verify($stream, $settings, $at);
        } finally {
            fclose($stream);
        }
        try {
            $json = $crJson ? $report->toCrJson() : $report->toJson();
        } catch (\JsonException $e) {
            // SPEC-019 AC13: no input is known to reach this since SPEC-007 AC15; should one, it is
            // the command's own failure, one line and exit 2, never PHP's fatal error and its trace
            fwrite($stderr, self::shown(sprintf("Error: the report cannot be written as JSON: %s\n", $e->getMessage())));

            return 2;
        }
        fwrite($stdout, $json."\n");
        if ($crJson && $report->store === null) {
            // SPEC-075 AC8: crJSON describes a store; with none, the report's own answer has nowhere to go but here
            $failure = array_values(array_filter($report->result->statuses, static fn ($s): bool => $s->code->isFailure()))[0] ?? null;
            fwrite($stderr, $failure === null
                ? "crJSON: no manifest store in the file\n"
                : self::shown(sprintf("crJSON: no manifest store: %s: %s\n", $failure->code->value, $failure->explanation)));
        }

        return $report->result->state === ValidationState::Invalid ? 1 : 0;
    }

    /**
     * An RFC 3339 §5.6 date-time, or null (SPEC-073 AC5). Every field is range-checked, so
     * 2026-02-30 is refused rather than rolled over into March; a leap second (60) is
     * refused too, since no epoch can hold it. The fraction is dropped: rounded down.
     */
    private static function rfc3339(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?([Zz]|[+-](\d{2}):(\d{2}))$/D', $value, $m) !== 1) {
            return null;
        }
        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59
            || (isset($m[8], $m[9]) && ((int) $m[8] > 23 || (int) $m[9] > 59))) {
            return null;
        }
        $offset = strtoupper($m[7]) === 'Z' ? '+00:00' : $m[7];
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:sP', sprintf('%s-%s-%sT%s:%s:%s%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6], $offset));
        // the time is recorded in UTC (crJSON's validationTime): an offset can carry it past year 9999 or before
        // year 1, which RFC 3339 cannot write (SPEC-073 amendment 2)
        $year = $parsed === false ? 0 : (int) $parsed->setTimezone(new \DateTimeZone('UTC'))->format('Y');

        return $parsed === false || $year < 1 || $year > 9999 ? null : $parsed;
    }

    /**
     * A line for the terminal (SPEC-019 amendment 4): what the command echoes (a path, an option, a
     * message naming manifest content) with its control characters written as escapes, as the report's
     * JSON writes them: C0 and DEL as \xNN, C1 and the bidirectional overrides as \u{…}, bytes that are
     * not UTF-8 as U+FFFD. One final newline is kept.
     */
    private static function shown(string $line): string
    {
        $newline = str_ends_with($line, "\n");
        $text = mb_scrub($newline ? substr($line, 0, -1) : $line, 'UTF-8');
        $text = (string) preg_replace_callback('/[\x00-\x1F\x7F]/', static fn (array $m): string => sprintf('\\x%02X', ord($m[0])), $text);
        $text = (string) preg_replace_callback('/[\x{80}-\x{9F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', static fn (array $m): string => sprintf('\\u{%X}', mb_ord($m[0], 'UTF-8')), $text);

        return $newline ? $text."\n" : $text;
    }

    /** @param  resource  $stderr */
    private function usage($stderr, string $fault): int
    {
        fwrite($stderr, self::shown(sprintf("Error: %s\n", $fault)).self::USAGE);

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
     * wrapper to choose. Whether it exists is left to fopen() itself: on Linux /dev/stdin leads to
     * "pipe:[…]", which realpath() and PHP's stat cannot resolve though open() can, and the refusal
     * must then say that the input cannot seek, not that it is missing.
     */
    private static function local(string $path): ?string
    {
        if ($path === '') {
            return null;
        }
        $cwd = getcwd();
        $absolute = str_starts_with($path, '/') || $cwd === false ? $path : $cwd.'/'.$path;

        // longer than any path the system accepts, so no file (a data: URL of an image is kilobytes)
        return strlen($absolute) > PHP_MAXPATHLEN ? null : 'file://'.$absolute;
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
