# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **Every user registration and every article save returned HTTP 500.** The plugin implements `SubscriberInterface`, so `CMSPlugin::registerListeners()` passes it to `Dispatcher::addSubscriber()` and skips the legacy argument-unwrapping layer (`libraries/src/Plugin/CMSPlugin.php:226-233` in Joomla 5.3.0, `:204-212` in 4.4.13). The dispatcher then calls each listener as `$listener($event)` — exactly one argument (`joomla/event` 3.0.2 `src/Dispatcher.php:454`). All three listeners declared three required parameters, so PHP raised `ArgumentCountError` before the method body ran; the in-method `try`/`catch` never executed, and since `ArgumentCountError` extends `Error` rather than `Exception`, neither `User::save()` nor `AdminModel::save()` caught it. `onUserBeforeSave` and `onContentBeforeSave` are now `(EventInterface $event): void` and read their payload from the event.
- **The veto never reached Joomla.** `onContentBeforeSave` returned `bool`, but the subscriber path discards return values. Joomla reads the verdict from `$event['result']` (`User.php:788-794`, `AdminModel.php:1293-1299`, `EventAware.php:111-114`). Both listeners now write it there: `addResult(false)` on Joomla 5's concrete, immutable `ResultAware` event classes, `setArgument('result', …)` on the plain mutable `Joomla\Event\Event` that Joomla 4 dispatches for these names.
- **Blocked article saves showed no reason.** `AdminModel::save()` reports a veto as `$this->setError($table->getError())`, so the message is now set on the event's subject.
- **`onUserBeforeDataValidation` never scanned anything.** It read the payload with `get_object_vars()` on the `Event` object, yielding an empty array and therefore an empty username and e-mail, which `Scanner::checkUserRegistration()` short-circuits to `allow()`. See *Removed* — the event is gone rather than repaired.
- **The installable ZIP shipped without the Spamtroll PHP SDK.** `build/build-package.sh` copied only `plg_system_spamtroll/`, and `vendor/` is gitignored, so a clean install fatally failed in `services/provider.php` at `new JoomlaHttpClient()` — that class implements an SDK interface Joomla's autoloader cannot resolve. Because system plugins are imported during application bootstrap, this took down every request. The build script now installs the SDK into the archive and refuses to emit a package without it; `<folder>vendor</folder>` was added to the manifest's `<files>`.
- Three pre-existing PHPStan errors (`NoteField`, two `Factory::getContainer()` calls) that had the QA workflow red on every PHP version in the matrix.

### Changed

- **Fail-open is now unconditional in the listeners.** A veto is signalled in-band through the event and never by throwing, so an SDK exception (`SpamtrollException extends RuntimeException`) can no longer be mistaken for a block verdict by a future refactor.
- `services/provider.php` registers the bundled SDK autoloader before anything touches `JoomlaHttpClient`. When the SDK is unavailable the plugin is built with a null scanner and its listeners no-op, instead of breaking the site.
- Object payloads are read through `getProperties()` where available; `get_object_vars()` on a `Table` from outside the class only sees its public columns.
- Minimum PHP raised to 8.2 (`composer.json` `require.php` and `config.platform.php`, `<php_minimum>` in the manifest). The QA matrix drops 8.1 and adds 8.4.
- The manifest's `<folder plugin="spamtroll">services</folder>` lost its attribute — `plugin="…"` only ever applied to `<filename>`.

### Removed

- **`onUserBeforeDataValidation` is no longer subscribed.** It is deprecated in Joomla 5 (`libraries/src/MVC/Model/FormModel.php:200-211`, removal in Joomla 6), its `Model\BeforeValidateDataEvent` extends `AbstractImmutableEvent` and is not `ResultAware`, so a listener there has no way to cancel a registration — and scanning it as well doubled the API spend for every registration. `onUserBeforeSave` covers every path, since `RegistrationModel::register()` and `UserModel::save()` both end in `User::save()`.

### Added

- `tests/Integration/PluginDispatchTest` drives the plugin through the real `Joomla\Event\Dispatcher` (`joomla/event` is now a dev dependency) and asserts the veto lands in `$event['result']` for both the Joomla 4 and Joomla 5 event shapes. Nine of its ten cases fail on the previous code with the exact `ArgumentCountError` from `Dispatcher.php:454`. The empty `SubscriberInterface` / `DispatcherInterface` stubs are gone — analysing against them is why the bug was invisible to both PHPStan and the suite; faithful stubs for the CMS event hierarchy (immutable + `ResultAware`) took their place.
- New `package` CI job builds the real artefact and asserts the SDK is inside the archive.
- **Quota-aware fail-open** on HTTP 402 / `QUOTA_EXCEEDED`. When the API returns 402 the scanner records the event in the plugin's `#__extensions.params` (key `quota_skipped_log`) and returns `Decision::allow()` — the user's plan ran out of daily scans, content goes through unscanned instead of being blocked because of a billing condition.
- `Scanner::recordQuotaSkipped()` and `getQuotaSkippedStats($days)` static helpers; rolling 30-day per-day count plus the most recent `usage` block from the API. No DB schema changes, no new tables.
- New `QuotaskippedField` form field renders an inline warning banner on the plugin's options screen with the trailing-7-day skipped count, the most recent usage reading, and an "Upgrade your plan" CTA. Hidden when there's nothing to report. Field type `quotaskipped` is registered via `addfieldprefix` on the `<fields>` element so Joomla autoloads it from `src/Field/`.

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
