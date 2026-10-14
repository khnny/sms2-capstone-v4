<?php
/**
 * SMS 2 - Panel Application (student). P1.1: the group's request for a panel defense.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../modules/crad/config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/modules/crad/includes/research-group-flow.php';
require_once ROOT_PATH . '/modules/crad/includes/panel-applications.php';

requireAuth();
if (getCurrentUserRoleKey() !== 'student') {
    http_response_code(403);
    exit('Forbidden');
}

$pageTitle = 'Panel Application';
$activeModule = 'student_portal';
$activePage = 'panel-application';
$pdo = cradDb();
$studentId = strtoupper(trim((string) ($_SESSION['student_id'] ?? '')));
$group = null;
$app = null;
$notice = null;
$error = null;

if ($pdo instanceof PDO && $studentId !== '') {
    cradPanelAppEnsureSchema($pdo);
    $group = cradRgFlowGetStudentGroup($pdo, $studentId);
}
if ($group) {
    $app = cradPanelAppDraftForGroup($pdo, $group, (int) getCurrentUserId(), (string) getCurrentUserName());
}

if ($app && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['panel_action'] ?? '');
    if (!csrfVerify()) {
        $error = 'Security token expired. Refresh the page and try again.';
    } elseif ($action === 'save_details') {
        $result = cradPanelAppSaveDetails($pdo, (int) $app['id'], $_POST);
    } elseif ($action === 'upload_document') {
        $result = cradPanelAppStoreDocument($pdo, (int) $app['id'], (string) ($_POST['doc_type'] ?? ''), $_FILES['document'] ?? []);
    } elseif ($action === 'submit') {
        $result = cradPanelAppSubmit($pdo, (int) $app['id'], (int) getCurrentUserId(), (string) getCurrentUserName());
    } else {
        $result = ['ok' => false, 'error' => 'Unknown action.'];
    }
    if (isset($result)) {
        if ($result['ok']) {
            $notice = $result['message'];
        } else {
            $error = $result['error'];
        }
        unset($result);
    }
    $app = cradPanelAppFind($pdo, (int) $app['id']);
}

$editable = $app && in_array((string) $app['status'], cradPanelAppEditableStatuses(), true);
$documents = $app ? cradPanelAppDocuments($pdo, (int) $app['id']) : [];
$docByType = [];
foreach ($documents as $d) {
    $docByType[$d['doc_type']] = $d;
}
$history = $app ? cradPanelAppHistory($pdo, (int) $app['id']) : [];
$breadcrumbs = [['label' => 'Dashboard', 'href' => BASE_URL . '/dashboard/index.php'], ['label' => 'Panel Application']];
require_once ROOT_PATH . '/includes/layout-start.php';
?>
<?php renderBreadcrumbs($breadcrumbs); ?>
<h1 class="h4 mb-3">Panel Application</h1>

<?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<?php if (!$group || !$app): ?>
    <div class="alert alert-warning">No research group is linked to your student account yet, so a panel application cannot start.</div>
<?php else: ?>
<section class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <strong>Status:</strong>
            <span class="badge bg-secondary" id="panelAppStatus"><?= e(cradPanelAppStatusLabel((string) $app['status'])) ?></span>
            <span class="small text-muted">Reference <?= e((string) $app['application_ref']) ?> · <?= e((string) $app['group_number']) ?></span>
        </div>
        <?php if ($app['status'] === 'Rejected' && $app['review_remarks']): ?>
            <div class="alert alert-warning small mb-0"><strong>CRAD remarks:</strong> <?= e((string) $app['review_remarks']) ?> Edit the application and submit it again.</div>
        <?php endif; ?>
    </div>
</section>

<section class="card border-0 shadow-sm mb-4" aria-labelledby="panelDetailsTitle">
    <div class="card-header bg-white py-3"><strong id="panelDetailsTitle">Research details</strong></div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="panel_action" value="save_details">
            <div class="mb-2">
                <label class="form-label small mb-0" for="paTitle">Research title</label>
                <input class="form-control form-control-sm" id="paTitle" name="research_title" maxlength="255" value="<?= e((string) $app['research_title']) ?>" <?= $editable ? '' : 'disabled' ?> required>
            </div>
            <div class="mb-2">
                <label class="form-label small mb-0" for="paAdviser">Adviser</label>
                <input class="form-control form-control-sm" id="paAdviser" name="adviser_name" maxlength="160" value="<?= e((string) $app['adviser_name']) ?>" <?= $editable ? '' : 'disabled' ?> required>
            </div>
            <div class="mb-2">
                <label class="form-label small mb-0" for="paMembers">Group members (one name per line, up to <?= CRAD_PANEL_APP_MAX_MEMBERS ?>)</label>
                <textarea class="form-control form-control-sm" id="paMembers" name="members_text" rows="4" <?= $editable ? '' : 'disabled' ?> required><?= e((string) $app['members_text']) ?></textarea>
            </div>
            <?php if ($editable): ?>
                <button class="btn btn-sm btn-outline-primary" type="submit">Save details</button>
            <?php endif; ?>
        </form>
    </div>
</section>

<section class="card border-0 shadow-sm mb-4" aria-labelledby="panelDocsTitle">
    <div class="card-header bg-white py-3"><strong id="panelDocsTitle">Required documents</strong></div>
    <div class="card-body">
        <?php foreach (cradPanelAppDocTypes() as $type => $label): $doc = $docByType[$type] ?? null; ?>
            <div class="border rounded p-2 mb-2">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div>
                        <strong><?= e($label) ?></strong>
                        <?php if ($doc): ?>
                            <div class="small">Uploaded: <a href="<?= e(BASE_URL . '/modules/crad/panel-application-file.php?doc=' . (int) $doc['id']) ?>" target="_blank" rel="noopener"><?= e((string) $doc['original_name']) ?></a></div>
                        <?php else: ?>
                            <div class="small text-danger">Not uploaded yet</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($editable): ?>
                    <form method="post" enctype="multipart/form-data" class="mt-2 d-flex flex-wrap gap-2 align-items-center">
                        <?= csrfField() ?>
                        <input type="hidden" name="panel_action" value="upload_document">
                        <input type="hidden" name="doc_type" value="<?= e($type) ?>">
                        <input class="form-control form-control-sm" type="file" name="document" accept=".pdf,.png,.jpg,.jpeg" required>
                        <button class="btn btn-sm btn-outline-primary" type="submit"><?= $doc ? 'Replace file' : 'Upload' ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if ($editable): ?>
            <form method="post" class="mt-3">
                <?= csrfField() ?>
                <input type="hidden" name="panel_action" value="submit">
                <button class="btn btn-primary" type="submit">Submit panel application</button>
                <span class="small text-muted ms-2">Submitting locks the details and documents until CRAD responds.</span>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="card border-0 shadow-sm mb-4" aria-labelledby="panelHistoryTitle">
    <div class="card-header bg-white py-3"><strong id="panelHistoryTitle">Application history</strong></div>
    <div class="card-body table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>When</th><th>From</th><th>To</th><th>By</th><th>Remarks</th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= e(date('M j, Y g:i A', strtotime((string) $h['created_at']))) ?></td>
                    <td><?= e((string) ($h['from_status'] ?: '—')) ?></td>
                    <td><?= e((string) $h['to_status']) ?></td>
                    <td><?= e((string) ($h['actor_name'] ?: '—')) ?></td>
                    <td><?= e((string) ($h['remarks'] ?: '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
