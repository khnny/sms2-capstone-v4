"""Shared helpers for the HTTP tests. Database settings can be overridden with environment variables."""
import os, pathlib, subprocess

ROOT = pathlib.Path(__file__).resolve().parent.parent
DB_NAME = os.environ.get('SMS2_TEST_DB', 'sms2_db')
DB_USER = os.environ.get('SMS2_TEST_DB_USER', 'root')
DB_PASS = os.environ.get('SMS2_TEST_DB_PASSWORD', '')

def db(sql):
    cmd = ['mysql', '-u' + DB_USER]
    if DB_PASS:
        cmd.append('-p' + DB_PASS)
    cmd += [DB_NAME, '-N', '-e', sql]
    r = subprocess.run(cmd, capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError('SQL failed: ' + r.stderr.strip()[:300])
    return r.stdout.strip()

def account_helper(*args):
    """Runs tests/helpers/test_accounts.php. Only zz_test_* accounts can be touched."""
    r = subprocess.run(['php', 'tests/helpers/test_accounts.php', *args], cwd=ROOT, capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(r.stderr.strip() or 'helper failed')
    return r.stdout.strip()
