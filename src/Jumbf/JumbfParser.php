<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Jumbf;

use Provemark\C2paVerifier\Support\Bytes;

/**
 * The manifest store as a tree of JUMBF boxes (SPEC-005; C2PA 2.4 §11.1).
 *
 * Walks the bytes recursively: every box is validated — LBox, fit inside
 * its parent, the description box's fields — before its children are
 * visited, and the depth and box counters are checked before a child is
 * created. Superboxes with a type UUID this parser does not know, and
 * content boxes of a type it does not know, are kept as UnknownBox and not
 * walked (§11.1.2). Compressed and update manifests are errors: a verifier
 * must not say anything about a manifest it cannot read. Nothing inside a
 * content box is interpreted.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class JumbfParser
{
    public const DEFAULT_MAX_DEPTH = 16;

    public const DEFAULT_MAX_BOXES = 4096;

    public const UUID_MANIFEST_STORE = '63327061-0011-0010-8000-00aa00389b71';   // c2pa

    public const UUID_MANIFEST = '63326d61-0011-0010-8000-00aa00389b71';         // c2ma

    public const UUID_COMPRESSED_MANIFEST = '6332636d-0011-0010-8000-00aa00389b71'; // c2cm

    public const UUID_UPDATE_MANIFEST = '6332756d-0011-0010-8000-00aa00389b71';  // c2um

    public const UUID_TIMESTAMP_MANIFEST = '6332746d-0011-0010-8000-00aa00389b71'; // c2tm (deprecated, §11.2.5)

    public const UUID_ASSERTION_STORE = '63326173-0011-0010-8000-00aa00389b71';  // c2as

    public const UUID_CLAIM = '6332636c-0011-0010-8000-00aa00389b71';            // c2cl

    public const UUID_CLAIM_SIGNATURE = '63326373-0011-0010-8000-00aa00389b71';  // c2cs

    public const UUID_CBOR_ASSERTION = '63626f72-0011-0010-8000-00aa00389b71';   // cbor

    public const UUID_JSON_ASSERTION = '6a736f6e-0011-0010-8000-00aa00389b71';   // json

    public const UUID_EMBEDDED_FILE = '40cb0c32-bb8a-489d-a70b-2ad6f47f4369';

    public const UUID_UUID_ASSERTION = '75756964-0011-0010-8000-00aa00389b71';   // uuid

    /** The superbox types this parser walks into. */
    private const KNOWN_SUPERBOXES = [
        self::UUID_MANIFEST_STORE, self::UUID_MANIFEST, self::UUID_UPDATE_MANIFEST, self::UUID_ASSERTION_STORE,
        self::UUID_CLAIM, self::UUID_CLAIM_SIGNATURE,
        self::UUID_CBOR_ASSERTION, self::UUID_JSON_ASSERTION, self::UUID_EMBEDDED_FILE, self::UUID_UUID_ASSERTION,
    ];

    private const CONTENT_TYPES = ['cbor', 'json', 'bfdb', 'bidb', 'uuid'];

    private const ROOT_LABEL = 'c2pa';

    private const SALT_TYPE = 'c2sh';

    private const LABEL_FORBIDDEN = '/[\x00-\x1F\x7F-\x9F\/;?#\x{FEFF}\x{FFFF}]/u';

    public function __construct(
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public int $maxBoxes = self::DEFAULT_MAX_BOXES,
    ) {}

    /**
     * @param  string  $bytes  the manifest store, as a Container extractor yields it
     *
     * @throws JumbfException on every malformed case
     */
    public function parse(string $bytes): Superbox
    {
        $walk = new JumbfWalk($bytes, $this->maxBoxes);
        $end = strlen($bytes);

        // No parent bounds the root, so its header is read unbounded; the walk
        // below is what notices a root claiming more than the store holds.
        [$lBox, $tBox] = $walk->header(0, PHP_INT_MAX);
        if ($tBox !== 'jumb') {
            throw new JumbfException(sprintf('expected a jumb superbox at offset 0, found %s', Bytes::printable($tBox)));
        }
        // The root has no parent to bound it: its LBox is checked against the
        // store after the walk, so that a root that claims more than the store
        // holds is reported as "its children end before its LBox" (AC10).
        $root = $this->superbox($walk, 0, $lBox, 1, true);
        if ($lBox < $end) {
            throw new JumbfException(sprintf('the root superbox ends at %d but the store holds %d bytes', $lBox, $end));
        }
        if ($root->description->uuid !== self::UUID_MANIFEST_STORE) {
            throw new JumbfException(sprintf(
                'expected the manifest store UUID %s at the root, found %s',
                self::UUID_MANIFEST_STORE,
                $root->description->uuid,
            ));
        }
        if ($root->description->label !== self::ROOT_LABEL) {
            throw new JumbfException(sprintf(
                'expected the root label %s, found %s',
                self::ROOT_LABEL,
                Bytes::printable($root->description->label) === $root->description->label ? $root->description->label : Bytes::hex($root->description->label),
            ));
        }

        return $root;
    }

    /**
     * A superbox whose header has been read and counted. $isRoot lets the
     * root be bounded by the store instead of a parent.
     */
    private function superbox(JumbfWalk $walk, int $offset, int $lBox, int $depth, bool $isRoot = false): Superbox
    {
        if ($depth > $this->maxDepth) {
            throw new JumbfException(sprintf('superbox at offset %d: depth %d exceeds the limit of %d', $offset, $depth, $this->maxDepth));
        }
        $end = $offset + $lBox;
        $readable = $isRoot ? min($end, $walk->size()) : $end;

        // The first child must be the description box.
        [$childLBox, $childTBox] = $walk->header($offset + 8, $readable);
        if ($childTBox !== 'jumd') {
            throw new JumbfException(sprintf(
                'superbox at offset %d: first child is %s, not a description box',
                $offset,
                Bytes::printable($childTBox),
            ));
        }
        $description = $this->description($walk, $offset + 8, $childLBox);
        $this->refuseUnreadable($description, $offset);

        $children = [];
        $p = $offset + 8 + $childLBox;
        while ($p < $end) {
            if ($p + 8 > $readable) {
                throw new JumbfException(sprintf(
                    'superbox at offset %d: its children end at %d but its LBox ends it at %d',
                    $offset,
                    $p,
                    $end,
                ));
            }
            [$childLBox, $childTBox] = $walk->header($p, $readable);
            $children[] = $this->child($walk, $p, $childLBox, $childTBox, $depth);
            $p += $childLBox;
        }
        if ($p !== $end) {
            throw new JumbfException(sprintf('superbox at offset %d: its children end at %d but its LBox ends it at %d', $offset, $p, $end));
        }
        $this->requireBidbAfterBfdb($children);

        return new Superbox($offset, $lBox, $description, $children, $walk->bytes());
    }

    /** A child whose header has been read: a superbox, a content box, or an unknown box. */
    private function child(JumbfWalk $walk, int $offset, int $lBox, string $tBox, int $depth): Superbox|ContentBox|UnknownBox
    {
        if ($tBox === 'jumb') {
            [$descLBox, $descTBox] = $walk->header($offset + 8, $offset + $lBox, false);
            if ($descTBox !== 'jumd') {
                throw new JumbfException(sprintf(
                    'superbox at offset %d: first child is %s, not a description box',
                    $offset,
                    Bytes::printable($descTBox),
                ));
            }
            $description = $this->description($walk, $offset + 8, $descLBox);
            $this->refuseUnreadable($description, $offset);
            if (! in_array($description->uuid, self::KNOWN_SUPERBOXES, true)) {
                return new UnknownBox($offset, $lBox, 'jumb', $description->uuid, $description->label, $walk->slice($offset, $lBox));
            }

            return $this->superbox($walk, $offset, $lBox, $depth + 1);
        }
        if ($tBox === 'jumd') {
            throw new JumbfException(sprintf('box at offset %d: a second description box in one superbox', $offset));
        }
        if ($tBox === 'brob') {
            throw new JumbfException(sprintf('box at offset %d: compressed boxes (brob) are not supported', $offset));
        }
        if (in_array($tBox, self::CONTENT_TYPES, true)) {
            return new ContentBox($offset, $lBox, $tBox, $walk->slice($offset + 8, $lBox - 8));
        }

        return new UnknownBox($offset, $lBox, Bytes::printable($tBox), null, null, $walk->slice($offset, $lBox));
    }

    /**
     * Compressed and time-stamp manifests: an error, never a silent skip (AC13). Update manifests
     * (`c2um`) are read since SPEC-022; `c2cm` needs Brotli, and `c2tm` is deprecated and "not to be
     * … read by manifest consumers" (C2PA 2.4 §11.2.5).
     */
    private function refuseUnreadable(DescriptionBox $description, int $superboxOffset): void
    {
        if ($description->uuid === self::UUID_COMPRESSED_MANIFEST) {
            throw new JumbfException(sprintf('superbox at offset %d: compressed manifests (c2cm) are not supported', $superboxOffset));
        }
        if ($description->uuid === self::UUID_TIMESTAMP_MANIFEST) {
            throw new JumbfException(sprintf('superbox at offset %d: time-stamp manifests (c2tm) are deprecated and not supported (C2PA 2.4 §11.2.5)', $superboxOffset));
        }
    }

    /** A description box whose header has been read (C2PA 2.4 §11.1.4.1; the salt §8.4.2.3). */
    private function description(JumbfWalk $walk, int $offset, int $lBox): DescriptionBox
    {
        $end = $offset + $lBox;
        if ($lBox < 8 + 16 + 1) {
            throw new JumbfException(sprintf('description box at offset %d: LBox %d cannot hold a UUID and a toggles byte', $offset, $lBox));
        }
        $uuid = self::uuid($walk->slice($offset + 8, 16));
        $toggles = ord($walk->slice($offset + 24, 1));
        $p = $offset + 25;

        if (($toggles & ~0x1F) !== 0) {
            throw new JumbfException(sprintf('description box at offset %d: toggles %d set unknown bits', $offset, $toggles));
        }
        if (($toggles & DescriptionBox::TOGGLE_LABEL) === 0) {
            throw new JumbfException(sprintf('description box at offset %d: Label Present is not set', $offset));
        }
        $nul = strpos($walk->bytes(), "\0", $p);
        if ($nul === false || $nul >= $end) {
            throw new JumbfException(sprintf('description box at offset %d: label is not NUL-terminated', $offset));
        }
        $label = $walk->slice($p, $nul - $p);
        if (! mb_check_encoding($label, 'UTF-8') || preg_match(self::LABEL_FORBIDDEN, $label) === 1) {
            throw new JumbfException(sprintf(
                'description box at offset %d: label %s contains a character that is not permitted',
                $offset,
                Bytes::hex($label),
            ));
        }
        $p = $nul + 1;

        $id = null;
        if (($toggles & DescriptionBox::TOGGLE_ID) !== 0) {
            $id = self::u32($walk->slice($p, 4), $offset, $end, $p + 4);
            $p += 4;
        }
        $signature = null;
        if (($toggles & DescriptionBox::TOGGLE_SIGNATURE) !== 0) {
            if ($p + 32 > $end) {
                throw new JumbfException(sprintf('description box at offset %d: the 32-byte signature does not fit', $offset));
            }
            $signature = $walk->slice($p, 32);
            $p += 32;
        }
        $salt = null;
        if (($toggles & DescriptionBox::TOGGLE_PRIVATE) !== 0) {
            [$privateLBox, $privateTBox] = $walk->header($p, $end, false);
            if ($privateTBox !== self::SALT_TYPE) {
                throw new JumbfException(sprintf('description box at offset %d: private box %s is not %s', $offset, Bytes::printable($privateTBox), self::SALT_TYPE));
            }
            $saltLength = $privateLBox - 8;
            if ($saltLength !== 16 && $saltLength !== 32) {
                throw new JumbfException(sprintf('description box at offset %d: salt of %d bytes, expected 16 or 32', $offset, $saltLength));
            }
            $salt = $walk->slice($p + 8, $saltLength);
            $p += $privateLBox;
        }
        if ($p !== $end) {
            throw new JumbfException(sprintf('description box at offset %d: %d bytes after its last field', $offset, $end - $p));
        }

        return new DescriptionBox($offset, $lBox, $uuid, $toggles, $label, $id, $signature, $salt);
    }

    /** @param list<Superbox|ContentBox|UnknownBox> $children */
    private function requireBidbAfterBfdb(array $children): void
    {
        foreach ($children as $i => $child) {
            if ($child instanceof ContentBox && $child->type === 'bfdb') {
                $next = $children[$i + 1] ?? null;
                if (! $next instanceof ContentBox || $next->type !== 'bidb') {
                    throw new JumbfException(sprintf('bfdb at offset %d is not followed by bidb', $child->offset));
                }
            }
        }
    }

    private static function uuid(string $bytes): string
    {
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    private static function u32(string $bytes, int $offset, int $end, int $needed): int
    {
        if ($needed > $end || strlen($bytes) !== 4) {
            throw new JumbfException(sprintf('description box at offset %d: the 4-byte id does not fit', $offset));
        }
        /** @var array{1: int} $u */
        $u = unpack('N', $bytes);

        return $u[1];
    }
}
