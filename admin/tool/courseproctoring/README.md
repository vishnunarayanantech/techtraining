# Course proctoring (`tool_courseproctoring`)

A Moodle admin tool that proctors learners while they study a course, using the Techversant proctoring service.

**Status: version 0.1.0, build phase 1 of 8. Not usable yet.** This version has the settings page (App Key and on/off
switch) and the code that talks to the vendor. It does not proctor anything: there is no course setting, no gate and no
widget yet. See `docs/DESIGN.md` for what comes next.

## Requirements

* Moodle 4.5 or newer.
* PHP with the sodium extension (Moodle uses it to encrypt the App Key).
* From the vendor: an App Key, and one Key ID for each course that should be proctored.
* Nothing else. The plugin is **stand-alone**: it needs no other plugin, makes its own database tables, and has its own
  settings.

## Install

Site administration > Plugins > Install plugins (upload the zip), or copy this folder to `admin/tool/courseproctoring`
in the Moodle code, then run the Moodle upgrade.

## Settings

Site administration > Plugins > Admin tools > Course proctoring (site administrators only):

* **App Key** – stored encrypted, never shown again after saving.
* **Enable vendor integration** – off by default. While it is off, the plugin starts and closes nothing and makes no
  request to the vendor.

Read `docs/VENDOR_QUESTIONS.md` before switching the integration on.

## Tests

From the Moodle code directory, after `php admin/tool/phpunit/cli/init.php`:

    vendor/bin/phpunit --testsuite tool_courseproctoring_testsuite

Coding style: `phpcs --standard=moodle`.

## Files

| Path | What |
|---|---|
| `settings.php`, `index.php` | The admin page |
| `classes/local/config.php`, `settings_manager.php`, `classes/form/settings_form.php` | Settings: the encrypted App Key and the switch |
| `classes/output/status_notice.php` | The plain-words state message |
| `classes/event/settings_updated.php` | Audit event (never contains the App Key) |
| `classes/local/http/` | Vendor-independent HTTP transport (bounded time, TLS checked, no redirects) |
| `classes/local/vendor/` | Everything vendor-specific, behind one interface |
| `tests/independence_test.php` | Enforces the stand-alone rule |
