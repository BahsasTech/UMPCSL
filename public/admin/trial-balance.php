<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();
ensure_default_accounts();

$asOf = (string) get('as_of', date('Y-m-d'));
$tb = trial_balance($asOf);
$balanced = abs($tb['total_debit'] - $tb['total_credit']) < 0.01;

render_header('Trial Balance', $user, [
    'actions' => '<a class="btn btn-outline-success btn-sm no-print" href="/export.php?type=trial_balance&as_of=' . e($asOf) . '"><i class="bi bi-filetype-csv"></i> Export CSV</a>'
        . '<button class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>',
]);
render_tabs(accounting_tabs(), 'trial');
?>
<div class="card p-4 mb-4 no-print">
    <form class="row g-3 align-items-end" method="get">
        <div class="col-md-3">
            <label class="form-label">As at</label>
            <input class="form-control" type="date" name="as_of" value="<?= e($asOf) ?>">
        </div>
        <div class="col-md-3">
            <button class="btn btn-primary"><i class="bi bi-funnel"></i> Apply</button>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 class="mb-0">Trial Balance</h5>
            <span class="text-muted small">As at <?= e(date('d M Y', strtotime($asOf))) ?></span>
        </div>
        <?php if ($balanced): ?>
            <span class="badge text-bg-success"><i class="bi bi-check-circle"></i> Balanced</span>
        <?php else: ?>
            <span class="badge text-bg-danger"><i class="bi bi-exclamation-octagon"></i> Out of balance by <?= money(abs($tb['total_debit'] - $tb['total_credit'])) ?></span>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Code</th><th>Account</th><th>Type</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
            <tbody>
            <?php if ($tb['rows'] === []): ?>
                <tr><td colspan="5" class="text-center text-muted">No balances to report.</td></tr>
            <?php else: foreach ($tb['rows'] as $row): ?>
                <tr>
                    <td><code><?= e($row['code']) ?></code></td>
                    <td><?= e($row['name']) ?></td>
                    <td class="text-muted small"><?= e(ACCOUNT_TYPES[$row['type']]) ?></td>
                    <td class="text-end"><?= $row['debit'] > 0 ? money($row['debit']) : '' ?></td>
                    <td class="text-end"><?= $row['credit'] > 0 ? money($row['credit']) : '' ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <tfoot class="fw-semibold table-light">
                <tr><td colspan="3" class="text-end">Totals</td><td class="text-end"><?= money($tb['total_debit']) ?></td><td class="text-end"><?= money($tb['total_credit']) ?></td></tr>
            </tfoot>
        </table>
    </div>
</div>
<?php render_footer(); ?>
