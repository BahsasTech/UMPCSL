<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = require_login();
$payment = get_payment((int) get('id', 0));

$member = member_for_user((int) $user['id']);

if (!$payment || (is_member() && (int) $payment['member_id'] !== (int) $member['id'])) {
    http_response_code(404);
    exit('Receipt not found.');
}

render_header('Receipt ' . $payment['receipt_no'], $user, [
    'actions' => '<button class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>',
]);
?>
<div class="card p-4 receipt">
    <div class="text-center border-bottom pb-3 mb-4">
        <h4 class="mb-0"><?= e(config('app.name')) ?></h4>
        <div class="text-muted">Official payment receipt</div>
    </div>
    <div class="row mb-4">
        <div class="col-6">
            <div class="text-muted small">Received from</div>
            <div class="fw-semibold"><?= e($payment['first_name'] . ' ' . $payment['last_name']) ?></div>
            <div class="small">Membership: <?= e($payment['membership_no']) ?></div>
            <div class="small"><?= e($payment['email']) ?></div>
        </div>
        <div class="col-6 text-end">
            <div class="text-muted small">Receipt no.</div>
            <div class="fw-semibold"><?= e($payment['receipt_no']) ?></div>
            <div class="small">Date: <?= e(fmt_date($payment['paid_at'], 'd M Y H:i')) ?></div>
        </div>
    </div>
    <table class="table">
        <tbody>
            <tr><th class="w-50">Payment type</th><td><?= e(label($payment['type'])) ?></td></tr>
            <tr><th>Applied to</th><td><?= e($payment['product'] ? label($payment['product']) : ($payment['loan_no'] ?? '—')) ?></td></tr>
            <tr><th>Method</th><td><?= e(label($payment['method'])) ?></td></tr>
            <tr><th>Reference</th><td><?= e($payment['reference'] ?: '—') ?></td></tr>
            <tr><th>Narration</th><td><?= e($payment['narration'] ?: '—') ?></td></tr>
            <tr class="table-light"><th>Amount</th><th class="fs-5"><?= money($payment['amount']) ?></th></tr>
        </tbody>
    </table>
    <p class="text-muted small mb-0">This is a computer-generated receipt. Thank you for your payment.</p>
</div>
<?php render_footer(); ?>
