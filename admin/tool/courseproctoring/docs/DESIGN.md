# Design notes

Status: **version 0.9.0 (beta).** Everything planned is built and tested locally (see "Tests"). What has **not** been done
is a test by a human with the real vendor widget: proctoring must be tested by hand, using `MANUAL_TEST_SHEET.md`.

## What the plugin does

It proctors learners while they study a course. When an enrolled learner opens a page of a course that has a Proctor Key,
the vendor's widget starts and stays with the learner through the course. There is **one vendor session per learner per
course**. The session is closed at the vendor when the learner completes the course, or is un-enrolled, suspended or
deleted.

## Decisions

| # | Decision |
|---|---|
| D1 | **The whole course** is proctored: the course page and every learning page in it (sections, Pages and Books alike). Choosing single sections or chapters is not in version 1. |
| D2 | **One vendor session per learner per course**, kept across visits, tabs and devices. Its id is made by this plugin (see "The session id"). |
| D3 | A session ends when the course is completed, or the learner is un-enrolled, suspended or deleted. No timers. In a course without completion tracking it stays open until the learner is un-enrolled. |
| D4 | Only enrolled learners are proctored (permission `tool/courseproctoring:beproctored`, given to the Student role by default). Never site administrators, teachers, "log in as", or a switched role. |
| D5 | **Never on a page of a quiz activity**, and there is no setting for it. |
| D6 | The plugin has its own App Key and its own on/off switch, typed on its own admin page. |
| D7 | Component `tool_courseproctoring`, shown as "Course proctoring". Course settings section "Course Proctoring Key Setting", field "Proctor Key". |
| D9 | Moving courses that another set-up proctors: pilot on a new test course first, then one planned window (old widget off, keys typed in, humans test). |
| D10 | Code that is similar to other proctoring work was copied once and renamed. There is no link and no shared code. |
| D11 | The plugin's tables and settings live in the plugin itself. |

## Stand-alone rule

The plugin must work on a Moodle that has no other proctoring plugin, and must not change or depend on one.

* Own tables, made by its own `db/install.xml` (and, for a site that already has an older version, by `db/upgrade.php`):
  `tool_courseproctoring_course` and `tool_courseproctoring_session`. It reads and writes no other plugin's tables.
* Own code, namespace, JavaScript module, language file, form field names, settings and permission.
* `version.php` declares **no dependency**, not even on the quiz module.
* It recognises a quiz page only from what Moodle says about the page (the activity type), never by loading a quiz class.
* `tests/independence_test.php` enforces this: no file may name another proctoring plugin, `version.php` must list no
  dependency, the code must not read source files, include files of other plugins, use classes of other modules, query
  tables of other plugins, or use another plugin's settings or strings. Everything registered in `db/` (hooks, event
  observers, permissions, tasks) must be this plugin's own and may listen to Moodle core only.

## Minimum Moodle version

Moodle 4.5.0 (`requires` 2024100700). The course-form hooks (`after_form_definition`, `after_form_validation`,
`after_form_submission`), the course-deletion hook and the footer hook (`before_footer_html_generation`) all exist from
**Moodle 4.4.0**; this was checked against the Moodle 4.3.0, 4.4.0, 4.5.0 and 4.5.3 sources (they are absent in 4.3.0). The
plugin is tested on 4.5.3 only, so it claims 4.5.

## Proctor Key on the course settings page

Site administrators (`moodle/site:config`) get a section "Course Proctoring Key Setting" with a field "Proctor Key" on the
course settings page. Nobody else sees the section, the field or the saved key.

* Three Moodle hooks are used (`db/hooks.php`): `after_form_definition` adds the section and shows the saved key,
  `after_form_validation` refuses a malformed key with an error on the field, `after_form_submission` saves it.
* A **new course** is saved by `after_form_submission` too: Moodle sets the new course id before it dispatches that hook
  (checked in the Moodle 4.4.0, 4.5.0 and 4.5.3 sources), so no other hook is needed.
* **A save only changes a key when the submitted data really carried the field.** A course saved by a teacher, a web
  service or an import never touches the key. An empty value, typed by an administrator, removes the key; the course is
  then no longer proctored.
* A key may contain letters, numbers, hyphens and underscores (up to 255). Anything else is refused, not cleaned.
* The key is stored in `tool_courseproctoring_course` (one row per course). The site home page never gets one.
* Each change is logged as the event `course_key_updated` (created, updated, deleted); the key itself is never logged.
* A copy made by "duplicate" or "restore" does not get the key.

## How a page is decided (the gate)

`course_gate` is pure decision logic. It runs from the footer hook on every page, so the checks run cheapest first and stop
at the first "no". The first four need no database read.

| # | Check | If not |
|---|---|---|
| 1 | The integration switch is on | `vendor_disabled` |
| 2 | The page is in a course (not the site home) and has the layout `course` or `incourse` | `not_a_course_page` |
| 3 | It is not a page of a quiz activity (the activity type is `quiz`, or the page type starts `mod-quiz-`) | `quiz_page` |
| 4 | A real person is logged in (not a visitor, not a guest) | `not_logged_in` |
| 5 | ...who is not "logged in as" somebody else | `logged_in_as` |
| 6 | ...who has not switched role in this course | `role_switched` |
| 7 | ...who is not a site administrator | `site_admin` |
| 8 | The course has a Proctor Key (one indexed read) | `no_course_key` |
| 9 | An App Key is saved and can be decrypted (decrypting is the costliest step, so it comes after the key lookup) | `no_app_key` |
| 10 | The person is actively enrolled and holds `tool/courseproctoring:beproctored` | `not_a_learner` |
| 11 | The ledger has no finished (closed or failed) session for them | `session_already_ended` |
| 12 | They have not completed the course | `course_complete` |
| 13 | The stored session id is one this plugin made | `session_id_invalid` |

If all pass the page is proctored: a **new** session (`proctored`) with the course's Key ID, or the learner's **running**
session (`continuing_session`) with the Key ID it started with, even if the course's Key ID has changed since.

`widget_injector` then records the session (before anything else, so no widget is shown that could never be closed), builds
the vendor address and queues the JavaScript module `tool_courseproctoring/proctor_widget`. The footer callback never
breaks a page: any failure is swallowed and shown only in developer debugging.

## The session and the session id

The vendor sees one session per learner per course, and the session id is how it knows which one.

* Format: `course_` followed by 20 random lowercase hexadecimal characters (80 random bits from PHP's secure random
  generator). 27 characters, for example `course_0123456789abcdef0123`. The format and its check live in
  `classes/local/session_id.php`.
* Made **once**, in the same step that creates the learner's ledger row, and stored in the ledger. Every later visit,
  tab or device reads it from there. Starting and closing both use the stored id.
* Two pages that start at the same moment end with one row and one id (the database's unique key on learner + course
  decides). If the database ever reports that a drawn id is already in use, a new one is drawn.
* Random, because the widget address that reaches the browser contains the id and the vendor's design also sends the App
  Key to the browser: an id that can be calculated from a user id and a course id could be guessed. A calculated id also
  repeats after a reinstall, when the vendor may refuse to reuse the id of a closed session.
* The widget address and the close request are only ever built from ids that pass the check.

## Ending a session

| What happens | What the plugin does |
|---|---|
| The learner **completes the course** | Closes their session (one try with the vendor's bounded time-outs, 3 s to connect and 6 s in all). A failure that may pass queues a retry. From then on the widget no longer appears, even while a close is waiting for a retry. |
| The learner is **un-enrolled** or **suspended** | Closes their session, unless they are still actively enrolled through another enrolment method. |
| The learner's enrolment **ends by date** | Nothing is announced by Moodle. The widget stops (the enrolment check fails) but the vendor session stays open. |
| The **user is deleted** | Closes their sessions (one try), then removes every row of theirs from the ledger. If the vendor cannot be reached at that moment, that session stays open at the vendor. |
| The **course is deleted** | Removes the course's Proctor Key and its ledger rows. The vendor is **not** called: deleting a course un-enrols everybody in it, and waiting for the vendor once per learner would slow the deletion down. Those vendor sessions stay open. |
| The **Proctor Key is removed** from a course | The widget stops appearing on new page views. Sessions already running are not closed by this. |
| The **switch is turned off** | The widget stops. Sessions that were started are still closed when their learner completes or leaves the course. |

Closing a session (`close_service`): serialised with a lock, only for a ledger row that is still active; the outcome is
written to the ledger (`closed`, `failed`, or still `active` with the number of failed tries and a short note without keys).
**Success** = the vendor answers 2xx. **Permanent failure** = any other answer except those listed next (no retry).
**Retry** = no answer, time-out, 408, 425, 429 or 5xx. A retry is an adhoc task (`close_session`) that Moodle repeats with
growing delays (12 tries by default); on the last try the failure is recorded as final. **Retries need Moodle cron.** The
first try, made at the moment of completion, does not.

## Data

| Table | Holds |
|---|---|
| `tool_courseproctoring_course` | `courseid` (unique), `keyid`, two times. The only place a course's Key ID is read from. |
| `tool_courseproctoring_session` | The ledger: `userid` + `courseid` (unique), `keyid`, `sessionid` (unique), `status` (0 active, 1 closed, 2 failed), `closeattempts`, `lasterror`, three times. |

Settings (under `tool_courseproctoring`): `appkey` (encrypted with Moodle's own encryption) and `vendorenabled`.

## Permission

`tool/courseproctoring:beproctored`, course context, `read`, allowed for the **Student** role by default. A site whose
learners have another role allows it for that role. Teachers, managers and guests do not have it.

## Privacy

`classes/privacy/provider.php` declares the ledger and the external service. The export includes the session id, so that
the vendor can be asked about it. Deleting a user's data, a course context, or a list of users removes the matching ledger
rows. The table of Proctor Keys holds no personal data. Moodle cannot reach what the vendor's widget collects.

## What is registered with Moodle

* Hooks (`db/hooks.php`): `after_form_definition`, `after_form_validation`, `after_form_submission` (course settings form),
  `before_footer_html_generation` (the widget), `before_course_deleted` (notes the course so its un-enrolments do not
  close sessions one by one).
* Event observers (`db/events.php`): `course_completed`, `user_enrolment_deleted`, `user_enrolment_updated`, `user_deleted`
  (these four run after the database transaction has committed) and `course_deleted`.
* Permission (`db/access.php`), one adhoc task (`classes/task/close_session.php`).

## Known limits of version 1

* One session per learner per course, for ever: a learner whose session was closed (completed, or un-enrolled and enrolled
  again) is not proctored again in that course.
* Quiz pages are never proctored by this plugin, and there is no setting to change that.
* No page that lists sessions. The ledger can be read in the database (see `MANUAL_TEST_SHEET.md`).
* Vendor sessions can be left open at the vendor when a course or user is deleted, or an enrolment ends by date.
* The vendor questions in `VENDOR_QUESTIONS.md` are still open.

## Tests

PHPUnit (`tests/`), checked with `phpcs --standard=moodle`. They use a fake vendor and never contact the real service.
Besides unit tests they cover: the real course settings form; the footer hook through Moodle's own dispatcher; the
observers driven by real Moodle actions (completing a course, un-enrolling, suspending, deleting a user or a course);
a fresh install and every upgrade path (against `install.xml`); the privacy provider; and the stand-alone rule.
