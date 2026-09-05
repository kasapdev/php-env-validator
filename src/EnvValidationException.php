<?php

declare(strict_types=1);

namespace Kasapdev\EnvValidator;

use RuntimeException;

/**
 * Thrown by EnvValidator::validate() when one or more environment variables
 * fail validation. Every violation found across every key is collected
 * before this exception is thrown (validation never fails fast), so
 * getErrors() may contain multiple messages at once.
 */
final class EnvValidationException extends RuntimeException
{
    /**
     * @param list<string> $errors human-readable, one-per-violation messages
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(self::buildMessage($errors));
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param list<string> $errors
     */
    private static function buildMessage(array $errors): string
    {
        $count = count($errors);
        $header = $count === 1
            ? 'Environment validation failed with 1 error:'
            : "Environment validation failed with {$count} errors:";

        $body = implode("\n", array_map(
            static fn (string $error): string => " - {$error}",
            $errors
        ));

        return $header . "\n" . $body;
    }
}
