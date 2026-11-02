# Tests

PHPUnit tests for the booking and appointment logic.

* `tests/Unit` needs no database (theme colour maths, question parsing, SQL splitter).
* `tests/Integration` runs the real application functions against a **MariaDB test database**
  (appointments, overlaps, provider unavailability, customers, verification tokens, booking questions,
  same-day email section, settings).

## Running them in GitHub

`.github/workflows/tests.yml` starts a MariaDB 10.6 container and runs everything on each push to `main` or
`feature` and on pull requests. Nothing to configure.

## Running them locally

1. Start a throwaway database (the tests delete data, so never point them at a real one):

   ```
   docker run --rm -d --name boomy-test-db -p 3306:3306 \
     -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=boomy_test mariadb:10.6
   ```

2. Install PHPUnit once: `composer install`

3. Run (the container above publishes port 3306, so the host is just `127.0.0.1`):

   ```
   export TEST_DB_HOST=127.0.0.1 TEST_DB_NAME=boomy_test TEST_DB_USER=root TEST_DB_PASS=root
   vendor/bin/phpunit
   ```

   PowerShell:

   ```
   $env:TEST_DB_HOST="127.0.0.1"; $env:TEST_DB_NAME="boomy_test"; $env:TEST_DB_USER="root"; $env:TEST_DB_PASS="root"
   vendor/bin/phpunit
   ```

   Only the unit tests (no database needed): `vendor/bin/phpunit --testsuite Unit`

## Safety

* `tests/bootstrap.php` stops immediately unless the configured database name ends in `_test`.
* If a real `config.php` exists, its database name must end in `_test` too. Otherwise delete it (or move it
  aside) and let the tests generate a temporary one from the `TEST_DB_*` variables; it is removed afterwards.
* The schema is rebuilt from `install.sql` (minus its `CREATE DATABASE` / `USE` lines) and every table except
  `settings` is emptied before each test.

## Notes for writing new tests

* Extend `Tests\Support\DatabaseTestCase`; use `Tests\Support\TestDatabase` for fixtures
  (`provider()`, `appointmentType()`, `customer()`, `setSetting()`).
* A few functions cache their results for the whole run (`get_booking_calendar_settings()`,
  `get_all_standard_breaks()`, `get_theme_settings()`), so a test cannot change those mid-run. The booking window
  is fixed at Mon-Fri 09:00-21:00 by `TestSchema::boot()`.
* `functions.php` upgrades the schema on the first `get_db()` call, so nothing may call `get_db()` before
  `TestSchema::boot()` has built the tables (the base class takes care of this).
* The page scripts (`booking_api.php`, `confirm_appointment.php`, ...) run on include and start sessions, so they
  are not covered here yet. Moving their logic into functions would make them testable.
