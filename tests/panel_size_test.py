"""Panel size rules test (To-Do P1.2: configure number of panelists). Uses zz_test_ rows and one zz_test_ CRAD officer,
removed at the end. The test sets the Proposal Defense rule to 3-4 members and restores 2-5 afterwards.

Checks: the rule is saved from the page; 2 and 5 members are refused; 3 members with a chair are accepted.
Usage: php -S 127.0.0.1:8080 -t .   then   python3 tests/panel_size_test.py http://127.0.0.1:8080
The login captcha must be off in the test database.
"""
import http.cookiejar, re, subprocess, sys, urllib.parse, urllib.request
from common_db import ROOT, db, account_helper

BASE = sys.argv[1]
USER, PW = 'zz_test_psz_cad', 'TestPass!2026'
GROUP_NO = 'zz_test_psz'
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

def page_and_csrf(op, path='/modules/crad/pages/panel-configuration.php'):
    html = op.open(BASE + path, timeout=20).read().decode('utf-8', 'ignore')
    return html, re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)

def post(op, fields):
    body = urllib.parse.urlencode(fields, doseq=True).encode()
    return op.open(urllib.request.Request(BASE + '/modules/crad/pages/panel-configuration.php', data=body), timeout=20).read().decode('utf-8', 'ignore')

def setting(key):
    r = subprocess.run(['php', '-r', f'require "config/config.php"; require_once ROOT_PATH."/includes/security.php"; echo smsSetting("{key}", "");'], cwd=ROOT, capture_output=True, text=True)
    return r.stdout.strip()

def restore_defaults():
    subprocess.run(['php', '-r', 'require "config/config.php"; require_once ROOT_PATH."/includes/security.php"; require_once ROOT_PATH."/modules/crad/includes/panelist-workflow.php"; cradSavePanelSizeLimits("Proposal Defense", 2, 5);'], cwd=ROOT, capture_output=True, text=True)

def cleanup(group_id):
    if group_id:
        db(f"DELETE FROM crad_panel_pool_assignments WHERE research_group_id = {group_id}")
    db("DELETE FROM crad_panelist_pool WHERE application_ref LIKE 'zz_test_psz%'")
    db("DELETE FROM crad_panelist_applications WHERE application_ref LIKE 'zz_test_psz%'")
    db(f"DELETE FROM crad_research_groups WHERE group_number = '{GROUP_NO}'")
    db("DELETE FROM crad_title_approvals WHERE proposed_title = 'zz_test_psz_title'")
    db("DELETE FROM crad_research_proposals WHERE ref_code = 'zz_test_psz_ref'")
    account_helper('delete', USER)
    restore_defaults()

cleanup(None)
account_helper('create', USER, 'crad_officer', PW)
group_id = None
try:
    pool_ids = []
    for i in range(1, 6):
        ref = f'zz_test_psz_{i:02d}'
        db(f"INSERT INTO crad_panelist_applications (application_ref, resume_token_hash, applicant_name, applicant_email, status, application_data) "
           f"VALUES ('{ref}', '{('%064x' % (i * 7919))}', 'Test Panelist {i}', '{ref}@example.test', 'Approved', '{{}}')")
        app_id = db(f"SELECT id FROM crad_panelist_applications WHERE application_ref = '{ref}'")
        db(f"INSERT INTO crad_panelist_pool (application_id, application_ref, applicant_name, applicant_email, panel_status, "
           f"research_areas, expertise_keywords, defense_preferences, preferred_schedule, unavailable_periods) "
           f"VALUES ({app_id}, '{ref}', 'Test Panelist {i}', '{ref}@example.test', 'Eligible', '', '', '', '', '')")
        pool_ids.append(int(db(f"SELECT id FROM crad_panelist_pool WHERE application_ref = '{ref}'")))
    # An official group: a fully approved title (adviser, coordinator, and CRAD signatures) linked to the group.
    db("INSERT INTO crad_title_approvals (student_id, student_name, submission_date, department, proposed_title, discipline_cluster, primary_sdg, research_agenda, sdg_justification, members_json, adviser_name, adviser_email, coordinator_name, status, coordinator_status, crad_status, adviser_signature_data, coordinator_signature_data, crad_signature_data) "
       "VALUES ('zz_test_psz', 'Test Leader', NOW(), 'Test', 'zz_test_psz_title', 'c', 's', 'a', 'j', '[]', 'Test Adviser', 'zz_test_psz_adv@example.test', 'Test Coordinator', 'Approved', 'Approved', 'Approved', 'sig', 'sig', 'sig')")
    title_id = int(db("SELECT id FROM crad_title_approvals WHERE proposed_title = 'zz_test_psz_title' ORDER BY id DESC LIMIT 1"))
    # The panel save joins the group to the legacy proposal row, so that row must exist too.
    db("INSERT INTO crad_research_proposals (ref_code, research_title, program_course, year_section, college_department, research_adviser, academic_year, rep_name, rep_id, rep_email, rep_contact, date_submitted, status) "
       "VALUES ('zz_test_psz_ref', 'zz_test_psz_title', 'BSIT', 'BSIT-41005', 'Test', 'Test Adviser', '2026', 'Test Leader', 'zz_test_psz_lead', 'zz_test_psz@example.test', '0', CURDATE(), 'Approved')")
    proposal_id = int(db("SELECT id FROM crad_research_proposals WHERE ref_code = 'zz_test_psz_ref' ORDER BY id DESC LIMIT 1"))
    db(f"INSERT INTO crad_research_groups (group_number, group_name, research_title, college_dept, adviser, academic_year, leader_name, leader_id, leader_email, leader_contact, status, flow_status, member_count, min_members_required, date_assigned, title_approval_id, proposal_id) "
       f"VALUES ('{GROUP_NO}', 'zz_test_grp', 'zz_test_psz_title', 'Test', 'Test Adviser', '2026', 'Test Leader', 'zz_test_psz_lead', 'zz_test_psz@example.test', '0', 'Approved', 'Active', 1, 1, CURDATE(), {title_id}, {proposal_id})")
    group_id = int(db(f"SELECT id FROM crad_research_groups WHERE group_number = '{GROUP_NO}' ORDER BY id DESC LIMIT 1"))

    op = login()
    html, csrf = page_and_csrf(op)
    check('the rules form is on the panel configuration page', 'panelSizeRulesTitle' in html and 'limits[proposal_defense][min]' in html)

    out = post(op, {'pool_action': 'save_panel_size', 'csrf_token': csrf,
                    'limits[title_defense][min]': '2', 'limits[title_defense][max]': '5',
                    'limits[proposal_defense][min]': '3', 'limits[proposal_defense][max]': '4',
                    'limits[final_defense][min]': '2', 'limits[final_defense][max]': '5'})
    check('the rule saves from the page', 'Panel size rules saved' in out and setting('crad_panel_min_proposal_defense') == '3' and setting('crad_panel_max_proposal_defense') == '4')
    html2, csrf = page_and_csrf(op)
    check('the form shows the saved values after saving', 'value="3" selected' in html2 or 'value="3" \n' in html2 or '<option value="3" selected' in html2)

    def assign(ids, chair):
        return post(op, {'pool_action': 'save_panel', 'csrf_token': csrf, 'research_group_id': group_id,
                         'defense_type': 'Proposal Defense', 'panel_pool_ids[]': ids, 'chair_pool_id': chair})

    def assignment_count():
        return int(db(f"SELECT COUNT(*) FROM crad_panel_pool_assignments WHERE research_group_id = {group_id} AND defense_type = 'Proposal Defense' AND assignment_status <> 'Removed'") or 0)

    out_two = assign(pool_ids[:2], pool_ids[0])
    check('2 panelists are refused when the minimum is 3', 'panelists, and exactly one chair' in out_two and '3 to 4' in out_two and assignment_count() == 0)
    out_five = assign(pool_ids[:5], pool_ids[0])
    check('5 panelists are refused when the maximum is 4', '3 to 4' in out_five and assignment_count() == 0)
    out_three = assign(pool_ids[:3], pool_ids[0])
    import re as _re
    alert = _re.search(r'alert-(?:danger|success)\"[^>]*>(.*?)</div>', out_three, _re.S)
    check('3 panelists with one chair are accepted', 'Panel configuration saved' in out_three and assignment_count() == 3,
          note=f'assignments={assignment_count()}; page says: ' + (alert.group(1).strip()[:160] if alert else 'no message'))
finally:
    cleanup(group_id)

print('PANEL SIZE RESULT:', 'PASS' if all(results) else 'FAIL', f'({sum(results)}/{len(results)})')
