# Agent Instructions

## Language

Respond in Japanese. Explain technical content in detail.

## Project Goal

Modernize the legacy Igo PHP library for PHP 8 and later.

The main goals are:

1. Add explicit PHP types.
2. Move implementation to namespaced PSR-4 classes.
3. Build PHPUnit coverage before replacing behavior.

## Modernization Policy

Do not modernize code in `lib/`.

Treat `lib/` as the legacy reference implementation. First write tests that
capture the behavior of classes in `lib/`, then implement the modern equivalent
under `src/` and verify that the same behavior passes there.

The project does not need to preserve compatibility for old global-class users.
Do not add compatibility wrappers solely for old usages such as `new Igo()` or
`new Morpheme()`.

## Source Layout

Create modern code under `src/`.

Use PSR-4 autoloading and split files by class. Prefer the initial namespace
`Igo`, introducing sub-namespaces only when they express a clear responsibility
boundary.

## TDD Workflow

Follow Red, Green, Refactor for implementation changes.

For legacy characterization tests, the Red step may not apply because the test
documents behavior that already exists in `lib/`. Explicitly state when a change
is documentation-only or characterization-only.

## Code Comments

Every test, function, and class method must include a concise comment explaining
the purpose of the processing it performs.

Keep comments focused on intent, invariants, and non-obvious behavior. Avoid
comments that merely restate the code.

## Coding Style

Use constructor property promotion for promoted dependencies and immutable
configuration values when it keeps the class concise and readable.

## Verification

Before committing, run these commands as the normal verification loop:

```bash
composer format
composer lint
composer test
composer analyze
```

Run `composer dump-autoload` after changing Composer autoload configuration.

## Reference

See `docs/migration-policy.md` for the detailed migration policy and suggested
class migration order.
