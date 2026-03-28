<?php
/**
 * SmartCare Guardian — Risk Context Helper
 * =========================================
 * Translates flagged vitals from the AI alerts array into
 * human-readable health context labels and plain-English
 * explanations for each viewer role.
 *
 * HOW TO USE:
 *   require_once 'risk_context_helper.php';
 *
 *   $alerts_array = $predictions[$rid]['alerts']['alerts'];  // from AI response
 *   $risk_level   = $predictions[$rid]['prediction']['risk_level'];
 *
 *   $context = getRiskContext($alerts_array, $risk_level);
 *
 *   // $context keys:
 *   //   'label'          — short clinical pattern label  (admin / caregiver)
 *   //   'icon'           — FontAwesome class string
 *   //   'color_class'    — CSS variable key: amber | red | blue | purple | green
 *   //   'summary_admin'  — 1-line summary for admin/caregiver
 *   //   'summary_elder'  — plain-English, non-alarming text for the elder
 *   //   'flagged_vitals' — array of formatted vital strings e.g. "BP Systolic: 158 mmHg (HIGH)"
 */


// ─────────────────────────────────────────────────────────────────────────────
// MAIN FUNCTION
// ─────────────────────────────────────────────────────────────────────────────

function getRiskContext(array $alerts, string $risk_level): array
{
    // Build a quick lookup: which vital signs are flagged and how
    $flagged = [];
    foreach ($alerts as $a) {
        $key = strtolower(str_replace(' ', '_', $a['vital_sign'] ?? ''));
        $flagged[$key] = $a['status'] ?? '';   // 'HIGH' or 'LOW'
    }

    // Detect patterns (order matters — most severe first)
    $pattern = detectPattern($flagged, $risk_level);

    // Build formatted flagged vitals list for display
    $formatted_vitals = [];
    foreach ($alerts as $a) {
        $formatted_vitals[] = sprintf(
            '%s: %s %s (%s) — Normal: %s',
            $a['vital_sign']   ?? '',
            $a['value']        ?? '',
            $a['unit']         ?? '',
            $a['status']       ?? '',
            $a['normal_range'] ?? ''
        );
    }

    return array_merge($pattern, ['flagged_vitals' => $formatted_vitals]);
}


// ─────────────────────────────────────────────────────────────────────────────
// PATTERN DETECTION
// Maps combinations of flagged vitals → clinical context
// ─────────────────────────────────────────────────────────────────────────────

function detectPattern(array $flagged, string $risk_level): array
{
    $has = fn(string $key) => isset($flagged[$key]);
    $hi  = fn(string $key) => isset($flagged[$key]) && $flagged[$key] === 'HIGH';
    $lo  = fn(string $key) => isset($flagged[$key]) && $flagged[$key] === 'LOW';

    $count = count($flagged);

    // ── 1. Multiple vitals flagged simultaneously ──────────────────────────
    if ($count >= 4) {
        return [
            'label'         => 'Multi-system deterioration',
            'icon'          => 'fa-triangle-exclamation',
            'color_class'   => 'red',
            'summary_admin' => 'Multiple vital signs are abnormal simultaneously. Immediate clinical review recommended.',
            'summary_elder' => 'Your health readings need a check-up today. Your care team has been notified.',
        ];
    }

    // ── 2. Cardiovascular stress (high BP + high pulse) ───────────────────
    if ($hi('blood_pressure_systolic') && $hi('pulse')) {
        return [
            'label'         => 'Cardiovascular stress indicators',
            'icon'          => 'fa-heart-pulse',
            'color_class'   => 'red',
            'summary_admin' => 'Elevated blood pressure combined with high pulse rate. Monitor for cardiovascular episode.',
            'summary_elder' => 'Your heart rate and blood pressure are a little high. Please rest and let your carer know.',
        ];
    }

    // ── 3. Hypertensive episode (high BP, pulse may be normal) ────────────
    if ($hi('blood_pressure_systolic') || $hi('blood_pressure_diastolic')) {
        $both = $hi('blood_pressure_systolic') && $hi('blood_pressure_diastolic');
        return [
            'label'         => $both ? 'Hypertensive episode indicators' : 'Elevated blood pressure',
            'icon'          => 'fa-heart-pulse',
            'color_class'   => $risk_level === 'high' ? 'red' : 'amber',
            'summary_admin' => $both
                ? 'Both systolic and diastolic blood pressure are above normal. Review medications and activity.'
                : 'Systolic or diastolic blood pressure is elevated. Monitor closely.',
            'summary_elder' => 'Your blood pressure reading is a bit high today. Try to rest and avoid stress.',
        ];
    }

    // ── 4. Hypotension / fall risk (low BP) ───────────────────────────────
    if ($lo('blood_pressure_systolic') || $lo('blood_pressure_diastolic')) {
        return [
            'label'         => 'Hypotension / fall-risk indicators',
            'icon'          => 'fa-person-falling',
            'color_class'   => 'red',
            'summary_admin' => 'Low blood pressure detected. Resident may be at increased fall risk. Check hydration and medications.',
            'summary_elder' => 'Your blood pressure is a little low today. Please be careful when standing up, and drink some water.',
        ];
    }

    // ── 5. Hyperglycaemic episode (high blood sugar) ──────────────────────
    if ($hi('blood_sugar')) {
        return [
            'label'         => 'Hyperglycaemic episode indicators',
            'icon'          => 'fa-droplet',
            'color_class'   => 'amber',
            'summary_admin' => 'Blood sugar is above normal range. Check meal timing, insulin schedule, or recent diet.',
            'summary_elder' => 'Your blood sugar is a little high. Your carer will check in with you about your meals.',
        ];
    }

    // ── 6. Hypoglycaemic episode (low blood sugar) ────────────────────────
    if ($lo('blood_sugar')) {
        return [
            'label'         => 'Hypoglycaemic episode indicators',
            'icon'          => 'fa-droplet',
            'color_class'   => 'red',
            'summary_admin' => 'Blood sugar is below normal. Risk of hypoglycaemic episode — check for dizziness or confusion.',
            'summary_elder' => 'Your blood sugar is a little low. Have a small snack and let your carer know right away.',
        ];
    }

    // ── 7. Respiratory distress (low oxygen saturation) ───────────────────
    if ($lo('oxygen_saturation')) {
        return [
            'label'         => 'Respiratory distress indicators',
            'icon'          => 'fa-lungs',
            'color_class'   => 'red',
            'summary_admin' => 'Oxygen saturation is below 95%. Assess breathing, positioning, and respiratory function.',
            'summary_elder' => 'Your oxygen level is a little low. Please sit upright and breathe slowly. Your carer has been notified.',
        ];
    }

    // ── 8. Fever / Possible infection (high temperature) ─────────────────
    if ($hi('temperature')) {
        return [
            'label'         => 'Possible infection / fever indicators',
            'icon'          => 'fa-thermometer-half',
            'color_class'   => 'amber',
            'summary_admin' => 'Elevated body temperature detected. Assess for signs of infection or inflammation.',
            'summary_elder' => 'You have a slightly raised temperature today. Drink plenty of water and let your carer know if you feel unwell.',
        ];
    }

    // ── 9. Hypothermia risk (low temperature) ─────────────────────────────
    if ($lo('temperature')) {
        return [
            'label'         => 'Low body temperature indicators',
            'icon'          => 'fa-thermometer-empty',
            'color_class'   => 'blue',
            'summary_admin' => 'Body temperature is below normal. Check warmth of environment and clothing.',
            'summary_elder' => 'Your body temperature is a little low. Put on an extra layer and let your carer know.',
        ];
    }

    // ── 10. Tachycardia / Bradycardia (pulse only) ────────────────────────
    if ($hi('pulse')) {
        return [
            'label'         => 'Elevated heart rate (tachycardia indicators)',
            'icon'          => 'fa-heart',
            'color_class'   => 'amber',
            'summary_admin' => 'Pulse rate is elevated. Check for anxiety, dehydration, pain, or medication effects.',
            'summary_elder' => 'Your heart rate is a little fast today. Rest quietly and tell your carer if you feel dizzy or short of breath.',
        ];
    }
    if ($lo('pulse')) {
        return [
            'label'         => 'Low heart rate (bradycardia indicators)',
            'icon'          => 'fa-heart',
            'color_class'   => 'blue',
            'summary_admin' => 'Pulse rate is lower than normal. Assess for medication side-effects or cardiac causes.',
            'summary_elder' => 'Your heart rate is a little slow today. Let your carer know if you feel faint or very tired.',
        ];
    }

    // ── 11. AI flagged risk but no specific vital abnormality shown ────────
    if ($risk_level === 'high' || $risk_level === 'medium') {
        return [
            'label'         => 'Elevated overall health risk',
            'icon'          => 'fa-chart-line',
            'color_class'   => $risk_level === 'high' ? 'red' : 'amber',
            'summary_admin' => 'AI model has detected an elevated risk pattern in the combined vital signs, even though individual readings may appear borderline.',
            'summary_elder' => 'Your health readings today show something to keep an eye on. Your care team will check in with you.',
        ];
    }

    // ── 12. All clear ─────────────────────────────────────────────────────
    return [
        'label'         => 'All readings within normal range',
        'icon'          => 'fa-circle-check',
        'color_class'   => 'green',
        'summary_admin' => 'No abnormal vital signs detected. Resident appears stable.',
        'summary_elder' => 'All your health readings look good today. Keep it up!',
    ];
}


// ─────────────────────────────────────────────────────────────────────────────
// DISPLAY HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns bg/text/border hex colors for each risk color class.
 * Uses hardcoded values matching the SmartCare Guardian design system
 * so the badge is always visible regardless of CSS variable availability.
 */
function contextColors(string $color_class): array
{
    return match($color_class) {
        'red'    => ['bg' => '#F5DADA', 'text' => '#6A2020', 'border' => '#C05050'],
        'amber'  => ['bg' => '#FDF3DC', 'text' => '#7A5520', 'border' => '#C89040'],
        'blue'   => ['bg' => '#DCE8F5', 'text' => '#1A3A5C', 'border' => '#4A7AAA'],
        'purple' => ['bg' => '#EDE8F5', 'text' => '#4A2878', 'border' => '#7A50B0'],
        'green'  => ['bg' => '#DDEFD8', 'text' => '#3A6830', 'border' => '#5E8A40'],
        default  => ['bg' => '#F2F6EF', 'text' => '#4A4540', 'border' => '#B8B0A4'],
    };
}

/**
 * Renders the context badge HTML.
 *
 * Usage (admin / caregiver):  echo renderContextBadge($context, 'admin');
 * Usage (elder):              echo renderContextBadge($context, 'elder');
 */
function renderContextBadge(array $context, string $role = 'admin'): string
{
    $c       = contextColors($context['color_class']);
    $bg      = $c['bg'];
    $text    = $c['text'];
    $border  = $c['border'];
    $icon    = htmlspecialchars($context['icon']);
    $label   = htmlspecialchars($context['label']);
    $summary = htmlspecialchars(
        $role === 'elder' ? $context['summary_elder'] : $context['summary_admin']
    );

    if ($role === 'elder') {
        return <<<HTML
        <div style="background:{$bg};border-radius:12px;padding:16px 18px;margin-top:14px;border-left:4px solid {$border};">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                <i class="fas {$icon}" style="color:{$border};font-size:20px;flex-shrink:0;"></i>
                <span style="font-weight:700;color:{$text};font-size:1.05em;line-height:1.3;">{$label}</span>
            </div>
            <p style="margin:0;color:#4A4540;font-size:1em;line-height:1.65;">{$summary}</p>
        </div>
        HTML;
    }

    // Admin / caregiver — full-width strip with strong left border
    return <<<HTML
    <div style="background:{$bg};border-left:4px solid {$border};border-radius:0 8px 8px 0;padding:11px 14px;margin-top:10px;display:flex;align-items:flex-start;gap:10px;">
        <i class="fas {$icon}" style="color:{$border};font-size:16px;margin-top:1px;flex-shrink:0;"></i>
        <div style="flex:1;">
            <div style="font-weight:700;color:{$text};font-size:12px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">{$label}</div>
            <div style="color:#4A4540;font-size:13px;line-height:1.5;">{$summary}</div>
        </div>
    </div>
    HTML;
}

/**
 * Renders a compact flagged vitals list — useful in admin resident cards.
 *
 * Usage:
 *   echo renderFlaggedVitals($context['flagged_vitals']);
 */
function renderFlaggedVitals(array $flagged_vitals): string
{
    if (empty($flagged_vitals)) {
        return '<span style="color:var(--green-text);font-size:12px;"><i class="fas fa-check me-1"></i>No abnormal vitals</span>';
    }

    $items = '';
    foreach ($flagged_vitals as $v) {
        $is_high = str_contains($v, '(HIGH)');
        $is_low  = str_contains($v, '(LOW)');
        $color   = $is_high ? 'var(--red-text)' : ($is_low ? 'var(--blue-text)' : 'var(--st700)');
        $icon    = $is_high ? 'fa-arrow-up' : ($is_low ? 'fa-arrow-down' : 'fa-minus');
        $items  .= "<li style='color:{$color};font-size:12px;margin-bottom:3px;'>"
                 . "<i class='fas {$icon} me-1' style='width:10px;'></i>"
                 . htmlspecialchars($v)
                 . "</li>";
    }

    return "<ul style='list-style:none;padding:0;margin:6px 0 0;'>{$items}</ul>";
}