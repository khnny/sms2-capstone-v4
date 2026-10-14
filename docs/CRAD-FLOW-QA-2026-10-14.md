# CRAD actual flow testing — 14 October 2026

This follow-up responds to the request to test the flows. It supersedes the earlier report's statement that all runtime tests were blocked. The earlier static findings remain separate evidence.

## What was executed

Application: PHP HTTP server at `127.0.0.1:8086`, local commit `d5e05f6`, with separate QA session/upload-temporary directory. Both application connections, PHP fixture helpers and Python SQL assertions targeted disposable `crad_qa_flow_20261014c`. Existing databases were not modified. SMTP host configuration was blanked in the QA database; no AI provider requests or OCR image processing were executed.

Tests used dedicated `zz_test_*` accounts. HTTP workflows below exercised application handlers and checked database state, history or visibility. They were not browser click/JavaScript tests. Earlier title/group/defense prerequisites are seeded where each suite needs them, so separate passing suites do not prove one continuous newly registered research record completed the entire lifecycle.

| Flow | Executed path and verification | Result | Evidence |
|---|---|---|---|
| Panel application | Student saves details, uploads manuscript/approval sheet, submits; CRAD reviews and rejects; student edits/resubmits; CRAD approves, schedules and completes. Invalid transitions and incomplete fields rejected; history ordered; owner/CRAD downloads allowed, another student's download denied. | PASS — 23/23 | `tests/panel_application_test.py` |
| Grant approval | Adviser → department chair → dean → research office → VPAA → finance; wrong-role and out-of-order approvals rejected; return requires remarks and produces Revision Required. Final approval persists Approved & Funded, creates 2 tranches and 5 milestones; signer names persisted. | PASS — 19/19 | `tests/grants_approval_test.py` |
| Chapter sequence | Previous-chapter submission unlocks next chapter; duplicate Chapter 1 version cannot substitute for Chapter 2. This is a database/helper test, not chapter upload/review HTTP E2E. | PASS — 7/7 | `tests/chapter_sequence_test.py` |
| Defense rescheduling | Save date/time/venue, preserve old/new history and reason; reject venue/group overlaps, invalid/past dates, short reason and unavailable/out-of-window panelist; rejected attempts preserve schedule. | PASS — 14/14 | `tests/defense_reschedule_test.py` |
| Defense schedule queue | Title-path schedule appears in CRAD queue; loading list/assignment pages preserves it. | PASS — 5/5 | `tests/defense_schedule_list_test.py` |
| Panel availability | Panel member saves weekly windows; reject inverted/empty windows; saved values reload and assignment notification appears on dashboard. | PASS — 7/7 | `tests/panelist_availability_test.py` |
| Panel configuration and assignment limits | Save minimum/maximum from CRAD form; values reload; reject 2 below minimum 3 and 5 above maximum 4; accept 3 with one chair. | PASS — 6/6 after schema-helper preparation | `tests/panel_size_test.py` |
| Scheduling conflict rules | Venue, group and shared-panel conflicts; overlapping and non-overlapping times; panel windows. Helper-level, not full scheduling creation UI. | PASS — 9/9 | `tests/rd_conflict_test.py` |
| Role navigation | Seven roles tested across protected pages; student/staff separation, CRAD/coordinator/adviser/panel pages and Super Admin account management. | PASS — 14/14 | `tests/rbac_separation_test.py` |
| Account deactivation | Inactive/suspended login refused; active login works; account deactivated during session loses access next request; restored login works. | PASS — 7/7 | `tests/account_deactivation_test.py` |
| Announcement calendar | Published announcement visibility follows audience; draft excluded; event typing and filter control verified. | PASS — 9/9 | `tests/calendar_announcements_test.py` |
| Dashboard after workflow states | Application totals match DB; returned excluded from pending; signed approval rule, upcoming and past confirmed defenses counted. | PASS — 5/5 | `tests/dashboard_scorecards_test.py` |
| Manuscript review → final approval → publication → repository | Reject premature publication/final approval and over-max score; return manuscript for revision then approve evaluation; block final approval until defense results; approve final manuscript; create Draft publication; refuse sequential duplicate; publish and confirm repository result; archive and confirm archived filter. | PASS — 14/14 | Added `tests/final_publication_flow_test.py` |
| Deadline reminders | Fixture insertion requires member `research_stage`, absent in schema produced by supplied dumps. No reminder delivery conclusion possible. | BLOCKED | `tests/deadline_reminders_test.py`: ERROR 1054 Unknown column research_stage |

These thirteen completed suites total **139 successful assertions**. The parser-only failures from the previous baseline are not included in this flow total and remain unresolved.

## Important fixture boundaries

The added final/publication flow seeds an approved signed title, group and manuscript row. Review/revision/approval and publication actions then use real HTTP handlers. Its final-defense prerequisite is seeded as a schedule, panel assignment and approved evaluation. It does not test student manuscript upload, actual panel evaluation submission, adviser recommendation, or the earlier title approval chain. The fixture's single panel actor tests downstream gating only; it is not evidence that real panel-size policy is satisfied.

The review test returns and then approves the same manuscript row. It verifies those available handler transitions; it does not claim that a revised attachment was uploaded between them. Repository checks target authenticated CRAD management views, not a public repository.

The grant suite checks the return result but does not prove a student revised attachments and resubmitted through the full student UI. The panel application's Scheduled/Completed request states do not themselves prove actual defense schedule creation or defense evaluation completion.

## Setup failures encountered and how they were separated

1. Normal `database/migrate.php` on new disposable `crad_qa_flow_20261014` failed: **1068 Multiple primary key defined**. No application source was fixed.
2. Test-only import into a second disposable schema encountered SMS2 additive statements referencing CRAD tables before those tables exist. A final fixture schema was prepared by deferring those statements until after CRAD import and skipping already-present columns/indexes. This is a documented fixture workaround, not proof that standard deployment succeeds. See `tests/helpers/prepare_flow_audit.php`.
3. Initial HTTP runs failed because sandboxed PHP could not write sessions to XAMPP's default `C:\xampp\tmp`. Those unauthenticated outputs were invalid workflow evidence. Server session and upload-temporary paths were moved into the workspace; reruns produced the results above. No application authentication change was made.
4. Panel-size test initially could not run because `crad_panelist_pool` was missing. Existing application schema helpers were invoked explicitly against the fixture PDO to prepare panel workflow tables. Results of its rerun are recorded separately below.

## Still unverified

- A single continuous group registration → department approval → adviser/coordinator confirmation → student title submission → adviser/coordinator/CRAD signed title approval journey.
- Actual chapter attachments → adviser evaluation/revision/resubmission → final-defense recommendation.
- Full defense creation and actual panel evaluation submissions, including revision compliance and final manuscript upload.
- End-to-end clearance, real image OCR, manual correction, persistence and clearance sign-offs.
- Report/export correctness, AI timeout/malformed-output behavior, notification delivery, concurrency and rollback failures.
- HostForge deployed commit, runtime configuration and production-specific behavior.

## Outcome

The completed flows above passed in a disposable local fixture environment. Whole-system E2E readiness remains **INCONCLUSIVE — REQUIRED TESTS ARE BLOCKED**; the full continuous lifecycle and HostForge were not verified. Standard migration and reminder schema mismatches need investigation, while the earlier confirmed receipt-parser failures still require fixes. No application code was changed during baseline flow testing.
