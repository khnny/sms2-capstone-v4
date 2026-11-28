<?php
/**
 * Research Implementation & Progress Monitoring
 * API Endpoint for Student (Researcher) Operations
 * 
 * Handles:
 * - Get research plan and milestones
 * - Submit progress updates
 * - Get progress history
 * - Get adviser feedback
 * 
 * DUPLICATE PREVENTION: Token-based + timestamp validation
 */

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../../../includes/uploads.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/research-progress-helpers.php';
require_once __DIR__ . '/../includes/research-progress-submit.php';

// Require authentication
requireAuth();

// Only students with research groups can access
$currentRole = getCurrentUserRoleKey();
if ($currentRole !== 'student') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Students only.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$rpJsonInput = [];
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $decodedInput = json_decode((string) $rawInput, true);
    if (is_array($decodedInput)) {
        $rpJsonInput = $decodedInput;
    }

    requireCsrf($rpJsonInput['csrf_token'] ?? ($_POST['csrf_token'] ?? null));
}
$action = $_GET['action'] ?? $_POST['action'] ?? ($rpJsonInput['action'] ?? '');

try {
    $crad = cradDb();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Get current student's research group
$studentId     = trim((string) ($_SESSION['student_id'] ?? ''));
$studentUserId = (int) ($_SESSION['user_id'] ?? 0);
$studentName   = trim((string) ($_SESSION['user_name'] ?? ''));

if (empty($studentId) && $studentUserId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Student identification required']);
    exit;
}

// Only allow access if the student's group is in the Capstone Group/Student Registry
$researchGroup = rpGetRegisteredResearchGroup($crad, $studentId, $studentUserId);

if (!$researchGroup) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Research Development is not available. Your group must be registered in the Capstone Group/Student Registry first.'
    ]);
    exit;
}

$groupId = (int) $researchGroup['id'];

// Route to appropriate handler
switch ($action) {
    case 'get_research_plan':
        handleGetResearchPlan($crad, $groupId, $researchGroup);
        break;
        
    case 'get_milestones':
        handleGetMilestones($crad, $groupId);
        break;
        
    case 'submit_progress':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        }
        handleSubmitProgress($crad, $groupId, $researchGroup, $studentUserId, $studentName);
        break;
        
    case 'get_progress_history':
        handleGetProgressHistory($crad, $groupId);
        break;
        
    case 'get_adviser_feedback':
        handleGetAdviserFeedback($crad, $groupId);
        break;
        
    case 'generate_token':
        // Generate submission token for duplicate prevention
        echo json_encode([
            'success' => true,
            'token' => rpGenerateSubmissionToken()
        ]);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
}

/**
 * Get or create research plan with milestones
 */
function handleGetResearchPlan(PDO $crad, int $groupId, array $researchGroup): void
{
    // Get or create plan (idempotent)
    $plan = rpGetOrCreateResearchPlan($crad, $groupId);
    
    if (!$plan) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to get research plan']);
        return;
    }
    
    // Get milestones from Research Development progress only.
    $milestones = rpGetMilestonesForPlan($crad, (int) $plan['id'], $groupId);
    $plan = rpApplySyncedPlanProgress($plan, $milestones);
    
    // Get latest progress update
    $latestUpdateStmt = $crad->prepare("
        SELECT * FROM `crad_research_progress_updates` 
        WHERE research_group_id = ?
        ORDER BY submitted_at DESC
        LIMIT 1
    ");
    $latestUpdateStmt->execute([$groupId]);
    $latestUpdate = $latestUpdateStmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'plan' => $plan,
        'group' => [
            'group_number' => $researchGroup['group_number'],
            'group_name' => $researchGroup['group_name'],
            'research_title' => $researchGroup['research_title'],
            'adviser' => $researchGroup['adviser_name'] ?: $researchGroup['adviser']
        ],
        'milestones' => $milestones,
        'latest_update' => $latestUpdate ?: null
    ]);
}

/**
 * Get all milestones for the research plan
 */
function handleGetMilestones(PDO $crad, int $groupId): void
{
    // Get plan first. Read-only polling must not create a plan.
    $planStmt = $crad->prepare("SELECT id FROM `crad_research_plans` WHERE research_group_id = ? LIMIT 1");
    $planStmt->execute([$groupId]);
    $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$plan) {
        echo json_encode(['success' => true, 'milestones' => []]);
        return;
    }
    
    $milestones = rpGetMilestonesWithPendingFlags($crad, (int) $plan['id'], $groupId);
    
    echo json_encode([
        'success' => true,
        'milestones' => $milestones
    ]);
}

/**
 * Submit progress update with duplicate prevention (rules: rpStudentSubmitProgress)
 */
function handleSubmitProgress(PDO $crad, int $groupId, array $researchGroup, int $userId, string $userName): void
{
    // Get POST data. Multipart is used when a progress document is uploaded.
    $input = $GLOBALS['rpJsonInput'] ?: $_POST;
    $document = is_array($_FILES['document'] ?? null) ? $_FILES['document'] : [];
    $result = rpStudentSubmitProgress($crad, $groupId, $researchGroup, $userId, $userName, $input, $document);
    if ($result['status'] !== 200) {
        http_response_code($result['status']);
    }
    echo json_encode($result['body']);
}

/**
 * Get progress update history for the research group
 */
function handleGetProgressHistory(PDO $crad, int $groupId): void
{
    $stmt = $crad->prepare("
        SELECT 
            rpu.*,
            rm.milestone_name,
            rm.milestone_order
        FROM `crad_research_progress_updates` rpu
        LEFT JOIN `crad_research_milestones` rm ON rm.id = rpu.milestone_id
        WHERE rpu.research_group_id = ?
        ORDER BY rpu.submitted_at DESC
        LIMIT 50
    ");
    $stmt->execute([$groupId]);
    $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'updates' => $updates
    ]);
}

/**
 * Get adviser feedback for the research group
 */
function handleGetAdviserFeedback(PDO $crad, int $groupId): void
{
    $stmt = $crad->prepare("
        SELECT 
            rpf.*,
            rm.milestone_name,
            rpu.update_title,
            rpu.submitted_at as update_submitted_at
        FROM `crad_research_progress_feedback` rpf
        INNER JOIN `crad_research_progress_updates` rpu ON rpu.id = rpf.progress_update_id
        LEFT JOIN `crad_research_milestones` rm ON rm.id = rpf.milestone_id
        WHERE rpu.research_group_id = ?
        ORDER BY rpf.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$groupId]);
    $feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'feedback' => $feedback
    ]);
}
