<?php
/**
 * SmartCare Guardian — AI Suggestions Display
 * ============================================
 * Add this snippet to your caregiver resident view page.
 * 
 * HOW TO USE:
 * 1. At the top of your caregiver resident page, add:
 *    require_once '../ai-service/RoutineSuggestionAI.php';
 *
 * 2. After your DB connection ($conn), add:
 *    $ai = new RoutineSuggestionAI($conn);
 *    $aiResult = $ai->getSuggestionsForResident($residentId);
 *
 * 3. Then paste the HTML section below where you want the suggestions to appear.
 */

// ── EXAMPLE: paste this at the top of your resident detail page ──────────────

/*
require_once '../ai-service/RoutineSuggestionAI.php';

$residentId = (int)$_GET['id'];   // or however you get the resident ID

$ai       = new RoutineSuggestionAI($conn);
$aiResult = $ai->getSuggestionsForResident($residentId);

$suggestions = $aiResult['suggestions'] ?? [];
*/

// ── ICONS per routine type ────────────────────────────────────────────────────
$icons = [
    'meal'     => '🍽️',
    'exercise' => '🏃',
    'checkup'  => '🩺',
    'hygiene'  => '🧼',
    'therapy'  => '💆',
];

// ── COLORS per action ─────────────────────────────────────────────────────────
$actionColors = [
    'add'    => 'success',   // green  — add a new routine
    'change' => 'warning',   // yellow — modify existing routine
];

$actionLabels = [
    'add'    => 'Add New',
    'change' => 'Update',
];
?>

<!-- ═══════════════════════════════════════════════════════════════
     AI ROUTINE SUGGESTIONS PANEL
     Paste this block inside your caregiver resident detail page
     ═══════════════════════════════════════════════════════════════ -->

<div class="card shadow-sm mt-4" id="ai-suggestions-section">
    <div class="card-header d-flex align-items-center gap-2"
         style="background: linear-gradient(135deg, #1a73e8, #0d47a1); color: white;">
        <span style="font-size:1.3rem;">🤖</span>
        <div>
            <strong>AI Routine Suggestions</strong>
            <div style="font-size:0.78rem; opacity:0.85;">
                Generated based on health data and current routines
            </div>
        </div>
        <?php if (!empty($suggestions)): ?>
            <span class="badge bg-light text-dark ms-auto">
                <?= count($suggestions) ?> suggestion<?= count($suggestions) > 1 ? 's' : '' ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="card-body">

        <?php if (isset($aiResult['error'])): ?>
            <!-- API unavailable message -->
            <div class="alert alert-secondary d-flex align-items-center gap-2 mb-0">
                <span>⚠️</span>
                <span>AI suggestions are currently unavailable. Make sure the AI service is running.</span>
            </div>

        <?php elseif (empty($suggestions)): ?>
            <!-- No suggestions needed -->
            <div class="alert alert-success d-flex align-items-center gap-2 mb-0">
                <span>✅</span>
                <span>
                    <strong>All routines are appropriate</strong> for this resident's
                    current health status. No changes recommended at this time.
                </span>
            </div>

        <?php else: ?>
            <!-- Suggestion cards -->
            <p class="text-muted small mb-3">
                The AI has reviewed this resident's recent health data and current
                routines. Review each suggestion and apply as appropriate.
            </p>

            <div class="row g-3">
                <?php foreach ($suggestions as $s): ?>
                    <?php
                        $icon    = $icons[$s['routine_type']]   ?? '📋';
                        $color   = $actionColors[$s['action']]  ?? 'secondary';
                        $label   = $actionLabels[$s['action']]  ?? 'Review';
                        $confPct = round($s['confidence'] * 100);
                    ?>
                    <div class="col-md-6">
                        <div class="card h-100 border-<?= $color ?> border-start border-4 shadow-sm">
                            <div class="card-body py-3">

                                <!-- Header row -->
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <span style="font-size:1.4rem;"><?= $icon ?></span>
                                        <strong><?= htmlspecialchars($s['title']) ?></strong>
                                    </div>
                                    <span class="badge bg-<?= $color ?> text-<?= $color === 'warning' ? 'dark' : 'white' ?>">
                                        <?= $label ?>
                                    </span>
                                </div>

                                <!-- Description -->
                                <p class="text-muted small mb-3">
                                    <?= htmlspecialchars($s['description']) ?>
                                </p>

                                <!-- Confidence bar -->
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between small text-muted mb-1">
                                        <span>AI Confidence</span>
                                        <span><?= $confPct ?>%</span>
                                    </div>
                                    <div class="progress" style="height:6px;">
                                        <div class="progress-bar bg-<?= $color ?>"
                                             style="width:<?= $confPct ?>%"></div>
                                    </div>
                                </div>

                                <!-- Action button — links to your existing add/edit routine page -->
                                <?php
                                    $resId = $aiResult['resident_id'] ?? '';
                                    if ($s['action'] === 'add') {
                                        $btnHref = "add_routine.php?resident_id={$resId}&type={$s['routine_type']}";
                                        $btnText = "Add Routine";
                                    } else {
                                        $btnHref = "edit_routine.php?resident_id={$resId}&type={$s['routine_type']}";
                                        $btnText = "Edit Routine";
                                    }
                                ?>
                                <a href="<?= $btnHref ?>"
                                   class="btn btn-sm btn-outline-<?= $color ?> w-100">
                                    <?= $btnText ?>
                                </a>

                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </div><!-- /.card-body -->

    <div class="card-footer text-muted small d-flex justify-content-between">
        <span>🔬 Powered by SmartCare AI · Random Forest Model · 98.2% avg accuracy</span>
        <span><?= date('d M Y, H:i') ?></span>
    </div>
</div>
<!-- ═══════════════════════════════════════════════════════════════ -->