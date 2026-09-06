<?php

declare(strict_types=1);

$__failures = 0;
function check(string $label, bool $condition): void {
    global $__failures;
    echo ($condition ? "[PASS] " : "[FAIL] ") . $label . "\n";
    if (!$condition) { $__failures++; }
}

require_once __DIR__ . '/../src/RuleType.php';
require_once __DIR__ . '/../src/Rule.php';
require_once __DIR__ . '/../src/EnvValidationException.php';
require_once __DIR__ . '/../src/EnvValidator.php';
require_once __DIR__ . '/../src/EnvFileLoader.php';

use Kasapdev\EnvValidator\EnvFileLoader;
use Kasapdev\EnvValidator\EnvValidationException;
use Kasapdev\EnvValidator\EnvValidator;
use Kasapdev\EnvValidator\Rule;

// ---------------------------------------------------------------------
// EnvValidator: fully passing case, with correct type casting
// ---------------------------------------------------------------------

$validator = EnvValidator::make([
    'APP_NAME'  => Rule::string()->required(),
    'APP_PORT'  => Rule::int()->required(),
    'APP_DEBUG' => Rule::bool()->required(),
    'ALLOWED_IPS' => Rule::array()->required(),
]);

$result = $validator->validate([
    'APP_NAME' => 'MyApp',
    'APP_PORT' => '8080',
    'APP_DEBUG' => 'true',
    'ALLOWED_IPS' => '127.0.0.1, 10.0.0.1,192.168.1.1',
]);

check('passing case: no exception thrown and array returned', is_array($result));
check('passing case: string value preserved as string', $result['APP_NAME'] === 'MyApp' && is_string($result['APP_NAME']));
check('passing case: int value cast to int', $result['APP_PORT'] === 8080 && is_int($result['APP_PORT']));
check('passing case: bool value cast to bool', $result['APP_DEBUG'] === true && is_bool($result['APP_DEBUG']));
check('passing case: array value split and trimmed', $result['ALLOWED_IPS'] === ['127.0.0.1', '10.0.0.1', '192.168.1.1']);

// ---------------------------------------------------------------------
// EnvValidator: defaults applied when key absent
// ---------------------------------------------------------------------

$validatorWithDefaults = EnvValidator::make([
    'APP_ENV'   => Rule::string()->default('production'),
    'APP_PORT'  => Rule::int()->default(3000),
    'APP_DEBUG' => Rule::bool()->default(false),
    'FEATURES'  => Rule::array()->default(['a', 'b']),
    'OPTIONAL_NO_DEFAULT' => Rule::string(),
]);

$withDefaults = $validatorWithDefaults->validate([]);

check('defaults: string default applied', $withDefaults['APP_ENV'] === 'production');
check('defaults: int default applied', $withDefaults['APP_PORT'] === 3000);
check('defaults: bool default applied', $withDefaults['APP_DEBUG'] === false);
check('defaults: array default applied', $withDefaults['FEATURES'] === ['a', 'b']);
check('defaults: optional key without default resolves to null', $withDefaults['OPTIONAL_NO_DEFAULT'] === null);

// A blank string value should be treated the same as an absent key.
$withBlank = $validatorWithDefaults->validate(['APP_ENV' => '']);
check('defaults: blank string value triggers default (not treated as a real value)', $withBlank['APP_ENV'] === 'production');

// ---------------------------------------------------------------------
// EnvValidator: THE key correctness property.
// Every violation across every key must be collected and reported in a
// single exception; validation must not fail fast on the first bad key.
// ---------------------------------------------------------------------

$strictValidator = EnvValidator::make([
    'DB_HOST' => Rule::string()->required(),
    'DB_PORT' => Rule::int()->required(),
    'DB_USER' => Rule::string()->required(),
    'APP_ENV' => Rule::string()->in(['dev', 'staging', 'production']),
]);

$caughtAggregated = false;
$aggregatedErrors = [];

try {
    $strictValidator->validate([
        // DB_HOST missing entirely
        'DB_PORT' => 'not-a-number', // bad int
        // DB_USER missing entirely
        'APP_ENV' => 'nonsense', // not in allowed list
    ]);
} catch (EnvValidationException $e) {
    $caughtAggregated = true;
    $aggregatedErrors = $e->getErrors();
}

check('aggregation: exception is thrown when multiple keys are invalid', $caughtAggregated);
check('aggregation: exactly 4 errors collected (all violations, not just the first)', count($aggregatedErrors) === 4);
check(
    'aggregation: missing DB_HOST is reported',
    (bool) array_filter($aggregatedErrors, static fn (string $m) => str_contains($m, 'DB_HOST'))
);
check(
    'aggregation: invalid DB_PORT is reported',
    (bool) array_filter($aggregatedErrors, static fn (string $m) => str_contains($m, 'DB_PORT'))
);
check(
    'aggregation: missing DB_USER is reported',
    (bool) array_filter($aggregatedErrors, static fn (string $m) => str_contains($m, 'DB_USER'))
);
check(
    'aggregation: invalid APP_ENV (in-list violation) is reported',
    (bool) array_filter($aggregatedErrors, static fn (string $m) => str_contains($m, 'APP_ENV'))
);
check(
    'aggregation: getMessage() produces a combined human-readable summary',
    isset($e) && str_contains($e->getMessage(), 'DB_HOST') && str_contains($e->getMessage(), 'APP_ENV')
);

// ---------------------------------------------------------------------
// EnvValidator: required but missing (single key case)
// ---------------------------------------------------------------------

$requiredValidator = EnvValidator::make([
    'SECRET_KEY' => Rule::string()->required(),
]);

$threwForMissingRequired = false;
try {
    $requiredValidator->validate([]);
} catch (EnvValidationException $e2) {
    $threwForMissingRequired = true;
    check('required: single missing key error mentions the key name', str_contains($e2->getErrors()[0], 'SECRET_KEY'));
    check('required: exactly one error reported', count($e2->getErrors()) === 1);
}
check('required: exception thrown for missing required key', $threwForMissingRequired);

// ---------------------------------------------------------------------
// EnvValidator: type casting failures
// ---------------------------------------------------------------------

$typeValidator = EnvValidator::make([
    'PORT' => Rule::int()->required(),
    'FLAG' => Rule::bool()->required(),
]);

$typeErrors = [];
try {
    $typeValidator->validate(['PORT' => 'abc', 'FLAG' => 'maybe']);
} catch (EnvValidationException $e3) {
    $typeErrors = $e3->getErrors();
}
check('type casting: invalid int value produces an error', count($typeErrors) === 2);
check('type casting: negative integers are accepted', $typeValidator->validate(['PORT' => '-5', 'FLAG' => 'off'])['PORT'] === -5);
check('type casting: "off" is accepted as boolean false', $typeValidator->validate(['PORT' => '1', 'FLAG' => 'off'])['FLAG'] === false);
check('type casting: "yes" is accepted as boolean true', $typeValidator->validate(['PORT' => '1', 'FLAG' => 'yes'])['FLAG'] === true);

// ---------------------------------------------------------------------
// EnvValidator: in() constraint
// ---------------------------------------------------------------------

$inValidator = EnvValidator::make([
    'APP_ENV' => Rule::string()->in(['dev', 'staging', 'production'])->default('dev'),
]);

check('in(): valid value passes', $inValidator->validate(['APP_ENV' => 'staging'])['APP_ENV'] === 'staging');

$inThrew = false;
try {
    $inValidator->validate(['APP_ENV' => 'not-allowed']);
} catch (EnvValidationException $e4) {
    $inThrew = true;
    check('in(): violation message names the key', str_contains($e4->getErrors()[0], 'APP_ENV'));
}
check('in(): exception thrown for disallowed value', $inThrew);

// ---------------------------------------------------------------------
// EnvFileLoader: parse a real temp .env file on disk
// ---------------------------------------------------------------------

$tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-env-validator-test-' . uniqid() . '.env';

$envFileContents = <<<ENV
# Full-line comment, should be ignored
; Another style of full-line comment

APP_NAME=MyApp
APP_PORT=8080

# blank line above should be skipped
DOUBLE_QUOTED="hello world"
SINGLE_QUOTED='hello world'
ESCAPED="line1\\nline2\\ttabbed"
QUOTED_WITH_HASH="value # not a comment"
UNQUOTED_WITH_COMMENT=value # this is a trailing comment
EMPTY_VALUE=
SPACED_KEY = trimmed value
ENV;

file_put_contents($tmpPath, $envFileContents);

try {
    $loaded = EnvFileLoader::loadEnvFile($tmpPath);

    check('loadEnvFile: unquoted value parsed', $loaded['APP_NAME'] === 'MyApp');
    check('loadEnvFile: numeric unquoted value parsed as string', $loaded['APP_PORT'] === '8080');
    check('loadEnvFile: double-quoted value has quotes stripped', $loaded['DOUBLE_QUOTED'] === 'hello world');
    check('loadEnvFile: single-quoted value has quotes stripped', $loaded['SINGLE_QUOTED'] === 'hello world');
    check('loadEnvFile: double-quoted escapes are interpreted', $loaded['ESCAPED'] === "line1\nline2\ttabbed");
    check('loadEnvFile: "#" inside double quotes is preserved, not treated as a comment', $loaded['QUOTED_WITH_HASH'] === 'value # not a comment');
    check('loadEnvFile: trailing unquoted comment is stripped', $loaded['UNQUOTED_WITH_COMMENT'] === 'value');
    check('loadEnvFile: empty value parses to empty string', $loaded['EMPTY_VALUE'] === '');
    check('loadEnvFile: whitespace around key/value is trimmed', $loaded['SPACED_KEY'] === 'trimmed value');
    check('loadEnvFile: full-line "#" comments are skipped entirely', !array_key_exists('#', $loaded));
    check('loadEnvFile: only the expected keys were parsed (comments/blanks excluded)', count($loaded) === 9);

    // Feed the loaded .env values straight into EnvValidator.
    $fileBackedValidator = EnvValidator::make([
        'APP_NAME' => Rule::string()->required(),
        'APP_PORT' => Rule::int()->required(),
    ]);
    $fileBackedResult = $fileBackedValidator->validate($loaded);
    check('loadEnvFile -> EnvValidator integration: values validate and cast correctly', $fileBackedResult === ['APP_NAME' => 'MyApp', 'APP_PORT' => 8080]);
} finally {
    if (is_file($tmpPath)) {
        unlink($tmpPath);
    }
}

check('loadEnvFile: temp fixture file was cleaned up', !is_file($tmpPath));

// ---------------------------------------------------------------------
// EnvFileLoader: missing file throws
// ---------------------------------------------------------------------

$missingFileThrew = false;
try {
    EnvFileLoader::loadEnvFile(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'this-file-should-not-exist-' . uniqid() . '.env');
} catch (\RuntimeException) {
    $missingFileThrew = true;
}
check('loadEnvFile: throws RuntimeException for a missing file', $missingFileThrew);

// ---------------------------------------------------------------------
// EnvValidator: additional edge cases
// ---------------------------------------------------------------------

// array cast preserves empty elements from consecutive/trailing commas.
$arrayValidator = EnvValidator::make(['LIST' => Rule::array()->required()]);
check(
    'array cast preserves empty elements from consecutive/trailing commas',
    $arrayValidator->validate(['LIST' => 'a,,b,'])['LIST'] === ['a', '', 'b', '']
);

// bool cast is case-insensitive.
$boolValidator = EnvValidator::make(['FLAG' => Rule::bool()->required()]);
check('bool cast accepts mixed-case "YES"', $boolValidator->validate(['FLAG' => 'YES'])['FLAG'] === true);
check('bool cast accepts mixed-case "False"', $boolValidator->validate(['FLAG' => 'False'])['FLAG'] === false);

// A non-string raw value (e.g. an actual int/array already in $env) is rejected with a clear error.
$nonStringValidator = EnvValidator::make(['PORT' => Rule::int()->required()]);
$nonStringErrors = [];
try {
    $nonStringValidator->validate(['PORT' => 8080]);
} catch (EnvValidationException $e5) {
    $nonStringErrors = $e5->getErrors();
}
check('a non-string raw env value produces a clear "expected a string" error', count($nonStringErrors) === 1 && str_contains($nonStringErrors[0], 'expected a string'));

// default() values are used verbatim and are NOT checked against in().
$defaultBypassesInValidator = EnvValidator::make([
    'APP_ENV' => Rule::string()->in(['dev', 'staging', 'production'])->default('not-in-the-list'),
]);
check(
    'a default() value is used verbatim and is not checked against in()',
    $defaultBypassesInValidator->validate([])['APP_ENV'] === 'not-in-the-list'
);

// ---------------------------------------------------------------------
// EnvFileLoader: additional edge cases
// ---------------------------------------------------------------------

$tmpPath2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-env-validator-test-' . uniqid() . '.env';

$envFileContents2 = <<<ENV
CONN_STRING=a=b=c
=no-key-here
UNTERMINATED="still-open
ENV;

file_put_contents($tmpPath2, $envFileContents2);

try {
    $loaded2 = EnvFileLoader::loadEnvFile($tmpPath2);

    check('loadEnvFile: only the first "=" splits key from value, rest stays in the value', $loaded2['CONN_STRING'] === 'a=b=c');
    check('loadEnvFile: a line with an empty key is skipped entirely', !array_key_exists('', $loaded2));
    check(
        'loadEnvFile: an unterminated double-quoted value falls back to the literal raw text (no quote stripping)',
        $loaded2['UNTERMINATED'] === '"still-open'
    );
} finally {
    if (is_file($tmpPath2)) {
        unlink($tmpPath2);
    }
}

echo $__failures === 0 ? "\nAll tests passed.\n" : "\n$__failures test(s) FAILED.\n";
exit($__failures === 0 ? 0 : 1);
