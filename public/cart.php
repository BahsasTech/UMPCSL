<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) post('action', '');
    $redirect = (string) post('redirect', '/cart.php');
    try {
        if ($action === 'add') {
            cart_add((int) post('product_id', 0), (int) post('quantity', 1));
            flash('success', 'Added to cart.');
        } elseif ($action === 'set') {
            $qty = (array) ($_POST['quantity'] ?? []);
            foreach ($qty as $pid => $q) {
                cart_set((int) $pid, (int) $q);
            }
            flash('success', 'Cart updated.');
        } elseif ($action === 'remove') {
            cart_remove((int) post('product_id', 0));
            flash('success', 'Item removed.');
        } elseif ($action === 'clear') {
            cart_clear();
            flash('success', 'Cart cleared.');
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect($redirect);
}

$items = cart_items();
$subtotal = cart_subtotal();

render_header('Your Cart', $user, []);
?>
<h3 class="mb-4">Your Cart</h3>

<?php if ($items === []): ?>
    <div class="card p-5 text-center">
        <div class="text-muted mb-3">Your cart is empty.</div>
        <a class="btn btn-primary" href="/store.php"><i class="bi bi-shop"></i> Browse the store</a>
    </div>
<?php else: ?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card p-4">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead class="table-light"><tr><th>Product</th><th class="text-end">Price</th><th style="width:120px">Qty</th><th class="text-end">Total</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $item): $p = $item['product']; ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <img src="<?= e(product_image_url($p['image'])) ?>" width="48" height="48" style="object-fit:cover" class="rounded" alt="">
                                        <span><?= e($p['name']) ?></span>
                                    </div>
                                </td>
                                <td class="text-end"><?= money($p['price']) ?></td>
                                <td><input type="number" class="form-control form-control-sm" name="quantity[<?= (int) $p['id'] ?>]" value="<?= (int) $item['quantity'] ?>" min="0" max="<?= (int) $p['stock_qty'] ?>"></td>
                                <td class="text-end"><?= money($item['line_total']) ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Remove" onclick="this.closest('tr').querySelector('input[name^=quantity]').value='0'; this.closest('form').submit();"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-repeat"></i> Update cart</button>
            </form>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-4">
            <h5 class="mb-3">Summary</h5>
            <div class="d-flex justify-content-between mb-3"><span class="text-muted">Subtotal</span><strong><?= money($subtotal) ?></strong></div>
            <a class="btn btn-primary w-100 mb-2" href="/checkout.php"><i class="bi bi-bag-check"></i> Proceed to checkout</a>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="clear"><button class="btn btn-outline-danger w-100 btn-sm">Clear cart</button></form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php render_footer(); ?>
