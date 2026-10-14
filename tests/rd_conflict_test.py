"""Research Director scheduling checks (To-Do P1.3: detect scheduling conflicts). Pure functions, no database rows.

Checks: a panelist's saved windows must cover the slot (outside time is flagged, inside time is not, a day with no
window is flagged); a venue already in use at that time conflicts; the same group conflicts; a shared panelist
conflicts; a different venue with no shared people does not conflict; a non-overlapping time does not conflict.
Usage: python3 tests/rd_conflict_test.py
"""
import json, subprocess
from common_db import ROOT

PHP = r'''
require "config/config.php";
require_once ROOT_PATH . "/modules/faculty/includes/rd-scheduling-optimizer.php";
$rows = [["panel_name" => "Panelist A", "availability_windows_json" => json_encode(["Monday" => [["start" => "09:00", "end" => "12:00"]]])]];
$cases = [
  "inside_window" => rdScheduleAiPanelsOutsideAvailability($rows, "2031-03-17", "10:00", "11:00"),
  "outside_window" => rdScheduleAiPanelsOutsideAvailability($rows, "2031-03-17", "13:00", "14:00"),
  "day_without_window" => rdScheduleAiPanelsOutsideAvailability($rows, "2031-03-18", "10:00", "11:00"),
  "no_windows_saved" => rdScheduleAiPanelsOutsideAvailability([["panel_name" => "Panelist B", "availability_windows_json" => ""]], "2031-03-17", "13:00", "14:00"),
];
$b = [["start_at" => "2031-03-17 10:00:00", "end_at" => "2031-03-17 11:00:00", "is_own_group" => false, "venue_id" => 5, "shares_adviser" => false, "shares_panel" => false]];
$conf = [
  "same_venue_overlap" => rdScheduleAiHasConflict($b, 1, 5, "2031-03-17 10:30:00", "2031-03-17 11:30:00"),
  "other_venue_no_share" => rdScheduleAiHasConflict($b, 1, 9, "2031-03-17 10:30:00", "2031-03-17 11:30:00"),
  "no_overlap" => rdScheduleAiHasConflict($b, 1, 5, "2031-03-17 11:00:00", "2031-03-17 12:00:00"),
  "own_group_overlap" => rdScheduleAiHasConflict([["start_at" => "2031-03-17 10:00:00", "end_at" => "2031-03-17 11:00:00", "is_own_group" => true, "venue_id" => 2]], 1, 9, "2031-03-17 10:30:00", "2031-03-17 11:30:00"),
  "shared_panel_overlap" => rdScheduleAiHasConflict([["start_at" => "2031-03-17 10:00:00", "end_at" => "2031-03-17 11:00:00", "is_own_group" => false, "venue_id" => 2, "shares_panel" => true]], 1, 9, "2031-03-17 10:30:00", "2031-03-17 11:30:00"),
];
echo json_encode(["availability" => $cases, "conflict" => $conf]);
'''

r = subprocess.run(['php', '-r', PHP], cwd=ROOT, capture_output=True, text=True)
if r.returncode != 0 or not r.stdout.strip():
    raise SystemExit('PHP check failed: ' + (r.stderr or r.stdout)[:300])
data = json.loads(r.stdout.strip())
av, cf = data['availability'], data['conflict']
results = []

def check(label, cond, note=''):
    results.append(bool(cond))
    print(('PASS ' if cond else 'FAIL ') + label + (f'  [{note}]' if note else ''))

def flagged(v):
    return 'Panelist A' in json.dumps(v) or (isinstance(v, list) and len(v) > 0)

check('a slot inside the panelist window is not flagged', not flagged(av['inside_window']), json.dumps(av['inside_window']))
check('a slot outside the panelist window is flagged', flagged(av['outside_window']), json.dumps(av['outside_window']))
check('a day with no window is flagged', flagged(av['day_without_window']), json.dumps(av['day_without_window']))
check('a panelist with no windows saved is not flagged', not flagged(av['no_windows_saved']), json.dumps(av['no_windows_saved']))
check('the same venue at the same time conflicts', cf['same_venue_overlap'] is True)
check('a different venue with no shared people does not conflict', cf['other_venue_no_share'] is False)
check('a non-overlapping time does not conflict', cf['no_overlap'] is False)
check('the same group at the same time conflicts', cf['own_group_overlap'] is True)
check('a shared panelist at the same time conflicts', cf['shared_panel_overlap'] is True)
print('RD CONFLICT RESULT:', 'PASS' if all(results) else 'FAIL', f'({sum(results)}/{len(results)})')
