# Database dumps versus current code — 14 October 2026

**Verdict: PARTIALLY ALIGNED. The two deployment dumps are not a reliable complete fresh-install schema for the current codebase.**

Audit used the CRAD QA skill and current local source at commit `d5e05f6`. No application code or dump was edited. No existing application databases were changed. Imports were executed only into newly created, explicitly named `crad_qa_schema_*` / `crad_qa_dump_*` diagnostic databases. Local MariaDB results do not establish the deployed HostForge database state.

## Sources checked

- `database/sms2_db.sql`: deployment SMS2 dump, 15 CREATE TABLE definitions (13 SMS2 plus 2 CRAD).
- `modules/crad/database/crad_db.sql`: deployment CRAD dump, 53 CREATE TABLE definitions.
- `sms2_db (1).sql`: additional root snapshot, 66 table definitions; compared structurally, not imported.
- Current application SQL, runtime schema helpers, table map, deployment migration runner and Docker packaging.

`database/migrate.php` applies the first two files into the **same configured database**, SMS2 first. They have two overlapping CREATE definitions, so the canonical pair describes 66 distinct tables. The root snapshot is not selected by this runner and is excluded from Docker by `.dockerignore`.

## Executed checks

| ID | Procedure | Expected | Observed | Status |
|---|---|---|---|---|
| DB-001 | Run normal `database/migrate.php` against new `crad_qa_schema_20261014_normal` | Complete both schemas | Stops with SQLSTATE 42000 / error 1068, Multiple primary key defined | FAIL |
| DB-002 | Import SMS2 statements in order into new isolated DB, continue after errors for diagnosis | No SQL errors | 18 errors: duplicate OTP primary key and 17 ALTER statements against absent CRAD tables | FAIL |
| DB-003 | Import CRAD statements into a different empty isolated DB | No SQL errors | Zero errors, 53 tables | PASS (SQL import only) |
| DB-004 | Import SMS2 then CRAD, continuing after errors for diagnosis | Complete schema matching current features | 18 errors, 66 resulting tables, missing member research_stage column | FAIL |
| DB-005 | Compare current feature table references/constant table names with dump CREATE definitions | Required tables included in deployment schema | 9 required tables absent; runtime helper definitions located | FAIL (dump completeness) |
| DB-006 | Compare extra root snapshot columns against combined diagnostic schema | Identify whether interchangeable | Three tables have column differences | FAIL (equivalence) |

Diagnostic continuation in DB-002/004 deliberately differs from normal deployment, which stops on the first exception. It exposes later problems; it does not mean a partially imported database is usable or that data integrity passed. No errors were suppressed or repaired in the diagnostic imports. All statements and their indexes are identified in `import-diagnostics.json`; data values are omitted.

## Confirmed mismatches

### 1. High — duplicate primary key stops the SMS2 migration

`database/sms2_db.sql:253` creates `sms2_security_otps` with PRIMARY KEY(id), already present at line 262. The later ALTER at line 535 adds the same primary key again. Error 1068 was reproduced both through the normal migration runner and isolated statement execution.

Impact: normal fresh deployment cannot reach the CRAD import. Recommendation: define this primary key once; validate on an empty database afterward. Do not treat SQL comments advising users to ignore errors as effective exception handling.

### 2. High — SMS2 dump contains CRAD alterations before CRAD tables exist

`database/sms2_db.sql:734` onward contains 17 ALTER statements for:

- `crad_research_groups`: group flow, completeness, membership counts, submission and department decision columns (11 statements).
- `crad_research_adviser_assignments`: confirmation status/time/actor (3).
- `crad_research_coordinator_assignments`: confirmation status/time/actor (3).

These target tables are created by the CRAD dump, which the runner loads afterward (`database/migrate-lib.php:301` onward). With diagnostic continuation, all 17 produce error 1146. The CRAD dump later adds the corresponding columns successfully, but that cannot rescue the normal runner because it already stopped.

Recommendation: keep CRAD-owned additive changes after the relevant CRAD CREATE definitions, remove redundant cross-file changes from the fresh schema, and use explicit versioned upgrades for existing installations. Reversing the entire file order alone does not fix the duplicate primary key or later duplicate-column issues.

### 3. High — combined import silently loses member stage isolation

First definition: `database/sms2_db.sql:691`, `crad_research_group_members` without `research_stage`.

Later definition: `modules/crad/database/crad_db.sql:2205`, CREATE TABLE IF NOT EXISTS with `research_stage` and `idx_rgm_group_stage`. Because the table already exists, this statement does not update its columns or indexes. The ALTER instructions that could add stage isolation at lines 2228–2234 are commented out.

Confirmed in imported metadata: CRAD-only import includes `research_stage`; SMS2-first combined import does not. This is an order-dependent schema difference.

Current code explicitly checks for the column in `rcpMemberStageScoped()` (`modules/crad/includes/research-clearance-payment.php:165`). Its stage-aware path scopes individual receipt references to Research 1/Research 2; its legacy fallback uses the group roster without stage filtering. Therefore the missing column does **not** imply every receipt request crashes. It means the deployment lacks the intended stage-specific member schema and can use legacy behavior. Stage cross-contamination was not executed in this audit. The earlier reminder test's Unknown column error was in its fixture setup, not proof that reminder delivery itself fails.

Recommendation: use one canonical member definition and a tested additive upgrade for the stage column/index. Preserve existing rows and verify per-stage OR-number behavior before deployment.

### 4. Medium — 9 current feature tables missing from both canonical dumps

| Missing table | Current source / bootstrap | Feature |
|---|---|---|
| `crad_panelist_applications` | `modules/crad/includes/panelist-workflow.php:6` | Panelist recruitment |
| `crad_panel_application_documents` | Same helper, line 27 | Panelist documents |
| `crad_panel_application_history` | Same helper, line 40 | Panelist review history |
| `crad_panelist_pool` | Same helper, line 52 | Approved panelist pool |
| `crad_defense_panel_requests` | `modules/crad/includes/panel-applications.php:12`, schema helper line 41 | Group panel request |
| `crad_defense_panel_request_history` | Same helper, constant line 13 | Group panel request history |
| `crad_defense_panel_request_documents` | Same helper, constant line 14 | Group panel request attachments |
| `crad_research_defense_schedule_history` | `modules/crad/includes/defense-reschedule.php:13` | Reschedule history |
| `sms2_deadline_reminders` | `includes/deadline-reminders.php:29` | In-app/email reminder tracking |

Application helpers create these at runtime. For example, panelist application and panel configuration pages call their schema helper, and reminder generation invokes `smsReminderSchema()`. This is deployment-schema drift, not a claim that all nine features are currently broken. A fresh runtime needs CREATE privileges and successful helper execution before these tables are available. Static table-map presence is not proof of actual table creation.

Recommendation: include current definitions in the appropriate deployment schema and provide upgrades, rather than relying exclusively on the first feature request. Include the constant-based table names in any automated schema completeness check.

### 5. Medium — the root snapshot is not equivalent to the deployment pair

`sms2_db (1).sql` has the same 66 table names as the combined diagnostic schema, but:

| Table | Root snapshot | Canonical combined import |
|---|---|---|
| `crad_research_group_members` | Has `research_stage` | Missing due to earlier CREATE |
| `crad_panel_member_availability` | Missing `availability_windows_json` | Has it |
| `sms2_admin_announcements` | Missing `category`, `expires_at`, `scheduled_for` | Has them |

This comparison covers column presence, not complete equivalence of indexes, default values, seed records or types. Do not substitute the root snapshot as a complete fix; it has different gaps and also omits the nine runtime feature tables.

### 6. Medium — migration bookkeeping can skip schema drift

`database/migrate-lib.php:225` onward skips an already recorded migration. It also records a migration as applied when all expected table names exist, without validating every column/index. Therefore a populated installation can appear migrated while missing newer columns. This is confirmed source behavior; an actual HostForge false-positive state was not tested.

Recommendation: use separate versioned schema upgrades and validate required columns/indexes, rather than table existence alone. Do not force-reapply a populated SQL dump to production as an upgrade strategy.

## Alignment that was verified

- CRAD-only SQL imports without errors on the local server, including its additive group/assignment and availability columns.
- Both dumps and current configuration use prefixed `sms2_*` / `crad_*` names in a shared physical database architecture.
- Canonical CRAD dump includes `uploaded_blob`, `uploaded_mime`, and `uploaded_size` for payment and clearance rows, consistent with the durable-upload migration helper.
- Most scanned literal INSERT column lists matched columns in the continued combined import. The confirmed member-stage difference is listed above.

## Scan limits and rejected candidates

Structural scanning is a review aid, not a complete PHP/SQL parser. Dynamic table interpolation, generated field lists, conditional SQL, enum semantics, ownership, transactions and business rules need targeted review/runtime tests. Qualified-name candidates that turned out to be JavaScript properties (for example `match_score`, `student_id`, `x`, `y`, `status`) were not reported as database defects. `crad_db` in file-view comments is a database name, not a missing table. Some CREATE definitions in code are unreachable legacy code after early returns; they were not treated as executed migrations.

No live HostForge schema, existing user records, passwords, SMTP secrets, API keys or private documents were read. Root SQL snapshot data was not printed. Existing deployment data compatibility, all FK/enum/index semantics and full application workflows were not certified by this scan.

## Artifacts and next work

- `structural-scan.json`: dump hashes, definitions, duplicate CREATE evidence, table-reference candidates, column candidates and root comparison.
- `import-diagnostics.json`: actual SQL failure codes and resulting columns/indexes for each isolated run.
- `tools/audit-dump-alignment.py`: read-only structural scanner.
- `tools/audit-dump-import.php`: CLI diagnostic importer; creates only new audit databases and continues after errors for evidence.

No fixes were applied. Priority is to repair fresh-install ordering/duplicate primary key, reconcile member stage schema, then include current feature tables and implement safe upgrades. After changes, validate normal migration on an empty database and upgrades on a disposable copy of an existing schema, then rerun the affected flows.

**NOT READY — CRITICAL OR HIGH DEFECTS REMAIN** for using these files as the complete fresh-deployment schema. Existing running installations may have compensating runtime-created tables or manual changes; their actual alignment remains unverified.
