# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.1.0] - 2026-09-06

### Added

- Test coverage for edge cases in `EnvValidator` and `EnvFileLoader`:
  - Array-type casting preserves empty elements produced by consecutive or
    trailing commas (e.g. `"a,,b,"` casts to `['a', '', 'b', '']`).
  - Boolean casting is confirmed case-insensitive for mixed-case input
    (`"YES"`, `"False"`).
  - A non-string raw value already present in the `$env` array (e.g. an
    actual `int` rather than a string) produces a clear "expected a
    string" validation error instead of a type error.
  - `Rule::default()` values are used verbatim and are confirmed to bypass
    the `in()` allow-list check, matching the documented "used verbatim"
    behavior.
  - `EnvFileLoader::loadEnvFile()`: only the first `=` on a line splits key
    from value, so values that themselves contain `=` are preserved in
    full.
  - `EnvFileLoader::loadEnvFile()`: a line with an empty key (e.g.
    `=value`) is skipped entirely.
  - `EnvFileLoader::loadEnvFile()`: an unterminated double-quoted value
    falls back to the literal raw text instead of having its quote
    stripped.

No behavioral changes were needed — all new edge-case tests passed against
the existing implementation.
