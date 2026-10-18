<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();

$filters = [
    'status'  => (string) get('status', ''),
    'channel' => (string) get('channel', ''),
    'search'  => (string) get('q', ''),
    'from'    => (string) get('from', ''),
    'to'      => (string) get('to', ''),
];
$sales = list_sales($filters);
$summary = sales_summary($filters['from'] ?: null, $filters['to'] ?: null);

render_header('Sales & Orders', $user, [
    'actions' => '<a class="btn btn-outline-success btn-sm" href="/export.php?type=sales"><i class="bi bi-filetype-csv"></i> Export</a>'
        . '<a class="btn btn-primary btn-sm" href="/admin/sale-new.php"><i class="bi bi-plus-lg"></i> New sale</a>',
]);
?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3"><div class="card p-3"><div class="text-muted small">Sales (paid)</div><div class="h5 mb-0"><?= number_format($summary['count']) ?></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card p-3"><div class="text-muted small">Revenue</div><div class="h5 mb-0"><?= money($summary['revenue']) ?></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card p-3"><div class="text-muted small">Gross profit</div><div class="h5 mb-0"><?= money($summary['profit']) ?></div></div></div>
    <div class="col-sm-6 col-xl-3">
        <a class="text-decoration-none" href="/admin/performance.php"><div class="card p-3"><div class="text-muted small">Employee performance</div><div class="h6 mb-0 text-body"><i class="bi bi-graph-up-arrow"></i> View report</div></div></a>
    </div>
</div>

<div class="card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Sales &amp; orders</h5>
        <form class="d-flex gap-2" method="get">
            <input class="form-control form-control-sm" type="search" name="q" value="<?= e($filters['search']) ?>" placeholder="Sale no / customer">
            <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <?php foreach (SALE_STATUSES as $key => $lbl): ?>
                    <option value="<?= e($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm" name="channel" onchange="this.form.submit()">
                <option value="">All channels</option>
                <option value="store" <?= $filters['channel'] === 'store' ? 'selected' : '' ?>>Store</option>
                <option value="online" <?= $filters['channel'] === 'online' ? 'selected' : '' ?>>Online</option>
            </select>
            <input class="form-control form-control-sm" type="date" name="from" value="<?= e($filters['from']) ?>">
            <input class="form-control form-control-sm" type="date" name="to" value="<?= e($filters['to']) ?>">
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Sale no.</th><th>Date</th><th>Customer</th><th>Channel</th><th>Status</th><th class="text-end">Total</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php if ($sales === []): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No sales found.</td></tr>
            <?php else: foreach ($sales as $s): ?>
                <tr>
                    <td><code><?= e($s['sale_no']) ?></code></td>
                    <td class="text-muted small"><?= e(fmt_date($s['created_at'], 'd M Y H:i')) ?></td>
                    <td>
                        <?= e($s['customer_name']) ?>
                        <?php if ($s['membership_no']): ?><br><span class="text-muted small"><?= e($s['membership_no']) ?></span><?php endif; ?>
                    </td>
                    <td><?= e(label($s['channel'])) ?></td>
                    <td><?= status_badge($s['status']) ?></td>
                    <td class="text-end"><?= money($s['total']) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/admin/sale-view.php?id=<?= (int) $s['id'] ?>"><i class="bi bi-eye"></i></a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
