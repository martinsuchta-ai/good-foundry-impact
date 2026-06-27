<?php
/**
 * impacts_tier_thresholds.php — tier-mandated go-live minimums.
 *
 * Brief §4 + §5: a project cannot transition planning -> execution
 * unless the tier-mandated minimums are met OR an admin override is
 * set (tier_override_reason). The minimums are policy not data, so
 * they live in code — every layer (state engine, admin UI, public
 * progress meter) computes them the same way.
 *
 * Tiers (impact_project.scale):
 *   - micro       — informal / very small initiatives. 1 supporter to launch.
 *   - mid         — typical small project. Needs a small confirmed cohort.
 *   - macro       — full campaign tier. Requires demonstrated demand.
 *   - borderless  — globally pooled (no geographic constraint). Highest bar.
 *
 * Minimums apply to CONFIRMED + FULFILLED pledges only — a 'pledged'
 * (not yet confirmed) effort pledge doesn't count. Money-lane pledges
 * count whenever they exist because the money lane has no confirm step
 * (the redirect IS the commitment, and we don't see fulfilment).
 *
 * Adjust these constants conservatively — every existing planning
 * project will be re-evaluated against the new threshold at next
 * scheduler tick. Lowering a threshold may auto-flip projects to
 * execution; raising one may NOT pull projects back from execution
 * (state machine is monotonic — see brief §4).
 */

declare(strict_types=1);

/**
 * Per-tier go-live minimums. Keys must match the impact_project.scale
 * ENUM. Each entry can specify any subset of {supporters, pledges,
 * money_pledges} — all specified gates must be cleared (AND logic),
 * unspecified gates are skipped.
 */
function impacts_tier_thresholds(): array
{
    return [
        'micro'      => ['supporters' => 1,   'pledges' => 1],
        'mid'        => ['supporters' => 5,   'pledges' => 5],
        'macro'      => ['supporters' => 20,  'pledges' => 20],
        'borderless' => ['supporters' => 100, 'pledges' => 50],
    ];
}

/**
 * Return the live pledge progress for a project — used by the state
 * engine to gate planning -> execution and by the admin / public UIs
 * to render a progress meter.
 *
 * Counts:
 *   - supporters         distinct supporter_id across all qualifying pledges
 *   - pledges            count of qualifying pledges (any lane)
 *   - money_pledges      count of money-lane pledges
 *   - effort_pledges     count of effort-lane pledges (confirmed/fulfilled)
 *   - energy_pledges     count of energy-lane pledges (confirmed/fulfilled)
 *
 * Qualifying = money-lane (any status) OR effort/energy with status
 * confirmed/fulfilled. Withdrawn pledges never count.
 *
 * @return array{
 *   supporters:int, pledges:int, money_pledges:int,
 *   effort_pledges:int, energy_pledges:int
 * }
 */
function impacts_pledge_progress(PDO $pdo, int $projectId): array
{
    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT CASE
                WHEN (
                    p.`lane` = 'money'
                    OR p.`status` IN ('confirmed', 'fulfilled')
                ) THEN p.`supporter_id` END
            ) AS supporters,
            SUM(CASE
                WHEN (
                    p.`lane` = 'money'
                    OR p.`status` IN ('confirmed', 'fulfilled')
                ) THEN 1 ELSE 0 END
            ) AS pledges,
            SUM(CASE WHEN p.`lane` = 'money' THEN 1 ELSE 0 END) AS money_pledges,
            SUM(CASE WHEN p.`lane` = 'effort'
                       AND p.`status` IN ('confirmed', 'fulfilled') THEN 1 ELSE 0 END) AS effort_pledges,
            SUM(CASE WHEN p.`lane` = 'energy'
                       AND p.`status` IN ('confirmed', 'fulfilled') THEN 1 ELSE 0 END) AS energy_pledges
        FROM `contribution_pledge` p
        JOIN `contribution_ask` a ON a.`id` = p.`contribution_ask_id`
        WHERE a.`impact_project_id` = ?
    ");
    $stmt->execute([$projectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'supporters'     => (int) ($row['supporters']     ?? 0),
        'pledges'        => (int) ($row['pledges']        ?? 0),
        'money_pledges'  => (int) ($row['money_pledges']  ?? 0),
        'effort_pledges' => (int) ($row['effort_pledges'] ?? 0),
        'energy_pledges' => (int) ($row['energy_pledges'] ?? 0),
    ];
}

/**
 * Evaluate a project against its tier minimums. Pure read — never
 * mutates state.
 *
 * Returns:
 *   met        — true if every applicable threshold is cleared
 *   override   — true if tier_override_reason is set (caller may choose
 *                to honour `met` OR `override` per Brief §5)
 *   tier       — the project's scale string
 *   thresholds — the minimums for this tier
 *   progress   — pledge_progress() output
 *   shortfall  — per-gate {required, actual, missing} for any gate not met
 *
 * @return array{
 *   met:bool, override:bool, tier:string,
 *   thresholds:array, progress:array, shortfall:array
 * }
 */
function impacts_evaluate_thresholds(PDO $pdo, int $projectId): array
{
    $proj = $pdo->prepare("SELECT `scale`, `tier_override_reason` FROM `impact_project` WHERE `id` = ? LIMIT 1");
    $proj->execute([$projectId]);
    $r = $proj->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return [
            'met' => false, 'override' => false, 'tier' => 'unknown',
            'thresholds' => [], 'progress' => [], 'shortfall' => [],
        ];
    }

    $tier      = (string) $r['scale'];
    $override  = trim((string) ($r['tier_override_reason'] ?? '')) !== '';
    $all       = impacts_tier_thresholds();
    $thresh    = $all[$tier] ?? [];
    $progress  = impacts_pledge_progress($pdo, $projectId);

    $shortfall = [];
    $met = true;
    foreach ($thresh as $gate => $required) {
        $actual = (int) ($progress[$gate] ?? 0);
        if ($actual < $required) {
            $met = false;
            $shortfall[$gate] = [
                'required' => $required,
                'actual'   => $actual,
                'missing'  => $required - $actual,
            ];
        }
    }

    return [
        'met'        => $met,
        'override'   => $override,
        'tier'       => $tier,
        'thresholds' => $thresh,
        'progress'   => $progress,
        'shortfall'  => $shortfall,
    ];
}

/**
 * Boolean shortcut for the state engine. True if the project may
 * proceed planning -> execution (either thresholds met OR admin
 * override set).
 */
function impacts_thresholds_clear(PDO $pdo, int $projectId): bool
{
    $eval = impacts_evaluate_thresholds($pdo, $projectId);
    return $eval['met'] || $eval['override'];
}
