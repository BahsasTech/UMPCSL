<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'contribute') {
    require_csrf();
    try {
        $member = fetch_one('SELECT id FROM members WHERE membership_no = ?', [(string) post('membership_no')]);
        if (!$member) {
            throw new RuntimeException('No member found with that membership number.');
        }
        $result = record_savings_contribution((int) $member['id'], (string) post('type'), to_amount(post('amount', 0)), [
            'method' => (string) post('method', 'cash'),
            'reference' => (string) post('reference', ''),
        ]);
        flash('success', 'Contribution posted. Receipt ' . $result['receipt_no'] . '.');
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/admin/savings.php');
}

$byProduct = report_savings_by_product();
$grand = array_sum(array_map(fn($r) => (float) $r['balance'], $byProduct));
$topAccounts = fetch_all(
    'SELECT s.*, m.membership_no, m.first_name, m.last_name
     FROM savings_accounts s JOIN members m ON m.id = s.member_id
     ORDER BY s.balance DESC LIMIT 25'
);

render_header('Savings', $user, [
    'actions' => '<a class="btn btn-outline-success btn-sm" href="/export.php?type=member_positions"><i class="bi bi-filetype-csv"></i> Export positions</a>',
]);
?>
<div class="row g-3 mb-4">
    <?php foreach ($byProduct as $row): ?>
        <div class="col-md-3">
            <div class="card p-3 h-100">
                <div class="text-muted small"><?= e(label($row['type'])) ?></div>
                <div class="h5 mb-0"><?= money($row['balance']) ?></div>
                <div class="text-muted small"><?= number_format((int) $row['members']) ?> members</div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card p-4">
            <h5 class="mb-3">Record contribution</h5>
            <form method="post" class="row g-2">
                <?= csrf_field() ?><input type="hidden" name="action" value="contribute">
                <div class="col-12"><label class="form-label small mb-0">Membership no.</label><input class="form-control" name="membership_no" placeholder="UMP-..." required></div>
                <div class="col-12"><label class="form-label small mb-0">Product</label><select class="form-select" name="type"><?php foreach (SAVINGS_PRODUCTS as $t => $n): ?><option value="<?= $t ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label small mb-0">Amount</label><input type="number" step="0.01" class="form-control" name="amount" required></div>
                <div class="col-6"><label class="form-label small mb-0">Method</label><select class="form-select" name="method"><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="paystack">Paystack</option><option value="remita">Remita</option></select></div>
                <div class="col-12"><input class="form-control" name="reference" placeholder="Reference"></div>
                <div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-plus-circle"></i> Post contribution</button></div>
            </form>
            <div class="alert alert-secondary small mt-3 mb-0">Total society savings: <strong><?= money($grand) ?></strong></div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card p-4">
            <h5 class="mb-3">Top savings accounts</h5>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Member</th><th>Product</th><th>Account</th><th class="text-end">Balance</th><th class="text-end">Target</th></tr></thead>
                    <tbody>
                    <?php if ($topAccounts === []): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No savings accounts yet.</td></tr>
                    <?php else: foreach ($topAccounts as $a): ?>
                        <tr>
                            <td><?= e($a['first_name'] . ' ' . $a['last_name']) ?><br><span class="text-muted small"><?= e($a['membership_no']) ?></span></td>
                            <td><?= e(label($a['type'])) ?></td>
                            <td><?= e($a['account_no']) ?></td>
                            <td class="text-end"><?= money($a['balance']) ?></td>
                            <td class="text-end text-muted"><?= money($a['target_amount']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php render_footer(); ?>
