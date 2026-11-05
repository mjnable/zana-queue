# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.13.0 — Unreleased

### Fixed

- The database queue works on PostgreSQL with jobs that have protected or private properties.
  `serialize()` writes NUL bytes for them, and PostgreSQL silently truncated the payload at the
  first one, so the job could not be read back. Payloads are now stored as base64; payloads
  written by earlier versions are still read, so queued and failed jobs survive the upgrade.
- Tested on SQLite, MySQL and PostgreSQL.

## 2.4.0 — 2026-10-26

### Changed

- Factory registrations declare the services they resolve with `uses()`, so the container checks
  their lifetimes at startup (FE-40).
- Requires `mj-zana/core` ^2.4.

## 2.3.0 — 2026-10-26

### Changed

- Tests and tooling (`tests/`, `phpunit.xml.dist`, `phpstan.neon.dist`) are no longer in Composer
  archives, so they stay out of production `vendor/` folders (FE-17).

## 2.0.0 — 2026-10-26

The final 2.0 release. No changes since 2.0.0-rc.1.

## 2.0.0-alpha.3 — 2026-10-24

First release of the 2.0 line. Background jobs for Zana applications. Jobs go onto a queue (in memory, in the database, or run at once) and a worker processes them. Each job runs in its own container scope, with retries and failure storage. Requires PHP 8.5 or later.

Documentation: [the queue guide](https://github.com/mjnable/zana-docs/blob/main/guides/queue.md).
