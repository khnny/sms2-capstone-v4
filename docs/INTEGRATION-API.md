# SMS 2 Integration API (v1): JWT, REST and Webhooks

This is the data contract between SMS 2 / CRAD and separately deployed apps. CRAD has five integrations, and each one gets its own API client (key): **Student Portal** (`student-portal`, the `sms2-student-portal` app), **Registrar** (`registrar`), **Payment** (`payment`), **Faculty** (`faculty`) and **Accreditation** (`accreditation`). Each key only reads what its module needs (section 6).

Nothing in the existing CRAD pages or workflows was changed for this. The only edit to an existing file is in `includes/authentication.php`: after login, SMS 2 returns to an app's sign-in request if one is in progress.

## 1. Pieces

| Piece | File | Purpose |
|---|---|---|
| JWT helper | `includes/jwt.php` | HS256 sign and verify (no external library) |
| Auth library | `includes/integration.php` | API clients, one-time login codes, refresh tokens, SSO tickets |
| Read models | `includes/integration-data.php` | Student data for the API (reuses the CRAD group lookup) |
| Webhooks | `includes/webhooks.php` | Change detection, outbox, signing, delivery with retries |
| Sign-in page | `login/sso-authorize.php` | Sends a one-time code back to the app after a normal SMS 2 login |
| SSO launch | `login/sso-launch.php` | Opens an SMS 2 page for the same user from an app |
| REST API | `api/v1/index.php` (+ `.htaccess`) | All endpoints below |
| Cron job | `tools/send-webhooks.php` | Run every minute |
| Client setup | `tools/register-api-client.php` | Registers a module app from its preset and prints its secrets once |
| Test | `tests/integration_api_test.php` | 25 checks |
| Test | `tests/module_clients_test.php` | 41 checks: presets, scopes, webhook filtering, env setup, rotation, HTTP 403s |
| Schema | `database/patches/integration_api_schema.sql` | Optional: the tables are created on first use |

## 2. Setup

1. **Secret for signing tokens.** Set `SMS2_JWT_SECRET` (at least 32 random characters) in HostForge Environment Variables or `config/local.php`. On XAMPP you can skip it: a key is created in `storage/keys/jwt.key`. If that key changes, everyone must sign in to the portal again.
2. **Register the Student Portal** (run in the SMS 2 folder):
   ```
   php tools/register-api-client.php student-portal --name="Student Portal" --roles=student \
       --redirect=http://localhost/sms2-student-portal/callback.php \
       --webhook=http://localhost/sms2-student-portal/webhook.php
   ```
   Copy the **CLIENT SECRET** and **WEBHOOK SECRET** into the portal's `config/local.php`. They are shown once. Run it again with `--rotate-secret` or `--rotate-webhook-secret` to get new ones. On HostForge, use the `https://portal.<domain>/...` addresses instead.
   Other modules (Registrar, Payment, Faculty, Accreditation) are set up the same way with their own key: see section 6.
   **No terminal (HostForge):** instead of running the script, set these Environment Variables on SMS 2. SMS 2 creates or updates the `student-portal` client from them on the next request. Generate each secret with `php -r "echo bin2hex(random_bytes(32));"` and set the same value on the portal.
   - `SMS2_PORTAL_CLIENT_SECRET`
   - `SMS2_PORTAL_REDIRECT_URI` (`https://<portal>/callback.php`)
   - `SMS2_PORTAL_WEBHOOK_URL` (`https://<portal>/webhook.php`)
   - `SMS2_PORTAL_WEBHOOK_SECRET`
3. **Cron:** `php /path/to/sms2/tools/send-webhooks.php` every minute. If cron is not available, set `SMS2_CRON_TOKEN` (16+ characters) and have any web cron service call `POST /api/v1/internal/webhooks/run` with the header `X-Cron-Token: <token>`.
4. Optional: `SMS2_PUBLIC_URL` (for example `https://sms2.example.com`) if SMS 2 runs behind a proxy and the links it builds look wrong.

## 3. Sign-in flow

```
Student          Portal                          SMS 2
  | open portal    |                               |
  |--------------->| redirect with client_id,      |
  |                | redirect_uri, random state    |
  |<---------------|------------------------------>| /login/sso-authorize.php
  |  normal SMS 2 login (password, OTP, Authenticator, passkey)  |
  |<-----------------------------------------------| redirect to callback.php?code&state
  |--------------->| check state; POST /auth/token (code + client secret, server-to-server)
  |                |------------------------------>| access JWT (15 min) + refresh token (8 h)
  |  dashboard     |  GET /me/... with Bearer JWT  |
```

- The code works once, for 60 seconds, and only with the registered `redirect_uri` (exact match).
- The portal's client only accepts `student` accounts. Staff who try get `error=access_denied`.
- Refresh tokens rotate on every use. Presenting an old one revokes that user's whole portal session (theft detection).
- Every API call re-checks that the account and client are still active, so a deactivated student loses access immediately.
- Tokens stay on the portal server (PHP session). They never reach the browser.

### Access token claims

```json
{ "iss": "sms2-auth", "aud": "sms2-api", "azp": "student-portal", "sub": "1709", "role": "student",
  "student_id": "S230106713", "name": "…", "email": "…", "iat": 1795767559, "nbf": 1795767559,
  "exp": 1795768459, "jti": "…" }
```

## 4. Endpoints

Base: `<SMS2>/api/v1/` (with mod_rewrite) or `<SMS2>/api/v1/index.php/` (always works). Responses are JSON: `{"data": …}` on success, or `{"error": {"code": "…", "message": "…"}}`.

### Auth (client authenticates with HTTP Basic `client_id:client_secret`, or `client_id` and `client_secret` in the body)

| Method and path | Body | Returns |
|---|---|---|
| `POST auth/token` | `grant_type=authorization_code`, `code`, `redirect_uri` | `access_token`, `token_type`, `expires_in`, `refresh_token`, `user` |
| `POST auth/token` | `grant_type=refresh_token`, `refresh_token` | same, with a new refresh token |
| `POST auth/token` | `grant_type=password`, `username`, `password`, `client_ip` (the visitor's IP) | same as above, or `{"mfa_required":true,"mfa_token":"…","methods":["authenticator","email"],"expires_in":600}` when the account has an Authenticator |
| `POST auth/token` | `grant_type=mfa_code`, `mfa_token`, `method` (`authenticator` or `email`), `code`, `client_ip` | same as the first row |
| `POST auth/mfa/email` | `mfa_token`, `client_ip` | `{"data":{"sent_to":"ju****@bestlink.edu.ph"}}` (emails a 6-digit login code) |
| `POST auth/revoke` | `refresh_token` | `{"data":{"revoked":true}}` |
| `POST auth/ticket` (Bearer) | `path=/modules/student-portal/pages/submit-chapters.php` | `{"data":{"url":"…/login/sso-launch.php?ticket=…","expires_in":60}}` |

#### App login page (password grant)

The Student Portal has its own login form. It never stores passwords: it sends what the student typed
server-to-server to `auth/token` with its client secret, and SMS 2 checks it with the same code as the
SMS 2 login page (`smsLoginAttempt`): failed-attempt lockout, per-IP throttle (using `client_ip`),
inactive accounts and audit logs all apply. Students with an Authenticator get an `mfa_token` (10 minutes)
and finish with `grant_type=mfa_code`; wrong codes are counted per challenge. Errors: `invalid_grant`
(wrong login, message says attempts left), `locked` (423), `inactive`, `access_denied` (not a student),
`password_change_required` (sign in on SMS 2 once to set a new password), `maintenance` (503),
`invalid_code`, `mfa_expired`, `mfa_locked`.

### Data (Bearer access token; the `me/research…`, `me/defenses` and `me/clearance` endpoints are for students only)

Each endpoint also needs a **scope** on the app's key (section 6). Without it the answer is `403` with `{"error":{"code":"insufficient_scope",…}}`.

| Path | Data |
|---|---|
| `GET me` | User, plus `profile` for students (program, section, year, standing, contacts) |
| `GET me/research` | `status`, `title`, `title_approval` (adviser, coordinator and CRAD status and remarks), `group`, `plan` (progress, stage) |
| `GET me/research/statement` | The whole CRAD process in one response: `student`, `summary` (title, group no., adviser, progress, next step) and 9 `steps` (key, title, state, status, rows, list, remarks, action). Used by the Research Hub pages. |
| `GET me/research/members` | Group members |
| `GET me/research/timeline` | Milestones with progress and status |
| `GET me/research/submissions` | `chapters` (version, status, dates) and `progress_updates` |
| `GET me/research/feedback` | Adviser feedback and chapter evaluations, newest first |
| `GET me/defenses` | Defense type, status, start and end, venue, panel |
| `GET me/clearance` | `clearances` (signatures and verifications, needs `clearance.read`) and `payments` (needs `payment.read`). A key with only one of the two gets only that list. |
| `GET me/notifications` | SMS 2 notifications for the user |
| `GET announcements` | Published announcements for the user's role |
| `GET health` | `{"ok":true}` |

### Student actions (Bearer access token, students only)

These use the same rules as the SMS 2 student pages (adviser approval, chapter order,
revision gate, Final Defense recommendation, file type and size checks, notifications).
Uploads are `multipart/form-data`. A rule that blocks the action answers `422` (or `409`)
with `{"error":{"code":"not_submitted","message":"…"}}`; show the message to the student.

| Path | Body | Result |
|---|---|---|
| `GET me/research/chapters` | | `available`, `message`, `group_number`, `max_mb`, `chapters` (number, label, `can_submit`, `next_version`, `reason`, `latest`) |
| `POST me/research/chapters` | `chapter_number` (1-3), `document` (file, max 10 MB), `notes`, `submission_token` (64 hex chars, new per form; blocks double submits) | `version`, `submission_id` |
| `GET me/research/manuscript` | | `available`, `eligible`, `can_submit`, `message`, `max_mb`, `submissions` (version, status, file name, dates) |
| `POST me/research/manuscript` | `manuscript` (file, max 20 MB), `notes` | `version`, `submission_id`. Refused while the latest version is not returned for revision. |
| `GET me/research/group` | | `has_group`, `flow_status`, `status_label`, `can_edit`, `min_members`, `max_members`, `group_number`, `incomplete_reason`, `remarks`, `members` (full_name, student_id, section, is_leader) |
| `POST me/research/group` | `members[i][full_name]`, `members[i][student_id]`, `members[i][section]`, `incomplete_reason`, `action` (`draft` or `submit`) | `message`. Refused once the group is with the Department Head or approved. |
| `GET me/research/title` | | `can_submit`, `is_resubmit`, `message`, `adviser`, `coordinator`, `members` (from the Research Group), `options` (departments, discipline clusters, agendas, SDGs, defaults), `submission` (latest form and its adviser / coordinator / CRAD status and remarks) |
| `POST me/research/title` | `research_title`, `department`, `discipline`, `research_agenda` (+ `research_agenda_others`), `primary_sdg` (`SDG 9 — Industry, …`), `justification` | `message`, `submission_id`. Sends a new form, or resends a returned one. Same gates as `modules/crad/api/send-to-adviser.php`. |
| `GET me/research/progress` | | `available`, `message`, `max_mb`, `statuses`, `milestones` (id, name, status, progress, chapter, pending, can_submit), `history` |
| `POST me/research/progress` | `milestone_id`, `new_progress`, `milestone_status` (`Not Started` or `Submitted for Review`), `update_title`, `accomplishments`, `problems_blockers`, `next_planned_activity`, `submission_token` (64 hex), `document` (file; required for a chapter milestone sent for review) | `message`, `update_id` |
| `GET me/research/panel` | | `available`, `message`, `max_mb`, `application` (ref, status, editable, details, review_remarks, documents, history). Starts a draft the first time, like the SMS 2 page. |
| `POST me/research/panel` | `action` = `save_details` (`research_title`, `adviser_name`, `members_text`), `upload_document` (`doc_type`, `document`) or `submit` | `message` |
| `GET me/research/payment` | | `available`, `message`, `max_mb`, `stages` (Research 1 / 2: status, O.R. number, remarks, `can_upload`, `locked_reason`) |
| `POST me/research/payment` | `stage` (`research_1` or `research_2`), `payment_file` (PNG or JPG), `or_number` (optional; read from the picture when blank) | `message` |
| `GET me/research/clearance-form` | | `available`, `message`, `max_mb`, `stages` (id, status, `can_upload`, `locked_reason`, CRAD remarks, `form_html` = the printable form) |
| `POST me/research/clearance-form` | `id`, `clearance_file` (PNG or JPG of the signed form) | `message` |

With these, every student step of the CRAD process can be done from the portal. CRAD officers,
advisers, coordinators, panels and Admin keep reviewing in SMS 2 as before. The SMS 2 page endpoints
(`send-to-adviser.php`, `research-progress.php`) and the API call the same functions
(`modules/crad/includes/title-approval-submit.php`, `research-progress-submit.php`).

Example:

```
GET /api/v1/me/defenses
Authorization: Bearer eyJ…

{"data":[{"id":12,"type":"Pre-Oral","status":"Scheduled","starts_at":"2026-12-10T09:00:00+08:00",
  "ends_at":"2026-12-10T10:00:00+08:00","venue":"Room 301","panel_chair":"Dr. A",
  "panel":[{"name":"Dr. A","status":"Assigned"}],"adviser":"…","research_title":"…"}]}
```

## 5. Webhooks

SMS 2 checks the CRAD tables every run (cron, every minute) and sends one event per change.

| Event | When |
|---|---|
| `research.title_status_changed` | The overall title status changes (Document Packet Sent, Adviser Approved, Coordinator Approved, CRAD Approved, Returned, Rejected) |
| `research.adviser_assigned` | An adviser assignment becomes or changes while Assigned |
| `submission.reviewed` | A chapter submission leaves "Submitted" (`source: chapter`), or an adviser leaves feedback on a progress update (`source: progress_update`) |
| `defense.scheduled` | A new defense schedule |
| `defense.rescheduled` | Date, time or venue changed |
| `defense.status_changed` | Only the status changed |
| `clearance.status_changed` | Research clearance (`kind: clearance`) or college payment (`kind: payment`) status changed |
| `manuscript.final_approved` | Final manuscript approved |
| `research.group_status_changed` | Research group approved, ready for assignment, or rejected |
| `research.panel_application_status_changed` | Panel application under review, approved, rejected, scheduled or completed |

The first run only records the current state, so existing records do not send old events.

An app only receives events its key's scopes allow (section 6), on top of its `--events` filter.

Request sent to the app:

```
POST /webhook.php
Content-Type: application/json
X-SMS2-Event: defense.rescheduled
X-SMS2-Event-Id: 3f8c…
X-SMS2-Timestamp: 1795768000
X-SMS2-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>" with the webhook secret>

{"id":"3f8c…","type":"defense.rescheduled","occurred_at":"2026-11-27T16:24:43+08:00",
 "data":{"defense_id":12,"defense_type":"Pre-Oral","status":"Scheduled","starts_at":"…","ends_at":"…",
         "venue":"AVR 2","previous_starts_at":"…"},
 "subjects":{"student_ids":["S230106713","S230106714"],"user_ids":[1354]}}
```

Receiver rules: verify the signature with `hash_equals`, reject timestamps older than 5 minutes, and ignore an `id` you have already processed. Any 2xx response counts as delivered. Anything else is retried after 1, 5, 15 and 60 minutes, then marked `failed` in `sms2_webhook_deliveries`.

## 6. Module API keys (one per integration)

Every CRAD integration has its own client. Never share one key between two modules: if one leaks, you rotate only that one, and each key can only read its own data.

### Presets

`tools/register-api-client.php` and the Environment Variables path fill these in for you. See them any time with `php tools/register-api-client.php --presets`.

| Module | client_id | Who can sign in (roles) | Scopes (what the key can read/do) | Webhook events |
|---|---|---|---|---|
| Student Portal | `student-portal` | `student` | `*` (everything) | `*` |
| Registrar | `registrar` | `student`, `registrar` | `profile research.read defenses.read clearance.read notifications.read announcements.read sso` | `research.*`, `defense.*`, `clearance.status_changed` (clearances only), `manuscript.final_approved` |
| Payment | `payment` | `student`, `finance` | `profile payment.read payment.write notifications.read announcements.read sso` | `clearance.status_changed` (payments only) |
| Faculty | `faculty` | `adviser`, `panel`, `grammarian`, `research_coordinator`, `research_director`, `hr` | `profile defenses.read notifications.read announcements.read sso` | `research.adviser_assigned`, `defense.*` |
| Accreditation | `accreditation` | `qa` | `profile research.read announcements.read sso` | `research.title_status_changed`, `manuscript.final_approved` |

So a **Registrar key cannot read payments** (`me/research/payment` and the `payments` list are refused or left out) and a **Payment key cannot read research, clearance or defense data**. Webhooks follow the same rule: a payment event (`clearance.status_changed` with `kind: payment`) is never sent to Registrar, and research, clearance and defense events are never sent to Payment, even if `--events=*` is set.

### Scopes

| Scope | Allows |
|---|---|
| `profile` | `GET me` |
| `research.read` | `GET me/research`, `members`, `timeline`, `submissions`, `feedback`, and the `GET` of `group`, `title`, `progress`, `panel`, `chapters`, `manuscript`; `research.*`, `submission.*`, `manuscript.*` webhooks |
| `research.write` | The `POST` of `group`, `title`, `progress`, `panel`, `chapters`, `manuscript` |
| `defenses.read` | `GET me/defenses`; `defense.*` webhooks |
| `clearance.read` | `clearances` in `GET me/clearance`; `GET me/research/clearance-form`; clearance webhooks |
| `clearance.write` | `POST me/research/clearance-form` |
| `payment.read` | `payments` in `GET me/clearance`; `GET me/research/payment`; payment webhooks |
| `payment.write` | `POST me/research/payment` |
| `statement.read` | `GET me/research/statement` (the whole process, payments included) |
| `notifications.read` | `GET me/notifications` |
| `announcements.read` | `GET announcements` |
| `sso` | `POST auth/ticket` |

Scopes are checked against the client row on every request, so changing a key's scopes takes effect at once, even for tokens already issued. Clients registered before scopes existed get `*` and keep working exactly as before. The token response and the access token carry `"scope": "…"` for information.

### Create a key: option A, with a terminal (XAMPP or any server with PHP CLI)

Run in the SMS 2 folder, once per module (use the module's real URLs on HostForge):

```
php tools/register-api-client.php registrar \
    --redirect=http://localhost/registrar/callback.php \
    --webhook=http://localhost/registrar/webhook.php

php tools/register-api-client.php payment \
    --redirect=http://localhost/payment/callback.php \
    --webhook=http://localhost/payment/webhook.php
```

The same works for `student-portal`, `faculty` and `accreditation`. The output looks like this (fake values):

```
Client saved: registrar (preset: Registrar)
  roles:  student,registrar
  scopes: profile research.read defenses.read clearance.read notifications.read announcements.read sso
  events: research.*,defense.*,clearance.status_changed,manuscript.final_approved
CLIENT SECRET (shown once): EXAMPLE-not-a-real-secret-0000000000000000
WEBHOOK SECRET (shown once): EXAMPLE-not-a-real-webhook-secret-00000000
```

Copy both secrets straight into that module app's `config/local.php` (or its Environment Variables). They are shown only once and SMS 2 keeps only a hash of the client secret. Other useful commands:

- `php tools/register-api-client.php --list` shows every client with its roles, scopes and webhook (no secrets).
- `--scopes="profile payment.read"` or `--roles=student` changes one client. `--preset` resets it to its module preset.
- `--preset=registrar` on another client_id (for example `registrar-kiosk`) gives a second app the same rules with its own key.
- `--disable` turns a client off and revokes its refresh tokens.

### Create a key: option B, no terminal (HostForge Environment Variables)

Set these on the **SMS 2** service. SMS 2 creates or corrects the client on the next request. Use the prefix of the module:

| Module | Prefix |
|---|---|
| Student Portal | `SMS2_PORTAL_` |
| Registrar | `SMS2_REGISTRAR_` |
| Payment | `SMS2_PAYMENT_` |
| Faculty | `SMS2_FACULTY_` |
| Accreditation | `SMS2_ACCREDITATION_` |

| Variable | Value |
|---|---|
| `<PREFIX>CLIENT_SECRET` | At least 32 random characters. Required; without it the client is managed by the CLI only. |
| `<PREFIX>REDIRECT_URI` | `https://<module>/callback.php` (space-separate for several; optional if the module only uses its own login form) |
| `<PREFIX>WEBHOOK_URL` | `https://<module>/webhook.php` (optional) |
| `<PREFIX>WEBHOOK_SECRET` | At least 32 random characters (optional, needed with a webhook URL) |
| `<PREFIX>ROLES`, `<PREFIX>SCOPES`, `<PREFIX>EVENTS` | Optional overrides of the preset. Leave them out normally. |

Generate each secret with `php -r "echo bin2hex(random_bytes(32));"` (or any password generator, 64 characters), and set the **same** value on the module app. Example for Registrar (fake values):

```
SMS2_REGISTRAR_CLIENT_SECRET=0000example0000example0000example0000example0000example00000000
SMS2_REGISTRAR_REDIRECT_URI=https://registrar.example.com/callback.php
SMS2_REGISTRAR_WEBHOOK_URL=https://registrar.example.com/webhook.php
SMS2_REGISTRAR_WEBHOOK_SECRET=1111example1111example1111example1111example1111example11111111
```

While `<PREFIX>CLIENT_SECRET` is set, the Environment Variables win: changes made with the CLI to that client are reset on the next request.

### Where to store the secrets

- **On the module app only:** its `config/local.php` (git-ignored) or its HostForge Environment Variables.
- **Never** in Git, a screenshot, a group chat, a document or a ticket. If you need to tell someone which key, say the `client_id`, not the secret.
- `SMS2_JWT_SECRET` (section 2) belongs to SMS 2 only. Module apps never need it.

### Expiry and rotation

- For the capstone the client secrets have **no fixed expiry**. Access tokens still expire after 15 minutes and refresh tokens after 8 hours.
- **Rotate a key when it may have leaked** (pushed to Git, pasted in chat, a laptop lost, a member leaves the team), and before the real school deployment.
- CLI: `php tools/register-api-client.php payment --rotate-secret` (and `--rotate-webhook-secret` for the webhook secret). Environment Variables: put a new value in `SMS2_PAYMENT_CLIENT_SECRET`. The old secret stops working at once, so update the module app right after. Students signed in to that module may need to sign in again.
- Rotating one module's key does not affect the other modules.

## 7. Plan: Faculty accounts sync (CRAD as client of a Faculty API)

Not built yet. In this direction CRAD is the **client** of the Faculty / HR system's API, so it can keep adviser and panel accounts up to date without typing them in by hand.

**Settings on SMS 2** (Environment Variables or `config/local.php`):

- `FACULTY_API_URL`, for example `https://faculty.example.com/api/v1/faculty`
- `FACULTY_API_KEY`, the key the Faculty system issues to CRAD (sent as `Authorization: Bearer <key>`; same storage and rotation rules as above)

**Expected response** (mock fixture: `tests/fixtures/faculty-api-sample.json`):

```json
{ "data": [
  { "employee_id": "EMP-0001", "full_name": "Juan Dela Cruz", "email": "juan.delacruz@example.edu",
    "department": "BSIT", "status": "active", "roles": ["adviser", "panel"] }
] }
```

**`tools/sync-faculty.php`** (cron, nightly):

1. GET `FACULTY_API_URL` with the key. Or read a file with `--fixture=tests/fixtures/faculty-api-sample.json` for testing without the real API.
2. Upsert by `employee_id`: a new `sms2_faculty_links` table (`employee_id` unique, `user_id`) links each employee to an `sms2_users` row. A known employee gets name, email, department and role updated. A new one gets an account with a random password and `must_change_password = 1` (they set it through Forgot password). An email that already exists is linked instead of duplicated.
3. `status: inactive` sets the SMS 2 account to inactive (never deleted, so old CRAD records keep their names).
4. Only CRAD faculty roles are written (`adviser`, `panel`, `grammarian`, `research_coordinator`, `research_director`). Admin roles are never changed by the sync.
5. `--dry-run` prints what would change, and every run writes an audit log entry with counts.

## 8. Known limits (v1)

- Uploaded files (payment pictures, signed forms) are not served back through the API; the portal shows the file name and status only.
- Webhook latency is up to one minute (the cron interval).
- A student with no Registrar profile row gets the profile defaults that the existing Student Portal already uses (for example the "BSIT 4A" section). Fix those records in the Registrar data.
