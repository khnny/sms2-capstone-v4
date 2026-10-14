"""Panelist availability and notification test (To-Do P1.3). Uses one zz_test_ panel account, removed at the end.

Checks: a panelist saves available days and time ranges, the saved values are shown back, bad input is refused,
and a panel notification for that panelist shows on the dashboard.
Usage: php -S 127.0.0.1:8080 -t .   then   python3 tests/panelist_availability_test.py http://127.0.0.1:8080
"""
import http.cookiejar, re, sys, urllib.parse, urllib.request
from common_db import db, account_helper

BASE = sys.argv[1]
USER, PW = 'zz_test_pav_panel', 'TestPass!2026'
NOTICE = 'zz_test_pav_notice_title'
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

def page(op, path):
    r = op.open(BASE + path, timeout=30)
    return r.read().decode('utf-8', 'ignore')

def csrf(html):
    return re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)

def post(op, path, fields):
    body = urllib.parse.urlencode(fields, doseq=True).encode()
    return op.open(urllib.request.Request(BASE + path, data=body), timeout=30).read().decode('utf-8', 'ignore')

def user_id():
    return int(db(f"SELECT id FROM sms2_users WHERE username = '{USER}'"))

def cleanup():
    try:
        uid = user_id()
        db(f"DELETE FROM crad_panel_member_availability WHERE panel_user_id = {uid}")
        db(f"DELETE FROM crad_panel_assignment_notifications WHERE recipient_user_id = {uid}")
    except Exception:
        pass
    db(f"DELETE FROM crad_panel_assignment_notifications WHERE title = '{NOTICE}'")
    account_helper('delete', USER)

AV = '/modules/faculty/pages/availability.php'
cleanup()
account_helper('create', USER, 'panel', PW)
try:
    uid = user_id()
    op = login()
    html = page(op, AV)
    check('the availability page opens for a panelist', 'availability_days' in html and 'availability_status' in html)
    tok = csrf(html)

    base = {'panel_action': 'update_availability', 'csrf_token': tok, 'availability_status': 'Available'}
    bad_order = dict(base, **{'availability_days[]': ['Monday'], 'availability_start[Monday]': '12:00', 'availability_end[Monday]': '09:00'})
    out = post(op, AV, bad_order)
    check('an end time before the start time is refused', 'valid start and end time' in out)
    no_days = dict(base)
    out = post(op, AV, no_days)
    check('Available with no day and time range is refused', 'at least one weekly day' in out)

    good = dict(base, **{'availability_days[]': ['Monday', 'Wednesday'], 'availability_start[Monday]': '09:00', 'availability_end[Monday]': '12:00', 'availability_start[Wednesday]': '13:00', 'availability_end[Wednesday]': '15:00'})
    out = post(op, AV, good)
    check('valid days and time ranges are saved', 'weekly time windows saved' in out)
    stored = db(f"SELECT availability_status FROM crad_panel_member_availability WHERE panel_user_id = {uid}")
    windows = db(f"SELECT availability_windows_json FROM crad_panel_member_availability WHERE panel_user_id = {uid}")
    check('the database holds Available and both days', stored == 'Available' and '"Monday"' in windows and '"Wednesday"' in windows, note=f'status={stored} windows={windows}')

    html2 = page(op, AV)
    check('the saved time range shows back on the page', '09:00' in html2 and '15:00' in html2)

    db(f"INSERT INTO crad_panel_assignment_notifications (event_key, recipient_user_id, recipient_role, recipient_email, panel_assignment_id, research_group_id, title, body, url, is_read) "
       f"VALUES ('zz_test_pav_{uid}', {uid}, 'panel', 'zz_test_pav@example.test', NULL, NULL, '{NOTICE}', 'Test body', '/', 0)")
    dash = page(op, '/dashboard/index.php')
    check('a panel notification for this panelist shows on the dashboard', NOTICE in dash)
finally:
    cleanup()

print('PANELIST AVAILABILITY RESULT:', 'PASS' if all(results) else 'FAIL', f'({sum(results)}/{len(results)})')
