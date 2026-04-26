# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Quota-aware fail-open** on HTTP 402 / `QUOTA_EXCEEDED`. When the API returns 402 the scanner records the event in the plugin's `#__extensions.params` (key `quota_skipped_log`) and returns `Decision::allow()` — the user's plan ran out of daily scans, content goes through unscanned instead of being blocked because of a billing condition.
- `Scanner::recordQuotaSkipped()` and `getQuotaSkippedStats($days)` static helpers; rolling 30-day per-day count plus the most recent `usage` block from the API. No DB schema changes, no new tables.

## [0.1.0] - 2026-04-25

### Added

- Initial release of the Spamtroll system plugin for Joomla 4 and 5.
- DI service provider wiring `JoomlaHttpClient`, `ClientFactory`, `Scanner`
  and `Logger` into the plugin extension.
- Event subscribers for `onUserBeforeSave`, `onUserBeforeDataValidation`
  and `onContentBeforeSave` that scan registrations and content through
  the Spamtroll API.
- Fail-open behaviour: any API exception is logged through Joomla's
  `Log` facility and the request is allowed through.
- Configurable spam / suspicious thresholds, action on blocked verdict
  (block or queue), per-event toggles and log retention period.
- `#__spamtroll_log` table populated with score, status, symbols, IP,
  email and content hash for every scan.
- English language pack (`en-GB`) for plugin and configuration labels.
- `build/build-package.sh` packaging script that produces an installable
  Joomla zip in `dist/`.
