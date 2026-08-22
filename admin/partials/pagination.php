<?php
// ============================================================
// Partial: pagination
// ------------------------------------------------------------
//   $pg = [
//     'page'     => 2,        // current page, 1-based
//     'pages'    => 9,        // total pages
//     'total'    => 412,      // total rows
//     'per_page' => 50,
//     'base'     => '?filter=all',  // existing query string WITHOUT page
//     'label'    => 'products',     // optional noun
//   ];
//   include 'partials/pagination.php';
//
// Renders nothing when there is only one page - a lone "1" tells
// the user nothing.
// ============================================================
$pg = $pg ?? [];
$pgPage  = max(1, (int)($pg['page'] ?? 1));
$pgPages = max(1, (int)($pg['pages'] ?? 1));
$pgTotal = (int)($pg['total'] ?? 0);
$pgPer   = max(1, (int)($pg['per_page'] ?? 50));
$pgBase  = $pg['base'] ?? '?';
$pgLabel = $pg['label'] ?? 'rows';

if ($pgPages > 1 || $pgTotal > $pgPer):
    // Append page= to whatever filters are already in the query string.
    $sep = (strpos($pgBase, '?') === false) ? '?' : (substr($pgBase, -1) === '?' ? '' : '&');
    $link = function ($n) use ($pgBase, $sep) {
        return htmlspecialchars($pgBase . $sep . 'page=' . $n);
    };

    $from = ($pgPage - 1) * $pgPer + 1;
    $to   = min($pgPage * $pgPer, $pgTotal);

    // Window of page numbers around the current one, with gaps.
    $window = [];
    for ($i = 1; $i <= $pgPages; $i++) {
        if ($i === 1 || $i === $pgPages || abs($i - $pgPage) <= 1) { $window[] = $i; }
    }
    $window = array_values(array_unique($window));
?>
<nav class="ui-pagination" aria-label="Pagination">
    <div class="ui-pagination-info">
        Showing <strong><?php echo number_format($from); ?></strong>–<strong><?php echo number_format($to); ?></strong>
        of <strong><?php echo number_format($pgTotal); ?></strong> <?php echo htmlspecialchars($pgLabel); ?>
    </div>
    <div class="ui-pagination-pages">
        <a class="ui-page-link <?php echo $pgPage <= 1 ? 'disabled' : ''; ?>"
           href="<?php echo $link(max(1, $pgPage - 1)); ?>" aria-label="Previous page">
            <i class="fas fa-angle-left"></i>
        </a>

        <?php $prev = 0; foreach ($window as $n): ?>
            <?php if ($prev && $n - $prev > 1): ?><span class="ui-page-gap">…</span><?php endif; ?>
            <a class="ui-page-link <?php echo $n === $pgPage ? 'active' : ''; ?>"
               href="<?php echo $link($n); ?>"
               <?php echo $n === $pgPage ? 'aria-current="page"' : ''; ?>><?php echo $n; ?></a>
            <?php $prev = $n; ?>
        <?php endforeach; ?>

        <a class="ui-page-link <?php echo $pgPage >= $pgPages ? 'disabled' : ''; ?>"
           href="<?php echo $link(min($pgPages, $pgPage + 1)); ?>" aria-label="Next page">
            <i class="fas fa-angle-right"></i>
        </a>
    </div>
</nav>
<?php endif; $pg = null; ?>
