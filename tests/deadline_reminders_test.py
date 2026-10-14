"""Deadline reminders test (To-Do P2.6). Uses zz_test_ accounts and zz_test_ CRAD rows, and removes them at the end.

What it checks:
  - a plan due in 3 days creates a 3-day reminder for the group's student and adviser
  - a plan due in 10 days creates no reminder
  - running the daily job twice creates no duplicates
  - the student sees the reminder banner on their portal page and can mark it as read
  - the adviser sees the reminder banner on their workspace page
Usage: php -S 127.0.0.1:8080 -t .   then   python3 tests/deadline_reminders_test.py http://127.0.0.1:8080
The login captcha must be off in the test database.
"""
import http.cookiejar, json, re, subprocess, sys, urllib.parse, urllib.request
from common_db import ROOT, db, account_helper

BASE = sys.argv[1]
STU, ADV = 'zz_test_rem_stu', 'zz_test_rem_adv'
PW = 'TestPass!2026'
STUDENT_ID = 'zz_test_rem01'
GROUP_NO = 'zz_test_rg'
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

def cleanup():
    uids = db(f"SELECT GROUP_CONCAT(id) FROM sms2_users WHERE username IN ('{STU}','{ADV}')") or '0'
    db(f"DELETE FROM sms2_deadline_reminders WHERE user_id IN ({uids})")
    db(f"DELETE FROM crad_research_plans WHERE research_title LIKE 'zz_test_%'")
    db(f"DELETE FROM crad_research_adviser_assignments WHERE group_number IN ('{GROUP_NO}', '{GROUP_NO}2')")
    db(f"DELETE FROM crad_research_group_members WHERE student_id = '{STUDENT_ID}'")
    db(f"DELETE FROM crad_research_groups WHERE group_number IN ('{GROUP_NO}', '{GROUP_NO}2')")
    account_helper('delete', STU)
    account_helper('delete', ADV)

cleanup()
account_helper('create', STU, 'student', PW)
account_helper('create', ADV, 'adviser', PW)
try:
    db(f"UPDATE sms2_users SET student_id = '{STUDENT_ID}' WHERE username = '{STU}'")
    stu_id = db(f"SELECT id FROM sms2_users WHERE username = '{STU}'")
    adv_id = db(f"SELECT id FROM sms2_users WHERE username = '{ADV}'")

    db(f"INSERT INTO crad_research_groups (group_number, group_name, research_title, college_dept, adviser, academic_year, "
       f"leader_name, leader_id, leader_email, leader_contact, status, flow_status, member_count, min_members_required, date_assigned) "
       f"VALUES ('{GROUP_NO}', 'zz_test_grp', 'zz_test_reminder_title', 'Test', 'Test Adviser', '2026', 'Test Student', "
       f"'{STUDENT_ID}', 'zz_test_rem_stu@example.test', '0', 'Approved', 'Active', 1, 1, CURDATE())")
    gid = db(f"SELECT id FROM crad_research_groups WHERE group_number = '{GROUP_NO}' ORDER BY id DESC LIMIT 1")
    db(f"INSERT INTO crad_research_group_members (research_group_id, research_stage, member_order, student_id, full_name, email, is_leader) "
       f"VALUES ({gid}, 'Chapter 1', 1, '{STUDENT_ID}', 'Test Student', 'zz_test_rem_stu@example.test', 1)")
    db(f"INSERT INTO crad_research_adviser_assignments (research_group_id, adviser_user_id, adviser_name, adviser_email, group_number, assignment_status) "
       f"VALUES ({gid}, {adv_id}, 'Test Adviser', 'zz_test_rem_adv@example.test', '{GROUP_NO}', 'Active')")
    db(f"INSERT INTO crad_research_plans (research_group_id, research_title, group_number, adviser_name, adviser_email, start_date, target_completion_date, current_stage, status) "
       f"VALUES ({gid}, 'zz_test_reminder_title', '{GROUP_NO}', 'Test Adviser', 'zz_test_rem_adv@example.test', CURDATE(), CURDATE() + INTERVAL 3 DAY, 'Chapter 1', 'Active')")
    # One plan per group is allowed (uniq_rp_group), so the far-off plan lives in a second group with the same student.
    db(f"INSERT INTO crad_research_groups (group_number, group_name, research_title, college_dept, adviser, academic_year, "
       f"leader_name, leader_id, leader_email, leader_contact, status, flow_status, member_count, min_members_required, date_assigned) "
       f"VALUES ('{GROUP_NO}2', 'zz_test_grp2', 'zz_test_reminder_far', 'Test', 'Test Adviser', '2026', 'Test Student', "
       f"'{STUDENT_ID}', 'zz_test_rem_stu@example.test', '0', 'Approved', 'Active', 1, 1, CURDATE())")
    gid2 = db(f"SELECT id FROM crad_research_groups WHERE group_number = '{GROUP_NO}2' ORDER BY id DESC LIMIT 1")
    db(f"INSERT INTO crad_research_group_members (research_group_id, research_stage, member_order, student_id, full_name, email, is_leader) "
       f"VALUES ({gid2}, 'Chapter 1', 1, '{STUDENT_ID}', 'Test Student', 'zz_test_rem_stu@example.test', 1)")
    db(f"INSERT INTO crad_research_adviser_assignments (research_group_id, adviser_user_id, adviser_name, adviser_email, group_number, assignment_status) "
       f"VALUES ({gid2}, {adv_id}, 'Test Adviser', 'zz_test_rem_adv@example.test', '{GROUP_NO}2', 'Active')")
    db(f"INSERT INTO crad_research_plans (research_group_id, research_title, group_number, adviser_name, adviser_email, start_date, target_completion_date, current_stage, status) "
       f"VALUES ({gid2}, 'zz_test_reminder_far', '{GROUP_NO}2', 'Test Adviser', 'zz_test_rem_adv@example.test', CURDATE(), CURDATE() + INTERVAL 10 DAY, 'Chapter 1', 'Active')")
    near_plan = db(f"SELECT id FROM crad_research_plans WHERE research_title = 'zz_test_reminder_title' AND group_number = '{GROUP_NO}' LIMIT 1")

    def run_job():
        out = subprocess.run(['php', 'tools/send-deadline-reminders.php'], cwd=ROOT, capture_output=True, text=True)
        return out.returncode, json.loads(out.stdout) if out.returncode == 0 else {}

    rc, stats1 = run_job()
    check('daily job runs without error', rc == 0 and 'error' not in stats1)
    stu_rows = db(f"SELECT remind_days, source_key, is_read FROM sms2_deadline_reminders WHERE user_id = {stu_id} AND source_key = 'plan:{near_plan}'")
    adv_rows = db(f"SELECT remind_days FROM sms2_deadline_reminders WHERE user_id = {adv_id} AND source_key = 'plan:{near_plan}'")
    check('student gets a 3-day reminder for the plan due in 3 days', stu_rows.startswith('3\t'))
    check('adviser gets a 3-day reminder for the same plan', adv_rows.strip() == '3')
    far = db(f"SELECT COUNT(*) FROM sms2_deadline_reminders WHERE user_id = {stu_id} AND deadline_date = CURDATE() + INTERVAL 10 DAY")
    check('no reminder for the plan due in 10 days', far == '0')
    emails = db(f"SELECT COUNT(*) FROM sms2_deadline_reminders WHERE user_id IN ({stu_id},{adv_id}) AND email_status IS NOT NULL")
    check('every new reminder records its email outcome', int(emails) >= 2)
    before = db(f"SELECT COUNT(*) FROM sms2_deadline_reminders WHERE user_id IN ({stu_id},{adv_id})")
    rc2, stats2 = run_job()
    after = db(f"SELECT COUNT(*) FROM sms2_deadline_reminders WHERE user_id IN ({stu_id},{adv_id})")
    check('running the job again creates no duplicates', rc2 == 0 and before == after and stats2.get('created') == 0)

    op = login(STU)
    html = op.open(BASE + '/modules/student-portal/pages/dashboard.php', timeout=20).read().decode('utf-8', 'ignore')
    check('student dashboard shows the reminder', 'Research plan completion is due in 3 days' in html)
    csrf = re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)
    reminder_id = db(f"SELECT id FROM sms2_deadline_reminders WHERE user_id = {stu_id} AND source_key = 'plan:{near_plan}'")
    data = urllib.parse.urlencode({'reminder_id': reminder_id, 'return_to': BASE + '/dashboard/index.php', 'csrf_token': csrf}).encode()
    op.open(urllib.request.Request(BASE + '/communication/reminder-read.php', data=data), timeout=20)
    check('mark as read stores the read state', db(f"SELECT is_read FROM sms2_deadline_reminders WHERE id = {reminder_id}") == '1')
    html2 = op.open(BASE + '/modules/student-portal/pages/dashboard.php', timeout=20).read().decode('utf-8', 'ignore')
    check('a read reminder is no longer shown to the student', 'Research plan completion is due in 3 days' not in html2)

    adv = login(ADV)
    ahtml = adv.open(BASE + '/modules/faculty/pages/research-workspace.php', timeout=20).read().decode('utf-8', 'ignore')
    adv_reminder_id = db(f"SELECT id FROM sms2_deadline_reminders WHERE user_id = {adv_id} AND source_key = 'plan:{near_plan}'")
    check('adviser sees the deadline reminder banner on their workspace page', 'Deadline reminders' in ahtml and 'Research plan completion is due in 3 days' in ahtml)
    check('the adviser reminder exists and is unread', db(f"SELECT is_read FROM sms2_deadline_reminders WHERE id = {adv_reminder_id}") == '0')
finally:
    cleanup()

print('DEADLINE REMINDERS RESULT:', 'PASS' if all(results) else 'FAIL', f'({sum(results)}/{len(results)})')
