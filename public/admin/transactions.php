<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) post('action', '');
    try {
        if ($action === 'settings') {
            require_login(['admin']);
            save_gateway_settings([
                'currency'            => (string) post('currency', 'NGN'),
                'default_gateway'     => (string) post('default_gateway', 'test'),
                'test_enabled'        => (bool) post('test_enabled'),
                'paystack_enabled'    => (bool) post('paystack_enabled'),
                'paystack_public_key' => (string) post('paystack_public_key', ''),
                'paystack_secret_key' => (string) post('paystack_secret_key', ''),
                'remita_enabled'      => (bool) post('remita_enabled'),
                'remita_merchant_id'  => (string) post('remita_merchant_id', ''),
                'remita_service_type_id' => (string) post('remita_service_type_id', ''),
                'remita_api_key'      => (string) post('remita_api_key', ''),
                'remita_api_hash_key' => (string) post('remita_api_hash_key', ''),
                'remita_base_url'     => (string) post('remita_base_url', 'https://remitademo.net'),
            ]);
            flash('success', 'Gateway settings saved.');
        } elseif ($action === 'requery') {
            require_login(['admin']);
            $txn = get_transaction_by_id((int) post('id', 0));
            if (!$txn) {
                throw new RuntimeException('Transaction not found.');
            }
            if ($txn['provider'] === 'test') {
                throw new RuntimeException('The test gateway is confirmed from its own page.');
            }
            $verify = gateway_verify((string) $txn['provider'], $txn);
            $result = finalize_transaction((string) $txn['reference'], $verify);
            flash($result['status'] === 'success' ? 'success' : 'warning', 'Re-checked: status is now ' . ($result['status']) . '.');
        } elseif ($action === 'cancel') {
            require_login(['admin']);
            $txn = get_transaction_by_id((int) post('id', 0));
            if ($txn && $txn['status'] === 'pending') {
                transaction_update((int) $txn['id'], ['status' => 'cancelled']);
                flash('success', 'Transaction cancelled.');
            }
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/admin/transactions.php' . (post('tab') === 'settings' ? '?tab=settings' : ''));
}

$tab = (string) get('tab', 'list');
$filters = [
    'status'   => (string) get('status', ''),
    'provider' => (string) get('provider', ''),
    'purpose'  => (string) get('purpose', ''),
    'search'   => (string) get('search', ''),
];
$transactions = list_transactions($filters);
$stats = transaction_stats();
$cfg = gateway_config();
$gateways = payment_gateways();

render_header('Online Payments', $user, [
    'actions' => '<a class="btn btn-outline-secondary btn-sm" href="/pay.php"><i class="bi bi-credit-card"></i> Payment page</a>',
]);
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Transactions</div><div class="h5 mb-0"><?= number_format($stats['count']) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Collected</div><div class="h5 mb-0 text-success"><?= money($stats['success_total']) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Pending</div><div class="h5 mb-0 text-warning"><?= number_format($stats['pending']) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Failed</div><div class="h5 mb-0 text-danger"><?= number_format($stats['failed']) ?></div></div></div>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab === 'list' ? 'active' : '' ?>" href="?tab=list"><i class="bi bi-list"></i> Transactions</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'settings' ? 'active' : '' ?>" href="?tab=settings"><i class="bi bi-gear"></i> Gateway settings</a></li>
</ul>

<?php if ($tab === 'settings'): ?>
    <?php if ($user['role'] !== 'admin'): ?>
        <div class="alert alert-warning">Only administrators can change gateway settings.</div>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="settings">
        <input type="hidden" name="tab" value="settings">
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card p-4 h-100">
                    <h5 class="mb-3"><i class="bi bi-sliders"></i> General</h5>
                    <div class="mb-3">
                        <label class="form-label">Currency code</label>
                        <input class="form-control" name="currency" value="<?= e($cfg['currency']) ?>" placeholder="NGN">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Default gateway</label>
                        <select class="form-select" name="default_gateway">
                            <?php foreach ($gateways as $key => $gw): ?>
                                <option value="<?= e($key) ?>" <?= $cfg['default_gateway'] === $key ? 'selected' : '' ?>><?= e($gw['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="test_enabled" id="test_enabled" value="1" <?= $cfg['test_enabled'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="test_enabled">Enable the test simulator</label>
                    </div>
                    <div class="alert alert-info small mt-3 mb-0">
                        Webhook URL for live gateways: <code><?= e(rtrim((string) config('app.url'), '/')) ?>/payment-webhook.php?provider=paystack</code>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card p-4 h-100">
                    <h5 class="mb-3"><i class="bi bi-credit-card"></i> Paystack</h5>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="paystack_enabled" id="paystack_enabled" value="1" <?= $cfg['paystack']['enabled'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="paystack_enabled">Enable Paystack</label>
                    </div>
                    <div class="mb-3"><label class="form-label">Public key</label><input class="form-control" name="paystack_public_key" value="<?= e($cfg['paystack']['public_key']) ?>"></div>
                    <div class="mb-3"><label class="form-label">Secret key</label><input type="password" class="form-control" name="paystack_secret_key" value="<?= e($cfg['paystack']['secret_key']) ?>"></div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card p-4 h-100">
                    <h5 class="mb-3"><i class="bi bi-bank"></i> Remita</h5>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="remita_enabled" id="remita_enabled" value="1" <?= $cfg['remita']['enabled'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="remita_enabled">Enable Remita</label>
                    </div>
                    <div class="mb-3"><label class="form-label">Merchant ID</label><input class="form-control" name="remita_merchant_id" value="<?= e($cfg['remita']['merchant_id']) ?>"></div>
                    <div class="mb-3"><label class="form-label">Service type ID</label><input class="form-control" name="remita_service_type_id" value="<?= e($cfg['remita']['service_type_id']) ?>"></div>
                    <div class="mb-3"><label class="form-label">API key</label><input type="password" class="form-control" name="remita_api_key" value="<?= e($cfg['remita']['api_key']) ?>"></div>
                    <div class="mb-3"><label class="form-label">API hash key</label><input type="password" class="form-control" name="remita_api_hash_key" value="<?= e($cfg['remita']['api_hash_key']) ?>"></div>
                    <div class="mb-3"><label class="form-label">Base URL</label><input class="form-control" name="remita_base_url" value="<?= e($cfg['remita']['base_url']) ?>"></div>
                </div>
            </div>
        </div>
        <?php if ($user['role'] === 'admin'): ?>
            <button class="btn btn-primary mt-3"><i class="bi bi-save"></i> Save settings</button>
        <?php endif; ?>
    </form>

<?php else: ?>
    <div class="card p-4">
        <form class="row g-2 mb-3" method="get">
            <input type="hidden" name="tab" value="list">
            <div class="col-md-4"><input class="form-control" name="search" value="<?= e($filters['search']) ?>" placeholder="Reference or membership no."></div>
            <div class="col-md-2"><select class="form-select" name="provider"><option value="">All gateways</option><?php foreach (PAYMENT_PROVIDERS as $p => $l): ?><option value="<?= e($p) ?>" <?= $filters['provider'] === $p ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><select class="form-select" name="purpose"><option value="">All purposes</option><?php foreach (TRANSACTION_PURPOSES as $p => $l): ?><option value="<?= e($p) ?>" <?= $filters['purpose'] === $p ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><select class="form-select" name="status"><option value="">All statuses</option><?php foreach (TRANSACTION_STATUSES as $s => $l): ?><option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 d-flex gap-1"><button class="btn btn-outline-primary flex-grow-1">Filter</button><a class="btn btn-outline-secondary" href="/admin/transactions.php">Reset</a></div>
        </form>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>When</th><th>Reference</th><th>Member</th><th>Purpose</th><th>Gateway</th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php if ($transactions === []): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No online transactions yet.</td></tr>
                <?php else: foreach ($transactions as $t): ?>
                    <tr>
                        <td class="small"><?= e(fmt_date($t['created_at'], 'd M Y H:i')) ?></td>
                        <td class="small"><?= e($t['reference']) ?><?php if ($t['provider_reference'] && $t['provider_reference'] !== $t['reference']): ?><br><span class="text-muted"><?= e($t['provider_reference']) ?></span><?php endif; ?></td>
                        <td class="small"><?= $t['membership_no'] ? e($t['membership_no'] . ' — ' . $t['first_name'] . ' ' . $t['last_name']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="small"><?= e(TRANSACTION_PURPOSES[$t['purpose']] ?? label($t['purpose'])) ?></td>
                        <td class="small"><?= e(PAYMENT_PROVIDERS[$t['provider']] ?? $t['provider']) ?></td>
                        <td class="text-end"><?= money((float) $t['amount']) ?></td>
                        <td><?= status_badge($t['status']) ?></td>
                        <td class="text-end">
                            <?php if ($user['role'] === 'admin' && $t['status'] === 'pending'): ?>
                                <form method="post" class="d-inline"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="requery"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                    <button class="btn btn-sm btn-outline-primary">Re-check</button>
                                </form>
                                <form method="post" class="d-inline"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Cancel</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
<?php render_footer(); ?>
