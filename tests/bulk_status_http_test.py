"""HTTP test for bulk account archive and restore.

Uses three dedicated zz_test_ accounts that are created and deleted by this test. Real accounts are not touched.
Usage (from the project root, with the app running at BASE):
    php -S 127.0.0.1:8080 -t .
    python3 tests/bulk_status_http_test.py http://127.0.0.1:8080
The login captcha must be off in the test database.
"""
import json, re, sys, http.cookiejar, urllib.request, urllib.parse
from common_db import db, account_helper

BASE = sys.argv[1]
ADMIN, TARGET, CRAD = 'zz_test_bulkadm', 'zz_test_bulktgt', 'zz_test_bulkcrad'
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
    data = urllib.parse.urlencode({'username': user, 'password': PW, 'csrf_token': tok}).encode()
    r = op.open(urllib.request.Request(BASE + '/login/login.php', data=data), timeout=20)
    return op, r.geturl()

def csrf_on(op, paths):
    for path in paths:
        try:
            html = op.open(BASE + path, timeout=20).read().decode('utf-8', 'ignore')
        except Exception:
            continue
        m = re.search(r'<meta name="csrf-token" content="([^"]+)"', html) or re.search(r'name="csrf_token" value="([^"]+)"', html)
        if m:
            return m.group(1)
    return ''

def post_json(op, payload, csrf):
    body = json.dumps(dict(payload, csrf_token=csrf)).encode()
    req = urllib.request.Request(BASE + '/modules/user-management/includes/save-user.php', data=body,
                                 headers={'Content-Type': 'application/json', 'Accept': 'application/json'})
    try:
        text = op.open(req, timeout=20).read().decode('utf-8', 'ignore')
    except urllib.error.HTTPError as e:
        text = e.read().decode('utf-8', 'ignore')
    try:
        return json.loads(text)
    except Exception:
        return {'ok': False, 'raw': text[:200]}

for u, role in [(ADMIN, 'superadmin'), (TARGET, 'registrar'), (CRAD, 'crad_officer')]:
    account_helper('create', u, role, PW)
try:
    admin_id = db(f"SELECT id FROM sms2_users WHERE username='{ADMIN}'")
    target_id = db(f"SELECT id FROM sms2_users WHERE username='{TARGET}'")

    sa, _ = login(ADMIN)
    csrf = csrf_on(sa, ['/modules/user-management/pages/user-accounts.php'])
    page = sa.open(BASE + '/modules/user-management/pages/user-accounts.php', timeout=20).read().decode('utf-8', 'ignore')
    check('page renders bulk bar and row checkboxes', 'id="umBulkBar"' in page and 'um-row-select' in page and 'id="umSelectAll"' in page)

    r = post_json(sa, {'action': 'bulk_set_status', 'user_ids': [int(target_id)], 'status': 'inactive'}, csrf)
    check('superadmin can archive a selected account in bulk', r.get('ok') is True and r.get('updated') == 1)
    check('database shows status inactive', db(f"SELECT status FROM sms2_users WHERE id={target_id}") == 'inactive')

    _, tgt_url = login(TARGET)
    _, dash_url = (None, None)
    t_op, _ = login(TARGET)
    landed = t_op.open(BASE + '/dashboard/index.php', timeout=20).geturl()
    check('archived account is blocked from the system (no dashboard access)', 'dashboard/index.php' not in landed)

    r = post_json(sa, {'action': 'bulk_set_status', 'user_ids': [int(admin_id), int(target_id)], 'status': 'inactive'}, csrf)
    check('cannot archive own account in a bulk request', r.get('ok') is False)
    check('the bulk request containing own account changed nothing', db(f"SELECT status FROM sms2_users WHERE id={target_id}") == 'inactive')

    r = post_json(sa, {'action': 'bulk_set_status', 'user_ids': [int(target_id)], 'status': 'locked'}, csrf)
    check('invalid status is rejected', r.get('ok') is False)
    r = post_json(sa, {'action': 'bulk_set_status', 'user_ids': [], 'status': 'inactive'}, csrf)
    check('empty selection is rejected', r.get('ok') is False)

    r = post_json(sa, {'action': 'bulk_set_status', 'user_ids': [int(target_id)], 'status': 'active'}, csrf)
    check('superadmin can restore accounts in bulk', r.get('ok') is True and db(f"SELECT status FROM sms2_users WHERE id={target_id}") == 'active')

    cad, _ = login(CRAD)
    cad_csrf = csrf_on(cad, ['/modules/crad/pages/register-proposal.php', '/modules/crad/pages/panelist-applications.php', '/dashboard/index.php'])
    r = post_json(cad, {'action': 'bulk_set_status', 'user_ids': [int(target_id)], 'status': 'inactive'}, cad_csrf)
    print('   server response for CRAD officer:', json.dumps(r)[:160])
    check('non-superadmin is refused with a permission error', r.get('ok') is not True and 'permission' in json.dumps(r).lower())
    check('the refused request changed nothing', db(f"SELECT status FROM sms2_users WHERE id={target_id}") == 'active')
finally:
    for u in (ADMIN, TARGET, CRAD):
        account_helper('delete', u)

print('BULK STATUS RESULT:', 'PASS' if all(results) else 'FAIL', f'({sum(results)}/{len(results)})')
