<?php
// ============================================================
// Accounting & Financial Ledger - shared logic
// ------------------------------------------------------------
// Posting journal entries, reading balances and the general
// ledger, recording expenses, and producing the daily close
// (Z-report).
//
// BALANCE RULE: no account stores a running total. Every balance
// on every screen is summed from acc_journal_lines, filtered to
// entries with status 'posted'. Reversing an entry writes an
// opposite entry rather than editing history, so the ledger is
// append-only and always reconciles with what it printed.
// ============================================================

require_once __DIR__ . '/inventory_functions.php';
require_once __DIR__ . '/catalog_schema.php';   // catalogDepartments()
require_once __DIR__ . '/accounting_schema.php';

/** Include at the top of every accounting page. */
function accountingBoot(mysqli $conn): void {
    ensureInventorySchema($conn);
    ensureCoreSchema($conn);
    ensureAccountingSchema($conn);
}

// ---------- Account lookup ---------------------------------------------

/** Resolve an account id from its code. Returns 0 when missing. */
function accAccountId(mysqli $conn, string $code): int {
    static $cache = [];
    if (isset($cache[$code])) { return $cache[$code]; }

    $stmt = $conn->prepare("SELECT id FROM acc_accounts WHERE code = ? AND deleted_at IS NULL LIMIT 1");
    if (!$stmt) { return 0; }
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $cache[$code] = $row ? (int)$row['id'] : 0;
    return $cache[$code];
}

/** True when an account id exists and has not been deleted. */
function accAccountExists(mysqli $conn, int $accountId): bool {
    static $cache = [];
    if (isset($cache[$accountId])) { return $cache[$accountId]; }

    $stmt = $conn->prepare("SELECT id FROM acc_accounts WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    if (!$stmt) { return false; }
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    $cache[$accountId] = $exists;
    return $exists;
}

/**
 * The account a payment method settles into.
 *
 * Card is treated as bank rather than mobile money: card takings land in
 * the bank account on settlement, not in the Lipa Namba float.
 */
function accPaymentAccountCode(string $paymentMethod): string {
    $codes = accSystemAccounts();
    switch ($paymentMethod) {
        case 'lipa_namba':
        case 'mobile':
            return $codes['mobile_money'];
        case 'bank':
        case 'bank_transfer':
        case 'card':
            return $codes['bank'];
        case 'credit':
            return $codes['receivable'];
        default:
            return $codes['cash'];
    }
}

/** Every account, optionally filtered by type, for dropdowns and lists. */
function accGetAccounts(mysqli $conn, string $type = '', bool $activeOnly = true): array {
    $sql = "SELECT * FROM acc_accounts WHERE deleted_at IS NULL";
    $params = [];
    $types = '';
    if ($activeOnly) { $sql .= " AND is_active = 1"; }
    if ($type !== '' && isset(accAccountTypes()[$type])) {
        $sql .= " AND type = ?";
        $params[] = $type;
        $types .= 's';
    }
    $sql .= " ORDER BY code ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) { return []; }
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// ---------- Entry numbers ----------------------------------------------

/** JE-YYYYMMDD-NNNN, sequential within the day. */
function accNextEntryNo(mysqli $conn): string {
    $prefix = 'JE-' . date('Ymd') . '-';
    $stmt = $conn->prepare("SELECT entry_no FROM acc_journal WHERE entry_no LIKE CONCAT(?, '%') ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s', $prefix);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = $row ? (int)substr($row['entry_no'], strlen($prefix)) + 1 : 1;
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/** EXP-YYYYMMDD-NNN, sequential within the day. */
function accNextExpenseRef(mysqli $conn): string {
    $prefix = 'EXP-' . date('Ymd') . '-';
    $stmt = $conn->prepare("SELECT reference_no FROM acc_expenses WHERE reference_no LIKE CONCAT(?, '%') ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s', $prefix);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = $row ? (int)substr($row['reference_no'], strlen($prefix)) + 1 : 1;
    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

// ---------- Posting -----------------------------------------------------

/**
 * Post a balanced journal entry.
 *
 * $lines: [['account' => '4000'|int, 'debit' => float, 'credit' => float, 'memo' => string], ...]
 *         'account' accepts either an account CODE (string) or an id (int).
 *
 * Debits must equal credits to the cent, or nothing is written.
 *
 * $ownTransaction: pass false when calling from inside a larger
 * transaction (e.g. POS checkout), so the sale and its ledger entry
 * commit or roll back together - a sale can never exist without its
 * accounting, and vice versa.
 *
 * Returns [ok(bool), messageOrEntryNo(string), journalId(int)].
 */
function accPostEntry(
    mysqli $conn,
    array $lines,
    string $memo,
    string $sourceType = 'manual',
    ?int $sourceId = null,
    ?int $userId = null,
    string $userName = '',
    string $department = 'general',
    ?string $entryDate = null,
    bool $ownTransaction = true
): array {
    // Resolve accounts and drop empty lines.
    $resolved = [];
    $totalDebit = 0.0;
    $totalCredit = 0.0;

    foreach ($lines as $l) {
        // An int is an account ID, a string is an account CODE. Both are
        // checked against the chart before use: passing an id that does
        // not exist would otherwise surface as an opaque foreign-key
        // failure when the lines are written.
        $account = $l['account'] ?? 0;
        if (is_int($account)) {
            $accountId = accAccountExists($conn, $account) ? $account : 0;
        } else {
            $accountId = accAccountId($conn, (string)$account);
        }
        if ($accountId <= 0) {
            return [false, 'Unknown account "' . (string)$account . '" - check the chart of accounts.', 0];
        }
        $debit  = round(max(0.0, (float)($l['debit'] ?? 0)), 2);
        $credit = round(max(0.0, (float)($l['credit'] ?? 0)), 2);
        if ($debit == 0.0 && $credit == 0.0) { continue; }
        if ($debit > 0 && $credit > 0) {
            return [false, 'A single ledger line cannot be both a debit and a credit.', 0];
        }

        $resolved[] = [
            'account_id' => $accountId,
            'debit'      => $debit,
            'credit'     => $credit,
            'memo'       => trim((string)($l['memo'] ?? '')) ?: null,
        ];
        $totalDebit  += $debit;
        $totalCredit += $credit;
    }

    if (count($resolved) < 2) {
        return [false, 'A journal entry needs at least two lines.', 0];
    }
    if (abs($totalDebit - $totalCredit) > 0.005) {
        return [false, 'Entry does not balance: debits ' . number_format($totalDebit, 2)
                     . ' vs credits ' . number_format($totalCredit, 2) . '.', 0];
    }

    $department = isset(catalogDepartments()[$department]) ? $department : 'general';
    $entryDate  = $entryDate ?: date('Y-m-d');

    if ($ownTransaction) { $conn->begin_transaction(); }
    try {
        $entryNo = accNextEntryNo($conn);

        $stmt = $conn->prepare(
            "INSERT INTO acc_journal
                (entry_no, entry_date, memo, source_type, source_id, department,
                 total_debit, total_credit, created_by, created_by_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $memoVal = trim($memo) ?: null;
        $stmt->bind_param('ssssisddis',
            $entryNo, $entryDate, $memoVal, $sourceType, $sourceId, $department,
            $totalDebit, $totalCredit, $userId, $userName);

        if (!$stmt->execute()) {
            // 1062 on uq_acc_journal_source means this source was already
            // posted - that is the idempotency guard doing its job.
            $duplicate = ($conn->errno === 1062);
            $stmt->close();
            throw new Exception($duplicate
                ? 'This transaction has already been posted to the ledger.'
                : 'Could not save the journal entry.');
        }
        $journalId = (int)$conn->insert_id;
        $stmt->close();

        $lineStmt = $conn->prepare(
            "INSERT INTO acc_journal_lines (journal_id, account_id, debit, credit, memo)
             VALUES (?, ?, ?, ?, ?)");
        foreach ($resolved as $r) {
            $lineStmt->bind_param('iidds', $journalId, $r['account_id'], $r['debit'], $r['credit'], $r['memo']);
            if (!$lineStmt->execute()) {
                $lineStmt->close();
                throw new Exception('Could not save the journal lines.');
            }
        }
        $lineStmt->close();

        if ($ownTransaction) { $conn->commit(); }
        return [true, $entryNo, $journalId];
    } catch (Throwable $e) {
        if ($ownTransaction) { $conn->rollback(); }
        return [false, $e->getMessage(), 0];
    }
}

/**
 * Reverse a posted entry by writing its mirror image. History is never
 * edited: the original stays, flagged 'reversed', and the new entry
 * carries reversal_of. Idempotent - reversing twice is refused.
 */
function accReverseEntry(mysqli $conn, int $journalId, ?int $userId, string $userName, string $reason = ''): array {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM acc_journal WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $journalId);
        $stmt->execute();
        $entry = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$entry) { throw new Exception('Journal entry not found.'); }
        if ($entry['status'] === 'reversed') { throw new Exception('This entry has already been reversed.'); }

        $lstmt = $conn->prepare("SELECT account_id, debit, credit, memo FROM acc_journal_lines WHERE journal_id = ?");
        $lstmt->bind_param('i', $journalId);
        $lstmt->execute();
        $lines = $lstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lstmt->close();

        // Swap every debit and credit.
        $mirror = [];
        foreach ($lines as $l) {
            $mirror[] = [
                'account' => (int)$l['account_id'],
                'debit'   => (float)$l['credit'],
                'credit'  => (float)$l['debit'],
                'memo'    => $l['memo'],
            ];
        }

        $memo = 'Reversal of ' . $entry['entry_no'] . ($reason !== '' ? ' - ' . $reason : '');
        [$ok, $msg, $newId] = accPostEntry(
            $conn, $mirror, $memo, 'reversal', $journalId, $userId, $userName,
            $entry['department'], date('Y-m-d'), false
        );
        if (!$ok) { throw new Exception($msg); }

        $upd = $conn->prepare("UPDATE acc_journal SET status = 'reversed', reversed_by = ? WHERE id = ?");
        $upd->bind_param('ii', $newId, $journalId);
        $upd->execute();
        $upd->close();

        $upd = $conn->prepare("UPDATE acc_journal SET reversal_of = ? WHERE id = ?");
        $upd->bind_param('ii', $journalId, $newId);
        $upd->execute();
        $upd->close();

        $conn->commit();
        return [true, 'Entry ' . $entry['entry_no'] . ' reversed as ' . $msg . '.'];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, $e->getMessage()];
    }
}

// ---------- Automatic postings from the till ---------------------------

/**
 * Post a completed POS sale to the ledger. Two economic events, one
 * entry each side:
 *
 *   Cash / Mobile Money      DR  total collected
 *      Sales Revenue             CR  net of tax
 *      Taxes Payable             CR  tax portion (only when tax is on)
 *
 *   Cost of Goods Sold       DR  cost of what left the shelf
 *      Inventory Asset           CR  same
 *
 * Both are written as ONE journal entry so the sale's full economic
 * effect is inseparable. Called from inside posCheckout's transaction
 * (ownTransaction = false), so a ledger failure rolls the sale back.
 */
function accPostSale(
    mysqli $conn,
    int $transactionId,
    string $receiptNo,
    float $total,
    float $taxAmount,
    $paymentMethod,
    float $costOfGoods,
    string $department,
    ?int $userId,
    string $userName,
    bool $ownTransaction = false
): array {
    $codes = accSystemAccounts();
    $net = round($total - $taxAmount, 2);

    // $paymentMethod is either a single method string (the original
    // signature) or, for a split tender, an array of
    // ['method' => …, 'amount' => …]. Each method debits its own asset
    // account, so a sale settled with cash + mobile produces two debit
    // lines that together equal the total.
    $lines = [];
    if (is_array($paymentMethod)) {
        // Several tenders may share an account (card and bank both land
        // in 1020); merge them so the entry has one line per account.
        $byAccount = [];
        foreach ($paymentMethod as $p) {
            $amount = round((float)($p['amount'] ?? 0), 2);
            if ($amount <= 0) { continue; }
            $code = accPaymentAccountCode((string)($p['method'] ?? 'cash'));
            $byAccount[$code] = ($byAccount[$code] ?? 0) + $amount;
        }
        foreach ($byAccount as $code => $amount) {
            // Cast back to string: PHP turns numeric array keys into
            // ints, and accPostEntry reads an int as an account ID
            // rather than an account CODE.
            $lines[] = ['account' => (string)$code, 'debit' => round($amount, 2),
                        'memo' => 'Takings for ' . $receiptNo];
        }
    } else {
        $lines[] = ['account' => accPaymentAccountCode((string)$paymentMethod), 'debit' => $total,
                    'memo' => 'Takings for ' . $receiptNo];
    }

    $lines[] = ['account' => $codes['sales'], 'credit' => $net,
                'memo' => 'Sales revenue ' . $receiptNo];

    if ($taxAmount > 0) {
        $lines[] = ['account' => '2100', 'credit' => $taxAmount, 'memo' => 'Tax on ' . $receiptNo];
    }

    // Cost of sales only when we actually know a cost. An item received
    // without a purchase price has average_cost 0, and posting a zero
    // COGS line would just clutter the ledger.
    if ($costOfGoods > 0) {
        $lines[] = ['account' => $codes['cogs'],      'debit'  => $costOfGoods, 'memo' => 'Cost of goods sold ' . $receiptNo];
        $lines[] = ['account' => $codes['inventory'], 'credit' => $costOfGoods, 'memo' => 'Stock released ' . $receiptNo];
    }

    return accPostEntry(
        $conn, $lines, 'POS sale ' . $receiptNo, 'pos_sale', $transactionId,
        $userId, $userName, $department, date('Y-m-d'), $ownTransaction
    );
}

/**
 * Post the reversal of a voided sale. Mirrors accPostSale so takings,
 * revenue, tax and stock value all come back. Uses its own source_type
 * so it never collides with the original sale's idempotency key.
 */
function accPostSaleVoid(
    mysqli $conn,
    int $transactionId,
    string $receiptNo,
    float $total,
    float $taxAmount,
    $paymentMethod,
    float $costOfGoods,
    string $department,
    ?int $userId,
    string $userName,
    bool $ownTransaction = false
): array {
    $codes = accSystemAccounts();
    $net = round($total - $taxAmount, 2);

    // Returns go to the contra-revenue account rather than debiting
    // Sales Revenue directly, so gross sales for the day stays honest
    // and the refund is visible as its own figure.
    $lines = [
        ['account' => $codes['sales_returns'], 'debit' => $net, 'memo' => 'Void of ' . $receiptNo],
    ];

    // Refund each tender back to the account it came from, mirroring
    // however the sale was originally settled.
    if (is_array($paymentMethod)) {
        $byAccount = [];
        foreach ($paymentMethod as $p) {
            $amount = round((float)($p['amount'] ?? 0), 2);
            if ($amount <= 0) { continue; }
            $code = accPaymentAccountCode((string)($p['method'] ?? 'cash'));
            $byAccount[$code] = ($byAccount[$code] ?? 0) + $amount;
        }
        foreach ($byAccount as $code => $amount) {
            // See accPostSale: numeric array keys become ints, and an int
            // is read as an account ID rather than a code.
            $lines[] = ['account' => (string)$code, 'credit' => round($amount, 2),
                        'memo' => 'Refund for ' . $receiptNo];
        }
    } else {
        $lines[] = ['account' => accPaymentAccountCode((string)$paymentMethod), 'credit' => $total,
                    'memo' => 'Refund for ' . $receiptNo];
    }

    if ($taxAmount > 0) {
        $lines[] = ['account' => '2100', 'debit' => $taxAmount, 'memo' => 'Tax reversed on ' . $receiptNo];
    }
    if ($costOfGoods > 0) {
        $lines[] = ['account' => $codes['inventory'], 'debit'  => $costOfGoods, 'memo' => 'Stock returned ' . $receiptNo];
        $lines[] = ['account' => $codes['cogs'],      'credit' => $costOfGoods, 'memo' => 'Cost reversed ' . $receiptNo];
    }

    return accPostEntry(
        $conn, $lines, 'Void of POS sale ' . $receiptNo, 'pos_void', $transactionId,
        $userId, $userName, $department, date('Y-m-d'), $ownTransaction
    );
}

// ---------- Automatic postings from purchasing -------------------------

/**
 * Post a goods receipt to the ledger:
 *
 *   Inventory Asset       DR  cost of goods received
 *      Accounts Payable       CR  same
 *
 * Stock is NOT an expense when it arrives - it is an asset that has
 * changed form (money owed becomes goods on the shelf). It only becomes
 * an expense when it sells, which is what accPostSale's COGS line does.
 * Posting it this way is what keeps Inventory Asset positive instead of
 * drifting negative as sales credit it.
 *
 * Called from inside the receiving transaction (ownTransaction = false)
 * so stock and books commit together.
 */
function accPostGoodsReceipt(
    mysqli $conn,
    int $receiptId,
    string $reference,
    float $value,
    ?int $userId,
    string $userName,
    bool $ownTransaction = false
): array {
    if ($value <= 0) {
        // Receiving something with no cost price is legitimate (a free
        // sample, a replacement). There is simply nothing to post.
        return [true, 'no value to post', 0];
    }

    $codes = accSystemAccounts();
    return accPostEntry(
        $conn,
        [
            ['account' => $codes['inventory'], 'debit'  => $value, 'memo' => 'Goods received ' . $reference],
            ['account' => $codes['payable'],   'credit' => $value, 'memo' => 'Owed to supplier ' . $reference],
        ],
        'Goods received ' . $reference, 'po_receipt', $receiptId,
        $userId, $userName, 'general', date('Y-m-d'), $ownTransaction
    );
}

/**
 * Post a supplier payment to the ledger:
 *
 *   Accounts Payable      DR  amount paid
 *      Cash / Mobile / Bank   CR  same
 *
 * This settles the liability created when the goods were received. It
 * is deliberately NOT an expense - the cost already entered the books
 * as inventory.
 */
function accPostSupplierPayment(
    mysqli $conn,
    int $paymentId,
    string $reference,
    float $amount,
    int $paidFromAccountId,
    ?int $userId,
    string $userName,
    bool $ownTransaction = false
): array {
    if ($amount <= 0) { return [false, 'Payment amount must be greater than zero.', 0]; }
    if ($paidFromAccountId <= 0) { return [false, 'Choose which account paid the supplier.', 0]; }

    $codes = accSystemAccounts();
    return accPostEntry(
        $conn,
        [
            ['account' => $codes['payable'],    'debit'  => $amount, 'memo' => 'Payment for ' . $reference],
            ['account' => $paidFromAccountId,   'credit' => $amount, 'memo' => 'Paid supplier ' . $reference],
        ],
        'Supplier payment ' . $reference, 'po_payment', $paymentId,
        $userId, $userName, 'general', date('Y-m-d'), $ownTransaction
    );
}

// ---------- Expenses ----------------------------------------------------

/**
 * Record an expense and post it to the ledger in one transaction:
 *   Expense account      DR  amount
 *      Cash / Mobile / Payable   CR  amount
 * Returns [ok, message].
 */
function accRecordExpense(mysqli $conn, array $data, ?int $userId, string $userName): array {
    $accountId     = (int)($data['account_id'] ?? 0);
    $paidFromId    = (int)($data['paid_from_account_id'] ?? 0);
    $amount        = round((float)($data['amount'] ?? 0), 2);
    $expenseDate   = $data['expense_date'] ?? date('Y-m-d');
    $payee         = trim((string)($data['payee'] ?? '')) ?: null;
    $description   = trim((string)($data['description'] ?? '')) ?: null;
    $department    = isset(catalogDepartments()[$data['department'] ?? '']) ? $data['department'] : 'general';

    if ($accountId <= 0)  { return [false, 'Choose the expense account.']; }
    if ($paidFromId <= 0) { return [false, 'Choose which account paid for it.']; }
    if ($amount <= 0)     { return [false, 'Enter an amount greater than zero.']; }
    if (!strtotime($expenseDate)) { return [false, 'Enter a valid date.']; }

    $conn->begin_transaction();
    try {
        $ref = accNextExpenseRef($conn);

        $stmt = $conn->prepare(
            "INSERT INTO acc_expenses
                (reference_no, expense_date, account_id, paid_from_account_id, department,
                 payee, description, amount, recorded_by, recorded_by_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        // ref(s) date(s) account(i) paidFrom(i) dept(s) payee(s)
        // description(s) amount(d) user(i) userName(s)
        $stmt->bind_param('ssiisssdis',
            $ref, $expenseDate, $accountId, $paidFromId, $department,
            $payee, $description, $amount, $userId, $userName);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Could not save the expense.');
        }
        $expenseId = (int)$conn->insert_id;
        $stmt->close();

        $memo = 'Expense ' . $ref . ($payee ? ' - ' . $payee : '');
        [$ok, $msg, $journalId] = accPostEntry(
            $conn,
            [
                ['account' => $accountId,  'debit'  => $amount, 'memo' => $description ?: $memo],
                ['account' => $paidFromId, 'credit' => $amount, 'memo' => $memo],
            ],
            $memo, 'expense', $expenseId, $userId, $userName, $department, $expenseDate, false
        );
        if (!$ok) { throw new Exception($msg); }

        $upd = $conn->prepare("UPDATE acc_expenses SET journal_id = ? WHERE id = ?");
        $upd->bind_param('ii', $journalId, $expenseId);
        $upd->execute();
        $upd->close();

        $conn->commit();
        return [true, 'Expense ' . $ref . ' recorded and posted (' . $msg . ').'];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, $e->getMessage()];
    }
}

// ---------- Balances & reports -----------------------------------------

/**
 * Balance of one account over a date range, as a signed amount in the
 * account's NORMAL direction (so an asset with more debits than credits
 * reads positive, and so does a revenue account with more credits).
 */
function accAccountBalance(mysqli $conn, int $accountId, string $from = '', string $to = ''): float {
    $sql = "SELECT a.type,
                   COALESCE(SUM(l.debit), 0)  AS debits,
                   COALESCE(SUM(l.credit), 0) AS credits
            FROM acc_accounts a
            LEFT JOIN acc_journal_lines l ON l.account_id = a.id
            LEFT JOIN acc_journal j ON j.id = l.journal_id AND j.status = 'posted'
            WHERE a.id = ? AND (j.id IS NOT NULL OR l.id IS NULL)";
    $params = [$accountId];
    $types = 'i';

    if ($from !== '') { $sql .= " AND j.entry_date >= ?"; $params[] = $from; $types .= 's'; }
    if ($to !== '')   { $sql .= " AND j.entry_date <= ?"; $params[] = $to;   $types .= 's'; }
    $sql .= " GROUP BY a.id, a.type";

    $stmt = $conn->prepare($sql);
    if (!$stmt) { return 0.0; }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { return 0.0; }

    $net = (float)$row['debits'] - (float)$row['credits'];
    $normal = accAccountTypes()[$row['type']]['normal'] ?? 'debit';
    return $normal === 'debit' ? round($net, 2) : round(-$net, 2);
}

/**
 * Trial balance: every account with its debit and credit totals.
 * The two column totals must match - that is the whole point of it.
 */
/**
 * Balances for EVERY account in one query.
 *
 * accAccountBalance() answers for a single account; calling it in a loop
 * costs one query per account (29 accounts = 29 round trips on the chart
 * of accounts). This returns the same numbers, computed the same way and
 * with the same sign convention, as [account_id => balance].
 *
 * The arithmetic is deliberately identical to accAccountBalance(): debit
 * less credit, flipped for the account types whose normal balance is a
 * credit. Reporting must never invent its own version of a balance.
 */
function accAllAccountBalances(mysqli $conn, string $from = '', string $to = ''): array {
    // The WHERE clause mirrors accAccountBalance() exactly, including the
    // "(j.id IS NOT NULL OR l.id IS NULL)" guard that drops lines whose
    // journal is reversed or otherwise not posted. Without it this would
    // quietly report different balances from the single-account version.
    $sql = "SELECT a.id, a.type,
                   COALESCE(SUM(l.debit), 0)  AS debits,
                   COALESCE(SUM(l.credit), 0) AS credits
              FROM acc_accounts a
              LEFT JOIN acc_journal_lines l ON l.account_id = a.id
              LEFT JOIN acc_journal j ON j.id = l.journal_id AND j.status = 'posted'
             WHERE (j.id IS NOT NULL OR l.id IS NULL)";
    $params = []; $types = '';
    if ($from !== '') { $sql .= " AND j.entry_date >= ?"; $params[] = $from; $types .= 's'; }
    if ($to   !== '') { $sql .= " AND j.entry_date <= ?"; $params[] = $to;   $types .= 's'; }
    $sql .= " GROUP BY a.id, a.type";

    $stmt = $conn->prepare($sql);
    if (!$stmt) { return []; }
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Sign convention read from the same source accAccountBalance() uses,
    // so the two can never drift apart.
    $types_meta = accAccountTypes();
    $out = [];
    foreach ($rows as $r) {
        $net    = (float)$r['debits'] - (float)$r['credits'];
        $normal = $types_meta[$r['type']]['normal'] ?? 'debit';
        $out[(int)$r['id']] = $normal === 'debit' ? round($net, 2) : round(-$net, 2);
    }
    return $out;
}

function accTrialBalance(mysqli $conn, string $from = '', string $to = ''): array {
    $sql = "SELECT a.id, a.code, a.name, a.type,
                   COALESCE(SUM(l.debit), 0)  AS debits,
                   COALESCE(SUM(l.credit), 0) AS credits
            FROM acc_accounts a
            LEFT JOIN acc_journal_lines l ON l.account_id = a.id
            LEFT JOIN acc_journal j ON j.id = l.journal_id AND j.status = 'posted'";
    $where = ["a.deleted_at IS NULL"];
    $params = [];
    $types = '';

    if ($from !== '') { $where[] = "(j.entry_date >= ? OR j.id IS NULL)"; $params[] = $from; $types .= 's'; }
    if ($to !== '')   { $where[] = "(j.entry_date <= ? OR j.id IS NULL)"; $params[] = $to;   $types .= 's'; }

    $sql .= " WHERE " . implode(' AND ', $where) . " GROUP BY a.id ORDER BY a.code";

    $stmt = $conn->prepare($sql);
    if (!$stmt) { return []; }
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Ledger lines for one account, newest first, with a running balance. */
function accLedgerLines(mysqli $conn, int $accountId, string $from = '', string $to = '', int $limit = 300): array {
    $sql = "SELECT j.id AS journal_id, j.entry_no, j.entry_date, j.memo AS entry_memo,
                   j.source_type, j.status, j.created_by_name,
                   l.debit, l.credit, l.memo AS line_memo
            FROM acc_journal_lines l
            JOIN acc_journal j ON j.id = l.journal_id
            WHERE l.account_id = ? AND j.status = 'posted'";
    $params = [$accountId];
    $types = 'i';

    if ($from !== '') { $sql .= " AND j.entry_date >= ?"; $params[] = $from; $types .= 's'; }
    if ($to !== '')   { $sql .= " AND j.entry_date <= ?"; $params[] = $to;   $types .= 's'; }

    $sql .= " ORDER BY j.entry_date ASC, j.id ASC, l.id ASC LIMIT " . max(1, min($limit, 2000));

    $stmt = $conn->prepare($sql);
    if (!$stmt) { return []; }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Running balance in the account's normal direction.
    $type = '';
    $t = $conn->prepare("SELECT type FROM acc_accounts WHERE id = ?");
    if ($t) {
        $t->bind_param('i', $accountId);
        $t->execute();
        $type = $t->get_result()->fetch_assoc()['type'] ?? '';
        $t->close();
    }
    $normal = accAccountTypes()[$type]['normal'] ?? 'debit';

    $running = 0.0;
    foreach ($rows as &$r) {
        $delta = (float)$r['debit'] - (float)$r['credit'];
        $running += ($normal === 'debit') ? $delta : -$delta;
        $r['balance'] = round($running, 2);
    }
    unset($r);

    return $rows;
}

/** Journal entries for the journal screen. */
function accGetJournal(mysqli $conn, string $from = '', string $to = '', string $sourceType = '', int $limit = 200): array {
    $sql = "SELECT * FROM acc_journal WHERE 1=1";
    $params = [];
    $types = '';

    if ($from !== '')       { $sql .= " AND entry_date >= ?";  $params[] = $from;       $types .= 's'; }
    if ($to !== '')         { $sql .= " AND entry_date <= ?";  $params[] = $to;         $types .= 's'; }
    if ($sourceType !== '') { $sql .= " AND source_type = ?";  $params[] = $sourceType; $types .= 's'; }

    $sql .= " ORDER BY entry_date DESC, id DESC LIMIT " . max(1, min($limit, 1000));

    $stmt = $conn->prepare($sql);
    if (!$stmt) { return []; }
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** The lines of one journal entry, with account code and name. */
function accGetEntryLines(mysqli $conn, int $journalId): array {
    $stmt = $conn->prepare(
        "SELECT l.*, a.code, a.name, a.type
         FROM acc_journal_lines l
         JOIN acc_accounts a ON a.id = l.account_id
         WHERE l.journal_id = ? ORDER BY l.id");
    if (!$stmt) { return []; }
    $stmt->bind_param('i', $journalId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Expenses list for the expenses screen. */
function accGetExpenses(mysqli $conn, string $from = '', string $to = '', int $accountId = 0, int $limit = 300): array {
    $sql = "SELECT e.*, a.code AS account_code, a.name AS account_name,
                   p.code AS paid_from_code, p.name AS paid_from_name
            FROM acc_expenses e
            JOIN acc_accounts a ON a.id = e.account_id
            JOIN acc_accounts p ON p.id = e.paid_from_account_id
            WHERE e.deleted_at IS NULL";
    $params = [];
    $types = '';

    if ($from !== '')     { $sql .= " AND e.expense_date >= ?"; $params[] = $from; $types .= 's'; }
    if ($to !== '')       { $sql .= " AND e.expense_date <= ?"; $params[] = $to;   $types .= 's'; }
    if ($accountId > 0)   { $sql .= " AND e.account_id = ?";    $params[] = $accountId; $types .= 'i'; }

    $sql .= " ORDER BY e.expense_date DESC, e.id DESC LIMIT " . max(1, min($limit, 1000));

    $stmt = $conn->prepare($sql);
    if (!$stmt) { return []; }
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// ---------- Daily close / Z-report --------------------------------------

/**
 * Live figures for one business day, read straight from the till and
 * the ledger. This is what the Z-report shows BEFORE the day is closed;
 * afterwards the stored snapshot in acc_daily_close is shown instead.
 */
function accDayFigures(mysqli $conn, string $date): array {
    $figures = [
        'sales_count' => 0, 'voided_count' => 0,
        'gross_sales' => 0.0, 'discounts' => 0.0, 'tax_collected' => 0.0,
        'net_sales' => 0.0, 'cost_of_sales' => 0.0, 'gross_profit' => 0.0,
        'cash_sales' => 0.0, 'mobile_sales' => 0.0, 'bank_sales' => 0.0, 'card_sales' => 0.0,
        'supermarket_sales' => 0.0, 'stationery_sales' => 0.0, 'general_sales' => 0.0,
        'cash_expenses' => 0.0,
        // Takings per department, keyed by department key. This is the
        // real breakdown - it has as many entries as the shop has
        // departments. The three *_sales keys above are the original
        // fixed columns, kept so acc_daily_close's existing columns and
        // any older close row still line up.
        'department_sales' => [],
    ];

    // --- Sale headers ---------------------------------------------------
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS sales_count,
                COALESCE(SUM(subtotal), 0)   AS gross_sales,
                COALESCE(SUM(discount), 0)   AS discounts,
                COALESCE(SUM(tax_amount), 0) AS tax_collected,
                COALESCE(SUM(total), 0)      AS net_sales
         FROM sales_transactions
         WHERE status = 'completed' AND DATE(created_at) = ?");
    if ($stmt) {
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) { $figures = array_merge($figures, array_map('floatval', $row)); }
        $figures['sales_count'] = (int)($row['sales_count'] ?? 0);
    }

    // --- Payment split, from the tender rows -----------------------------
    // Read per-tender rather than per-sale, so a sale settled with cash
    // AND mobile contributes to both columns instead of landing wholly
    // in whichever method happened to be recorded on the header.
    $stmt = $conn->prepare(
        "SELECT p.method, COALESCE(SUM(p.amount), 0) AS amount
         FROM sales_payments p
         JOIN sales_transactions t ON t.id = p.transaction_id
         WHERE t.status = 'completed' AND DATE(t.created_at) = ?
         GROUP BY p.method");
    if ($stmt) {
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $map = ['cash' => 'cash_sales', 'lipa_namba' => 'mobile_sales',
                'mobile' => 'mobile_sales', 'bank' => 'bank_sales', 'card' => 'card_sales'];
        foreach ($rows as $r) {
            $key = $map[$r['method']] ?? null;
            if ($key) { $figures[$key] += (float)$r['amount']; }
        }
    }

    // Change handed back comes out of the drawer, so the cash actually
    // held is what was tendered in cash MINUS the change given. Without
    // this the expected-cash figure would be overstated on every sale
    // where the customer paid with a note.
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(change_due), 0) AS c FROM sales_transactions
         WHERE status = 'completed' AND DATE(created_at) = ?");
    if ($stmt) {
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $figures['cash_sales'] -= (float)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
    }
    $figures['cash_sales'] = round($figures['cash_sales'], 2);

    // --- Voided sales ---------------------------------------------------
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM sales_transactions WHERE status = 'voided' AND DATE(created_at) = ?");
    if ($stmt) {
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $figures['voided_count'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
    }

    // --- Cost of sales -------------------------------------------------
    // Left exactly as it was: one aggregate over the day's lines.
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(li.quantity * li.unit_cost), 0) AS cost_of_sales
         FROM sales_transaction_items li
         JOIN sales_transactions t ON t.id = li.transaction_id
         WHERE t.status = 'completed' AND DATE(t.created_at) = ?");
    if ($stmt) {
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) { $figures['cost_of_sales'] = (float)$row['cost_of_sales']; }
    }

    // --- Departmental split --------------------------------------------
    // Grouped rather than three fixed CASE WHENs, because a shop defines
    // its own departments - a hardware shop's takings are Plumbing and
    // Electrical, not "supermarket". Department comes from the item, so a
    // sale spanning two departments is split line by line rather than
    // dumped into one.
    $stmt = $conn->prepare(
        "SELECT COALESCE(NULLIF(i.department, ''), li.department, ?) AS dept,
                COALESCE(SUM(li.line_total), 0) AS amount
         FROM sales_transaction_items li
         JOIN sales_transactions t ON t.id = li.transaction_id
         LEFT JOIN inv_items i ON i.id = li.item_id
         WHERE t.status = 'completed' AND DATE(t.created_at) = ?
         GROUP BY dept");
    if ($stmt) {
        $fallback = catalogDefaultDepartmentKey();
        $stmt->bind_param('ss', $fallback, $date);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $r) {
            $key = (string)$r['dept'];
            $figures['department_sales'][$key] = round((float)$r['amount'], 2);
            // Keep the three original keys populated for the stored
            // snapshot's original columns.
            if (isset($figures[$key . '_sales'])) { $figures[$key . '_sales'] = (float)$r['amount']; }
        }
    }

    // --- Cash paid out of the drawer today ------------------------------
    $cashAccount = accAccountId($conn, accSystemAccounts()['cash']);
    if ($cashAccount > 0) {
        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(amount), 0) AS c FROM acc_expenses
             WHERE deleted_at IS NULL AND expense_date = ? AND paid_from_account_id = ?");
        if ($stmt) {
            $stmt->bind_param('si', $date, $cashAccount);
            $stmt->execute();
            $figures['cash_expenses'] = (float)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
        }
    }

    $figures['gross_profit'] = round($figures['net_sales'] - $figures['tax_collected'] - $figures['cost_of_sales'], 2);

    $openingFloat = (float)getInvSetting($conn, 'acc_opening_float', '0');
    $figures['opening_float']  = $openingFloat;
    $figures['expected_cash']  = round($openingFloat + $figures['cash_sales'] - $figures['cash_expenses'], 2);

    return $figures;
}

/** The stored close for a date, or null when the day is still open. */
function accGetClose(mysqli $conn, string $date): ?array {
    $stmt = $conn->prepare("SELECT * FROM acc_daily_close WHERE close_date = ? LIMIT 1");
    if (!$stmt) { return null; }
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * The departmental breakdown of a stored close, as `key => amount`.
 *
 * Closes recorded before departments became configurable have no child
 * rows, so their three original columns are read instead - an old
 * Z-report keeps showing exactly the figures it was closed with.
 */
function accCloseDepartments(mysqli $conn, array $close): array {
    $out     = [];
    $closeId = (int)($close['id'] ?? 0);

    if ($closeId > 0) {
        $stmt = @$conn->prepare("SELECT dept_key, dept_name, sales
                                 FROM acc_daily_close_departments
                                 WHERE close_id = ? ORDER BY dept_name");
        if ($stmt) {
            $stmt->bind_param('i', $closeId);
            if ($stmt->execute()) {
                foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                    $out[(string)$r['dept_key']] = [
                        'label'  => (string)$r['dept_name'],
                        'amount' => (float)$r['sales'],
                    ];
                }
            }
            $stmt->close();
        }
    }
    if ($out) { return $out; }

    // Pre-v4 close: fall back to the original columns.
    foreach (['supermarket', 'stationery', 'general'] as $key) {
        if (!isset($close[$key . '_sales'])) { continue; }
        $amount = (float)$close[$key . '_sales'];
        if (abs($amount) < 0.005) { continue; }
        $out[$key] = ['label' => catalogDepartmentLabel($key), 'amount' => $amount];
    }
    return $out;
}

/**
 * Close a business day: snapshot the figures, record the counted cash
 * and the variance. Refuses to close a day twice, and refuses to close
 * a day that hasn't happened yet.
 *
 * A variance (over/short) is posted to the ledger so the cash account
 * matches what is physically in the drawer - an unexplained shortfall
 * is a real expense, not a rounding difference to be ignored.
 */
function accCloseDay(mysqli $conn, string $date, float $countedCash, string $notes, ?int $userId, string $userName): array {
    if (!strtotime($date))          { return [false, 'Invalid date.']; }
    if ($date > date('Y-m-d'))      { return [false, 'You cannot close a day that has not happened yet.']; }
    if (accGetClose($conn, $date))  { return [false, 'That day has already been closed.']; }

    $f = accDayFigures($conn, $date);
    $variance = round($countedCash - $f['expected_cash'], 2);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "INSERT INTO acc_daily_close
                (close_date, opening_float, sales_count, voided_count, gross_sales, discounts,
                 tax_collected, net_sales, cost_of_sales, gross_profit, cash_sales, mobile_sales,
                 bank_sales, card_sales, supermarket_sales, stationery_sales, general_sales, cash_expenses,
                 expected_cash, counted_cash, variance, notes, closed_by, closed_by_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $notesVal = trim($notes) ?: null;
        // date(s) float(d) counts(ii) then 17 decimals, notes(s) user(i) name(s) = 24
        $stmt->bind_param('sdiidddddddddddddddddsis',
            $date, $f['opening_float'], $f['sales_count'], $f['voided_count'],
            $f['gross_sales'], $f['discounts'], $f['tax_collected'], $f['net_sales'],
            $f['cost_of_sales'], $f['gross_profit'], $f['cash_sales'], $f['mobile_sales'],
            $f['bank_sales'], $f['card_sales'], $f['supermarket_sales'], $f['stationery_sales'],
            $f['general_sales'], $f['cash_expenses'], $f['expected_cash'], $countedCash, $variance,
            $notesVal, $userId, $userName);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Could not save the daily close.');
        }
        $closeId = (int)$conn->insert_id;
        $stmt->close();

        // One row per department that took money today. The parent's
        // three fixed columns are written above for continuity; this is
        // what a shop with five departments - or one - actually reports
        // from. Inside the same transaction as the close itself, so a
        // close can never exist without its breakdown.
        if (!empty($f['department_sales'])) {
            $dStmt = $conn->prepare("INSERT INTO acc_daily_close_departments
                                        (close_id, dept_key, dept_name, sales)
                                     VALUES (?, ?, ?, ?)");
            if ($dStmt) {
                foreach ($f['department_sales'] as $deptKey => $amount) {
                    $deptKey  = (string)$deptKey;
                    $deptName = catalogDepartmentLabel($deptKey);
                    $amountF  = (float)$amount;
                    $dStmt->bind_param('issd', $closeId, $deptKey, $deptName, $amountF);
                    if (!$dStmt->execute()) {
                        $dStmt->close();
                        throw new Exception('Could not save the departmental breakdown.');
                    }
                }
                $dStmt->close();
            }
        }

        // Post the over/short so the books agree with the drawer.
        if (abs($variance) >= 0.01) {
            $codes = accSystemAccounts();
            $lines = $variance < 0
                // Short: cash is missing - recognise the loss.
                ? [
                    ['account' => $codes['shrinkage'], 'debit'  => abs($variance), 'memo' => 'Cash short on ' . $date],
                    ['account' => $codes['cash'],      'credit' => abs($variance), 'memo' => 'Till count ' . $date],
                  ]
                // Over: more cash than expected - other income.
                : [
                    ['account' => $codes['cash'], 'debit'  => $variance, 'memo' => 'Till count ' . $date],
                    ['account' => '4900',         'credit' => $variance, 'memo' => 'Cash over on ' . $date],
                  ];

            [$ok, $msg] = accPostEntry(
                $conn, $lines, 'Cash variance for ' . $date, 'daily_close', $closeId,
                $userId, $userName, 'general', $date, false
            );
            if (!$ok) { throw new Exception($msg); }
        }

        $conn->commit();
        $verdict = abs($variance) < 0.01
            ? 'drawer balanced exactly'
            : ($variance < 0 ? 'short by ' : 'over by ') . number_format(abs($variance), 2);
        return [true, 'Day ' . $date . ' closed - ' . $verdict . '.'];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, $e->getMessage()];
    }
}

/** Recent closes for the Z-report history table. */
function accRecentCloses(mysqli $conn, int $limit = 30): array {
    $res = @$conn->query("SELECT * FROM acc_daily_close ORDER BY close_date DESC LIMIT " . max(1, min($limit, 365)));
    return ($res instanceof mysqli_result) ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Profit & loss for a period, straight from the ledger.
 * Returns ['revenue' => [...], 'expenses' => [...], totals].
 */
function accProfitAndLoss(mysqli $conn, string $from, string $to): array {
    $out = ['revenue' => [], 'expenses' => [], 'total_revenue' => 0.0, 'total_expenses' => 0.0, 'net_profit' => 0.0];

    $stmt = $conn->prepare(
        "SELECT a.id, a.code, a.name, a.type,
                COALESCE(SUM(l.credit), 0) - COALESCE(SUM(l.debit), 0) AS credit_net,
                COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS debit_net
         FROM acc_accounts a
         JOIN acc_journal_lines l ON l.account_id = a.id
         JOIN acc_journal j ON j.id = l.journal_id AND j.status = 'posted'
         WHERE a.type IN ('revenue','expense') AND a.deleted_at IS NULL
           AND j.entry_date BETWEEN ? AND ?
         GROUP BY a.id ORDER BY a.code");
    if (!$stmt) { return $out; }
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $r) {
        if ($r['type'] === 'revenue') {
            $amount = round((float)$r['credit_net'], 2);
            $out['revenue'][] = $r + ['amount' => $amount];
            $out['total_revenue'] += $amount;
        } else {
            $amount = round((float)$r['debit_net'], 2);
            $out['expenses'][] = $r + ['amount' => $amount];
            $out['total_expenses'] += $amount;
        }
    }

    $out['total_revenue']  = round($out['total_revenue'], 2);
    $out['total_expenses'] = round($out['total_expenses'], 2);
    $out['net_profit']     = round($out['total_revenue'] - $out['total_expenses'], 2);
    return $out;
}
