<?php
/**
 * SMS 2 - CRAD Research Hub: the whole CRAD process on one page as a collage
 * of step cards. Each card shows its status and key details; its buttons open
 * a pop-up on the same page (the step's full details, or the step's existing
 * upload/form page shown inside the pop-up).
 *
 * Data comes from smsResearchStatement() in research-statement.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/research-statement-view.php';

/** Card state for display: done, ongoing, action (needs action) or locked. */
function smsHubState(string $state): string
{
    return match ($state) {
        'done' => 'done',
        'attention' => 'action',
        'locked' => 'locked',
        default => 'ongoing',
    };
}

/** Short card titles so narrow cards stay readable. */
function smsHubTitle(array $step): string
{
    return [
        'group' => 'Research group',
        'assignment' => 'Coordinator & adviser',
        'title' => 'Title approval',
        'registry' => 'Group number',
        'chapters' => 'Chapters 1 to 3',
        'progress' => 'Progress updates',
        'defense' => 'Panel & defense',
        'manuscript' => 'Final manuscript',
        'clearance' => 'Payment & clearance',
    ][$step['key']] ?? (string) $step['title'];
}

/** Turn stored codes such as clearance_done into readable words. */
function smsHubWords(string $value): string
{
    $value = trim($value);
    if ($value !== '' && preg_match('/^[a-z0-9]+(_[a-z0-9]+)+$/', $value)) {
        $value = ucfirst(str_replace('_', ' ', $value));
    }
    return $value;
}

/** Icon class for a generic icon name; the Student Portal sets SMS_HUB_ICON_SET to 'fa'. */
function smsHubIconClass(string $name): string
{
    $fa = ['checks' => 'fa-check-double', 'flag' => 'fa-flag', 'presentation' => 'fa-person-chalkboard', 'user-check' => 'fa-user-check',
        'users' => 'fa-users', 'signature' => 'fa-file-signature', 'hash' => 'fa-hashtag', 'file-text' => 'fa-file-lines',
        'chart-line' => 'fa-chart-line', 'book' => 'fa-book', 'clipboard-check' => 'fa-clipboard-check', 'lock' => 'fa-lock'];
    if (defined('SMS_HUB_ICON_SET') && SMS_HUB_ICON_SET === 'fa') {
        return 'fa-solid ' . ($fa[$name] ?? 'fa-circle');
    }
    return 'ti ti-' . $name;
}

/** Icon name for each CRAD step. */
function smsHubStepIcon(array $step): string
{
    return [
        'group' => 'users', 'assignment' => 'user-check', 'title' => 'signature', 'registry' => 'hash', 'chapters' => 'file-text',
        'progress' => 'chart-line', 'defense' => 'presentation', 'manuscript' => 'book', 'clearance' => 'clipboard-check',
    ][$step['key']] ?? 'flag';
}

function smsHubStateLabel(string $hubState): string
{
    return ['done' => 'Done', 'ongoing' => 'Ongoing', 'action' => 'Needs action', 'locked' => 'Locked'][$hubState] ?? 'Ongoing';
}

/** Index of a list column by name, or -1. */
function smsHubCol(array $list, string $name): int
{
    $i = array_search($name, $list['columns'] ?? [], true);
    return $i === false ? -1 : (int) $i;
}

/** Tone of a free-text status, for the small chips. */
function smsHubTone(string $status): string
{
    $s = strtolower($status);
    if (preg_match('/revis|reject|return|declin|fail/', $s)) {
        return 'action';
    }
    if (preg_match('/approv|pass|clear|complet|done|accept/', $s)) {
        return 'done';
    }
    return 'ongoing';
}

/** The highlight block of a card, chosen by step key. */
function smsHubHighlight(array $step): string
{
    $list = $step['list'] ?? null;
    $rows = $list['rows'] ?? [];
    $out = '';
    switch ($step['key']) {
        case 'group':
            if ($rows) {
                $out .= '<div class="rh-big">' . count($rows) . ' member' . (count($rows) === 1 ? '' : 's') . '</div>';
            }
            break;
        case 'registry':
            $no = (string) ($step['rows']['Official group no.'] ?? '');
            if ($no !== '') {
                $out .= '<div class="rh-big">' . smsRsEsc($no) . '</div>';
            }
            break;
        case 'chapters':
            $ci = smsHubCol($list ?? [], 'Chapter');
            $si = smsHubCol($list ?? [], 'Status');
            if ($rows && $ci >= 0 && $si >= 0) {
                $out .= '<div class="rh-chips">';
                foreach (array_slice($rows, 0, 6) as $r) {
                    $tone = smsHubTone((string) $r[$si]);
                    $out .= '<div class="rh-chip rh-' . $tone . '"><b>' . smsRsEsc(str_replace('Chapter ', 'Ch ', (string) $r[$ci])) . '</b><span>' . smsRsEsc((string) $r[$si]) . '</span></div>';
                }
                $out .= '</div>';
            }
            break;
        case 'progress':
            $pct = (int) preg_replace('/\D/', '', (string) ($step['rows']['Overall progress'] ?? '0'));
            $out .= '<div class="rh-track"><div class="rh-fill" style="width:' . max(0, min(100, $pct)) . '%"></div></div>';
            break;
        case 'defense':
            $di = smsHubCol($list ?? [], 'Date and time');
            if ($rows && $di >= 0) {
                $r = $rows[0];
                $out .= '<div class="rh-big">' . smsRsEsc((string) $r[$di]) . '</div>';
                $line = array_filter([
                    ($ti = smsHubCol($list, 'Defense')) >= 0 ? (string) $r[$ti] : '',
                    ($vi = smsHubCol($list, 'Venue')) >= 0 ? (string) $r[$vi] : '',
                ]);
                if ($line) {
                    $out .= '<p>' . smsRsEsc(implode(' · ', $line)) . '</p>';
                }
                if (($pi = smsHubCol($list, 'Panel')) >= 0 && trim((string) $r[$pi]) !== '') {
                    $out .= '<p>Panel: ' . smsRsEsc((string) $r[$pi]) . '</p>';
                }
            }
            break;
        case 'clearance':
            $si = smsHubCol($list ?? [], 'Stage');
            $pi = smsHubCol($list ?? [], 'Payment');
            $ki = smsHubCol($list ?? [], 'Clearance');
            if ($rows && $si >= 0) {
                $out .= '<ul class="rh-checks">';
                foreach ($rows as $r) {
                    $pay = $pi >= 0 ? smsHubWords((string) $r[$pi]) : '';
                    $clr = $ki >= 0 ? smsHubWords((string) $r[$ki]) : '';
                    $out .= '<li class="' . (smsHubTone($pay) === 'done' ? 'ok' : '') . '">' . smsRsEsc($r[$si] . ' payment: ' . ($pay !== '' ? $pay : 'Not uploaded')) . '</li>';
                    $out .= '<li class="' . (smsHubTone($clr) === 'done' ? 'ok' : '') . '">' . smsRsEsc($r[$si] . ' clearance: ' . ($clr !== '' ? $clr : 'Not uploaded')) . '</li>';
                }
                $out .= '</ul>';
            }
            break;
    }
    return $out;
}

/** Up to $max short label: value lines for a card. */
function smsHubFacts(array $step, int $max = 3): array
{
    $skip = ['Overall progress', 'Official group no.', 'Research title', 'Group reference', 'Decided by / on'];
    $facts = [];
    foreach (($step['rows'] ?? []) as $label => $value) {
        if (in_array($label, $skip, true) || trim((string) $value) === '') {
            continue;
        }
        $facts[] = [$label, smsHubWords((string) $value)];
        if (count($facts) >= $max) {
            break;
        }
    }
    return $facts;
}

/**
 * @param callable|null $actionHref When given, fn(array $step): ?string returns a URL and
 *        step actions open that URL in a new tab instead of a pop-up (used by the
 *        separate Student Portal, which cannot show SMS 2 pages inside a pop-up).
 */
function smsRenderResearchHub(array $st, string $baseUrl, ?callable $actionHref = null): string
{
    $s = $st['summary'];
    $student = $st['student'];
    $steps = $st['steps'];
    $next = null;
    foreach ($steps as $step) {
        if ($step['key'] === ($s['next_step'] ?? null)) {
            $next = $step;
        }
    }
    $progress = (int) round((float) ($s['progress'] ?? 0));
    $tags = array_filter([
        $s['group_number'] ?? '',
        trim(($student['program'] ?? '') . ' ' . ($student['section'] ?? '')),
        !empty($s['adviser']) ? 'Adviser: ' . $s['adviser'] : '',
        !empty($student['school_year']) ? 'AY ' . $student['school_year'] : '',
    ]);
    $nameParts = preg_split('/\s+/', trim((string) ($student['name'] ?? ''))) ?: [];
    $firstName = $nameParts[0] ?? 'there';
    if (str_contains((string) ($student['name'] ?? ''), ',')) {
        $afterComma = trim(explode(',', (string) $student['name'], 2)[1]);
        $firstName = (preg_split('/\s+/', $afterComma) ?: [$firstName])[0] ?: $firstName;
    }
    $nextDefense = 'Not scheduled';
    foreach ($steps as $st2) {
        if ($st2['key'] === 'defense' && !empty($st2['list']['rows'])) {
            $di = smsHubCol($st2['list'], 'Date and time');
            if ($di >= 0 && trim((string) $st2['list']['rows'][0][$di]) !== '') {
                $nextDefense = (string) $st2['list']['rows'][0][$di];
            }
        }
    }
    $current = $next ? smsHubTitle($next) : 'Complete';
    $kpis = [
        ['checks', 'Steps done', (int) $s['steps_done'] . ' of ' . (int) $s['steps_total'], '#22c55e'],
        ['flag', 'Current step', $current, '#3b82f6'],
        ['presentation', 'Next defense', $nextDefense, '#a855f7'],
        ['user-check', 'Adviser', ($s['adviser'] ?? '') !== '' ? $s['adviser'] : 'For assignment', '#f59e0b'],
    ];
    $actionAttrs = static function (array $step) use ($baseUrl, $actionHref): string {
        if (empty($step['action']) || $step['state'] === 'locked') {
            return '';
        }
        if ($actionHref !== null) {
            $href = $actionHref($step);
            return $href ? ' data-rh-href="' . smsRsEsc($href) . '"' : '';
        }
        return ' data-rh-open="frame" data-rh-src="' . smsRsEsc($baseUrl . $step['action']['path']) . '" data-rh-title="' . smsRsEsc(smsHubTitle($step)) . '" data-rh-no="' . (int) $step['no'] . '"';
    };
    ob_start();
    ?>
<div class="rh" id="researchHub">
    <section class="rh-hero2">
        <img class="rh-wm" src="<?= smsRsEsc($baseUrl . '/images/bcp-crest.png') ?>" alt="" onerror="this.remove()">
        <div class="rh-hero-main">
            <div class="rh-hero-text">
                <div class="rh-date"><?= smsRsEsc((new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('l, M j, Y')) ?> · CRAD Research Hub</div>
                <h1>Hi, <?= smsRsEsc($firstName) ?>! 👋</h1>
                <p class="rh-hero-title"><?= smsRsEsc(($s['research_title'] ?? '') !== '' ? $s['research_title'] : 'No research title yet') ?></p>
                <?php if ($tags): ?>
                    <div class="rh-tags2"><?php foreach ($tags as $t): ?><span><?= smsRsEsc($t) ?></span><?php endforeach; ?></div>
                <?php endif; ?>
            </div>
            <div class="rh-big-ring" style="--p:<?= max(0, min(100, $progress)) ?>" role="img" aria-label="<?= $progress ?>% overall progress"><div><b><?= $progress ?>%</b><span>overall</span></div></div>
        </div>
        <div class="rh-hero-grid">
            <div class="rh-kpis2">
                <?php foreach ($kpis as $k): ?>
                    <div class="rh-kpi2" style="--c:<?= smsRsEsc($k[3]) ?>">
                        <div class="rh-kpi2-ic"><i class="<?= smsRsEsc(smsHubIconClass($k[0])) ?>" aria-hidden="true"></i></div>
                        <div><span><?= smsRsEsc($k[1]) ?></span><b><?= smsRsEsc($k[2]) ?></b></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($next): $ns = smsHubState($next['state']); ?>
                <div class="rh-next2 rh-<?= $ns ?>">
                    <div class="rh-next-k"><?= $ns === 'action' ? 'Needs your action' : ($next['state'] === 'waiting' ? 'Waiting on CRAD or your adviser' : 'Your next step') ?></div>
                    <h2><?= smsRsEsc(smsHubTitle($next)) ?></h2>
                    <p><?= smsRsEsc(str_ends_with((string) ($s['next_text'] ?? ''), 'this is your next step.') || empty($s['next_text']) ? $next['summary'] : $s['next_text']) ?></p>
                    <?php if (!empty($next['action'])): ?>
                        <button type="button" class="rh-btn rh-btn--solid"<?= $actionAttrs($next) ?>><?= smsRsEsc($next['action']['label']) ?> →</button>
                    <?php else: ?>
                        <button type="button" class="rh-btn" data-rh-open="details" data-rh-step="<?= smsRsEsc($next['key']) ?>">See details</button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="rh-next2 rh-done">
                    <div class="rh-next-k">All done</div>
                    <h2>Every CRAD step is complete 🎉</h2>
                    <p>Congratulations. Your research has finished the CRAD process.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div class="rh-legend">
        <h2>CRAD process</h2>
        <?php foreach (['done', 'ongoing', 'action', 'locked'] as $hs): ?>
            <span><i class="rh-dot rh-<?= $hs ?>"></i><?= smsHubStateLabel($hs) ?></span>
        <?php endforeach; ?>
    </div>

    <div class="rh-grid">
    <?php foreach ($steps as $step):
        $hs = smsHubState($step['state']);
        $isNext = $next && $next['key'] === $step['key'];
        $highlight = $hs === 'locked' ? '' : smsHubHighlight($step);
        $facts = $hs === 'locked' ? [] : smsHubFacts($step, $highlight !== '' ? 2 : 3);
        $wide = $isNext || in_array($step['key'], ['chapters', 'defense', 'title'], true) && $hs !== 'locked';
        $remark = $step['remarks'][0] ?? null;
        ?>
        <article class="rh-card rh-<?= $hs ?><?= $wide ? ' rh-wide' : '' ?><?= $isNext ? ' rh-is-next' : '' ?>" id="rh-step-<?= smsRsEsc($step['key']) ?>">
            <header class="rh-card-head">
                <span class="rh-step-ic"><i class="<?= smsRsEsc(smsHubIconClass($hs === 'locked' ? 'lock' : smsHubStepIcon($step))) ?>" aria-hidden="true"></i></span>
                <h3><small>Step <?= (int) $step['no'] ?></small><?= smsRsEsc(smsHubTitle($step)) ?></h3>
                <span class="rh-pill" title="<?= smsRsEsc(smsHubStateLabel($hs)) ?>"><?= smsRsEsc(smsHubWords((string) $step['status'])) ?></span>
            </header>
            <?php if ($hs === 'locked'): ?>
                <p class="rh-why"><span aria-hidden="true">🔒</span> <?= smsRsEsc($step['summary']) ?></p>
            <?php else: ?>
                <?php if ($step['key'] === 'title' && ($s['research_title'] ?? '') !== ''): ?>
                    <p class="rh-quote-title">“<?= smsRsEsc($s['research_title']) ?>”</p>
                <?php elseif ($step['summary'] !== '' && $highlight === ''): ?>
                    <p class="rh-summary"><?= smsRsEsc($step['summary']) ?></p>
                <?php endif; ?>
                <?= $highlight ?>
                <?php if ($facts): ?>
                    <dl class="rh-facts">
                        <?php foreach ($facts as [$label, $value]): ?><div><dt><?= smsRsEsc($label) ?></dt><dd><?= smsRsEsc($value) ?></dd></div><?php endforeach; ?>
                    </dl>
                <?php endif; ?>
                <?php if ($remark): ?>
                    <p class="rh-remark"><b><?= smsRsEsc($remark['from']) ?>:</b> “<?= smsRsEsc(mb_strimwidth((string) $remark['text'], 0, 160, '…')) ?>”</p>
                <?php endif; ?>
            <?php endif; ?>
            <footer class="rh-card-foot">
                <button type="button" class="rh-link" data-rh-open="details" data-rh-step="<?= smsRsEsc($step['key']) ?>">Details</button>
                <?php if (!empty($step['action']) && $hs !== 'locked'): ?>
                    <button type="button" class="rh-btn<?= $hs === 'action' ? ' rh-btn--solid' : '' ?>"<?= $actionAttrs($step) ?>><?= smsRsEsc($step['action']['label']) ?> →</button>
                <?php endif; ?>
            </footer>
            <template class="rh-details-tpl"><?= smsRenderResearchStatementSteps([$step], '') ?></template>
        </article>
    <?php endforeach; ?>
    </div>

    <div class="rh-modal" id="rhModal" hidden>
        <div class="rh-modal-backdrop" data-rh-close></div>
        <div class="rh-modal-box" role="dialog" aria-modal="true" aria-labelledby="rhModalTitle">
            <header class="rh-modal-head">
                <span class="rh-no" id="rhModalNo"></span>
                <h2 id="rhModalTitle"></h2>
                <a class="rh-modal-pop" id="rhModalPop" href="#" target="_blank" rel="noopener" hidden>Open full page ↗</a>
                <button type="button" class="rh-modal-x" data-rh-close aria-label="Close">×</button>
            </header>
            <div class="rh-modal-body" id="rhModalBody"></div>
        </div>
    </div>
</div>
    <?php
    return (string) ob_get_clean();
}
