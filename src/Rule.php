<?php

declare(strict_types=1);

namespace Kasapdev\EnvValidator;

/**
 * Describes how a single environment variable should be validated and
 * type-cast by EnvValidator.
 *
 * Rules are created via one of the static type factories and then refined
 * with the fluent instance methods:
 *
 *   Rule::int()->required();
 *   Rule::string()->default('production');
 *   Rule::string()->in(['dev', 'staging', 'production']);
 */
final class Rule
{
    private bool $required = false;

    private bool $hasDefault = false;

    private mixed $default = null;

    /** @var list<int|string|bool>|null */
    private ?array $allowedValues = null;

    private function __construct(public readonly RuleType $type)
    {
    }

    public static function string(): self
    {
        return new self(RuleType::String);
    }

    public static function int(): self
    {
        return new self(RuleType::Int);
    }

    public static function bool(): self
    {
        return new self(RuleType::Bool);
    }

    public static function array(): self
    {
        return new self(RuleType::Array);
    }

    /**
     * Marks this variable as required. Validation fails when the key is
     * absent from the given environment array (or is an empty string).
     */
    public function required(): self
    {
        $this->required = true;

        return $this;
    }

    /**
     * Sets the value to use when the key is absent from the given
     * environment array. The default is used verbatim (it is not passed
     * through type casting), so pass it already in the correct PHP type.
     */
    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    /**
     * Restricts the (cast) value to one of the given allowed values.
     *
     * @param list<int|string|bool> $values
     */
    public function in(array $values): self
    {
        $this->allowedValues = $values;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    /**
     * @return list<int|string|bool>|null
     */
    public function getAllowedValues(): ?array
    {
        return $this->allowedValues;
    }
}
