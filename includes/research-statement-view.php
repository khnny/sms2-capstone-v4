<?php
/**
 * SMS 2 - Research statement view (one long page, like a utility bill).
 *
 * smsRenderResearchStatement($statement, $linkBase) returns the HTML for a
 * statement built by smsResearchStatement(). Styles live in
 * assets/css/research-statement.css. $linkBase is prefixed to each step's
 * action path ('' hides the action links).
 */
declare(strict_types=1);

function smsRsEsc($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function smsRsStateLabel(string $state): string
{
    return [
        'done' => 'Done',
        'current' => 'Your turn',
        'waiting' => 'In review',
        'attention' => 'Needs action',
        'locked' => 'Not yet',
    ][$state] ?? ucfirst($state);
}

/**
 * Box width for a step, from how much it holds: 'full' (wide tables),
 * 'half' (a table or many lines) or 'third' (a few lines). The grid packs
 * small boxes next to each other so the page stays compact.
 */
function smsRsSpan(array $step): string
{
    $cols = count($step['list']['columns'] ?? []);
    $tableRows = count($step['list']['rows'] ?? []);
    $lines = count($step['rows'] ?? []) + count($step['remarks'] ?? []) * 2;
    if ($cols >= 5 && $tableRows > 0) {
        return 'full';
    }
    if ($tableRows > 0 || $lines > 4) {
        return 'half';
    }
    return 'third';
}

function smsRenderResearchStatement(array $st, string $linkBase = '', array $opts = []): string
{
    $s = $st['summary'];
    $student = $st['student'];
    $steps = $st['steps'];
    $stamp = !empty($st['generated_at']) ? date('F j, Y · g:i A', strtotime((string) $st['generated_at'])) : date('F j, Y · g:i A');
    $next = null;
    foreach ($steps as $step) {
        if ($step['key'] === ($s['next_step'] ?? null)) {
            $next = $step;
        }
    }
    ob_start();
    ?>
<article class="rs" aria-label="Research status statement">
    <header class="rs-head">
        <div class="rs-head-brand">
            <?php if (!empty($opts['crest'])): ?><img src="<?= smsRsEsc($opts['crest']) ?>" alt="" class="rs-crest"><?php endif; ?>
            <div>
                <div class="rs-head-school">Bestlink College of the Philippines</div>
                <div class="rs-head-office">Center for Research and Development (CRAD)</div>
            </div>
        </div>
        <div class="rs-head-doc">
            <div class="rs-head-title">Research Status Statement</div>
            <div class="rs-head-date">As of <?= smsRsEsc($stamp) ?></div>
        </div>
    </header>

    <section class="rs-summary">
        <div class="rs-account">
            <h2 class="rs-label">Student and group</h2>
            <dl class="rs-kv rs-kv--compact">
                <div><dt>Student</dt><dd><?= smsRsEsc($student['name']) ?></dd></div>
                <div><dt>Student no.</dt><dd><?= smsRsEsc($student['student_id']) ?></dd></div>
                <?php if ($student['program'] !== ''): ?><div><dt>Program / section</dt><dd><?= smsRsEsc(trim($student['program'] . ' ' . $student['section'])) ?></dd></div><?php endif; ?>
                <div><dt>Group no.</dt><dd><?= smsRsEsc($s['group_number'] !== '' ? $s['group_number'] : 'Not yet assigned') ?></dd></div>
                <div><dt>Research title</dt><dd class="rs-strong"><?= smsRsEsc($s['research_title'] !== '' ? $s['research_title'] : 'No title yet') ?></dd></div>
                <div><dt>Research adviser</dt><dd><?= smsRsEsc($s['adviser'] !== '' ? $s['adviser'] : 'For assignment') ?></dd></div>
                <div><dt>Research coordinator</dt><dd><?= smsRsEsc($s['coordinator'] !== '' ? $s['coordinator'] : 'For assignment') ?></dd></div>
            </dl>
        </div>
        <div class="rs-due">
            <h2 class="rs-label">Overall progress</h2>
            <div class="rs-big"><?= (int) $s['progress'] ?><small>%</small></div>
            <div class="rs-due-sub"><?= (int) $s['steps_done'] ?> of <?= (int) $s['steps_total'] ?> steps done</div>
            <div class="rs-next <?= $next ? 'rs-next--' . smsRsEsc($next['state']) : 'rs-next--done' ?>">
                <span class="rs-next-label">Next step</span>
                <span class="rs-next-text"><?= smsRsEsc($s['next_text']) ?></span>
                <?php if ($next && $next['state'] !== 'waiting'): ?>
                    <a href="#rs-step-<?= smsRsEsc($next['key']) ?>" class="rs-next-link">See details</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <nav class="rs-strip" aria-label="Steps overview">
        <?php foreach ($steps as $step): ?>
            <a class="rs-strip-item rs-is-<?= smsRsEsc($step['state']) ?>" href="#rs-step-<?= smsRsEsc($step['key']) ?>">
                <span class="rs-strip-no"><?= (int) $step['no'] ?></span>
                <span class="rs-strip-name"><?= smsRsEsc($step['title']) ?></span>
                <span class="rs-strip-state"><?= smsRsEsc($step['status']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <h2 class="rs-section-title">Details per step</h2>

    <?= smsRenderResearchStatementSteps($steps, $linkBase) ?>

    <footer class="rs-foot">
        <div class="rs-legend">
            <?php foreach (['done', 'current', 'waiting', 'attention', 'locked'] as $state): ?>
                <span><i class="rs-dot rs-dot--<?= $state ?>"></i><?= smsRsEsc(smsRsStateLabel($state)) ?></span>
            <?php endforeach; ?>
        </div>
        <p>This statement updates by itself whenever CRAD, your adviser or your coordinator acts on your research.</p>
    </footer>
</article>
    <?php
    return (string) ob_get_clean();
}

function smsRenderResearchStatementSteps(array $steps, string $linkBase = ''): string
{
    ob_start();
    ?>
    <div class="rs-grid">
    <?php foreach ($steps as $step): ?>
        <section class="rs-step rs-is-<?= smsRsEsc($step['state']) ?> rs-span-<?= smsRsSpan($step) ?>" id="rs-step-<?= smsRsEsc($step['key']) ?>">
            <header class="rs-step-head">
                <span class="rs-step-no"><?= (int) $step['no'] ?></span>
                <div class="rs-step-titles">
                    <h3><?= smsRsEsc($step['title']) ?></h3>
                    <p><?= smsRsEsc($step['summary']) ?></p>
                </div>
                <span class="rs-pill rs-pill--<?= smsRsEsc($step['state']) ?>" title="<?= smsRsEsc(smsRsStateLabel($step['state'])) ?>"><?= smsRsEsc($step['status']) ?></span>
            </header>
            <?php if ($step['rows']): ?>
                <dl class="rs-kv">
                    <?php foreach ($step['rows'] as $label => $value): ?>
                        <div><dt><?= smsRsEsc($label) ?></dt><dd><?= smsRsEsc($value) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
            <?php if (!empty($step['list']['rows'])): ?>
                <div class="rs-table-wrap">
                    <table class="rs-table">
                        <thead><tr><?php foreach ($step['list']['columns'] as $c): ?><th><?= smsRsEsc($c) ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                        <?php foreach ($step['list']['rows'] as $row): ?>
                            <tr><?php foreach ($row as $cell): ?><td><?= smsRsEsc($cell !== '' ? $cell : '—') ?></td><?php endforeach; ?></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($step['remarks']): ?>
                <div class="rs-remarks">
                    <div class="rs-remarks-label">Remarks</div>
                    <?php foreach ($step['remarks'] as $r): ?>
                        <p><strong><?= smsRsEsc($r['from']) ?><?= $r['at'] !== '' ? ' · ' . smsRsEsc($r['at']) : '' ?>:</strong> <?= nl2br(smsRsEsc($r['text'])) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!$step['rows'] && empty($step['list']['rows']) && !$step['remarks'] && $step['state'] === 'locked'): ?>
                <p class="rs-locked-note">This step opens after the steps above are done.</p>
            <?php endif; ?>
            <?php if ($linkBase !== '' && !empty($step['action']) && $step['state'] !== 'locked'): ?>
                <a class="rs-action" href="<?= smsRsEsc($linkBase . $step['action']['path']) ?>"><?= smsRsEsc($step['action']['label']) ?> →</a>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}
