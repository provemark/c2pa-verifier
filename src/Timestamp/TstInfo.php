<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Timestamp;

use Provemark\C2paVerifier\Asn1\Asn1Exception;
use Provemark\C2paVerifier\Asn1\Der;
use Provemark\C2paVerifier\Asn1\DerReader;
use Provemark\C2paVerifier\Asn1\TagClass;

/**
 * RFC 3161 §2.4.2 `TSTInfo`, the signed content of a timestamp token
 * (SPEC-016 AC3): what was stamped (the imprint), when (`genTime`), by
 * which policy, with which serial. Version must be 1; the imprint's digest
 * must fit its algorithm; a critical extension is a refusal; an element
 * the grammar does not name is a refusal.
 */
final readonly class TstInfo
{
    /** @var array<string, int> hash OID => digest length in bytes */
    public const DIGEST_LENGTHS = [
        '2.16.840.1.101.3.4.2.1' => 32,   // sha256
        '2.16.840.1.101.3.4.2.2' => 48,   // sha384
        '2.16.840.1.101.3.4.2.3' => 64,   // sha512
    ];

    /**
     * @param  string  $hashAlgorithm  OID
     * @param  string  $serialNumber  decimal
     * @param  int  $genTime  UTC epoch, fractions dropped
     * @param  string|null  $nonce  decimal
     * @param  string|null  $tsa  the GeneralName's DER
     * @param  string|null  $extensions  the Extensions' DER (none critical)
     * @param  string|null  $genTimeFraction  the fractional-second digits of genTime as written, or null (amendment 3)
     */
    public function __construct(
        public int $version,
        public string $policy,
        public string $hashAlgorithm,
        public string $hashedMessage,
        public string $serialNumber,
        public int $genTime,
        public ?TstAccuracy $accuracy,
        public bool $ordering,
        public ?string $nonce,
        public ?string $tsa,
        public ?string $extensions,
        public ?string $genTimeFraction = null,
    ) {}

    /**
     * @param  string  $der  the eContent octets
     *
     * @throws TimestampException
     */
    public static function fromDer(string $der, DerReader $reader): self
    {
        try {
            return self::read($reader->read($der));
        } catch (Asn1Exception $e) {
            throw new TimestampException('TSTInfo: '.$e->getMessage(), 0, $e);
        }
    }

    private static function read(Der $root): self
    {
        $fields = $root->sequence();
        $count = count($fields);
        if ($count < 5) {
            throw new TimestampException(sprintf('TSTInfo has %d fields; version, policy, messageImprint, serialNumber and genTime are required', $count));
        }
        $version = (int) $fields[0]->integer();
        if ($version !== 1) {
            throw new TimestampException(sprintf('TSTInfo version %d is not supported (version 1 only)', $version));
        }
        $policy = $fields[1]->oid();

        $imprint = $fields[2]->sequence();
        if (count($imprint) !== 2) {
            throw new TimestampException(sprintf('messageImprint has %d fields, not hashAlgorithm and hashedMessage', count($imprint)));
        }
        $hashAlgorithm = $imprint[0]->sequence()[0]->oid();
        $hashedMessage = $imprint[1]->octets();
        $expected = self::DIGEST_LENGTHS[$hashAlgorithm] ?? null;
        if ($expected === null) {
            throw new TimestampException(sprintf('messageImprint: hash algorithm %s is not supported', $hashAlgorithm));
        }
        if (strlen($hashedMessage) !== $expected) {
            throw new TimestampException(sprintf('messageImprint: %d bytes is not a %s digest (%d bytes)', strlen($hashedMessage), self::digestName($hashAlgorithm), $expected));
        }

        $serialNumber = $fields[3]->integer();
        try {
            $genTime = $fields[4]->time();
            $genTimeFraction = $fields[4]->timeFraction();
        } catch (Asn1Exception $e) {
            throw new TimestampException('TSTInfo genTime: '.$e->getMessage(), 0, $e);
        }
        if (! $fields[4]->is(TagClass::Universal, Der::GENERALIZED_TIME)) {
            throw new TimestampException(sprintf('TSTInfo genTime at offset %d is %s, not GeneralizedTime', $fields[4]->offset, $fields[4]->describe()));
        }

        // the optional tail, in order: accuracy, ordering, nonce, tsa [0], extensions [1]
        $accuracy = null;
        $ordering = false;
        $nonce = null;
        $tsa = null;
        $extensions = null;
        $i = 5;
        if ($i < $count && $fields[$i]->is(TagClass::Universal, Der::SEQUENCE)) {
            $accuracy = self::accuracy($fields[$i]);
            $i++;
        }
        if ($i < $count && $fields[$i]->is(TagClass::Universal, Der::BOOLEAN)) {
            $ordering = $fields[$i]->boolean();
            $i++;
        }
        if ($i < $count && $fields[$i]->is(TagClass::Universal, Der::INTEGER)) {
            $nonce = $fields[$i]->integer(signed: true);   // a random value, either sign (amendment 3)
            $i++;
        }
        if ($i < $count && $fields[$i]->is(TagClass::ContextSpecific, 0)) {
            $tsa = $fields[$i]->encoded();
            $i++;
        }
        if ($i < $count && $fields[$i]->is(TagClass::ContextSpecific, 1)) {
            $extensions = self::extensions($fields[$i]);
            $i++;
        }
        if ($i < $count) {
            throw new TimestampException(sprintf('TSTInfo has an unexpected %s at offset %d after its known fields', $fields[$i]->describe(), $fields[$i]->offset));
        }

        return new self($version, $policy, $hashAlgorithm, $hashedMessage, $serialNumber, $genTime, $accuracy, $ordering, $nonce, $tsa, $extensions, $genTimeFraction);
    }

    private static function accuracy(Der $der): TstAccuracy
    {
        $seconds = null;
        $millis = null;
        $micros = null;
        foreach ($der->sequence() as $field) {
            if ($field->is(TagClass::Universal, Der::INTEGER)) {
                $seconds = (int) $field->integer();
            } elseif ($field->is(TagClass::ContextSpecific, 0)) {
                $millis = self::smallInteger($field, 'accuracy millis');
            } elseif ($field->is(TagClass::ContextSpecific, 1)) {
                $micros = self::smallInteger($field, 'accuracy micros');
            } else {
                throw new TimestampException(sprintf('accuracy has an unexpected %s at offset %d', $field->describe(), $field->offset));
            }
        }

        return new TstAccuracy($seconds, $millis, $micros);
    }

    /** An IMPLICIT-tagged INTEGER of at most three bytes (millis and micros are 1..999). */
    private static function smallInteger(Der $der, string $what): int
    {
        $bytes = $der->contents;
        if ($der->constructed || $bytes === '' || strlen($bytes) > 3) {
            throw new TimestampException(sprintf('%s at offset %d is not a small INTEGER', $what, $der->offset));
        }

        return (int) hexdec(bin2hex($bytes));
    }

    /** The Extensions' DER, provided none is critical (RFC 3161 §2.4.2: a critical extension the validator does not know is a rejection). */
    private static function extensions(Der $der): string
    {
        if (! $der->constructed) {
            throw new TimestampException(sprintf('TSTInfo extensions at offset %d is not constructed', $der->offset));
        }
        foreach ($der->children ?? [] as $extension) {
            $parts = $extension->sequence();
            $oid = $parts[0]->oid();
            $critical = isset($parts[1]) && $parts[1]->is(TagClass::Universal, Der::BOOLEAN) && $parts[1]->boolean();
            if ($critical) {
                throw new TimestampException(sprintf('TSTInfo carries the critical extension %s, which this verifier does not know', $oid));
            }
        }

        return $der->encoded();
    }

    public static function digestName(string $oid): string
    {
        return match ($oid) {
            '2.16.840.1.101.3.4.2.1' => 'sha256',
            '2.16.840.1.101.3.4.2.2' => 'sha384',
            '2.16.840.1.101.3.4.2.3' => 'sha512',
            default => $oid,
        };
    }
}
