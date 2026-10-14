"""Chapter order test (team decision, To-Do P3.11). Uses a zz_test_ group id and its zz_test_ submission rows, removed at the end.

Rule: Chapter 1 has no prerequisite. Chapter N can be submitted only after Chapter N-1 has been submitted at least once.
Usage: php -S 127.0.0.1:8080 -t .   then   python3 tests/chapter_sequence_test.py http://127.0.0.1:8080
(The HTTP path is not used here; this checks the shared rule that both the student display and the server submit call.)
"""
import secrets, subprocess, sys
from common_db import db, ROOT

GID = 987901
results = []

def check(label, cond, note=''):
    results.append(bool(cond))
    print(('PASS ' if cond else 'FAIL ') + label + (f'  [{note}]' if note else ''))

def php(expr):
    r = subprocess.run(['php', '-r', 'require "config/config.php"; require_once ROOT_PATH."/modules/crad/config/config.php"; require_once ROOT_PATH."/modules/crad/includes/research-progress-helpers.php"; $crad = cradDb(); ' + expr],
                       cwd=ROOT, capture_output=True, text=True)
    return r.stdout.strip(), r.stderr.strip()

def may(chapter):
    out, err = php(f'echo rpPreviousChapterSubmitted($crad, {GID}, {chapter}) ? "yes" : "no";')
    return out == 'yes', err

def add_submission(chapter, version=1):
    db(f"INSERT INTO crad_chapter_submissions (research_group_id, chapter_number, version_number, status, submitted_by_name, submitted_by_email, original_name, stored_subdir, stored_name, file_size, file_mime, submission_token, submitted_at) "
       f"VALUES ({GID}, {chapter}, {version}, 'Submitted', 'Test', 'zz_test_seq@example.test', 'zz.pdf', 'zz', 'zz_{secrets.token_hex(4)}.pdf', 10, 'application/pdf', '{secrets.token_hex(16)}', NOW())")

def cleanup():
    db(f"DELETE FROM crad_chapter_submissions WHERE research_group_id = {GID}")

cleanup()
try:
    ok, err = may(1)
    check('Chapter 1 has no prerequisite', ok, err)
    ok, err = may(2)
    check('Chapter 2 is blocked before Chapter 1 is submitted', not ok, err)
    out, err = php(f'echo rpPreviousChapterMessage(2);')
    check('the message tells the student to submit Chapter 1 first', 'Submit Chapter 1 first' in out, out)

    add_submission(1)
    ok, err = may(2)
    check('Chapter 2 opens after Chapter 1 is submitted', ok, err)
    ok, err = may(3)
    check('Chapter 3 is still blocked until Chapter 2 is submitted', not ok, err)

    add_submission(2)
    ok, err = may(3)
    check('Chapter 3 opens after Chapter 2 is submitted', ok, err)
    add_submission(1, version=2)
    ok, err = may(3)
    check('a second Chapter 1 version does not replace the Chapter 2 requirement', ok, err)
finally:
    cleanup()

print('CHAPTER SEQUENCE RESULT:', 'PASS' if all(results) else 'FAIL', f'({sum(results)}/{len(results)})')
