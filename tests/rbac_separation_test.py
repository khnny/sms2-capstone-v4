"""RBAC separation test (To-Do P2.9). Creates one zz_test_rbac_ account per system role, logs in as each,
and checks which representative pages each role can open. Test accounts are removed at the end.

Pass rules (from the To-Do list: separate permissions for Super Admin, Admin, Faculty, Adviser, Coordinator,
based on the system role):
  - the student portal opens only for students
  - user management opens only for Super Admin and Admin
  - CRAD officer pages open only for CRAD officers
  - coordinator pages open for the research coordinator
  - the faculty workspace does not open for students
  - no role that is refused a page gets an unrelated page by mistake (printed in the matrix)
Usage: php -S 127.0.0.1:8080 -t .   then   python3 tests/rbac_separation_test.py http://127.0.0.1:8080
The login captcha must be off in the test database.
"""
import http.cookiejar, re, sys, urllib.parse, urllib.request
from common_db import account_helper

BASE = sys.argv[1]
PW = 'TestPass!2026'
ROLES = {
    'superadmin': 'superadmin',
    'sms_admin': 'sms_admin',
    'adviser': 'adviser',
    'research_coordinator': 'research_coordinator',
    'crad_officer': 'crad_officer',
    'panel': 'panel',
    'student': 'student',
}
PAGES = {
    'user_mgmt': '/modules/user-management/pages/user-accounts.php',
    'crad_officer_page': '/modules/crad/pages/panelist-applications.php',
    'coordinator_home': '/modules/crad/pages/research-workspace.php',
    'assignments_page': '/modules/crad/pages/research-coordinator-management.php',
    'faculty_workspace': '/modules/faculty/pages/research-workspace.php',
    'panel_home': '/modules/faculty/pages/assigned-defenses.php',
    'student_portal': '/modules/student-portal/pages/dashboard.php',
}
results = []

def check(label, cond):
    results.append(bool(cond))
    print(('PASS ' if cond else 'FAIL ') + label)

def login(username):
    jar = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    html = op.open(BASE + '/login/login.php', timeout=20).read().decode('utf-8', 'ignore')
    tok = re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)
    op.open(urllib.request.Request(BASE + '/login/login.php', data=urllib.parse.urlencode({'username': username, 'password': PW, 'csrf_token': tok}).encode()), timeout=20)
    return op

def can_open(op, path):
    """A page counts as open only if it answers 200 at the same path. A redirect or 403 means refused."""
    try:
        r = op.open(BASE + path, timeout=20)
        return r.getcode() == 200 and urllib.parse.urlparse(r.geturl()).path == path
    except urllib.error.HTTPError:
        return False

for role, key in ROLES.items():
    account_helper('create', 'zz_test_rbac_' + role, key, PW)
try:
    matrix = {}
    for role in ROLES:
        op = login('zz_test_rbac_' + role)
        matrix[role] = {name: can_open(op, path) for name, path in PAGES.items()}

    header = 'role'.ljust(22) + ''.join(n[:14].ljust(16) for n in PAGES)
    print(header)
    for role in ROLES:
        print(role.ljust(22) + ''.join(('open' if matrix[role][n] else 'refused').ljust(16) for n in PAGES))

    check('student portal opens for students', matrix['student']['student_portal'])
    check('student portal is refused to every staff role', not any(matrix[r]['student_portal'] for r in ROLES if r != 'student'))
    check('user management opens for Super Admin', matrix['superadmin']['user_mgmt'])
    check('user management is refused to Admin (Super Admin only manages accounts)', not matrix['sms_admin']['user_mgmt'])
    check('user management is refused to Adviser, Coordinator, CRAD Officer, Panel, Student',
          not any(matrix[r]['user_mgmt'] for r in ('adviser', 'research_coordinator', 'crad_officer', 'panel', 'student')))
    check('CRAD officer pages open for the CRAD Officer', matrix['crad_officer']['crad_officer_page'])
    check('CRAD officer pages are refused to Adviser, Coordinator, Panel, Student',
          not any(matrix[r]['crad_officer_page'] for r in ('adviser', 'research_coordinator', 'panel', 'student')))
    check('coordinator home opens for the Research Coordinator', matrix['research_coordinator']['coordinator_home'])
    check('coordinator home is refused to students', not matrix['student']['coordinator_home'])
    check('assignments page is refused to the Research Coordinator (only Department Head and admin override)', not matrix['research_coordinator']['assignments_page'])
    check('faculty workspace opens for the Adviser', matrix['adviser']['faculty_workspace'])
    check('faculty workspace is refused to students', not matrix['student']['faculty_workspace'])
    check('panel home (assigned defenses) opens for the Panel Member', matrix['panel']['panel_home'])
    check('panel home is refused to students', not matrix['student']['panel_home'])
finally:
    for role in ROLES:
        account_helper('delete', 'zz_test_rbac_' + role)

print('RBAC SEPARATION RESULT:', 'PASS' if all(results) else 'FAIL', f'({sum(results)}/{len(results)})')
