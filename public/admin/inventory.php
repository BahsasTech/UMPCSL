<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) post('action', '');
    try {
        if ($action === 'category_save') {
            save_category(post('id') ? (int) post('id') : null, (string) post('name', ''), (string) post('description', '') ?: null);
            flash('success', 'Category saved.');
        } elseif ($action === 'category_delete') {
            require_login(['admin']);
            delete_category((int) post('id', 0));
            flash('success', 'Category deleted. Products are now uncategorised.');
        } elseif ($action === 'movement') {
            $productId = (int) post('product_id', 0);
            $mode = (string) post('mode', 'restock');
            if ($mode === 'restock') {
                restock_product($productId, (int) post('quantity', 0), to_amount(post('unit_cost', 0)), (string) post('reference', '') ?: null, (string) post('notes', '') ?: null);
                flash('success', 'Stock added.');
            } else {
                adjust_stock($productId, (int) post('quantity', 0), (string) post('notes', '') ?: null);
                flash('success', 'Stock adjusted.');
            }
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/admin/inventory.php?tab=' . urlencode((string) post('tab', 'products')));
}

$tab = (string) get('tab', 'products');
$inv = inventory_value();
$lowStock = low_stock_products();

$ptabs = [
    'products'   => ['label' => 'Products',        'href' => '/admin/inventory.php',                'icon' => 'box-seam'],
    'movements'  => ['label' => 'Stock Movements', 'href' => '/admin/inventory.php?tab=movements',  'icon' => 'arrow-left-right'],
    'categories' => ['label' => 'Categories',      'href' => '/admin/inventory.php?tab=categories', 'icon' => 'tags'],
];

$filters = [
    'search'    => (string) get('q', ''),
    'category'  => (string) get('category', ''),
    'low_stock' => get('low') === '1',
];
$products = list_products($filters);
$categories = list_categories();
$movements = list_movements([
    'product' => (string) get('product', ''),
    'type'    => (string) get('mtype', ''),
]);

render_header('Inventory', $user, [
    'actions' => '<a class="btn btn-primary btn-sm" href="/admin/product-form.php"><i class="bi bi-plus-lg"></i> New product</a>',
]);
render_tabs($ptabs, $tab);
?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3"><div class="card p-3"><div class="text-muted small">Products</div><div class="h5 mb-0"><?= number_format($inv['products']) ?></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card p-3"><div class="text-muted small">Stock value (cost)</div><div class="h5 mb-0"><?= money($inv['cost']) ?></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card p-3"><div class="text-muted small">Stock value (retail)</div><div class="h5 mb-0"><?= money($inv['retail']) ?></div></div></div>
    <div class="col-sm-6 col-xl-3">
        <a class="text-decoration-none" href="/admin/inventory.php?low=1">
            <div class="card p-3"><div class="text-muted small">Low stock items</div><div class="h5 mb-0 <?= $lowStock !== [] ? 'text-danger' : '' ?>"><?= number_format(count($lowStock)) ?></div></div>
        </a>
    </div>
</div>

<?php if ($tab === 'products'): ?>
<div class="card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Products</h5>
        <form class="d-flex gap-2 no-print" method="get">
            <input type="hidden" name="tab" value="products">
            <input class="form-control form-control-sm" type="search" name="q" value="<?= e($filters['search']) ?>" placeholder="Search name or SKU">
            <select class="form-select form-select-sm" name="category" onchange="this.form.submit()">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (string) $filters['category'] === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="btn btn-sm btn-outline-secondary <?= $filters['low_stock'] ? 'active' : '' ?>"><input type="checkbox" name="low" value="1" class="d-none" <?= $filters['low_stock'] ? 'checked' : '' ?> onchange="this.form.submit()"> Low only</label>
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th></th><th>Product</th><th>Category</th><th class="text-end">Cost</th><th class="text-end">Price</th><th class="text-end">Stock</th><th>Status</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php if ($products === []): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No products found.</td></tr>
            <?php else: foreach ($products as $p): ?>
                <tr>
                    <td style="width:48px"><img src="<?= e(product_image_url($p['image'])) ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:6px"></td>
                    <td><?= e($p['name']) ?><br><span class="text-muted small"><?= e($p['sku']) ?></span></td>
                    <td><?= e($p['category_name'] ?? '—') ?></td>
                    <td class="text-end"><?= money($p['cost_price']) ?></td>
                    <td class="text-end"><?= money($p['price']) ?></td>
                    <td class="text-end <?= (int) $p['stock_qty'] <= (int) $p['reorder_level'] ? 'text-danger fw-semibold' : '' ?>"><?= (int) $p['stock_qty'] ?> <span class="text-muted small"><?= e($p['unit']) ?></span></td>
                    <td>
                        <?php if ($p['is_active']): ?><span class="badge text-bg-success">Active</span><?php else: ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?>
                        <?php if ($p['is_online']): ?><span class="badge text-bg-info">Online</span><?php endif; ?>
                    </td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/admin/product-form.php?id=<?= (int) $p['id'] ?>"><i class="bi bi-pencil"></i></a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($tab === 'movements'): ?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card p-4">
            <h5 class="mb-3">Record movement</h5>
            <form method="post" class="row g-2" id="moveForm">
                <?= csrf_field() ?><input type="hidden" name="action" value="movement"><input type="hidden" name="tab" value="movements">
                <div class="col-12"><label class="form-label small mb-0">Product</label>
                    <select class="form-select" name="product_id" required>
                        <option value="">Select product…</option>
                        <?php foreach (list_products(['active' => true]) as $p): ?>
                            <option value="<?= (int) $p['id'] ?>"><?= e($p['name'] . ' (' . $p['sku'] . ')') ?> — <?= (int) $p['stock_qty'] ?> in stock</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12"><label class="form-label small mb-0">Mode</label>
                    <select class="form-select" name="mode" id="modeSelect">
                        <option value="restock">Restock (add stock)</option>
                        <option value="adjust">Adjust to quantity</option>
                    </select>
                </div>
                <div class="col-6"><label class="form-label small mb-0">Quantity</label><input type="number" class="form-control" name="quantity" min="0" required></div>
                <div class="col-6" id="costWrap"><label class="form-label small mb-0">Unit cost</label><input type="number" step="0.01" class="form-control" name="unit_cost" value="0"></div>
                <div class="col-12"><input class="form-control" name="reference" placeholder="Reference (e.g. PO number)"></div>
                <div class="col-12"><input class="form-control" name="notes" placeholder="Notes"></div>
                <div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-plus-circle"></i> Save movement</button></div>
            </form>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Stock movement history</h5>
                <form class="d-flex gap-2 no-print" method="get">
                    <input type="hidden" name="tab" value="movements">
                    <select class="form-select form-select-sm" name="product" onchange="this.form.submit()">
                        <option value="">All products</option>
                        <?php foreach (list_products() as $p): ?>
                            <option value="<?= (int) $p['id'] ?>" <?= (string) get('product', '') === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="form-select form-select-sm" name="mtype" onchange="this.form.submit()">
                        <option value="">All types</option>
                        <?php foreach (MOVEMENT_TYPES as $key => $lbl): ?>
                            <option value="<?= e($key) ?>" <?= get('mtype') === $key ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>When</th><th>Product</th><th>Type</th><th class="text-end">Change</th><th class="text-end">Balance</th><th>By</th></tr></thead>
                    <tbody>
                    <?php if ($movements === []): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No movements recorded.</td></tr>
                    <?php else: foreach ($movements as $m): ?>
                        <tr>
                            <td class="text-muted small"><?= e(fmt_date($m['created_at'], 'd M Y H:i')) ?></td>
                            <td><?= e($m['product_name']) ?></td>
                            <td><?= e(MOVEMENT_TYPES[$m['type']] ?? label($m['type'])) ?></td>
                            <td class="text-end <?= (int) $m['change_qty'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= (int) $m['change_qty'] >= 0 ? '+' : '' ?><?= (int) $m['change_qty'] ?></td>
                            <td class="text-end"><?= (int) $m['balance_after'] ?></td>
                            <td class="text-muted small"><?= e($m['user_name'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script>
document.getElementById('modeSelect').addEventListener('change', function () {
    document.getElementById('costWrap').style.display = this.value === 'restock' ? '' : 'none';
});
</script>

<?php else: ?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card p-4">
            <h5 class="mb-3">Add category</h5>
            <form method="post" class="row g-2">
                <?= csrf_field() ?><input type="hidden" name="action" value="category_save"><input type="hidden" name="tab" value="categories">
                <div class="col-12"><input class="form-control" name="name" placeholder="Category name" required></div>
                <div class="col-12"><input class="form-control" name="description" placeholder="Description (optional)"></div>
                <div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> Save category</button></div>
            </form>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card p-4">
            <h5 class="mb-3">Categories</h5>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Name</th><th>Description</th><th class="text-end">Products</th><th></th></tr></thead>
                    <tbody>
                    <?php if ($categories === []): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No categories yet.</td></tr>
                    <?php else: foreach ($categories as $c): ?>
                        <tr>
                            <td><?= e($c['name']) ?></td>
                            <td class="text-muted small"><?= e($c['description'] ?? '—') ?></td>
                            <td class="text-end"><?= (int) $c['products'] ?></td>
                            <td class="text-end">
                                <?php if ($user['role'] === 'admin'): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this category?')">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="category_delete"><input type="hidden" name="tab" value="categories"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php render_footer(); ?>
