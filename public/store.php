<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = current_user();

$categoryId = (int) get('category', 0);
$search = (string) get('q', '');

$detailId = (int) get('product', 0);
if ($detailId > 0) {
    $detail = get_product($detailId);
    if (!$detail || !$detail['is_active'] || !$detail['is_online']) {
        flash('danger', 'Product not available.');
        redirect('/store.php');
    }
    $out = (int) $detail['stock_qty'] <= 0;
    render_header($detail['name'], $user, []);
    ?>
    <a class="btn btn-outline-secondary btn-sm mb-3" href="/store.php"><i class="bi bi-arrow-left"></i> Back to store</a>
    <div class="row g-4">
        <div class="col-lg-5">
            <img src="<?= e(product_image_url($detail['image'])) ?>" class="img-fluid rounded w-100" style="object-fit:cover;max-height:420px" alt="<?= e($detail['name']) ?>">
        </div>
        <div class="col-lg-7">
            <div class="text-muted small"><?= e($detail['category_name'] ?? 'Uncategorised') ?></div>
            <h2 class="mb-2"><?= e($detail['name']) ?></h2>
            <div class="fs-4 fw-semibold text-primary mb-3"><?= money($detail['price']) ?></div>
            <div class="mb-3 <?= $out ? 'text-danger' : 'text-muted' ?>">
                <?= $out ? 'Out of stock' : (int) $detail['stock_qty'] . ' ' . e(PRODUCT_UNITS[$detail['unit']] ?? $detail['unit']) . ' available' ?>
            </div>
            <?php if ($detail['description']): ?><p><?= nl2br(e($detail['description'])) ?></p><?php endif; ?>
            <form method="post" action="/cart.php" class="d-flex gap-2" style="max-width:320px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="product_id" value="<?= (int) $detail['id'] ?>">
                <input type="number" class="form-control" name="quantity" value="1" min="1" max="<?= max(1, (int) $detail['stock_qty']) ?>" <?= $out ? 'disabled' : '' ?>>
                <button class="btn btn-primary text-nowrap" <?= $out ? 'disabled' : '' ?>><i class="bi bi-cart-plus"></i> Add to cart</button>
            </form>
        </div>
    </div>
    <?php
    render_footer();
    exit;
}

$products = list_products([
    'active' => true,
    'online' => true,
    'category' => $categoryId ?: null,
    'search' => $search,
]);
$categories = list_categories();

render_header('Online Store', $user, [
]);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <div>
        <h3 class="mb-1">Cooperative Store</h3>
        <div class="text-muted">Farm inputs, provisions and household goods at member-friendly prices.</div>
    </div>
    <a class="btn btn-outline-primary" href="/cart.php"><i class="bi bi-cart3"></i> View cart<?= cart_count() > 0 ? ' (' . (int) cart_count() . ')' : '' ?></a>
</div>

<div class="card p-3 mb-4">
    <form class="row g-2 align-items-center" method="get">
        <div class="col-md-5">
            <input class="form-control" type="search" name="q" value="<?= e($search) ?>" placeholder="Search products…">
        </div>
        <div class="col-md-4">
            <select class="form-select" name="category" onchange="this.form.submit()">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $categoryId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-search"></i> Search</button></div>
    </form>
</div>

<?php if ($products === []): ?>
    <div class="card p-5 text-center text-muted">No products available right now. Please check back later.</div>
<?php else: ?>
<div class="row g-4">
    <?php foreach ($products as $p): $out = (int) $p['stock_qty'] <= 0; ?>
        <div class="col-sm-6 col-lg-4 col-xl-3">
            <div class="card h-100">
                <a href="/store.php?product=<?= (int) $p['id'] ?>" class="text-decoration-none">
                    <img src="<?= e(product_image_url($p['image'])) ?>" class="card-img-top" style="height:180px;object-fit:cover" alt="<?= e($p['name']) ?>">
                </a>
                <div class="card-body d-flex flex-column">
                    <div class="text-muted small"><?= e($p['category_name'] ?? 'Uncategorised') ?></div>
                    <h6 class="card-title mb-1"><?= e($p['name']) ?></h6>
                    <div class="fw-semibold text-primary mb-2"><?= money($p['price']) ?></div>
                    <div class="small mb-3 <?= $out ? 'text-danger' : 'text-muted' ?>">
                        <?= $out ? 'Out of stock' : (int) $p['stock_qty'] . ' ' . e(PRODUCT_UNITS[$p['unit']] ?? $p['unit']) . ' available' ?>
                    </div>
                    <form method="post" action="/cart.php" class="mt-auto d-flex gap-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                        <input type="number" class="form-control form-control-sm" name="quantity" value="1" min="1" max="<?= max(1, (int) $p['stock_qty']) ?>" style="width:80px" <?= $out ? 'disabled' : '' ?>>
                        <button class="btn btn-primary btn-sm flex-fill" <?= $out ? 'disabled' : '' ?>><i class="bi bi-cart-plus"></i> Add</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php render_footer(); ?>
