"""P0 end-to-end test over HTTP (To-Do: Test password change -> logout -> login -> verify key).

Uses a dedicated zz_test_ account that is created and deleted by this test. Real accounts are not touched.
Usage (from the project root, with the app running at BASE):
    php -S 127.0.0.1:8080 -t .
    python3 tests/p0_authenticator_http_test.py http://127.0.0.1:8080
The login captcha must be off in the test database (Settings > Login captcha), or the login steps will fail.
"""
import hashlib, http.cookiejar, json, re, sys, time, urllib.parse, urllib.request
from common_db import ROOT, account_helper

BASE = sys.argv[1]
USER = 'zz_test_p0http'
OLD_PW, NEW_PW = 'P0Start!2026', 'P0New!2027'
results = []

def check(label, cond):
    results.append(bool(cond))
    print(('PASS ' if cond else 'FAIL ') + label)

def csrf_from(html):
    return re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)

def opener():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def get(op, path):
    r = op.open(BASE + path, timeout=20)
    return r.geturl(), r.read().decode('utf-8', 'ignore')

def post(op, path, data):
    r = op.open(urllib.request.Request(BASE + path, data=urllib.parse.urlencode(data).encode()), timeout=20)
    return r.geturl(), r.read().decode('utf-8', 'ignore')

def login_with_authenticator(op, password):
    _, html = get(op, '/login/login.php')
    url, _ = post(op, '/login/login.php', {'username': USER, 'password': password, 'csrf_token': csrf_from(html)})
    if 'verify-2fa' not in url:
        return url
    for _ in range(2):
        _, vhtml = get(op, '/login/verify-2fa.php')
        url, _ = post(op, '/login/verify-2fa.php', {'action': 'verify', 'code': account_helper('code', USER), 'csrf_token': csrf_from(vhtml)})
        if 'verify-2fa' not in url:
            return url
        time.sleep(31)  # the same 30-second code can be rejected; wait for the next window
    return url

keyfile = ROOT / 'storage' / 'keys' / 'app.key'
account_helper('create', USER, 'superadmin', OLD_PW)
try:
    check('setup: Authenticator enabled for test account', account_helper('enable', USER) == 'enabled')
    before = json.loads(account_helper('state', USER))
    key_before = hashlib.sha256(keyfile.read_bytes()).hexdigest()

    op = opener()
    url = login_with_authenticator(op, OLD_PW)
    check('step 1: login with old password and Authenticator code reaches the dashboard', 'dashboard' in url)

    _, cp = get(op, '/login/change-password.php')
    _, body = post(op, '/login/change-password.php', {'current_password': OLD_PW, 'password': NEW_PW, 'password_confirm': NEW_PW, 'csrf_token': csrf_from(cp)})
    check('step 2: password change accepted', 'Could not update' not in body and 'do not match' not in body)

    get(op, '/login/logout.php')
    op = opener()
    url = login_with_authenticator(op, NEW_PW)
    check('step 3: after logout, login with the NEW password and Authenticator code works', 'dashboard' in url)

    after = json.loads(account_helper('state', USER))
    key_after = hashlib.sha256(keyfile.read_bytes()).hexdigest()
    check('Authenticator is still enabled after the password change', after['enabled'] is True)
    check('stored Authenticator secret is unchanged', before['secret_sha256'] == after['secret_sha256'])
    check('app key file is unchanged', key_before == key_after)
finally:
    account_helper('delete', USER)

print('P0 HTTP FLOW RESULT:', 'PASS' if all(results) else 'FAIL', f'({sum(results)}/{len(results)})')
