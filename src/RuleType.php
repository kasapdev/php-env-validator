<?php

declare(strict_types=1);

namespace Kasapdev\EnvValidator;

/**
 * The set of primitive types a Rule can validate and cast a raw
 * (always string, when it comes from a real process environment) value into.
 */
enum RuleType: string
{
    case String = 'string';
    case Int = 'int';
    case Bool = 'bool';
    case Array = 'array';
}
