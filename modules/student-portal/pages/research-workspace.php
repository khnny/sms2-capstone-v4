<?php
declare(strict_types=1);

/**
 * Student Research Workspace: the CRAD Research Hub. The whole CRAD process on
 * one page as step cards; uploads and forms open in a pop-up on this page.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/breadcrumbs.php';

requireAuth();
if (getCurrentUserRoleKey() !== 'student') {
    http_response_code(403);
    exit('Forbidden');
}

$pageTitle = 'Research Workspace';
$activeModule = 'student_portal';
$activePage = 'research-workspace';
$pageBannerIcon = 'fa-flask';
$pageBannerDescription = 'Every step of your CRAD research process on one page. Open a step to upload or fill it in without leaving.';

$breadcrumbs = [
    ['label' => 'Student Portal', 'url' => BASE_URL . '/modules/student-portal/pages/dashboard.php'],
    ['label' => 'Research Workspace', 'url' => null],
];

$hubHtml = '';
$hubError = '';
try {
    require_once ROOT_PATH . '/includes/integration.php';
    require_once ROOT_PATH . '/includes/integration-data.php';
    require_once ROOT_PATH . '/includes/research-statement.php';
    require_once ROOT_PATH . '/includes/research-hub-view.php';
    $hubUser = smsApiLoadUser((int) ($_SESSION['user_id'] ?? 0));
    if (!$hubUser) {
        throw new RuntimeException('Student account not found.');
    }
    $hubStatement = smsResearchStatement(getCradDatabaseConnection(), $hubUser);
    $hubHtml = smsRenderResearchHub($hubStatement, (string) BASE_URL);
} catch (Throwable $e) {
    error_log('Research hub: ' . $e->getMessage());
    $hubError = 'Your research steps could not be loaded right now. Please refresh the page or try again later.';
}

require_once ROOT_PATH . '/includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<link href="<?= BASE_URL ?>/assets/css/research-statement.css?v=2" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/research-hub.css?v=1" rel="stylesheet">

<?php if ($hubError !== ''): ?>
    <div class="alert alert-warning" role="alert"><?= htmlspecialchars($hubError, ENT_QUOTES, 'UTF-8') ?></div>
<?php else: ?>
    <?= $hubHtml ?>
<?php endif; ?>

<script src="<?= BASE_URL ?>/assets/js/research-hub.js?v=1" defer></script>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
