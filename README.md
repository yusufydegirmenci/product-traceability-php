# Product Traceability System — Reference Architecture

**Version: v1.4-prototype** · PHP 8 · SQLite / MySQL · 70 automated tests

A tamper-evident, chain-of-custody traceability design for physical products: from warehouse intake, to packaging and shipping, to dealers and returns. Every unit (or lot) has a GS1-compatible identity and an append-only, hash-chained event history, so "who touched what, when" can be reconstructed and verified later.

> **Status:** reference architecture / prototype. It has been tested with its own test suite, but it has **not** been reviewed by a second engineer or run in production. See `SECURITY_REVIEW.md` and `PRODUCTION_CHECKLIST.md` before using any part of it for real.
> Code comments and some docs are written in Turkish.

## What it does
- **GS1 identifiers:** GTIN builder with check digits, SSCC, serial generator and GS1 Digital Link URLs (`src/Gs1`)
- **Tamper-evident event store:** append-only ledger with HMAC hash chaining; fork attempts are detected and retried with backoff (`src/Ledger/EventStore.php`)
- **Lot and unit tracking:** full history for unit-level items and for lot-based items without individual IDs (`LotTraceService`, FIFO consumption)
- **Packaging and shipping:** package sealing, package/label mismatch protection, warehouse transfers with customs-zone handling
- **Returns and refunds:** return eligibility windows, per-product weight verification, claims kept separate from verified system events
- **Fraud and abuse guards:** scan-sequence guard, dwell-time guard, duplicate-scan detection, relationship/conflict-of-interest guard, collusion and device-fault detectors, four-eyes approval for destructive actions (`src/Risk`, `src/Ledger`)
- **Operations:** shift handover lockout, cycle counts, audit-readiness report, privacy-compliance service, notifications
- **Configurable thresholds:** every limit lives in `config/warehouse.php` and can be overridden from `.env` without code changes

## Project layout
```
src/Gs1/        GS1 identifiers (GTIN, SSCC, Digital Link, serials)
src/Ledger/     event store, entities, lots, packages, returns, transfers, reporting
src/Risk/       scoring, weight reconciliation, authorization and relationship guards
src/Config/     .env loader and validator
src/Logging/    PSR-3 style logger and error reporting
schema/         MySQL and SQLite schemas, sample product weight profiles
tests/          Unit, Integration and Concurrency tests
tools/          realistic data seeder
demo.php        end-to-end walkthrough of the scenarios
ARCHITECTURE.md       design decisions and roadmap
SECURITY_REVIEW.md    open review items for the security-critical modules
PRODUCTION_CHECKLIST.md
```

## Run it
Requires PHP 8.1+ with `pdo_sqlite`; `pcntl` is needed for the concurrency tests.

```bash
php tests/run-tests.php                      # all tests
php tests/run-tests.php --group=fast         # quick tests only
php tests/run-tests.php --group=concurrency  # real multi-process race tests
php demo.php                                 # walkthrough of the scenarios
```
Copy `.env.example` to `.env` to override thresholds or set the database and HMAC key. Never commit `.env`.

## Three design rules
1. **Claims are not facts.** What a customer or dealer says happened is stored as a claim and never mixed with verified system or carrier events.
2. **History is append-only.** Nothing is updated or deleted; corrections are new events, and each event is hash-chained to the previous one.
3. **No single person can complete a risky action alone.** Destructive and high-value actions need a second, independent approver.

## Not covered (on purpose)
A public GS1 Digital Link resolver, real-hardware scanner integration and a UI are out of scope. See `ARCHITECTURE.md` for the roadmap.

## Author
Yusuf Yahya Değirmenci — [GitHub](https://github.com/yusufydegirmenci) · [LinkedIn](https://www.linkedin.com/in/yusufydegirmenci/)
