# Course proctoring (`tool_courseproctoring`)

A Moodle admin tool that proctors learners while they study a course, using the Techversant proctoring service.

**Version 0.9.0 (beta).** Everything is built and tested locally with a fake vendor. It has **not** yet been tested by a
person with the real proctoring widget: do that with `docs/MANUAL_TEST_SHEET.md` before relying on it.

## How it works

1. An administrator saves the **App Key** and ticks **Enable vendor integration** on the plugin's admin page.
2. An administrator types a **Proctor Key** in the settings of each course that should be proctored.
3. When an enrolled learner opens any page of such a course (never a quiz page), the vendor's proctoring widget starts. The
   learner has **one session for the whole course**, whatever they open and however often they come back.
4. The session is closed at the vendor when the learner completes the course, or is un-enrolled or suspended, or is deleted.

Administrators, teachers, visitors, guests, people "logged in as" a learner and people who switched role are never proctored.

## Requirements

* Moodle 4.5 or newer.
* PHP with the sodium extension (Moodle uses it to encrypt the App Key).
* A web server that can reach the vendor's addresses (the learner's browser and the Moodle server both talk to them).
* From the vendor: an App Key, and one Key ID for each course that should be proctored.
* Nothing else. The plugin is **stand-alone**: it needs no other plugin, makes its own database tables, and has its own
  settings. It works on a Moodle that has no other proctoring plugin, and it does not touch one that is there.

## Install

1. Site administration > Plugins > Install plugins (upload the zip), or copy this folder to `admin/tool/courseproctoring`
   in the Moodle code. Then run the Moodle upgrade (the page that opens, or `php admin/cli/upgrade.php`).
   The upgrade makes the plugin's two tables and its permission.
2. The plugin starts **switched off** and with no App Key. Nothing happens until you do the next steps.

A site that already has an older version of the plugin is brought up to date by the same upgrade, keeping its settings and
its Proctor Keys.

## Set up

1. Site administration > Plugins > Admin tools > **Course proctoring** (site administrators only):
   * **App Key** – stored encrypted, never shown again after saving.
   * **Enable vendor integration** – off by default. While it is off, the plugin starts nothing and makes no request to
     the vendor. Read `docs/VENDOR_QUESTIONS.md` before switching it on.
2. A course > Settings > section **Course Proctoring Key Setting** > **Proctor Key** (site administrators only; teachers do
   not see it). Letters, numbers, hyphens and underscores. Leave it empty for no proctoring. The same section appears when a
   new course is created. A course that is copied (duplicate or restore) does not get the key.
3. **Who is proctored:** people who are actively enrolled in the course and hold the permission
   `tool/courseproctoring:beproctored`. The Student role has it by default. If your learners have another role, allow the
   permission for that role (Site administration > Users > Permissions > Define roles).
4. **Cron:** starting a session and closing it when a course is completed work without Moodle cron. Retrying a close that
   failed (for example when the vendor was unreachable) needs cron.

## Uninstall

Site administration > Plugins > Plugins overview > Course proctoring > Uninstall. Moodle removes the plugin's tables,
settings and permission. Sessions already open at the vendor are not closed by this.

## Tests

From the Moodle code directory, after `php admin/tool/phpunit/cli/init.php`:

    vendor/bin/phpunit --testsuite tool_courseproctoring_testsuite

Coding style: `phpcs --standard=moodle`. The tests never contact the real vendor.

## Documents

| File | What |
|---|---|
| `docs/DESIGN.md` | The decisions, the rules that decide a page, how sessions start and end, the data, the limits |
| `docs/MANUAL_TEST_SHEET.md` | What a person must check by hand, with the real widget |
| `docs/VENDOR_QUESTIONS.md` | What is still unconfirmed with the vendor |

## Files

| Path | What |
|---|---|
| `settings.php`, `index.php` | The admin page |
| `classes/local/config.php`, `settings_manager.php`, `classes/form/settings_form.php` | Settings: the encrypted App Key and the switch |
| `classes/output/status_notice.php` | The plain-words state message |
| `classes/hook_callbacks.php` | The Moodle hooks: course settings form, footer (widget), course deletion |
| `classes/local/course_key_form.php`, `course_key_repository.php` | The Proctor Key field, and where the key is stored |
| `classes/local/course_gate.php`, `course_facts.php`, `decision.php` | The rules that decide whether a page is proctored |
| `classes/local/widget_injector.php`, `session_repository.php`, `session_id.php` | Starting a session: the ledger and the session id |
| `classes/local/close_service.php`, `classes/task/close_session.php`, `classes/observer.php` | Ending a session |
| `classes/local/http/`, `classes/local/vendor/` | Vendor-independent HTTP transport, and everything vendor-specific |
| `classes/event/` | Audit events (never contain a key) |
| `classes/privacy/provider.php` | Privacy API |
| `amd/src/proctor_widget.js`, `amd/build/` | The JavaScript that starts the widget (built with Moodle's grunt) |
| `db/` | `install.xml`, `upgrade.php`, `access.php`, `hooks.php`, `events.php` |
| `tests/` | PHPUnit tests, including `independence_test.php`, which enforces the stand-alone rule |
