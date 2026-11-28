# SMS 2 Integration API (v1): JWT, REST and Webhooks

This is the data contract between SMS 2 / CRAD and separately deployed apps. The first one is the **Student Portal** (`sms2-student-portal`). Registrar, Payment and Accreditation can connect the same way later.

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
| Client setup | `tools/register-api-client.php` | Registers an app and prints its secrets once |
| Test | `tests/integration_api_test.php` | 25 checks |
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
| `GET me/clearance` | `clearances` (signatures and verifications) and `payments` |
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

## 6. Adding another module (for example Registrar)

1. `php tools/register-api-client.php registrar --name="Registrar" --roles=registrar --redirect=<its callback> --webhook=<its receiver> --events="research.*,manuscript.final_approved"`
2. It signs users in the same way and calls the same endpoints, or new ones added under `api/v1`.
3. Breaking changes go to a new `api/v2`. v1 keeps working.

## 7. Known limits (v1)

- Uploaded files (payment pictures, signed forms) are not served back through the API; the portal shows the file name and status only.
- Webhook latency is up to one minute (the cron interval).
- A student with no Registrar profile row gets the profile defaults that the existing Student Portal already uses (for example the "BSIT 4A" section). Fix those records in the Registrar data.
