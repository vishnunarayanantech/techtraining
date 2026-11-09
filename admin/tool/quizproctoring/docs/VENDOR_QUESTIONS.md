# Unresolved vendor questions

Status: **OPEN**. Nothing below has been confirmed by the proctoring vendor. This plugin keeps
the vendor integration switched **off by default** (Site administration > Plugins > Admin tools >
Quiz proctoring > "Enable vendor integration"). Until it is switched on, the plugin neither starts
nor closes any proctoring session and makes no request to the vendor.

All vendor-specific knowledge lives in two classes so that answers can be applied in one place:

* `classes/local/vendor/techversant_client.php` – URLs, header, request body, widget parameters,
  and the mapping from HTTP result to *success / retryable / permanent*.
* `classes/local/http/http_client.php` – vendor-independent transport (timeouts, TLS, no redirects).

## What is known (from source code, not from vendor documentation)

| Fact | Evidence |
|---|---|
| The widget bundle (`https://dedylcfq70vm0.cloudfront.net/`) sends `x-api-key: <app_key>` **from the student's browser** to `https://proctoring.api.techversantinfotech.com/proctor-client/*` (redis create/update/fetch, rules). | Public bundle source, 2026-11-03. |
| The bundle reads its parameters only from `?data=<base64(JSON)>` on its own `<script src>` and then overwrites `window.__PROCTOR_PARAMS__`. Personal data therefore travels in a URL. | Same bundle (`_getScriptParams`). |
| The earlier integrations with this vendor (a previous Moodle integration, the WordPress plugin) call `POST .../proctor-client/session/close` with header `x-api-key: <app_key>` and JSON body `{session_id_or_external, app_key, key_id}`, using the **same** App Key value that the browser receives. | `mod/quiz/processattempt.php`, `proctor-widget-integration.php`. |
| The widget bundle itself never calls `session/close`. | Bundle contains no `session/close` string. |
| Early versions of the contract used a numeric `organization_id` and no API-key header. | `attempt.php.bak.20260728_*`, `proctor_end_session.js.bak.*`. |

## Questions for the vendor

### 1. App Key exposure (security)
1. Is `app_key` intended to be a **public, client-side** credential? (The bundle requires it in the browser.)
2. Does the **same** key authorise `POST /proctor-client/session/close`? If yes, any student can close
   their own or another student's session (attempt ids are sequential).
3. Can the vendor issue a **separate server-only credential** for `session/close`, or accept
   **signed, short-lived session parameters** (HMAC of `session_id + key_id + expiry`)?
4. Can the client key be restricted by origin and rate-limited?
5. Can parameters be supplied without putting name, e-mail and user name into a URL (CDN / proxy logs)?

*Current behaviour:* the App Key is stored encrypted, masked in the UI, never logged, never placed in
events or task data; but it **is** sent to the browser, because the current bundle requires it.

### 2. Key ID / App Key pairing
1. Is a Key ID bound to the App Key it was issued under? The previous Moodle integration used one App
   Key for course-level proctoring and another for quiz-level proctoring. Entering a Key ID that was issued under a different
   App Key may fail on the vendor side.
2. Is there a way to validate a Key ID / App Key pair without starting a session?

*Current behaviour:* none; no validation call is made. Format validation only (letters, digits, `_`, `-`).

### 3. `session/close` response codes
1. Which status code means success (200, 201, 204)? Is the body meaningful?
2. What is returned for: unknown session, already-closed session, wrong Key ID, bad API key, rate limit?
3. Which codes are safe to retry?

*Current behaviour (conservative, unverified):* 2xx = success; network failure, 408, 425, 429 and 5xx =
retry; **every other status (including 404 and 409) = permanent failure**, recorded in the session
ledger and not retried. No status code is assumed to mean "already closed".

### 4. Idempotency
1. Is closing an already-closed session harmless (idempotent)?
2. Is it safe to send the same close request twice if the first response was lost?
3. Can a closed session id (the attempt id) be reused if a teacher re-opens an attempt?

*Current behaviour:* the plugin keeps a ledger row per attempt and a lock around each close, and treats a
closed ledger row as final (a re-opened attempt is **not** proctored again; see `docs/DESIGN.md`).
A lost response can still cause one duplicate close request.

### 5. Parameter semantics
1. In the widget parameters, `course_id` is the **quiz id** in the previous Moodle integration's quiz-level proctoring. Is that
   what the vendor expects, or should it be the course id?
2. What does `redirect_url` do? (The plugin sends the site URL.)
3. Are `session_id` / `user_id` expected as strings or numbers? (The plugin sends strings, as the previous integration did.)

### 6. Data protection
1. What does the widget collect beyond the parameters (camera, screen, behaviour) and for how long is it kept?
2. How can a learner's data be exported or deleted on request? (Moodle privacy requests only cover
   Moodle's own data.)

## Switching the integration on

Do **not** enable "Enable vendor integration" on a production site until questions 1–4 above have
written answers and `techversant_client.php` has been reviewed against them.
