<?php
declare(strict_types=1);

// Run only against a disposable database: SMS2_TEST_DB=crad_qa_...
require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/modules/crad/includes/chapter-evaluation-workflow.php';
$database = (string) getenv('SMS2_TEST_DB');
if (!str_starts_with($database, 'crad_qa_')) {
    throw new RuntimeException('Set SMS2_TEST_DB to a disposable crad_qa_ database.');
}
$pdo = new PDO('mysql:host=localhost;dbname=' . $database . ';charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    ++$checks;
    echo "PASS: $label\n";
};
$pdo->beginTransaction();
try {
    $sid = 'zz_test_gate_' . bin2hex(random_bytes(4));
    $_SESSION = ['student_id' => $sid];
    $pdo->prepare("INSERT INTO crad_title_approvals
        (student_id, student_name, submission_date, department, proposed_title,
         sdg_justification, members_json, status, coordinator_status, crad_status,
         adviser_signature_data, coordinator_signature_data, crad_signature_data)
        VALUES (?, 'Gate Leader', CURDATE(), 'CCS', 'Gate Title', '', '[]',
                'Approved', 'Approved', 'Approved', 'signed', 'signed', 'signed')")->execute([$sid]);
    $tid = (int) $pdo->lastInsertId();
    $insertGroup = $pdo->prepare("INSERT INTO crad_research_groups
        (title_approval_id, group_number, research_title, college_dept, academic_year,
         leader_id, date_assigned) VALUES (?, ?, 'Gate Title', 'CCS', '2026-2027', ?, CURDATE())");
    $insertGroup->execute([null, 'STU-' . $sid, $sid]);
    $placeholder = (int) $pdo->lastInsertId();
    $insertGroup->execute([$tid, 'RG-' . $sid, $sid]);
    $official = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO crad_research_coordinator_assignments
        (research_group_id, group_number, student_id, coordinator_name, status)
        VALUES (?, ?, ?, 'Coordinator', 'Active')")->execute([$placeholder, 'STU-' . $sid, $sid]);
    $ca = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO crad_research_adviser_assignments
        (research_group_id, group_number, student_id, adviser_name, assignment_status)
        VALUES (?, ?, ?, 'Adviser', 'Confirmed')")->execute([$placeholder, 'STU-' . $sid, $sid]);
    $check((int) (chapterRegisteredStudentGroup($pdo)['id'] ?? 0) === $official, 'Chapter submission resolves placeholder assignments');
    $check((int) (rpGetRegisteredResearchGroup($pdo, $sid, 0)['id'] ?? 0) === $official, 'Workspace resolves the same official group');
    $pdo->exec("UPDATE crad_title_approvals SET crad_signature_data = NULL WHERE id = $tid");
    $check(chapterRegisteredStudentGroup($pdo) === null, 'Missing final signature blocks submission');
    $pdo->exec("UPDATE crad_title_approvals SET crad_signature_data = 'signed', crad_status = 'Pending' WHERE id = $tid");
    $check(chapterRegisteredStudentGroup($pdo) === null, 'Pending CRAD approval blocks submission');
    $pdo->exec("UPDATE crad_title_approvals SET crad_status = 'Approved' WHERE id = $tid");
    $_SESSION['student_id'] = 'zz_test_unrelated';
    $check(chapterRegisteredStudentGroup($pdo) === null, 'Unrelated student cannot access the group');
    $_SESSION['student_id'] = $sid;
    $pdo->exec("UPDATE crad_research_coordinator_assignments SET status = 'Inactive' WHERE id = $ca");
    $check(chapterRegisteredStudentGroup($pdo) === null, 'Inactive coordinator blocks submission');
    $pdo->exec("UPDATE crad_research_coordinator_assignments SET status = 'Active', research_group_id = $official, group_number = 'RG-other' WHERE id = $ca");
    $check((int) (chapterRegisteredStudentGroup($pdo)['id'] ?? 0) === $official, 'Direct group assignment still works');
    echo "TITLE SUBMISSION GATE: $checks checks passed\n";
} finally {
    $pdo->rollBack();
}
