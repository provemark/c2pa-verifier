<?php

declare(strict_types=1);

/*
 * SPEC-025: what a version number promises.
 *
 * Tooling, like bin/spec-check.php and bin/package-check.php: outside the Deptrac
 * layers, nothing in src/ depends on it, and it is analysed by PHPStan at level max
 * and formatted by Pint like everything else.
 *
 * Step 69 measured 600 public symbols across 69 classes with none marked
 * `@internal`, against a README naming two classes and four accessors. A tag would
 * promise all six hundred by default. This script draws the line: nine classes are
 * the contract, every other public class says `@internal`, and the contract's own
 * surface is recorded in tests/Fixtures/api/public-surface.txt so that changing a
 * promise shows up as a diff.
 *
 * `@internal` is documentation, not enforcement, and that is deliberate. The layers
 * of this library are built to be testable on their own and the tests reach into
 * them by design; sealing them would cost more than the promise is worth. What ends
 * is the implication of support that was never given.
 */

final readonly class ApiCheckResult
{
    /** @param list<string> $findings one sentence each, naming the class or the symbol */
    public function __construct(public array $findings) {}

    public function exitCode(): int
    {
        return $this->findings === [] ? 0 : 1;
    }

    public function render(): string
    {
        if ($this->findings === []) {
            return 'every public class is either in the contract or marked @internal'.PHP_EOL;
        }

        return implode(PHP_EOL, array_map(static fn (string $f): string => '  '.$f, $this->findings)).PHP_EOL;
    }
}

/**
 * The public symbols a class declares itself, as `kind name`, sorted.
 *
 * Inherited members are left out: they belong to the class that declares them, and
 * listing them twice would make a change to a parent look like a change to a child.
 *
 * @param  class-string  $class
 * @return list<string>
 */
function apiSurface(string $class): array
{
    $reflection = new ReflectionClass($class);
    $symbols = [];
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() === $class) {
            $symbols[] = 'method '.$method->getName();
        }
    }
    foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
        if ($constant->getDeclaringClass()->getName() === $class) {
            $symbols[] = 'const '.$constant->getName();
        }
    }
    foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        if ($property->getDeclaringClass()->getName() === $class) {
            $symbols[] = 'property '.$property->getName();
        }
    }
    sort($symbols);

    return $symbols;
}

/**
 * Every public class, interface and enum under a source directory, with whether its
 * own docblock carries `@internal`.
 *
 * @return array<string, bool> fully qualified name => is marked internal
 */
function apiPublicClasses(string $sourceDirectory): array
{
    $classes = [];
    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($sourceDirectory) + 1);
        $name = 'Provemark\C2paVerifier\\'.str_replace('/', '\\', substr($relative, 0, -4));
        if (! class_exists($name) && ! interface_exists($name) && ! enum_exists($name)) {
            continue;
        }
        $classes[$name] = str_contains((string) (new ReflectionClass($name))->getDocComment(), '@internal');
    }
    ksort($classes);

    return $classes;
}

/**
 * AC2: every public class is in the contract or marked `@internal`, and never both.
 *
 * A class in neither is a finding rather than a default in either direction — the
 * same rule SPEC-023 applies to a top-level path, and for the same reason: the
 * failure this catches is a class added next year that nobody classified.
 *
 * @param  array<string, bool>  $classes  as apiPublicClasses() returns them
 * @param  list<string>  $contract  short names, `Verifier\Verifier` style
 */
function apiCheck(array $classes, array $contract): ApiCheckResult
{
    $full = array_map(static fn (string $short): string => str_starts_with($short, 'Provemark\\')
        ? $short
        : 'Provemark\C2paVerifier\\'.$short, $contract);

    $findings = [];
    foreach ($classes as $name => $internal) {
        $inContract = in_array($name, $full, true);
        if ($inContract && $internal) {
            $findings[] = "{$name}: in the contract and marked @internal at the same time";

            continue;
        }
        if (! $inContract && ! $internal) {
            $findings[] = "{$name}: in neither the contract nor marked @internal; decide which it is";
        }
    }

    return new ApiCheckResult($findings);
}

/**
 * AC4: the recorded surface against the live one, line for line.
 *
 * @param  list<string>  $recorded
 * @param  list<string>  $live
 */
function apiCompare(array $recorded, array $live): ApiCheckResult
{
    $findings = [];
    foreach (array_diff($live, $recorded) as $added) {
        $findings[] = "{$added}: public but not recorded";
    }
    foreach (array_diff($recorded, $live) as $gone) {
        $findings[] = "{$gone}: recorded but no longer public";
    }
    sort($findings);

    return new ApiCheckResult($findings);
}

// Run as a script: check this repository and print what it found.
if (isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    require dirname(__DIR__).'/vendor/autoload.php';

    $contract = [
        'Cli\Command', 'Report\StatusCode', 'Report\ValidationResult', 'Report\ValidationState',
        'Report\ValidationStatus', 'Trust\TrustException', 'Trust\TrustSettings',
        'Verifier\FragmentedVerifier', 'Verifier\VerificationReport', 'Verifier\Verifier',
    ];
    $classes = apiPublicClasses(dirname(__DIR__).'/src');
    $result = apiCheck($classes, $contract);

    $live = [];
    foreach ($contract as $short) {
        foreach (apiSurface('Provemark\C2paVerifier\\'.$short) as $symbol) {
            $live[] = $short.' :: '.$symbol;
        }
    }
    $recordedPath = dirname(__DIR__).'/tests/Fixtures/api/public-surface.txt';
    $recorded = is_file($recordedPath)
        ? (array) file($recordedPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        : [];
    /** @var list<string> $recorded */
    $drift = apiCompare($recorded, $live);

    printf('%d public classes: %d in the contract, %d marked @internal%s',
        count($classes), count($contract), count(array_filter($classes)), PHP_EOL);
    printf('contract surface: %d symbols%s', count($live), PHP_EOL);
    echo $result->render();
    echo $drift->findings === [] ? 'the recorded surface matches'.PHP_EOL : $drift->render();

    exit(max($result->exitCode(), $drift->exitCode()));
}
