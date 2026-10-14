"""Panel Application test (To-Do P1.1). Uses a zz_test_ student group, a zz_test_ student, and a zz_test_ CRAD officer,
all removed at the end.

Flow: a student fills in the details, uploads the manuscript and approval sheet, submits. CRAD starts the review,
rejects with a reason, the student fixes and resubmits, CRAD approves, schedules, and completes. Checks that the
wrong file type, a missing detail, an invalid jump, and a short rejection reason are refused, that the history
records every step, and that the document downloads only for CRAD and the owning student.
Usage: php -S 127.0.0.1:8080 -t .   then   python3 tests/panel_application_test.py http://127.0.0.1:8080
"""
import base64, http.cookiejar, os, re, secrets, sys, urllib.parse, urllib.request
from common_db import db, account_helper, ROOT

BASE = sys.argv[1]
PW = 'TestPass!2026'
STU, CAD = 'zz_test_pa_student', 'zz_test_pa_cad'
SID = 'ZZPA0001'
GID = 987801
TITLE = 'zz_test_pa_title'
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=')
PDF = b'%PDF-1.4\n% zz test manuscript\n%%EOF\n'
PAGE_S = '/modules/student-portal/pages/panel-application.php'
PAGE_C = '/modules/crad/pages/panel-applications.php'
results = []

def check(label, cond, note=''):
    results.append(bool(cond))
    print(('PASS ' if cond else 'FAIL ') + label + (f'  [{note}]' if note and not cond else ''))

def login(username):
    jar = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    html = op.open(BASE + '/login/login.php', timeout=20).read().decode('utf-8', 'ignore')
    tok = re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)
    op.open(urllib.request.Request(BASE + '/login/login.php', data=urllib.parse.urlencode({'username': username, 'password': PW, 'csrf_token': tok}).encode()), timeout=20)
    return op

def get(op, path):
    r = op.open(BASE + path, timeout=30)
    return r.getcode(), r.headers.get('Content-Type', ''), r.read()

def csrf(op, path):
    return re.search(r'name="csrf_token" value="([^"]+)"', get(op, path)[2].decode('utf-8', 'ignore')).group(1)

def post_form(op, path, fields):
    body = urllib.parse.urlencode(fields, doseq=True).encode()
    return op.open(urllib.request.Request(BASE + path, data=body), timeout=30).read().decode('utf-8', 'ignore')

def post_file(op, path, fields, fname, content, ctype):
    b = '----zzb' + secrets.token_hex(8)
    parts = []
    for k, v in fields.items():
        parts.append(f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
    parts.append(f'--{b}\r\nContent-Disposition: form-data; name="document"; filename="{fname}"\r\nContent-Type: {ctype}\r\n\r\n'.encode() + content + b'\r\n')
    parts.append(f'--{b}--\r\n'.encode())
    req = urllib.request.Request(BASE + path, data=b''.join(parts), headers={'Content-Type': f'multipart/form-data; boundary={b}'})
    return op.open(req, timeout=30).read().decode('utf-8', 'ignore')

def app_row():
    return db(f"SELECT CONCAT(id, '|', status, '|', application_ref) FROM crad_defense_panel_requests WHERE research_group_id = {GID} ORDER BY id DESC LIMIT 1")

def status():
    v = app_row()
    return v.split('|')[1] if v else ''

def cleanup():
    try:
        for stored in db(f"SELECT GROUP_CONCAT(d.stored_name) FROM crad_defense_panel_request_documents d JOIN crad_defense_panel_requests a ON a.id = d.application_id WHERE a.research_group_id = {GID}").split(','):
            if stored and stored != 'NULL':
                p = os.path.join(str(ROOT), 'storage', 'uploads', 'panel_applications', os.path.basename(stored))
                try:
                    os.remove(p)
                except OSError:
                    pass
    except Exception:
        pass
    db(f"DELETE d FROM crad_defense_panel_request_documents d JOIN crad_defense_panel_requests a ON a.id = d.application_id WHERE a.research_group_id = {GID}")
    db(f"DELETE h FROM crad_defense_panel_request_history h JOIN crad_defense_panel_requests a ON a.id = h.application_id WHERE a.research_group_id = {GID}")
    db(f"DELETE FROM crad_defense_panel_requests WHERE research_group_id = {GID}")
    db(f"DELETE FROM crad_research_group_members WHERE research_group_id = {GID}")
    db(f"DELETE FROM crad_research_groups WHERE id = {GID}")
    for u in (STU, CAD):
        account_helper('delete', u)

import subprocess
subprocess.run(['php', '-r', 'require "config/config.php"; require_once ROOT_PATH."/modules/crad/config/config.php"; require_once ROOT_PATH."/modules/crad/includes/panel-applications.php"; cradPanelAppEnsureSchema(cradDb());'], cwd=ROOT, check=True)
cleanup()
account_helper('create', STU, 'student', PW)
account_helper('create', CAD, 'crad_officer', PW)
db(f"UPDATE sms2_users SET student_id = '{SID}' WHERE username = '{STU}'")
db(f"INSERT INTO crad_research_groups (id, group_number, group_name, research_title, adviser, academic_year, leader_name, leader_id, leader_email, leader_contact, status, flow_status, member_count, min_members_required, college_dept, date_assigned) "
   f"VALUES ({GID}, 'zz_test_pa', 'zz_test_pa_grp', '{TITLE}', 'Test Adviser', '2026', 'Test Leader', '{SID}', 'zz_test_pa@example.test', '0', 'Approved', 'Active', 1, 1, 'Test', CURDATE())")
try:
    st = login(STU)
    code, _, body = get(st, PAGE_S)
    html = body.decode('utf-8', 'ignore')
    check('the student sees the panel application page with the group title', code == 200 and TITLE in html and 'Panel Application' in html)
    check('the application starts as a draft', status() == 'Draft', note=status())
    tok = csrf(st, PAGE_S)

    out = post_form(st, PAGE_S, {'csrf_token': tok, 'panel_action': 'save_details', 'research_title': TITLE, 'adviser_name': 'Test Adviser', 'members_text': ''})
    check('a missing member list is refused', 'at least one group member' in out)
    out = post_form(st, PAGE_S, {'csrf_token': tok, 'panel_action': 'save_details', 'research_title': TITLE, 'adviser_name': 'Test Adviser', 'members_text': 'Juan Dela Cruz\nMaria Santos'})
    check('valid details are saved', 'Details saved' in out)

    out = post_file(st, PAGE_S, {'csrf_token': tok, 'panel_action': 'upload_document', 'doc_type': 'manuscript'}, 'notes.txt', b'plain text', 'text/plain')
    check('a text file is refused as the manuscript', 'Upload a PDF, PNG, or JPG' in out or 'must be a PDF' in out)
    out = post_file(st, PAGE_S, {'csrf_token': tok, 'panel_action': 'upload_document', 'doc_type': 'manuscript'}, 'manuscript.pdf', PDF, 'application/pdf')
    check('the manuscript PDF uploads', 'Final manuscript (PDF) uploaded' in out)
    out = post_file(st, PAGE_S, {'csrf_token': tok, 'panel_action': 'upload_document', 'doc_type': 'approval_sheet'}, 'approval.png', PNG, 'image/png')
    check('the approval sheet image uploads', 'Signed approval sheet (PDF or image) uploaded' in out)

    out = post_form(st, PAGE_S, {'csrf_token': tok, 'panel_action': 'submit'})
    check('the application submits when complete', 'Application submitted' in out and status() == 'Submitted', note=status())
    out = post_form(st, PAGE_S, {'csrf_token': tok, 'panel_action': 'save_details', 'research_title': 'changed', 'adviser_name': 'X', 'members_text': 'A'})
    check('details are locked after submission', 'can only be edited' in out and 'changed' not in db(f"SELECT research_title FROM crad_defense_panel_requests WHERE research_group_id = {GID}"))

    app_id, _, ref = app_row().split('|')
    doc_id = db(f"SELECT id FROM crad_defense_panel_request_documents WHERE application_id = {app_id} AND doc_type = 'manuscript'")

    cad = login(CAD)
    code, _, body = get(cad, PAGE_C + f'?id={app_id}')
    check('CRAD sees the submitted application', code == 200 and ref in body.decode('utf-8', 'ignore'))
    ctok = csrf(cad, PAGE_C + f'?id={app_id}')
    out = post_form(cad, PAGE_C, {'csrf_token': ctok, 'panel_action': 'approve', 'application_id': app_id})
    check('CRAD cannot approve before starting the review', 'cannot move to Approved' in out and status() == 'Submitted')
    out = post_form(cad, PAGE_C, {'csrf_token': ctok, 'panel_action': 'start_review', 'application_id': app_id})
    check('CRAD starts the review', status() == 'Under Review', note=status())
    out = post_form(cad, PAGE_C, {'csrf_token': ctok, 'panel_action': 'reject', 'application_id': app_id, 'remarks': 'bad'})
    check('a rejection without a real reason is refused', 'at least 5 characters' in out and status() == 'Under Review')
    out = post_form(cad, PAGE_C, {'csrf_token': ctok, 'panel_action': 'reject', 'application_id': app_id, 'remarks': 'Attach the signed approval sheet pages'})
    check('CRAD rejects with a reason', status() == 'Rejected', note=status())

    code, _, body = get(st, PAGE_S)
    html = body.decode('utf-8', 'ignore')
    check('the student sees the rejection and the CRAD reason', 'Attach the signed approval sheet pages' in html)
    tok = csrf(st, PAGE_S)
    out = post_form(st, PAGE_S, {'csrf_token': tok, 'panel_action': 'save_details', 'research_title': TITLE, 'adviser_name': 'Test Adviser', 'members_text': 'Juan Dela Cruz\nMaria Santos'})
    check('a rejected application can be edited again', 'Details saved' in out)
    out = post_form(st, PAGE_S, {'csrf_token': tok, 'panel_action': 'submit'})
    check('the student resubmits a rejected application', status() == 'Submitted', note=status())

    ctok = csrf(cad, PAGE_C + f'?id={app_id}')
    for action in ('start_review', 'approve', 'schedule', 'complete'):
        post_form(cad, PAGE_C, {'csrf_token': ctok, 'panel_action': action, 'application_id': app_id})
    check('CRAD approves, schedules, and completes the application', status() == 'Completed', note=status())
    out = post_form(cad, PAGE_C, {'csrf_token': ctok, 'panel_action': 'approve', 'application_id': app_id})
    check('a completed application cannot move again', 'cannot move to Approved' in out and status() == 'Completed')

    hist = int(db(f"SELECT COUNT(*) FROM crad_defense_panel_request_history WHERE application_id = {app_id}") or 0)
    tos = db(f"SELECT GROUP_CONCAT(to_status ORDER BY id SEPARATOR ',') FROM crad_defense_panel_request_history WHERE application_id = {app_id}")
    check('the history records every step in order', hist >= 9 and tos.startswith('Draft,Submitted,Under Review,Rejected,Submitted,Under Review,Approved,Scheduled,Completed'), note=tos)

    code, ctype, _ = get(cad, '/modules/crad/panel-application-file.php?doc=' + doc_id)
    check('CRAD can download the manuscript', code == 200 and 'pdf' in ctype)
    code, ctype, _ = get(st, '/modules/crad/panel-application-file.php?doc=' + doc_id)
    check('the owning student can download it too', code == 200 and 'pdf' in ctype)
    other = login(CAD)
    other_student = 'zz_test_pa_other'
    account_helper('create', other_student, 'student', PW)
    try:
        ost = login(other_student)
        try:
            code, _, _ = get(ost, '/modules/crad/panel-application-file.php?doc=' + doc_id)
        except urllib.error.HTTPError as e:
            code = e.code
        check('another student cannot download it', code == 404, note=f'status {code}')
    finally:
        account_helper('delete', other_student)
finally:
    cleanup()

print('PANEL APPLICATION RESULT:', 'PASS' if all(results) else 'FAIL', f'({sum(results)}/{len(results)})')
