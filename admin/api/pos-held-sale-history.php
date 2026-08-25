<?php
// ============================================================
// GET /admin/api/pos-held-sale-history.php?id=N
//   The full lifecycle audit trail for one held sale, oldest first -
//   what admin/pos-held-sales.php shows a manager before they decide
//   whether/how to recover it. Read-only; gated the same as the review
//   page itself, so only admin/manager can ever call it.
//
// Response: { ok, trail: [{action, label, details, created_at, username}] }
// ============================================================
require_once '../../includes/auth.php';
requireModule('held_sales_review');
header('Content-Type: application/json');

include '../../includes/db.php';
require_once '../../includes/pos_functions.php';
posBoot($conn);

$heldSaleId = (int)($_GET['id'] ?? 0);
$rows = posGetHeldSaleAuditTrail($conn, $heldSaleId);

// Human labels live here, not in the stored action string, so the
// audit log itself stays a stable machine-readable key.
$labels = [
    'held_sale_created'          => 'Created',
    'held_sale_resumed'          => 'Resumed',
    'held_sale_completed'        => 'Completed',
    'held_sale_cashier_logout'   => 'Cashier logged out',
    'held_sale_orphaned'         => 'Orphaned',
    'held_sale_reclaimed'        => 'Reclaimed by owner',
    'held_sale_stale'            => 'Marked ageing',
    'held_sale_expired'          => 'Expired',
    'held_sale_cancelled'        => 'Cancelled',
    'held_sale_voided'           => 'Voided',
    'held_sale_manager_recovery' => 'Recovered by manager',
    'held_sale_link_mismatch'    => 'Completion could not be linked',
];

$trail = array_map(function ($row) use ($labels) {
    return [
        'action'     => $row['action'],
        'label'      => $labels[$row['action']] ?? ucfirst(str_replace('_', ' ', $row['action'])),
        'details'    => $row['details'],
        'created_at' => $row['created_at'],
        'username'   => $row['username'] ?? 'System',
    ];
}, $rows);

echo json_encode(['ok' => true, 'trail' => $trail]);
