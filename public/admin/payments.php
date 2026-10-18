<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'record') {
    require_csrf();
    try {
        $member = fetch_one('SELECT id FROM members WHERE membership_no = ?', [(string) post('membership_no')]);
        if (!$member) {
            throw new RuntimeException('No member found with that membership number.');
        }
        $meta = [
            'method' => (string) post('method', 'cash'),
            'reference' => (string) post('reference', ''),
            'product' => (string) post('product', 'special_land'),
        ];
        if (post('type') === 'loan_repayment') {
            $loan = fetch_one('SELECT id FROM loans WHERE member_id = ? AND status IN ("disbursed","defaulted") ORDER BY id DESC LIMIT 1', [(int) $member['id']]);
            if (!$loan) {
                throw new RuntimeException('This member has no active loan to repay.');
            }
            $meta['loan_id'] = (int) $loan['id'];
        }
        $result = record_payment((int) $member['id'], (string) post('type'), to_amount(post('amount', 0)), $meta);
        flash('success', 'Payment recorded. Receipt ' . $result['receipt_no'] . '.');
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/admin/payments.php');
}

$filters = ['type' => (string) get('type', ''), 'method' => (string) get('method', ''), 'search' => (string) get('search', ''), 'from' => (string) get('from', ''), 'to' => (string) get('to', '')];
$payments = list_payments($filters);
$totals = payment_totals($filters['from'] ?: null, $filters['to'] ?: null);

$types = ['loan_repayment', 'savings_contribution', 'share_capital', 'special_savings', 'fee', 'other'];
$methods = ['cash', 'bank_transfer', 'paystack', 'remita', 'other'];

render_header('Payments', $user, [
    'actions' => '<a class="btn btn-outline-success btn-sm" href="/export.php?type=payments&from=' . urlencode($filters['from']) . '&to=' . urlencode($filters['to']) . '&search=' . urlencode($filters['search']) . '"><i class="bi bi-filetype-csv"></i> Export</a>',
]);
?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card p-4">
            <h5 class="mb-3">Record payment</h5>
            <form method="post" class="row g-2">
                <?= csrf_field() ?><input type="hidden" name="action" value="record">
                <div class="col-12"><label class="form-label small mb-0">Membership no.</label><input class="form-control" name="membership_no" placeholder="UMP-..." required></div>
                <div class="col-12"><label class="form-label small mb-0">Payment type</label>
                    <select class="form-select" name="type">
                        <?php foreach ($types as $t): ?><option value="<?= $t ?>"><?= e(label($t)) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12"><label class="form-label small mb-0">Special savings product</label>
                    <select class="form-select" name="product"><option value="special_land">Special Savings (Land)</option><option value="special_housing">Special Savings (Housing)</option></select>
                </div>
                <div class="col-6"><label class="form-label small mb-0">Amount</label><input type="number" step="0.01" class="form-control" name="amount" required></div>
                <div class="col-6"><label class="form-label small mb-0">Method</label><select class="form-select" name="method"><?php foreach ($methods as $m): ?><option value="<?= $m ?>"><?= e(label($m)) ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><input class="form-control" name="reference" placeholder="Reference / teller"></div>
                <div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-receipt"></i> Record payment</button></div>
            </form>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card p-4">
            <form class="row g-2 mb-3" method="get">
                <div class="col-md-3"><input class="form-control form-control-sm" name="search" value="<?= e($filters['search']) ?>" placeholder="Receipt / member"></div>
                <div class="col-md-2"><select class="form-select form-select-sm" name="type"><option value="">All types</option><?php foreach ($types as $t): ?><option value="<?= $t ?>" <?= $filters['type'] === $t ? 'selected' : '' ?>><?= e(label($t)) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2"><input type="date" class="form-control form-control-sm" name="from" value="<?= e($filters['from']) ?>"></div>
                <div class="col-md-2"><input type="date" class="form-control form-control-sm" name="to" value="<?= e($filters['to']) ?>"></div>
                <div class="col-md-3 d-flex gap-1"><button class="btn btn-sm btn-outline-primary flex-grow-1">Filter</button><a class="btn btn-sm btn-outline-secondary" href="/admin/payments.php">Reset</a></div>
            </form>

            <div class="d-flex justify-content-between mb-2">
                <span class="text-muted small"><?= number_format(count($payments)) ?> records</span>
                <span class="fw-semibold">Filtered total: <?= money($totals['grand_total']) ?></span>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Date</th><th>Receipt</th><th>Member</th><th>Type</th><th>Method</th><th class="text-end">Amount</th><th></th></tr></thead>
                    <tbody>
                    <?php if ($payments === []): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No payments found.</td></tr>
                    <?php else: foreach ($payments as $p): ?>
                        <tr>
                            <td><?= e(fmt_date($p['paid_at'])) ?></td>
                            <td><?= e($p['receipt_no']) ?></td>
                            <td><?= e($p['first_name'] . ' ' . $p['last_name']) ?><br><span class="text-muted small"><?= e($p['membership_no']) ?></span></td>
                            <td><?= e(label($p['type'])) ?></td>
                            <td><?= e(label($p['method'])) ?></td>
                            <td class="text-end"><?= money($p['amount']) ?></td>
                            <td class="text-end"><a class="small text-brand" href="/receipt.php?id=<?= (int) $p['id'] ?>">Receipt</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php render_footer(); ?>
