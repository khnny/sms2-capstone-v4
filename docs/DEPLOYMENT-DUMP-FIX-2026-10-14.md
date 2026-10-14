# Account-preserving deployment dump repair

Implemented for importing into an empty MySQL/MariaDB hosting database. The canonical files are `database/sms2_db.sql` and `modules/crad/database/crad_db.sql`; the root `sms2_db (1).sql` snapshot was not changed and must not be substituted.

## Changes

- SMS2 now owns 14 SMS2 tables, including reminders. CRAD owns 61 CRAD tables, including all panel application/request and reschedule-history tables.
- Fresh schema columns, keys and constraints are in their CREATE definitions. Removed the duplicate OTP primary key, cross-file CRAD ALTER statements and conflicting group-member CREATE. Member `research_stage` and its composite index are included.
- Preserved every source row in users (24), student profiles (4), roles (23), permissions (33) and settings (26), including original IDs, password hashes, status and account flags. Empty source authenticator/passkey tables remain present. Aggregate fingerprints in the test fixture verify full-row equality without containing individual account values.
- CRAD tables contain no demo seed records. OTPs, password resets/reset requests and login throttles begin empty.
- Manual-import SQL has no CREATE DATABASE, USE, GRANT or DEFINER directive. Both dumps use InnoDB and utf8mb4_unicode_ci. Dependencies are loaded with foreign-key checks temporarily disabled; the script restores them afterward.
- The migration runner resets the shared database only once for explicit `--fresh`, checks required columns and ordered indexes before adopting/skipping/recording a schema, and reports SQL file/statement/error codes without seeded values. Existing partial/old schemas fail validation instead of silently being recorded as current. This is not an automatic data-preserving upgrade for existing hosting databases.
- CI now runs the import/migration regression suite against MariaDB in addition to building the production image. Local Docker/remote CI execution was not available in this session.
- Test-account cleanup now removes synthetic student profiles before deleting its synthetic user, avoiding leftover test-only orphan profiles.

## Verification

`C:\xampp\php\php.exe tests/deployment_migration_test.php`: **69 checks PASS** on local MariaDB. Covers standalone imports, combined imports, exact account preservation, next user ID, no CRAD demo rows, normal migration, adoption of manual imports, repeat migration, one shared reset with --fresh, rejection of missing indexes/columns even for recorded migrations, and safe error reporting/restoration of FK checks on failed imports. Every test-owned migration database is removed by the test's exact-name cleanup.

HTTP regressions run against a separately imported disposable database, with outbound SMTP disabled and CAPTCHA off only in that fixture:

| Suite | Checks |
|---|---|
| Login and role-based navigation | PASS — 14/14 |
| Deadline reminders, deduplication and read state | PASS — 11/11 |
| Panel application/rejection/resubmission/completion and downloads | PASS — 23/23 |
| Manuscript review/final approval/publication/repository | PASS — 14/14 |
| Panel configuration and assignment limits | PASS — 6/6 |

All 68 HTTP assertions passed on a clean import after the synthetic-profile cleanup correction. Full-row fingerprints confirmed users, all four original profiles, roles and permissions unchanged after those flows; zero synthetic accounts remained. The final/publication suite seeds earlier title and defense prerequisites; this is not certification of the entire continuous research lifecycle. Existing users' passwords were not changed. PHP syntax checks and git whitespace checks passed. Actual hosting import and production runtime have not been executed.

## Preserved source-data limitation

Two of the four original student profiles reference user IDs absent from the source users table. All four profiles are retained exactly, rather than inventing users or silently assigning profiles to different accounts. One orphan has an exact student-ID match to another existing account; the other has no matching account. Linking or correcting these personal records remains a separate explicit decision. Thus account preservation passes, but complete profile referential integrity is not claimed.

## Import instructions

1. Select an empty hosting-provided database in phpMyAdmin or the hosting import tool.
2. Import `database/sms2_db.sql`.
3. Import `modules/crad/database/crad_db.sql` into that same selected database.
4. Configure SMS2 and CRAD connections to use that database and sign in with the preserved administrator account.

For CLI deployment use `php database/migrate.php`. Repeating that command validates and skips the existing schema. Do not run the old `database/install.php` role-seeding installer after importing these seeded files. Do not use `--fresh` on a populated hosting database: it intentionally replaces all data with the dump contents. A previously failed import may leave partial DDL; preserve any needed data and retry the corrected files on an empty database.

If encrypted authenticator records are exported later, keep their matching SMS2_APP_KEY. Passkeys can be domain-bound and may require re-enrollment on a new host. No application key was printed or embedded in the SQL files.
