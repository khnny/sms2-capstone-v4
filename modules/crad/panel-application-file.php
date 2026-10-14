<?php
/**
 * Serves a panel application document to CRAD staff and to the group's own student. Path-checked, no listing.
 */
require_once __DIR__ . '/../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/modules/crad/config/config.php';
require_once ROOT_PATH . '/modules/crad/includes/panel-applications.php';

requireAuth();
$pdo = cradDb();
$docId = (int) ($_GET['doc'] ?? 0);
if (!$pdo instanceof PDO || $docId <= 0) {
    http_response_code(404);
    exit('File not found.');
}
$stmt = $pdo->prepare(
    "SELECT d.stored_name, d.mime, d.original_name, a.research_group_id, a.student_id
       FROM `" . CRAD_PANEL_APP_DOC_TABLE . "` d
       JOIN `" . CRAD_PANEL_APP_TABLE . "` a ON a.id = d.application_id
      WHERE d.id = ? LIMIT 1"
);
$stmt->execute([$docId]);
$doc = $stmt->fetch();
$role = getCurrentUserRoleKey();
$isStaff = in_array($role, ['crad_officer'], true);
$isOwner = $role === 'student' && $doc && strcasecmp((string) $doc['student_id'], (string) ($_SESSION['student_id'] ?? '')) === 0;
if (!$doc || (!$isStaff && !$isOwner)) {
    http_response_code(404);
    exit('File not found.');
}
$base = realpath(ROOT_PATH . '/storage/uploads/panel_applications');
$path = realpath($base . '/' . basename((string) $doc['stored_name']));
if ($base === false || $path === false || strncmp($path, $base, strlen($base)) !== 0 || !is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}
header('Content-Type: ' . $doc['mime']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . str_replace('"', '', (string) $doc['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
