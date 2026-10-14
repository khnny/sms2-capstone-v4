<?php
/**
 * Panel Application (To-Do P1.1): a research group asks CRAD for a panel defense.
 *
 * Flow: Draft -> Submitted -> Under Review -> Approved -> Scheduled -> Completed.
 * A Rejected application is edited and resubmitted by the student (Rejected -> Submitted).
 * The student fills in the details and uploads the required documents, then submits.
 * CRAD starts the review, then approves or rejects (remarks required), then marks scheduled and completed.
 * Every status change writes a history row. Documents are stored under storage/uploads/panel_applications.
 */

const CRAD_PANEL_APP_TABLE = 'crad_defense_panel_requests';
const CRAD_PANEL_APP_HISTORY_TABLE = 'crad_defense_panel_request_history';
const CRAD_PANEL_APP_DOC_TABLE = 'crad_defense_panel_request_documents';
const CRAD_PANEL_APP_MAX_BYTES = 10485760;
const CRAD_PANEL_APP_MAX_MEMBERS = 10;

/** Allowed next statuses. The student moves Draft/Rejected to Submitted; CRAD moves the rest. */
const CRAD_PANEL_APP_TRANSITIONS = [
    'Draft' => ['Submitted'],
    'Rejected' => ['Submitted'],
    'Submitted' => ['Under Review'],
    'Under Review' => ['Approved', 'Rejected'],
    'Approved' => ['Scheduled'],
    'Scheduled' => ['Completed'],
];

function cradPanelAppDocTypes(): array
{
    return [
        'manuscript' => 'Final manuscript (PDF)',
        'approval_sheet' => 'Signed approval sheet (PDF or image)',
    ];
}

function cradPanelAppEditableStatuses(): array
{
    return ['Draft', 'Rejected'];
}

function cradPanelAppEnsureSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `" . CRAD_PANEL_APP_TABLE . "` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            application_ref VARCHAR(40) NOT NULL,
            research_group_id INT UNSIGNED NOT NULL,
            group_number VARCHAR(60) NOT NULL DEFAULT '',
            student_id VARCHAR(40) NOT NULL DEFAULT '',
            research_title VARCHAR(255) NOT NULL DEFAULT '',
            adviser_name VARCHAR(160) NOT NULL DEFAULT '',
            members_text TEXT DEFAULT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Draft',
            review_remarks TEXT DEFAULT NULL,
            submitted_at DATETIME DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            created_by_user_id INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_panel_app_ref (application_ref),
            KEY idx_panel_app_group (research_group_id),
            KEY idx_panel_app_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `" . CRAD_PANEL_APP_HISTORY_TABLE . "` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id INT UNSIGNED NOT NULL,
            from_status VARCHAR(30) DEFAULT NULL,
            to_status VARCHAR(30) NOT NULL,
            actor_user_id INT UNSIGNED DEFAULT NULL,
            actor_name VARCHAR(160) DEFAULT NULL,
            remarks TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_panel_app_history_app (application_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `" . CRAD_PANEL_APP_DOC_TABLE . "` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id INT UNSIGNED NOT NULL,
            doc_type VARCHAR(40) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(120) NOT NULL,
            mime VARCHAR(60) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_panel_app_doc_app (application_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function cradPanelAppStatusLabel(string $status): string
{
    return $status !== '' ? $status : 'Draft';
}

/** @return array<string, mixed>|null */
function cradPanelAppFind(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM `" . CRAD_PANEL_APP_TABLE . "` WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** The latest application for a group, or null. */
function cradPanelAppLatestForGroup(PDO $pdo, int $groupId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM `" . CRAD_PANEL_APP_TABLE . "` WHERE research_group_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$groupId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Returns the group's editable draft, creating one from the group's title and adviser if the latest
 * application is finished (Completed) or the group has none yet.
 * @return array<string, mixed>
 */
function cradPanelAppDraftForGroup(PDO $pdo, array $group, int $actorId, string $actorName): array
{
    cradPanelAppEnsureSchema($pdo);
    $groupId = (int) $group['id'];
    $latest = cradPanelAppLatestForGroup($pdo, $groupId);
    if ($latest !== null && $latest['status'] !== 'Completed') {
        return $latest;
    }
    $ref = 'PA-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $pdo->prepare(
        "INSERT INTO `" . CRAD_PANEL_APP_TABLE . "`
            (application_ref, research_group_id, group_number, student_id, research_title, adviser_name, members_text, status, created_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, '', 'Draft', ?)"
    )->execute([
        $ref,
        $groupId,
        (string) ($group['group_number'] ?? ''),
        (string) ($group['leader_id'] ?? ''),
        mb_substr((string) ($group['research_title'] ?? ''), 0, 255),
        mb_substr((string) ($group['adviser'] ?? ''), 0, 160),
        $actorId > 0 ? $actorId : null,
    ]);
    $id = (int) $pdo->lastInsertId();
    cradPanelAppWriteHistory($pdo, $id, null, 'Draft', $actorId, $actorName, 'Application started.');
    return cradPanelAppFind($pdo, $id) ?? [];
}

function cradPanelAppWriteHistory(PDO $pdo, int $appId, ?string $from, string $to, int $actorId, string $actorName, ?string $remarks): void
{
    $pdo->prepare(
        "INSERT INTO `" . CRAD_PANEL_APP_HISTORY_TABLE . "` (application_id, from_status, to_status, actor_user_id, actor_name, remarks)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$appId, $from, $to, $actorId > 0 ? $actorId : null, mb_substr($actorName, 0, 160), $remarks !== null && $remarks !== '' ? mb_substr($remarks, 0, 2000) : null]);
}

/** @return array{ok: bool, error?: string, message?: string} */
function cradPanelAppSaveDetails(PDO $pdo, int $appId, array $input): array
{
    $app = cradPanelAppFind($pdo, $appId);
    if (!$app) {
        return ['ok' => false, 'error' => 'That application no longer exists.'];
    }
    if (!in_array($app['status'], cradPanelAppEditableStatuses(), true)) {
        return ['ok' => false, 'error' => 'This application can only be edited while it is a draft or after a rejection.'];
    }
    $title = trim((string) ($input['research_title'] ?? ''));
    $adviser = trim((string) ($input['adviser_name'] ?? ''));
    $members = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($input['members_text'] ?? '')) ?: [])));
    if ($title === '' || mb_strlen($title) > 255) {
        return ['ok' => false, 'error' => 'Enter the research title (up to 255 characters).'];
    }
    if ($adviser === '' || mb_strlen($adviser) > 160) {
        return ['ok' => false, 'error' => 'Enter the adviser name (up to 160 characters).'];
    }
    if (count($members) < 1) {
        return ['ok' => false, 'error' => 'List at least one group member, one name per line.'];
    }
    if (count($members) > CRAD_PANEL_APP_MAX_MEMBERS) {
        return ['ok' => false, 'error' => 'A panel application can list up to ' . CRAD_PANEL_APP_MAX_MEMBERS . ' members.'];
    }
    $pdo->prepare(
        "UPDATE `" . CRAD_PANEL_APP_TABLE . "` SET research_title = ?, adviser_name = ?, members_text = ?, updated_at = NOW() WHERE id = ?"
    )->execute([$title, $adviser, implode("\n", array_map(static fn($m) => mb_substr($m, 0, 160), $members)), $appId]);
    return ['ok' => true, 'message' => 'Details saved.'];
}

/** @return array{ok: bool, error?: string, message?: string} */
function cradPanelAppStoreDocument(PDO $pdo, int $appId, string $docType, array $file): array
{
    $app = cradPanelAppFind($pdo, $appId);
    if (!$app) {
        return ['ok' => false, 'error' => 'That application no longer exists.'];
    }
    if (!in_array($app['status'], cradPanelAppEditableStatuses(), true)) {
        return ['ok' => false, 'error' => 'Documents can only be changed while the application is a draft or after a rejection.'];
    }
    $types = cradPanelAppDocTypes();
    if (!isset($types[$docType])) {
        return ['ok' => false, 'error' => 'Choose a valid document type.'];
    }
    $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($code !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => $code === UPLOAD_ERR_NO_FILE ? 'Choose a file to upload.' : 'The upload failed. Try a smaller file.'];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $original = mb_substr(basename((string) ($file['name'] ?? 'document')), 0, 255);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > CRAD_PANEL_APP_MAX_BYTES) {
        return ['ok' => false, 'error' => 'Each file must be 10 MB or smaller.'];
    }
    $data = (string) file_get_contents($tmp, false, null, 0, 8192);
    $ext = null;
    $mime = null;
    if (strncmp($data, '%PDF-', 5) === 0) {
        $ext = 'pdf';
        $mime = 'application/pdf';
    } else {
        $info = @getimagesize($tmp);
        $imageMime = strtolower((string) ($info['mime'] ?? ''));
        if ($imageMime === 'image/png') {
            $ext = 'png';
            $mime = 'image/png';
        } elseif ($imageMime === 'image/jpeg') {
            $ext = 'jpg';
            $mime = 'image/jpeg';
        }
    }
    if ($ext === null) {
        return ['ok' => false, 'error' => 'Upload a PDF, PNG, or JPG file.'];
    }
    if ($docType === 'manuscript' && $ext !== 'pdf') {
        return ['ok' => false, 'error' => 'The final manuscript must be a PDF.'];
    }

    $dir = ROOT_PATH . '/storage/uploads/panel_applications';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'The upload folder could not be created.'];
    }
    $stored = 'pa-' . $appId . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $stored)) {
        return ['ok' => false, 'error' => 'The file could not be saved.'];
    }

    // One current file per document type: replace the old one.
    $old = $pdo->prepare("SELECT stored_name FROM `" . CRAD_PANEL_APP_DOC_TABLE . "` WHERE application_id = ? AND doc_type = ?");
    $old->execute([$appId, $docType]);
    foreach ($old->fetchAll() ?: [] as $prev) {
        $prevPath = $dir . '/' . basename((string) $prev['stored_name']);
        if (is_file($prevPath)) {
            @unlink($prevPath);
        }
    }
    $pdo->prepare("DELETE FROM `" . CRAD_PANEL_APP_DOC_TABLE . "` WHERE application_id = ? AND doc_type = ?")->execute([$appId, $docType]);
    $pdo->prepare(
        "INSERT INTO `" . CRAD_PANEL_APP_DOC_TABLE . "` (application_id, doc_type, original_name, stored_name, mime, size_bytes)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$appId, $docType, $original, $stored, $mime, $size]);
    return ['ok' => true, 'message' => $types[$docType] . ' uploaded.'];
}

/** @return list<array<string, mixed>> */
function cradPanelAppDocuments(PDO $pdo, int $appId): array
{
    $stmt = $pdo->prepare("SELECT * FROM `" . CRAD_PANEL_APP_DOC_TABLE . "` WHERE application_id = ? ORDER BY id ASC");
    $stmt->execute([$appId]);
    return $stmt->fetchAll() ?: [];
}

/** @return list<array<string, mixed>> */
function cradPanelAppHistory(PDO $pdo, int $appId): array
{
    $stmt = $pdo->prepare("SELECT * FROM `" . CRAD_PANEL_APP_HISTORY_TABLE . "` WHERE application_id = ? ORDER BY id ASC");
    $stmt->execute([$appId]);
    return $stmt->fetchAll() ?: [];
}

/** Student submit: needs the details and every required document. */
function cradPanelAppSubmit(PDO $pdo, int $appId, int $actorId, string $actorName): array
{
    $app = cradPanelAppFind($pdo, $appId);
    if (!$app) {
        return ['ok' => false, 'error' => 'That application no longer exists.'];
    }
    if (!in_array($app['status'], cradPanelAppEditableStatuses(), true)) {
        return ['ok' => false, 'error' => 'This application has already been submitted.'];
    }
    $missing = [];
    if (trim((string) $app['research_title']) === '' || trim((string) $app['adviser_name']) === '' || trim((string) $app['members_text']) === '') {
        $missing[] = 'the research details';
    }
    $have = [];
    foreach (cradPanelAppDocuments($pdo, $appId) as $doc) {
        $have[$doc['doc_type']] = true;
    }
    foreach (cradPanelAppDocTypes() as $type => $label) {
        if (empty($have[$type])) {
            $missing[] = $label;
        }
    }
    if ($missing !== []) {
        return ['ok' => false, 'error' => 'Before submitting, complete: ' . implode('; ', $missing) . '.'];
    }
    cradPanelAppTransitionTo($pdo, $appId, 'Submitted', $actorId, $actorName, null, true);
    return ['ok' => true, 'message' => 'Application submitted. CRAD will review it.'];
}

/**
 * Moves an application to a new status if the transition is allowed. Rejection needs remarks.
 * @return array{ok: bool, error?: string, message?: string}
 */
function cradPanelAppTransition(PDO $pdo, int $appId, string $toStatus, int $actorId, string $actorName, ?string $remarks): array
{
    $app = cradPanelAppFind($pdo, $appId);
    if (!$app) {
        return ['ok' => false, 'error' => 'That application no longer exists.'];
    }
    $from = (string) $app['status'];
    $allowed = CRAD_PANEL_APP_TRANSITIONS[$from] ?? [];
    if (!in_array($toStatus, $allowed, true)) {
        return ['ok' => false, 'error' => 'A ' . $from . ' application cannot move to ' . $toStatus . '.'];
    }
    if ($toStatus === 'Rejected' && mb_strlen(trim((string) $remarks)) < 5) {
        return ['ok' => false, 'error' => 'Write the reason for rejecting (at least 5 characters).'];
    }
    cradPanelAppTransitionTo($pdo, $appId, $toStatus, $actorId, $actorName, $remarks, false);
    return ['ok' => true, 'message' => 'Application is now ' . $toStatus . '.'];
}

function cradPanelAppTransitionTo(PDO $pdo, int $appId, string $toStatus, int $actorId, string $actorName, ?string $remarks, bool $studentSubmit): void
{
    $app = cradPanelAppFind($pdo, $appId);
    $from = (string) ($app['status'] ?? '');
    $pdo->beginTransaction();
    try {
        $sets = 'status = ?, updated_at = NOW()';
        $params = [$toStatus];
        if ($studentSubmit) {
            $sets .= ', submitted_at = NOW(), review_remarks = NULL';
        }
        if (in_array($toStatus, ['Under Review', 'Approved', 'Rejected', 'Scheduled', 'Completed'], true)) {
            $sets .= ', reviewed_at = NOW()';
        }
        if ($remarks !== null && $remarks !== '') {
            $sets .= ', review_remarks = ?';
            $params[] = mb_substr($remarks, 0, 2000);
        }
        $params[] = $appId;
        $pdo->prepare("UPDATE `" . CRAD_PANEL_APP_TABLE . "` SET {$sets} WHERE id = ?")->execute($params);
        cradPanelAppWriteHistory($pdo, $appId, $from, $toStatus, $actorId, $actorName, $remarks);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Panel application transition failed: ' . $e->getMessage());
        throw $e;
    }
}
