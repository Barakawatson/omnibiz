<?php
// ============================================================
// Partial: empty state
// ------------------------------------------------------------
//   $es = [
//     'icon'   => 'fa-box-open',
//     'title'  => 'No products yet',
//     'msg'    => 'Add your first product to start selling.',
//     'action' => '<a class="ui-btn ui-btn-primary" href="…">Add product</a>',
//   ];
//   include 'partials/empty-state.php';
//
// Deliberately plain: an empty state should explain and offer the
// next step, not decorate the screen.
// ============================================================
$es = $es ?? [];
?>
<div class="ui-empty">
    <i class="fas <?php echo htmlspecialchars($es['icon'] ?? 'fa-inbox'); ?>"></i>
    <?php if (!empty($es['title'])): ?>
        <div class="ui-empty-title"><?php echo htmlspecialchars($es['title']); ?></div>
    <?php endif; ?>
    <?php if (!empty($es['msg'])): ?>
        <div class="ui-empty-msg"><?php echo htmlspecialchars($es['msg']); ?></div>
    <?php endif; ?>
    <?php if (!empty($es['action'])): ?>
        <div><?php echo $es['action']; ?></div>
    <?php endif; ?>
</div>
<?php $es = null; ?>
