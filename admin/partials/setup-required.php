<?php
/**
 * Configuration guard.
 * ---------------------------------------------------------------
 * Shown at the top of a screen that cannot do its job until some
 * master data exists - a product needs a department to belong to,
 * and a unit to be counted in.
 *
 * This is deliberately a notice rather than a redirect: a shop that
 * has half-configured itself should still see its own screen, with
 * the missing piece named and a direct link to fix it. Redirecting
 * an administrator out of the page they asked for teaches them
 * nothing about what is wrong.
 *
 * Expects $conn. Optional $guardNeeds: which checks to run, from
 * 'departments', 'units', 'categories'. Defaults to departments.
 */
$guardNeeds = $guardNeeds ?? ['departments'];
$guardMissing = [];

if (in_array('departments', $guardNeeds, true) && !catalogActiveDepartments($conn)) {
    $guardMissing[] = [
        'what' => 'a department',
        'why'  => 'Every product belongs to one, and the till groups the shop by them.',
        'href' => 'departments.php',
        'cta'  => 'Add a department',
    ];
}

if (in_array('units', $guardNeeds, true)) {
    $r = @$conn->query("SELECT COUNT(*) c FROM inv_units WHERE is_active = 1 AND deleted_at IS NULL");
    $n = ($r instanceof mysqli_result) ? (int)($r->fetch_assoc()['c'] ?? 0) : 1;
    if ($n === 0) {
        $guardMissing[] = [
            'what' => 'a unit of measure',
            'why'  => 'Stock has to be counted in something - pieces, boxes, kilograms.',
            'href' => 'inventory-units.php',
            'cta'  => 'Add a unit',
        ];
    }
}

if (in_array('categories', $guardNeeds, true)) {
    $r = @$conn->query("SELECT COUNT(*) c FROM inv_categories WHERE is_active = 1 AND deleted_at IS NULL");
    $n = ($r instanceof mysqli_result) ? (int)($r->fetch_assoc()['c'] ?? 0) : 1;
    if ($n === 0) {
        $guardMissing[] = [
            'what' => 'a category',
            'why'  => 'Categories group products inside a department.',
            'href' => 'inventory-categories.php',
            'cta'  => 'Add a category',
        ];
    }
}

if ($guardMissing): ?>
<div class="alert alert-warning" style="border-radius:12px;">
  <div style="flex:1;">
    <div class="fw-bold mb-1">
        <i class="fas fa-triangle-exclamation me-1"></i>
        This shop is not set up yet
    </div>
    <?php foreach ($guardMissing as $m): ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 py-1">
        <div style="font-size:.86rem;">
            You need <strong><?php echo htmlspecialchars($m['what']); ?></strong> first.
            <span class="text-muted"><?php echo htmlspecialchars($m['why']); ?></span>
        </div>
        <a href="<?php echo htmlspecialchars($m['href']); ?>" class="btn btn-sm btn-inv" style="white-space:nowrap;">
            <?php echo htmlspecialchars($m['cta']); ?>
        </a>
    </div>
    <?php endforeach; ?>
    <div class="text-muted mt-1" style="font-size:.8rem;">
        Or run <a href="setup.php">Setup</a> to configure the whole shop in one pass.
    </div>
  </div>
</div>
<?php endif;
unset($guardNeeds, $guardMissing);
