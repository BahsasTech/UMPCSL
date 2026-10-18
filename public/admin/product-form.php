<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();
$id = (int) get('id', 0);
$product = $id > 0 ? get_product($id) : null;
if ($id > 0 && !$product) {
    flash('danger', 'Product not found.');
    redirect('/admin/inventory.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $productId = save_product($id > 0 ? $id : null, $_POST);
        if (!empty($_FILES['image']['name'])) {
            $filename = save_product_image($_FILES['image'], $productId);
            db_update('products', $productId, ['image' => $filename]);
        }
        audit($id > 0 ? 'product_updated' : 'product_created', 'products', $productId, (string) post('name', ''));
        flash('success', 'Product saved.');
        redirect('/admin/inventory.php?tab=products');
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
        redirect('/admin/product-form.php' . ($id > 0 ? '?id=' . $id : ''));
    }
}

$categories = list_categories();
render_header($id > 0 ? 'Edit product' : 'New product', $user, [
    'actions' => '<a class="btn btn-outline-secondary btn-sm" href="/admin/inventory.php?tab=products"><i class="bi bi-arrow-left"></i> Back</a>',
]);
?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card p-4">
            <form method="post" enctype="multipart/form-data" class="row g-3">
                <?= csrf_field() ?>
                <div class="col-md-6">
                    <label class="form-label">Product name</label>
                    <input class="form-control" name="name" value="<?= e($product['name'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">SKU / code</label>
                    <input class="form-control" name="sku" value="<?= e($product['sku'] ?? '') ?>" placeholder="Leave blank to auto-generate">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Category</label>
                    <select class="form-select" name="category_id">
                        <option value="">Uncategorised</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= (string) ($product['category_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Unit</label>
                    <select class="form-select" name="unit">
                        <?php foreach (PRODUCT_UNITS as $key => $lbl): ?>
                            <option value="<?= e($key) ?>" <?= ($product['unit'] ?? 'unit') === $key ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cost price</label>
                    <input type="number" step="0.01" min="0" class="form-control" name="cost_price" value="<?= e($product['cost_price'] ?? '0') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Selling price</label>
                    <input type="number" step="0.01" min="0" class="form-control" name="price" value="<?= e($product['price'] ?? '0') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Reorder level</label>
                    <input type="number" min="0" class="form-control" name="reorder_level" value="<?= e($product['reorder_level'] ?? '0') ?>">
                </div>
                <?php if ($id === 0): ?>
                <div class="col-md-4">
                    <label class="form-label">Opening stock</label>
                    <input type="number" min="0" class="form-control" name="opening_stock" value="0">
                </div>
                <?php else: ?>
                <div class="col-md-4">
                    <label class="form-label">Current stock</label>
                    <input class="form-control" value="<?= (int) ($product['stock_qty'] ?? 0) ?>" disabled>
                    <div class="form-text">Adjust stock on the Inventory → Stock Movements tab.</div>
                </div>
                <?php endif; ?>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="description" rows="3"><?= e($product['description'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Image</label>
                    <input type="file" class="form-control" name="image" accept="image/*">
                </div>
                <div class="col-md-6 d-flex align-items-end gap-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= ($product === null || $product['is_active']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_online" id="is_online" <?= ($product === null || $product['is_online']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_online">Show in online store</label>
                    </div>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Save product</button>
                </div>
            </form>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-4">
            <h6 class="mb-3">Preview</h6>
            <img src="<?= e(product_image_url($product['image'] ?? null)) ?>" alt="" class="img-fluid rounded mb-3" style="max-height:200px;object-fit:cover">
            <div class="text-muted small">
                Products marked active and online appear in the public store. Stock is reduced automatically when a sale is recorded.
            </div>
        </div>
    </div>
</div>
<?php render_footer(); ?>
