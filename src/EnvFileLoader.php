<?php

declare(strict_types=1);

namespace Kasapdev\EnvValidator;

use RuntimeException;

/**
 * Parses simple `.env`-style files into a plain associative array, with no
 * external dependency.
 *
 * Supported syntax:
 *   - `KEY=value` pairs, one per line.
 *   - Full-line comments starting with `#` or `;` (leading whitespace allowed).
 *   - Blank lines (skipped).
 *   - Double-quoted values (`KEY="some value"`), which support the common
 *     backslash escapes \n, \r, \t, \" and \\.
 *   - Single-quoted values (`KEY='some value'`), taken completely literally
 *     (no escape processing).
 *   - Unquoted values may carry a trailing inline comment, e.g.
 *     `KEY=value # comment`, which is stripped.
 *
 * This loader only parses a file into an array; it does not populate
 * getenv()/$_ENV. Merge the result into $_ENV yourself, or feed it directly
 * into EnvValidator::validate().
 */
final class EnvFileLoader
{
    /**
     * @return array<string, string>
     */
    public static function loadEnvFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Env file not found or not readable: {$path}");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException("Failed to read env file: {$path}");
        }

        $values = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') {
                continue;
            }

            $eqPos = strpos($trimmed, '=');

            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($trimmed, 0, $eqPos));

            if ($key === '') {
                continue;
            }

            $rawValue = trim(substr($trimmed, $eqPos + 1));

            $values[$key] = self::parseValue($rawValue);
        }

        return $values;
    }

    private static function parseValue(string $rawValue): string
    {
        $length = strlen($rawValue);

        if ($length >= 2 && $rawValue[0] === '"' && $rawValue[$length - 1] === '"') {
            return self::unescapeDoubleQuoted(substr($rawValue, 1, -1));
        }

        if ($length >= 2 && $rawValue[0] === "'" && $rawValue[$length - 1] === "'") {
            return substr($rawValue, 1, -1);
        }

        // Unquoted value: strip a trailing inline comment such as
        // `value # comment` or `value ; comment`.
        $withoutComment = preg_replace('/\s+[#;].*$/', '', $rawValue);

        return trim($withoutComment ?? $rawValue);
    }

    private static function unescapeDoubleQuoted(string $value): string
    {
        return strtr($value, [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\"' => '"',
            '\\\\' => '\\',
        ]);
    }
}
