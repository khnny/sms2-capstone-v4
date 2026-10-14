# Tests

These tests run against a local test database. They create and delete their own accounts, all named `zz_test_*`. Real accounts are never read or changed by them.

## Before you run

- Use a local copy of the database, not the one your team uses.
- Turn off the login captcha in the test database (System Settings).
- Run from the project root.

## PHP tests (no web server needed)

    php tests/p0_app_key_test.php        P0: Authenticator survives a password change
    php tests/otp_ttl_test.php           P2: OTP expires after 5 minutes

## HTTP tests (web server needed)

Start the app first:

    php -S 127.0.0.1:8080 -t .

Then, in a second terminal, from the `tests` folder:

    python3 p0_authenticator_http_test.py http://127.0.0.1:8080     P0 full flow: enable, change password, logout, login, verify
    python3 bulk_status_http_test.py http://127.0.0.1:8080          P2: bulk archive and restore, permission checks
    python3 dashboard_scorecards_test.py http://127.0.0.1:8080     P2: CRAD dashboard scorecards match the database
    python3 calendar_announcements_test.py http://127.0.0.1:8080  P2: announcements on the calendar, by audience
    python3 deadline_reminders_test.py http://127.0.0.1:8080      P2: deadline reminders for students and advisers
    python3 rbac_separation_test.py http://127.0.0.1:8080         P2: each role reaches only its own pages
    python3 grants_approval_test.py http://127.0.0.1:8080        P3: grant approval chain, returns, and legacy roles 
    python3 panel_size_test.py http://127.0.0.1:8080             P1: panel size rules (min and max panelists)
    python3 defense_schedule_list_test.py http://127.0.0.1:8080  P1.4: defense list shows title-path schedules and keeps them
    python3 panelist_availability_test.py http://127.0.0.1:8080 P1.3: panelist days and times, validation, and notification
    python3 defense_reschedule_test.py http://127.0.0.1:8080       P1.4: reschedule, conflicts, validation, and history
    python3 panel_application_test.py http://127.0.0.1:8080        P1.1: panel application flow, review, downloads
    python3 chapter_sequence_test.py http://127.0.0.1:8080        P3.11: chapters are submitted in order
    python3 account_deactivation_test.py http://127.0.0.1:8080  P2.10: deactivated accounts lose access
    python3 rd_conflict_test.py                                  P1.3: Research Director availability and conflict checks

The P0 HTTP test takes about one minute because it waits for the next Authenticator code window.

If the database name or credentials differ, set `SMS2_TEST_DB`, `SMS2_TEST_DB_USER`, and `SMS2_TEST_DB_PASSWORD` before running.

## Helper

`tests/helpers/test_accounts.php` creates, deletes, and manages `zz_test_*` accounts only. It refuses any other username.
