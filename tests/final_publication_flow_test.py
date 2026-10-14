"""Isolated HTTP flow: manuscript review -> final approval -> publication -> repository.

Requires SMS2_TEST_DB and matching application configuration pointing at a disposable
crad_qa_flow_* database. Earlier title/group/defense work is seeded, not E2E tested.
"""
import http.cookiejar, re, secrets, sys, urllib.parse, urllib.request
from common_db import db, account_helper, DB_NAME

if not DB_NAME.startswith(('crad_qa_flow_', 'crad_qa_deploy_http_')):
    raise SystemExit('Disposable CRAD flow/deployment QA database required')
BASE = sys.argv[1]
SUFFIX = secrets.token_hex(4)
USER = 'zz_test_final_' + SUFFIX
TITLE = 'zz_test_final_title_' + SUFFIX
PW = 'TestPass!2026'
results = []
tokens = {}
gid = tid = mid = sid = 0

def check(label, condition):
    results.append(bool(condition))
    print(('PASS ' if condition else 'FAIL ') + label, flush=True)

def get(op, path):
    return op.open(BASE + path, timeout=30).read().decode('utf-8', 'replace')

def post(op, path, fields):
    html = get(op, path)
    match = re.search(r'name="csrf_token" value="([^"]+)"', html)
    if not match:
        # Ineligible queues intentionally render no submission form.
        html = get(op, '/modules/crad/pages/final-manuscript-review.php')
        match = re.search(r'name="csrf_token" value="([^"]+)"', html)
    if not match and id(op) not in tokens:
        raise RuntimeError('No CSRF form available for authenticated reviewer')
    token = match.group(1) if match else tokens[id(op)]
    tokens[id(op)] = token
    data = urllib.parse.urlencode(dict(fields, csrf_token=token)).encode()
    return op.open(urllib.request.Request(BASE + path, data=data), timeout=30).read().decode('utf-8', 'replace')

try:
    account_helper('create', USER, 'crad_officer', PW)
    uid = int(db(f"SELECT id FROM sms2_users WHERE username='{USER}'"))
    db(f"INSERT INTO crad_title_approvals (student_id, submission_date, department, proposed_title, sdg_justification, members_json, status, coordinator_status, crad_status, adviser_signature_data, coordinator_signature_data, crad_signature_data) VALUES ('{USER}', CURDATE(), 'Test', '{TITLE}', 'Test', '[]', 'Approved', 'Approved', 'Approved', 'sig', 'sig', 'sig')")
    tid = int(db(f"SELECT id FROM crad_title_approvals WHERE proposed_title='{TITLE}'"))
    db(f"INSERT INTO crad_research_groups (title_approval_id, group_number, group_name, research_title, college_dept, academic_year, date_assigned) VALUES ({tid}, '{USER}', '{USER}', '{TITLE}', 'Test', '2026', CURDATE())")
    gid = int(db(f"SELECT id FROM crad_research_groups WHERE group_number='{USER}'"))
    db(f"INSERT INTO crad_manuscript_submissions (research_group_id, version_number, submission_token, status) VALUES ({gid}, 1, '{secrets.token_hex(16)}', 'Submitted')")
    mid = int(db(f"SELECT id FROM crad_manuscript_submissions WHERE research_group_id={gid}"))
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    post(op, '/login/login.php', {'username': USER, 'password': PW})
    review = '/modules/crad/pages/final-manuscript-review.php'
    final = '/modules/crad/pages/final-manuscript-approval.php'
    create = '/modules/crad/pages/publication-create.php'
    manage = '/modules/crad/pages/documentation-publication-management.php'
    repository = '/modules/crad/pages/research-repository.php'
    post(op, create, {'research_group_id': gid})
    check('publication is refused before final approval', db(f'SELECT COUNT(*) FROM crad_publications WHERE research_group_id={gid}') == '0')
    post(op, final, {'group_id': gid})
    check('final approval is refused before manuscript evaluation', db(f'SELECT COUNT(*) FROM crad_final_manuscript_approvals WHERE research_group_id={gid}') == '0')
    fields = {'submission_id': mid, 'review_action': 'approve', 'remarks': 'Synthetic audit review'}
    for key in ['content', 'methodology', 'results', 'conclusions', 'recommendations', 'references', 'formatting', 'compliance']:
        fields[key + '_score'] = '12.5'
    bad = dict(fields, content_score='13')
    post(op, review, bad)
    check('score above criterion maximum is rejected', db(f'SELECT status FROM crad_manuscript_submissions WHERE id={mid}') == 'Submitted')
    post(op, review, dict(fields, review_action='revision'))
    check('review can return the manuscript for revision', db(f'SELECT status FROM crad_manuscript_submissions WHERE id={mid}') == 'For Revision')
    post(op, review, fields)
    check('manuscript review approval persists', db(f'SELECT status FROM crad_manuscript_submissions WHERE id={mid}') == 'Approved')
    check('evaluation records APPROVED and score 100', db(f"SELECT CONCAT(result, '|', overall_score) FROM crad_manuscript_evaluations WHERE submission_id={mid} ORDER BY id DESC LIMIT 1") == 'APPROVED|100.00')
    post(op, final, {'group_id': gid})
    check('final approval is refused without defense results', db(f'SELECT COUNT(*) FROM crad_final_manuscript_approvals WHERE research_group_id={gid}') == '0')
    # Fixture boundary: seed a completed approved defense; do not claim to test panel evaluation UI.
    db(f"INSERT INTO crad_research_defense_schedules (research_group_id, group_number, research_group, research_title, defense_type, status, defense_datetime) VALUES ({gid}, '{USER}', '{USER}', '{TITLE}', 'Final Defense', 'Finalized', NOW())")
    sid = int(db(f'SELECT id FROM crad_research_defense_schedules WHERE research_group_id={gid}'))
    db(f"INSERT INTO crad_research_panel_assignments (research_group_id, defense_schedule_id, panel_user_id, defense_phase) VALUES ({gid}, {sid}, {uid}, 'Final Defense')")
    db(f"INSERT INTO crad_final_defense_evaluations (research_group_id, defense_schedule_id, panel_user_id, content_score, methodology_score, references_score, format_score, overall_score, result) VALUES ({gid}, {sid}, {uid}, 25, 25, 25, 25, 100, 'APPROVED')")
    post(op, final, {'group_id': gid, 'remarks': 'Synthetic final approval'})
    check('final approval persists after defense prerequisite', db(f'SELECT status FROM crad_final_manuscript_approvals WHERE research_group_id={gid}') == 'Approved')
    post(op, create, {'research_group_id': gid})
    pub = int(db(f'SELECT id FROM crad_publications WHERE research_group_id={gid}'))
    check('approved manuscript creates Draft publication', db(f'SELECT status FROM crad_publications WHERE id={pub}') == 'Draft')
    post(op, create, {'research_group_id': gid})
    check('sequential duplicate publication is refused', db(f'SELECT COUNT(*) FROM crad_publications WHERE research_group_id={gid}') == '1')
    post(op, manage, {'publication_id': pub, 'status': 'Published', 'publication_outlet': 'Synthetic Journal', 'publication_date': '2026-10-14', 'doi_link': 'https://example.test/audit', 'notes': 'Synthetic test'})
    check('publication status and outlet persist', db(f"SELECT CONCAT(status, '|', publication_outlet) FROM crad_publications WHERE id={pub}") == 'Published|Synthetic Journal')
    check('published research appears in repository', TITLE in get(op, repository + '?status=Published'))
    post(op, manage, {'publication_id': pub, 'status': 'Archived', 'publication_outlet': 'Synthetic Journal', 'publication_date': '2026-10-14'})
    check('archiving persists', db(f'SELECT status FROM crad_publications WHERE id={pub}') == 'Archived')
    check('archived research appears under archived filter', TITLE in get(op, repository + '?status=Archived'))
finally:
    if gid:
        for table in ['crad_publications', 'crad_final_manuscript_approvals', 'crad_manuscript_evaluations', 'crad_manuscript_submissions', 'crad_final_defense_evaluations', 'crad_research_panel_assignments', 'crad_research_revision_cycles', 'crad_research_defense_schedules']:
            db(f'DELETE FROM {table} WHERE research_group_id={gid}')
        db(f'DELETE FROM crad_research_groups WHERE id={gid}')
    if tid:
        db(f'DELETE FROM crad_title_approvals WHERE id={tid}')
    account_helper('delete', USER)
print(f'FINAL PUBLICATION FLOW RESULT: {"PASS" if all(results) else "FAIL"} ({sum(results)}/{len(results)})')
raise SystemExit(0 if all(results) else 1)
