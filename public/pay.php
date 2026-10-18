<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = require_login(['member']);
$member = member_for_user((int) $user['id']);
if (!$member) {
    flash('danger', 'No member profile is linked to your account.');
    redirect('/logout.php');
}
$memberId = (int) $member['id'];

$gateways = enabled_gateways();
if ($gateways === []) {
    flash('warning', 'No online payment gateway is currently enabled. Please contact the co-operative office.');
    redirect('/dashboard.php');
}

$purpose = (string) get('purpose', 'savings');
if (!isset(TRANSACTION_PURPOSES[$purpose]) || $purpose === 'other') {
    $purpose = 'savings';
}

$loans = fetch_all("SELECT id, loan_no, outstanding FROM loans WHERE member_id = ? AND status = 'disbursed' AND outstanding > 0 ORDER BY id DESC", [$memberId]);
$orders = fetch_all("SELECT id, sale_no, total FROM sales WHERE member_id = ? AND status = 'pending' ORDER BY id DESC", [$memberId]);
$preProduct = (string) get('product', 'monthly');
$preLoan = (int) get('loan_id', 0);
$preSale = (int) get('sale_id', 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $provider = (string) post('gateway', '');
        if (!isset($gateways[$provider])) {
            throw new RuntimeException('Choose a payment gateway.');
        }
        $purpose = (string) post('purpose', 'savings');
        if (!isset(TRANSACTION_PURPOSES[$purpose]) || $purpose === 'other') {
            throw new RuntimeException('Choose what you are paying for.');
        }

        $amount = to_amount(post('amount', 0));
        $relatedId = null;
        $meta = [];

        if ($purpose === 'savings') {
            $product = (string) post('product', 'monthly');
            if (!isset(SAVINGS_PRODUCTS[$product])) {
                throw new RuntimeException('Choose a savings product.');
            }
            $meta['product'] = $product;
        } elseif ($purpose === 'loan_repayment') {
            $loanId = (int) post('loan_id', 0);
            $loan = fetch_one('SELECT * FROM loans WHERE id = ? AND member_id = ?', [$loanId, $memberId]);
            if (!$loan) {
                throw new RuntimeException('Select one of your loans.');
            }
            if ((float) $loan['outstanding'] <= 0) {
                throw new RuntimeException('That loan has no outstanding balance.');
            }
            $relatedId = $loanId;
            $amount = $amount > 0 ? $amount : (float) $loan['outstanding'];
            $amount = min($amount, (float) $loan['outstanding']);
        } elseif ($purpose === 'order') {
            $saleId = (int) post('sale_id', 0);
            $sale = fetch_one('SELECT * FROM sales WHERE id = ? AND member_id = ?', [$saleId, $memberId]);
            if (!$sale) {
                throw new RuntimeException('Select one of your orders.');
            }
            if ($sale['status'] === 'cancelled') {
                throw new RuntimeException('That order was cancelled.');
            }
            if ($sale['status'] !== 'pending') {
                throw new RuntimeException('That order has already been paid.');
            }
            $relatedId = $saleId;
            $amount = (float) $sale['total'];
        }

        if ($amount <= 0) {
            throw new RuntimeException('Enter an amount greater than zero.');
        }

        $reference = 'TXN-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $txnId = transaction_create([
            'reference' => $reference,
            'provider'  => $provider,
            'purpose'   => $purpose,
            'member_id' => $memberId,
            'amount'    => $amount,
            'currency'  => gateway_config()['currency'],
            'related_id' => $relatedId,
            'meta'      => $meta,
        ]);
        $txn = get_transaction_by_id($txnId);
        $init = gateway_initialize($provider, $txn);
        transaction_update($txnId, [
            'authorization_url'  => $init['redirect_url'],
            'provider_reference' => $init['provider_reference'] ?? null,
        ]);
        redirect($init['redirect_url']);
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
        redirect('/pay.php?purpose=' . urlencode((string) post('purpose', 'savings')));
    }
}

$recent = fetch_all('SELECT * FROM transactions WHERE member_id = ? ORDER BY id DESC LIMIT 10', [$memberId]);

render_header('Make a Payment', $user);
?>
<ul class="nav nav-pills mb-4">
    <?php foreach (['savings' => 'Savings', 'loan_repayment' => 'Loan repayment', 'order' => 'Store order'] as $key => $label): ?>
        <li class="nav-item">
            <a class="nav-link <?= $purpose === $key ? 'active' : '' ?>" href="/pay.php?purpose=<?= $key ?>">
                <i class="bi bi-<?= $key === 'savings' ? 'piggy-bank' : ($key === 'loan_repayment' ? 'cash-coin' : 'bag-check') ?> me-1"></i><?= e($label) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card p-4">
            <h5 class="mb-3"><i class="bi bi-credit-card"></i> Pay online</h5>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="purpose" value="<?= e($purpose) ?>">

                <?php if ($purpose === 'savings'): ?>
                    <div class="mb-3">
                        <label class="form-label">Savings product</label>
                        <select class="form-select" name="product">
                            <?php foreach (SAVINGS_PRODUCTS as $v => $l): ?>
                                <option value="<?= $v ?>" <?= $preProduct === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount (<?= e(gateway_config()['currency']) ?>)</label>
                        <input type="number" step="0.01" min="1" class="form-control" name="amount" required>
                    </div>
                <?php elseif ($purpose === 'loan_repayment'): ?>
                    <?php if ($loans === []): ?>
                        <div class="alert alert-info mb-0">You have no disbursed loans with an outstanding balance.</div>
                    <?php else: ?>
                        <div class="mb-3">
                            <label class="form-label">Loan</label>
                            <select class="form-select" name="loan_id">
                                <?php foreach ($loans as $loan): ?>
                                    <option value="<?= (int) $loan['id'] ?>" <?= $preLoan === (int) $loan['id'] ? 'selected' : '' ?>><?= e($loan['loan_no']) ?> — outstanding <?= money($loan['outstanding']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount (<?= e(gateway_config()['currency']) ?>)</label>
                            <input type="number" step="0.01" min="1" class="form-control" name="amount" placeholder="Leave blank to pay the full outstanding balance">
                        </div>
                    <?php endif; ?>
                <?php elseif ($purpose === 'order'): ?>
                    <?php if ($orders === []): ?>
                        <div class="alert alert-info mb-0">You have no unpaid orders. <a href="/store.php">Visit the store</a> to shop.</div>
                    <?php else: ?>
                        <div class="mb-3">
                            <label class="form-label">Order</label>
                            <select class="form-select" name="sale_id">
                                <?php foreach ($orders as $order): ?>
                                    <option value="<?= (int) $order['id'] ?>" <?= $preSale === (int) $order['id'] ? 'selected' : '' ?>><?= e($order['sale_no']) ?> — <?= money($order['total']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="alert alert-secondary small">The full order total will be charged.</div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label">Payment method</label>
                    <div class="d-flex flex-column gap-2">
                        <?php $first = true; foreach ($gateways as $key => $gw): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="gateway" id="gw_<?= e($key) ?>" value="<?= e($key) ?>" <?= $first ? 'checked' : '' ?>>
                                <label class="form-check-label" for="gw_<?= e($key) ?>">
                                    <strong><?= e($gw['label']) ?></strong>
                                    <span class="text-muted small d-block"><?= e($gw['description']) ?></span>
                                </label>
                            </div>
                        <?php $first = false; endforeach; ?>
                    </div>
                </div>

                <?php
                $canPay = $purpose === 'savings' || ($purpose === 'loan_repayment' && $loans !== []) || ($purpose === 'order' && $orders !== []);
                if ($canPay): ?>
                    <button class="btn btn-primary w-100"><i class="bi bi-lock"></i> Proceed to payment</button>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card p-4">
            <h6 class="mb-3">Recent online payments</h6>
            <?php if ($recent === []): ?>
                <p class="text-muted small mb-0">You have not made any online payments yet.</p>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($recent as $t): ?>
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-start">
                            <div>
                                <div><?= money($t['amount']) ?> <span class="text-muted small">· <?= e(label($t['purpose'])) ?></span></div>
                                <div class="text-muted small"><?= e(fmt_date($t['created_at'], 'd M Y H:i')) ?> · <?= e($t['reference']) ?></div>
                            </div>
                            <?= status_badge($t['status']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php render_footer(); ?>
