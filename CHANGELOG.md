# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.0.0 — 2026-10-26

The final 2.0 release. No changes since 2.0.0-rc.1.

## 2.0.0-alpha.3 — 2026-10-24

First release of the 2.0 line. Background jobs for Zana applications. Jobs go onto a queue (in memory, in the database, or run at once) and a worker processes them. Each job runs in its own container scope, with retries and failure storage. Requires PHP 8.5 or later.

Documentation: [the queue guide](https://github.com/mjnable/zana-docs/blob/main/guides/queue.md).
