<?php
// admin/data_health.php — Data integrity checks + rebuild tools
// Refactored to enforce member_code as the single source of truth.
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();
if ($current_user['role'] !== 'admin') {
    header('Location: dashboard.php'); exit;
}

$db = Database::getInstance();
$message = '';
$error   = '';

// ---- Handle POST: rebuild balances ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'rebuild_balances') {
        try {
            $db->getConnection()->begin_transaction();
            $db->execute("DELETE FROM member_balances");

            // Full rebuild: all balance columns written in one pass.
            // Uses payment_due_day setting (default 15th) for next_due_date.
            $db->execute("
                INSERT INTO member_balances
                    (member_code, total_paid, total_due, current_balance,
                     last_payment_date, next_due_date, updated_at)
                SELECT
                    m.member_code,
                    COALESCE(SUM(CASE WHEN p.payment_status='confirmed' THEN p.amount ELSE 0 END), 0) AS total_paid,
                    ((PERIOD_DIFF(DATE_FORMAT(CURDATE(),'%Y%m'), DATE_FORMAT(m.date_joined,'%Y%m')) + 1)
                     * m.monthly_contribution) AS total_due,
                    ((PERIOD_DIFF(DATE_FORMAT(CURDATE(),'%Y%m'), DATE_FORMAT(m.date_joined,'%Y%m')) + 1)
                     * m.monthly_contribution)
                    - COALESCE(SUM(CASE WHEN p.payment_status='confirmed' THEN p.amount ELSE 0 END), 0) AS current_balance,
                    MAX(CASE WHEN p.payment_status='confirmed' THEN p.payment_date END) AS last_payment_date,
                    (
                        SELECT STR_TO_DATE(
                            CONCAT(
                                DATE_FORMAT(
                                    DATE_ADD(
                                        MAX(COALESCE(pp.period_month, DATE_FORMAT(pp.payment_date, '%Y-%m'))),
                                        INTERVAL 1 MONTH
                                    ),
                                    '%Y-%m'
                                ),
                                '-',
                                LPAD(COALESCE(
                                    (SELECT CAST(setting_value AS UNSIGNED) FROM settings WHERE setting_key='payment_due_day' LIMIT 1),
                                    15
                                ), 2, '0')
                            ),
                            '%Y-%m-%d'
                        )
                        FROM payments pp
                        WHERE pp.member_code = m.member_code AND pp.payment_status = 'confirmed'
                    ) AS next_due_date,
                    NOW()
                FROM members m
                LEFT JOIN payments p ON p.member_code = m.member_code
                GROUP BY m.member_code, m.date_joined, m.monthly_contribution
            ");

            $db->getConnection()->commit();
            Security::logEvent('BALANCES_REBUILT', 'Admin rebuilt member_balances (all columns) from confirmed payments');
            $message = 'Balances rebuilt successfully. All columns (total_paid, total_due, current_balance, next_due_date) are now in sync.';
        } catch (Throwable $e) {
            $db->getConnection()->rollback();
            $error = 'Rebuild failed: ' . $e->getMessage();
        }
    }

    if ($action === 'backfill_due_dates') {
        try {
            $db->getConnection()->begin_transaction();
            $db->execute("
                UPDATE member_balances mb
                JOIN members m ON m.member_code = mb.member_code
                SET mb.next_due_date = (
                    SELECT STR_TO_DATE(
                        CONCAT(
                            DATE_FORMAT(
                                DATE_ADD(
                                    MAX(COALESCE(p.period_month, DATE_FORMAT(p.payment_date, '%Y-%m'))),
                                    INTERVAL 1 MONTH
                                ),
                                '%Y-%m'
                            ),
                            '-',
                            LPAD(COALESCE(
                                (SELECT CAST(setting_value AS UNSIGNED) FROM settings WHERE setting_key='payment_due_day' LIMIT 1),
                                15
                            ), 2, '0')
                        ),
                        '%Y-%m-%d'
                    )
                    FROM payments p
                    WHERE p.member_code = mb.member_code AND p.payment_status = 'confirmed'
                )
                WHERE mb.next_due_date IS NULL
            ");
            $db->getConnection()->commit();
            Security::logEvent('DUE_DATES_BACKFILLED', 'Admin backfilled next_due_date');
            $message = 'Next-due dates backfilled.';
        } catch (Throwable $e) {
            $db->getConnection()->rollback();
            $error = 'Backfill failed: ' . $e->getMessage();
        }
    }
}

// ---- Read-only checks ----

// 1. Orphan payments (member_code is NULL — should never happen after the fix)
$orphans = $db->getAll("
    SELECT payment_id, member_id, amount, payment_date, receipt_number
    FROM payments
    WHERE member_code IS NULL
    ORDER BY payment_date DESC
    LIMIT 100
");
$orphan_count = count($orphans);
$orphan_total = array_sum(array_map(fn($r) => (float)$r['amount'], $orphans));

// 2. Identity drift (numeric codes only — alphanumeric codes are excluded since they're legacy)
$drift_rows = $db->getAll("
    SELECT payment_id, member_id, member_code, amount, payment_date
    FROM payments
    WHERE member_code REGEXP '^[0-9]+\$'
      AND CAST(member_code AS UNSIGNED) <> member_id
    ORDER BY payment_date DESC
    LIMIT 100
");
$drift_count = count($drift_rows);

// 3. Legacy alphanumeric member codes (should be migrated to numeric)
$legacy_rows = $db->getAll("
    SELECT member_code, first_name, last_name, status
    FROM members
    WHERE member_code NOT REGEXP '^[0-9]+\$'
    ORDER BY member_code
    LIMIT 100
");
$legacy_count = count($legacy_rows);

// 4. Stale cached balances — checks total_paid AND current_balance
$stale_count = (int)($db->getSingle("
    SELECT COUNT(*) AS c FROM (
        SELECT
            m.member_code,
            mb.total_paid AS cached_paid,
            mb.current_balance AS cached_balance,
            COALESCE(SUM(CASE WHEN p.payment_status='confirmed' THEN p.amount ELSE 0 END), 0) AS live_paid,
            ((PERIOD_DIFF(DATE_FORMAT(CURDATE(),'%Y%m'), DATE_FORMAT(m.date_joined,'%Y%m')) + 1)
             * m.monthly_contribution) AS live_due
        FROM members m
        LEFT JOIN member_balances mb ON mb.member_code = m.member_code
        LEFT JOIN payments p ON p.member_code = m.member_code
        GROUP BY m.member_code, m.date_joined, m.monthly_contribution, mb.total_paid, mb.current_balance
        HAVING ABS(COALESCE(mb.total_paid, 0) - live_paid) > 0.01
            OR ABS(COALESCE(mb.current_balance, 0) - (live_due - live_paid)) > 0.01
    ) AS x
")['c'] ?? 0);

// 5. Missing next_due_date
$missing_due_count = (int)($db->getSingle("
    SELECT COUNT(*) AS c FROM member_balances WHERE next_due_date IS NULL
")['c'] ?? 0);

// 6. Totals
$total_payments = (int)($db->getSingle("SELECT COUNT(*) AS c FROM payments")['c'] ?? 0);
$total_confirmed = (int)($db->getSingle("SELECT COUNT(*) AS c FROM payments WHERE payment_status = 'confirmed'")['c'] ?? 0);
$total_pending = (int)($db->getSingle("SELECT COUNT(*) AS c FROM payments WHERE payment_status = 'pending'")['c'] ?? 0);

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Health — NHGL Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
body{background:#f0f2f5;font-family:'Segoe UI',Tahoma,sans-serif;padding:0;margin:0}
.navbar{background:#fff;box-shadow:0 2px 4px rgba(0,0,0,.05);padding:.7rem 1.2rem;
    display:flex;justify-content:space-between;align-items:center}
.navbar-brand{font-weight:700;color:#1c1c1d;font-size:1.15rem}
.container{max-width:1100px;margin:2rem auto;padding:0 1.25rem}
h1{font-size:1.5rem;font-weight:800;margin-bottom:1.5rem}
.check-card{background:#fff;border-radius:14px;padding:1.5rem;margin-bottom:1.25rem;
    box-shadow:0 1px 3px rgba(0,0,0,.05);border-left:5px solid #64748b}
.check-card.ok{border-left-color:#16a34a}
.check-card.warn{border-left-color:#f59e0b}
.check-card.bad{border-left-color:#dc2626}
.check-card h2{font-size:1rem;font-weight:800;margin:0 0 .4rem}
.check-card .num{font-size:2rem;font-weight:900;color:#1e2b3c;line-height:1.1}
.check-card .desc{color:#64748b;font-size:.9rem;margin-top:.4rem}
.alert{border-radius:10px;padding:.85rem 1.15rem;font-size:.92rem;margin-bottom:1rem;
    border-left:4px solid}
.alert-success{background:#dcfce7;color:#14532d;border-color:#16a34a}
.alert-danger{background:#fee2e2;color:#7f1d1d;border-color:#dc2626}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;
    font-size:.85rem;font-weight:700;border:1px solid transparent;cursor:pointer;
    text-decoration:none;transition:all .15s}
.btn-primary{background:#1e3a5a;color:#fff}
.btn-primary:hover{background:#2d5f92;color:#fff}
.btn-outline{background:#fff;color:#1e3a5a;border-color:#1e3a5a}
.btn-outline:hover{background:#e8f0f8}
table{width:100%;border-collapse:collapse;font-size:.85rem;margin-top:1rem}
th{background:#f8fafc;text-align:left;padding:.6rem .8rem;font-size:.72rem;
    text-transform:uppercase;color:#64748b;font-weight:700;border-bottom:1px solid #e2e8f0}
td{padding:.6rem .8rem;border-bottom:1px solid #f1f5f9}
code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:.8rem}
</style>
</head>
<body>

<nav class="navbar">
    <span class="navbar-brand"><i class="fas fa-heartbeat me-2"></i>Data Health</span>
    <a href="dashboard.php" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
</nav>

<div class="container">
    <h1>Data Integrity Dashboard</h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Summary stats -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem;">
        <div class="check-card" style="margin:0;border-left-color:#2d5f92;">
            <h2><i class="fas fa-credit-card me-2"></i>Total Payments</h2>
            <div class="num"><?php echo number_format($total_payments); ?></div>
            <div class="desc"><?php echo number_format($total_confirmed); ?> confirmed · <?php echo number_format($total_pending); ?> pending</div>
        </div>
    </div>

    <!-- 1. Orphan payments -->
    <div class="check-card <?php echo $orphan_count === 0 ? 'ok' : ($orphan_count < 5 ? 'warn' : 'bad'); ?>">
        <h2><i class="fas fa-exclamation-triangle me-2"></i>Orphan payments</h2>
        <div class="num"><?php echo $orphan_count; ?></div>
        <div class="desc">
            Payments with a <code>NULL</code> member_code. Should be zero after the batch-confirm fix.
            Total value: ₱<?php echo number_format($orphan_total, 2); ?>.
        </div>
        <?php if ($orphan_count > 0): ?>
            <table>
                <thead><tr><th>Payment ID</th><th>Bad Member ID</th><th>Amount</th><th>Date</th><th>Receipt</th></tr></thead>
                <tbody>
                    <?php foreach ($orphans as $o): ?>
                        <tr>
                            <td>#<?php echo (int)$o['payment_id']; ?></td>
                            <td><code><?php echo htmlspecialchars((string)$o['member_id']); ?></code></td>
                            <td>₱<?php echo number_format((float)$o['amount'], 2); ?></td>
                            <td><?php echo date('M j, Y', strtotime($o['payment_date'])); ?></td>
                            <td><code><?php echo htmlspecialchars($o['receipt_number'] ?? '—'); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- 2. Identity drift -->
    <div class="check-card <?php echo $drift_count === 0 ? 'ok' : 'bad'; ?>">
        <h2><i class="fas fa-fingerprint me-2"></i>Identity drift</h2>
        <div class="num"><?php echo $drift_count; ?></div>
        <div class="desc">
            Payments where <code>CAST(member_code AS UNSIGNED) ≠ member_id</code>.
            This happens when the old code wrote the wrong value into the INT column.
            Should be zero after the fix.
        </div>
        <?php if ($drift_count > 0): ?>
            <table>
                <thead><tr><th>Payment ID</th><th>member_code</th><th>member_id</th><th>Amount</th><th>Date</th></tr></thead>
                <tbody>
                    <?php foreach ($drift_rows as $r): ?>
                        <tr>
                            <td>#<?php echo (int)$r['payment_id']; ?></td>
                            <td><code><?php echo htmlspecialchars($r['member_code']); ?></code></td>
                            <td><code><?php echo htmlspecialchars((string)$r['member_id']); ?></code></td>
                            <td>₱<?php echo number_format((float)$r['amount'], 2); ?></td>
                            <td><?php echo date('M j, Y', strtotime($r['payment_date'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- 3. Legacy alphanumeric member codes -->
    <div class="check-card <?php echo $legacy_count === 0 ? 'ok' : 'warn'; ?>">
        <h2><i class="fas fa-tag me-2"></i>Legacy alphanumeric member codes</h2>
        <div class="num"><?php echo $legacy_count; ?></div>
        <div class="desc">
            Members with non-numeric codes (e.g., <code>M4546</code>). The system now only accepts numeric codes.
            These should be migrated to numeric or removed.
        </div>
        <?php if ($legacy_count > 0): ?>
            <table>
                <thead><tr><th>member_code</th><th>Name</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($legacy_rows as $l): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($l['member_code']); ?></code></td>
                            <td><?php echo htmlspecialchars(trim($l['first_name'] . ' ' . $l['last_name'])); ?></td>
                            <td><?php echo htmlspecialchars($l['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- 4. Stale cached balances -->
    <div class="check-card <?php echo $stale_count === 0 ? 'ok' : 'warn'; ?>">
        <h2><i class="fas fa-calculator me-2"></i>Stale cached balances</h2>
        <div class="num"><?php echo $stale_count; ?></div>
        <div class="desc">
            Members whose cached <code>member_balances</code> (total_paid or current_balance)
            differs from the live sum of their confirmed payments. Rebuilding fixes them all at once.
        </div>
        <form method="POST" style="margin-top:1rem;" onsubmit="return confirm('Rebuild all member balances from confirmed payments? Safe to run any time.');">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="rebuild_balances">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-sync-alt me-1"></i> Rebuild all balances
            </button>
        </form>
    </div>

    <!-- 5. Missing next_due_date -->
    <div class="check-card <?php echo $missing_due_count === 0 ? 'ok' : 'warn'; ?>">
        <h2><i class="fas fa-calendar-times me-2"></i>Missing next-due dates</h2>
        <div class="num"><?php echo $missing_due_count; ?></div>
        <div class="desc">
            Balances with a <code>NULL</code> next_due_date. Backfilling sets them to the 15th of the next unpaid month
            (or whatever <code>payment_due_day</code> is set to in Settings).
        </div>
        <form method="POST" style="margin-top:1rem;">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="backfill_due_dates">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-calendar-check me-1"></i> Backfill due dates
            </button>
        </form>
    </div>

</div>

</body>
</html>