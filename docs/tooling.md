# Contributing to Laravel Dominion

Thank you for contributing to Laravel Dominion. This guide describes the local development workflow and the checks expected before a change is submitted.

## Requirements

- PHP 8.4 or newer
- Composer 2

Dominion supports Laravel 12 and 13. Composer resolves the compatible framework and Testbench versions for the dependency set being tested.

## Local setup

Clone the repository and install its dependencies:

```bash
composer install
```

Composer runs Testbench package discovery automatically. If the workbench needs to be rebuilt explicitly, run:

```bash
composer build
```

The Testbench workbench provides the Laravel application used by the feature tests. Package source belongs in `src`, while workbench-only application models and fixtures belong in `workbench`.

## Tests

Run the complete Pest suite with:

```bash
composer test
```

To run a specific file or filter, invoke Pest directly:

```bash
vendor/bin/pest tests/Feature/PrincipalDeletionTest.php
vendor/bin/pest --filter="preserves assignments"
```

Tests use an in-memory SQLite database and the array cache store as configured in `phpunit.xml`.

Place tests according to their scope:

- `tests/Unit` for isolated domain behavior that does not require Laravel integration or a database.
- `tests/Feature` for service-container, Eloquent, database, cache, command, Gate, and package integration behavior.
- `tests/Support` for test-only models, enums, fakes, and shared fixtures.

Every bug fix should include a regression test that fails without the fix. Prefer observable behavior over assertions against implementation details.

## Code quality

### Fix and validate

Run the complete quality suite with:

```bash
composer lint
```

This command can change tracked PHP files because it executes the following tools in order:

1. `vendor/bin/pint --ansi` formats files and applies style fixes.
2. `vendor/bin/phpstan analyse --verbose --ansi` reports static-analysis errors without changing files.
3. `vendor/bin/rector process --dry-run --ansi` reports proposed refactors without applying them.

Review the diff after running `composer lint`, because Pint may have updated the source or tests.

### Validate without changing files

To see the same formatting, static-analysis, and refactoring results without changing tracked files, run:

```bash
vendor/bin/pint --test --ansi
vendor/bin/phpstan analyse --verbose --ansi
vendor/bin/rector process --dry-run --ansi
```

Pint's `--test` option reports formatting differences instead of fixing them. PHPStan only analyzes the code, and Rector's `--dry-run` option reports proposed changes without applying them. These tools may create ignored runtime cache files, but they do not modify tracked source files in this mode.

Tests and whitespace validation are also non-changing checks:

```bash
composer test
git diff --check
```

## Development conventions

- Follow the existing namespace and directory structure.
- Use Laravel helpers and framework conventions where they improve clarity.
- Keep public behavior compatible unless the change is intentionally documented as breaking.
- Add configuration validation for invalid values while avoiding unnecessary work during package boot.
- Keep principal, tenant, and table identifier handling consistent with the published schema configuration.
- Use database transactions for multi-table authorization mutations.
- Defer cache invalidation until a transaction commits successfully.

Authorization assignments must be mutated through Dominion's assignment API, including `assignRole`, `removeRole`, `grantPermission`, `denyPermission`, `revokePermission`, and `assignAuthorizationProfile`. Do not use relationship mutation methods or write directly to Dominion tables; doing so bypasses conflict handling, events, transactions, and cache invalidation.

## Documentation

Update the README or relevant documentation whenever a change affects installation, configuration, public APIs, supported behavior, or upgrade requirements. Examples should use the current Dominion API and should be covered by tests where practical.

## Before submitting a change

Run the following checks from a clean working tree:

```bash
composer test
composer lint
git diff --check
```

Then verify that:

- New behavior and regressions are covered by tests.
- Public-facing changes are documented.
- Configuration defaults and published migrations remain compatible.
- No generated files, debugging output, or unrelated formatting changes are included.
- The complete test suite, static analysis, formatting, and Rector checks pass.
