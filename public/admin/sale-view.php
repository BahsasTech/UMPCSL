<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();
$id = (int) get('id', 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $action = (string) post('action', '');
        if ($action === 'mark_paid') {
            mark_sale_paid($id, (string) post('method', 'cash'), (string) post('reference', '') ?: null);
            flash('success', 'Payment recorded.');
        } elseif ($action === 'status') {
            set_sale_status($id, (string) post('status', 'paid'));
            flash('success', 'Sale updated.');
        } elseif ($action === 'cancel') {
            cancel_sale($id);
            flash('success', 'Sale cancelled and stock returned.');
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/admin/sale-view.php?id=' . $id);
}

$sale = get_sale($id);
if (!$sale) {
    flash('danger', 'Sale not found.');
    redirect('/admin/sales.php');
}

render_header('Sale ' . $sale['sale_no'], $user, [
    'actions' => '<button class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>'
        . '<a class="btn btn-outline-secondary btn-sm no-print" href="/admin/sales.php"><i class="bi bi-arrow-left"></i> Back</a>',
]);
?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card p-4">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h5 class="mb-1"><?= e($sale['sale_no']) ?></h5>
                    <div class="text-muted small"><?= e(fmt_date($sale['created_at'], 'd M Y H:i')) ?> &middot; <?= e(label($sale['channel'])) ?> sale</div>
                </div>
                <?= status_badge($sale['status']) ?>
            </div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="table-light"><tr><th>Item</th><th class="text-end">Unit price</th><th class="text-end">Qty</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($sale['items'] as $item): ?>
                        <tr>
                            <td><?= e($item['product_name']) ?></td>
                            <td class="text-end"><?= money($item['unit_price']) ?></td>
                            <td class="text-end"><?= (int) $item['quantity'] ?></td>
                            <td class="text-end"><?= money($item['line_total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="3" class="text-end text-muted">Subtotal</td><td class="text-end"><?= money($sale['subtotal']) ?></td></tr>
                        <tr><td colspan="3" class="text-end text-muted">Discount</td><td class="text-end"><?= money($sale['discount']) ?></td></tr>
                        <tr class="fw-semibold"><td colspan="3" class="text-end">Total</td><td class="text-end"><?= money($sale['total']) ?></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card p-4 mb-4">
            <h6 class="mb-3">Customer</h6>
            <div><?= e($sale['customer_name']) ?></div>
            <?php if ($sale['membership_no']): ?><div class="text-muted small">Member <?= e($sale['membership_no']) ?></div><?php endif; ?>
            <?php if ($sale['customer_phone']): ?><div class="text-muted small"><?= e($sale['customer_phone']) ?></div><?php endif; ?>
            <?php if ($sale['customer_email']): ?><div class="text-muted small"><?= e($sale['customer_email']) ?></div><?php endif; ?>
            <hr>
            <div class="d-flex justify-content-between small"><span class="text-muted">Sold by</span><span><?= e($sale['seller'] ?? '—') ?></span></div>
            <div class="d-flex justify-content-between small"><span class="text-muted">Paid</span><span><?= e($sale['paid_at'] ? fmt_date($sale['paid_at'], 'd M Y H:i') : '—') ?></span></div>
            <div class="d-flex justify-content-between small"><span class="text-muted">Method</span><span><?= e($sale['payment_method'] ? label($sale['payment_method']) : '—') ?></span></div>
            <?php if ($sale['payment_reference']): ?><div class="d-flex justify-content-between small"><span class="text-muted">Reference</span><span><?= e($sale['payment_reference']) ?></span></div><?php endif; ?>
            <div class="d-flex justify-content-between small"><span class="text-muted">Cost of goods</span><span><?= money($sale['cost_total']) ?></span></div>
            <div class="d-flex justify-content-between small"><span class="text-muted">Gross profit</span><span><?= money((float) $sale['total'] - (float) $sale['cost_total']) ?></span></div>
        </div>

        <?php if ($sale['status'] !== 'cancelled'): ?>
        <div class="card p-4 no-print">
            <h6 class="mb-3">Actions</h6>
            <?php if ($sale['status'] === 'pending'): ?>
                <form method="post" class="mb-3" action="/admin/sale-view.php?id=<?= (int) $sale['id'] ?>">
                    <?= csrf_field() ?><input type="hidden" name="action" value="mark_paid">
                    <div class="mb-2"><label class="form-label small mb-0">Payment method</label>
                        <select class="form-select form-select-sm" name="method">
                            <option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option>
                            <option value="paystack">Paystack</option><option value="remita">Remita</option><option value="other">Other</option>
                        </select>
                    </div>
                    <div class="mb-2"><input class="form-control form-control-sm" name="reference" placeholder="Reference"></div>
                    <button class="btn btn-success btn-sm w-100"><i class="bi bi-cash-coin"></i> Mark as paid</button>
                </form>
            <?php endif; ?>
            <div class="d-flex gap-2 mb-2">
                <?php if (in_array($sale['status'], ['paid'], true)): ?>
                    <form method="post" action="/admin/sale-view.php?id=<?= (int) $sale['id'] ?>" class="flex-fill"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="status" value="fulfilled"><button class="btn btn-outline-info btn-sm w-100">Mark fulfilled</button></form>
                <?php endif; ?>
                <?php if (in_array($sale['status'], ['pending', 'paid', 'fulfilled'], true)): ?>
                    <form method="post" action="/admin/sale-view.php?id=<?= (int) $sale['id'] ?>" class="flex-fill"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="status" value="completed"><button class="btn btn-outline-success btn-sm w-100">Mark completed</button></form>
                <?php endif; ?>
            </div>
            <form method="post" action="/admin/sale-view.php?id=<?= (int) $sale['id'] ?>" onsubmit="return confirm('Cancel this sale and return stock?')">
                <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
                <button class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-x-circle"></i> Cancel sale</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php render_footer(); ?>
