<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();

$byProduct = report_savings_by_product();
$byStatus = report_loans_by_status();
$byMethod = report_payments_by_method();
$trend = report_monthly_contributions((int) date('Y'));
$positions = report_member_positions();
$o = admin_overview();

$maxTrend = 0.0;
foreach ($trend as $row) {
    $maxTrend = max($maxTrend, (float) $row['total']);
}

render_header('Reports', $user, [
    'actions' => '<button class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>',
]);
?>
<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Total savings</div><div class="h5 mb-0"><?= money($o['savings_total']) ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Share capital</div><div class="h5 mb-0"><?= money($o['share_capital']) ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Loans outstanding</div><div class="h5 mb-0"><?= money($o['loan_outstanding']) ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Members</div><div class="h5 mb-0"><?= number_format($o['members']) ?></div></div></div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card p-4 h-100">
            <h5 class="mb-3">Savings by product</h5>
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Product</th><th class="text-end">Members</th><th class="text-end">Balance</th></tr></thead>
                <tbody>
                <?php foreach ($byProduct as $row): ?>
                    <tr><td><?= e(label($row['type'])) ?></td><td class="text-end"><?= number_format((int) $row['members']) ?></td><td class="text-end"><?= money($row['balance']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card p-4 h-100">
            <h5 class="mb-3">Loan portfolio by status</h5>
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Status</th><th class="text-end">Count</th><th class="text-end">Amount</th><th class="text-end">Outstanding</th></tr></thead>
                <tbody>
                <?php if ($byStatus === []): ?><tr><td colspan="4" class="text-muted text-center">No loans.</td></tr>
                <?php else: foreach ($byStatus as $row): ?>
                    <tr><td><?= status_badge($row['status']) ?></td><td class="text-end"><?= number_format((int) $row['count']) ?></td><td class="text-end"><?= money($row['amount']) ?></td><td class="text-end"><?= money($row['outstanding']) ?></td></tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card p-4 h-100">
            <h5 class="mb-3">Monthly contributions <?= date('Y') ?></h5>
            <?php if ($trend === []): ?>
                <p class="text-muted mb-0">No contributions recorded this year.</p>
            <?php else: foreach ($trend as $row): $pct = $maxTrend > 0 ? round((float) $row['total'] / $maxTrend * 100) : 0; ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between small"><span><?= e(date('M Y', strtotime($row['period'] . '-01'))) ?></span><span><?= money($row['total']) ?></span></div>
                    <div class="progress" style="height:8px"><div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div></div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card p-4 h-100">
            <h5 class="mb-3">Payments by method</h5>
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Method</th><th class="text-end">Count</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                <?php if ($byMethod === []): ?><tr><td colspan="3" class="text-muted text-center">No payments.</td></tr>
                <?php else: foreach ($byMethod as $row): ?>
                    <tr><td><?= e(label($row['method'])) ?></td><td class="text-end"><?= number_format((int) $row['count']) ?></td><td class="text-end"><?= money($row['total']) ?></td></tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-12">
        <div class="card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Member financial positions</h5>
                <a class="btn btn-sm btn-outline-success no-print" href="/export.php?type=member_positions"><i class="bi bi-filetype-csv"></i> Export CSV</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Membership</th><th>Name</th><th>Status</th><th class="text-end">Savings</th><th class="text-end">Loan outstanding</th></tr></thead>
                    <tbody>
                    <?php foreach ($positions as $p): ?>
                        <tr>
                            <td><?= e($p['membership_no']) ?></td>
                            <td><?= e($p['first_name'] . ' ' . $p['last_name']) ?></td>
                            <td><?= status_badge($p['status']) ?></td>
                            <td class="text-end"><?= money($p['savings']) ?></td>
                            <td class="text-end"><?= money($p['outstanding']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php render_footer(); ?>
