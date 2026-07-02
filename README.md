# Booking Manager

This is a complete, production-ready solution to parse, sanitize, deduplicate, and reliably store passenger payloads in PHP 8.1+.

## Design Decisions

- **Dependency Injection**: The `PDO` instance and an optional logger closure are injected directly into `BookingManager` via the constructor. This ensures the class is testable and isn't hardcoded to specific environment dependencies.
- **Graceful Error Handling**: Database failures, structural JSON errors, and logic warnings are caught and delegated to the logger rather than allowing unhandled exceptions to crash script execution. Batch inserts continue cleanly on invalid items.
- **Efficient Operations**: `PDO` prepared statements are used exclusively. To improve performance and prevent SQL injection, a single query is prepared once and executed in a loop.
- **Isolating Logic**: Sanitization methods (`sanitizeAge`, `sanitizePhone`) are protected and isolated, tested individually using an anonymous/extended mock wrapper in the test suite. 

## Edge Cases Handled

- **Malformed JSON**: Caught explicitly using `JSON_THROW_ON_ERROR` inside a `try/catch`. Skips payload but returns a `malformed => 1` summary counter.
- **Missing Required Fields**: Any record without a valid `passenger_full_name` (non-empty string) or `timestamp` (integer) is ignored and recorded under the `skipped` counter.
- **Age Bounds and Types**: Anything that cannot safely be evaluated to an integer from 1–120 is reset to `NULL`, maintaining the valid passenger record rather than dropping it entirely.
- **Duplicates**: Deduplication occurs based on normalized Names and Phone Numbers prior to timestamp-based chronological sorting. The first occurring duplicate *within the payload* is kept. The others are discarded.
- **Unicode Support**: `PDO` operates natively with UTF-8 data. However, the driver connection string heavily emphasizes configuring `charset=utf8mb4` during startup to fully support complex non-Latin inputs safely.

## How to Run

1. **Database Setup**:
   Ensure you have a MySQL/MariaDB database set up. Execute the contents of `schema.sql` to build `tbl_pass_det_v2`.

2. **Configuration**:
   In `run.php`, adjust the following PDO connection specifics:
   ```php
   $dsn = "mysql:host=127.0.0.1;dbname=test_db;charset=utf8mb4";
   $user = "root";
   $pass = "";
   ```

3. **Execution**:
   Run the processing script using the PHP CLI:
   ```bash
   php run.php
   ```

4. **Testing**:
   Tests are written to target PHPUnit. You can run them by installing the package:
   ```bash
   composer require --dev phpunit/phpunit
   ./vendor/bin/phpunit BookingManagerTest.php
   ```

## Post-development Review

No compromises or conflicts were required between the assignment specifications, `strict_types=1`, or PHP 8 compatibility. 

PHP 8's type declarations fully support the necessary business rules. For example, `mixed` was effectively used alongside explicit casts for type-safe validation where needed (e.g. `mixed $age`). Type declarations cover parameters and return types across all methods properly, ensuring clean code hygiene.
