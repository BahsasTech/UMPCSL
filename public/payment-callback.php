<?php
declare(strict_types=1);

/**
 * Return URL for online payments. Confirms the transaction with the gateway
 * (idempotent) and shows the result.
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = require_login();
$member = member_for_user((int) $user['id']);

$reference = (string) (get('reference', '') ?: get('trxref', '') ?: get('orderID', '') ?: get('orderId', '') ?: get('orderid', ''));
$txn = $reference !== '' ? get_transaction($reference) : null;

if (!$txn) {
    $rrr = (string) get('RRR', '');
    if ($rrr !== '') {
        $txn = fetch_one('SELECT * FROM transactions WHERE provider_reference = ?', [$rrr]);
    }
}

if (!$txn) {
    flash('danger', 'We could not find that payment.');
    redirect(is_logged_in() ? '/pay.php' : '/login.php');
}

$isStaff = in_array($user['role'], ['admin', 'staff'], true);
if (!$isStaff && (int) $txn['member_id'] !== (int) ($member['id'] ?? 0)) {
    flash('danger', 'That payment does not belong to your account.');
    redirect('/pay.php');
}

$reference = (string) $txn['reference'];
$notice = null;

if ($txn['provider'] !== 'test' && $txn['status'] !== 'success') {
    try {
        $verify = gateway_verify((string) $txn['provider'], $txn);
        $result = finalize_transaction($reference, $verify);
        if ($result['status'] === 'success') {
            $notice = ['success', 'Payment confirmed. Thank you!'];
        } elseif ($result['status'] === 'pending') {
            $notice = ['info', 'Your payment is still pending confirmation.'];
        } else {
            $notice = ['warning', 'The payment was not completed.'];
        }
    } catch (RuntimeException $e) {
        $notice = ['danger', 'We could not confirm this payment: ' . $e->getMessage()];
    }
}

$txn = get_transaction($reference);

render_header('Payment result', $user);
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card p-4 text-center">
            <?php
            $icon = ['success' => 'check-circle text-success', 'pending' => 'hourglass-split text-warning', 'failed' => 'x-circle text-danger', 'cancelled' => 'slash-circle text-secondary'][$txn['status']] ?? 'info-circle text-muted';
            ?>
            <i class="bi bi-<?= $icon ?> display-4"></i>
            <h4 class="mt-3"><?= e(TRANSACTION_STATUSES[$txn['status']] ?? label($txn['status'])) ?></h4>
            <p class="text-muted mb-4"><?= e(label($txn['purpose'])) ?> · <?= money((float) $txn['amount']) ?></p>

            <?php if ($notice): ?>
                <div class="alert alert-<?= e($notice[0]) ?>"><?= e($notice[1]) ?></div>
            <?php endif; ?>

            <dl class="row text-start small mb-4">
                <dt class="col-5 text-muted">Reference</dt><dd class="col-7"><?= e($txn['reference']) ?></dd>
                <dt class="col-5 text-muted">Gateway</dt><dd class="col-7"><?= e(PAYMENT_PROVIDERS[$txn['provider']] ?? $txn['provider']) ?></dd>
                <dt class="col-5 text-muted">Status</dt><dd class="col-7"><?= status_badge($txn['status']) ?></dd>
                <dt class="col-5 text-muted">Paid</dt><dd class="col-7"><?= e($txn['paid_at'] ? fmt_date($txn['paid_at'], 'd M Y H:i') : '—') ?></dd>
            </dl>

            <div class="d-flex gap-2 justify-content-center">
                <a class="btn btn-primary" href="/pay.php">Make another payment</a>
                <a class="btn btn-outline-secondary" href="<?= !empty($member) ? '/dashboard.php' : '/admin/transactions.php' ?>">Done</a>
            </div>
        </div>
    </div>
</div>
<?php render_footer(); ?>
