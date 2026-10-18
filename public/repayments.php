<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = require_login(['member']);
$member = member_for_user((int) $user['id']);
$memberId = (int) $member['id'];

$history = member_payment_history($memberId, 300);

render_header('Payment History', $user, [
    'actions' => '<a href="/statements.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-text"></i> Statement</a>',
]);
?>
<div class="card p-4">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr><th>Date</th><th>Receipt</th><th>Type</th><th>For</th><th>Method</th><th class="text-end">Amount</th><th></th></tr>
            </thead>
            <tbody>
            <?php if ($history === []): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No payments recorded yet.</td></tr>
            <?php else: foreach ($history as $row): ?>
                <tr>
                    <td><?= e(fmt_date($row['paid_at'])) ?></td>
                    <td><?= e($row['receipt_no']) ?></td>
                    <td><?= e(label($row['type'])) ?></td>
                    <td><?= e($row['product'] ? label($row['product']) : ($row['loan_no'] ?? '—')) ?></td>
                    <td><?= e(label($row['method'])) ?></td>
                    <td class="text-end"><?= money($row['amount']) ?></td>
                    <td class="text-end"><a class="small text-brand" href="/receipt.php?id=<?= (int) $row['id'] ?>">Receipt</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
