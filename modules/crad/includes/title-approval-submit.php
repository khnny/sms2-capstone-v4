<?php
/**
 * CRAD - Title Approval Form: send (or resend after a return) to the adviser.
 *
 * Shared by the CRAD page endpoint (modules/crad/api/send-to-adviser.php) and
 * the Integration API (POST me/research/title), so the gates (research group,
 * coordinator/adviser assignment and confirmation, adviser account) live once.
 */
declare(strict_types=1);

require_once __DIR__ . '/title-approval-assignees.php';
require_once __DIR__ . '/research-group-flow.php';

/** @return array{status: int, body: array} */
function cradTitleResult(int $status, bool $ok, string $message, array $extra = []): array
{
    return ['status' => $status, 'body' => array_merge(['ok' => $ok, 'message' => $message], $extra)];
}

/** Choices on the Title Approval Form (CRAD Form S2). */
function cradTitleFormOptions(): array
{
    $sdgs = [];
    foreach (['No Poverty', 'Zero Hunger', 'Good Health and Well-being', 'Quality Education', 'Gender Equality',
        'Clean Water and Sanitation', 'Affordable and Clean Energy', 'Decent Work and Economic Growth',
        'Industry, Innovation and Infrastructure', 'Reduced Inequalities', 'Sustainable Cities and Communities',
        'Responsible Consumption and Production', 'Climate Action', 'Life Below Water', 'Life on Land',
        'Peace, Justice and Strong Institutions', 'Partnerships for the Goals'] as $i => $title) {
        $sdgs[] = ['num' => $i + 1, 'title' => $title];
    }
    return [
        'departments' => [
            'College of Computer Studies',
            'College of Business Administration',
            'College of Education',
            'College of Criminal Justice',
            'College of Hospitality & Tourism Management',
            'College of Nursing and Health Sciences',
        ],
        'discipline_clusters' => [
            'Natural Sciences, Environmental Studies, and Mathematics',
            'Education, Curriculum, Teaching, and Learning',
            'Health Sciences, Mental Health, and Allied Professions',
            'Engineering, Information Technology, and Computing',
            'Humanities, Social Sciences, and Public Safety',
            'Business, Entrepreneurship, Hospitality, and Tourism Management',
        ],
        'research_agendas' => [
            'Quality Education and Human Capital Development',
            'Inclusive Economic Development, Entrepreneurship, and Industry Competitiveness',
            'Science, Technology, Digital Transformation, and Innovation',
            'Community Health, Well-being, and Social Development',
            'Sustainable Communities, Environmental Stewardship, and Climate Resilience',
            'Peace, Good Governance, Public Safety, and Social Justice',
            'Global Partnerships, Research Excellence, and Institutional Sustainability',
            'Others [Please Specify Below]',
        ],
        'sdgs' => $sdgs,
        'default_department' => 'College of Computer Studies',
        'default_discipline' => 'Engineering, Information Technology, and Computing',
        'default_agenda' => 'Science, Technology, Digital Transformation, and Innovation',
        'default_sdg' => 9,
    ];
}

/**
 * @param array $in student_id, student_user_id, student_name, research_title, department,
 *                  submission_date, discipline, primary_sdg, research_agenda, justification,
 *                  members (JSON string or array), submission_id (resubmit)
 * @return array{status: int, body: array}
 */
function cradTitleSendToAdviser(PDO $pdo, array $in): array
{
    $studentId     = trim((string) ($in['student_id']       ?? ''));
    $studentUserId = isset($in['student_user_id']) && $in['student_user_id'] !== ''
                        ? (int) $in['student_user_id'] : null;
    $studentName   = trim((string) ($in['student_name']     ?? ''));
    $adviserName   = '';
    $adviserEmail  = '';
    $coordName     = '';
    $title         = trim((string) ($in['research_title']   ?? ''));
    $dept          = trim((string) ($in['department']       ?? ''));
    $dateStr       = trim((string) ($in['submission_date']  ?? date('Y-m-d')));
    $discipline    = trim((string) ($in['discipline']       ?? ''));
    $sdg           = trim((string) ($in['primary_sdg']      ?? ''));
    $agenda        = trim((string) ($in['research_agenda']  ?? ''));
    $justification = trim((string) ($in['justification']    ?? ''));
    $membersRaw    = $in['members'] ?? '[]';
    $submissionId  = (int) ($in['submission_id'] ?? 0);

    if ($title === '') {
        return cradTitleResult(422, false, 'Research title is required.');
    }

    /* ── Normalise date ──────────────────────────────────────── */
    $submissionDate = date('Y-m-d', strtotime($dateStr) ?: time());

    /* ── Normalise members JSON ──────────────────────────────── */
    if (is_string($membersRaw)) {
        $dec = json_decode($membersRaw, true);
        $membersJson = json_encode(is_array($dec) ? $dec : [], JSON_UNESCAPED_UNICODE);
    } else {
        $membersJson = json_encode(is_array($membersRaw) ? $membersRaw : [], JSON_UNESCAPED_UNICODE);
    }

    $rgGate = cradRgFlowTitleApprovalGate($pdo, $studentId);
    if (empty($rgGate['ok'])) {
        return cradTitleResult(422, false, (string) ($rgGate['message'] ?? 'Complete Research Group, coordinator/adviser assignment, and confirmation before Title Approval.'));
    }
    // Prefer Research Group members for title approval payload (source of truth).
    $rgMembersPayload = $rgGate['members'] ?? [];
    if (!is_array($rgMembersPayload) || $rgMembersPayload === []) {
        try {
            $rgRow = cradRgFlowGetStudentGroup($pdo, $studentId);
            if ($rgRow && function_exists('cradRgFlowMembersAsTitleJson')) {
                $rgMembersPayload = cradRgFlowMembersAsTitleJson($pdo, (int) $rgRow['id']);
            }
        } catch (Throwable $e) {
            error_log('RG members prefer failed: ' . $e->getMessage());
        }
    }
    if (is_array($rgMembersPayload) && $rgMembersPayload !== []) {
        $membersJson = json_encode($rgMembersPayload, JSON_UNESCAPED_UNICODE);
    }

    $official = cradStudentOfficialAssignees($pdo, $studentId);
    $adviserName = trim((string) ($official['adviser_name'] ?? ''));
    $adviserEmail = trim((string) ($official['adviser_email'] ?? ''));
    $coordName = trim((string) ($official['coordinator_name'] ?? ''));
    if ($adviserName === '' || $coordName === '' || empty($official['ready'])) {
        return cradTitleResult(422, false, 'Waiting for coordinator and adviser assignment or confirmation. Names stay blank on the Title Approval Form until both are assigned and confirmed.');
    }

    /* ── Check adviser has an account in sms2_db ─────────────── */
    try {
        $mainPdo = db();
        if ($mainPdo) {
            $chk = $mainPdo->prepare(
                "SELECT id, full_name, email FROM `sms2_users`
                 WHERE status = 'active'
                   AND (
                        (email != '' AND LOWER(email) = LOWER(:email))
                     OR LOWER(full_name) = LOWER(:name)
                   )
                 LIMIT 1"
            );
            $chk->execute([':email' => $adviserEmail, ':name' => $adviserName]);
            $adviserUser = $chk->fetch();

            if (!$adviserUser) {
                return cradTitleResult(200, false, 'The message cannot be sent because the adviser does not have an account.', [
                    'no_account' => true,
                    'adviser'    => $adviserName ?: $adviserEmail,
                ]);
            }

            $adviserName  = (string) $adviserUser['full_name'];
            $adviserEmail = (string) $adviserUser['email'];
        }
    } catch (Throwable $e) {
        error_log('Adviser account check failed: ' . $e->getMessage());
    }

    try {
        $sigCol = $pdo->query("SHOW COLUMNS FROM `crad_title_approvals` LIKE 'adviser_signature_data'")->fetch();
        if (!$sigCol) {
            $pdo->exec("ALTER TABLE `crad_title_approvals` ADD COLUMN adviser_signature_data MEDIUMTEXT NULL DEFAULT NULL AFTER adviser_remarks");
        }
        $workflowColumns = [
            'coordinator_status' => "ALTER TABLE `crad_title_approvals` ADD COLUMN coordinator_status VARCHAR(30) NOT NULL DEFAULT 'Not Ready' AFTER adviser_signature_data",
            'coordinator_remarks' => "ALTER TABLE `crad_title_approvals` ADD COLUMN coordinator_remarks TEXT NULL DEFAULT NULL AFTER coordinator_status",
            'coordinator_screening_json' => "ALTER TABLE `crad_title_approvals` ADD COLUMN coordinator_screening_json TEXT NULL DEFAULT NULL AFTER coordinator_remarks",
            'coordinator_signature_data' => "ALTER TABLE `crad_title_approvals` ADD COLUMN coordinator_signature_data MEDIUMTEXT NULL DEFAULT NULL AFTER coordinator_remarks",
            'coordinator_reviewed_at' => "ALTER TABLE `crad_title_approvals` ADD COLUMN coordinator_reviewed_at DATETIME NULL DEFAULT NULL AFTER coordinator_signature_data",
            'crad_status' => "ALTER TABLE `crad_title_approvals` ADD COLUMN crad_status VARCHAR(30) NOT NULL DEFAULT 'Not Ready' AFTER coordinator_reviewed_at",
            'crad_signature_data' => "ALTER TABLE `crad_title_approvals` ADD COLUMN crad_signature_data MEDIUMTEXT NULL DEFAULT NULL AFTER crad_status",
            'crad_reviewed_at' => "ALTER TABLE `crad_title_approvals` ADD COLUMN crad_reviewed_at DATETIME NULL DEFAULT NULL AFTER crad_signature_data",
        ];
        foreach ($workflowColumns as $column => $sql) {
            if (!$pdo->query("SHOW COLUMNS FROM `crad_title_approvals` LIKE " . $pdo->quote($column))->fetch()) {
                $pdo->exec($sql);
            }
        }
    } catch (Throwable $e) {
        error_log('Title approval schema check failed: ' . $e->getMessage());
    }

    /* Resubmit returned/current row instead of creating a duplicate. */
    $existingId = 0;
    if ($submissionId > 0) {
        $byId = $pdo->prepare(
            "SELECT id, status, coordinator_status
             FROM `crad_title_approvals`
             WHERE id = :id AND student_id = :sid
             LIMIT 1"
        );
        $byId->execute([':id' => $submissionId, ':sid' => $studentId]);
        $existing = $byId->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existing) {
            $existingId = (int) $existing['id'];
            if ((string) $existing['status'] === 'Pending') {
                return cradTitleResult(200, true, 'Already sent.', ['already_sent' => true, 'submission_id' => $existingId]);
            }
            if ((string) $existing['status'] !== 'Returned' && (string) ($existing['coordinator_status'] ?? '') !== 'Returned') {
                return cradTitleResult(200, true, 'Already sent.', ['already_sent' => true, 'submission_id' => $existingId]);
            }
        }
    }

    if ($existingId === 0) {
        $findExisting = $pdo->prepare(
            "SELECT id, status, coordinator_status
             FROM `crad_title_approvals`
             WHERE student_id = :sid AND proposed_title = :title
             ORDER BY id DESC
             LIMIT 1"
        );
        $findExisting->execute([':sid' => $studentId, ':title' => $title]);
        $existing = $findExisting->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existing) {
            $existingId = (int) $existing['id'];
            if ((string) $existing['status'] === 'Pending') {
                return cradTitleResult(200, true, 'Already sent.', ['already_sent' => true, 'submission_id' => $existingId]);
            }
            if ((string) $existing['status'] !== 'Returned' && (string) ($existing['coordinator_status'] ?? '') !== 'Returned') {
                return cradTitleResult(200, true, 'Already sent.', ['already_sent' => true, 'submission_id' => $existingId]);
            }
        }
    }

    if ($existingId > 0) {
        $update = $pdo->prepare("
            UPDATE `crad_title_approvals`
            SET student_user_id = :student_user_id,
                student_name = :student_name,
                submission_date = :submission_date,
                department = :department,
                proposed_title = :proposed_title,
                discipline_cluster = :discipline_cluster,
                primary_sdg = :primary_sdg,
                research_agenda = :research_agenda,
                sdg_justification = :sdg_justification,
                members_json = :members_json,
                adviser_name = :adviser_name,
                adviser_email = :adviser_email,
                coordinator_name = :coordinator_name,
                status = 'Pending',
                adviser_remarks = NULL,
                adviser_signature_data = NULL,
                coordinator_status = 'Not Ready',
                coordinator_remarks = NULL,
                coordinator_screening_json = NULL,
                coordinator_signature_data = NULL,
                coordinator_reviewed_at = NULL,
                crad_status = 'Not Ready',
                crad_signature_data = NULL,
                crad_reviewed_at = NULL,
                reviewed_at = NULL,
                sent_at = NOW()
            WHERE id = :id
              AND student_id = :student_id
        ");
        $update->execute([
            ':student_user_id'    => $studentUserId,
            ':student_name'       => $studentName,
            ':submission_date'    => $submissionDate,
            ':department'         => $dept,
            ':proposed_title'     => $title,
            ':discipline_cluster' => $discipline,
            ':primary_sdg'        => $sdg,
            ':research_agenda'    => $agenda,
            ':sdg_justification'  => $justification,
            ':members_json'       => $membersJson,
            ':adviser_name'       => $adviserName,
            ':adviser_email'      => $adviserEmail,
            ':coordinator_name'   => $coordName,
            ':id'                 => $existingId,
            ':student_id'         => $studentId,
        ]);

        return cradTitleResult(200, true, 'Title approval form resubmitted to adviser.', [
            'submission_id' => $existingId,
            'adviser'       => $adviserName,
            'resubmitted'   => true,
            'sent_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    $stmt = $pdo->prepare("
        INSERT INTO `crad_title_approvals`
            (student_id, student_user_id, student_name, submission_date, department,
             proposed_title, discipline_cluster, primary_sdg, research_agenda,
             sdg_justification, members_json, adviser_name, adviser_email,
             coordinator_name, status, sent_at)
        VALUES
            (:student_id, :student_user_id, :student_name, :submission_date, :department,
             :proposed_title, :discipline_cluster, :primary_sdg, :research_agenda,
             :sdg_justification, :members_json, :adviser_name, :adviser_email,
             :coordinator_name, 'Pending', NOW())
    ");

    $stmt->execute([
        ':student_id'         => $studentId,
        ':student_user_id'    => $studentUserId,
        ':student_name'       => $studentName,
        ':submission_date'    => $submissionDate,
        ':department'         => $dept,
        ':proposed_title'     => $title,
        ':discipline_cluster' => $discipline,
        ':primary_sdg'        => $sdg,
        ':research_agenda'    => $agenda,
        ':sdg_justification'  => $justification,
        ':members_json'       => $membersJson,
        ':adviser_name'       => $adviserName,
        ':adviser_email'      => $adviserEmail,
        ':coordinator_name'   => $coordName,
    ]);

    return cradTitleResult(200, true, 'Title approval form sent to adviser.', [
        'submission_id' => (int) $pdo->lastInsertId(),
        'adviser'       => $adviserName,
        'sent_at'       => date('Y-m-d H:i:s'),
    ]);
}
