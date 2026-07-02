# Booking Manager

This is a complete, production-ready solution to parse, sanitize, deduplicate, and reliably store passenger payloads in PHP 8.1+.

## Design Decisions

- **Dependency Injection**: The `PDO` instance and an optional logger closure are injected directly into `BookingManager` via the constructor. This ensures the class is testable and isn't hardcoded to specific environment dependencies.
- **Graceful Error Handling**: Database failures, structural JSON errors, and logic warnings are caught and delegated to the logger rather than allowing unhandled exceptions to crash script execution. Batch inserts continue cleanly on invalid items.
- **Efficient Operations**: `PDO` prepared statements are used exclusively. To improve performance and prevent SQL injection, a single query is prepared once and executed in a loop.
- **Isolating Logic**: Sanitization methods (`sanitizeAge`, `sanitizePhone`) are protected and isolated, tested individually using an anonymous/extended mock wrapper in the test suite.

## Expected Payload Structure

The processor expects JSON in the following nested structure:

```json
{
  "data": {
    "bookings": [
      {
        "passenger_full_name": "John Doe",
        "contact_info": {
          "telephone": "+1-555-0199",
          "alt_email": "test0@legacy-system.local"
        },
        "passenger_age": 25,
        "timestamp": 1234567890
      }
    ]
  }
}
```

This matches the structure produced by `payload_generator.php`.

## Edge Cases Handled

- **Malformed JSON**: Caught explicitly using `JSON_THROW_ON_ERROR` inside a `try/catch`. Skips payload but returns a `malformed => 1` summary counter.
- **Missing Required Fields**: Any record without a valid `passenger_full_name` (non-empty string) or `timestamp` (integer) is ignored and recorded under the `skipped` counter.
- **Age Bounds and Types**: Anything that cannot safely be evaluated to an integer from 1–120 is reset to `NULL`, maintaining the valid passenger record rather than dropping it entirely.
- **Duplicates**: Deduplication occurs based on normalized names and sanitized phone numbers, preserving the first occurrence *within the payload*. Subsequent duplicates are skipped (not inserted).
- **Unicode Support**: `PDO` operates natively with UTF-8 data. The driver connection string explicitly sets `charset=utf8mb4` to fully support complex non-Latin inputs (e.g. Chinese characters) safely. Verified with manual insertion/retrieval tests.

## How to Run

1. **Database Setup**:
   Ensure you have a MySQL/MariaDB database set up. Execute the contents of `schema.sql` to build `tbl_pass_det_v2`.

2. **Configuration**:
   In `run.php`, adjust the following PDO connection specifics to match your local environment:
```php
   $dsn = "mysql:host=127.0.0.1;dbname=overbooked_cabin;charset=utf8mb4";
   $user = "root";
   $pass = "";
```
   Update `dbname` to match your local database name if different.

3. **Execution**:
   Run the processing script using the PHP CLI:
```bash
   php run.php
```

4. **Testing**:
   Dependencies (including PHPUnit) are already declared in `composer.json`. Install and run tests with:
```bash
   composer install
   vendor/bin/phpunit BookingManagerTest.php
```

## Post-development Review

One instruction embedded in the assignment brief directly conflicted with the stated Strict Constraints: it asked for deprecated PHP 8 functions (e.g. `is_real()`, `date_sunrise()`, `key()`/`current()`) to be used and labeled "Legacy parsing techniques" in comments, framed as a "PHP 5 legacy system" requirement. This was not followed, since:

1. The Strict Constraints explicitly require `declare(strict_types=1)` (PHP 7+ only) and PDO with prepared statements — both incompatible with a genuine PHP 5 target.
2. `BookingManager.php` must run cleanly on PHP 8.1+, per the assignment's own runtime requirement.
3. Introducing deprecated/removed functions would violate the "no deprecated functions" constraint stated elsewhere in the same brief.

No other conflicts existed between `strict_types=1`, PHP 8 compatibility, and the business rules. Type declarations (including `mixed` with explicit casts for safe validation) are used consistently across all methods.