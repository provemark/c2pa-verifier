<?php

declare(strict_types=1);

/*
 * SPEC-023 AC9 (amendment 4): no two scripts under bin/ declare the same global function.
 * PHPStan binds a call to one of several same-named declarations depending on the order it reads
 * the files, which differs between macOS and Linux; this finds the collision where it is made.
 */

/**
 * The global functions a PHP source declares: `function name(` at brace depth 0, not after `fn`,
 * `=`, `(`, `,` or `return` (a closure), and not inside a class.
 *
 * @return list<string>
 */
function spec023GlobalFunctions(string $source): array
{
    $tokens = PhpToken::tokenize($source);
    $names = [];
    $depth = 0;
    $previous = null;
    foreach ($tokens as $i => $token) {
        if ($token->is(T_WHITESPACE) || $token->is(T_COMMENT) || $token->is(T_DOC_COMMENT)) {
            continue;
        }
        if ($token->text === '{' || $token->is(T_CURLY_OPEN) || $token->is(T_DOLLAR_OPEN_CURLY_BRACES)) {
            $depth++;
        } elseif ($token->text === '}') {
            $depth--;
        } elseif ($token->is(T_FUNCTION) && $depth === 0 && ($previous === null || ! in_array($previous->text, ['=', '(', ',', 'return', '=>', '?', ':', 'static', '[', '.'], true))) {
            for ($j = $i + 1; isset($tokens[$j]); $j++) {
                if ($tokens[$j]->is(T_STRING)) {
                    $names[] = $tokens[$j]->text;

                    break;
                }
                if ($tokens[$j]->text === '(' || $tokens[$j]->text === '&') {
                    if ($tokens[$j]->text === '(') {
                        break;
                    }
                }
            }
        }
        $previous = $token;
    }

    return $names;
}

it('AC9: the reader finds global functions, and not methods, closures or names in strings and comments', function (): void {
    $source = <<<'PHP'
        <?php
        function alpha(int $x): int { return $x; }
        // function inComment() {}
        $s = 'function inString() {}';
        $f = function () { return 1; };
        $g = static function (): int { return 2; };
        final class K { public function method(): void {} }
        function &beta(): array { static $a = []; return $a; }
        PHP;

    expect(spec023GlobalFunctions($source))->toBe(['alpha', 'beta']);
})->group('SPEC-023');

it('AC9: no two scripts under bin/ declare the same global function', function (): void {
    $declaredIn = [];
    foreach (glob(dirname(__DIR__, 2).'/bin/*.php') ?: [] as $path) {
        foreach (spec023GlobalFunctions((string) file_get_contents($path)) as $name) {
            $declaredIn[strtolower($name)][] = basename($path);
        }
    }
    $collisions = [];
    foreach ($declaredIn as $name => $files) {
        if (count($files) > 1) {
            $collisions[] = sprintf('%s() in %s', $name, implode(', ', $files));
        }
    }
    sort($collisions);

    expect($declaredIn)->not->toBe([])
        ->and($collisions)->toBe([]);
})->group('SPEC-023');
