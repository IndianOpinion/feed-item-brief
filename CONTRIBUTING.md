# Contributing

Thank you for helping. Bug reports, fixes and documentation are welcome.

## Setup

PHP 8.1 or later with dom, json, mbstring and mysqli, and Composer 2.

```sh
git clone https://github.com/IndianOpinion/feed-item-brief.git
cd feed-item-brief
composer install
```

## Tests

```sh
composer test      # PHPUnit
composer analyse   # PHPStan, level 8
composer lint      # phpcs, PSR-12
composer check     # all three
```

The unit tests run against a small WordPress stub layer in `tests/stubs/`
and a fake model client. They need no WordPress install and no network.

The database tests in `tests/Integration/` run against a real MySQL or
MariaDB server and are skipped unless you point them at one:

```sh
FIB_TEST_DB_SOCKET=/path/to/mysqld.sock FIB_TEST_DB_NAME=feed_item_brief_test vendor/bin/phpunit
# or FIB_TEST_DB_HOST, FIB_TEST_DB_PORT, FIB_TEST_DB_USER, FIB_TEST_DB_PASSWORD
```

Use a throwaway database: the tests create and drop their own tables.

## Style

- PSR-12, `declare(strict_types=1);` in every file, PHP 8.1 syntax only
  (no readonly classes, no DNF types, no standalone `true` type).
- Typed exceptions, never silent failure, never the `@` operator.
- Escape every output, validate every input, nonces and capability checks
  on every admin action.
- Short sentences and British spelling in docs. No em dashes.

## Pull requests

- One change per pull request, with a test that fails without it.
- Update the README, CHANGELOG and `docs/ARCHITECTURE.md` when behaviour
  or a decision changes.
- Keep `main` green: tests, PHPStan and phpcs must pass.
- A DCO-style sign-off is not required.
