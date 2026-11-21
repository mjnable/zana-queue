# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.34.0 — Unreleased

### Security

- Copies of signed jobs are refused (a documented limit until now: the signature covered the job, not its row, so
  someone who can write to the jobs table could copy a job, which then ran again). With `QueueReplayMigration` run:
  - jobs are signed with a nonce and the time they were issued (`zs2`; `zs1` is still read), which the signature
    covers, so neither can be changed;
  - the nonce is unique among the waiting jobs (`zana_jobs.nonce`), so a copy cannot be added beside its job, and
    recorded in `zana_jobs_done` when the job ran, so a copy added later is refused, whichever of the two runs first;
  - a job issued longer ago than `queue.replay_window` (30 days by default), whose record may have been pruned, is
    refused, and so is a job signed before 2.34, which carries no nonce;
  - each refused job is moved to the failed jobs with the reason; `queue:retry` signs it again with a fresh nonce.
  Without the migration, or without a key, jobs run as before.
- `queue:table` writes the missing migrations only, so running it after an upgrade adds
  `*_refuse_copied_queue_jobs.php`. `check` warns (`queue.payload_replay`) while jobs are signed and it has not run.
- `PayloadKey::seal()` takes a nonce and an issue time; `envelope()` reads them back once the signature holds;
  `DatabaseQueue::refusesCopies()` says whether copies are refused. Tested on SQLite, MySQL and PostgreSQL.

## 2.20.0 — 2026-11-14

### Security

- Queued jobs are signed, HMAC-SHA256 with `queue.key` or a key derived from `security.secret`, and the signature is
  checked before anything is unserialized, so a row added to the queue table by anything but the application is refused
  (S28). `PayloadKey` holds the key.
- Unsigned jobs, written before 2.20, are refused; `queue.accept_unsigned: true` lets them through while the
  queue drains. Without any key, jobs are neither signed nor checked, as before.
- `check` warns (queue.payload_signing) when jobs are not signed, or unsigned ones are accepted.

## 2.13.0 — 2026-11-05

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
