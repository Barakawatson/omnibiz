<?php
// ============================================================
// GET /admin/notifications-api.php
//   Live counts for the sidebar badges, polled every 30s by
//   MX.watchAlerts (assets/js/admin/ui.js). Keys here must match
//   the badge ids rendered by sidebar-admin.php and the ALERT_META
//   map in ui.js, or a count simply won't paint anywhere.
//
// Only counts the signed-in role is allowed to see are returned,
// so a cashier is never told how many purchase orders are open.
//
// Response: { low_stock: n, requests: n, open_pos: n, unclosed_days: n }
// ============================================================
require_once '../includes/auth.php';

if (!isset($_SESSION['username'], $_SESSION['role'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not signed in.']);
    exit;
}

header('Content-Type: application/json');
include '../includes/db.php';

/** One count, guarded so a table that isn't installed yet returns 0. */
function alertCount(mysqli $conn, string $sql): int {
    $r = @$conn->query($sql);
    if ($r instanceof mysqli_result) {
        $row = $r->fetch_assoc();
        return (int)($row ? array_values($row)[0] : 0);
    }
    return 0;
}

$counts = [];

if (userCan('inventory')) {
    $counts['low_stock'] = alertCount($conn,
        "SELECT COUNT(*) FROM inv_items
          WHERE deleted_at IS NULL AND status = 'active'
            AND (current_stock <= 0 OR (reorder_level > 0 AND current_stock <= reorder_level))");
}

if (userCan('stock_requests')) {
    $counts['requests'] = alertCount($conn,
        "SELECT COUNT(*) FROM inv_stock_requests WHERE status = 'pending'");
}

if (userCan('inventory')) {
    $counts['disposal'] = alertCount($conn,
        "SELECT COUNT(*) FROM inv_disposal_requests WHERE status = 'pending'");
}

if (userCan('purchasing')) {
    $counts['open_pos'] = alertCount($conn,
        "SELECT COUNT(*) FROM inv_purchase_orders
          WHERE deleted_at IS NULL AND status IN ('draft','approved','partially_received')");
}

if (userCan('accounting')) {
    // Past trading days whose cash was never counted against the books.
    $counts['unclosed_days'] = alertCount($conn,
        "SELECT COUNT(DISTINCT DATE(t.created_at)) FROM sales_transactions t
          WHERE t.status = 'completed' AND DATE(t.created_at) < CURDATE()
            AND NOT EXISTS (SELECT 1 FROM acc_daily_close c WHERE c.close_date = DATE(t.created_at))");
}

if (userCan('fraud_audit')) {
    // Admin-only, same as the Cancelled Carts report itself. This is
    // what makes the report "near real-time" (the next 30s poll, not
    // instant) without any push infrastructure - MX.watchAlerts()
    // already toasts anything whose count went up since the last check.
    $counts['cancelled_carts_today'] = alertCount($conn,
        "SELECT COUNT(*) FROM cancelled_carts WHERE DATE(created_at) = CURDATE()");
}

echo json_encode($counts);
