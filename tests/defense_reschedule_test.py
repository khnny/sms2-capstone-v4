"""Defense reschedule and history test (To-Do P1.4). Uses zz_test_ schedules and one zz_test_ CRAD officer, removed at the end.

Checks: a valid reschedule saves and writes a history row with the old and new values; a venue already in use,
a group already booked at that time, a missing reason, an end before the start, and a past time are all refused;
the history shows the reason; no schedule is deleted.
Usage: php -S 127.0.0.1:8080 -t .   then   python3 tests/defense_reschedule_test.py http://127.0.0.1:8080
"""
import http.cookiejar, re, sys, urllib.parse, urllib.request
from common_db import db, account_helper

BASE = sys.argv[1]
USER, PW = 'zz_test_rsc_cad', 'TestPass!2026'
PANEL = 'zz_test_rsc_panel'
PAGE = '/modules/crad/pages/research-defense-scheduling.php'
GA, GB, GC = 987701, 987702, 987703
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

def page(op, extra=''):
    return op.open(BASE + PAGE + extra, timeout=30).read().decode('utf-8', 'ignore')

def reschedule(op, sid, date, start, end, venue, reason):
    html = page(op)
    tok = re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)
    body = urllib.parse.urlencode({'csrf_token': tok, 'defense_action': 'reschedule', 'schedule_id': sid,
                                   'new_date': date, 'new_start': start, 'new_end': end, 'new_venue': venue, 'reason': reason}).encode()
    return op.open(urllib.request.Request(BASE + PAGE, data=body), timeout=30).read().decode('utf-8', 'ignore')

def row(sid):
    return db(f"SELECT CONCAT(defense_datetime, '|', defense_end_datetime, '|', venue) FROM crad_research_defense_schedules WHERE id = {sid}")

def schedule(gid, number, when_start, when_end, venue):
    db(f"INSERT INTO crad_research_defense_schedules (research_group_id, group_number, research_group, research_title, adviser_name, panel_chair, panel_members, venue, defense_datetime, defense_end_datetime, defense_type, status) "
       f"VALUES ({gid}, '{number}', '{number}_grp', '{number} title', 'Test Adviser', 'Test Chair', '', '{venue}', '{when_start}', '{when_end}', 'Final Defense', 'Selected')")
    return int(db(f"SELECT id FROM crad_research_defense_schedules WHERE research_group_id = {gid} ORDER BY id DESC LIMIT 1"))

def cleanup():
    try:
        db("DELETE FROM crad_research_defense_schedule_history WHERE group_number LIKE 'zz_test_rsc%'")
    except Exception:
        pass
    try:
        db("DELETE FROM crad_panel_member_availability WHERE panel_user_id IN (SELECT id FROM sms2_users WHERE username = 'zz_test_rsc_panel')")
    except Exception:
        pass
    try:
        account_helper('delete', PANEL)
    except Exception:
        pass
    db(f"DELETE FROM crad_research_defense_schedules WHERE research_group_id IN ({GA}, {GB}, {GC})")
    account_helper('delete', USER)

cleanup()
account_helper('create', USER, 'crad_officer', PW)
try:
    sb = schedule(GB, 'zz_test_rsc_B', '2031-03-10 09:00:00', '2031-03-10 10:00:00', 'zz Room 101')
    sa = schedule(GA, 'zz_test_rsc_A', '2031-03-11 09:00:00', '2031-03-11 10:00:00', 'zz Room 202')
    sc = schedule(GA, 'zz_test_rsc_A', '2031-03-13 13:00:00', '2031-03-13 14:00:00', 'zz Room 404')  # same group as A
    op = login()

    out = reschedule(op, sa, '2031-03-12', '14:00', '15:00', 'zz Room 303', 'Panel asked for a new slot')
    check('a valid reschedule is saved', 'Defense rescheduled' in out, note='no success message')
    check('the new date, time, and venue are stored', row(sa) == '2031-03-12 14:00:00|2031-03-12 15:00:00|zz Room 303', note=row(sa))
    hist = int(db(f"SELECT COUNT(*) FROM crad_research_defense_schedule_history WHERE schedule_id = {sa}") or 0)
    old_venue = db(f"SELECT old_venue FROM crad_research_defense_schedule_history WHERE schedule_id = {sa}")
    check('one history row records the old venue', hist == 1 and old_venue == 'zz Room 202', note=f'rows={hist} old={old_venue}')
    check('the history table shows the reason', 'Panel asked for a new slot' in page(op))

    out = reschedule(op, sa, '2031-03-10', '09:30', '10:30', 'zz Room 101', 'Test venue clash')
    check('a venue already booked at that time is refused', 'already booked' in out and 'zz_test_rsc_B' in out)
    out = reschedule(op, sa, '2031-03-13', '13:30', '14:30', 'zz Room 505', 'Test group clash')
    check('the same group cannot have two defenses at once', 'another defense at that time' in out)
    out = reschedule(op, sa, '2031-03-12', '16:00', '15:00', 'zz Room 303', 'End before start')
    check('an end time before the start time is refused', 'end time must be after' in out)
    out = reschedule(op, sa, '2031-03-12', '16:00', '17:00', 'zz Room 303', 'bad')
    check('a reason shorter than 5 characters is refused', 'at least 5' in out)
    out = reschedule(op, sa, '2020-01-05', '09:00', '10:00', 'zz Room 303', 'Past date test')
    check('a past date is refused', 'in the future' in out)
    check('refused attempts did not change the schedule', row(sa) == '2031-03-12 14:00:00|2031-03-12 15:00:00|zz Room 303', note=row(sa))
    # Panelist availability (P1.4): a panelist with saved windows must be free at the new time.
    account_helper('create', PANEL, 'panel', PW)
    pid = int(db(f"SELECT id FROM sms2_users WHERE username = '{PANEL}'"))
    db(f"UPDATE sms2_users SET full_name = 'zz Test Panel Rsc' WHERE id = {pid}")
    db(f"INSERT INTO crad_panel_member_availability (panel_user_id, availability_status, availability_windows_json) VALUES ({pid}, 'Available', '{{\"Monday\":[{{\"start\":\"09:00\",\"end\":\"12:00\"}}]}}')")
    db(f"UPDATE crad_research_defense_schedules SET panel_members = 'zz Test Panel Rsc' WHERE id = {sa}")
    out = reschedule(op, sa, '2031-03-17', '13:00', '14:00', 'zz Room 303', 'Panel time test')
    check('a panelist outside their saved time is refused', 'not available at that time' in out and 'zz Test Panel Rsc' in out)
    out = reschedule(op, sa, '2031-03-17', '10:00', '11:00', 'zz Room 303', 'Panel time test')
    check('a time inside the panelist window is accepted', 'Defense rescheduled' in out)
    db(f"UPDATE crad_panel_member_availability SET availability_status = 'Unavailable' WHERE panel_user_id = {pid}")
    out = reschedule(op, sa, '2031-03-17', '09:30', '10:30', 'zz Room 404', 'Panel unavailable test')
    check('a panelist marked Unavailable blocks any time', 'not available at that time' in out)
    check('no schedule was deleted', all(int(db(f"SELECT COUNT(*) FROM crad_research_defense_schedules WHERE id = {x}") or 0) == 1 for x in (sa, sb, sc)))
finally:
    cleanup()

print('DEFENSE RESCHEDULE RESULT:', 'PASS' if all(results) else 'FAIL', f'({sum(results)}/{len(results)})')
