"""Defense schedule listing test (To-Do P1.4, part 1). Uses zz_test_ rows and one zz_test_ CRAD officer, removed at the end.

Checks: a schedule for a title-path group (no legacy proposal row) shows on the CRAD defense scheduling page,
and loading the pages does not delete the schedule.
Usage: php -S 127.0.0.1:8080 -t .   then   python3 tests/defense_schedule_list_test.py http://127.0.0.1:8080
"""
import http.cookiejar, re, sys, urllib.parse, urllib.request
from common_db import db, account_helper

BASE = sys.argv[1]
USER, PW = 'zz_test_rds_cad', 'TestPass!2026'
TITLE = 'zz_test_rds_title'
GROUP_ID = 987654
results = []

def check(label, cond, note=''):
    results.append(bool(cond))
    print(('PASS ' if cond else 'FAIL ') + label + (f'  [{note}]' if note and not cond else ''))

def login():
    jar = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    html = op.open(BASE + '/login/login.php', timeout=20).read().decode('utf-8', 'ignore')
    tok = re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)
    op.open(urllib.request.Request(BASE + '/login/login.php', data=urllib.parse.urlencode({'username': USER, 'password': PW, 'csrf_token': tok}).encode()), timeout=20)
    return op

def get(op, path):
    r = op.open(BASE + path, timeout=30)
    return r.getcode(), r.read().decode('utf-8', 'ignore')

def schedule_count():
    return int(db(f"SELECT COUNT(*) FROM crad_research_defense_schedules WHERE research_group_id = {GROUP_ID}") or 0)

def cleanup():
    db(f"DELETE FROM crad_research_defense_schedules WHERE research_group_id = {GROUP_ID}")
    account_helper('delete', USER)

cleanup()
account_helper('create', USER, 'crad_officer', PW)
try:
    db(f"INSERT INTO crad_research_defense_schedules (research_group_id, proposal_id, proposal_number, group_number, research_group, research_title, adviser_name, panel_chair, venue, defense_datetime, defense_type, status) "
       f"VALUES ({GROUP_ID}, NULL, NULL, 'zz_test_rds', 'zz_test_rds_grp', '{TITLE}', 'Test Adviser', 'Test Chair', 'Room 1', NOW() + INTERVAL 7 DAY, 'Final Defense', 'Selected')")
    check('the title-path schedule exists before the page loads', schedule_count() == 1)

    import json
    op = login()
    code, html = get(op, '/modules/crad/pages/research-defense-scheduling.php')
    check('the defense scheduling page loads for the CRAD officer', code == 200, note=f'status {code}')
    # The screen fills its list from ?ajax=defense-schedules, so test that same data.
    code_j, body = get(op, '/modules/crad/pages/research-defense-scheduling.php?ajax=defense-schedules')
    data = json.loads(body) if code_j == 200 and body.strip().startswith('{') else {'rows': []}
    refs = [r.get('reference', '') for r in data.get('rows', [])]
    check('the title-path schedule is in the list the screen shows', 'zz_test_rds' in refs, note=f'rows returned: {refs}')
    check('the schedule is still there after the list loads', schedule_count() == 1, note=f'rows {schedule_count()}')

    code2, _ = get(op, '/modules/crad/pages/adviser-panel-assignment.php')
    check('loading the adviser assignment page does not delete the schedule', schedule_count() == 1, note=f'rows {schedule_count()} (page status {code2})')
finally:
    cleanup()

print('DEFENSE SCHEDULE LIST RESULT:', 'PASS' if all(results) else 'FAIL', f'({sum(results)}/{len(results)})')
