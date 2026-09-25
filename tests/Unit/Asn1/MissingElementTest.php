<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Tests\Support\DerPatch;
use Provemark\C2paVerifier\Timestamp\TimestampCheck;
use Provemark\C2paVerifier\Timestamp\TimestampException;
use Provemark\C2paVerifier\Timestamp\TimeStampToken;
use Provemark\C2paVerifier\Trust\Certificate;
use Provemark\C2paVerifier\Trust\OcspCheck;

/*
 * SPEC-016 amendment 4 and SPEC-030 amendment 3 (step 153): a DER element that is not there is a
 * refusal, never a PHP error. Every constructed element of a real timestamp token and of a real OCSP
 * response is emptied, and separately loses its last child; the patches come from DerPatch, which does
 * not use the reader under test. Any PHP warning counts as an escape, so a notice cannot hide one.
 */

/** @return array<string, string> every mutation of $der: each constructed element emptied, and without its last child */
function spec016Mutations(string $der): array
{
    $out = [];
    foreach (DerPatch::constructed($der) as $e) {
        $out["empty@{$e['offset']}"] = DerPatch::splice($der, $e['contents'], $e['length'], '');
        $last = DerPatch::element($der, $e['last']);
        $out["drop-last@{$e['offset']}"] = DerPatch::splice($der, $e['last'], $last['headerLength'] + $last['length'], '', $e['offset']);
    }

    return $out;
}

/**
 * Runs $parse on every mutation; the escapes, as "label: class message at file:line".
 *
 * @param  array<string, string>  $mutations
 * @param  callable(string): mixed  $parse
 * @param  list<class-string<Throwable>>  $refusals  the exceptions that are the parser's own answer
 * @return list<string>
 */
function spec016Escapes(array $mutations, callable $parse, array $refusals): array
{
    $escapes = [];
    set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
        throw new ErrorException($message, 0, $no, $file, $line);
    });
    try {
        foreach ($mutations as $label => $bytes) {
            try {
                $parse($bytes);
            } catch (Throwable $e) {
                $refused = array_filter($refusals, static fn (string $class): bool => $e instanceof $class) !== [];
                if (! $refused) {
                    $escapes[] = sprintf('%s: %s %s at %s:%d', $label, $e::class, $e->getMessage(), basename($e->getFile()), $e->getLine());
                }
            }
        }
    } finally {
        restore_error_handler();
    }

    return $escapes;
}

it('AC11: a timestamp token with any element emptied or cut short is refused, never a PHP error', function (): void {
    $total = 0;
    $escapes = [];
    // RSA, RSA with SHA-384 and ECDSA TSAs: three SignerInfo shapes
    foreach (['c2pa-rs/C.jpg', 'public-testfiles/truepic-20230212-camera.jpg', 'c2pa-rs/ocsp.jpg'] as $file) {
        $value = Corpus::headerValue($file);
        $judge = static fn (string $bytes): mixed => (new TimestampCheck)->judge(TimeStampToken::fromHeaderValue($bytes), 'tbs', null, 'self#jumbf=/c2pa/x/c2pa.signature');
        $judge($value);   // the control: the unchanged token parses
        $mutations = spec016Mutations($value);
        $total += count($mutations);
        $escapes = [...$escapes, ...array_map(static fn (string $e): string => "{$file} {$e}", spec016Escapes($mutations, $judge, [TimestampException::class]))];
    }

    expect($total)->toBeGreaterThan(300)
        ->and($escapes)->toBe([]);
})->group('SPEC-016');

it('AC11: an OCSP response with any element emptied or cut short is skipped, never a PHP error', function (): void {
    $certificate = static function (string $name): Certificate {
        $pem = (string) file_get_contents(Corpus::fixtures()."/ocsp/{$name}.crt");

        return Certificate::fromDer((string) base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem) ?? '', true));
    };
    $chain = [$certificate('signer'), $certificate('ca')];
    $check = static fn (string $bytes): mixed => (new OcspCheck)->check(['rVals' => ['ocspVals' => [new CborBytes($bytes)]]], $chain, null, 'self#jumbf=/c2pa/x/c2pa.signature');
    $total = 0;
    $escapes = [];
    foreach (['good', 'revoked', 'removed'] as $name) {
        $mutations = spec016Mutations((string) file_get_contents(Corpus::fixtures()."/ocsp/{$name}.der"));
        $total += count($mutations);
        $escapes = [...$escapes, ...array_map(static fn (string $e): string => "{$name} {$e}", spec016Escapes($mutations, $check, []))];
    }

    expect($total)->toBeGreaterThan(250)
        ->and($escapes)->toBe([]);
})->group('SPEC-030');
