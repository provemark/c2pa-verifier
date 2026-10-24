#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Step 407: fuzzing the manifest graph with stores that are signed again, end to end.
 *
 * bin/fuzz.php flips bytes, and a flipped byte breaks a signature or a hash before the graph code
 * decides anything (step 406). This script builds whole manifest stores instead — a PNG with a caBX
 * chunk, every manifest a claim v2 that is signed correctly — from a random plan: chains, shared
 * ingredients, cycles, a manifest naming itself, references to a manifest that is not there,
 * manifests nothing names, redactions at every level, and in every ingredient assertion recorded
 * validation results that are right, wrong, or about another manifest. The only faults in a store
 * are the ones its plan put there.
 *
 * The oracle is the plan. From it alone, without reading the store, the script predicts every
 * fault the verifier must find and which of them a record may acknowledge: a fault of manifest X
 * only by a record held by a manifest that X lies below, never one of the active manifest (C2PA
 * 2.4 §18.16.12.4; SPEC-021 AC5; SPEC-070 amendment 3); a redaction only by a manifest above its
 * target, never of the active manifest (SPEC-035 amendment 6); a box removed without such a
 * redaction, in any manifest, refuses the store (SPEC-035 amendment 5). That gives a predicted
 * state. The verifier's state is compared with it:
 *
 *   WRONG     the verifier says better than the plan allows (Trusted for Valid, Valid for Invalid)
 *   STRICTER  the verifier says worse; reported apart, not a fault
 *   ORDER     the same store with its other manifests in another order gives another answer
 *   FAULT     an exception, or a PHP warning, notice or deprecation while verifying
 *
 * A fixed calibration runs first: single faults in the active manifest and in an ingredient, the
 * faults step 389 and step 390 fixed, and plans without a fault, which must be Trusted or the
 * generator itself is wrong and the run stops.
 *
 * Keys: a throw-away hierarchy generated for the run in a directory outside the repository (the
 * device-and-inode guard of SPEC-078 amendment 1), read into memory and deleted from disk at once.
 * Decided by Maurice van Loon on 2026-09-21: tooling may sign with throw-away keys; the product
 * never signs. The store is built here, without the verifier's classes, so that one script can
 * judge any version of it: --verifier names the root of the checkout whose vendor/autoload.php is
 * loaded.
 *
 * Deterministic in its plans: run i of seed s draws from its own stream, seeded by "s/i", so a
 * finding replays from its seed and index (the keys and signatures differ per run; the plan does
 * not). Every finding is written out as the PNG, its plan and the verifier's report.
 *
 * With --export <dir> and one or more --only <calibration plan>, the PNGs of those plans are written
 * to <dir> as fixtures (named after the plan), with graph-fuzz-root.pem and
 * graph-fuzz-root.settings.json beside them: the public root of that run, so the fixtures verify
 * under it. Regenerating changes the root and every signature.
 *
 * Usage: php bin/graph-fuzz.php <seed> <graphs> <scratch-dir> [--verifier <root>] [--calibrate-only]
 *        [--export <dir> --only <calibration plan> …]
 * Prints the calibration, one line per finding and a summary; exit code 1 on WRONG, ORDER or FAULT.
 */

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
$verifierOption = dirname(__DIR__);
$calibrateOnly = false;
$exportDir = null;
$only = [];
$positional = [];
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--verifier') {
        $verifierOption = $argv[++$i] ?? '';
    } elseif ($argv[$i] === '--calibrate-only') {
        $calibrateOnly = true;
    } elseif ($argv[$i] === '--export') {
        $exportDir = $argv[++$i] ?? '';
    } elseif ($argv[$i] === '--only') {
        $only[] = $argv[++$i] ?? '';
    } else {
        $positional[] = $argv[$i];
    }
}
if (count($positional) !== 3 || ! ctype_digit($positional[0]) || ! ctype_digit($positional[1]) || ($exportDir !== null && (! is_dir($exportDir) || $only === []))) {
    fwrite(STDERR, "usage: php bin/graph-fuzz.php <seed> <graphs> <scratch-dir outside the repository> [--verifier <root>] [--calibrate-only] [--export <dir> --only <calibration plan> …]\n");
    exit(2);
}
[$seed, $graphs, $scratch] = [(int) $positional[0], (int) $positional[1], $positional[2]];
$repo = dirname(__DIR__);
$verifierRoot = realpath($verifierOption);
$scratchReal = realpath($scratch);
if ($verifierRoot === false || ! is_file($verifierRoot.'/vendor/autoload.php')) {
    fwrite(STDERR, "no vendor/autoload.php under the verifier root {$verifierOption}\n");
    exit(2);
}
if ($scratchReal === false || ! is_dir($scratchReal) || gfInside($scratchReal, $repo) || gfInside($scratchReal, $verifierRoot)) {
    fwrite(STDERR, "the scratch directory must exist and lie outside this repository and the verifier's\n");
    exit(2);
}
require $verifierRoot.'/vendor/autoload.php';

use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\Verifier;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/** Whether $dir is $repo or lies inside it, compared by device and inode (SPEC-078 amendment 1). */
function gfInside(string $dir, string $repo): bool
{
    $target = @stat($repo);
    if ($target === false) {
        return true;
    }
    for ($current = $dir; ; $current = dirname($current)) {
        $here = @stat($current);
        if ($here === false || ($here['dev'] === $target['dev'] && $here['ino'] === $target['ino'])) {
            return true;
        }
        if (dirname($current) === $current) {
            return false;
        }
    }
}

function gfRun(string ...$parts): void
{
    $command = implode(' ', array_map('escapeshellarg', $parts));
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

// ---- CBOR, JUMBF and COSE, written here ----

/** A byte string to be encoded as CBOR major type 2 (a PHP string encodes as text). */
final readonly class GfBytes
{
    public function __construct(public string $bytes) {}
}

/** An unsigned integer always encoded in four bytes, so that a store's length does not move with its value. */
final readonly class GfU32
{
    public function __construct(public int $value) {}
}

/** The CBOR head for major type $mt and argument $n. */
function gfHead(int $mt, int $n): string
{
    $mt <<= 5;

    return match (true) {
        $n < 24 => pack('C', $mt | $n),
        $n < 256 => pack('CC', $mt | 24, $n),
        $n < 65536 => pack('Cn', $mt | 25, $n),
        default => pack('CN', $mt | 26, $n),
    };
}

/** A small CBOR encoder: ints, text, bytes, fixed u32, null, bool, lists and maps in the order given. */
function gfCbor(mixed $v): string
{
    if (is_array($v) && ! array_is_list($v)) {
        $pairs = '';
        foreach ($v as $key => $value) {
            $pairs .= gfCbor($key).gfCbor($value);
        }

        return gfHead(5, count($v)).$pairs;
    }

    return match (true) {
        $v instanceof GfBytes => gfHead(2, strlen($v->bytes)).$v->bytes,
        $v instanceof GfU32 => "\x1a".pack('N', $v->value),
        is_string($v) => gfHead(3, strlen($v)).$v,
        is_int($v) => $v >= 0 ? gfHead(0, $v) : gfHead(1, -1 - $v),
        $v === null => "\xf6",
        is_bool($v) => $v ? "\xf5" : "\xf4",
        is_array($v) => gfHead(4, count($v)).implode('', array_map(gfCbor(...), $v)),
        default => throw new RuntimeException('cannot encode '.get_debug_type($v)),
    };
}

function gfBox(string $type, string $data): string
{
    return pack('N', 8 + strlen($data)).$type.$data;
}

/** A JUMBF superbox: a description box (type UUID, toggles 3 = requestable + label) and its children. */
function gfSuper(string $uuidPrefix, string $label, string ...$children): string
{
    $uuid = (string) hex2bin($uuidPrefix.'00110010800000aa00389b71');

    return gfBox('jumb', gfBox('jumd', $uuid."\x03".$label."\0").implode('', $children));
}

/** The hash a hashed URI or an ingredient reference states over a box: its payload, the header left out. */
function gfBoxHash(string $box): string
{
    return hash('sha256', substr($box, 8), true);
}

/** A DER ECDSA signature as R‖S of 2 × 32 bytes (what COSE carries for ES256). */
function gfDerToRs(string $der): string
{
    $p = (ord($der[1]) & 0x80) !== 0 ? 2 + (ord($der[1]) & 0x7F) : 2;
    $rs = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$p + 1]);
        $rs .= str_pad(ltrim(substr($der, $p + 2, $len), "\0"), 32, "\0", STR_PAD_LEFT);
        $p += 2 + $len;
    }

    return $rs;
}

/** A COSE_Sign1 over $claimBytes, detached, ES256, x5chain [leaf, root] in the protected header (C2PA 2.4 §14.6). */
function gfCose(string $claimBytes, GfSigner $signer): string
{
    $protected = "\xa2\x01\x26\x18\x21\x82".gfCbor(new GfBytes($signer->leaf)).gfCbor(new GfBytes($signer->root));
    $tbs = gfCbor(['Signature1', new GfBytes($protected), new GfBytes(''), new GfBytes($claimBytes)]);
    if (! openssl_sign($tbs, $der, $signer->key, OPENSSL_ALGO_SHA256) || ! is_string($der)) {
        throw new RuntimeException('openssl_sign failed');
    }

    return "\xd2\x84".gfCbor(new GfBytes($protected))."\xa1\x63pad\x40\xf6".gfCbor(new GfBytes(gfDerToRs($der)));
}

/** One byte of $s changed at $at. */
function gfFlip(string $s, int $at): string
{
    $s[$at] = chr(ord($s[$at]) ^ 0x01);

    return $s;
}

// ---- the plan ----

const GF_PROBE = 'org.provemark.probe';   // the assertion a redaction removes
const GF_NOTE = 'org.provemark.note';     // the assertion a wrong hashed URI names

/** A key of the throw-away hierarchy and the chain it signs with. */
final readonly class GfSigner
{
    public function __construct(public OpenSSLAsymmetricKey $key, public string $leaf, public string $root) {}
}

/** A validation status an ingredient assertion records: its kind is the list it is filed under. */
final readonly class GfRecord
{
    public function __construct(public string $code, public string $url, public string $kind, public string $where) {}
}

/** One ingredient assertion: its target is a manifest id, 'missing' (a label the store does not hold) or null. */
final class GfIngredient
{
    /** @param list<GfRecord> $records */
    public function __construct(
        public string $rel,
        public ?string $target,
        public ?string $missing = null,
        public bool $badHash = false,
        public array $records = [],
    ) {}
}

/** One manifest: its label, its key ('good', 'untrusted', 'expired'), its faults ('sig', 'uri', 'binding'). */
final class GfManifest
{
    /**
     * @param  list<string>  $faults
     * @param  list<GfIngredient>  $ingredients
     * @param  list<string>  $redacts  the manifests whose probe this one redacts
     */
    public function __construct(
        public string $label,
        public string $key = 'good',
        public array $faults = [],
        public array $ingredients = [],
        public array $redacts = [],
    ) {}
}

/** A whole plan: the active manifest, every manifest by id, and the store order (the active one last). */
final readonly class GfPlan
{
    /**
     * @param  array<string, GfManifest>  $manifests
     * @param  list<string>  $order
     */
    public function __construct(public string $active, public array $manifests, public array $order) {}

    public function manifest(string $id): GfManifest
    {
        return $this->manifests[$id] ?? throw new RuntimeException("no manifest {$id} in the plan");
    }

    public function targetLabel(GfIngredient $ingredient): ?string
    {
        return match ($ingredient->target) {
            null => null,
            'missing' => $ingredient->missing,
            default => $this->manifest($ingredient->target)->label,
        };
    }
}

/** A fault the verifier must report; $about is the manifest it is about, null where nothing may acknowledge it. */
final readonly class GfFault
{
    public function __construct(public string $code, public string $url, public ?string $about, public bool $acknowledged = false) {}

    public function key(): string
    {
        return $this->code.' '.$this->url;
    }
}

/** The URL of a manifest, and of a part of one, as the verifier's statuses name them. */
function gfUrl(string $label, string $part = ''): string
{
    return 'self#jumbf=/c2pa/'.$label.($part === '' ? '' : '/'.$part);
}

function gfIngredientLabel(int $i): string
{
    return 'c2pa.ingredient.v3'.($i === 0 ? '' : '__'.$i);
}

function gfUrn(Randomizer $r): string
{
    $h = bin2hex($r->getBytes(16));

    return sprintf('urn:c2pa:%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
}

/**
 * A random plan. Manifests m0…mN, m0 the active one; each with a key, its own faults, its
 * ingredients (relationship, target, a wrong hash or not) and the manifests whose probe it redacts.
 * The records come after the faults are known (gfRecords), so that most of them aim at a real fault.
 */
function gfPlan(Randomizer $r): GfPlan
{
    $count = $r->getInt(1, 6);
    $ids = array_map(static fn (int $i): string => 'm'.$i, range(0, $count - 1));
    $chance = static fn (int $percent): bool => $r->getInt(1, 100) <= $percent;
    $manifests = [];
    foreach ($ids as $id) {
        $active = $id === 'm0';
        $key = $r->getInt(1, 100);
        $faults = [];
        foreach (['sig' => 7, 'uri' => 7] as $fault => $percent) {
            if ($chance($percent)) {
                $faults[] = $fault;
            }
        }
        if ($active && $chance(4)) {
            $faults[] = 'binding';
        }
        $ingredients = [];
        $parent = false;
        for ($k = $r->getInt(0, 3); $k > 0; $k--) {
            $roll = $r->getInt(1, 100);
            $target = match (true) {
                $roll <= 6 => $id,                  // itself: a cycle of one
                $roll <= 11 => 'missing',           // a manifest the store does not hold
                $roll <= 20 => null,                // no manifest at all
                default => $ids[$r->getInt(0, $count - 1)],
            };
            $relationship = ! $parent && $chance(50) ? 'parentOf' : ($target === null && $chance(50) ? 'inputTo' : 'componentOf');
            $parent = $parent || $relationship === 'parentOf';
            $ingredients[] = new GfIngredient($relationship, $target, $target === 'missing' ? gfUrn($r) : null, $target !== null && $chance(6));
        }
        $redacts = $chance(10) ? [$ids[$r->getInt(0, $count - 1)]] : [];
        $signer = $key <= ($active ? 90 : 80) ? 'good' : ($key <= ($active ? 95 : 90) ? 'untrusted' : 'expired');
        $manifests[$id] = new GfManifest(gfUrn($r), $signer, $faults, $ingredients, $redacts);
    }
    /** @var list<string> $others */
    $others = $r->shuffleArray(array_values(array_diff($ids, ['m0'])));
    $plan = new GfPlan('m0', $manifests, [...$others, 'm0']);
    gfRecords($plan, $r);

    return $plan;
}

/**
 * The records of every ingredient assertion: mostly a key of a fault the plan predicts (of any
 * manifest — the one below, the holder itself, the active one, a sibling), filed under its own kind
 * or another, its url absolute or relative to the ingredient's manifest; sometimes a success line.
 */
function gfRecords(GfPlan $plan, Randomizer $r): void
{
    $universe = array_values(array_filter(gfFaults($plan), static fn (GfFault $f): bool => $f->code !== '*'));
    $labels = array_values(array_map(static fn (GfManifest $m): string => $m->label, $plan->manifests));
    foreach ($plan->manifests as $m) {
        foreach ($m->ingredients as $ingredient) {
            $recorded = $r->getInt(1, 100);
            if ($recorded > 65) {
                continue;
            }
            for ($n = $r->getInt(1, 3); $n > 0; $n--) {
                $aimed = $r->getInt(1, 100);
                [$code, $url] = $universe !== [] && $aimed <= 85
                    ? [($f = $universe[$r->getInt(0, count($universe) - 1)])->code, $f->url]
                    : ['claimSignature.validated', gfUrl($labels[$r->getInt(0, count($labels) - 1)], 'c2pa.signature')];
                $misfiled = $r->getInt(1, 100);
                $kind = $misfiled <= 10 ? ['success', 'informational', 'failure'][$r->getInt(0, 2)] : gfKind($code);
                $target = $plan->targetLabel($ingredient);
                $relative = $r->getInt(1, 100);
                if ($target !== null && str_starts_with($url, gfUrl($target).'/') && $relative <= 40) {
                    $url = 'self#jumbf='.substr($url, strlen(gfUrl($target).'/'));   // relative: resolved against the ingredient's manifest
                }
                $where = $r->getInt(1, 100);
                $ingredient->records[] = new GfRecord($code, $url, $kind, $where <= 70 ? 'activeManifest' : 'delta');
            }
        }
    }
}

/** The kind a status code belongs to (C2PA 2.4 §15, the codes this script uses). */
function gfKind(string $code): string
{
    return match ($code) {
        'claimSignature.validated', 'ingredient.manifest.validated' => 'success',
        'ingredient.unknownProvenance' => 'informational',
        default => 'failure',
    };
}

// ---- the oracle: what the plan says, without the store ----

/**
 * The walk the graph makes (SPEC-020): from the active manifest, depth first, each manifest entered
 * once, a reference back into the path cyclic and not followed, one to a manifest not in the store
 * missing. Returns the manifests reached, every reference followed, and the cyclic and missing ones.
 *
 * @return array{reached: list<string>, references: list<array{from: string, k: int, to: string}>, cyclic: list<array{from: string, k: int}>, missing: list<array{from: string, k: int}>}
 */
function gfWalk(GfPlan $plan): array
{
    $out = ['reached' => [], 'references' => [], 'cyclic' => [], 'missing' => []];
    $visited = [];
    $descend = static function (string $id, array $path) use (&$descend, &$out, &$visited, $plan): void {
        /** @var list<string> $path */
        $visited[$id] = true;
        $out['reached'][] = $id;
        $path[] = $id;
        foreach ($plan->manifest($id)->ingredients as $k => $ingredient) {
            $to = $ingredient->target;
            if ($to === null) {
                continue;
            }
            if ($to === 'missing') {
                $out['missing'][] = ['from' => $id, 'k' => $k];
            } elseif (in_array($to, $path, true)) {
                $out['cyclic'][] = ['from' => $id, 'k' => $k];
            } else {
                $out['references'][] = ['from' => $id, 'k' => $k, 'to' => $to];
                if (! isset($visited[$to])) {
                    $descend($to, $path);
                }
            }
        }
    };
    $descend($plan->active, []);

    return $out;
}

/**
 * The manifests below $id: every manifest an ingredient reference leads to, followed on, cycles
 * included, $id itself left out (what a manifest may speak for and redact).
 *
 * @return list<string>
 */
function gfBelow(GfPlan $plan, string $id): array
{
    $seen = [];
    $queue = [$id];
    while ($queue !== []) {
        $current = array_pop($queue);
        foreach ($plan->manifest($current)->ingredients as $ingredient) {
            $to = $ingredient->target;
            if ($to !== null && $to !== 'missing' && ! isset($seen[$to])) {
                $seen[$to] = true;
                $queue[] = $to;
            }
        }
    }
    unset($seen[$id]);

    return array_map('strval', array_keys($seen));
}

/**
 * Whether the probe of $id is redacted in a way the verifier must accept: declared by a manifest the
 * walk reaches, which $id lies below, and $id not the active one (SPEC-035 amendment 6).
 *
 * @param  list<string>  $reached
 */
function gfLegitRedaction(GfPlan $plan, string $id, array $reached): bool
{
    foreach ($plan->manifests as $by => $m) {
        if (in_array($id, $m->redacts, true) && $by !== $id && $id !== $plan->active && in_array($by, $reached, true) && in_array($id, gfBelow($plan, (string) $by), true)) {
            return true;
        }
    }

    return false;
}

/** @param list<string> $reached Whether the probe of $id is removed (some manifest redacts it) without a redaction that may take it. */
function gfRemovedUnredacted(GfPlan $plan, string $id, array $reached): bool
{
    foreach ($plan->manifests as $m) {
        if (in_array($id, $m->redacts, true)) {
            return ! gfLegitRedaction($plan, $id, $reached);
        }
    }

    return false;
}

/**
 * Every fault the verifier must report, each with the manifest it is about (null: no manifest can
 * be named, so nothing may acknowledge it) and a code ('*' where only the verdict is predicted).
 *
 * @return list<GfFault>
 */
function gfFaults(GfPlan $plan): array
{
    $walk = gfWalk($plan);
    $faults = [];
    foreach ($walk['reached'] as $id) {
        $m = $plan->manifest($id);
        if (in_array('sig', $m->faults, true)) {
            $faults[] = new GfFault('claimSignature.mismatch', gfUrl($m->label, 'c2pa.signature'), $id);
        }
        if (in_array('uri', $m->faults, true)) {
            $faults[] = new GfFault('assertion.hashedURI.mismatch', gfUrl($m->label, 'c2pa.assertions/'.GF_NOTE), $id);
        }
        if (in_array('binding', $m->faults, true)) {
            $faults[] = new GfFault('*', gfUrl($m->label), null);
        }
        if ($m->key !== 'good') {
            $faults[] = new GfFault($m->key === 'untrusted' ? 'signingCredential.untrusted' : 'signingCredential.expired', gfUrl($m->label, 'c2pa.signature'), $id);
        }
    }
    // a box gone that no redaction may take, in any manifest of the store, reached or not: every manifest is read
    // first and redactions count only from those the walk reaches, so the store cannot be read (SPEC-035
    // amendment 5), and no record speaks for that
    foreach (array_keys($plan->manifests) as $id) {
        if (gfRemovedUnredacted($plan, (string) $id, $walk['reached'])) {
            $faults[] = new GfFault('assertion.missing', 'self#jumbf=/c2pa', null);
        }
    }
    // a reached manifest that lists its own probe as redacted, where another redaction may take the box so the
    // store is read: its claim's self-redaction (SPEC-035 AC4), whoever else redacts the box
    foreach ($walk['reached'] as $id) {
        if (in_array($id, $plan->manifest($id)->redacts, true) && ! gfRemovedUnredacted($plan, $id, $walk['reached'])) {
            $faults[] = new GfFault('assertion.selfRedacted', gfUrl($plan->manifest($id)->label, 'c2pa.assertions/'.GF_PROBE), $id);
        }
    }
    // every reference whose recorded hash is wrong: the box hash, or the claim-signature hash for a manifest redacted from
    foreach ($walk['references'] as $ref) {
        if ($plan->manifest($ref['from'])->ingredients[$ref['k']]->badHash) {
            $legit = gfLegitRedaction($plan, $ref['to'], $walk['reached']);
            $faults[] = new GfFault($legit ? 'ingredient.claimSignature.mismatch' : 'ingredient.manifest.mismatch', gfUrl($plan->manifest($ref['to'])->label), $ref['to']);
        }
    }
    // a reference that closes a cycle: no record may speak for it (SPEC-070 amendment 4; c2patool refuses the store)
    foreach ($walk['cyclic'] as $ref) {
        $faults[] = new GfFault('assertion.ingredient.malformed', gfUrl($plan->manifest($ref['from'])->label, 'c2pa.assertions/'.gfIngredientLabel($ref['k'])), null);
    }
    foreach ($walk['missing'] as $ref) {
        $faults[] = new GfFault('ingredient.manifest.missing', (string) $plan->manifest($ref['from'])->ingredients[$ref['k']]->missing, null);
    }

    return $faults;
}

/**
 * The predicted state. A fault about manifest X is acknowledged only by a record of its code and url,
 * filed under its own kind, held by a reached manifest that X lies below, and never when X is the
 * active manifest; a relative url in a record is resolved against its ingredient's manifest.
 *
 * @return array{state: string, faults: list<GfFault>}
 */
function gfPredict(GfPlan $plan): array
{
    $records = [];
    foreach (gfWalk($plan)['reached'] as $holder) {
        $below = gfBelow($plan, $holder);
        foreach ($plan->manifest($holder)->ingredients as $ingredient) {
            $target = $plan->targetLabel($ingredient);
            foreach ($ingredient->records as $record) {
                $url = $record->url;
                if ($target !== null && str_starts_with($url, 'self#jumbf=') && ! str_starts_with($url, 'self#jumbf=/')) {
                    $url = gfUrl($target, substr($url, strlen('self#jumbf=')));
                }
                if ($record->kind === gfKind($record->code)) {
                    $records[] = ['key' => $record->code.' '.$url, 'below' => $below];
                }
            }
        }
    }
    $faults = [];
    $invalid = false;
    $untrusted = false;
    foreach (gfFaults($plan) as $fault) {
        $acknowledged = false;
        if ($fault->about !== null && $fault->about !== $plan->active && $fault->code !== '*') {
            foreach ($records as $record) {
                $acknowledged = $acknowledged || ($record['key'] === $fault->key() && in_array($fault->about, $record['below'], true));
            }
        }
        $faults[] = new GfFault($fault->code, $fault->url, $fault->about, $acknowledged);
        if (! $acknowledged) {
            $invalid = $invalid || $fault->code !== 'signingCredential.untrusted';
            $untrusted = $untrusted || $fault->code === 'signingCredential.untrusted';
        }
    }

    return ['state' => $invalid ? 'Invalid' : ($untrusted ? 'Valid' : 'Trusted'), 'faults' => $faults];
}

// ---- the builder ----

/**
 * The manifest boxes of a plan, by id. Each manifest is built after every manifest it names that is
 * not on its own build path, so that each reference states the hash of the box as it ends up; a
 * reference back into the path (a cycle, which the verifier does not follow) states zeros. The
 * active manifest is built twice: its exclusion needs the store's length, held in a fixed-width u32.
 *
 * @param  array<string, GfSigner>  $signers
 * @return array<string, string>
 */
function gfBuild(GfPlan $plan, array $signers, string $png): array
{
    $boxes = [];
    $signatures = [];
    $onPath = [];
    $build = static function (string $id, int $storeLength) use (&$build, &$boxes, &$signatures, &$onPath, $plan, $signers, $png): void {
        $onPath[$id] = true;
        $m = $plan->manifest($id);
        foreach ($m->ingredients as $ingredient) {
            $to = $ingredient->target;
            if ($to !== null && $to !== 'missing' && ! isset($boxes[$to]) && ! isset($onPath[$to])) {
                $build($to, 0);
            }
        }
        $assertions = [];   // label => box, in store order
        // ingredients first: the actions name the parentOf one by its hash
        foreach ($m->ingredients as $k => $ingredient) {
            $data = ['dc:title' => $id.'-'.$k.'.png', 'dc:format' => 'image/png', 'relationship' => $ingredient->rel];
            $target = $plan->targetLabel($ingredient);
            if ($target !== null) {
                $to = $ingredient->target;
                $box = $to !== null && $to !== 'missing' ? ($boxes[$to] ?? null) : null;
                $signature = $to !== null && $to !== 'missing' ? ($signatures[$to] ?? null) : null;
                $manifestHash = $box === null ? str_repeat("\0", 32) : gfBoxHash($box);
                $signatureHash = $signature === null ? str_repeat("\0", 32) : gfBoxHash($signature);
                if ($ingredient->badHash) {
                    $manifestHash = gfFlip($manifestHash, 0);
                    $signatureHash = gfFlip($signatureHash, 0);
                }
                $data['activeManifest'] = ['url' => gfUrl($target), 'hash' => new GfBytes($manifestHash)];
                $data['claimSignature'] = ['url' => gfUrl($target, 'c2pa.signature'), 'hash' => new GfBytes($signatureHash)];
            }
            $lists = static fn (): array => ['success' => [], 'informational' => [], 'failure' => []];
            $active = $lists();
            $delta = $lists();
            foreach ($ingredient->records as $record) {
                $entry = ['code' => $record->code, 'url' => $record->url, 'explanation' => 'recorded by graph-fuzz'];
                if ($record->where === 'activeManifest') {
                    $active[$record->kind][] = $entry;
                } else {
                    $delta[$record->kind][] = $entry;
                }
            }
            $deltas = $delta === $lists() ? [] : [['ingredientAssertionURI' => gfUrl($target ?? $m->label, 'c2pa.assertions/c2pa.ingredient.v3'), 'validationDeltas' => $delta]];
            $data['validationResults'] = ['activeManifest' => $active, 'ingredientDeltas' => $deltas];
            $assertions[gfIngredientLabel($k)] = gfSuper('63626f72', gfIngredientLabel($k), gfBox('cbor', gfCbor($data)));
        }
        $opening = ['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture'];
        foreach ($m->ingredients as $k => $ingredient) {
            if ($ingredient->rel === 'parentOf') {
                $opening = ['action' => 'c2pa.opened', 'parameters' => ['ingredients' => [['url' => 'self#jumbf=c2pa.assertions/'.gfIngredientLabel($k), 'hash' => new GfBytes(gfBoxHash($assertions[gfIngredientLabel($k)]))]]]];
            }
        }
        $actions = [$opening];
        $redacted = [];
        foreach ($m->redacts as $of) {
            $uri = gfUrl($plan->manifest($of)->label, 'c2pa.assertions/'.GF_PROBE);
            $redacted[] = $uri;
            $actions[] = ['action' => 'c2pa.redacted', 'parameters' => ['redacted' => $uri]];
        }
        $assertions = ['c2pa.actions.v2' => gfSuper('63626f72', 'c2pa.actions.v2', gfBox('cbor', gfCbor(['actions' => $actions]))), ...$assertions];
        $assertions[GF_PROBE] = gfSuper('63626f72', GF_PROBE, gfBox('cbor', gfCbor(['manifest' => $id])));
        $assertions[GF_NOTE] = gfSuper('63626f72', GF_NOTE, gfBox('cbor', gfCbor(['note' => 'graph-fuzz'])));
        if (! in_array('binding', $m->faults, true)) {
            // the active manifest binds this PNG: the caBX chunk at 33 excluded, the rest is the unsigned file;
            // an ingredient's binding covers its own asset, which the verifier does not check (§15.11.3.3.1)
            $isActive = $id === $plan->active;
            $assertions['c2pa.hash.data'] = gfSuper('63626f72', 'c2pa.hash.data', gfBox('cbor', gfCbor([
                'exclusions' => [['start' => new GfU32(33), 'length' => new GfU32($isActive ? 12 + $storeLength : 1000)]],
                'name' => 'jumbf manifest',
                'alg' => 'sha256',
                'hash' => new GfBytes($isActive ? hash('sha256', $png, true) : hash('sha256', $id, true)),
                'pad' => new GfBytes(str_repeat("\0", 8)),
            ])));
        }
        $created = [];
        foreach ($assertions as $assertionLabel => $box) {
            $hash = gfBoxHash($box);
            if ($assertionLabel === GF_NOTE && in_array('uri', $m->faults, true)) {
                $hash = gfFlip($hash, 0);
            }
            $created[] = ['url' => 'self#jumbf=c2pa.assertions/'.$assertionLabel, 'hash' => new GfBytes($hash)];
        }
        foreach ($plan->manifests as $other) {
            if (in_array($id, $other->redacts, true)) {
                unset($assertions[GF_PROBE]);   // redacted and removed: the claim still lists it
            }
        }
        $claim = [
            'instanceID' => 'xmp:iid:'.substr($m->label, 9),
            'claim_generator_info' => ['name' => 'c2pa-verifier graph-fuzz', 'version' => '0'],
            'signature' => gfUrl($m->label, 'c2pa.signature'),
            'created_assertions' => $created,
            'dc:title' => $id.'.png',
            'alg' => 'sha256',
        ];
        if ($redacted !== []) {
            $claim['redacted_assertions'] = $redacted;
        }
        $claimBytes = gfCbor($claim);
        $cose = gfCose($claimBytes, $signers[$m->key] ?? throw new RuntimeException("no signer {$m->key}"));
        if (in_array('sig', $m->faults, true)) {
            $cose = gfFlip($cose, strlen($cose) - 8);   // inside the signature, after signing
        }
        $signatures[$id] = gfSuper('63326373', 'c2pa.signature', gfBox('cbor', $cose));
        $boxes[$id] = gfSuper('63326d61', $m->label,
            gfSuper('63326173', 'c2pa.assertions', ...array_values($assertions)),
            gfSuper('6332636c', 'c2pa.claim.v2', gfBox('cbor', $claimBytes)),
            $signatures[$id],
        );
        unset($onPath[$id]);
    };
    $build($plan->active, 0);
    foreach (array_keys($plan->manifests) as $id) {
        if (! isset($boxes[$id])) {
            $build((string) $id, 0);
        }
    }
    $first = $boxes[$plan->active];
    $length = strlen(gfStore($plan->order, $boxes));
    unset($boxes[$plan->active]);
    $build($plan->active, $length);
    if (strlen($boxes[$plan->active]) !== strlen($first)) {
        throw new RuntimeException('the active manifest changed size when its exclusion was filled in');
    }

    return $boxes;
}

/**
 * @param  list<string>  $order
 * @param  array<string, string>  $boxes
 */
function gfStore(array $order, array $boxes): string
{
    return gfSuper('63327061', 'c2pa', ...array_map(static fn (string $id): string => $boxes[$id] ?? throw new RuntimeException("no box for {$id}"), $order));
}

/** The unsigned PNG with a caBX chunk after IHDR (at 33). */
function gfPng(string $png, string $store): string
{
    return substr($png, 0, 33).pack('N', strlen($store)).'caBX'.$store.pack('N', crc32('caBX'.$store)).substr($png, 33);
}

// ---- judging ----

/**
 * The verifier's answer: its state and the failures it counted, sorted, or the fault it raised.
 *
 * @return array{state: string, failures: list<string>, report: string, fault: ?string}
 */
function gfJudge(string $png, TrustSettings $settings): array
{
    $stream = fopen('php://memory', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('no memory stream');
    }
    fwrite($stream, $png);
    rewind($stream);
    set_error_handler(static function (int $level, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $level, $file, $line);
    });
    try {
        $report = (new Verifier)->verify($stream, $settings);
        $json = $report->toJson();
    } catch (Throwable $e) {
        return ['state' => 'FAULT', 'failures' => [], 'report' => '', 'fault' => get_class($e).': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine()];
    } finally {
        restore_error_handler();
        fclose($stream);
    }
    $failures = [];
    foreach ($report->result->statuses as $status) {
        if ($status->code->isFailure() && ! $status->acknowledged) {
            $failures[] = $status->code->value.' '.$status->url;
        }
    }
    sort($failures);

    return ['state' => $report->result->state->value, 'failures' => $failures, 'report' => $json, 'fault' => null];
}

/** @param list<string> $keys "code url" keys, as their codes */
function gfCodes(array $keys): string
{
    return implode(', ', array_map(static fn (string $k): string => explode(' ', $k)[0], $keys)) ?: '-';
}

function gfRank(string $state): int
{
    return match ($state) {
        'Invalid' => 0,
        'Valid' => 1,
        'Trusted' => 2,
        default => -1,
    };
}

// ---- the throw-away hierarchy ----

$keyDir = $scratchReal.'/graph-fuzz-keys-'.bin2hex(random_bytes(4));
if (! mkdir($keyDir, 0700)) {
    throw new RuntimeException("cannot create {$keyDir}");
}
register_shutdown_function(static function () use ($keyDir): void {
    foreach (glob("{$keyDir}/*") ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($keyDir)) {
        rmdir($keyDir);
        echo "keys deleted: {$keyDir}\n";
    }
});
// OpenSSL 4 writes no AuthorityKeyIdentifier unless asked, and SPEC-015's profile requires one
file_put_contents("{$keyDir}/ext.cnf", <<<'CNF'
[req]
distinguished_name = dn
[dn]
[v3_root]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign, digitalSignature
subjectKeyIdentifier = hash
[good]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, nonRepudiation
extendedKeyUsage = emailProtection
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
CNF);
$subject = static fn (string $cn): string => '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN='.$cn;
foreach (['root' => 'Throw-away Root (graph fuzz)', 'other' => 'Throw-away Root not trusted (graph fuzz)'] as $name => $cn) {
    gfRun('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keyDir}/{$name}.key");
    gfRun('openssl', 'req', '-x509', '-new', '-key', "{$keyDir}/{$name}.key", '-subj', $subject($cn), '-days', '3650', '-config', "{$keyDir}/ext.cnf", '-extensions', 'v3_root', '-out', "{$keyDir}/{$name}.pem");
}
foreach (['good' => ['root', ['-days', '3650']], 'expired' => ['root', ['-not_before', '20200101000000Z', '-not_after', '20210101000000Z']], 'untrusted' => ['other', ['-days', '3650']]] as $name => [$issuer, $validity]) {
    gfRun('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keyDir}/{$name}.key");
    gfRun('openssl', 'req', '-new', '-key', "{$keyDir}/{$name}.key", '-subj', $subject("graph fuzz {$name} signer"), '-config', "{$keyDir}/ext.cnf", '-out', "{$keyDir}/{$name}.csr");
    gfRun('openssl', 'x509', '-req', '-in', "{$keyDir}/{$name}.csr", '-CA', "{$keyDir}/{$issuer}.pem", '-CAkey', "{$keyDir}/{$issuer}.key", '-set_serial', (string) random_int(1000, 999999),
        '-extfile', "{$keyDir}/ext.cnf", '-extensions', 'good', '-out', "{$keyDir}/{$name}.pem", ...$validity);
}
$der = static fn (string $file): string => (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', (string) file_get_contents($file)), true);
$signers = [];
foreach (['good' => 'root', 'expired' => 'root', 'untrusted' => 'other'] as $name => $issuer) {
    $key = openssl_pkey_get_private((string) file_get_contents("{$keyDir}/{$name}.key"));
    if ($key === false) {
        throw new RuntimeException("cannot read the {$name} key");
    }
    $signers[$name] = new GfSigner($key, $der("{$keyDir}/{$name}.pem"), $der("{$keyDir}/{$issuer}.pem"));
}
$rootPem = (string) file_get_contents("{$keyDir}/root.pem");
foreach (glob("{$keyDir}/*.key") ?: [] as $file) {
    unlink($file);   // in memory from here on
}
$settingsJson = (string) json_encode([
    'trust' => ['trust_anchors' => $rootPem, 'trust_config' => (string) file_get_contents($repo.'/tests/Fixtures/trust/store.cfg')],
    'verify' => ['verify_trust' => true],
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$settings = TrustSettings::fromJson($settingsJson);
$png = (string) file_get_contents($repo.'/tests/Fixtures/fixture-unsigned.png');
$out = $scratchReal.'/graph-fuzz-'.$seed;
if (! is_dir($out) && ! mkdir($out, 0755, true)) {
    throw new RuntimeException("cannot create {$out}");
}
file_put_contents("{$out}/settings.json", $settingsJson."\n");

/**
 * One plan judged: the prediction, the verifier's answer, the order check; a finding written out.
 *
 * @return array{prediction: array{state: string, faults: list<GfFault>}, answer: array{state: string, failures: list<string>, report: string, fault: ?string}, findings: list<string>, file: string}
 */
$judge = static function (string $name, GfPlan $plan) use ($signers, $png, $settings, $out): array {
    $prediction = gfPredict($plan);
    $boxes = gfBuild($plan, $signers, $png);
    $file = gfPng($png, gfStore($plan->order, $boxes));
    $answer = gfJudge($file, $settings);
    $findings = [];
    if ($answer['fault'] !== null) {
        $findings[] = 'FAULT '.$answer['fault'];
    } elseif (gfRank($answer['state']) > gfRank($prediction['state'])) {
        $findings[] = 'WRONG';
    } elseif (gfRank($answer['state']) < gfRank($prediction['state'])) {
        $findings[] = 'STRICTER';
    }
    if ($answer['fault'] === null && count($plan->order) > 2) {
        // the other manifests in reverse: the manifest boxes are the same bytes, and the exclusion still covers the chunk
        $reordered = [...array_reverse(array_slice($plan->order, 0, -1)), $plan->active];
        $again = gfJudge(gfPng($png, gfStore($reordered, $boxes)), $settings);
        if ([$again['state'], $again['failures']] !== [$answer['state'], $answer['failures']]) {
            $findings[] = 'ORDER';
        }
    }
    if ($findings !== []) {
        $base = $out.'/'.preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        file_put_contents($base.'.png', $file);
        file_put_contents($base.'.plan.json', json_encode(['plan' => $plan, 'prediction' => $prediction, 'answer' => ['state' => $answer['state'], 'failures' => $answer['failures'], 'fault' => $answer['fault']]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        file_put_contents($base.'.report.json', $answer['report']."\n");
    }

    return ['prediction' => $prediction, 'answer' => $answer, 'findings' => $findings, 'file' => $file];
};

// ---- calibration: fixed plans, each with what it must show ----

$label = static fn (string $id): string => 'urn:c2pa:00000000-0000-4000-a000-'.str_pad(substr($id, 1), 12, '0', STR_PAD_LEFT);
$one = static fn (GfManifest $a): GfPlan => new GfPlan('m0', ['m0' => $a], ['m0']);
$two = static fn (GfManifest $a, GfManifest $b): GfPlan => new GfPlan('m0', ['m0' => $a, 'm1' => $b], ['m1', 'm0']);
$three = static fn (GfManifest $a, GfManifest $b, GfManifest $c): GfPlan => new GfPlan('m0', ['m0' => $a, 'm1' => $b, 'm2' => $c], ['m2', 'm1', 'm0']);
$to = static fn (?string $target, string $rel = 'componentOf', bool $badHash = false, GfRecord ...$records): GfIngredient => new GfIngredient($rel, $target, $target === 'missing' ? $label('m9') : null, $badHash, array_values($records));
$rec = static fn (string $code, string $url, ?string $kind = null): GfRecord => new GfRecord($code, $url, $kind ?? gfKind($code), 'activeManifest');
$sig1 = gfUrl($label('m1'), 'c2pa.signature');
$calibration = [
    'one manifest' => $one(new GfManifest($label('m0'))),
    'a chain of three' => $three(new GfManifest($label('m0'), ingredients: [$to('m1', 'parentOf')]), new GfManifest($label('m1'), ingredients: [$to('m2', 'parentOf')]), new GfManifest($label('m2'))),
    'shared ingredient' => $three(new GfManifest($label('m0'), ingredients: [$to('m1', 'parentOf'), $to('m2')]), new GfManifest($label('m1'), ingredients: [$to('m2')]), new GfManifest($label('m2'))),
    'active: signature' => $one(new GfManifest($label('m0'), faults: ['sig'])),
    'active: hashed URI' => $one(new GfManifest($label('m0'), faults: ['uri'])),
    'active: untrusted' => $one(new GfManifest($label('m0'), 'untrusted')),
    'active: expired' => $one(new GfManifest($label('m0'), 'expired')),
    'active: no binding' => $one(new GfManifest($label('m0'), faults: ['binding'])),
    'ingredient: signature' => $two(new GfManifest($label('m0'), ingredients: [$to('m1')]), new GfManifest($label('m1'), faults: ['sig'])),
    'ingredient: hashed URI' => $two(new GfManifest($label('m0'), ingredients: [$to('m1')]), new GfManifest($label('m1'), faults: ['uri'])),
    'ingredient: untrusted' => $two(new GfManifest($label('m0'), ingredients: [$to('m1')]), new GfManifest($label('m1'), 'untrusted')),
    'ingredient: expired' => $two(new GfManifest($label('m0'), ingredients: [$to('m1')]), new GfManifest($label('m1'), 'expired')),
    'ingredient: wrong hash' => $two(new GfManifest($label('m0'), ingredients: [$to('m1', 'componentOf', true)]), new GfManifest($label('m1'))),
    'ingredient: second reference, wrong hash' => $two(new GfManifest($label('m0'), ingredients: [$to('m1'), $to('m1', 'componentOf', true)]), new GfManifest($label('m1'))),
    'redacted ingredient: second reference, wrong hash' => $two(new GfManifest($label('m0'), ingredients: [$to('m1'), $to('m1', 'componentOf', true)], redacts: ['m1']), new GfManifest($label('m1'))),
    'ingredient: cycle back to the active' => $two(new GfManifest($label('m0'), ingredients: [$to('m1')]), new GfManifest($label('m1'), ingredients: [$to('m0')])),
    'cycle back to the active, acknowledged' => $two(new GfManifest($label('m0'), ingredients: [$to('m1', 'componentOf', false, $rec('assertion.ingredient.malformed', gfUrl($label('m1'), 'c2pa.assertions/c2pa.ingredient.v3')))]), new GfManifest($label('m1'), ingredients: [$to('m0')])),
    'cycle below the active, acknowledged' => $three(new GfManifest($label('m0'), ingredients: [$to('m1', 'componentOf', false, $rec('assertion.ingredient.malformed', gfUrl($label('m2'), 'c2pa.assertions/c2pa.ingredient.v3')))]), new GfManifest($label('m1'), ingredients: [$to('m2')]), new GfManifest($label('m2'), ingredients: [$to('m1')])),
    'ingredient: names itself' => $two(new GfManifest($label('m0'), ingredients: [$to('m1')]), new GfManifest($label('m1'), ingredients: [$to('m1')])),
    'ingredient: missing manifest' => $one(new GfManifest($label('m0'), ingredients: [$to('missing')])),
    'acknowledged by the parent' => $two(new GfManifest($label('m0'), ingredients: [$to('m1', 'componentOf', false, $rec('claimSignature.mismatch', $sig1))]), new GfManifest($label('m1'), faults: ['sig'])),
    'acknowledged, relative url' => $two(new GfManifest($label('m0'), ingredients: [$to('m1', 'componentOf', false, $rec('claimSignature.mismatch', 'self#jumbf=c2pa.signature'))]), new GfManifest($label('m1'), faults: ['sig'])),
    'acknowledged under the wrong kind' => $two(new GfManifest($label('m0'), ingredients: [$to('m1', 'componentOf', false, $rec('claimSignature.mismatch', $sig1, 'success'))]), new GfManifest($label('m1'), faults: ['sig'])),
    'step 389: a manifest acknowledges itself' => $three(new GfManifest($label('m0'), ingredients: [$to('m1')]), new GfManifest($label('m1'), faults: ['sig'], ingredients: [$to('m2', 'componentOf', false, $rec('claimSignature.mismatch', $sig1))]), new GfManifest($label('m2'))),
    'step 389: a child acknowledges its parent' => $three(new GfManifest($label('m0'), ingredients: [$to('m1')]), new GfManifest($label('m1'), faults: ['sig'], ingredients: [$to('m2')]), new GfManifest($label('m2'), ingredients: [$to(null, 'inputTo', false, $rec('claimSignature.mismatch', $sig1))])),
    'redaction by the parent' => $two(new GfManifest($label('m0'), ingredients: [$to('m1')], redacts: ['m1']), new GfManifest($label('m1'))),
    'redaction of itself, and by the parent' => $two(new GfManifest($label('m0'), ingredients: [$to('m1')], redacts: ['m1']), new GfManifest($label('m1'), redacts: ['m1'])),
    'redaction of itself' => $one(new GfManifest($label('m0'), redacts: ['m0'])),
    'step 390: an ingredient redacts the active' => $two(new GfManifest($label('m0'), ingredients: [$to('m1')]), new GfManifest($label('m1'), redacts: ['m0'])),
    'redaction of the active through a cycle' => $two(new GfManifest($label('m0'), ingredients: [$to('m1', 'componentOf', false, $rec('assertion.ingredient.malformed', gfUrl($label('m1'), 'c2pa.assertions/c2pa.ingredient.v3')))]), new GfManifest($label('m1'), ingredients: [$to('m0')], redacts: ['m0'])),
    'redaction by an unreached manifest' => $three(new GfManifest($label('m0'), ingredients: [$to('m1')]), new GfManifest($label('m1')), new GfManifest($label('m2'), redacts: ['m1'])),
];

$started = microtime(true);
printf("graph-fuzz against %s (Verifier::VERSION %s)\ncalibration:\n", $verifierRoot, defined(Verifier::class.'::VERSION') ? (string) constant(Verifier::class.'::VERSION') : 'not defined');
$broken = false;
$calibrationFindings = 0;
foreach ($calibration as $name => $plan) {
    $result = $judge('calibration-'.$name, $plan);
    $predicted = [];
    foreach ($result['prediction']['faults'] as $fault) {
        if (! $fault->acknowledged && $fault->code !== '*') {
            $predicted[] = $fault->key();
        }
    }
    sort($predicted);
    printf("  %-44s predicted %-7s got %-7s %s\n", $name, $result['prediction']['state'], $result['answer']['state'], implode(' ', $result['findings']));
    if ($predicted !== $result['answer']['failures']) {
        printf("  %44s codes predicted [%s], got [%s]\n", '', gfCodes($predicted), gfCodes($result['answer']['failures']));
        file_put_contents($out.'/calibration-'.preg_replace('/[^A-Za-z0-9._-]/', '_', $name).'.report.json', $result['answer']['report']."\n");   // the codes differ: kept to read
    }
    if ($exportDir !== null && in_array($name, $only, true)) {
        file_put_contents($exportDir.'/'.trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-').'.png', $result['file']);
    }
    $calibrationFindings += $result['findings'] === [] ? 0 : 1;
    $broken = $broken || (in_array($name, ['one manifest', 'a chain of three', 'shared ingredient'], true) && $result['answer']['state'] !== 'Trusted');
}
if ($exportDir !== null) {
    $unknown = array_diff($only, array_keys($calibration));
    if ($unknown !== []) {
        fwrite(STDERR, 'no calibration plan named '.implode(', ', $unknown)."\n");
        exit(2);
    }
    file_put_contents($exportDir.'/graph-fuzz-root.pem', $rootPem);
    file_put_contents($exportDir.'/graph-fuzz-root.settings.json', $settingsJson."\n");
}
if ($broken) {
    fwrite(STDERR, "a plan without a fault is not Trusted: the generator is wrong, not the verifier; stopping\n");
    exit(2);
}
if ($calibrateOnly) {
    printf("calibration: %d of %d plans with a finding\n", $calibrationFindings, count($calibration));
    exit($calibrationFindings === 0 ? 0 : 1);
}

// ---- the random plans ----

$counts = ['WRONG' => 0, 'STRICTER' => 0, 'ORDER' => 0, 'FAULT' => 0];
$predictedStates = ['Trusted' => 0, 'Valid' => 0, 'Invalid' => 0];
$answered = [];
$acknowledgements = 0;
for ($i = 0; $i < $graphs; $i++) {
    $plan = gfPlan(new Randomizer(new Xoshiro256StarStar(hash('sha256', "{$seed}/{$i}", true))));
    $result = $judge("{$seed}-{$i}", $plan);
    $predictedStates[$result['prediction']['state']] = ($predictedStates[$result['prediction']['state']] ?? 0) + 1;
    $answered[$result['answer']['state']] = ($answered[$result['answer']['state']] ?? 0) + 1;
    foreach ($result['prediction']['faults'] as $fault) {
        $acknowledgements += $fault->acknowledged ? 1 : 0;
    }
    foreach ($result['findings'] as $finding) {
        $kind = explode(' ', $finding)[0];
        $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        printf("%s %d-%d: %d manifests, predicted %s, got %s%s\n", $kind, $seed, $i, count($plan->manifests), $result['prediction']['state'], $result['answer']['state'], $kind === 'FAULT' ? ' — '.substr($finding, 6) : '');
    }
}
printf("\n%d graphs, seed %d: %d wrong, %d stricter, %d order, %d faults; predicted %s; got %s; %d acknowledgements predicted; calibration %d of %d with a finding; %.1fs\n",
    $graphs, $seed, $counts['WRONG'], $counts['STRICTER'], $counts['ORDER'], $counts['FAULT'],
    json_encode($predictedStates), json_encode($answered), $acknowledgements, $calibrationFindings, count($calibration), microtime(true) - $started);
exit($counts['WRONG'] + $counts['ORDER'] + $counts['FAULT'] + $calibrationFindings === 0 ? 0 : 1);
