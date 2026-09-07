# php-env-validator

[![CI](https://github.com/kasapdev/php-env-validator/actions/workflows/ci.yml/badge.svg)](https://github.com/kasapdev/php-env-validator/actions/workflows/ci.yml) [![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE) ![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)

A zero-dependency, type-safe environment variable validator and `.env` file loader for PHP 8.1+. Declare the shape you expect your environment to have, validate it once at boot, and get back a plain array of correctly typed values — with every violation reported at once instead of one at a time.

## Installation

Once published to Packagist:

```bash
composer require kasapdev/php-env-validator
```

Until then (or if you'd rather not add a dependency), just require the files directly — the library has zero external dependencies:

```php
require_once 'src/RuleType.php';
require_once 'src/Rule.php';
require_once 'src/EnvValidationException.php';
require_once 'src/EnvValidator.php';
require_once 'src/EnvFileLoader.php';
```

## Usage

### Validating environment variables

```php
use Kasapdev\EnvValidator\EnvValidator;
use Kasapdev\EnvValidator\EnvValidationException;
use Kasapdev\EnvValidator\Rule;

$validator = EnvValidator::make([
    'APP_ENV'     => Rule::string()->in(['dev', 'staging', 'production'])->default('production'),
    'APP_PORT'    => Rule::int()->required(),
    'APP_DEBUG'   => Rule::bool()->default(false),
    'ALLOWED_IPS' => Rule::array()->default([]),
    'DB_HOST'     => Rule::string()->required(),
]);

try {
    $config = $validator->validate($_ENV);
} catch (EnvValidationException $e) {
    // getMessage() is a combined, human-readable summary of every problem found.
    fwrite(STDERR, $e->getMessage() . PHP_EOL);

    // getErrors() gives you the individual messages if you want to handle them yourself.
    foreach ($e->getErrors() as $error) {
        fwrite(STDERR, " - {$error}" . PHP_EOL);
    }

    exit(1);
}

// $config['APP_PORT'] is a real int, $config['APP_DEBUG'] a real bool,
// $config['ALLOWED_IPS'] a real array — no manual casting needed.
```

`validate()` **never fails fast**. It checks every rule against every key first, and only then throws a single `EnvValidationException` listing *all* the problems it found — missing required keys, bad types, and disallowed values alike — so you can fix your `.env` in one pass instead of playing whack-a-mole.

### A full boot-time example

A more realistic setup: define the rules once, validate `$_ENV` at boot, and pass the resulting typed config around the app instead of calling `getenv()` everywhere.

```php
use Kasapdev\EnvValidator\EnvValidator;
use Kasapdev\EnvValidator\EnvValidationException;
use Kasapdev\EnvValidator\EnvFileLoader;
use Kasapdev\EnvValidator\Rule;

function bootConfig(): array
{
    $validator = EnvValidator::make([
        'APP_ENV'      => Rule::string()->in(['dev', 'staging', 'production'])->default('production'),
        'APP_PORT'     => Rule::int()->default(8080),
        'APP_DEBUG'    => Rule::bool()->default(false),
        'DB_HOST'      => Rule::string()->required(),
        'DB_PORT'      => Rule::int()->default(5432),
        'ALLOWED_IPS'  => Rule::array()->default([]),
    ]);

    // Merge a local .env file (if present) under real process env vars, so
    // actual environment variables always win.
    $fileEnv = is_file(__DIR__ . '/.env') ? EnvFileLoader::loadEnvFile(__DIR__ . '/.env') : [];
    $env = $fileEnv + $_ENV;

    try {
        return $validator->validate($env);
    } catch (EnvValidationException $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

$config = bootConfig();

// From here on, every value is already the right PHP type:
$server->listen(host: '0.0.0.0', port: $config['APP_PORT']);
```

### Loading a `.env` file

```php
use Kasapdev\EnvValidator\EnvFileLoader;

$env = EnvFileLoader::loadEnvFile(__DIR__ . '/.env');

// Merge into $_ENV if you want getenv()-style access elsewhere,
// or just feed it straight into EnvValidator:
$config = $validator->validate($env);
```

Example `.env` file:

```env
# Full-line comments are supported
; So are semicolon comments

APP_ENV=production
APP_PORT=8080
APP_DEBUG=true

DB_HOST="db.internal.example.com"
DB_PASSWORD='p@ss w0rd with spaces'

ALLOWED_IPS=127.0.0.1, 10.0.0.1, 192.168.1.1

WELCOME_MESSAGE="Hello\nWorld"
```

## Generating a .env.example File

Since a real project's `.env.example` tends to quietly drift out of sync with whatever rules the code actually validates, `EnvValidator::generateExampleFile()` builds one directly from the declared rules instead — so it can never disagree with `validate()`.

```php
use Kasapdev\EnvValidator\EnvValidator;
use Kasapdev\EnvValidator\Rule;

$validator = EnvValidator::make([
    'APP_ENV'     => Rule::string()->in(['dev', 'staging', 'production'])->default('production'),
    'APP_PORT'    => Rule::int()->required(),
    'APP_DEBUG'   => Rule::bool()->default(false),
    'ALLOWED_IPS' => Rule::array()->default([]),
    'DB_HOST'     => Rule::string()->required(),
]);

file_put_contents(__DIR__ . '/.env.example', $validator->generateExampleFile());
```

This produces:

```env
# Type: string, Optional, Default: production, Allowed: [dev, staging, production]
APP_ENV=production
# Type: int, Required
APP_PORT=
# Type: bool, Optional, Default: false
APP_DEBUG=false
# Type: array, Optional, Default: (empty)
ALLOWED_IPS=
# Type: string, Required
DB_HOST=
```

Every key gets a `#` comment describing its type, whether it's required or optional, its default (if any), and its allowed values (if constrained by `->in()`), immediately followed by a `KEY=value` line — prefilled with the default when one is declared, left blank otherwise. Those `KEY=value` lines are ordinary `.env` syntax, so the file `generateExampleFile()` produces can be fed straight back into `EnvFileLoader::loadEnvFile()` (and from there into `validate()`) without any changes to either method.

Run this from a small script (or a Composer/CI step) whenever your rules change, and `.env.example` stays truthful automatically instead of relying on someone to remember to update it by hand.

## API

### `Rule` (`Kasapdev\EnvValidator\Rule`)

Static factories, each fixing the rule's type:

| Factory | Casts raw string to |
|---|---|
| `Rule::string()` | `string` |
| `Rule::int()` | `int` (accepts optional leading `-`, digits only) |
| `Rule::bool()` | `bool` (`1`/`true`/`yes`/`on` → `true`, `0`/`false`/`no`/`off` → `false`, case-insensitive) |
| `Rule::array()` | `list<string>` (splits on `,`, trims each item) |

Fluent instance methods (chainable, return `$this`):

| Method | Effect |
|---|---|
| `->required()` | Validation fails if the key is absent or an empty string. |
| `->default(mixed $value)` | Used verbatim when the key is absent/empty and not required. Not passed through casting — provide it already in the target type. |
| `->in(array $values)` | The cast value must be one of `$values` (strict comparison), or validation fails. |

### `EnvValidator` (`Kasapdev\EnvValidator\EnvValidator`)

- `EnvValidator::make(array $rules): self` — `$rules` is `[string $key => Rule $rule]`.
- `->validate(array $env): array` — validates and casts `$env` against the rules. Returns `[string $key => mixed $castValue]` on success. Throws `EnvValidationException` if any rule is violated, after checking **all** rules against **all** keys (no fail-fast).
- `->generateExampleFile(): string` — builds a `.env.example`-shaped string directly from the declared rules (see [Generating a .env.example File](#generating-a-envexample-file)), so it can never drift from what `validate()` actually enforces.

Behavior notes:
- A key that is absent from `$env`, or present with an empty string, is treated as "not provided": it triggers `required()` failure, or falls back to `default()`, or resolves to `null` if neither applies.
- Only string values in `$env` are accepted for casting (this is what real process environments and `EnvFileLoader` produce); any other scalar/array type in `$env` is reported as a validation error.

### `EnvValidationException` (`Kasapdev\EnvValidator\EnvValidationException`)

- `->getMessage(): string` — a combined, human-readable summary of every error.
- `->getErrors(): array` — the individual error messages, one per violation, in rule-declaration order.

### `EnvFileLoader` (`Kasapdev\EnvValidator\EnvFileLoader`)

- `EnvFileLoader::loadEnvFile(string $path): array` — parses a `.env`-style file into `[string $key => string $value]`. Supports:
  - `KEY=value` pairs.
  - Full-line comments (`#` or `;`, leading whitespace allowed).
  - Blank lines.
  - Double-quoted values, with `\n`, `\r`, `\t`, `\"`, `\\` escapes interpreted.
  - Single-quoted values, taken literally (no escape processing).
  - Trailing inline comments on unquoted values (`KEY=value # comment`).
  - Throws `RuntimeException` if the file doesn't exist or can't be read.

## Testing

This library has no PHPUnit dependency — tests are a single self-contained script:

```bash
php tests/run.php
```

It exits `0` and prints `All tests passed.` when everything passes, or a non-zero exit code with a failure count otherwise.

## License

MIT — see [LICENSE](LICENSE).
