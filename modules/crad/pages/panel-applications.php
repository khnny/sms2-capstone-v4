<?php
/**
 * SMS 2 - Panel Applications (CRAD review). P1.1: start the review, approve or reject, then schedule and complete.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/modules/crad/includes/panel-applications.php';

requireAuth();
if (!smsRoleAllowedForModule(['crad_officer'], 'crad')) {
    http_response_code(403);
    exit('Forbidden');
}

$pageTitle = 'Panel Applications';
$activeModule = 'crad';
$activePage = 'panel-applications';
$pdo = cradDb();
$notice = null;
$error = null;
$actions = [
    'start_review' => 'Under Review',
    'approve' => 'Approved',
    'reject' => 'Rejected',
    'schedule' => 'Scheduled',
    'complete' => 'Completed',
];

if ($pdo instanceof PDO) {
    cradPanelAppEnsureSchema($pdo);
}
if ($pdo instanceof PDO && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['panel_action'] ?? '');
    if (!csrfVerify()) {
        $error = 'Security token expired. Refresh the page and try again.';
    } elseif (!isset($actions[$action])) {
        $error = 'Unknown action.';
    } else {
        $result = cradPanelAppTransition($pdo, (int) ($_POST['application_id'] ?? 0), $actions[$action], (int) getCurrentUserId(), (string) getCurrentUserName(), (string) ($_POST['remarks'] ?? ''));
        if ($result['ok']) {
            $notice = $result['message'];
        } else {
            $error = $result['error'];
        }
    }
}

$selectedId = (int) ($_GET['id'] ?? $_POST['application_id'] ?? 0);
$list = [];
$selected = null;
if ($pdo instanceof PDO) {
    $list = $pdo->query(
        "SELECT id, application_ref, group_number, research_title, status, submitted_at
           FROM `" . CRAD_PANEL_APP_TABLE . "`
          WHERE status <> 'Draft'
          ORDER BY (submitted_at IS NULL), submitted_at DESC, id DESC
          LIMIT 200"
    )->fetchAll() ?: [];
    if ($selectedId > 0) {
        $selected = cradPanelAppFind($pdo, $selectedId);
    }
}
$documents = $selected ? cradPanelAppDocuments($pdo, (int) $selected['id']) : [];
$history = $selected ? cradPanelAppHistory($pdo, (int) $selected['id']) : [];
$breadcrumbs = [['label' => 'CRAD', 'href' => BASE_URL . '/modules/crad/pages/research-defense-scheduling.php'], ['label' => 'Panel Applications']];
require_once ROOT_PATH . '/includes/layout-start.php';
?>
<?php renderBreadcrumbs($breadcrumbs); ?>
<h1 class="h4 mb-3">Panel Applications</h1>
<?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<section class="card border-0 shadow-sm mb-4">
    <div class="card-body table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Reference</th><th>Group</th><th>Title</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($list as $row): ?>
                <tr>
                    <td><?= e((string) $row['application_ref']) ?></td>
                    <td><?= e((string) $row['group_number']) ?></td>
                    <td><?= e((string) $row['research_title']) ?></td>
                    <td><span class="badge bg-secondary"><?= e((string) $row['status']) ?></span></td>
                    <td><?= e($row['submitted_at'] ? date('M j, Y', strtotime((string) $row['submitted_at'])) : '—') ?></td>
                    <td><a href="?id=<?= (int) $row['id'] ?>">Review</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$list): ?><tr><td colspan="6" class="text-muted">No submitted panel applications yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($selected): ?>
<section class="card border-0 shadow-sm mb-4" aria-labelledby="reviewTitle">
    <div class="card-header bg-white py-3"><strong id="reviewTitle"><?= e((string) $selected['application_ref']) ?> · <?= e((string) $selected['group_number']) ?></strong></div>
    <div class="card-body">
        <p class="mb-1"><strong>Status:</strong> <span class="badge bg-secondary" id="reviewStatus"><?= e((string) $selected['status']) ?></span></p>
        <p class="mb-1"><strong>Title:</strong> <?= e((string) $selected['research_title']) ?></p>
        <p class="mb-1"><strong>Adviser:</strong> <?= e((string) $selected['adviser_name']) ?></p>
        <p class="mb-2"><strong>Members:</strong><br><?= nl2br(e((string) $selected['members_text'])) ?></p>
        <?php foreach ($documents as $doc): ?>
            <div class="small mb-1"><a href="<?= e(BASE_URL . '/modules/crad/panel-application-file.php?doc=' . (int) $doc['id']) ?>" target="_blank" rel="noopener"><?= e((string) $doc['original_name']) ?></a> · <?= e((string) $doc['doc_type']) ?></div>
        <?php endforeach; ?>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <?php foreach ($actions as $key => $to): ?>
                <form method="post" class="d-flex gap-1 align-items-center">
                    <?= csrfField() ?>
                    <input type="hidden" name="panel_action" value="<?= e($key) ?>">
                    <input type="hidden" name="application_id" value="<?= (int) $selected['id'] ?>">
                    <?php if ($key === 'reject'): ?><input class="form-control form-control-sm" name="remarks" placeholder="Reason (required)" minlength="5" required><?php endif; ?>
                    <button class="btn btn-sm btn-outline-primary" type="submit"><?= e($to === 'Under Review' ? 'Start review' : ($to === 'Scheduled' ? 'Mark scheduled' : ($to === 'Completed' ? 'Mark completed' : $to))) ?></button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<section class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3"><strong>History</strong></div>
    <div class="card-body table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>When</th><th>From</th><th>To</th><th>By</th><th>Remarks</th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr><td><?= e(date('M j, Y g:i A', strtotime((string) $h['created_at']))) ?></td><td><?= e((string) ($h['from_status'] ?: '—')) ?></td><td><?= e((string) $h['to_status']) ?></td><td><?= e((string) ($h['actor_name'] ?: '—')) ?></td><td><?= e((string) ($h['remarks'] ?: '')) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
