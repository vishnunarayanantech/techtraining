# Quiz proctoring (`tool_quizproctoring`)

Moodle 4.5 administration tool. A site administrator enters the proctoring **App Key** once for the whole site and a
**Key ID** ("Proctor Key") in the settings of each quiz; a quiz is proctored only if it has a Key ID.

> **Status:** the vendor integration is **off by default** and the vendor's behaviour is not yet verified. Read
> `docs/VENDOR_QUESTIONS.md` before switching it on anywhere.
>
> **Key IDs** are entered in the quiz's own settings form (see "Use"). Only that Key ID is ever used. Key IDs saved per course
> by version 0.1.0 are no longer read.

* Admin page: Site administration > Plugins > Admin tools > Quiz proctoring (`/admin/tool/quizproctoring/index.php`),
  site administrators only (`moodle/site:config`).
* Design, decisions and known gaps: `docs/DESIGN.md`.
* Self-contained: it edits no core file and does not depend on, read or react to any other proctoring set-up or plugin on
  the site. A quiz is proctored when this plugin's own settings say so (see `docs/DESIGN.md`, "Independence").

## Requirements

Moodle 4.5.0 or later (tested on 4.5.3), PHP 8.1+, the `mod_quiz` plugin, and a working `sodium` extension (the App Key is
encrypted with `core\encryption`). Moodle cron is needed only for the retry of failed close requests.

## Install (when approved)

Copy this folder to `admin/tool/quizproctoring`, then run `php admin/cli/upgrade.php` (or visit Site administration >
Notifications) and purge caches. Uninstall from Site administration > Plugins > Plugins overview, then delete the folder;
this drops the plugin's tables and the plugin settings and touches nothing else on the site.

## Use

1. Save the **App Key** (stored encrypted, never shown again, only a masked hint).
2. As a site administrator, edit the quiz: in its settings form, section **Quiz Proctoring Key Setting**, enter the
   **Proctor Key**; leave it empty for no proctoring. Letters, digits,
   `-` and `_` only. It is stored per quiz (table `tool_quizproctoring_quiz`). Teachers do not see the field and a
   teacher's save never changes it. A duplicated or restored quiz does not get the Key ID of the original.
3. Only when the vendor questions are answered: tick **Enable vendor integration** and save.

**Saving an App Key does not start proctoring by itself.** While the switch is off, no quiz is proctored. The message at
the top of the page always says which state the plugin is in (OFF / ON / ON without a usable App Key), and the message
shown right after saving says the same, so a still-off switch is never silent.

Because the plugin is independent, a quiz should have a Key ID here only while nothing else is proctoring it: if
another set-up also starts a widget on the same attempt page, both will try (see `docs/DESIGN.md`, "Running next to
another proctoring set-up").

## Develop and test

The AMD module is ES6 in `amd/src/` and is **built with Moodle's standard Grunt pipeline** into `amd/build/` (do not edit
the build by hand):

```
# in a Moodle 4.5 checkout with the plugin in admin/tool/quizproctoring, Node 22 (see .nvmrc), after `npm ci`
cd admin/tool/quizproctoring && npx grunt amd
```

PHPUnit (Moodle 4.5, PHPUnit 9.6):

```
php admin/tool/phpunit/cli/init.php            # once, installs the plugin into the test site
vendor/bin/phpunit --testsuite tool_quizproctoring_testsuite
```

Coding standard: `phpcs --standard=moodle` (moodlehq/moodle-cs) is clean for the plugin's PHP.
