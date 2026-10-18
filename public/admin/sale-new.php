<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $items = [];
        $productIds = (array) ($_POST['item_product'] ?? []);
        $quantities = (array) ($_POST['item_qty'] ?? []);
        $prices = (array) ($_POST['item_price'] ?? []);
        foreach ($productIds as $i => $pid) {
            if ((int) $pid > 0) {
                $items[] = [
                    'product_id' => (int) $pid,
                    'quantity'   => (int) ($quantities[$i] ?? 0),
                    'unit_price' => $prices[$i] ?? '',
                ];
            }
        }
        $memberId = (int) post('member_id', 0);
        $customerName = (string) post('customer_name', '');
        if ($memberId > 0 && $customerName === '') {
            $m = get_member($memberId);
            $customerName = $m ? $m['first_name'] . ' ' . $m['last_name'] : 'Member';
        }
        if ($customerName === '') {
            $customerName = 'Walk-in customer';
        }
        $saleId = create_sale([
            'member_id'      => $memberId > 0 ? $memberId : null,
            'customer_name'  => $customerName,
            'customer_phone' => (string) post('customer_phone', '') ?: null,
            'channel'        => 'store',
            'status'         => (string) post('status', 'completed'),
            'discount'       => post('discount', 0),
            'payment_method' => (string) post('payment_method', 'cash'),
            'payment_reference' => (string) post('payment_reference', '') ?: null,
            'notes'          => (string) post('notes', '') ?: null,
        ], $items);
        flash('success', 'Sale recorded successfully.');
        redirect('/admin/sale-view.php?id=' . $saleId);
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
        redirect('/admin/sale-new.php');
    }
}

$members = list_members('', 'active');
$products = list_products(['active' => true]);
$priceMap = [];
foreach ($products as $p) {
    $priceMap[(int) $p['id']] = (float) $p['price'];
}

render_header('Record sale', $user, [
    'actions' => '<a class="btn btn-outline-secondary btn-sm" href="/admin/sales.php"><i class="bi bi-arrow-left"></i> Back</a>',
]);
?>
<form method="post" id="saleForm">
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card p-4 mb-4">
                <h5 class="mb-3">Items</h5>
                <div class="table-responsive">
                    <table class="table align-middle" id="itemsTable">
                        <thead class="table-light"><tr><th style="min-width:240px">Product</th><th style="width:110px">Qty</th><th style="width:150px">Unit price</th><th class="text-end" style="width:140px">Line total</th><th></th></tr></thead>
                        <tbody>
                            <tr>
                                <td>
                                    <select class="form-select form-select-sm item-product" name="item_product[]" onchange="onProductChange(this)">
                                        <option value="">Select product…</option>
                                        <?php foreach ($products as $p): ?>
                                            <option value="<?= (int) $p['id'] ?>" data-price="<?= e($p['price']) ?>"><?= e($p['name'] . ' — ' . money($p['price'])) ?> (<?= (int) $p['stock_qty'] ?> left)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="number" min="1" class="form-control form-control-sm item-qty" name="item_qty[]" value="1" onchange="recalc()" onkeyup="recalc()"></td>
                                <td><input type="number" step="0.01" min="0" class="form-control form-control-sm item-price" name="item_price[]" onchange="recalc()" onkeyup="recalc()"></td>
                                <td class="text-end item-total"><?= money(0) ?></td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)"><i class="bi bi-x"></i></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addRow()"><i class="bi bi-plus-lg"></i> Add item</button>
            </div>

            <div class="card p-4">
                <h5 class="mb-3">Customer</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Member (optional)</label>
                        <select class="form-select" name="member_id" onchange="onMemberChange(this)">
                            <option value="">Walk-in / guest</option>
                            <?php foreach ($members as $m): ?>
                                <option value="<?= (int) $m['id'] ?>" data-name="<?= e($m['first_name'] . ' ' . $m['last_name']) ?>"><?= e($m['membership_no'] . ' — ' . $m['first_name'] . ' ' . $m['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Customer name</label>
                        <input class="form-control" name="customer_name" id="customerName" placeholder="Walk-in customer">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input class="form-control" name="customer_phone">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Notes</label>
                        <input class="form-control" name="notes">
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card p-4">
                <h5 class="mb-3">Payment</h5>
                <div class="mb-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="completed" selected>Completed (paid &amp; handed over)</option>
                        <option value="paid">Paid (awaiting collection)</option>
                        <option value="pending">Pending payment</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Method</label>
                    <select class="form-select" name="payment_method">
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank transfer</option>
                        <option value="paystack">Paystack</option>
                        <option value="remita">Remita</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Reference</label>
                    <input class="form-control" name="payment_reference">
                </div>
                <div class="mb-3">
                    <label class="form-label">Discount</label>
                    <input type="number" step="0.01" min="0" class="form-control" name="discount" id="discount" value="0" onchange="recalc()" onkeyup="recalc()">
                </div>
                <hr>
                <div class="d-flex justify-content-between"><span class="text-muted">Subtotal</span><strong id="subtotal"><?= money(0) ?></strong></div>
                <div class="d-flex justify-content-between mb-3"><span class="text-muted">Discount</span><strong id="discountOut"><?= money(0) ?></strong></div>
                <div class="d-flex justify-content-between fs-5"><span>Total</span><strong id="grandTotal"><?= money(0) ?></strong></div>
                <button class="btn btn-primary w-100 mt-4"><i class="bi bi-check-lg"></i> Complete sale</button>
            </div>
        </div>
    </div>
</form>

<script>
const priceMap = <?= json_encode($priceMap) ?>;
function onProductChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    const row = sel.closest('tr');
    if (opt && opt.dataset.price) row.querySelector('.item-price').value = opt.dataset.price;
    recalc();
}
function onMemberChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('customerName').value = opt.dataset.name || '';
}
function addRow() {
    const tbody = document.querySelector('#itemsTable tbody');
    const row = tbody.rows[0].cloneNode(true);
    row.querySelectorAll('input').forEach(i => i.value = '');
    row.querySelector('.item-qty').value = 1;
    row.querySelector('select').selectedIndex = 0;
    row.querySelector('.item-total').textContent = <?= json_encode(money(0)) ?>;
    tbody.appendChild(row);
}
function removeRow(btn) {
    const tbody = document.querySelector('#itemsTable tbody');
    if (tbody.rows.length > 1) { btn.closest('tr').remove(); }
    recalc();
}
function recalc() {
    let subtotal = 0;
    document.querySelectorAll('#itemsTable tbody tr').forEach(row => {
        const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
        const price = parseFloat(row.querySelector('.item-price').value) || 0;
        const line = qty * price;
        row.querySelector('.item-total').textContent = line.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        subtotal += line;
    });
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    const total = Math.max(0, subtotal - discount);
    const fmt = n => n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('subtotal').textContent = <?= json_encode(config('app.currency')) ?> + fmt(subtotal);
    document.getElementById('discountOut').textContent = <?= json_encode(config('app.currency')) ?> + fmt(discount);
    document.getElementById('grandTotal').textContent = <?= json_encode(config('app.currency')) ?> + fmt(total);
}
</script>
<?php render_footer(); ?>
