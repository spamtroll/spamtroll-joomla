# Spamtroll for Joomla

Spam detection for Joomla 4.4 and 5 through the [Spamtroll API](https://spamtroll.io).
The plugin scans new user registrations and content saves that fire Joomla's
`onContentBeforeSave` event. A blocked decision can cancel the save; the queue
setting displays a warning and allows saving. It does not create a moderation queue.
Sending a contact form message is not covered merely because Joomla has a contact component.

## Requirements

- Joomla 4.4 or Joomla 5; dispatch tests cover both event shapes. Full CMS installation testing is still required before a directory compatibility claim. Joomla 6 compatibility has not been verified.
- PHP 8.2 or newer
- A Spamtroll account and **platform API key** (sign up at [spamtroll.io](https://spamtroll.io), add a platform and copy its key); the service has separate plan limits.

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
bash build/build-package.sh
```

The installable package is written to `dist/plg_system_spamtroll-<version>.zip` with the
plugin manifest at the top level so Joomla's installer accepts it directly. The script
installs the Spamtroll PHP SDK into the archive's `vendor/` directory itself and refuses to
emit a package without it. The ZIP also includes the extension GPL license.
The build writes a SHA-256 sidecar and `dist/updates.xml` for the Joomla update server;
attach all three assets to the same GitHub release. Run `python3 build/verify-package.py`
to check the package and feed together. Publication status and the prepared directory
submission are tracked in [PUBLICATION.md](PUBLICATION.md).

## Configuration

| Field | Description |
| --- | --- |
| API key | Spamtroll platform API key. Required. |
| API URL | Defaults to `https://api.spamtroll.io/api/v1`. Override only for self-hosted deployments. |
| Timeout | HTTP timeout for the API call (seconds, default `5`). |
| Spam threshold | Normalised score (0.0–1.0) above which content is treated as spam. Default `0.70`. |
| Suspicious threshold | Score above which content is sent to moderation. Default `0.40`. |
| Check user registrations | Toggle scanning of `onUserBeforeSave`, which covers every registration path. |
| Check content | Toggle scanning of `onContentBeforeSave` (including article creation and edits). |
| Action on blocked | Either `block` (reject the save) or `queue` (allow with a warning; no moderation queue). |
| Log retention (days) | Older entries in `#__spamtroll_log` are pruned. `0` keeps everything. |

## Fail-open behaviour

Per the Spamtroll integration policy the plugin **never blocks legitimate traffic when the
API is unreachable**. Specifically:

- Network errors (connection refused, DNS failure, timeout) → content is allowed through.
- HTTP 5xx / 4xx responses → content is allowed through.
- Invalid JSON / unparseable responses → content is allowed through.
- Missing / empty API key → content is allowed through (the plugin is effectively a no-op).
- Only a successful API scan whose normalized score reaches the configured spam threshold can cancel a save, when the action is `block`.

Every fail-open path is logged through Joomla's `Log` facility under the `spamtroll`
category so you can review what the API said in `administrator/logs/`.

## Data stored locally

The plugin creates `#__spamtroll_log` on install. Each row records the timestamp, source
(`comment`, `registration`, …), verdict status, normalised score, content hash, IP,
e-mail, username and the list of detection symbols returned by the API. Raw content is not stored in this table. IP addresses, e-mail addresses and usernames
remain personal data; choose retention and access controls accordingly. Scanned content
and available registration metadata are transmitted to the configured API. See the
service [privacy policy](https://spamtroll.io/privacy) and [terms](https://spamtroll.io/terms).

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
