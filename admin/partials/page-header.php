<?php
// ============================================================
// Partial: page header
// ------------------------------------------------------------
// Title, optional subtitle, and right-aligned actions.
//
//   $ph = [
//     'title'    => 'Products',
//     'subtitle' => 'Manage your retail catalogue and pricing',
//     'icon'     => 'fa-tags',                    // optional
//     'actions'  => '<a class="ui-btn …">…</a>',  // optional raw HTML
//   ];
//   include 'partials/page-header.php';
//
// 'actions' is emitted as-is, so callers must escape any dynamic
// values they interpolate into it.
// ============================================================
$ph = $ph ?? [];
?>
<div class="ui-page-header">
    <div>
        <h1>
            <?php if (!empty($ph['icon'])): ?>
                <i class="fas <?php echo htmlspecialchars($ph['icon']); ?>" style="color:var(--color-primary);font-size:1rem;margin-right:6px;"></i>
            <?php endif; ?>
            <?php echo htmlspecialchars($ph['title'] ?? ''); ?>
        </h1>
        <?php if (!empty($ph['subtitle'])): ?>
            <div class="subtitle"><?php echo $ph['subtitle']; ?></div>
        <?php endif; ?>
    </div>
    <?php if (!empty($ph['actions'])): ?>
        <div class="ui-page-actions"><?php echo $ph['actions']; ?></div>
    <?php endif; ?>
</div>
<?php $ph = null; ?>
