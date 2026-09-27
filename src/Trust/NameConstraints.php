<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

use Provemark\C2paVerifier\Asn1\Der;
use Provemark\C2paVerifier\Asn1\TagClass;

/**
 * A CA's nameConstraints (RFC 5280 §4.2.1.10), for the two name forms this verifier evaluates:
 * directoryName, against the subject, and rfc822Name, against the e-mail addresses (SPEC-046).
 * A subtree of any other form, or one with a minimum or maximum, cannot be judged here, and a path
 * through it is not trusted (fail closed, SPEC-046 scope item 4).
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class NameConstraints
{
    /** GeneralName's context tags (RFC 5280 §4.2.1.6), for messages. */
    private const FORMS = [0 => 'otherName', 1 => 'rfc822Name', 2 => 'dNSName', 3 => 'x400Address', 4 => 'directoryName', 5 => 'ediPartyName', 6 => 'uniformResourceIdentifier', 7 => 'iPAddress', 8 => 'registeredID'];

    /**
     * @param  array{permitted: list<list<list<array{0: string, 1: string}>>>, excluded: list<list<list<array{0: string, 1: string}>>>}  $directoryNames
     * @param  array{permitted: list<string>, excluded: list<string>}  $emails
     * @param  list<string>  $unevaluated  the forms present that this verifier does not evaluate
     */
    private function __construct(
        public array $directoryNames,
        public array $emails,
        public array $unevaluated,
    ) {}

    public static function fromDer(Der $value): self
    {
        // NameConstraints ::= SEQUENCE { permittedSubtrees [0] GeneralSubtrees OPTIONAL, excludedSubtrees [1] … }
        $directoryNames = ['permitted' => [], 'excluded' => []];
        $emails = ['permitted' => [], 'excluded' => []];
        $unevaluated = [];
        foreach ($value->sequence() as $subtrees) {
            $kind = match (true) {
                $subtrees->is(TagClass::ContextSpecific, 0) => 'permitted',
                $subtrees->is(TagClass::ContextSpecific, 1) => 'excluded',
                default => null,
            };
            if ($kind === null) {
                $unevaluated[] = $subtrees->describe();

                continue;
            }
            for ($i = 0, $n = $subtrees->childCount(); $i < $n; $i++) {
                // GeneralSubtree ::= SEQUENCE { base GeneralName, minimum [0] DEFAULT 0, maximum [1] OPTIONAL }
                $subtree = $subtrees->child($i);
                $parts = $subtree->sequence();
                $base = $subtree->element(0);
                if (count($parts) > 1) {
                    $unevaluated[] = 'a subtree with a minimum or maximum';

                    continue;
                }
                if ($base->is(TagClass::ContextSpecific, 4)) {
                    $directoryNames[$kind][] = CertificateExtensions::rdns($base->child(0));
                } elseif ($base->is(TagClass::ContextSpecific, 1)) {
                    $emails[$kind][] = strtolower($base->contents);
                } else {
                    $unevaluated[] = $base->class === TagClass::ContextSpecific ? (self::FORMS[$base->tag] ?? $base->describe()) : $base->describe();
                }
            }
        }

        return new self($directoryNames, $emails, array_values(array_unique($unevaluated)));
    }

    /** Why $certificate breaks these constraints, or null when it does not. */
    public function violation(Certificate $certificate): ?string
    {
        $issued = $certificate->x509;
        $subjectCn = $certificate->subjectCn();
        $value = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
        $subject = implode(', ', array_map(static fn (string $k, mixed $v): string => $k.'='.(is_array($v) ? implode('+', array_map($value, $v)) : $value($v)), array_keys($certificate->subject), $certificate->subject));
        if ($this->unevaluated !== []) {
            return sprintf('the name constraint holds %s, a form this verifier does not evaluate, so no certificate below it is trusted', implode(', ', $this->unevaluated));
        }
        if ($issued->subjectRdns !== []) {
            if ($this->directoryNames['permitted'] !== [] && ! self::withinAny($issued->subjectRdns, $this->directoryNames['permitted'])) {
                return sprintf('the subject of %s (%s) is outside every permitted directoryName of the name constraint', $subjectCn, $subject);
            }
            if (self::withinAny($issued->subjectRdns, $this->directoryNames['excluded'])) {
                return sprintf('the subject of %s (%s) is inside an excluded directoryName of the name constraint', $subjectCn, $subject);
            }
        }
        foreach ($issued->emails as $email) {
            if ($this->emails['permitted'] !== [] && array_filter($this->emails['permitted'], static fn (string $c): bool => self::emailMatches($email, $c)) === []) {
                return sprintf('the e-mail address %s of %s is outside every permitted rfc822Name of the name constraint', $email, $subjectCn);
            }
            if (array_filter($this->emails['excluded'], static fn (string $c): bool => self::emailMatches($email, $c)) !== []) {
                return sprintf('the e-mail address %s of %s is inside an excluded rfc822Name of the name constraint', $email, $subjectCn);
            }
        }

        return null;
    }

    /**
     * @param  list<list<array{0: string, 1: string}>>  $name
     * @param  list<list<list<array{0: string, 1: string}>>>  $subtrees
     */
    private static function withinAny(array $name, array $subtrees): bool
    {
        foreach ($subtrees as $base) {
            // within a directoryName subtree: the name begins with the base's RDNs (RFC 5280 §4.2.1.10)
            if (count($base) <= count($name) && array_slice($name, 0, count($base)) === $base) {
                return true;
            }
        }

        return false;
    }

    /** RFC 5280 §4.2.1.10: a mailbox, all mailboxes on a host, or all mailboxes in a domain (a leading dot). */
    private static function emailMatches(string $email, string $constraint): bool
    {
        if (str_contains($constraint, '@')) {
            return $email === $constraint;
        }
        $host = substr($email, (int) strrpos($email, '@') + 1);

        return str_starts_with($constraint, '.') ? str_ends_with($host, $constraint) : $host === $constraint;
    }
}
