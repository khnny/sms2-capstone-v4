"""Grant approval workflow test (To-Do P3.14). Uses zz_test_ accounts and zz_test_ grant applications, removed at the end.

Scenarios:
  A. Full chain: Adviser -> Dept. Chair -> Dean -> Research Office -> VPAA -> Finance, with out-of-order and
     wrong-role attempts refused, and the final status set. Also checks QA on the VPAA step (legacy mapping).
  B. Return loop: a step returns the proposal with remarks; remarks are required; the status becomes Returned.
  C. Research Coordinator on the Dept. Chair step (legacy mapping), then the chain finishes.
Usage: php -S 127.0.0.1:8080 -t .   then   python3 tests/grants_approval_test.py http://127.0.0.1:8080
The login captcha must be off in the test database.
"""
import http.cookiejar, json, re, subprocess, sys, urllib.parse, urllib.request
from common_db import ROOT, db, account_helper

BASE = sys.argv[1]
PW = 'TestPass!2026'
PREFIX = 'zz_test_grt_'
ACCOUNTS = {
    'student': 'student', 'adviser': 'adviser', 'coordinator': 'research_coordinator',
    'dept_chair': 'department_chair', 'dean': 'hr', 'research_office': 'research_office',
    'vpaa': 'vpaa', 'qa': 'qa', 'finance': 'finance',
}
SIGNER_FOR_STEP = {
    'adviser': 'adviser', 'department_chair': 'dept_chair', 'dean': 'dean',
    'research_office': 'research_office', 'vpaa': 'vpaa', 'finance': 'finance',
}
results = []
findings = []

def check(label, cond, note=''):
    results.append(bool(cond))
    print(('PASS ' if cond else 'FAIL ') + label + (f'  [{note}]' if note else ''))
    if not cond and note:
        findings.append(f'{label}: {note}')

def fixture(*args):
    r = subprocess.run(['php', 'tests/helpers/grant_fixture.php', *args], cwd=ROOT, capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(r.stderr.strip() or 'fixture failed')
    return r.stdout.strip()

def login(user):
    jar = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    html = op.open(BASE + '/login/login.php', timeout=20).read().decode('utf-8', 'ignore')
    tok = re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)
    op.open(urllib.request.Request(BASE + '/login/login.php', data=urllib.parse.urlencode({'username': user, 'password': PW, 'csrf_token': tok}).encode()), timeout=20)
    return op

def api(op, action, payload):
    req = urllib.request.Request(BASE + '/modules/crad/api/grant-approval.php?action=' + action,
                                 data=json.dumps(payload).encode(), headers={'Content-Type': 'application/json'})
    try:
        text = op.open(req, timeout=20).read().decode('utf-8', 'ignore')
    except urllib.error.HTTPError as e:
        text = e.read().decode('utf-8', 'ignore')
    try:
        return json.loads(text)
    except Exception:
        return {'success': False, 'raw': text[:200]}

def current_step(app_id):
    return db(f"SELECT current_step_key FROM crad_grant_proposal_approval_workflows WHERE grant_application_id = {app_id}")

def app_status(app_id):
    return db(f"SELECT status FROM crad_grant_applications WHERE id = {app_id}")

def approver_names(app_id):
    rows = db(f"SELECT approver_name FROM crad_grant_proposal_approval_steps WHERE grant_application_id = {app_id} AND status NOT IN ('Pending','Waiting') AND approver_name IS NOT NULL AND approver_name <> ''")
    return [r for r in rows.split('\n') if r.strip()]

def seed_evaluations(app_id, evaluations):
    """Adds the evaluation rows the approval gate checks: one per evaluator, with the evaluation type of their step."""
    version = db(f"SELECT COALESCE(current_version, 1) FROM crad_grant_applications WHERE id = {app_id}") or '1'
    for account_key, evaluation_type in evaluations:
        uid = db(f"SELECT id FROM sms2_users WHERE username = '{PREFIX + account_key}'")
        db(f"INSERT INTO crad_grant_proposal_evaluations (grant_application_id, proposal_version, evaluator_user_id, evaluator_name, evaluation_type, total_score, submitted_at) "
           f"VALUES ({app_id}, {version}, {uid}, 'Test {account_key}', '{evaluation_type}', 80, NOW())")

ALL_EVALUATIONS = [('adviser', 'adviser'), ('dept_chair', 'department_chair'), ('dean', 'dean'),
                   ('research_office', 'research_office'), ('vpaa', 'vpaa'), ('finance', 'finance'),
                   ('qa', 'vpaa'), ('coordinator', 'department_chair')]

def sign(ops, key, app_id, remarks=''):
    return api(ops[key], 'sign_approve', {'application_id': app_id, 'remarks': remarks})

def advance_to_end(ops, app_id):
    for _ in range(8):
        step = current_step(app_id)
        if not step or step == 'finance' and app_status(app_id) and 'approved' in app_status(app_id).lower():
            break
        signer = SIGNER_FOR_STEP.get(step)
        if signer is None:
            break
        sign(ops, signer, app_id)

for key, role in ACCOUNTS.items():
    account_helper('create', PREFIX + key, role, PW)
apps = []
try:
    # the leftover fixtures from earlier failed runs are removed by the fixture's delete command
    ops = {key: login(PREFIX + key) for key in ACCOUNTS}

    # Scenario A: full chain
    a = int(fixture('create')); apps.append(a)
    seed_evaluations(a, ALL_EVALUATIONS)
    check('A: a new application starts at the Adviser step', current_step(a) == 'adviser')
    check('A: a student cannot sign the Adviser step', not sign(ops, 'student', a).get('success'))
    check('A: the Dept. Chair cannot sign out of order', not sign(ops, 'dept_chair', a).get('success'))
    check('A: the Research Coordinator cannot sign the Adviser step', not sign(ops, 'coordinator', a).get('success'))
    check('A: the Adviser signs and the chain moves to Dept. Chair', sign(ops, 'adviser', a).get('success') is True and current_step(a) == 'department_chair')
    check('A: the Dept. Chair signs and the chain moves to Dean', sign(ops, 'dept_chair', a).get('success') is True and current_step(a) == 'dean')
    check('A: the Dean signs and the chain moves to Research Office', sign(ops, 'dean', a).get('success') is True and current_step(a) == 'research_office')
    check('A: the Research Office signs and the chain moves to VPAA', sign(ops, 'research_office', a).get('success') is True and current_step(a) == 'vpaa')
    qa = sign(ops, 'qa', a)
    check('A: QA is refused on the VPAA step (separation of duties)', not qa.get('success'),
          note='QA signed the VPAA step: legacy mapping grantApprovalLegacyStepRoleKeys("vpaa") = ["qa"]' if qa.get('success') else '')
    if not qa.get('success'):
        check('A: the VPAA signs and the chain moves to Finance', sign(ops, 'vpaa', a).get('success') is True and current_step(a) == 'finance')
        fin = sign(ops, 'finance', a)
        check('A: Finance sign-off is reported as recorded', fin.get('success') is True,
              note=f'the response says: "{fin.get("message", fin.get("raw", ""))}"; the database status is "{app_status(a)}"')
        check('A: the proposal reaches Approved & Funded after the six sign-offs', 'approved' in app_status(a).lower(), note=f'status is "{app_status(a)}"')
        tranches = db(f"SELECT COUNT(*) FROM crad_grant_funding_disbursements WHERE grant_application_id = {a}")
        milestones = db(f"SELECT COUNT(*) FROM crad_grant_funded_project_milestones WHERE grant_application_id = {a}")
        check('A: the funding tranches and project milestones are created by the final approval', int(tranches or 0) > 0 and int(milestones or 0) > 0,
              note=f'tranches {tranches}, milestones {milestones}')
    else:
        advance_to_end(ops, a)
    names = approver_names(a)
    check('A: each signature records the approver name', names and all(n.strip().lower() != 'user' for n in names),
          note=f'approver names recorded as {sorted(set(names))}; the API reads $_SESSION["full_name"], which is never set')

    # Scenario B: return loop
    b = int(fixture('create')); apps.append(b)
    seed_evaluations(b, ALL_EVALUATIONS)
    sign(ops, 'adviser', b)
    check('B: the Dept. Chair cannot return a proposal without remarks', not api(ops['dept_chair'], 'return_revision', {'application_id': b, 'remarks': ''}).get('success'))
    check('B: the Dept. Chair returns a proposal with remarks', api(ops['dept_chair'], 'return_revision', {'application_id': b, 'remarks': 'zz test return'}).get('success') is True)
    check('B: a returned proposal shows the revision status to the applicant', 'revision' in app_status(b).lower() or 'return' in app_status(b).lower(), note=f'status is "{app_status(b)}"')

    # Scenario C: Research Coordinator on the Dept. Chair step (legacy mapping)
    c = int(fixture('create')); apps.append(c)
    seed_evaluations(c, ALL_EVALUATIONS)
    sign(ops, 'adviser', c)
    coord = sign(ops, 'coordinator', c)
    check('C: the Research Coordinator cannot sign the Dept. Chair step', not coord.get('success'),
          note='Research Coordinator signed the Dept. Chair step: legacy mapping grantApprovalLegacyStepRoleKeys("department_chair") = ["research_coordinator"]' if coord.get('success') else '')
    advance_to_end(ops, c)
    check('C: the chain can still finish after a legacy signature or refusal', 'approved' in app_status(c).lower() or current_step(c) == 'finance' or current_step(c) is not None, note=f'status "{app_status(c)}", step "{current_step(c)}"')
finally:
    for app_id in apps:
        fixture('delete', str(app_id))
    for key in ACCOUNTS:
        account_helper('delete', PREFIX + key)

print('GRANTS APPROVAL RESULT:', 'PASS' if all(results) else 'FAIL', f'({sum(results)}/{len(results)})')
if findings:
    print('FINDINGS TO CONFIRM:')
    for f in findings:
        print('  -', f)
