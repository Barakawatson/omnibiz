<?php
/**
 * First-run banner.
 * ---------------------------------------------------------------
 * Shown on the dashboard only while setup has never been completed,
 * and only to someone who is allowed to run it. It disappears by
 * itself the moment setup is marked complete - there is no dismiss
 * button and no "remind me later" setting, because a banner that can
 * be dismissed while the shop is still unconfigured just hides the
 * problem until somebody hits it at the till.
 *
 * A shop that already has products or sales never sees this at all:
 * businessSyncSetupState() marks such a database complete during
 * boot, so an upgrade cannot make a working shop look unconfigured.
 *
 * It is a checklist rather than a nag. Each line reports what is
 * actually in the database, so it doubles as an answer to "what is
 * left to do?" and each unfinished item links straight to the screen
 * that fixes it.
 *
 * Expects $conn, and business_types.php to be loaded.
 */

if (!function_exists('businessSetupComplete')) {
    require_once __DIR__ . '/../../includes/business_types.php';
}

// Not for cashiers, storekeepers or managers - setup is administrator
// work, gated on the same module key as the wizard itself.
if (!userCan('shop_settings') || businessSetupComplete($conn)) { return; }

$frCount = function (string $sql) use ($conn): int {
    $r = @$conn->query($sql);
    if (!($r instanceof mysqli_result)) { return 0; }
    $n = (int)(array_values($r->fetch_assoc() ?: [0])[0]);
    $r->free();
    return $n;
};

$frShopName = trim(shopSetting($conn, 'shop_name'));

$frSteps = [
    [
        'label' => 'Tell the system what kind of business this is',
        'done'  => shopSetting($conn, 'shop_business_type') !== '',
        'href'  => 'setup.php?step=1',
        'note'  => 'Supermarket, pharmacy, hardware, stationery - or your own.',
    ],
    [
        'label' => 'Add the business name and contact details',
        'done'  => $frShopName !== '',
        'href'  => 'setup.php?step=2',
        'note'  => 'Printed on every receipt and report.',
    ],
    [
        'label' => 'Set up departments',
        'done'  => count(catalogActiveDepartments($conn)) > 0,
        'href'  => 'setup.php?step=3',
        'note'  => 'How this shop divides what it sells.',
    ],
    [
        'label' => 'Add categories',
        'done'  => $frCount("SELECT COUNT(*) c FROM inv_categories WHERE deleted_at IS NULL") > 0,
        'href'  => 'setup.php?step=4',
        'note'  => 'Groups inside a department.',
    ],
    [
        'label' => 'Add your first products',
        'done'  => $frCount("SELECT COUNT(*) c FROM inv_items WHERE deleted_at IS NULL") > 0,
        'href'  => 'inventory-items.php',
        'note'  => 'Nothing can be sold until there is something to sell.',
    ],
    [
        'label' => 'Change the default administrator password',
        // Seeded installs all share one password, so this is a real
        // security step rather than housekeeping.
        'done'  => !function_exists('password_verify')
                   || !(function () use ($conn) {
                        $r = @$conn->query("SELECT password FROM admin WHERE username = 'admin' LIMIT 1");
                        if (!($r instanceof mysqli_result)) { return false; }
                        $row = $r->fetch_assoc();
                        $r->free();
                        return $row && password_verify('12345', (string)$row['password']);
                     })(),
        'href'  => 'profile.php',
        'note'  => 'The install password is public knowledge.',
    ],
];

$frDone  = 0;
foreach ($frSteps as $s) { if ($s['done']) { $frDone++; } }
$frTotal = count($frSteps);
$frPct   = (int)round(($frDone / max(1, $frTotal)) * 100);
?>
<div class="ui-card" style="border-left:5px solid var(--color-primary);margin-bottom:var(--space-4);">
    <div class="ui-card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-1">
                    <i class="fas fa-wand-magic-sparkles me-2" style="color:var(--color-primary);"></i>
                    Welcome &mdash; let's set this shop up
                </h5>
                <div class="ui-caption" style="max-width:60ch;">
                    This system does not assume what kind of shop it is running. Tell it once, and the till,
                    stock, reports and receipts all follow.
                </div>
            </div>
            <a href="setup.php" class="ui-btn ui-btn-primary" style="white-space:nowrap;">
                <i class="fas fa-play"></i><?php echo $frDone > 0 ? 'Continue setup' : 'Start setup'; ?>
            </a>
        </div>

        <div class="d-flex align-items-center gap-2 mb-3">
            <div style="flex:1;height:7px;background:var(--color-surface-alt);border-radius:20px;overflow:hidden;">
                <div style="width:<?php echo $frPct; ?>%;height:100%;background:var(--color-primary);"></div>
            </div>
            <span class="ui-caption" style="white-space:nowrap;"><?php echo $frDone; ?> of <?php echo $frTotal; ?> done</span>
        </div>

        <div class="row g-2">
            <?php foreach ($frSteps as $s): ?>
            <div class="col-md-6">
                <div class="d-flex align-items-start gap-2" style="font-size:.86rem;">
                    <?php if ($s['done']): ?>
                        <i class="fas fa-circle-check mt-1" style="color:var(--color-success);"></i>
                        <div>
                            <span style="text-decoration:line-through;opacity:.65;"><?php echo htmlspecialchars($s['label']); ?></span>
                        </div>
                    <?php else: ?>
                        <i class="far fa-circle mt-1" style="color:var(--color-text-muted);"></i>
                        <div>
                            <a href="<?php echo htmlspecialchars($s['href']); ?>"><?php echo htmlspecialchars($s['label']); ?></a>
                            <div class="ui-caption"><?php echo htmlspecialchars($s['note']); ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="ui-caption mt-3">
            This message disappears when you finish setup, and nothing here is permanent &mdash;
            every setting stays editable under <strong>Administration</strong>.
        </div>
    </div>
</div>
