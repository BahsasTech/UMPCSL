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

$id = (int) get('id', 0);
$sale = $id > 0 ? get_sale($id) : null;
if ($id === 0) {
    $saleNo = (string) get('sale_no', '');
    if ($saleNo !== '') {
        $s = fetch_one('SELECT * FROM sales WHERE sale_no = ?', [$saleNo]);
        if ($s) {
            $sale = get_sale((int) $s['id']);
            $id = (int) $s['id'];
        }
    }
}
if (!$sale || (int) $sale['member_id'] !== $memberId) {
    flash('danger', 'Order not found.');
    redirect('/orders.php');
}

$actions = '<a class="btn btn-outline-secondary btn-sm" href="/orders.php"><i class="bi bi-arrow-left"></i> My orders</a>';
if ($sale['status'] === 'pending' && enabled_gateways() !== []) {
    $actions = '<a class="btn btn-success btn-sm" href="/pay.php?purpose=order&sale_id=' . (int) $sale['id'] . '"><i class="bi bi-credit-card"></i> Pay now</a> ' . $actions;
}
render_header('Order ' . $sale['sale_no'], $user, ['actions' => $actions]);
?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card p-4">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h5 class="mb-1">Order <?= e($sale['sale_no']) ?></h5>
                    <div class="text-muted small">Placed <?= e(fmt_date($sale['created_at'], 'd M Y H:i')) ?></div>
                </div>
                <?= status_badge($sale['status']) ?>
            </div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="table-light"><tr><th>Item</th><th class="text-end">Price</th><th class="text-end">Qty</th><th class="text-end">Total</th></tr></thead>
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
        <div class="card p-4">
            <h6 class="mb-3">Details</h6>
            <div class="d-flex justify-content-between small"><span class="text-muted">Status</span><span><?= e(SALE_STATUSES[$sale['status']] ?? label($sale['status'])) ?></span></div>
            <div class="d-flex justify-content-between small"><span class="text-muted">Channel</span><span><?= e(label($sale['channel'])) ?></span></div>
            <div class="d-flex justify-content-between small"><span class="text-muted">Paid</span><span><?= e($sale['paid_at'] ? fmt_date($sale['paid_at'], 'd M Y H:i') : '—') ?></span></div>
            <?php if ($sale['payment_method']): ?><div class="d-flex justify-content-between small"><span class="text-muted">Method</span><span><?= e(label($sale['payment_method'])) ?></span></div><?php endif; ?>
            <?php if ($sale['payment_reference']): ?><div class="d-flex justify-content-between small"><span class="text-muted">Reference</span><span><?= e($sale['payment_reference']) ?></span></div><?php endif; ?>
            <?php if ($sale['notes']): ?><div class="small text-muted mt-3"><?= nl2br(e($sale['notes'])) ?></div><?php endif; ?>
            <div class="alert alert-info mt-3 mb-0 small"><i class="bi bi-info-circle"></i> We'll notify you when your order status changes.</div>
        </div>
    </div>
</div>
<?php render_footer(); ?>
