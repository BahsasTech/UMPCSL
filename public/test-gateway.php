<?php
declare(strict_types=1);

/**
 * Test payment simulator. Stands in for an external gateway's checkout page so
 * the whole online payment flow can be exercised without live credentials.
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = require_login(['member']);
$member = member_for_user((int) $user['id']);
if (!$member) {
    flash('danger', 'No member profile is linked to your account.');
    redirect('/logout.php');
}

if (!gateway_config()['test_enabled']) {
    flash('danger', 'The test gateway is disabled.');
    redirect('/pay.php');
}

$reference = (string) ($_SERVER['REQUEST_METHOD'] === 'POST' ? post('reference', '') : get('reference', ''));
$txn = $reference !== '' ? get_transaction($reference) : null;
if (!$txn || (int) $txn['member_id'] !== (int) $member['id']) {
    flash('danger', 'Payment request not found.');
    redirect('/pay.php');
}

if ($txn['provider'] !== 'test') {
    flash('danger', 'This is not a test transaction.');
    redirect('/pay.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) post('action', '');
    if ($action === 'approve') {
        try {
            finalize_transaction($reference, gateway_verify('test', $txn));
            flash('success', 'Payment approved. ' . money((float) $txn['amount']) . ' received.');
        } catch (RuntimeException $e) {
            flash('danger', $e->getMessage());
        }
    } elseif ($action === 'decline') {
        transaction_update((int) $txn['id'], ['status' => 'cancelled']);
        flash('warning', 'Payment cancelled.');
    }
    redirect('/payment-callback.php?reference=' . urlencode($reference));
}

render_header('Test payment gateway', $user);
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card p-4">
            <div class="text-center mb-3">
                <i class="bi bi-credit-card-2-front display-5 text-primary"></i>
                <h4 class="mt-2 mb-0">Test Payment Gateway</h4>
                <p class="text-muted small mb-0">This is a simulator. No real money moves.</p>
            </div>
            <dl class="row mb-3">
                <dt class="col-5 text-muted">Merchant</dt><dd class="col-7"><?= e(config('app.name')) ?></dd>
                <dt class="col-5 text-muted">Reference</dt><dd class="col-7"><?= e($txn['reference']) ?></dd>
                <dt class="col-5 text-muted">Purpose</dt><dd class="col-7"><?= e(label($txn['purpose'])) ?></dd>
                <dt class="col-5 text-muted">Amount</dt><dd class="col-7 fw-semibold"><?= money((float) $txn['amount']) ?></dd>
                <dt class="col-5 text-muted">Status</dt><dd class="col-7"><?= status_badge($txn['status']) ?></dd>
            </dl>
            <?php if ($txn['status'] === 'success'): ?>
                <a class="btn btn-outline-secondary w-100" href="/payment-callback.php?reference=<?= e($txn['reference']) ?>">Continue</a>
            <?php else: ?>
                <form method="post" class="d-grid gap-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="reference" value="<?= e($txn['reference']) ?>">
                    <button class="btn btn-success" name="action" value="approve"><i class="bi bi-check-circle"></i> Approve payment of <?= money((float) $txn['amount']) ?></button>
                    <button class="btn btn-outline-danger" name="action" value="decline">Cancel payment</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php render_footer(); ?>
