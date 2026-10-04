# Joomla Extensions Directory publication

Checked 2026-10-04. Repository distribution and JED acceptance are separate.
No JED entry or publisher dashboard submission has been verified. Public search
did not establish a listing and cannot establish that none exists.

The extension was already implemented and audited at `e98cc0b`. Version 0.1.1
includes those fixes and supplies the missing publication prerequisites: the
installed GPL license and Joomla update server. Real installation testing also
found and repaired a missing plugin identity attribute in the audited manifest.

## Package and update distribution

- Source: https://github.com/spamtroll/spamtroll-joomla
- Release page: https://github.com/spamtroll/spamtroll-joomla/releases/tag/v0.1.1
- Installable asset: `plg_system_spamtroll-0.1.1.zip`; do not install GitHub's source ZIP.
- Checksum asset: `plg_system_spamtroll-0.1.1.zip.sha256`.
- Manifest update server: https://github.com/spamtroll/spamtroll-joomla/releases/latest/download/updates.xml
- Feed asset: `updates.xml`; each entry points at the version-specific release ZIP
  and its SHA-256, identifies the system plugin, and requires PHP 8.2.

The build resolves SDK v0.9.3 under the existing `^0.9` constraint. Its MIT license
and Composer's license remain bundled alongside the extension's GPL-2.0-or-later
license. The archive is checked against every source file in its runtime,
language and SQL directories, and the feed against its exact archive hash.

Build with `bash build/build-package.sh`, verify with
`python3 build/verify-package.py`, run `composer qa`, then attach all three
release assets. Retain previous releases so installed versions' download URLs
stay usable. Publish a matching feed whenever a new version becomes latest.

## Prepared listing

| Field | Value |
| --- | --- |
| Name | System - Spamtroll (installed English name for `plg_system_spamtroll`) |
| Developer | Spamtroll; the authenticated submitter must have authority to represent it |
| Type | System plugin |
| Suggested category | Access & Security / Site Security; confirm in the current form |
| Version | 0.1.1 |
| License | GPL-2.0-or-later |
| Download page | https://github.com/spamtroll/spamtroll-joomla/releases/tag/v0.1.1 |
| Documentation | https://github.com/spamtroll/spamtroll-joomla/blob/main/README.md |
| Support | https://github.com/spamtroll/spamtroll-joomla/issues |
| Product site | https://spamtroll.io |
| Service requirement | Spamtroll account and platform API key; service plan limits apply |

Suggested description:

> System - Spamtroll connects Joomla registration and content-save events to the
> Spamtroll spam detection API. It checks new user registrations and content
> submitted through `onContentBeforeSave`, including article creation and edits.
> Administrators configure normalized score thresholds, API timeout, event
> toggles and local log retention. High scores can reject a save; warning mode
> allows saving and shows a notice. Warning mode does not create a moderation
> queue, and contact form messages require their own event integration.
>
> API failures, missing credentials and exhausted service quotas allow the save
> to proceed. The plugin sends scanned content and available registration
> metadata to the configured API. Its local audit table stores a content hash,
> verdict, score, detection symbols and available IP, email and username; it
> does not store the scanned raw content. A Spamtroll account and platform API
> key are required. The extension source is GPL-2.0-or-later; service access has
> separate terms and plan limits.

## Submission gates

- Inspect the existing publisher dashboard first to avoid a duplicate entry.
- Use an authorized logged-in account and obtain an explicit instruction before
  submitting this listing. No account credentials should be placed in the repository.
- Disposable real Joomla 4.4.13 and 5.4.9 sites on PHP 8.2.34/MariaDB 13.0.2
  verified installation, installed identity/license/SDK, real provider boot,
  missing-key registration dispatch, and enabled/disabled database state. The
  CMS verifier is `build/verify-cms.php` and refuses a non-fixture configuration.
  Joomla 5.4.9 also passed reinstall/upgrade and uninstall checks. Capture real
  administrator screenshots for the form; do not claim Joomla 6 compatibility.
- Confirm the service's enduring free-tier policy before choosing JED's Free
  category. A free GPL ZIP alone does not determine the SaaS listing category.
- Review the current form, attach the installable ZIP and real screenshots, then
  retain submission ID, reviewer outcome and accepted listing URL.

Official sources: [submission checklist](https://extensions.joomla.org/support/knowledgebase/submission-requirements/jed-entries-checklists/)
requires configured update servers and distinguishes permanently free SaaS tiers
from trials/paid services. [JED terms](https://extensions.joomla.org/community/terms-of-service/)
cover GPL licensing, truthful names and direct download pages.
[Submission instructions](https://extensions.joomla.org/support/knowledgebase/for-jed-developers/submitting-an-extension/)
describe the logged-in profile/form route.
[Joomla update server documentation](https://manual.joomla.org/docs/building-extensions/install-update/update-server/)
defines identity, platform, download and checksum fields used by the generated feed.
These checks do not imply endorsement or directory acceptance.
