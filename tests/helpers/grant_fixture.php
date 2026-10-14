<?php
/**
 * Test fixture for tests/grants_approval_test.py. Creates and deletes zz_test_ grant applications only.
 * Usage (from the project root):
 *   php tests/helpers/grant_fixture.php create          prints the new application id
 *   php tests/helpers/grant_fixture.php delete <id>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/modules/crad/config/config.php';
require_once ROOT_PATH . '/modules/crad/includes/grant-approval-helpers.php';

$pdo = cradDb();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "CRAD database unavailable.\n");
    exit(1);
}
grantEnsureApprovalTables($pdo);

$cmd = $argv[1] ?? '';
if ($cmd === 'create') {
    $reference = 'zz_test_grant_' . bin2hex(random_bytes(4));
    $pdo->prepare(
        "INSERT INTO crad_grant_opportunities (funding_title, application_deadline, max_funding_cap, eligibility, status)
         VALUES (?, CURDATE() + INTERVAL 30 DAY, 100000, 'zz_test', 'Open for Application')"
    )->execute(['zz_test_opportunity_' . $reference]);
    $opportunityId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO crad_grant_applications
            (grant_opportunity_id, proposal_reference, research_title, applicant_name, college_dept, group_number, status, submitted_at)
         VALUES (?, ?, ?, 'Test Applicant', 'Test', 'zz_test_g', 'Submitted', NOW())"
    )->execute([$opportunityId, $reference, $reference]);
    $id = (int) $pdo->lastInsertId();
    $result = grantStartApprovalWorkflow($pdo, $id);
    if (empty($result['ok'])) {
        fwrite(STDERR, (string) ($result['error'] ?? 'Workflow could not start.') . "\n");
        exit(1);
    }
    echo $id . "\n";
    exit(0);
}
if ($cmd === 'delete') {
    $id = (int) ($argv[2] ?? 0);
    if ($id <= 0) {
        exit(1);
    }
    $tables = $pdo->query("SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'grant_application_id'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $pdo->prepare('DELETE FROM `' . str_replace('`', '', (string) $table) . '` WHERE grant_application_id = ?')->execute([$id]);
    }
    $pdo->prepare('DELETE FROM crad_grant_proposal_approval_steps WHERE grant_application_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM crad_grant_proposal_approval_workflows WHERE grant_application_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM crad_grant_applications WHERE id = ? AND proposal_reference LIKE ?')->execute([$id, 'zz_test_grant_%']);
    $pdo->prepare("DELETE FROM crad_grant_opportunities WHERE funding_title LIKE 'zz_test_opportunity_%' AND id NOT IN (SELECT grant_opportunity_id FROM crad_grant_applications)")->execute();
    echo "deleted\n";
    exit(0);
}
fwrite(STDERR, "Unknown command.\n");
exit(1);
