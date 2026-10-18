<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();
ensure_default_accounts();

$year = (int) get('year', (int) date('Y'));
$from = (string) get('from', $year . '-01-01');
$to   = (string) get('to', $year . '-12-31');

$pl = income_statement($from, $to);
$bs = balance_sheet($to);

render_header('Financial Statements', $user, [
    'actions' => '<button class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>',
]);
render_tabs(accounting_tabs(), 'financials');
?>
<div class="card p-4 mb-4 no-print">
    <form class="row g-3 align-items-end" method="get">
        <div class="col-md-3">
            <label class="form-label">Period from</label>
            <input class="form-control" type="date" name="from" value="<?= e($from) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Period to</label>
            <input class="form-control" type="date" name="to" value="<?= e($to) ?>">
        </div>
        <div class="col-md-3">
            <button class="btn btn-primary"><i class="bi bi-funnel"></i> Apply</button>
            <a class="btn btn-outline-secondary" href="/admin/financials.php?from=<?= (int) $year ?>-01-01&to=<?= (int) $year ?>-12-31">Full year <?= (int) $year ?></a>
        </div>
        <div class="col-md-3 text-md-end text-muted small">
            Showing statements as at <?= e(date('d M Y', strtotime($to))) ?>
        </div>
    </form>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card p-4 h-100">
            <h5 class="mb-1">Profit &amp; Loss</h5>
            <p class="text-muted small"><?= e(date('d M Y', strtotime($from))) ?> &ndash; <?= e(date('d M Y', strtotime($to))) ?></p>
            <table class="table table-sm align-middle">
                <tbody>
                    <tr class="table-light"><td class="fw-semibold" colspan="2">Income</td></tr>
                    <?php if ($pl['revenue'] === []): ?>
                        <tr><td colspan="2" class="text-muted">No income recorded.</td></tr>
                    <?php else: foreach ($pl['revenue'] as $row): ?>
                        <tr><td><?= e($row['code'] . ' ' . $row['name']) ?></td><td class="text-end"><?= money($row['amount']) ?></td></tr>
                    <?php endforeach; endif; ?>
                    <tr class="fw-semibold"><td class="text-end">Total income</td><td class="text-end"><?= money($pl['total_revenue']) ?></td></tr>

                    <tr class="table-light"><td class="fw-semibold" colspan="2">Expenses</td></tr>
                    <?php if ($pl['expenses'] === []): ?>
                        <tr><td colspan="2" class="text-muted">No expenses recorded.</td></tr>
                    <?php else: foreach ($pl['expenses'] as $row): ?>
                        <tr><td><?= e($row['code'] . ' ' . $row['name']) ?></td><td class="text-end"><?= money($row['amount']) ?></td></tr>
                    <?php endforeach; endif; ?>
                    <tr class="fw-semibold"><td class="text-end">Total expenses</td><td class="text-end"><?= money($pl['total_expense']) ?></td></tr>
                </tbody>
                <tfoot>
                    <tr class="<?= $pl['net'] >= 0 ? 'table-success' : 'table-danger' ?> fw-semibold">
                        <td class="text-end"><?= $pl['net'] >= 0 ? 'Net surplus' : 'Net deficit' ?></td>
                        <td class="text-end"><?= money(abs($pl['net'])) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card p-4 h-100">
            <h5 class="mb-1">Balance Sheet</h5>
            <p class="text-muted small">As at <?= e(date('d M Y', strtotime($to))) ?></p>
            <table class="table table-sm align-middle mb-0">
                <tbody>
                    <tr class="table-light"><td class="fw-semibold" colspan="2">Assets</td></tr>
                    <?php foreach ($bs['assets'] as $row): ?>
                        <tr><td><?= e($row['code'] . ' ' . $row['name']) ?></td><td class="text-end"><?= money($row['amount']) ?></td></tr>
                    <?php endforeach; ?>
                    <tr class="fw-semibold"><td class="text-end">Total assets</td><td class="text-end"><?= money($bs['total_assets']) ?></td></tr>

                    <tr class="table-light"><td class="fw-semibold" colspan="2">Liabilities</td></tr>
                    <?php foreach ($bs['liabilities'] as $row): ?>
                        <tr><td><?= e($row['code'] . ' ' . $row['name']) ?></td><td class="text-end"><?= money($row['amount']) ?></td></tr>
                    <?php endforeach; ?>
                    <tr class="fw-semibold"><td class="text-end">Total liabilities</td><td class="text-end"><?= money($bs['total_liabilities']) ?></td></tr>

                    <tr class="table-light"><td class="fw-semibold" colspan="2">Equity</td></tr>
                    <?php foreach ($bs['equity'] as $row): ?>
                        <tr><td><?= e($row['code'] . ' ' . $row['name']) ?></td><td class="text-end"><?= money($row['amount']) ?></td></tr>
                    <?php endforeach; ?>
                    <tr><td>Retained surplus</td><td class="text-end"><?= money($bs['retained']) ?></td></tr>
                    <tr class="fw-semibold"><td class="text-end">Total equity</td><td class="text-end"><?= money($bs['total_equity_and_retained']) ?></td></tr>
                </tbody>
                <tfoot>
                    <tr class="<?= $bs['balanced'] ? 'table-success' : 'table-danger' ?> fw-semibold">
                        <td class="text-end">Liabilities + equity</td>
                        <td class="text-end"><?= money($bs['total_liabilities'] + $bs['total_equity_and_retained']) ?></td>
                    </tr>
                </tfoot>
            </table>
            <?php if (!$bs['balanced']): ?>
                <div class="alert alert-danger small mt-3 mb-0"><i class="bi bi-exclamation-octagon me-1"></i>The balance sheet does not balance. Check for unposted or unbalanced journals.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php render_footer(); ?>
