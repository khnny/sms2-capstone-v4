"""Account deactivation test (To-Do P2.10). Uses zz_test_ accounts, removed at the end.

Checks: an archived account (stored as inactive, the only archive value the database allows) cannot log in; suspended too; an account that is archived
while logged in loses access on the next page load; a restored account can log in again.
Usage: php -S 127.0.0.1:8080 -t .   then   python3 tests/account_deactivation_test.py http://127.0.0.1:8080
"""
import http.cookiejar, re, sys, urllib.parse, urllib.request
from common_db import db, account_helper

BASE = sys.argv[1]
PW = 'TestPass!2026'
results = []

def check(label, cond, note=''):
    results.append(bool(cond))
    print(('PASS ' if cond else 'FAIL ') + label + (f'  [{note}]' if note else ''))

def opener():
    jar = http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

def login(op, user):
    h = op.open(BASE + '/login/login.php', timeout=20).read().decode('utf-8', 'ignore')
    tok = re.search(r'name="csrf_token" value="([^"]+)"', h).group(1)
    r = op.open(urllib.request.Request(BASE + '/login/login.php', data=urllib.parse.urlencode({'username': user, 'password': PW, 'csrf_token': tok}).encode()), timeout=20)
    return 'login' not in r.geturl().rsplit('/', 1)[-1]

def page_is_login(op, path='/dashboard/index.php'):
    r = op.open(BASE + path, timeout=20)
    return 'login' in r.geturl().rsplit('/', 1)[-1]

def set_status(user, status):
    db(f"UPDATE sms2_users SET status = '{status}' WHERE username = '{user}'")

def cleanup(users):
    for u in users:
        try:
            db(f"DELETE FROM sms2_login_throttles WHERE username = '{u}'")
        except Exception:
            pass
        account_helper('delete', u)

USERS = {'archived': 'zz_test_dea_arch', 'inactive': 'zz_test_dea_inac', 'suspended': 'zz_test_dea_susp', 'live': 'zz_test_dea_live'}
cleanup(list(USERS.values()))
for u in USERS.values():
    account_helper('create', u, 'adviser', PW)
try:
    set_status(USERS['archived'], 'inactive')
    set_status(USERS['inactive'], 'inactive')
    set_status(USERS['suspended'], 'suspended')
    check('an archived (inactive) account cannot log in', not login(opener(), USERS['archived']))
    check('an inactive account cannot log in (baseline)', not login(opener(), USERS['inactive']))
    check('a suspended account cannot log in (baseline)', not login(opener(), USERS['suspended']))

    live = opener()
    check('an active account logs in', login(live, USERS['live']))
    check('the logged-in account can open a page', not page_is_login(live))
    set_status(USERS['live'], 'inactive')
    check('an account archived while logged in loses access on the next page', page_is_login(live))

    set_status(USERS['live'], 'active')
    check('a restored account can log in again', login(opener(), USERS['live']))
finally:
    cleanup(list(USERS.values()))

print('ACCOUNT DEACTIVATION RESULT:', 'PASS' if all(results) else 'FAIL', f'({sum(results)}/{len(results)})')
