<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = require_login();
$member = member_for_user((int) $user['id']);
if (!$member) {
    flash('danger', 'No member profile is linked to your account.');
    redirect('/logout.php');
}

$items = cart_items();
if ($items === [] && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash('warning', 'Your cart is empty.');
    redirect('/store.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $orderItems = [];
        foreach (cart() as $productId => $qty) {
            $orderItems[] = ['product_id' => (int) $productId, 'quantity' => (int) $qty];
        }
        $saleId = create_sale([
            'member_id'      => (int) $member['id'],
            'customer_name'  => $member['first_name'] . ' ' . $member['last_name'],
            'customer_phone' => (string) post('customer_phone', '') ?: ($user['phone'] ?? null),
            'customer_email' => $user['email'] ?? null,
            'channel'        => 'online',
            'status'         => 'pending',
            'payment_method' => null,
            'notes'          => (string) post('notes', '') ?: null,
        ], $orderItems);
        cart_clear();
        $sale = get_sale($saleId);
        safe_notify_member((int) $member['id'], 'order_placed', [
            'amount'   => money((float) $sale['total']),
            'order_no' => $sale['sale_no'],
        ]);
        flash('success', 'Order placed! We will notify you when it is ready.');
        redirect('/order.php?id=' . $saleId);
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
        redirect('/cart.php');
    }
}

$subtotal = cart_subtotal();

render_header('Checkout', $user, []);
?>
<h3 class="mb-4">Checkout</h3>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card p-4">
            <h5 class="mb-3">Contact details</h5>
            <form method="post" id="checkoutForm">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Member</label>
                        <input class="form-control" value="<?= e($member['membership_no'] . ' — ' . $member['first_name'] . ' ' . $member['last_name']) ?>" disabled>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input class="form-control" name="customer_phone" value="<?= e($user['phone'] ?? '') ?>" placeholder="Contact phone">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Delivery / pickup notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="e.g. Pick up at the co-operative office on Saturday"></textarea>
                    </div>
                </div>
                <div class="alert alert-info mt-3 mb-0 small">
                    <i class="bi bi-info-circle"></i> Orders are reserved from stock immediately. A staff member will confirm payment and collection, and you'll be notified.
                </div>
            </form>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card p-4">
            <h5 class="mb-3">Order summary</h5>
            <ul class="list-group list-group-flush mb-3">
                <?php foreach ($items as $item): $p = $item['product']; ?>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span><?= e($p['name']) ?> <span class="text-muted">× <?= (int) $item['quantity'] ?></span></span>
                        <span><?= money($item['line_total']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="d-flex justify-content-between fs-5 mb-3"><span>Total</span><strong><?= money($subtotal) ?></strong></div>
            <button type="submit" form="checkoutForm" class="btn btn-primary w-100"><i class="bi bi-bag-check"></i> Place order</button>
        </div>
    </div>
</div>
<?php render_footer(); ?>
