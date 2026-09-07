<?php

declare(strict_types=1);

namespace Kasapdev\EnvValidator;

/**
 * Validates and type-casts an array of raw (string) environment variables
 * against a declared set of Rule objects.
 *
 * Usage:
 *
 *   $validator = EnvValidator::make([
 *       'APP_ENV'  => Rule::string()->default('production')->in(['dev', 'staging', 'production']),
 *       'APP_PORT' => Rule::int()->required(),
 *       'APP_DEBUG'=> Rule::bool()->default(false),
 *       'ALLOWED_IPS' => Rule::array()->default([]),
 *   ]);
 *
 *   $config = $validator->validate($_ENV);
 */
final class EnvValidator
{
    /**
     * @param array<string, Rule> $rules
     */
    private function __construct(private readonly array $rules)
    {
    }

    /**
     * @param array<string, Rule> $rules
     */
    public static function make(array $rules): self
    {
        return new self($rules);
    }

    /**
     * Validates and casts $env against the declared rules.
     *
     * Every rule is checked against every key before anything is thrown:
     * this method never fails fast. If one or more violations are found,
     * a single EnvValidationException is thrown carrying the full list of
     * error messages. On success, an array of validated, type-cast (and,
     * where applicable, defaulted) values is returned.
     *
     * @param array<string, mixed> $env
     * @return array<string, mixed>
     *
     * @throws EnvValidationException
     */
    public function validate(array $env): array
    {
        $result = [];
        $errors = [];

        foreach ($this->rules as $key => $rule) {
            $exists = array_key_exists($key, $env);
            $raw = $exists ? $env[$key] : null;
            $isBlank = $exists && $raw === '';

            if (!$exists || $isBlank) {
                if ($rule->isRequired()) {
                    $errors[] = "Missing required environment variable: {$key}";
                    continue;
                }

                $result[$key] = $rule->hasDefault() ? $rule->getDefault() : null;
                continue;
            }

            if (!is_string($raw)) {
                $errors[] = sprintf(
                    'Invalid value for %s: expected a string, got %s',
                    $key,
                    get_debug_type($raw)
                );
                continue;
            }

            [$castValue, $castError] = $this->cast($key, $raw, $rule->type);

            if ($castError !== null) {
                $errors[] = $castError;
                continue;
            }

            $allowedValues = $rule->getAllowedValues();

            if ($allowedValues !== null && !in_array($castValue, $allowedValues, true)) {
                $errors[] = sprintf(
                    'Invalid value for %s: expected one of [%s], got %s',
                    $key,
                    implode(', ', array_map(
                        static fn (int|string|bool $value): string => var_export($value, true),
                        $allowedValues
                    )),
                    var_export($castValue, true)
                );
                continue;
            }

            $result[$key] = $castValue;
        }

        if ($errors !== []) {
            throw new EnvValidationException($errors);
        }

        return $result;
    }

    /**
     * Generates a `.env.example`-shaped string directly from this
     * validator's declared rules, so the example file can never drift from
     * the actual validation rules.
     *
     * Each declared key produces a `#` comment line describing its type,
     * required/optional status, default (if any) and allowed values (if
     * any), followed by a `KEY=value` line — prefilled with the default
     * when one is set, otherwise left blank.
     *
     * The `KEY=value` lines this produces are parseable as-is by
     * EnvFileLoader::loadEnvFile().
     */
    public function generateExampleFile(): string
    {
        $lines = [];

        foreach ($this->rules as $key => $rule) {
            $lines[] = '# ' . $this->describeRule($rule);
            $lines[] = $key . '=' . ($rule->hasDefault() ? $this->formatDefaultForEnvFile($rule->type, $rule->getDefault()) : '');
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    private function describeRule(Rule $rule): string
    {
        $parts = ['Type: ' . $rule->type->value];
        $parts[] = $rule->isRequired() ? 'Required' : 'Optional';

        if ($rule->hasDefault()) {
            $displayDefault = $this->formatDefaultForEnvFile($rule->type, $rule->getDefault());
            $parts[] = 'Default: ' . ($displayDefault === '' ? '(empty)' : $displayDefault);
        }

        $allowedValues = $rule->getAllowedValues();

        if ($allowedValues !== null) {
            $parts[] = 'Allowed: [' . implode(', ', array_map(
                static fn (int|string|bool $value): string => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                $allowedValues
            )) . ']';
        }

        return implode(', ', $parts);
    }

    private function formatDefaultForEnvFile(RuleType $type, mixed $default): string
    {
        return match ($type) {
            RuleType::String => $this->formatStringDefault((string) $default),
            RuleType::Int => (string) $default,
            RuleType::Bool => $default ? 'true' : 'false',
            RuleType::Array => implode(',', array_map(
                static fn (mixed $item): string => (string) $item,
                (array) $default
            )),
        };
    }

    /**
     * Quotes a string default (using the same double-quote escaping
     * EnvFileLoader::loadEnvFile() already understands) whenever writing it
     * unquoted would either be ambiguous or be parsed differently.
     */
    private function formatStringDefault(string $value): string
    {
        $needsQuoting = $value === ''
            || $value !== trim($value)
            || str_contains($value, '"')
            || str_contains($value, '#')
            || str_contains($value, ';')
            || str_contains($value, "\n")
            || str_contains($value, "\r")
            || str_contains($value, "\t");

        if (!$needsQuoting) {
            return $value;
        }

        return '"' . strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]) . '"';
    }

    /**
     * @return array{0: mixed, 1: string|null}
     */
    private function cast(string $key, string $raw, RuleType $type): array
    {
        return match ($type) {
            RuleType::String => [$raw, null],
            RuleType::Int => $this->castInt($key, $raw),
            RuleType::Bool => $this->castBool($key, $raw),
            RuleType::Array => [$this->castArray($raw), null],
        };
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private function castInt(string $key, string $raw): array
    {
        $trimmed = trim($raw);

        if ($trimmed === '' || preg_match('/^-?\d+$/', $trimmed) !== 1) {
            return [null, "Invalid integer value for {$key}: \"{$raw}\""];
        }

        return [(int) $trimmed, null];
    }

    /**
     * @return array{0: bool|null, 1: string|null}
     */
    private function castBool(string $key, string $raw): array
    {
        $normalized = strtolower(trim($raw));

        return match ($normalized) {
            '1', 'true', 'yes', 'on' => [true, null],
            '0', 'false', 'no', 'off' => [false, null],
            default => [null, "Invalid boolean value for {$key}: \"{$raw}\""],
        };
    }

    /**
     * Splits a comma-separated raw value into a trimmed string list.
     *
     * @return list<string>
     */
    private function castArray(string $raw): array
    {
        return array_map(
            static fn (string $part): string => trim($part),
            explode(',', $raw)
        );
    }
}
