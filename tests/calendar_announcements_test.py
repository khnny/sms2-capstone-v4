"""Calendar test: published announcements appear on the calendar, filtered by audience (P2.6).

Creates zz_test_ announcements and two zz_test_ accounts (CRAD officer and student), reads the calendar API
as each account, and removes everything it created when it finishes.
Usage: php -S 127.0.0.1:8080 -t .   then   python3 tests/calendar_announcements_test.py http://127.0.0.1:8080
The login captcha must be off in the test database.
"""
import http.cookiejar, json, re, sys, urllib.parse, urllib.request
from common_db import db, account_helper

BASE = sys.argv[1]
CAD, STU = 'zz_test_calcad', 'zz_test_calstu'
PW = 'TestPass!2026'
results = []

def check(label, cond):
    results.append(bool(cond))
    print(('PASS ' if cond else 'FAIL ') + label)

def login(user):
    jar = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    html = op.open(BASE + '/login/login.php', timeout=20).read().decode('utf-8', 'ignore')
    tok = re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)
    op.open(urllib.request.Request(BASE + '/login/login.php', data=urllib.parse.urlencode({'username': user, 'password': PW, 'csrf_token': tok}).encode()), timeout=20)
    return op

def calendar_titles(op, start, end):
    raw = op.open(BASE + f'/communication/calendar-events.php?start={start}&end={end}', timeout=20).read().decode('utf-8', 'ignore')
    data = json.loads(raw)
    events = data.get('events', []) if data.get('ok') else []
    return {e['title']: e for e in events if str(e.get('title', '')).startswith('zz_test_cal')}, data

def cleanup():
    db("DELETE FROM sms2_admin_announcements WHERE title LIKE 'zz_test_cal%'")
    account_helper('delete', CAD)
    account_helper('delete', STU)

cleanup()
account_helper('create', CAD, 'crad_officer', PW)
account_helper('create', STU, 'student', PW)
try:
    today = db("SELECT DATE_FORMAT(CURDATE(), '%Y-%m-%d')")
    db("INSERT INTO sms2_admin_announcements (title, body, status, audience, category, published_at) VALUES "
       "('zz_test_cal_all', 'For everyone', 'published', 'all', 'General', NOW()),"
       "('zz_test_cal_student', 'For students', 'published', 'student', 'General', NOW()),"
       "('zz_test_cal_crad', 'For CRAD only', 'published', 'crad_officer', 'General', NOW()),"
       "('zz_test_cal_draft', 'Not published', 'draft', 'all', 'General', NOW())")
    start = db(f"SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '%Y-%m-%d')")
    end = db(f"SELECT DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '%Y-%m-%d')")

    cad = login(CAD)
    seen, data = calendar_titles(cad, start, end)
    check('calendar API responds ok for the CRAD officer', data.get('ok') is True)
    check('CRAD officer sees all three published announcements', all(t in seen for t in ('zz_test_cal_all', 'zz_test_cal_student', 'zz_test_cal_crad')))
    check('draft announcement is not on the calendar', 'zz_test_cal_draft' not in seen)
    if seen:
        sample = next(iter(seen.values()))
        check('announcement events are typed Announcement', sample.get('type') == 'Announcement')

    stu = login(STU)
    seen_s, data_s = calendar_titles(stu, start, end)
    check('calendar API responds ok for a student', data_s.get('ok') is True)
    check('student sees the "all" announcement', 'zz_test_cal_all' in seen_s)
    check('student sees the student-audience announcement', 'zz_test_cal_student' in seen_s)
    check('student does NOT see the CRAD-only announcement', 'zz_test_cal_crad' not in seen_s)

    page = cad.open(BASE + '/communication/calendar.php', timeout=20).read().decode('utf-8', 'ignore')
    check('calendar type filter offers Announcement', '<option>Announcement</option>' in page)
finally:
    cleanup()

print('CALENDAR ANNOUNCEMENTS RESULT:', 'PASS' if all(results) else 'FAIL', f'({sum(results)}/{len(results)})')
