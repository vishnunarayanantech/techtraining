# Design notes

Status: **build phase 1 of 8** (skeleton, settings page, vendor and HTTP code). Nothing proctors yet: there is no
course form, no gate, no ledger and no widget. These notes grow with each phase.

## What the plugin does (when finished)

It proctors learners while they study a course. When an enrolled learner opens a page of a course that has a Proctor
Key, the vendor's widget starts and stays with the learner through the course. There is **one vendor session per
learner per course**. The session is closed at the vendor when the course is completed, or when the learner is
unenrolled, suspended or deleted.

## Decisions

| # | Decision |
|---|---|
| D1 | **The whole course** is proctored: the course page and every learning page in it (sections, Pages and Books alike). Choosing single sections or chapters is not in version 1. |
| D2 | **One vendor session per learner per course**, kept across visits, tabs and devices. Its id is made by this plugin (see "Session id"). |
| D3 | A session ends when the course is completed, or the learner is unenrolled, suspended or deleted. No timers. In a course without completion tracking it stays open until the learner is unenrolled. |
| D4 | Only enrolled learners are proctored (permission `tool/courseproctoring:beproctored`, given to the Student role by default). Never site administrators, teachers, "log in as", or a switched role. |
| D5 | **Never on a page of a quiz activity**, and there is no setting for it. |
| D6 | The plugin has its own App Key and its own on/off switch, typed on its own admin page. |
| D7 | Component `tool_courseproctoring`, shown as "Course proctoring". Course settings section "Course Proctoring Key Setting", field "Proctor Key". |
| D9 | Moving courses that another set-up proctors: pilot on a new test course first, then one planned window (old widget off, keys typed in, humans test). |
| D10 | Code that is similar to other proctoring work was copied once and renamed. There is no link and no shared code. |
| D11 | The plugin's tables and settings live in the plugin itself. |

## Stand-alone rule

The plugin must work on a Moodle that has no other proctoring plugin, and must not change or depend on one.

* Own tables, made by its own `db/install.xml` (added in a later phase): `tool_courseproctoring_course` and
  `tool_courseproctoring_session`. It reads and writes no other plugin's tables.
* Own code, namespace, JavaScript module, language file, form field names, settings and permission.
* `version.php` declares **no dependency**, not even on the quiz module.
* It recognises a quiz page only from what Moodle says about the page (the activity type), never by loading a quiz class.
* `tests/independence_test.php` enforces this: no file may name another proctoring plugin, `version.php` must list no
  dependency, the code must not read source files, include files of other plugins, use classes of other modules, query
  tables of other plugins, or use another plugin's settings or strings. Checks for the registrations in `db/` (hooks,
  events, tasks, permissions) are added with those files.

## Minimum Moodle version

Moodle 4.5.0 (`requires` 2024100700). The course-form hooks (`after_form_definition`, `after_form_definition_after_data`,
`after_form_validation`, `after_form_submission`, `after_course_created`, `after_course_updated`, `before_course_deleted`)
and the footer hook (`before_footer_html_generation`) all exist from **Moodle 4.4.0**; this was checked against the
Moodle 4.3.0, 4.4.0, 4.5.0 and 4.5.3 sources on 2026-11-10 (they are absent in 4.3.0). The plugin is tested on 4.5.3
only, so it claims 4.5.

## Session id

The vendor sees one session per learner per course, and the session id is how it knows which one.

* Format: `course_` followed by 20 random lowercase hexadecimal characters (80 random bits from PHP's secure random
  generator). 27 characters, for example `course_0123456789abcdef0123`.
* Made **once**, in the same step that creates the learner's ledger row, and stored in the ledger. Every later visit,
  tab or device reads it from there. Starting and closing both use the stored id.
* Two pages that start at the same moment end with one row and one id (the database's unique key on learner + course
  decides).
* Random, because the widget address that reaches the browser contains the id and the vendor's design also sends the App
  Key to the browser: an id that can be calculated from a user id and a course id could be guessed. A calculated id also
  repeats after a reinstall, when the vendor may refuse to reuse the id of a closed session.
* One class (`classes/local/session_id.php`, added in a later phase) holds the format and a validity check.

## Phases

| Phase | Content | State |
|---|---|---|
| M0 | Decisions, Moodle version check | done (above); vendor answers still open, see `VENDOR_QUESTIONS.md` |
| M1 | Skeleton, independence test, settings (App Key, switch), admin page, vendor and HTTP code | done |
| M2 | Course key table, Proctor Key on the course settings page | next |
| M3 | Gate, ledger with the session id, widget start, widget JavaScript | |
| M4 | Closing: course completed, unenrolment, user deleted, retry task | |
| M5 | Privacy provider, events, docs, manual test sheet | |
| M6 | Rehearsals on throwaway Moodles (clean one; one with other proctoring plugins) | |
| M7 | Deployment kit, human test sheet | |
