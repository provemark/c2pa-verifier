<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Asn1\Asn1Exception;
use Provemark\C2paVerifier\Asn1\DerReader;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Support\Bytes;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Tests\Support\DerPatch;
use Provemark\C2paVerifier\Timestamp\TimestampException;
use Provemark\C2paVerifier\Timestamp\TimeStampToken;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-016 amendment 5 and SPEC-015 amendment 6 (step 154): an INTEGER read as a decimal number has at
 * most 256 octets, and the conversion is no longer one decimal digit per hex digit. Real files carry at
 * most 20 (RFC 5280); c2patool accepts a 200-octet certificate serial, and so does this verifier.
 */

/** A DER TLV, built here rather than by the reader under test. */
function spec016Tlv(int $tag, string $contents): string
{
    return pack('C', $tag).DerPatch::length(strlen($contents)).$contents;
}

function spec015SerialVerify(int $octets): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/integer-bound/serial-{$octets}.jpg", 'rb');
    assert($stream !== false);
    $settings = TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/integer-bound/root.settings.json'));

    return (new Verifier)->verify($stream, $settings);
}

it('AC12: an INTEGER of up to 256 octets is read exactly, a longer one is refused before it is converted', function (): void {
    expect(Bytes::hexToDecimal(''))->toBe('0')
        ->and(Bytes::hexToDecimal('000'))->toBe('0')
        ->and(Bytes::hexToDecimal('10000000000000000'))->toBe('18446744073709551616')
        ->and(Bytes::hexToDecimal(str_repeat('f', 64)))->toBe('115792089237316195423570985008687907853269984665640564039457584007913129639935');

    $reader = new DerReader;
    $longest = $reader->read(spec016Tlv(0x02, "\x7f".str_repeat("\xff", 255)))->integer();
    expect(strlen($longest))->toBe(617)
        ->and(substr($longest, 0, 20))->toBe('16158503035655503650');

    expect(fn () => $reader->read(spec016Tlv(0x02, "\x7f".str_repeat("\xff", 256)))->integer())
        ->toThrow(Asn1Exception::class, '256');

    // the review's shape: an 8000-octet INTEGER as SignedData's version took 8.6 s to convert
    $signedData = spec016Tlv(0x30, spec016Tlv(0x02, "\x01".str_repeat("\xff", 7999)).spec016Tlv(0x31, '').spec016Tlv(0x30, '').spec016Tlv(0x31, ''));
    $token = spec016Tlv(0x30, spec016Tlv(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x07\x02").spec016Tlv(0xA0, $signedData));
    $started = hrtime(true);
    expect(fn () => TimeStampToken::fromHeaderValue($token))->toThrow(TimestampException::class, '256');
    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0);
})->group('SPEC-016');

it('AC11: a certificate serial of up to 256 octets is c2patool\'s; a longer one is refused as a bound', function (): void {
    foreach ([200, 256] as $octets) {
        $report = spec015SerialVerify($octets);
        $oracle = spec020Oracle("integer-bound/serial-{$octets}--0.28.0.json");
        $label = $oracle['active_manifest'];
        assert(is_string($label));
        $signatureInfo = spec020OracleManifest("integer-bound/serial-{$octets}--0.28.0.json", $label)['signature_info'];
        assert(is_array($signatureInfo));

        expect($report->result->state)->toBe(ValidationState::Trusted, (string) $octets)
            ->and($oracle['validation_state'])->toBe('Trusted')
            ->and($report->signatureInfo['cert_serial_number'] ?? null)->toBe($signatureInfo['cert_serial_number']);
    }

    $report = spec015SerialVerify(257);
    $invalid = array_values(array_filter($report->result->statuses, static fn ($s): bool => $s->code === StatusCode::SigningCredentialInvalid));
    expect($report->result->state)->toBe(ValidationState::Invalid)
        // the profile check and the chain walk each read the leaf, and each says why it cannot
        ->and($invalid)->not->toBe([])
        ->and(array_filter($invalid, static fn ($s): bool => ! str_contains($s->explanation, 'at most 256')))->toBe([])
        // c2patool reads it; the bound is this verifier's, recorded in docs/comparison.md
        ->and(spec020Oracle('integer-bound/serial-257--0.28.0.json')['validation_state'])->toBe('Trusted');
})->group('SPEC-015');
