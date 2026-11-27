<?php
/**
 * SMS 2 - Research statement, bill layout (page 1), modelled on a utility bill:
 * header strip, "your research status" readings, a highlighted next-step box,
 * a step-by-step summary that totals to overall progress, a progress chart,
 * a notices panel and an instruction stub. Page 2 is the per-step detail grid
 * from research-statement-view.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/research-statement-view.php';

function smsRenderResearchBill(array $st, string $linkBase = '', array $opts = []): string
{
    $s = $st['summary'];
    $student = $st['student'];
    $steps = $st['steps'];
    $m = $st['meter'] ?? [];
    $milestones = $st['milestones'] ?? [];
    $notice = $st['notice'] ?? null;
    $deadline = $st['deadline'] ?? null;
    $tz = new DateTimeZone('Asia/Manila');
    $stampTs = !empty($st['generated_at']) ? strtotime((string) $st['generated_at']) : time();
    $statementDate = (new DateTimeImmutable('@' . $stampTs))->setTimezone($tz)->format('M j, Y');
    $statementNo = 'RS-' . preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $s['group_number'])) . '-' . date('ymd', $stampTs);
    $next = null;
    foreach ($steps as $step) {
        if ($step['key'] === ($s['next_step'] ?? null)) {
            $next = $step;
        }
    }
    $nextTitle = $next ? $next['title'] : 'All steps complete';
    $nextStatus = $next ? $next['status'] : 'Done';
    $nextState = $next ? $next['state'] : 'done';
    $prev = (float) ($m['previous_progress'] ?? 0);
    $curr = (float) ($s['progress'] ?? 0);
    $gain = $curr - $prev;
    ob_start();
    ?>
<article class="rb" aria-label="Research status statement">
    <!-- 1. Header strip -->
    <header class="rb-box rb-head">
        <div class="rb-head-who">
            <strong><?= smsRsEsc(strtoupper($student['name'])) ?></strong>
            <span><?= smsRsEsc(trim($student['program'] . ' · ' . $student['section'], ' ·')) ?></span>
            <span>Leader, <?= smsRsEsc($s['group_number'] ?: 'no group number yet') ?></span>
            <span class="rb-head-ids">Student No.: <?= smsRsEsc($student['student_id']) ?> &nbsp; Group No.: <?= smsRsEsc($s['group_number'] ?: '—') ?></span>
        </div>
        <div class="rb-head-office">
            <strong>CENTER FOR RESEARCH AND DEVELOPMENT</strong>
            <span>Bestlink College of the Philippines</span>
            <span>#1071 Brgy. Kaligayahan, Quirino Hwy, Novaliches, QC</span>
            <span class="rb-head-ids">Statement No.: <?= smsRsEsc($statementNo) ?></span>
        </div>
        <div class="rb-head-brand">
            <?php if (!empty($opts['crest'])): ?><img src="<?= smsRsEsc($opts['crest']) ?>" alt="BCP crest"><?php endif; ?>
            <span class="rb-head-page">Page 1 of 2</span>
        </div>
    </header>

    <div class="rb-cols">
        <div class="rb-left">
            <!-- 2. Readings -->
            <section class="rb-box rb-readings">
                <h2 class="rb-h1">Your research status</h2>
                <div class="rb-grid2">
                    <div><span class="rb-k">Academic year</span><strong><?= smsRsEsc($student['school_year'] ?: '—') ?></strong></div>
                    <div><span class="rb-k">Statement date</span><strong><?= smsRsEsc($statementDate) ?></strong></div>
                </div>
                <div class="rb-rule"></div>
                <div class="rb-grid2">
                    <div>
                        <span class="rb-k">Research started</span><strong><?= smsRsEsc($m['start_date'] ?? '—') ?></strong>
                        <span class="rb-k">Target completion</span><strong><?= smsRsEsc($m['target_date'] ?? '—') ?></strong>
                        <span class="rb-k">Current stage</span><strong><?= smsRsEsc($m['current_stage'] ?? '—') ?></strong>
                    </div>
                    <div class="rb-meter">
                        <span class="rb-k">Current progress</span><strong><?= (int) round($curr) ?>%</strong>
                        <span class="rb-k"><i class="rb-op">−</i>Last statement</span><strong><?= (int) round($prev) ?>%</strong>
                        <span class="rb-k"><i class="rb-op">=</i>Progress gained</span><strong><?= ($gain >= 0 ? '+' : '') . (int) round($gain) ?>%</strong>
                    </div>
                </div>
                <div class="rb-rule rb-rule--thin"></div>
                <div class="rb-grid2">
                    <div><span class="rb-k">Research adviser</span><strong class="rb-sm"><?= smsRsEsc($s['adviser'] ?: 'For assignment') ?></strong></div>
                    <div><span class="rb-k">Research coordinator</span><strong class="rb-sm"><?= smsRsEsc($s['coordinator'] ?: 'For assignment') ?></strong></div>
                </div>
                <p class="rb-title-line"><span class="rb-k">Research title</span><?= smsRsEsc($s['research_title'] ?: 'No title yet') ?></p>
            </section>

            <!-- 4. Chart -->
            <section class="rb-box rb-chart-box">
                <div class="rb-notice"><span aria-hidden="true">!</span>Page 2 has the full details of every step, with dates, signatures and remarks.</div>
                <h2 class="rb-h1">Your milestone progress</h2>
                <?php if ($milestones): ?>
                    <div class="rb-legend"><span><i class="rb-sw rb-sw--target"></i>Target by today</span><span><i class="rb-sw rb-sw--actual"></i>Actual</span></div>
                    <div class="rb-chart" role="img" aria-label="Milestone progress chart">
                        <div class="rb-yaxis"><span>100</span><span>75</span><span>50</span><span>25</span><span>0</span></div>
                        <div class="rb-bars">
                            <?php foreach ($milestones as $ms): ?>
                                <div class="rb-bar-group">
                                    <div class="rb-bar-pair">
                                        <span class="rb-bar rb-bar--target" style="height: <?= (int) max(0, min(100, $ms['target'])) ?>%"></span>
                                        <span class="rb-bar rb-bar--actual" style="height: <?= (int) max(0, min(100, $ms['actual'])) ?>%"></span>
                                    </div>
                                    <span class="rb-bar-label"><?= smsRsEsc($ms['short']) ?></span>
                                    <span class="rb-bar-val"><?= (int) $ms['actual'] ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="rb-muted">Your milestones appear here once your research plan opens.</p>
                <?php endif; ?>
                <div class="rb-explain">
                    <div>
                        <h3>Your progress explained</h3>
                        <?php foreach (($st['explain'] ?? []) as $line): ?>
                            <p><span class="rb-tri <?= $line['up'] ? 'rb-tri--up' : 'rb-tri--down' ?>"></span><?= smsRsEsc($line['text']) ?></p>
                        <?php endforeach; ?>
                    </div>
                    <div class="rb-typical">
                        <h3>Your typical activity</h3>
                        <div class="rb-typical-row">
                            <?php foreach (($st['typical'] ?? []) as $t): ?>
                                <div><strong><?= smsRsEsc($t['value']) ?></strong><span><?= smsRsEsc($t['label']) ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="rb-right">
            <!-- 3. Due box + summary -->
            <section class="rb-box rb-due">
                <div class="rb-due-top">
                    <div><span class="rb-k">Group number</span><strong class="rb-big-id"><?= smsRsEsc($s['group_number'] ?: '—') ?></strong></div>
                    <div class="rb-right-align"><span class="rb-k"><?= smsRsEsc($deadline['label'] ?? 'Next deadline') ?></span><strong class="rb-big-id"><?= smsRsEsc($deadline['date'] ?? 'None yet') ?></strong></div>
                </div>
                <div class="rb-please rb-please--<?= smsRsEsc($nextState) ?>">
                    <span class="rb-k">Your next step</span>
                    <strong><?= smsRsEsc($nextTitle) ?></strong>
                    <span class="rb-please-status"><?= smsRsEsc($nextStatus) ?></span>
                </div>
                <h2 class="rb-h2">Research Process Summary</h2>
                <div class="rb-line rb-line--bold"><span>Steps done</span><span><?= (int) $s['steps_done'] ?> of <?= (int) $s['steps_total'] ?></span></div>
                <p class="rb-fine">(each step below is explained on page 2)</p>
                <div class="rb-line rb-line--bold"><span>Steps of the CRAD process</span><span>Status</span></div>
                <ul class="rb-lines">
                    <?php foreach ($steps as $step): ?>
                        <li class="rb-is-<?= smsRsEsc($step['state']) ?>">
                            <a href="#rs-step-<?= smsRsEsc($step['key']) ?>"><?= (int) $step['no'] ?>. <?= smsRsEsc($step['title']) ?></a>
                            <span><?= smsRsEsc($step['status']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="rb-total"><span>Overall Progress</span><strong><?= (int) round($curr) ?>%</strong></div>
            </section>

            <!-- 5. Notices -->
            <section class="rb-box rb-promo">
                <h2>CRAD is here to help</h2>
                <?php if ($notice): ?>
                    <div class="rb-promo-notice">
                        <span class="rb-k">Latest announcement</span>
                        <strong><?= smsRsEsc($notice['title']) ?></strong>
                        <p><?= smsRsEsc($notice['body']) ?></p>
                    </div>
                <?php endif; ?>
                <p class="rb-promo-contact">Questions about your research process? Visit the CRAD office or message your research coordinator.</p>
            </section>
        </div>
    </div>

    <!-- 6. Instruction stub -->
    <section class="rb-box rb-stub">
        <div class="rb-stub-text">
            <h2>What to do next</h2>
            <ol>
                <?php foreach (($st['instructions'] ?? []) as $i): ?><li><?= smsRsEsc($i) ?></li><?php endforeach; ?>
            </ol>
        </div>
        <div class="rb-stub-box">
            <div><span class="rb-k">Group No.</span><strong><?= smsRsEsc($s['group_number'] ?: '—') ?></strong></div>
            <div><span class="rb-k">Next step</span><strong><?= smsRsEsc($nextTitle) ?></strong></div>
        </div>
    </section>

    <div class="rb-page2-label">Page 2 of 2 · Details per step</div>
    <?= smsRenderResearchStatementSteps($steps, $linkBase) ?>
</article>
    <?php
    return (string) ob_get_clean();
}
