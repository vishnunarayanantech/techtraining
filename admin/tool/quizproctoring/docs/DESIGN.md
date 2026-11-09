# Design notes: tool_quizproctoring

Status: the local copy is the source of truth for the plugin. The vendor integration is switched **off by default** and
the vendor's behaviour is unverified (see `VENDOR_QUESTIONS.md`).

## What it does

* A site administrator saves **one App Key** for the whole site. A **Key ID** ("Proctor Key") is entered in the settings
  form of each quiz (section "Proctor Key Settings", quizzes only, site administrators only).
* A quiz attempt is proctored if, and only if, all of these hold: the integration switch is on, a usable App Key is saved,
  the quiz has a Key ID, the attempt belongs to the logged-in user, it is not a teacher preview, and it is in progress.
  **A quiz with no Key ID is never proctored.** Nothing else is consulted (see "Independence" below).
* The session id sent to the vendor is the quiz attempt id.
* The administration page holds the site-wide settings only (the App Key and the integration switch).
* **Where Key IDs come from.** Only from the "Proctor Key" field of the quiz settings form (`lib.php` callbacks
  `coursemodule_standard_elements`, `coursemodule_validation`, `coursemodule_edit_post_actions`, implemented in
  `local\quiz_key_form`), stored in `tool_quizproctoring_quiz` (one row per quiz course module). Version 0.1.0 had a
  per-course table on the administration page (removed 2026-11-09); its table `tool_quizproctoring_course` is **left in
  the database untouched but nothing reads or writes it** (a test proves it is ignored). The field is shown and saved only
  for site administrators (`moodle/site:config`); a submission without the field never changes a Key ID. Quiz and course
  deletion remove the Key IDs (observers). Not covered: duplicating or restoring a quiz does not copy its Key ID.

## Flow

```
Student opens mod/quiz/attempt.php?attempt=N          (every question page, every reload)
  core_renderer::footer()  --dispatches-->  before_footer_html_generation
    hook_callbacks::before_footer_html_generation()   [pagetype mod-quiz-attempt only, logged in, not guest]
      widget_injector::inject()
        attempt_facts::from_database()                one query
        attempt_gate::evaluate()                      all the rules above
        session_repository::ensure_started()         idempotent ledger row; remembers the Key ID
        vendor_client::get_widget_script_url()        vendor knowledge lives here
        $PAGE->requires->js_call_amd('tool_quizproctoring/proctor_widget', 'init', [url])

Student submits / attempt is abandoned (also: cron auto-submit, teacher submit, web service)
  mod_quiz fires attempt_submitted / attempt_abandoned INSIDE its transaction
  observer::attempt_ended()  runs AFTER the commit ('internal' => false)
    close_service::close_attempt()                    only for attempts with an ACTIVE ledger row; per-attempt lock
      vendor_client::close_session()                  bounded HTTP (3 s connect, 6 s total), TLS verified, no redirects
        success            -> ledger CLOSED
        permanent failure  -> ledger FAILED (not retried)
        retryable failure  -> ledger stays ACTIVE (+1 attempt, short error text) and an adhoc task is queued
  task\close_session      retries with Moodle's back-off (60 s doubling, 12 tries); on the last try the ledger is FAILED
```

## Decisions and why

| Decision | Reason (verified in Moodle 4.5.3 source unless noted) |
|---|---|
| Hook `before_footer_html_generation`, not `before_standard_footer_html_generation` | The standard-footer hook is only dispatched by theme templates that render `standard_footer_html()`. Boost's `secure` layout (secure-window and Safe Exam Browser quizzes) does not, so that hook would silently skip those quizzes. `core_renderer::footer()` dispatches the other one for every layout, before the page JavaScript is finalised. |
| Observers with `'internal' => false` | `quiz_attempt::process_finish()` / `process_abandon()` fire the events inside a database transaction. Internal observers would run before the commit. |
| Session ledger table | Only sessions this plugin started are closed (mod_quiz fires its events for every attempt, including ones for which no session was started: no Key ID, a preview, the switch off); the Key ID a session started with is used to close it even if the quiz's Key ID changes or is removed; closing is idempotent. A session in progress keeps the Key ID it started with while the quiz still has one; once the quiz's Key ID is removed the quiz is no longer proctored. |
| No "proctor previews" option | `process_finish()` raises no `attempt_submitted` for previews, so such a session could never be closed. |
| Synchronous close first, adhoc task only on failure | Cron is not running on the demo server (last task_log entry 2026-05-28), so a task-only design would never close anything there. The synchronous try is bounded to 6 s. |
| Explicit HTTP options | Moodle's `\curl` sets a 30 s connect timeout, **no total timeout** and `CURLOPT_SSL_VERIFYPEER = 0` (`lib/filelib.php`). |
| Widget URL built in PHP, percent-encoded | A browser `btoa(JSON.stringify())` throws for characters above U+00FF; the bundle reads the value with `URLSearchParams`, which turns a literal `+` into a space. |
| App Key encrypted at rest (`core\encryption`) | It is a credential. Note it still reaches the learner's browser (vendor design, see VENDOR_QUESTIONS.md). |

## Independence from any other proctoring set-up

This plugin is self-contained. Whether an attempt is proctored depends only on **this plugin's own settings** (switch,
App Key, the quiz's Key ID, its own session ledger) and on **the attempt itself**. It does not read, wait for or react to

* another plugin (such as the older `local_techstack`), its tables, its hooks or whether it is installed; or
* edits made to core files such as `mod/quiz/attempt.php` or `mod/quiz/processattempt.php`, or whether they are active
  or commented out.

An earlier version "stepped aside" whenever a key from the older set-up was found in the database. That made the plugin
depend on code it does not own: when the older code was switched off but its keys stayed in the database, nobody
proctored the quiz. The step-aside rule was therefore removed completely. Two tests keep it that way:
`tests/independence_test.php` (the plugin's code never mentions the older plugin and never reads site source files) and
`test_other_proctoring_setups_have_no_effect` in `tests/hook_callbacks_test.php` (tables shaped like the older
plugin's, holding keys for the very same course and quiz, change nothing).

### Running next to another proctoring set-up

If something else still starts a proctoring widget on the same attempt page (for example the older course-level hook, for
a quiz that **also** has a Key ID here), both will try. The browser-side module refuses to mount a second widget when
`#proctor-widget-root` already exists, which protects the page in the usual order, but that is a best effort: the order in
which two asynchronous modules load is not fixed. So give a Key ID here only to quizzes that are no longer
proctored by the other set-up. This is a configuration choice, not something the plugin checks.

### Known gaps (covered by tests)

| Situation | Result |
|---|---|
| A teacher **re-opens** an attempt this plugin already closed or failed | Not proctored again: the vendor's behaviour for a re-used session id is unknown (`attempt_gate::SESSION_ENDED`). |
| A **teacher preview** attempt | Never proctored: `mod_quiz` raises no `attempt_submitted` for previews, so such a session could never be closed. |

## Administration page messages

The page states the integration switch's state at the top, and again right after every save, in plain words and by colour
(OFF / ON / ON without a usable App Key), because the plugin deliberately does nothing while the switch is off.

A saved App Key that cannot be decrypted (for example the site's encryption key was replaced) counts as **no key**: the
gate does not proctor and the page says "ON without a usable App Key".

## Operations

* **Cron.** The synchronous close needs no cron. The retry task does. If cron is not running, a failed close stays
  `ACTIVE` in the ledger (`tool_quizproctoring_session.lasterror` says why) until cron runs.
* **Worst case added to a student's "Submit all and finish":** one vendor call of at most 6 s (3 s to connect), plus up
  to 10 s waiting for the per-attempt lock if another process is closing the same attempt (rare). A vendor outage never
  fails the submission.
* **Retry schedule** (Moodle default for adhoc tasks): 60 s, 120 s, 240 s ... capped at 24 h, 12 attempts, about 2.4 days.
* **Logging.** Events record that a Key ID or the settings changed, never their values. The ledger stores short error
  texts such as `HTTP 503`, never keys or response bodies.
* **JavaScript cache.** After deploying, purge caches so `amd/build/proctor_widget.min.js` is picked up.

## Not done / out of scope

* Anything that depends on unanswered vendor questions (see `VENDOR_QUESTIONS.md`).
* Migrating, editing or removing any other proctoring set-up: this plugin neither needs nor touches it.
* Behat tests; the mobile app (the widget is a web page script, the close events would still fire).
* A privacy export of data held by the vendor.
