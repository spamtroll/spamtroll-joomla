# Spamtroll for Joomla

Real-time spam detection for Joomla 4 and 5 powered by the [Spamtroll API](https://spamtroll.io).
The plugin scans new user registrations and content (articles, contact submissions and any
extension that fires `onContentBeforeSave`) before they are persisted, and either lets them
through, sends them to moderation or blocks them outright based on the verdict returned by
the API.

## Requirements

- Joomla 4.0 or newer (tested against Joomla 4.4 and Joomla 5.x)
- PHP 8.1 or newer
- A Spamtroll API key (sign up at [spamtroll.io](https://spamtroll.io))

## Installation

### From a packaged ZIP

1. Download the latest `plg_system_spamtroll-<version>.zip` from the
   [Releases](https://github.com/spamtroll/spamtroll-joomla/releases) page or build it yourself
   (see below).
2. In the Joomla administrator panel, open **System → Install → Extensions**.
3. Drop the ZIP onto the *Upload Package File* tab.
4. Open **System → Manage → Plugins**, search for *System - Spamtroll* and enable it.
5. Open the plugin and paste your API key under the *Connection* tab.

### Building the ZIP from source

```bash
git clone https://github.com/spamtroll/spamtroll-joomla.git
cd spamtroll-joomla
composer install --no-dev
bash build/build-package.sh
```

The installable package is written to `dist/plg_system_spamtroll-<version>.zip` with the
plugin manifest at the top level so Joomla's installer accepts it directly.

## Configuration

| Field | Description |
| --- | --- |
| API key | Personal Spamtroll API key. Required. |
| API URL | Defaults to `https://api.spamtroll.io/api/v1`. Override only for self-hosted deployments. |
| Timeout | HTTP timeout for the API call (seconds, default `5`). |
| Spam threshold | Normalised score (0.0–1.0) above which content is treated as spam. Default `0.70`. |
| Suspicious threshold | Score above which content is sent to moderation. Default `0.40`. |
| Check user registrations | Toggle scanning of `onUserBeforeSave` and `onUserBeforeDataValidation`. |
| Check content | Toggle scanning of `onContentBeforeSave` (articles, contact, etc.). |
| Action on blocked | Either `block` (reject the save) or `queue` (allow but mark as moderated). |
| Log retention (days) | Older entries in `#__spamtroll_log` are pruned. `0` keeps everything. |

## Fail-open behaviour

Per the Spamtroll integration policy the plugin **never blocks legitimate traffic when the
API is unreachable**. Specifically:

- Network errors (connection refused, DNS failure, timeout) → content is allowed through.
- HTTP 5xx / 4xx responses → content is allowed through.
- Invalid JSON / unparseable responses → content is allowed through.
- Missing / empty API key → content is allowed through (the plugin is effectively a no-op).
- Only an explicit `blocked` status from a successful API response will cancel the save.

Every fail-open path is logged through Joomla's `Log` facility under the `spamtroll`
category so you can review what the API said in `administrator/logs/`.

## Data stored locally

The plugin creates `#__spamtroll_log` on install. Each row records the timestamp, source
(`comment`, `registration`, …), verdict status, normalised score, content hash, IP,
e-mail, username and the list of detection symbols returned by the API. The raw content
is never persisted, only its SHA-256 hash, so the table is safe to keep around for audit
purposes.

The `Log retention (days)` setting drives the cleanup performed at the end of every
request that touches the scanner.

## Development

```bash
composer install
composer stan      # PHPStan level 6
composer test      # PHPUnit unit tests
bash build/build-package.sh
```

PHPStan analyses `plg_system_spamtroll/src` and `plg_system_spamtroll/services`. Joomla
core classes are stubbed inside `tests/stubs/` for both the unit tests and PHPStan so the
suite runs without a Joomla install.

## License

GPL-2.0-or-later. Joomla extensions must be GPL-compatible; see [LICENSE](LICENSE).
