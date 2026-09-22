<?php

declare(strict_types=1);

/*
 * SPEC-023: what `composer require provemark/c2pa-verifier` delivers.
 *
 * Tooling, like bin/spec-check.php: it lives outside the Deptrac layers, nothing
 * in src/ may depend on it, and it is analysed by PHPStan at level max and
 * formatted by Pint like everything else — a checker that is itself unchecked is
 * the shape of bug this project has documented before.
 *
 * Composer fetches the dist archive, which is `git archive` with .gitattributes
 * applied, so that archive is what the criteria measure. Producing it means
 * invoking git: the maintainer decided on 2026-09-22 that this file and the
 * tests in SPEC-023's group may, because re-implementing `git archive` in PHP
 * would be a second truth of exactly the kind this project refuses. The rule
 * against `exec` binds the verification path — src/ — and is unchanged there.
 */

/**
 * The top-level paths that ship, declared rather than derived.
 *
 * Deriving it ("shipped means not export-ignore") cannot work: under that
 * reading a directory added next year ships by default and AC1 can raise no
 * finding, which is the failure the criterion exists for. Adding a line here is
 * the decision, and it is reviewable in the diff.
 *
 * docs/, specs/, notes/ and AI-LOG.md are on the list on purpose (step 62,
 * confirmed by the maintainer 2026-09-22): the package carries its own record,
 * every link in the README resolves inside it, and the disclosure travels with
 * the code. Together they are under 2 MB against 500 kB of source.
 */
const PACKAGE_SHIPPED = [
    'AI-LOG.md',
    'CHANGELOG.md',
    'CONTRIBUTING.md',
    'LICENSE',
    'NOTES.md',
    'README.md',
    'SECURITY.md',
    'bin',
    'composer.json',
    'docs',
    'notes',
    'specs',
    'src',
];

/** What the archive must hold whatever else changes (AC2). */
const PACKAGE_REQUIRED = ['composer.json', 'LICENSE', 'README.md'];

/** The section of the README that carries the disclosure (AC5). */
const PACKAGE_DISCLOSURE_SECTION = '## How this is built';

final readonly class PackageCheckResult
{
    /**
     * @param  list<string>  $findings  one sentence each, naming the path
     * @param  list<string>  $shipped  the given paths that ship
     * @param  list<string>  $ignored  the given paths marked export-ignore
     */
    public function __construct(
        public array $findings,
        public array $shipped = [],
        public array $ignored = [],
    ) {}

    public function exitCode(): int
    {
        return $this->findings === [] ? 0 : 1;
    }

    public function render(): string
    {
        $head = sprintf('%d shipped, %d export-ignore', count($this->shipped), count($this->ignored));
        if ($this->findings === []) {
            return $head.': every top-level path is classified'.PHP_EOL;
        }

        return $head.PHP_EOL.implode(PHP_EOL, array_map(static fn (string $f): string => '  '.$f, $this->findings)).PHP_EOL;
    }
}

/**
 * An archive, as the criteria need to see it: the paths it holds with their
 * sizes, its total size, and the contents of any path in it.
 */
interface PackageArchive
{
    /** @return array<string, int> path => size in bytes, the path as the archive spells it */
    public function entries(): array;

    /** The size of the archive itself, which is what a consumer downloads. */
    public function size(): int;

    public function read(string $path): string;
}

/** An archive held in memory: for cases that must be broken on purpose. */
final readonly class PackageArrayArchive implements PackageArchive
{
    /** @param array<string, string> $files path => contents */
    public function __construct(private array $files) {}

    public function entries(): array
    {
        return array_map(strlen(...), $this->files);
    }

    public function size(): int
    {
        return array_sum(array_map(strlen(...), $this->files));
    }

    public function read(string $path): string
    {
        return $this->files[$path] ?? throw new RuntimeException("not in the archive: {$path}");
    }
}

/** A tar on disk, read through PharData — no second process, and no extraction to list it. */
final class PackageTarArchive implements PackageArchive
{
    /** @var array<string, int>|null */
    private ?array $entries = null;

    public function __construct(public readonly string $tar) {}

    public function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }
        // The path inside the archive comes from the iterator, never from string
        // surgery on `phar://`: the stream wrapper canonicalises the tar's own path
        // (on macOS /var becomes /private/var), so a prefix computed here would not
        // match the one it hands back.
        $entries = [];
        $iterator = new RecursiveIteratorIterator(new PharData($this->tar));
        foreach ($iterator as $file) {
            assert($file instanceof SplFileInfo);
            $entries[$iterator->getSubPathname()] = $file->getSize();
        }
        ksort($entries);

        return $this->entries = $entries;
    }

    public function size(): int
    {
        return (int) filesize($this->tar);
    }

    public function read(string $path): string
    {
        $phar = new PharData($this->tar);
        if (! isset($phar[$path])) {
            throw new RuntimeException("not in the archive: {$path}");
        }

        return $phar[$path]->getContent();
    }

    public function extractTo(string $directory): void
    {
        (new PharData($this->tar))->extractTo($directory);
    }
}

/**
 * AC1: every top-level path is shipped or export-ignore, and no path is both.
 *
 * The paths are passed in rather than discovered, so that a case can be a
 * directory holding nothing but a .gitattributes — the way spec-check runs over
 * fixture trees.
 *
 * @param  list<string>  $topLevelPaths  as `git ls-files` gives them
 * @param  list<string>|null  $shipped  null means the declared list above
 */
function packageCheck(string $root, array $topLevelPaths, ?array $shipped = null): PackageCheckResult
{
    $shipped ??= PACKAGE_SHIPPED;
    $attributes = $root.'/.gitattributes';
    if (! is_file($attributes)) {
        // Not "nothing is ignored": without the file the dist is whatever the
        // repository happens to hold, which is the state step 62 found at 62.9 MB.
        return new PackageCheckResult(['no .gitattributes: nothing is export-ignore, so the dist is whatever the repository holds']);
    }

    $ignored = packageExportIgnored((string) file_get_contents($attributes));
    $findings = [];
    $isShipped = [];
    $isIgnored = [];
    foreach ($topLevelPaths as $path) {
        $ships = in_array($path, $shipped, true);
        $hidden = in_array($path, $ignored, true);
        if ($ships && $hidden) {
            $findings[] = "{$path}: on the shipped list and export-ignore at the same time";

            continue;
        }
        if (! $ships && ! $hidden) {
            $findings[] = "{$path}: in neither the shipped list nor export-ignore; decide which it is";

            continue;
        }
        if ($ships) {
            $isShipped[] = $path;
        } else {
            $isIgnored[] = $path;
        }
    }
    sort($isShipped);
    sort($isIgnored);

    return new PackageCheckResult($findings, $isShipped, $isIgnored);
}

/**
 * The paths a .gitattributes marks export-ignore, leading slash stripped.
 *
 * `tests/*` and `/tests` both name `tests` here: the criterion is about which
 * top-level path was decided on, not about the pattern that expresses it.
 *
 * @return list<string>
 */
function packageExportIgnored(string $gitattributes): array
{
    $paths = [];
    foreach (explode("\n", $gitattributes) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, 'export-ignore')) {
            continue;
        }
        $fields = preg_split('/\s+/', $line);
        if ($fields === false) {
            continue;
        }
        $pattern = trim($fields[0], '/');
        $pattern = (string) preg_replace('#/\*$#', '', $pattern);
        if ($pattern !== '') {
            $paths[] = $pattern;
        }
    }

    return array_values(array_unique($paths));
}

/**
 * The top-level paths git tracks. This is the one place the checker asks git a
 * question of its own; every other use of git here is `git archive`.
 *
 * @return list<string>
 */
function packageTrackedTopLevel(string $root): array
{
    $listing = packageGit($root, ['ls-files']);
    $paths = [];
    foreach (explode("\n", $listing) as $line) {
        if ($line === '') {
            continue;
        }
        $paths[explode('/', $line)[0]] = true;
    }
    $top = array_keys($paths);
    sort($top);

    return $top;
}

/**
 * AC2–AC5 over one archive. One result, so that a dist is judged whole rather
 * than by whichever criterion happens to run first.
 */
function packageDistCheck(PackageArchive $archive, int $ceilingBytes): PackageCheckResult
{
    $entries = $archive->entries();
    $findings = [];

    // AC2: what a consumer cannot do without
    foreach (PACKAGE_REQUIRED as $required) {
        if (! array_key_exists($required, $entries)) {
            $findings[] = "{$required}: not in the archive";
        }
    }
    if (packageUnder($entries, 'src/') === []) {
        $findings[] = 'src/: the archive holds no file under it';
    }
    foreach (packageBinEntries($archive) as $bin) {
        if (! array_key_exists($bin, $entries)) {
            $findings[] = "{$bin}: declared in composer.json \"bin\" but not in the archive";
        }
    }

    // AC3: no fixture, and a ceiling that fires long before it is 60 MB again
    $fixtures = packageUnder($entries, 'tests/');
    if ($fixtures !== []) {
        $findings[] = sprintf('tests/: %d entries under it; the fixtures are drift alarms, not something to ship', count($fixtures));
    }
    if ($archive->size() > $ceilingBytes) {
        $findings[] = sprintf('the archive is %.1f MB, over the ceiling of %.1f MB', $archive->size() / 1048576, $ceilingBytes / 1048576);
    }

    // AC4: the package is self-describing
    foreach (array_keys($entries) as $path) {
        if (! str_ends_with($path, '.md')) {
            continue;
        }
        foreach (packageLinks($archive->read($path)) as $link) {
            if (! packageResolves($entries, $path, $link)) {
                $findings[] = "{$path}: the link {$link} leaves the package";
            }
        }
    }

    // AC5: the disclosure travels with the package
    if (! array_key_exists('AI-LOG.md', $entries)) {
        $findings[] = 'AI-LOG.md: the disclosure is not in the archive';
    }
    if (array_key_exists('README.md', $entries) && ! str_contains($archive->read('README.md'), PACKAGE_DISCLOSURE_SECTION)) {
        $findings[] = 'README.md: no "How this is built" section, so the package does not say how it was made';
    }

    return new PackageCheckResult($findings);
}

/**
 * @param  array<string, int>  $entries
 * @return list<string>
 */
function packageUnder(array $entries, string $prefix): array
{
    return array_values(array_filter(array_keys($entries), static fn (string $p): bool => str_starts_with($p, $prefix)));
}

/**
 * The `bin` array of the archive's own composer.json — the package's promise
 * about itself, read from the package rather than from this repository.
 *
 * @return list<string>
 */
function packageBinEntries(PackageArchive $archive): array
{
    if (! array_key_exists('composer.json', $archive->entries())) {
        return [];
    }
    /** @var array<string, mixed> $manifest */
    $manifest = json_decode($archive->read('composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $bin = $manifest['bin'] ?? [];

    return is_array($bin) ? array_values(array_filter($bin, is_string(...))) : [];
}

/**
 * The relative links of a markdown text. Links with a scheme and absolute paths
 * are somebody else's business; a bare fragment points inside the file it is in.
 *
 * @return list<string>
 */
function packageLinks(string $markdown): array
{
    preg_match_all('/\]\(([^)]+)\)/', $markdown, $matches);
    $links = [];
    foreach ($matches[1] as $link) {
        $link = trim(explode('#', $link, 2)[0]);
        if ($link === '' || str_starts_with($link, '/') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $link) === 1) {
            continue;
        }
        $links[] = $link;
    }

    return array_values(array_unique($links));
}

/**
 * Does a link from $from resolve to something the archive holds? A link may name
 * a directory, which no archive lists as an entry of its own, so a prefix counts.
 *
 * @param  array<string, int>  $entries
 */
function packageResolves(array $entries, string $from, string $link): bool
{
    $base = dirname($from);
    $target = $base === '.' ? $link : $base.'/'.$link;

    // resolve . and .. without touching the filesystem: the archive is the world here
    $parts = [];
    foreach (explode('/', $target) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);

            continue;
        }
        $parts[] = $part;
    }
    $target = implode('/', $parts);
    if ($target === '' || array_key_exists($target, $entries)) {
        return true;
    }

    foreach (array_keys($entries) as $path) {
        if (str_starts_with($path, $target.'/')) {
            return true;
        }
    }

    return false;
}

/**
 * The dist as it would have shipped before `.gitattributes` existed: the archive
 * of the parent of the commit that added the file. Measured 62.9 MB, and it is
 * real rather than constructed, which is what makes it worth asserting on.
 *
 * Null when the history is not there — a shallow clone has no parent commit to
 * archive. The caller says so rather than passing quietly.
 */
function packageArchiveBeforeGitattributes(string $root): ?PackageArchive
{
    $adding = trim(packageGit($root, ['log', '--diff-filter=A', '--format=%H', '--', '.gitattributes']));
    if ($adding === '') {
        return null;
    }
    $lines = explode("\n", $adding);
    $first = trim((string) end($lines));
    try {
        $parent = trim(packageGit($root, ['rev-parse', '--verify', $first.'^']));
    } catch (RuntimeException) {
        return null;   // shallow: the parent is not in this clone
    }

    // false on purpose: `--worktree-attributes` would paste the working tree's
    // .gitattributes onto a commit that predates it, and the archive would come
    // back filtered — 1.9 MB instead of the 62.9 MB that commit really shipped.
    return packageGitArchive($root, false, $parent);
}

/**
 * The archive Composer would fetch, of `$commit` (HEAD by default).
 *
 * `--worktree-attributes` adds the working tree's `.gitattributes` to the one
 * already committed; git applies the committed file either way, which is why a
 * "without the flag" archive is not the same thing as a dist without the file.
 */
function packageGitArchive(string $root, bool $worktreeAttributes, string $commit = 'HEAD'): PackageArchive
{
    /** @var array<string, PackageTarArchive> $built */
    static $built = [];
    $key = $root.'|'.($worktreeAttributes ? '1' : '0').'|'.$commit;
    if (array_key_exists($key, $built)) {
        return $built[$key];
    }

    $tar = packageTempPath('c2pa-dist-').'.tar';
    $arguments = ['archive', '--format=tar'];
    if ($worktreeAttributes) {
        $arguments[] = '--worktree-attributes';
    }
    $arguments[] = '-o';
    $arguments[] = $tar;
    $arguments[] = $commit;
    packageGit($root, $arguments);

    return $built[$key] = new PackageTarArchive($tar);
}

/**
 * The package as Composer installs it: the archive under
 * vendor/provemark/c2pa-verifier/, with an autoloader built from the psr-4 map
 * the archive's own composer.json declares (SPEC-023 AC6, amendment 1).
 * Returns the directory that plays the consumer's project root.
 */
function packageInstall(PackageArchive $archive): string
{
    if (! $archive instanceof PackageTarArchive) {
        throw new RuntimeException('only a tar archive can be installed');
    }
    $root = packageTempPath('c2pa-install-');
    $package = $root.'/vendor/provemark/c2pa-verifier';
    if (! mkdir($package, 0o755, true) && ! is_dir($package)) {
        throw new RuntimeException("cannot create {$package}");
    }
    $archive->extractTo($package);

    /** @var array<string, mixed> $manifest */
    $manifest = json_decode($archive->read('composer.json'), true, 512, JSON_THROW_ON_ERROR);
    /** @var array<string, mixed> $autoload */
    $autoload = is_array($manifest['autoload'] ?? null) ? $manifest['autoload'] : [];
    /** @var array<string, string> $psr4 */
    $psr4 = is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];

    $map = var_export($psr4, true);
    file_put_contents($root.'/vendor/autoload.php', <<<PHP
        <?php

        // Built from the psr-4 map the package itself declares — nothing else. A file
        // lost to export-ignore, or a namespace the map does not cover, fails here.
        spl_autoload_register(static function (string \$class) {
            foreach ({$map} as \$prefix => \$dir) {
                if (str_starts_with(\$class, \$prefix)) {
                    \$path = __DIR__.'/provemark/c2pa-verifier/'.\$dir.str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))).'.php';
                    if (is_file(\$path)) {
                        require \$path;

                        return;
                    }
                }
            }
        });
        PHP);

    return $root;
}

/**
 * Run the package's CLI from $packageDirectory.
 *
 * @param  list<string>  $arguments
 * @return array{exit: int, stdout: string, stderr: string}
 */
function packageRun(string $packageDirectory, array $arguments): array
{
    $command = array_merge([PHP_BINARY, $packageDirectory.'/bin/c2pa-verify'], $arguments);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, $packageDirectory);
    if (! is_resource($process)) {
        throw new RuntimeException('cannot run the package CLI');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/**
 * git, with its arguments passed as a list so nothing is quoted into a shell.
 *
 * @param  list<string>  $arguments
 */
function packageGit(string $root, array $arguments): string
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(array_merge(['git'], $arguments), $descriptors, $pipes, $root);
    if (! is_resource($process)) {
        throw new RuntimeException('cannot run git');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException('git '.implode(' ', $arguments).' failed: '.trim($stderr));
    }

    return $stdout;
}

/** A path in the system temporary directory, removed when the process ends. */
function packageTempPath(string $prefix): string
{
    $path = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(6));
    register_shutdown_function(static function () use ($path): void {
        packageRemove($path);
        packageRemove($path.'.tar');
    });

    return $path;
}

function packageRemove(string $path): void
{
    if (is_file($path)) {
        unlink($path);

        return;
    }
    if (! is_dir($path)) {
        return;
    }
    /** @var SplFileInfo $entry */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

// Run as a script: check this repository and print the findings.
if (isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $repository = dirname(__DIR__);
    $result = packageCheck($repository, packageTrackedTopLevel($repository));
    echo $result->render();

    $archive = packageGitArchive($repository, true);
    $dist = packageDistCheck($archive, 4 * 1024 * 1024);
    printf('dist: %d files, %.1f MB%s', count($archive->entries()), $archive->size() / 1048576, PHP_EOL);
    foreach ($dist->findings as $finding) {
        echo '  ', $finding, PHP_EOL;
    }
    exit(max($result->exitCode(), $dist->exitCode()));
}
