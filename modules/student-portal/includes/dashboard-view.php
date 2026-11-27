<?php
/**
 * SMS 2 - Student dashboard (research overview).
 *
 * Same layout as the separate Student Portal dashboard: research status,
 * next defense, clearance and payment, latest updates, quick actions and
 * announcements. Data comes from the same read models the integration API
 * uses (includes/integration-data.php), so both dashboards always agree.
 *
 * Expects: $studentUserId, $studentProfile (from student-portal-page.php).
 */
require_once ROOT_PATH . '/includes/integration.php';
require_once ROOT_PATH . '/includes/integration-data.php';

$sdUser = smsApiLoadUser((int) $studentUserId);
$sdResearch = ['status' => 'Not Started', 'title' => null, 'group' => null, 'plan' => null];
$sdDefenses = [];
$sdClearance = ['clearances' => [], 'payments' => []];
$sdNotifications = [];
$sdLoadFailed = false;

if ($sdUser) {
    try {
        $sdCrad = getCradDatabaseConnection();
        $sdResearch = smsApiResearchSummary($sdCrad, $sdUser);
        $sdDefenses = smsApiDefenses($sdCrad, $sdUser);
        $sdClearance = smsApiClearance($sdCrad, $sdUser);
    } catch (Throwable $e) {
        error_log('Student dashboard research load failed: ' . $e->getMessage());
        $sdLoadFailed = true;
    }
    try {
        $sdNotifications = array_slice(smsApiNotifications(), 0, 4);
    } catch (Throwable $e) {
        error_log('Student dashboard notifications load failed: ' . $e->getMessage());
    }
}

// Next defense: the earliest one that has not started yet.
$sdUpcoming = null;
foreach ($sdDefenses as $d) {
    if (empty($d['starts_at']) || strtotime((string) $d['starts_at']) < strtotime('today')) {
        continue;
    }
    if (preg_match('/cancel/i', (string) $d['status'])) {
        continue;
    }
    if ($sdUpcoming === null || strtotime((string) $d['starts_at']) < strtotime((string) $sdUpcoming['starts_at'])) {
        $sdUpcoming = $d;
    }
}

$sdProgress = max(0, min(100, (float) ($sdResearch['plan']['progress'] ?? 0)));
$sdLatestClearance = $sdClearance['clearances'][0] ?? null;
$sdLatestPayment = $sdClearance['payments'][0] ?? null;
$sdFirstName = explode(' ', trim((string) ($studentProfile['name'] ?? getCurrentUserName() ?? 'Student')))[0] ?: 'Student';

$studentAnnouncementRole = function_exists('smsNormalizeRoleKey')
    ? smsNormalizeRoleKey((string) getCurrentUserRoleKey())
    : (string) getCurrentUserRoleKey();
$studentAnnouncementRows = smsAnnouncementPublicRows(smsAnnouncementFetch(true, 20, $studentAnnouncementRole));
$studentAnnouncements = array_slice($studentAnnouncementRows, 0, 3);
$studentAnnStamp = smsAnnouncementStamp($studentAnnouncementRows);

$sdPages = BASE_URL . '/modules/student-portal/pages/';
$sdActions = [
    ['Research proposal / title', 'file-pencil', 'research-proposal-submission.php'],
    ['Research group', 'users', 'research-group.php'],
    ['Submit chapters', 'file-upload', 'submit-chapters.php'],
    ['Progress updates', 'chart-line', 'progress-updates.php'],
    ['Panel application', 'users-group', 'panel-application.php'],
    ['Final manuscript', 'book', 'final-manuscript.php'],
    ['Upload college payment', 'receipt', 'college-payment.php'],
    ['Research clearance', 'clipboard-check', 'research-clearance.php'],
];

if (!function_exists('sdStatusBadge')) {
    function sdStatusBadge(?string $status, string $fallback): string
    {
        $label = trim((string) $status) !== '' ? (string) $status : $fallback;
        $s = strtolower($label);
        $tone = 'neutral';
        if (preg_match('/approved|accepted|completed|cleared|confirmed|paid|done/', $s)) {
            $tone = 'success';
        } elseif (preg_match('/reject|returned|revision|failed|cancel/', $s)) {
            $tone = 'danger';
        } elseif (preg_match('/pending|review|submitted|scheduled|progress|sent|assigned|active|packet/', $s)) {
            $tone = 'primary';
        }
        return '<span class="sd-badge sd-badge--' . $tone . '">' . htmlspecialchars(ucfirst($label)) . '</span>';
    }
}
if (!function_exists('sdDate')) {
    function sdDate(?string $iso, string $format): string
    {
        $ts = $iso ? strtotime($iso) : false;
        return $ts ? date($format, $ts) : '';
    }
}
?>
<div class="sd">
    <h1 class="sd-heading">Dashboard</h1>
    <p class="sd-welcome">Welcome back, <?= htmlspecialchars($sdFirstName) ?>. Here is where your research stands.</p>

    <?php if ($sdLoadFailed): ?>
        <div class="alert alert-warning">Some research details could not be loaded right now. Refresh to try again.</div>
    <?php endif; ?>

    <div class="sd-row sd-row--wide">
        <section class="sd-card" aria-labelledby="sdResearchTitle">
            <div class="sd-card-head">
                <h2 class="sd-kicker" id="sdResearchTitle">Research status</h2>
                <?= sdStatusBadge($sdResearch['status'] ?? null, 'Not Started') ?>
            </div>
            <p class="sd-title"><?= htmlspecialchars((string) ($sdResearch['title'] ?? '') !== '' ? (string) $sdResearch['title'] : 'No title submitted yet') ?></p>
            <p class="sd-meta">
                <?php if (!empty($sdResearch['group'])): ?>
                    Group <?= htmlspecialchars((string) $sdResearch['group']['group_number']) ?>
                    · Adviser: <?= htmlspecialchars((string) ($sdResearch['group']['adviser'] ?: 'For assignment')) ?>
                <?php else: ?>
                    Submit your research group and title to start.
                    <a href="<?= htmlspecialchars($sdPages . 'research-group.php') ?>">Open research group</a>
                <?php endif; ?>
            </p>
            <div class="sd-progress-label"><span>Overall progress</span><span><?= (int) round($sdProgress) ?>%</span></div>
            <div class="sd-progress" role="progressbar" aria-label="Overall research progress"
                 aria-valuenow="<?= (int) round($sdProgress) ?>" aria-valuemin="0" aria-valuemax="100">
                <span style="width: <?= htmlspecialchars((string) $sdProgress) ?>%"></span>
            </div>
            <?php if (!empty($sdResearch['plan']['current_stage'])): ?>
                <p class="sd-meta sd-meta--after">Current stage: <?= htmlspecialchars((string) $sdResearch['plan']['current_stage']) ?></p>
            <?php endif; ?>
        </section>

        <section class="sd-card" aria-labelledby="sdDefenseTitle">
            <h2 class="sd-kicker" id="sdDefenseTitle">Next defense</h2>
            <?php if ($sdUpcoming): ?>
                <p class="sd-title"><?= htmlspecialchars((string) ($sdUpcoming['type'] ?: 'Defense')) ?></p>
                <p class="sd-line"><?= smsIcon('calendar', ['aria-hidden' => 'true']) ?><?= htmlspecialchars(sdDate($sdUpcoming['starts_at'], 'D, M j, Y g:i A')) ?></p>
                <p class="sd-line"><?= smsIcon('map-pin', ['aria-hidden' => 'true']) ?><?= htmlspecialchars((string) ($sdUpcoming['venue'] ?: 'Venue to be announced')) ?></p>
                <a class="sd-link" href="<?= BASE_URL ?>/communication/calendar.php">See panel and details</a>
            <?php else: ?>
                <p class="sd-empty"><?= smsIcon('folder-open', ['aria-hidden' => 'true']) ?>No upcoming defense yet.</p>
            <?php endif; ?>
        </section>
    </div>

    <div class="sd-row">
        <section class="sd-card" aria-labelledby="sdClearanceTitle">
            <h2 class="sd-kicker" id="sdClearanceTitle">Clearance and payment</h2>
            <div class="sd-status-line"><span>Research clearance</span><?= sdStatusBadge($sdLatestClearance['status'] ?? null, 'Not started') ?></div>
            <div class="sd-status-line"><span>College payment</span><?= sdStatusBadge($sdLatestPayment['status'] ?? null, 'Not uploaded') ?></div>
            <a class="sd-link" href="<?= htmlspecialchars($sdPages . 'research-clearance.php') ?>">View details</a>
        </section>

        <section class="sd-card" aria-labelledby="sdUpdatesTitle">
            <h2 class="sd-kicker" id="sdUpdatesTitle">Latest updates</h2>
            <?php if ($sdNotifications): ?>
                <ul class="sd-feed">
                    <?php foreach ($sdNotifications as $n): ?>
                        <li class="<?= !empty($n['is_unread']) ? 'is-unread' : '' ?>">
                            <strong><?= htmlspecialchars((string) $n['title']) ?></strong>
                            <?php if ((string) $n['body'] !== ''): ?><span><?= htmlspecialchars((string) $n['body']) ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="sd-empty"><?= smsIcon('folder-open', ['aria-hidden' => 'true']) ?>No updates yet. CRAD updates appear here as they happen.</p>
            <?php endif; ?>
        </section>
    </div>

    <section class="sd-card sd-block" aria-labelledby="sdActionsTitle">
        <h2 class="sd-kicker" id="sdActionsTitle">Quick actions</h2>
        <div class="sd-actions">
            <?php foreach ($sdActions as [$label, $icon, $page]): ?>
                <a class="sd-action" href="<?= htmlspecialchars($sdPages . $page) ?>">
                    <?= smsIcon($icon, ['aria-hidden' => 'true']) ?>
                    <span><?= htmlspecialchars($label) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="sd-card sd-block" id="studentAnnouncements" aria-labelledby="studentAnnouncementsTitle"
             data-live-url="<?= htmlspecialchars(BASE_URL . '/account/announcements-data.php') ?>"
             data-stamp="<?= htmlspecialchars($studentAnnStamp) ?>">
        <div class="sd-card-head">
            <h2 class="sd-kicker" id="studentAnnouncementsTitle">Announcements</h2>
            <span class="sd-card-head-actions">
                <span class="um-live-badge" id="studentAnnLiveBadge">
                    <span class="um-live-dot" aria-hidden="true"></span>
                    <span data-live-label>Live</span>
                </span>
                <a class="sd-link" href="<?= BASE_URL ?>/communication/announcements.php">See all</a>
            </span>
        </div>
        <div id="studentAnnouncementsList">
            <?php foreach ($studentAnnouncements as $announcement): ?>
                <article class="student-ann-item">
                    <h3><?= htmlspecialchars((string) $announcement['title']) ?></h3>
                    <p><?= nl2br(htmlspecialchars((string) $announcement['body'])) ?></p>
                    <small><?= htmlspecialchars((string) $announcement['posted_at']) ?></small>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="sd-empty" id="studentAnnouncementsEmpty" <?= $studentAnnouncements ? 'hidden' : '' ?>>
            <?= smsIcon('folder-open', ['aria-hidden' => 'true']) ?>No announcements right now.
        </p>
    </section>
</div>
