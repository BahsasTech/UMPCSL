<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();

refresh_missed_installments();
$o = admin_overview();
$pendingLoans = list_loans('pending');
$recentPayments = list_payments();
$recentPayments = array_slice($recentPayments, 0, 8);
$missed = missed_payments();
$notifStats = notification_stats();
$pl = income_statement(date('Y') . '-01-01', date('Y') . '-12-31');
$bs = balance_sheet(date('Y-m-d'));
$storeSales = sales_summary(date('Y-m-01'), date('Y-m-d'));
$invValue = inventory_value();
$lowStock = low_stock_products();
$pendingOrders = (int) fetch_value("SELECT COUNT(*) FROM sales WHERE status = 'pending'");

render_header('Admin Dashboard', $user);
?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card p-3 h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-soft-green"><i class="bi bi-people"></i></div>
                <div><div class="text-muted small">Members</div><div class="h5 mb-0"><?= number_format($o['members']) ?></div></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card p-3 h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-soft-blue"><i class="bi bi-piggy-bank"></i></div>
                <div><div class="text-muted small">Total savings</div><div class="h5 mb-0"><?= money($o['savings_total']) ?></div></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card p-3 h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-soft-gold"><i class="bi bi-cash-coin"></i></div>
                <div><div class="text-muted small">Loan outstanding</div><div class="h5 mb-0"><?= money($o['loan_outstanding']) ?></div></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card p-3 h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-soft-red"><i class="bi bi-hourglass-split"></i></div>
                <div><div class="text-muted small">Pending loans</div><div class="h5 mb-0"><?= number_format($o['loans_pending']) ?></div></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Contributions this month</div><div class="h5 mb-0"><?= money($o['contributions_month']) ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Repayments this month</div><div class="h5 mb-0"><?= money($o['repayments_month']) ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Missed installments</div><div class="h5 mb-0"><?= number_format($o['missed']) ?></div></div></div>
    <div class="col-md-3">
        <a href="/admin/notifications.php" class="text-decoration-none">
            <div class="card p-3">
                <div class="text-muted small">Notifications sent<?= $notifStats['failed'] > 0 ? ' · <span class="text-danger">' . $notifStats['failed'] . ' failed</span>' : '' ?></div>
                <div class="h5 mb-0 text-body"><?= number_format($notifStats['sent'] + $notifStats['logged']) ?></div>
            </div>
        </a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <a href="/admin/financials.php" class="text-decoration-none">
            <div class="card p-3">
                <div class="text-muted small">Net surplus this year</div>
                <div class="h5 mb-0 <?= $pl['net'] >= 0 ? 'text-body' : 'text-danger' ?>"><?= money(abs($pl['net'])) ?></div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="/admin/financials.php" class="text-decoration-none">
            <div class="card p-3">
                <div class="text-muted small">Total assets</div>
                <div class="h5 mb-0 text-body"><?= money($bs['total_assets']) ?></div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="/admin/trial-balance.php" class="text-decoration-none">
            <div class="card p-3">
                <div class="text-muted small">Ledger status</div>
                <div class="h5 mb-0">
                    <?php if ($bs['balanced']): ?>
                        <span class="text-success"><i class="bi bi-check-circle"></i> Balanced</span>
                    <?php else: ?>
                        <span class="text-danger"><i class="bi bi-exclamation-octagon"></i> Out of balance</span>
                    <?php endif; ?>
                </div>
            </div>
        </a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <a href="/admin/sales.php" class="text-decoration-none">
            <div class="card p-3">
                <div class="text-muted small">Store sales this month</div>
                <div class="h5 mb-0 text-body"><?= money($storeSales['revenue']) ?></div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="/admin/inventory.php" class="text-decoration-none">
            <div class="card p-3">
                <div class="text-muted small">Inventory value (cost)</div>
                <div class="h5 mb-0 text-body"><?= money($invValue['cost']) ?></div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="/admin/sales.php?status=pending" class="text-decoration-none">
            <div class="card p-3">
                <div class="text-muted small">Pending online orders</div>
                <div class="h5 mb-0 text-body"><?= number_format($pendingOrders) ?></div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="/admin/inventory.php?low=1" class="text-decoration-none">
            <div class="card p-3">
                <div class="text-muted small">Low stock items</div>
                <div class="h5 mb-0 <?= $lowStock !== [] ? 'text-danger' : 'text-body' ?>"><?= number_format(count($lowStock)) ?></div>
            </div>
        </a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Loans awaiting action</h5>
                <a href="/admin/loans.php?status=pending" class="small text-brand">All pending</a>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Loan no.</th><th>Member</th><th class="text-end">Amount</th><th></th></tr></thead>
                    <tbody>
                    <?php if ($pendingLoans === []): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No pending applications.</td></tr>
                    <?php else: foreach (array_slice($pendingLoans, 0, 6) as $loan): ?>
                        <tr>
                            <td><?= e($loan['loan_no']) ?></td>
                            <td><?= e($loan['first_name'] . ' ' . $loan['last_name']) ?><br><span class="text-muted small"><?= e($loan['membership_no']) ?></span></td>
                            <td class="text-end"><?= money($loan['amount']) ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/admin/loan-view.php?id=<?= (int) $loan['id'] ?>">Review</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Recent payments</h5>
                <a href="/admin/payments.php" class="small text-brand">View all</a>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Receipt</th><th>Member</th><th>Type</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                    <?php if ($recentPayments === []): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No payments yet.</td></tr>
                    <?php else: foreach ($recentPayments as $p): ?>
                        <tr>
                            <td><?= e($p['receipt_no']) ?></td>
                            <td><?= e($p['first_name'] . ' ' . $p['last_name']) ?></td>
                            <td><?= e(label($p['type'])) ?></td>
                            <td class="text-end"><?= money($p['amount']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($missed !== []): ?>
<div class="card mt-4 p-4">
    <h5 class="mb-3 text-danger"><i class="bi bi-exclamation-triangle"></i> Missed repayments</h5>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Loan no.</th><th>Member</th><th>Installment</th><th>Due date</th><th class="text-end">Amount</th><th></th></tr></thead>
            <tbody>
            <?php foreach (array_slice($missed, 0, 10) as $m): ?>
                <tr>
                    <td><?= e($m['loan_no']) ?></td>
                    <td><?= e($m['first_name'] . ' ' . $m['last_name']) ?></td>
                    <td>#<?= (int) $m['installment'] ?></td>
                    <td><?= e(fmt_date($m['due_date'])) ?></td>
                    <td class="text-end"><?= money($m['amount_due']) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/admin/loan-view.php?id=<?= (int) $m['loan_id'] ?>">Record repayment</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php render_footer(); ?>
