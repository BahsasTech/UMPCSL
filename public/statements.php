<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = require_login(['member']);
$member = member_for_user((int) $user['id']);
$memberId = (int) $member['id'];

$summary = savings_summary($memberId);
$payments = member_payment_history($memberId, 500);
$loans = member_loans($memberId);
$from = (string) get('from', date('Y-m-01'));
$to = (string) get('to', date('Y-m-d'));

render_header('Account Statement', $user, [
    'actions' => '<a href="/export.php?type=statement&from=' . urlencode($from) . '&to=' . urlencode($to) . '" class="btn btn-outline-success btn-sm no-print"><i class="bi bi-filetype-csv"></i> Export CSV</a>'
        . '<button class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>',
]);
?>
<div class="card p-4">
    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
        <div>
            <h4 class="mb-0"><?= e(config('app.name')) ?></h4>
            <div class="text-muted small">Account statement</div>
        </div>
        <div class="text-end small">
            <div><strong><?= e($member['first_name'] . ' ' . $member['last_name']) ?></strong></div>
            <div>Membership: <?= e($member['membership_no']) ?></div>
            <div>Generated: <?= e(date('d M Y H:i')) ?></div>
        </div>
    </div>

    <h6 class="text-muted text-uppercase small">Savings balances</h6>
    <table class="table table-sm mb-4">
        <tbody>
        <?php $total = 0; foreach (SAVINGS_PRODUCTS as $type => $name): $bal = (float) ($summary['accounts'][$type]['balance'] ?? 0); $total += $bal; ?>
            <tr><td><?= e($name) ?></td><td class="text-end"><?= money($bal) ?></td></tr>
        <?php endforeach; ?>
        <tr class="table-light"><th>Total savings</th><th class="text-end"><?= money($total) ?></th></tr>
        </tbody>
    </table>

    <h6 class="text-muted text-uppercase small">Loans</h6>
    <div class="table-responsive mb-4">
        <table class="table table-sm">
            <thead class="table-light"><tr><th>Loan no.</th><th>Amount</th><th>Status</th><th class="text-end">Outstanding</th></tr></thead>
            <tbody>
            <?php if ($loans === []): ?>
                <tr><td colspan="4" class="text-muted text-center">No loans.</td></tr>
            <?php else: foreach ($loans as $loan): ?>
                <tr>
                    <td><?= e($loan['loan_no']) ?></td>
                    <td><?= money($loan['amount']) ?></td>
                    <td><?= status_badge($loan['status']) ?></td>
                    <td class="text-end"><?= money($loan['outstanding']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <h6 class="text-muted text-uppercase small">Transaction history</h6>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead class="table-light"><tr><th>Date</th><th>Receipt</th><th>Type</th><th>Method</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            <?php if ($payments === []): ?>
                <tr><td colspan="5" class="text-muted text-center">No transactions.</td></tr>
            <?php else: $sum = 0; foreach ($payments as $row): $sum += (float) $row['amount']; ?>
                <tr>
                    <td><?= e(fmt_date($row['paid_at'])) ?></td>
                    <td><?= e($row['receipt_no']) ?></td>
                    <td><?= e(label($row['type'])) ?></td>
                    <td><?= e(label($row['method'])) ?></td>
                    <td class="text-end"><?= money($row['amount']) ?></td>
                </tr>
            <?php endforeach; ?>
                <tr class="table-light"><th colspan="4">Total</th><th class="text-end"><?= money($sum) ?></th></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
