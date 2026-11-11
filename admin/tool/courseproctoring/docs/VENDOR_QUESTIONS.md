# Unresolved vendor questions

Status: **OPEN**. Nothing below has been confirmed by the proctoring vendor. This plugin keeps the vendor integration
switched **off by default** (Site administration > Plugins > Admin tools > Course proctoring > "Enable vendor
integration"). Until it is switched on, the plugin neither starts nor closes any proctoring session and makes no request
to the vendor.

All vendor-specific knowledge lives in a few classes so that answers can be applied in one place:

* `classes/local/vendor/techversant_client.php` – URLs, header, request body, widget parameters, and the mapping from
  HTTP result to *success / retryable / permanent*.
* `classes/local/http/http_client.php` – vendor-independent transport (timeouts, TLS, no redirects).
* The session id logic is a single class, `classes/local/session_id.php` (added in a later build phase).

## What is known (from source code, not from vendor documentation)

| Fact | Evidence |
|---|---|
| The widget bundle (`https://dedylcfq70vm0.cloudfront.net/`) sends `x-api-key: <app_key>` **from the learner's browser** to `https://proctoring.api.techversantinfotech.com/proctor-client/*` (create, update, fetch, rules). | Public bundle source, read 2026-11-03. |
| The bundle reads its parameters only from `?data=<base64(JSON)>` on its own `<script src>`. Personal data therefore travels in a URL. | Same bundle. |
| Existing integrations of this vendor close a session with `POST .../proctor-client/session/close`, header `x-api-key: <app_key>`, JSON body `{session_id_or_external, app_key, key_id}`, using the **same** App Key value that the browser receives. | Source code of those integrations. |
| The widget bundle itself never calls `session/close`. | The bundle contains no `session/close` string. |

## Questions for the vendor

### 1. App Key exposure (security)
1. Is `app_key` intended to be a **public, client-side** credential? (The bundle requires it in the browser.)
2. Does the **same** key authorise `POST /proctor-client/session/close`? If yes, any learner who reads the key from the
   page can close their own session (and the proctoring ends early), or another learner's session if they can guess its
   id. This plugin makes session ids random so that they cannot be guessed, but a learner knows their own.
3. Can the vendor issue a **separate server-only credential** for `session/close`, or accept **signed, short-lived
   session parameters** (HMAC of `session_id + key_id + expiry`)?
4. Can the client key be restricted by origin and rate-limited?
5. Can parameters be supplied without putting name, e-mail and user name into a URL (CDN / proxy logs)?

*Current behaviour:* the App Key is stored encrypted, masked in the UI, never logged, never placed in events or task
data; but it **is** sent to the browser, because the current bundle requires it.

### 2. Key ID / App Key pairing
1. Is a Key ID bound to the App Key it was issued under? Course-level and quiz-level proctoring have used different App
   Keys. Entering a Key ID that was issued under a different App Key may fail on the vendor side.
2. Is there a way to validate a Key ID / App Key pair without starting a session?

*Current behaviour:* none; no validation call is made. Format validation only (letters, digits, `_`, `-`).

### 3. The session id
This plugin makes one session per learner per course and names it `course_` followed by 20 random lowercase hexadecimal
characters (27 characters, for example `course_0123456789abcdef0123`).
1. Does the vendor accept that shape: lowercase letters, digits and underscore, 27 characters long?
2. Must session ids be unique per App Key, or across all keys? (The ids here are random, so they are unique either way.)
3. Is the id treated as an "external" id that the vendor maps to its own internal id (the close request names it
   `session_id_or_external`)?

*Current behaviour:* the id is made once, stored, and sent unchanged on every page and in the close request. The shape
is defined in one place and is a one-class change.

### 4. Long sessions and resuming
A course session stays open while a learner studies, which can be days or weeks.
1. Does a session that stays open that long cause any problem (expiry, cost, recording size, idle time-outs)?
2. Does the widget resume the **same** session on every new page, on a later day, in another tab, in another browser or
   on another device?
3. What happens if the same learner has the same session open in two tabs at once?

*Current behaviour:* the plugin sends the same session id every time and assumes the vendor resumes it.

### 5. `session/close` response codes
1. Which status code means success (200, 201, 204)? Is the body meaningful?
2. What is returned for: unknown session, already-closed session, wrong Key ID, bad API key, rate limit?
3. Which codes are safe to retry?

*Current behaviour (conservative, unverified):* 2xx = success; network failure, 408, 425, 429 and 5xx = retry; **every
other status (including 404 and 409) = permanent failure**, recorded in the session ledger and not retried. No status
code is assumed to mean "already closed".

### 6. Idempotency
1. Is closing an already-closed session harmless (idempotent)?
2. Is it safe to send the same close request twice if the first response was lost?
3. Can a closed session id be reused? (This plugin never reuses an id.)

*Current behaviour:* the plugin keeps a ledger row per learner and course and treats a closed row as final: a finished
session is not started again. A lost response can still cause one duplicate close request.

### 7. Sessions left open when a course changes over to this plugin
When a course that was proctored by another set-up starts using this plugin, learners who were mid-course get a **new**
session (new id). Their earlier session is not touched by this plugin.
1. Do open sessions expire by themselves, and after how long?
2. Can the vendor close a set of old sessions in bulk?

### 8. Parameter semantics
1. In the widget parameters, `course_id` is the **real Moodle course id** here. (Integrations that proctor quizzes send
   the quiz id there.) Is that what the vendor expects?
2. What does `redirect_url` do? (The plugin sends the site URL.)
3. Are `session_id` / `user_id` expected as strings or numbers? (The plugin sends strings.)

### 9. Data protection and consent
1. What does the widget collect beyond the parameters (camera, screen, behaviour) and for how long is it kept?
2. Does the widget ask the learner for consent? A camera that stays on while a learner reads a course is more intrusive
   than one that runs during a quiz.
3. How can a learner's data be exported or deleted on request? (Moodle privacy requests only cover Moodle's own data.)

## Switching the integration on

Do **not** enable "Enable vendor integration" on a production site until questions 1 to 6 above have written answers and
`techversant_client.php` has been reviewed against them.
