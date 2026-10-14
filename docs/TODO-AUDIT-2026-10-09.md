# Updated Website To-Do List: Code Audit and Changes

Project: SMS 2 Capstone (CRAD module and shared SMS 2 platform)
Base: sms2-capstone-v3-main (latest copy as of 9 October 2026)
Test environment: PHP 8.3, MariaDB 10.11, sms2_db and crad_db imported from the project dumps, PHP built-in server.

## 1. Summary

Most items on the list already exist in the code base. This audit verified each one against the source files and, where possible, against the running application. One item required a code change (OTP lifetime). Several items are partially complete and need a decision before they can be finished. Two items are not started.

Status key:
- **Implemented**: present in code and verified by page load or function test.
- **Implemented (not tested end to end)**: present in code; page loads, but the full workflow was not exercised.
- **Partial**: some sub-items are present, others are missing.
- **Changed**: modified in this round.
- **Decision needed**: depends on a scope or design decision by the team.
- **Not started**: no code found.

## 2. Status by item

| # | Item | Status | Evidence / notes |
|---|------|--------|------------------|
| P0 | App key deletion when changing password | **Verified, no bug reproduced** | Full flow over HTTP, `tests/p0_authenticator_http_test.py`: enable the Authenticator, change the password, log out, log in with the new password and an Authenticator code, then check that the Authenticator is still enabled, the stored secret is unchanged, and `storage/keys/app.key` is unchanged (7 of 7 checks pass). Every password path in the code was reviewed: `smsSetUserPassword()`, the forgot-password reset, the OTP-approved reset, the profile and module-security resets, and the forced change. None of them touches the Authenticator secret, the app key file, or the SMTP app password. Key loss can still break Authenticator logins: if `storage/keys/app.key` is lost or replaced while the database holds secrets encrypted with the old key, the app generates a new key and the Authenticator pages fail with a fatal error. Keep `app.key` with each copy of the database, or set `SMS2_APP_KEY` on every machine. |
| P1.1 | Panel Application (group request) | **Built and verified (23 of 23)** | A research group submits a panel defense request. The student fills in the title, adviser, and members, uploads the required documents, and submits. CRAD starts the review, approves or rejects with a reason, then marks scheduled and completed. A rejected application can be edited and resubmitted. Every step is recorded in the history. Assumptions to confirm: the required documents are a final manuscript (PDF) and a signed approval sheet (PDF or image), and CRAD marks "scheduled" by hand. Tables are `crad_defense_panel_requests`, `crad_defense_panel_request_history`, and `crad_defense_panel_request_documents`. The names `crad_panel_applications*` belong to the existing panelist application feature, so they were not reused. |
| P1.2 | Panel Configuration | **Changed; verified (6 of 6)** | The panel size is now configurable per defense type. The CRAD officer sets a minimum and maximum (2 to 7 members) on the panel configuration page, with a default of 2 to 5. The rule is used in three places: the assignment save, the scheduling gate, and the page text. Exactly one chair is still required. Test: `tests/panel_size_test.py`. |
| P1.3 | Panelist Availability | **Done; verified (7 of 7 availability, 9 of 9 Research Director conflict checks)** | Panelists set days, time ranges, and a status. Validation, saved values, and the notification are tested (7 of 7). The Research Director availability and conflict logic is tested directly (9 of 9): windows must cover the slot, and venue, group, and shared-panelist overlaps conflict. Not tested: the full Research Director page flow. |
| P1.4 | Defense Scheduling | **Done; create-side conflict check not yet tested** | CRAD owns scheduling. The list shows every active schedule, including title-path groups. Reschedule changes date, time, and venue. It refuses a venue or group already booked, a panelist who is not available at the new time (saved windows, or status Unavailable), a missing reason, an end before the start, and a past time. Each reschedule writes a history row. Verified by `tests/defense_reschedule_test.py` (14 of 14) and `tests/defense_schedule_list_test.py` (5 of 5). Not yet tested: the conflict and availability checks on the Research Director scheduling page, which already exist. |
| P2.5 | Announcement Page | Implemented (page verified) | `includes/announcements.php` and `communication/announcements.php`. Statuses: draft, scheduled, published, unpublished, archived. Filters by audience and category. Expiry date supported. Delete supported. Page returns HTTP 200 for the super admin. Please confirm that this matches the version you are working on. |
| P2.6 | Calendar / Bulletin | **Changed** | The centralized calendar, defense schedules, research deadlines, and filters already existed. Added: published announcements appear on the calendar as Announcement events, visible by audience (`communication/event-provider.php`). Added: deadline reminders (`includes/deadline-reminders.php`). See section 10. |
| P2.7 | Dashboard Improvements | **Changed** | The CRAD officer scorecards are in `includes/dashboard-metrics.php`. The earlier audit wrongly said Pending Applications and Completed Defenses were missing. They were present, but the cards read the legacy `crad_research_proposals` table, which current submissions never write to, so the numbers were always zero. Total, Pending, and Approved Applications now count `crad_title_approvals`. "Approved" uses the official rule `cradValidTitleApprovalWhereSql()`. "Pending" excludes Returned and Rejected titles. Scheduled Defenses now counts upcoming confirmed defenses. Completed Defenses counts confirmed defenses whose date has passed. Before this change, it counted a status that no code path ever sets. The donut chart (research groups by college) and Recent activity were already present and are unchanged. Decision needed: confirm that "completed" means the defense date has passed. A stricter definition would require panel evaluations to be recorded. |
| P2.8 | Authentication: OTP expiration | **Changed** | All OTP lifetimes now come from one constant, `SMS2_OTP_TTL_MINUTES = 5` in `config/config.php`. Previously 10 minutes for email and Authenticator codes, and 2 minutes for forgot-password. The forgot-password page text ("valid for 5 minutes", "Code expired") now reads the same constant, so the UI and the server agree. Test `tests/otp_ttl_test.php` confirms a stored OTP expires after 300 seconds. |
| P2.8b | Authentication: Session timeout after inactivity | **Changed** | The code default was 2 minutes. It is now 30 minutes in `includes/scripts.php`, matching the value already stored in the shipped database (`session_timeout_minutes = 30`). |
| P2.9 | Role-Based Access Control | **Verified, no code change** | Access is decided by the system role key (`smsRoleAllowedForModule()` in `includes/authentication.php`), not by office position. Verified over HTTP with one account per role: `tests/rbac_separation_test.py`, 14 of 14 pass. Super Admin (`superadmin`) manages accounts; Admin (`sms_admin`) does not. Adviser, Research Coordinator, CRAD Officer, Panel, and Student each reach only their own home pages. Decisions needed: see section 11. |
| P2.10 | Account Deactivation | **Done; verified (7 of 7)** | An account set to inactive or suspended cannot log in. An account that is deactivated while logged in loses access on its next page load, because the session now checks the account status on every request, failing closed. A restored account can log in again. Verified by `tests/account_deactivation_test.py`. The database allows only active, inactive, locked, and suspended, so "archive" is stored as inactive. |
| P3.11 | Document Submission Workflow | **Done; sequential order added and verified (7 of 7)** | Team decision: Chapter N can be submitted only after Chapter N-1 has been submitted. The rule is one helper (`rpPreviousChapterSubmitted`) used by the student's eligibility display and the server-side submit. The check counts any submission for the previous chapter, regardless of its status. Also present: submission and version history, statuses (Submitted, Under Review, Needs Revision, Accepted), per-criteria remarks, and adviser approval before each submission. Open question for the team: does "submitted" mean any submission, or only one that was accepted or is under review? The code currently uses any submission. |
| P3.12 | AI / Document analysis | **Built; needs an OpenAI key to run the full analysis** | The Adviser gets AI-assisted observations on each progress update, before approve or revision. They are observations, not decisions. With an OpenAI key (GPT-4.1), the system makes a summary and a structure analysis. Without a key, only a limited LanguageTool style check runs, and the result says so. Still needed from the team: the final list of AI features and who sees the results. |
| P3.13 | Research Clearance | Implemented (not tested end to end) | `modules/crad/pages/research-clearance.php` and `modules/crad/includes/research-services-clearance.php`. Decision needed: the approved Chapter 3 scope removes payment functionality, but this recommendation asks for a payment check before clearance. The team must decide which one applies. |
| P3.14 | Research Grant Application | **Verified; both bugs fixed** | The six-step approval chain (Adviser, Dept. Chair, Dean, Research Office, VPAA, Finance) with sequence enforcement, return loop, and final status. Verified over HTTP with `tests/grants_approval_test.py`: 19 of 19 pass after the fixes in section 12. |
| P3.15 | Storage / Database | Not started | No cloud storage integration was found. Documents are stored on the local server under `storage/` and `uploads/`. The storage plan is a documentation and architecture task. |

## 3. Verification performed

Full crawl: 257 page files were requested as the super admin and as the CRAD officer, on both the original v3 copy and the updated copy (514 requests per copy). Both copies ran against the same test database and the same app key. Result: zero differences in HTTP status between the two copies. Status codes: 467 HTTP 200, 33 HTTP 403 (role-gated, expected), 4 HTTP 400 (pages that require parameters), 10 HTTP 500.

The 10 HTTP 500 responses are pre-existing and are not user-facing. They come from five partial files that are only meant to be included (`communication/event-provider.php`, `communication/defense-calendar.php`, `communication/dashboard-widgets.php`, `communication/dashboard-announcements.php`, `dashboard/compact-board.php`). Each one works when included by its parent page, which was confirmed in `dashboard/index.php`, `includes/breadcrumbs.php`, and `modules/student-portal/student-portal-page.php`. Opening them directly fails. Recommended hardening: add a guard that returns 404 when opened directly. Not changed in this round.

One PHP warning was found and fixed: `modules/crad/pages/research-clearance.php` line 131 read an array offset from a null value when no clearance file had been uploaded.

1. PHP syntax lint on every `.php` file in the project: zero errors before and after this round.
2. Functional test, P0 (`tests/p0_app_key_test.php`): PASS.
3. Functional test, OTP lifetime (`tests/otp_ttl_test.php`): PASS.
4. HTTP smoke test with the running application (superadmin session): dashboard, announcements, user accounts, role permissions, system settings, research director: HTTP 200 with no PHP fatal or parse errors. Panel availability for the superadmin redirected as designed (role-gated).
5. HTTP smoke test (crad_officer session): CRAD index, panelist applications, panel configuration, research defense scheduling, register proposal, dashboard analytics, core system dashboard: HTTP 200 with no PHP fatal or parse errors.

Not yet tested: the full panelist application workflow, panel assignment, defense scheduling with real data, and the grant and clearance chains. These need a scripted end-to-end run in a test database.

## 4. How to run the tests

See `tests/README.md`. All tests use dedicated `zz_test_*` accounts that they create and delete. No real account is read or changed.

## 5. Decisions needed from the team

1. Panelist availability updates (P1.3): resume-link access without login, or access through a linked user account.
2. Session timeout default (P2.8b): keep 2 minutes or change to 15 to 30 minutes.
3. Bulk account deactivation (P2.10): UI selection or import list.
4. Payment check in clearance (P3.13): keep or remove, per the approved scope.
5. AI model documentation (P3.12): align the documented model with the implemented engine.
6. Application statuses "Scheduled" and "Completed" (P1.1): keep at defense level or add to the application model.


## 6. Round 2 changes

Scope rule: items written in the Updated Website To-Do List are treated as the panel's requirements. Anything outside that list was not changed.

Changed in this round: `includes/scripts.php` (session default), `modules/user-management/includes/save-user.php` and `modules/user-management/pages/user-accounts.php` (bulk status), `login/forgot-password.php` (OTP text now follows the configured lifetime), `modules/crad/pages/research-clearance.php` (null-offset warning fixed).

Test results: `tests/bulk_status_http_test.py` passed 10 of 10 checks against the running application. The rendered `user-accounts.php` page has 6 inline script blocks, all of which pass a JavaScript syntax check. The earlier full crawl remains the regression baseline.

Not done in this round, because each needs a new build or a decision:

- P1.1 Panel Application (group defense request) and its Scheduled and Completed statuses.
- P1.3 Panelist availability update by the panelist after submission.
- P1.4 Explicit reschedule action. Rescheduling currently happens by proposing and selecting a new slot.
- P2.6 Calendar filtering and deadline reminders. The calendar pages exist, but these features were not verified.
- P2.7 Dashboard scorecards for Pending Applications and Completed Defenses, and the graphs.
- P3.11 Step-by-step chapter tracking and adviser validation. Submission and evaluation exist; the step model was not verified.
- P3.12 Definition of AI's role (documentation task).
- P3.13 Payment check in clearance. The approved scope removes payment, so this needs a team decision.
- P3.15 Storage plan (documentation and architecture task).


## 7. Round 3: P0 verification and test safety

The P0 item was checked end to end over HTTP, and no bug was reproduced in this code base, so no code change was made for it. The HTTP test is `tests/p0_authenticator_http_test.py`, with 7 of 7 checks passing.

The earlier tests changed real accounts: they set fixed passwords and turned the Authenticator off for `superadmin`, `registrar`, and `cradofficer`. On a local installation, that could change the super admin credentials. All tests now use dedicated `zz_test_*` accounts, created by `tests/helpers/test_accounts.php` and deleted when each test finishes. A before-and-after comparison of the real accounts (password hashes, status, and Authenticator state) showed no changes, and no test accounts were left behind.

Final results: `tests/bulk_status_http_test.py` 11 of 11 checks pass, including the CRAD officer being refused with a permission error. `tests/p0_authenticator_http_test.py` 7 of 7. `tests/p0_app_key_test.php` and `tests/otp_ttl_test.php` pass.


## 8. Round 4: dashboard scorecards (P2.7)

Changed: `includes/dashboard-metrics.php`, both the card definitions and the live-value resolver, so the scorecards and their refresh values agree.

Test: `tests/dashboard_scorecards_test.py` inserts known title and defense rows (zz_test_ only), reads the CRAD officer dashboard over HTTP, and compares the card values with database counts. Result on the updated code: 5 of 5 pass. Result on the original code: 0 of 3 application checks pass (all cards show 0), which confirms the test detects the bug.

Full crawl after this change: 514 requests compared with the original, zero status differences.


## 9. Round 5: calendar (P2.6)

Changed: `communication/event-provider.php` (announcement events, audience-based visibility, and a guard so an announcement error cannot break the calendar) and `communication/calendar.php` (type filter option).

Test: `tests/calendar_announcements_test.py`. Result: 9 of 9 checks pass. It covers the CRAD officer seeing all published announcements, students seeing only "all" and their own audience, drafts staying off the calendar, and the filter option.

Full crawl after this change: 514 requests compared with the original, zero status differences.


## 10. Round 6: deadline reminders (P2.6)

**What it does.** A reminder is created for each group member and each assigned adviser when one of these falls within the next 7 days: a research plan completion date, a milestone target date (not yet completed or approved), or a confirmed defense (scheduled or finalized). Reminders are created at 7, 3, 1, and 0 days left. Each stage is stored once per person and deadline, so running the job again never creates duplicates, and a missed day is caught up on the next run.

**Where it shows.** A "Deadline reminders" banner appears on every authenticated page through the shared layout (`includes/layout-start.php`), with a "Mark as read" button. Email is sent when SMTP is configured. The in-app reminder is always stored, and its email outcome is recorded (`sent`, or the reason it was not sent).

**How it runs.** Two ways, both safe to repeat: (1) the first page load each day runs the job; (2) `tools/send-deadline-reminders.php` can be scheduled. On Windows (XAMPP), create a daily Task Scheduler task that runs `C:\xampp\php\php.exe F:\xampp\htdocs\sms2_system\tools\send-deadline-reminders.php`.

**Who receives reminders.** Group members are matched to active accounts by student ID or email. Advisers are matched by their account. Panel members are not included, because the panelist pool is not linked to user accounts (see section 5 of this audit).

**Setup needed before emails go out.** Set SMTP in System Settings. Without it, reminders still appear in the banner, and the email status says "not sent".

**Files.** `includes/deadline-reminders.php`, `tools/send-deadline-reminders.php`, `communication/reminder-read.php` (new POST-only endpoint), `includes/layout-start.php` (banner call), `communication/event-provider.php` and `communication/calendar.php` (announcement events from the previous round).

**Tests.** `tests/deadline_reminders_test.py`: 11 of 11 pass. It checks a 3-day reminder for the student and the adviser, no reminder for a 10-day deadline, no duplicates on a second run, the student banner and "Mark as read", and the adviser banner.

**Regression.** Full crawl after the shared layout change: 516 requests against the original. The only difference is the new `reminder-read.php`, which returns 400 on GET because it accepts POST only.

**Not verified here.** Real email delivery. The test environment has no SMTP server, so the "sent" path is untested. Please send one test reminder after configuring SMTP.


## 11. Round 7: role-based access verification (P2.9)

**Method.** One test account per system role. Each account logs in and requests five representative pages. A page counts as open only when it returns HTTP 200 at the same path. A redirect or a 403 counts as refused.

**Result matrix** (open or refused):

| Role | User mgmt | CRAD officer page | Coordinator home | Assignments page | Faculty workspace | Panel home | Student portal |
|---|---|---|---|---|---|---|---|
| Super Admin (superadmin) | open | refused | refused | open | refused | refused | refused |
| Admin (sms_admin) | refused | open | open | open | refused | refused | refused |
| Adviser | refused | refused | refused | refused | open | open | refused |
| Research Coordinator | refused | refused | open | refused | refused | refused | refused |
| CRAD Officer | refused | open | open | refused | refused | refused | refused |
| Panel Member | refused | refused | refused | refused | refused | open | refused |
| Student | refused | refused | refused | refused | refused | refused | open |

**Findings to confirm with the team.**

1. "Faculty" is not a role key. It is a module group. Adviser, Panel, Dean (`hr`), Research Director, and Grammarian receive it through the module map in `includes/authentication.php`. The To-Do list asks for a separate Faculty permission. This design satisfies it, but the team should confirm it.
2. Admin (`sms_admin`) can open CRAD pages and the assignments page, but not user management. Confirm that this split is intended.
3. The `admin` role key is a legacy alias that resolves to `superadmin`. The roles table also has duplicate labels: "Super Admin" appears for both `admin` and `superadmin`, and "CRAD Officer" for both `crad_officer` and `research_grant`. These are cosmetic, but they are confusing.
4. The CRAD Officer and the Research Coordinator share the coordinator home page. This is probably intended, but it should be confirmed.

**Tests.** `tests/rbac_separation_test.py`: 14 of 14 pass.

**Code changes.** None. Verification only.


## 12. Round 8: grant approval workflow (P3.14)

**What was checked.** Out-of-order and wrong-role sign-offs are refused. Each step advances in order. A return needs remarks and moves the proposal to Revision Required. The Research Coordinator cannot sign the Dept. Chair step. QA cannot sign the VPAA step. A complete chain reaches Approved & Funded, creates the funding tranches and project milestones, and records each signer by name. Each approver needs a recorded evaluation before signing.

**Bug 1 (fixed): the final sign-off reported a failure even though the approval was saved.** The final branch of `grantSubmitApprovalSignoff()` ran `CREATE TABLE IF NOT EXISTS` statements inside its own transaction. MySQL commits implicitly on DDL, so the transaction ended early, and the final `commit()` failed with "There is no active transaction." The status had already been written, so the proposal was approved, but the Finance user saw an error. The MySQL general log confirmed the statement order. Fix, in two parts: (a) `modules/crad/includes/grant-approval-helpers.php` now runs the evaluation, funding, and milestone schema checks before the transaction starts; (b) `grant-funding-helpers.php` and `grant-milestone-helpers.php` only begin and commit a transaction when none is already open, and they re-throw inside a caller's transaction so the caller can roll back.

**Bug 2 (fixed): approver names were recorded as "User."** `modules/crad/api/grant-approval.php` and `modules/crad/api/grant-document-repository.php` read `$_SESSION['full_name']` first. That key is never set, and the login sets `user_name`. Both now read `user_name` first, like the other grant APIs. Approval records now show the signer's name.

**Regression.** Full crawl after the fixes: 516 requests, zero status differences from the previous crawl. The only errors are the pre-existing partial-file errors listed in section 3.

**Notes, not bugs.** The legacy role map in `grantApprovalLegacyStepRoleKeys()` is keyed by the user's role. It is confusing but does not open any step to a wrong role. The server log also shows an unrelated CRAD error during the test: "Unknown column panel_assignment_notifications.research_group_id" in the chapter notification loader. It is outside the grant workflow and needs its own check.

**Tests.** `tests/grants_approval_test.py`: 19 of 19 pass. The fixture removes every row tied to the test application.


## 13. Round 9: panel size rules (P1.2) and chapter submission check (P3.11)

**P1.2 changes.** `modules/crad/includes/panelist-workflow.php`: `cradPanelSizeLimits()` and `cradSavePanelSizeLimits()`, stored as settings per defense type. `modules/crad/pages/panel-configuration.php`: a "Panel size rules" form (always visible), and the new `save_panel_size` action. The assignment save, the scheduling gate, and the helper text now use the rule. Each defense type can be set separately (Title, Proposal, Final).

**P1.2 test.** `tests/panel_size_test.py`: 6 of 6. The rule saves from the page. Two members are refused when the minimum is 3, five members are refused when the maximum is 4, and three members with one chair are accepted. The test restores the default 2 to 5 afterwards.

**P1.2 finding.** The panel save joins the group to the legacy `crad_research_proposals` table (`g.proposal_id`). A group whose legacy proposal row is missing cannot be assigned a panel, even when its approved title is complete. This is the dual-path issue from section 5 and should be fixed with the same cleanup.

**P3.11 check.** Read-only verification, no code change. The gap is the sequential submission rule in section 2 of the P3.11 row. This needs a team decision, because the code comment says the independence is intentional.

**Regression.** Full crawl after the panel changes: 516 requests, zero status differences from the previous crawl.


## 14. Round 10: defense schedule list (P1.4, part 1)

**Cause.** The defense list was always empty. `cradPruneOrphanDefenseSchedules()` ran before the list query and used a table name without its `crad_` prefix, so MySQL could not find the column. The exception was caught and the list returned no rows. The error was logged as "Defense schedule rows load failed."

**Why the fix is not just the alias.** The same cleanup deleted any schedule whose proposal did not exist in the legacy `crad_research_proposals` table. Groups on the title-approval path have no legacy row, so fixing the alias alone would have deleted their real schedules. This was tested: with only the alias fixed, the test schedule was deleted.

**Changes.** In `research-defense-scheduling.php` and `adviser-panel-assignment.php`, the cleanup no longer deletes anything. Schedules are records and are not removed automatically. In `research-defense-scheduling.php`, the listing uses a LEFT JOIN, so schedules without a legacy row show.

**Verification.** `tests/defense_schedule_list_test.py`: 5 of 5 on the fixed code. On the old code it fails at the list check (rows returned: []). The regression crawl shows zero status differences.

**Still open for P1.4.** (1) Who owns defense scheduling: the CRAD page or the Research Director page. Reschedule and history should go wherever finalize already lives. (2) The Research Director page still fails on `pma.availability_windows_json`. That column does not exist. Availability has only status and notes, so this belongs with P1.3.


## 15. Round 11: repairs from the current error log, and P1.3 verification

**Notification bug.** `includes/notifications.php` referenced `panel_assignment_notifications.research_group_id` without the `crad_` prefix, so the whole notification load failed on every page ("Chapter notification load failed", 461 times in one crawl). Fixed the reference. Panel notifications now load, which they did not before.

**Analytics bug.** `research-analytics-reporting.php` read `crad_research_groups.updated_at`, which does not exist. Replaced with `created_at`.

**RCP cleanup bug.** `research-clearance-payment.php` ran `SHOW TABLES LIKE ?`. MariaDB does not allow a placeholder in SHOW. Replaced with an `information_schema` query.

**Availability column.** `availability_windows_json` is added only by the standalone `install_panel_assignment.php`. A database made from the older dump lacks it, and the Research Director and panel profile pages failed. Added `cradEnsurePanelAvailabilityWindows()` in `modules/crad/config/config.php`. It creates the table and adds the column if missing, and is called before those pages read or write. Verified: the column was missing (0), the check added it (1), and the query ran.

**Not app bugs.** The `ROOT_PATH` and `smsDashSummary` errors come from partial files opened directly. The `smtp_password` decrypt error is a sandbox artifact from a regenerated app key.

**Verification.** Regression crawl: zero status differences, and the notification, analytics, and RCP errors no longer appear in the log. Tests passing after these changes: grants 19 of 19, panel size 6 of 6, defense list 5 of 5, RBAC 14 of 14, panelist availability 7 of 7.


## 16. Round 12: defense reschedule (P1.4) and panel application (P1.1)

**P1.4 ownership.** CRAD owns defense scheduling, as decided. Reschedule and history are on `research-defense-scheduling.php`, with the logic in `modules/crad/includes/defense-reschedule.php`.

**P1.4 reschedule.** Refuses a venue already booked by another active schedule at that time, a research group already booked at that time, a missing or short reason, an end time not after the start, and a past time. Writes a history row. Never deletes a schedule. Test: 11 of 11. Not yet included: re-checking panelist availability on reschedule.

**P1.1 panel application.** New student page `modules/student-portal/pages/panel-application.php` and CRAD page `modules/crad/pages/panel-applications.php`. Logic in `modules/crad/includes/panel-applications.php`. Download in `modules/crad/panel-application-file.php`, which serves a document only to CRAD officers and the owning student. Flow: Draft → Submitted → Under Review → Approved → Scheduled → Completed, with Rejected → Submitted for resubmission. Uploads: PDF, PNG, or JPG, up to 10 MB. The manuscript must be a PDF. Test: 23 of 23.

**Naming collision avoided.** `crad_panel_applications`, `crad_panel_application_history`, and `crad_panel_application_documents` already belong to the panelist application feature (`panelist-applications.php`). The group request uses `crad_defense_panel_requests*`. The existing feature was not changed.

**Menus.** Student: Research → Panel Application. CRAD: Panel Applications, shown to CRAD officers and not hidden.

**Regression.** Crawl: 520 requests. The only differences from the previous crawl are the two new pages. The CRAD page returns 200 for the CRAD officer. The student page returns 403 for the CRAD officer and the superadmin.

## 17. Student menu location

**Fix.** The Panel Application link was first added to the menu definition in `includes/authentication.php`, which the student sidebar does not use. The sidebar is built from `$studentNavGroups` in `includes/sidebar.php`. The link now sits under "Research Workspace" there, and the research page list includes it for highlighting. Verified over HTTP: a student sees the link on the dashboard, and the page opens with status 200.

## 18. Where the changes appear in the sidebar

**Verified over HTTP from each role's rendered sidebar.** Student: Research Calendar, Announcement Bulletin, and Panel Application (its own group). Panel Member and Adviser: Availability. CRAD Officer: Research Calendar, Announcement Bulletin, Panel Configuration, and Panel Applications on the Research Workspace hub. Research Coordinator: Research Calendar, Announcement Bulletin, and Research Defense Scheduling on the hub. Super Admin: Research Calendar, Announcement Bulletin, and User Accounts.

**Fixes made while checking.** The Panel Application link was first placed in a menu definition the student sidebar does not use. Panel Applications was first added to the module list only, and then to a hub tab set the CRAD officer does not use. Each is now where the role actually sees it. The CRAD officer sees the card once.

**Not visible in the sidebar.** Research Director has only two sidebar links, so the defense scheduling page is reached by URL.


## 19. Round 13: chapter order (P3.11)

**Decision.** Chapters are submitted in order (team, via scrum). The earlier code comment said each chapter was independent, and that line was replaced.

**Change.** `rpPreviousChapterSubmitted()` and `rpPreviousChapterMessage()` in `research-progress-helpers.php`. Used in `rpChapterSubmissionEligibility()` (what the student sees) and in `chapterSubmitDocument()` (the server-side check, so a direct request cannot skip the order).

**Test.** `tests/chapter_sequence_test.py`: 7 of 7. Chapter 1 is open. Chapter 2 waits for Chapter 1, and Chapter 3 waits for Chapter 2. A second Chapter 1 version does not change that.

**Not yet tested over HTTP.** The full student submit path needs an adviser-approved progress update and a registered group, so the shared rule was tested directly.


## 20. Round 14: defense reschedule availability (P1.4) and document analysis check (P3.12)

**Reschedule.** `cradPanelsUnavailableForSlot()` in `defense-reschedule.php`. Panelists are matched by name to the users table. A panelist with saved windows must have one window on that weekday that covers the new time. Status "Unavailable" blocks any time. A panelist with no availability record is not blocked. Test: 14 of 14.

**Difference from the Research Director check.** The Research Director check does not look at the Unavailable status. The reschedule check does.

**Document analysis.** Checked without a key: it returns a clear message and runs only the LanguageTool style check. It does not invent a summary. Not verified with a real key in this environment.


## 21. Round 15: account deactivation (P2.10) and Research Director checks (P1.3)

**Finding.** A deactivated account that was already logged in could still open pages. The login refused inactive and suspended accounts, but the session did not re-check the status.

**Fix.** `isAuthenticated()` in `includes/authentication.php` checks the account status once per request. Only active and locked accounts pass. A failed lookup denies access.

**Mistake corrected.** An early test wrongly suggested that archived accounts could log in. The status column does not allow "archived," so that update failed silently. The real archive value is inactive, and it was already blocked.

**Tests.** `tests/account_deactivation_test.py` 7 of 7. `tests/rd_conflict_test.py` 9 of 9. Re-run after the session change: panelist availability 7 of 7, panel application 23 of 23, RBAC 14 of 14.

**Still open in P2.7 and P3.12.** P2.7 needs the team's definition of "completed" and the chart choice. P3.12 needs the OpenAI key on your machine and the team's list of AI features.
