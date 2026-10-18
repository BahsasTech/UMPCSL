<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();

$from = (string) get('from', date('Y-m-01'));
$to = (string) get('to', date('Y-m-d'));

$summary = sales_summary($from, $to);
$byStaff = sales_by_staff($from, $to);
$topProducts = top_products($from, $to, 10);

render_header('Employee Performance', $user, [
    'actions' => '<a class="btn btn-outline-secondary btn-sm" href="/admin/sales.php"><i class="bi bi-arrow-left"></i> Sales</a>',
]);
?>
<div class="card p-4 mb-4 no-print">
    <form class="row g-3 align-items-end" method="get">
        <div class="col-md-3"><label class="form-label">From</label><input type="date" class="form-control" name="from" value="<?= e($from) ?>"></div>
        <div class="col-md-3"><label class="form-label">To</label><input type="date" class="form-control" name="to" value="<?= e($to) ?>"></div>
        <div class="col-md-3"><button class="btn btn-primary"><i class="bi bi-funnel"></i> Apply</button></div>
    </form>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3"><div class="card p-3"><div class="text-muted small">Sales</div><div class="h5 mb-0"><?= number_format($summary['count']) ?></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card p-3"><div class="text-muted small">Revenue</div><div class="h5 mb-0"><?= money($summary['revenue']) ?></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card p-3"><div class="text-muted small">Cost of goods</div><div class="h5 mb-0"><?= money($summary['cost']) ?></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card p-3"><div class="text-muted small">Gross profit</div><div class="h5 mb-0"><?= money($summary['profit']) ?></div></div></div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card p-4 h-100">
            <h5 class="mb-3">Staff performance</h5>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Staff</th><th class="text-end">Sales</th><th class="text-end">Revenue</th><th class="text-end">Profit</th></tr></thead>
                    <tbody>
                    <?php if ($byStaff === []): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No sales in this period.</td></tr>
                    <?php else: foreach ($byStaff as $row): ?>
                        <tr>
                            <td><?= e($row['full_name']) ?></td>
                            <td class="text-end"><?= number_format((int) $row['sales']) ?></td>
                            <td class="text-end"><?= money($row['revenue']) ?></td>
                            <td class="text-end"><?= money($row['profit']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card p-4 h-100">
            <h5 class="mb-3">Best-selling products</h5>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Product</th><th class="text-end">Units</th><th class="text-end">Revenue</th></tr></thead>
                    <tbody>
                    <?php if ($topProducts === []): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">No products sold.</td></tr>
                    <?php else: foreach ($topProducts as $row): ?>
                        <tr>
                            <td><?= e($row['product_name']) ?></td>
                            <td class="text-end"><?= number_format((int) $row['units']) ?></td>
                            <td class="text-end"><?= money($row['revenue']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php render_footer(); ?>
