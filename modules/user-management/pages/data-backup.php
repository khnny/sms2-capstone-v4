<?php
/**
 * SMS 2 – User Management – Data Backup (Google Drive)
 * Super Admin: connect Drive, back up now, list / download / restore backups.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/gdrive-backup.php';

$pageTitle    = 'Data Backup';
$activeModule = 'user-management';
$activePage   = 'data-backup';
$breadcrumbs  = [
    ['label' => 'User Management', 'url' => BASE_URL . '/modules/user-management/index.php'],
    ['label' => 'Data Backup',     'url' => null],
];

requireAuth();
requireSuperAdmin();

$self = BASE_URL . '/modules/user-management/pages/data-backup.php';
$who = (string) ($_SESSION['user_name'] ?? 'Super Admin');

/* ── Download a backup (streams from Drive) ─────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['download'])) {
    try {
        $meta = smsGdriveGetBackup((string) $_GET['download']);
        @set_time_limit(0);
        session_write_close();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $meta['name']) . '"');
        if (!empty($meta['size'])) {
            header('Content-Length: ' . (string) $meta['size']);
        }
        header('Cache-Control: no-store');
        logActivity('export', 'Downloaded Google Drive backup ' . (string) $meta['name'], 'user-management');
        smsGdriveDownload((string) $meta['id'], 'php://output');
    } catch (Throwable $e) {
        if (!headers_sent()) {
            $_SESSION['flash_backup_error'] = 'Download failed: ' . $e->getMessage();
            header('Location: ' . $self);
        }
    }
    exit;
}

/* ── POST actions ───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['backup_action'] ?? '');
    if (!csrfVerify()) {
        $_SESSION['flash_backup_error'] = 'Security check failed. Please try again.';
    } elseif ($action === 'backup_now') {
        session_write_close(); // do not lock the user's other tabs while this runs
        $result = smsBackupRun('manual', $who);
        session_start();
        if ($result['ok']) {
            $_SESSION['flash_backup_success'] = 'Backup uploaded to Google Drive: ' . $result['file_name']
                . ' (' . smsBackupFormatBytes($result['size']) . ')'
                . ($result['deleted'] > 0 ? '. Removed ' . $result['deleted'] . ' old backup(s).' : '.');
        } else {
            $_SESSION['flash_backup_error'] = 'Backup failed: ' . $result['error'];
        }
    } elseif ($action === 'disconnect') {
        smsGdriveDisconnect();
        logActivity('update', 'Disconnected Google Drive backups', 'user-management');
        $_SESSION['flash_backup_success'] = 'Google Drive disconnected. Automatic backups are paused until you connect again.';
    } elseif ($action === 'restore') {
        $confirm = trim((string) ($_POST['confirm_text'] ?? ''));
        if ($confirm !== 'RESTORE') {
            $_SESSION['flash_backup_error'] = 'Type RESTORE (all capitals) to confirm the restore.';
        } else {
            session_write_close();
            $result = smsBackupRestore((string) ($_POST['file_id'] ?? ''), $who);
            session_start();
            if ($result['ok']) {
                $_SESSION['flash_backup_success'] = 'Restore finished: ' . $result['statements'] . ' SQL statements and '
                    . $result['files'] . ' files restored. A safety copy of the data before the restore was saved as '
                    . $result['safety'] . '.';
            } else {
                $_SESSION['flash_backup_error'] = 'Restore failed: ' . $result['error'];
            }
        }
    }
    header('Location: ' . $self);
    exit;
}

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';

$flashSuccess = (string) ($_SESSION['flash_backup_success'] ?? '');
$flashError = (string) ($_SESSION['flash_backup_error'] ?? '');
unset($_SESSION['flash_backup_success'], $_SESSION['flash_backup_error']);

$configured = smsGdriveIsConfigured();
$connected = smsGdriveIsConnected();
$accountEmail = smsSetting('gdrive_account_email', '');
$connectedAt = smsSetting('gdrive_connected_at', '');
$cronReady = smsBackupCronToken() !== '';
$zipReady = class_exists('ZipArchive');
$redirectUri = smsGdriveRedirectUri();
$cronUrl = smsBackupAbsoluteUrl('backup/run.php');

$driveFiles = [];
$driveError = '';
$folderId = '';
if ($configured && $connected) {
    try {
        $driveFiles = smsGdriveListBackups();
        $folderId = smsSetting('gdrive_folder_id', '');
    } catch (Throwable $e) {
        $driveError = $e->getMessage();
    }
}
$runs = smsBackupRecentRuns(15);
$lastRun = $runs[0] ?? null;
$lastSuccess = null;
foreach ($runs as $run) {
    if ($run['status'] === 'success') {
        $lastSuccess = $run;
        break;
    }
}
$triggerLabels = ['cron' => 'Automatic', 'manual' => 'Manual', 'cli' => 'Command line', 'pre-restore' => 'Before restore'];
$fmtDate = static fn (?string $v): string => $v ? date('M j, Y g:i A', strtotime($v)) : '—';
?>

<link href="<?= BASE_URL ?>/modules/user-management/assets/css/user-management.css" rel="stylesheet">

<?php
$pageBannerIcon        = 'fa-cloud-upload-alt';
$pageBannerDescription = 'Automatic backups of the SMS2 + CRAD database and uploaded files to Google Drive.';
renderBreadcrumbs($breadcrumbs);
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div></div>
    <span class="placeholder-badge"><?= smsIcon('lock', ['class' => 'me-1']) ?>Superadmin Only</span>
</div>

<?php if ($flashSuccess !== ''): ?>
    <div class="alert alert-success"><?= e($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError !== ''): ?>
    <div class="alert alert-danger"><?= e($flashError) ?></div>
<?php endif; ?>
<?php if ($lastRun && $lastRun['status'] === 'failed' && $lastRun['trigger_type'] !== 'pre-restore'): ?>
    <div class="alert alert-warning">
        <strong>The last backup failed</strong> (<?= e($fmtDate($lastRun['started_at'])) ?>): <?= e((string) $lastRun['message']) ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">

        <!-- Connection -->
        <section class="card mb-4">
            <div class="card-body">
                <div class="settings-section-head">
                    <div class="settings-icon" style="background:linear-gradient(145deg,#1a73e8,#34a853);">
                        <?= smsIcon('cloud-upload-alt') ?>
                    </div>
                    <div>
                        <h6>Google Drive</h6>
                        <p>Backups are saved to the "<?= e(smsBackupFolderName()) ?>" folder of the connected Google account.</p>
                    </div>
                </div>

                <ul class="list-unstyled small mb-3">
                    <li class="mb-1"><?= $configured ? '✅' : '⚠️' ?> OAuth client
                        <?= $configured ? 'configured' : 'not configured yet' ?></li>
                    <li class="mb-1"><?= $connected ? '✅' : '⚠️' ?>
                        <?php if ($connected): ?>
                            Connected<?= $accountEmail !== '' ? ' as <strong>' . e($accountEmail) . '</strong>' : '' ?>
                            <?= $connectedAt !== '' ? '<span class="text-muted">since ' . e($fmtDate($connectedAt)) . '</span>' : '' ?>
                        <?php else: ?>
                            Not connected
                        <?php endif; ?>
                    </li>
                    <li class="mb-1"><?= $cronReady ? '✅' : '⚠️' ?> Daily schedule token
                        <?= $cronReady ? 'set' : 'not set (SMS2_BACKUP_CRON_TOKEN)' ?></li>
                    <li class="mb-1"><?= $zipReady ? '✅' : '❌' ?> PHP zip extension
                        <?= $zipReady ? 'enabled' : 'missing: enable extension=zip in php.ini' ?></li>
                    <li><?= smsIcon('history', ['class' => 'me-1']) ?>Last successful backup:
                        <strong><?= e($lastSuccess ? $fmtDate($lastSuccess['finished_at']) : 'none yet') ?></strong></li>
                </ul>

                <div class="d-flex flex-wrap gap-2">
                    <?php if ($configured && $connected): ?>
                        <form method="POST" class="m-0" data-backup-busy="Backing up… this can take a few minutes.">
                            <?= csrfField() ?>
                            <input type="hidden" name="backup_action" value="backup_now">
                            <button type="submit" class="btn btn-sms-primary btn-sm">
                                <?= smsIcon('cloud-upload-alt', ['class' => 'me-2']) ?>Back up now
                            </button>
                        </form>
                        <?php if ($folderId !== ''): ?>
                            <a class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener"
                               href="https://drive.google.com/drive/folders/<?= e(rawurlencode($folderId)) ?>">Open Drive folder</a>
                        <?php endif; ?>
                        <form method="POST" class="m-0" onsubmit="return confirm('Disconnect Google Drive? Automatic backups will stop until you connect again.');">
                            <?= csrfField() ?>
                            <input type="hidden" name="backup_action" value="disconnect">
                            <button type="submit" class="btn btn-outline-danger btn-sm">Disconnect</button>
                        </form>
                    <?php elseif ($configured): ?>
                        <form method="POST" action="<?= e(BASE_URL . '/backup/google-connect.php') ?>" class="m-0">
                            <?= csrfField() ?>
                            <button type="submit" class="btn btn-sms-primary btn-sm">Connect Google Drive</button>
                        </form>
                    <?php endif; ?>
                </div>
                <p class="busy-note text-muted small mt-2 mb-0 d-none" id="backupBusyNote"></p>
            </div>
        </section>

        <!-- Setup help -->
        <section class="card mb-4">
            <div class="card-body small">
                <h6 class="mb-2">Setup checklist</h6>
                <ol class="ps-3 mb-2">
                    <li class="mb-1">Put the OAuth client in <code>storage/keys/google-oauth-client.json</code> (the JSON from Google Cloud),
                        or set <code>SMS2_GDRIVE_CLIENT_ID</code> and <code>SMS2_GDRIVE_CLIENT_SECRET</code>.</li>
                    <li class="mb-1">In Google Cloud, the OAuth client must list this redirect URI:<br>
                        <code class="user-select-all"><?= e($redirectUri) ?></code></li>
                    <li class="mb-1">Click <strong>Connect Google Drive</strong> and sign in with the school Google account.</li>
                    <li class="mb-1">Set <code>SMS2_BACKUP_CRON_TOKEN</code> (random, 16+ characters), then add a daily job on cron-job.org:<br>
                        <code class="user-select-all"><?= e($cronUrl) ?></code><br>
                        with header <code>X-Backup-Token: &lt;your token&gt;</code>.</li>
                </ol>
                <p class="text-muted mb-0">Keeps the newest backup of the last <?= smsBackupKeepDaily() ?> days plus one per week for
                    <?= smsBackupKeepWeekly() ?> weeks before that. Older ones are deleted from Drive automatically.
                    Full guide: <code>docs/DATA-BACKUP.md</code>.</p>
            </div>
        </section>
    </div>

    <div class="col-lg-7">
        <!-- Backups in Drive -->
        <section class="card mb-4">
            <div class="card-body">
                <h6 class="mb-3">Backups in Google Drive</h6>
                <?php if ($driveError !== ''): ?>
                    <div class="alert alert-danger small mb-0"><?= e($driveError) ?></div>
                <?php elseif (!$connected): ?>
                    <p class="text-muted small mb-0">Connect Google Drive to see backups.</p>
                <?php elseif (!$driveFiles): ?>
                    <p class="text-muted small mb-0">No backups yet. Click "Back up now" to make the first one.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="text-nowrap"><tr><th>Date</th><th>Type</th><th class="text-end">Size</th><th class="text-end">Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($driveFiles as $file): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= e($fmtDate((string) $file['createdTime'])) ?></div>
                                        <div class="text-muted small"><?= e((string) $file['name']) ?></div>
                                    </td>
                                    <td><?= e($triggerLabels[$file['appProperties']['trigger'] ?? ''] ?? '—') ?></td>
                                    <td class="text-end text-nowrap"><?= e(smsBackupFormatBytes((int) ($file['size'] ?? 0))) ?></td>
                                    <td class="text-end text-nowrap">
                                        <a class="btn btn-outline-secondary btn-sm" href="<?= e($self . '?download=' . rawurlencode((string) $file['id'])) ?>">Download</a>
                                        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#restoreModal"
                                                data-restore-id="<?= e((string) $file['id']) ?>"
                                                data-restore-name="<?= e($fmtDate((string) $file['createdTime']) . ' — ' . $file['name']) ?>">Restore</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Run log -->
        <section class="card mb-4">
            <div class="card-body">
                <h6 class="mb-3">Backup history</h6>
                <?php if (!$runs): ?>
                    <p class="text-muted small mb-0">No backup has run yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="text-nowrap"><tr><th>Started</th><th>Type</th><th>Status</th><th class="text-end">Size</th><th>Details</th></tr></thead>
                            <tbody>
                            <?php foreach ($runs as $run): ?>
                                <?php
                                $badge = ['success' => 'bg-success', 'failed' => 'bg-danger', 'running' => 'bg-info'][$run['status']] ?? 'bg-secondary';
                                ?>
                                <tr>
                                    <td class="text-nowrap"><?= e($fmtDate($run['started_at'])) ?></td>
                                    <td class="text-nowrap"><?= e($triggerLabels[$run['trigger_type']] ?? $run['trigger_type']) ?>
                                        <?php if (!empty($run['started_by']) && !str_starts_with((string) $run['started_by'], 'System')): ?><div class="text-muted"><?= e((string) $run['started_by']) ?></div><?php endif; ?></td>
                                    <td><span class="badge <?= $badge ?>"><?= e(ucfirst((string) $run['status'])) ?></span></td>
                                    <td class="text-end text-nowrap"><?= $run['size_bytes'] !== null ? e(smsBackupFormatBytes((int) $run['size_bytes'])) : '—' ?></td>
                                    <td><?= e((string) ($run['message'] ?? '')) ?>
                                        <?php if ((int) $run['deleted_count'] > 0): ?><div class="text-muted">Removed <?= (int) $run['deleted_count'] ?> old backup(s)</div><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<!-- Restore confirmation -->
<div class="modal fade" id="restoreModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" data-backup-busy="Restoring… do not close this page.">
            <?= csrfField() ?>
            <input type="hidden" name="backup_action" value="restore">
            <input type="hidden" name="file_id" id="restoreFileId" value="">
            <div class="modal-header">
                <h5 class="modal-title">Restore backup</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body small">
                <p class="mb-2">You are restoring: <strong id="restoreFileName"></strong></p>
                <div class="alert alert-warning py-2">
                    This <strong>replaces the whole database</strong> (all SMS2 and CRAD data) with the data from that backup,
                    and puts back the uploaded files. Changes made after that backup will be lost.
                    A safety backup of the current data is uploaded to Drive first.
                </div>
                <label class="form-label fw-semibold" for="restoreConfirm">Type <code>RESTORE</code> to confirm</label>
                <input type="text" class="form-control" id="restoreConfirm" name="confirm_text" autocomplete="off" required pattern="RESTORE">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger btn-sm">Restore now</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('restoreModal');
    if (modal) {
        modal.addEventListener('show.bs.modal', function (ev) {
            var btn = ev.relatedTarget;
            if (!btn) { return; }
            document.getElementById('restoreFileId').value = btn.getAttribute('data-restore-id') || '';
            document.getElementById('restoreFileName').textContent = btn.getAttribute('data-restore-name') || '';
            document.getElementById('restoreConfirm').value = '';
        });
    }
    document.querySelectorAll('form[data-backup-busy]').forEach(function (form) {
        form.addEventListener('submit', function () {
            form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
            var note = document.getElementById('backupBusyNote');
            if (note) {
                note.textContent = form.getAttribute('data-backup-busy');
                note.classList.remove('d-none');
            }
        });
    });
})();
</script>

<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
