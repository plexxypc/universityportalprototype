<?php

declare(strict_types=1);

/**
 * Turn one dockerignore pattern into a full-path regular expression.
 *
 * A pattern with no slash matches in any directory, the same way Docker does.
 */
function dockerignore_pattern_regex(string $pattern): string
{
    $pattern = ltrim(str_replace('\\', '/', $pattern), '/');

    if (! str_contains($pattern, '/')) {
        $pattern = '**/'.$pattern;
    }

    $regex = '';
    $offset = 0;
    $length = strlen($pattern);

    while ($offset < $length) {
        if (substr($pattern, $offset, 3) === '**/') {
            $regex .= '(?:.*/)?';
            $offset += 3;

            continue;
        }

        if (substr($pattern, $offset, 2) === '**') {
            $regex .= '.*';
            $offset += 2;

            continue;
        }

        $character = $pattern[$offset];
        $regex .= match ($character) {
            '*' => '[^/]*',
            '?' => '[^/]',
            default => preg_quote($character, '#'),
        };
        $offset++;
    }

    return '#^'.$regex.'$#';
}

/**
 * Report whether the last matching dockerignore rule excludes a path.
 */
function dockerignore_excludes(string $contents, string $path): bool
{
    $excluded = false;
    $path = str_replace('\\', '/', $path);

    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        if (! is_string($line)) {
            continue;
        }

        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $negate = str_starts_with($line, '!');
        $pattern = trim($negate ? substr($line, 1) : $line);

        if ($pattern === '') {
            continue;
        }

        if (preg_match(dockerignore_pattern_regex($pattern), $path) === 1) {
            $excluded = ! $negate;
        }
    }

    return $excluded;
}

it('keeps the institution logo in the docker image', function () {
    $root = dirname(__DIR__, 2);
    $logo = 'public/images/logo-placeholder.svg';
    $logo_path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $logo);
    $ignore = (string) file_get_contents($root.DIRECTORY_SEPARATOR.'.dockerignore');

    expect(is_file($logo_path))->toBeTrue()
        ->and((string) file_get_contents($logo_path))->toContain('<svg')
        ->and(dockerignore_excludes($ignore, $logo))->toBeFalse()
        ->and(dockerignore_excludes($ignore, 'public/images/logo-default.png'))->toBeFalse()
        ->and(dockerignore_excludes($ignore, 'docs/screenshot.png'))->toBeTrue()
        ->and(dockerignore_excludes($ignore, 'README.md'))->toBeTrue();
});
