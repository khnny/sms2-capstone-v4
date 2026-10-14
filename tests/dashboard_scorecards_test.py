"""CRAD dashboard scorecards test: Total, Pending, and Approved Applications, plus Scheduled and Completed Defenses.

Inserts four zz_test_ title rows with different statuses, reads the CRAD officer dashboard over HTTP,
and compares the card values with counts computed directly from the database.
Test rows and the test account are deleted at the end. Real data is not changed.
Usage: php -S 127.0.0.1:8080 -t .   then   python3 tests/dashboard_scorecards_test.py http://127.0.0.1:8080
"""
import http.cookiejar, re, sys, urllib.parse, urllib.request
from common_db import db, account_helper

BASE = sys.argv[1]
USER, PW = 'zz_test_dashcad', 'TestPass!2026'
results = []

def check(label, cond):
    results.append(bool(cond))
    print(('PASS ' if cond else 'FAIL ') + label)

INSERT = """INSERT INTO crad_title_approvals
 (student_id, student_name, submission_date, department, proposed_title, discipline_cluster, primary_sdg, research_agenda, sdg_justification,
  members_json, adviser_name, adviser_email, coordinator_name, status, coordinator_status, crad_status,
  adviser_signature_data, coordinator_signature_data, crad_signature_data)
VALUES
 ('zz', 'Test A', NOW(), 'Test', 'zz_test_pending_plain', 'c', 's', 'a', 'test justification', '[]', 'x', 'x@example.test', 'y', 'Pending', 'Not Ready', 'Not Ready', NULL, NULL, NULL),
 ('zz', 'Test B', NOW(), 'Test', 'zz_test_approved_full', 'c', 's', 'a', 'test justification', '[]', 'x', 'x@example.test', 'y', 'Approved', 'Approved', 'Approved', 'sig', 'sig', 'sig'),
 ('zz', 'Test C', NOW(), 'Test', 'zz_test_pending_crad', 'c', 's', 'a', 'test justification', '[]', 'x', 'x@example.test', 'y', 'Approved', 'Approved', 'Pending', 'sig', 'sig', NULL),
 ('zz', 'Test D', NOW(), 'Test', 'zz_test_returned', 'c', 's', 'a', 'test justification', '[]', 'x', 'x@example.test', 'y', 'Returned', 'Not Ready', 'Not Ready', NULL, NULL, NULL)"""

DEFENSE_INSERT = """INSERT INTO crad_research_defense_schedules
 (group_number, research_group, research_title, research_group_id, defense_type, status, defense_datetime)
VALUES
 ('zz_test_g1', 'zz_test', 'zz_test_defense_upcoming', 990001, 'Pre-Oral', 'Finalized', NOW() + INTERVAL 7 DAY),
 ('zz_test_g2', 'zz_test', 'zz_test_defense_completed', 990002, 'Pre-Oral', 'Finalized', NOW() - INTERVAL 7 DAY),
 ('zz_test_g3', 'zz_test', 'zz_test_defense_draft', 990003, 'Pre-Oral', 'Proposed', NOW() - INTERVAL 7 DAY)"""

APPROVED = ("status = 'Approved' AND coordinator_status = 'Approved' AND crad_status = 'Approved' "
            "AND adviser_signature_data IS NOT NULL AND adviser_signature_data <> '' "
            "AND coordinator_signature_data IS NOT NULL AND coordinator_signature_data <> '' "
            "AND crad_signature_data IS NOT NULL AND crad_signature_data <> ''")

def card_value(html, label):
    """Finds the number shown next to a card label, whether it comes before or after the label."""
    for m in re.finditer(re.escape(label), html):
        before = html[max(0, m.start() - 500):m.start()]
        after = html[m.end():m.end() + 500]
        for window in (after, before):
            n = re.search(r'>\s*(\d+)\s*<', window)
            if n:
                return int(n.group(1))
    return None

def login(user):
    jar = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    html = op.open(BASE + '/login/login.php', timeout=20).read().decode('utf-8', 'ignore')
    tok = re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)
    op.open(urllib.request.Request(BASE + '/login/login.php', data=urllib.parse.urlencode({'username': user, 'password': PW, 'csrf_token': tok}).encode()), timeout=20)
    return op

account_helper('create', USER, 'crad_officer', PW)
db("DELETE FROM crad_title_approvals WHERE proposed_title LIKE 'zz_test_%'")
db("DELETE FROM crad_research_defense_schedules WHERE research_title LIKE 'zz_test_%'")
try:
    base_total = int(db("SELECT COUNT(*) FROM crad_title_approvals WHERE proposed_title NOT LIKE 'zz_test_%'"))
    base_pending = int(db("SELECT COUNT(*) FROM crad_title_approvals WHERE proposed_title NOT LIKE 'zz_test_%' AND status NOT IN ('Returned','Rejected') AND NOT (" + APPROVED + ")"))
    base_approved = int(db("SELECT COUNT(*) FROM crad_title_approvals WHERE proposed_title NOT LIKE 'zz_test_%' AND " + APPROVED))
    base_upcoming = int(db("SELECT COUNT(*) FROM crad_research_defense_schedules WHERE research_title NOT LIKE 'zz_test_%' AND defense_datetime >= NOW() AND LOWER(status) IN ('scheduled','finalized')"))
    base_completed = int(db("SELECT COUNT(*) FROM crad_research_defense_schedules WHERE research_title NOT LIKE 'zz_test_%' AND defense_datetime < NOW() AND LOWER(status) IN ('scheduled','finalized','completed','passed')"))
    db(INSERT)
    db(DEFENSE_INSERT)
    exp_upcoming = base_upcoming + 1
    exp_completed = base_completed + 1
    exp_total = int(db("SELECT COUNT(*) FROM crad_title_approvals WHERE proposed_title LIKE 'zz_test_%'")) + base_total
    exp_approved = base_approved + int(db("SELECT COUNT(*) FROM crad_title_approvals WHERE proposed_title='zz_test_approved_full'"))
    exp_pending = base_pending + int(db("SELECT COUNT(*) FROM crad_title_approvals WHERE proposed_title IN ('zz_test_pending_plain','zz_test_pending_crad')"))
    print(f'expected from database: total={exp_total} pending={exp_pending} approved={exp_approved}')

    op = login(USER)
    html = op.open(BASE + '/dashboard/index.php', timeout=20).read().decode('utf-8', 'ignore')
    got_total = card_value(html, 'Total Applications')
    got_pending = card_value(html, 'Pending Applications')
    got_approved = card_value(html, 'Approved Applications')
    got_upcoming = card_value(html, 'Scheduled Defenses')
    got_completed = card_value(html, 'Completed Defenses')
    print(f'dashboard shows: total={got_total} pending={got_pending} approved={got_approved}')
    check('Total Applications matches the database', got_total == exp_total)
    check('Pending Applications matches the database (returned row excluded, partly approved row included)', got_pending == exp_pending)
    check('Approved Applications matches the official approved rule', got_approved == exp_approved)
    check('Scheduled Defenses counts only upcoming confirmed defenses', got_upcoming == exp_upcoming)
    check('Completed Defenses counts confirmed defenses whose date has passed', got_completed == exp_completed)
finally:
    db("DELETE FROM crad_title_approvals WHERE proposed_title LIKE 'zz_test_%'")
    db("DELETE FROM crad_research_defense_schedules WHERE research_title LIKE 'zz_test_%'")
    account_helper('delete', USER)

print('DASHBOARD SCORECARDS RESULT:', 'PASS' if all(results) else 'FAIL', f'({sum(results)}/{len(results)})')
