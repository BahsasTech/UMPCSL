<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();

$status = (string) get('status', '');
$search = (string) get('search', '');
refresh_missed_installments();
$loans = list_loans($status, $search);
$totals = loan_totals();

$statuses = ['', 'pending', 'under_review', 'approved', 'disbursed', 'completed', 'defaulted', 'rejected'];

render_header('Loans', $user, [
    'actions' => '<a class="btn btn-outline-success btn-sm" href="/export.php?type=loans&status=' . urlencode($status) . '&search=' . urlencode($search) . '"><i class="bi bi-filetype-csv"></i> Export</a>',
]);
?>
<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Disbursed (all time)</div><div class="h5 mb-0"><?= money($totals['disbursed']) ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Outstanding</div><div class="h5 mb-0"><?= money($totals['outstanding']) ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Active loans</div><div class="h5 mb-0"><?= number_format($totals['active']) ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Pending applications</div><div class="h5 mb-0"><?= number_format($totals['pending']) ?></div></div></div>
</div>

<div class="card p-4">
    <form class="row g-2 mb-3" method="get">
        <div class="col-md-6"><input class="form-control" name="search" value="<?= e($search) ?>" placeholder="Search loan no. or member"></div>
        <div class="col-md-3">
            <select class="form-select" name="status">
                <option value="">All statuses</option>
                <?php foreach ($statuses as $s): if ($s === '') continue; ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(label($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-outline-primary flex-grow-1"><i class="bi bi-search"></i> Filter</button>
            <a class="btn btn-outline-secondary" href="/admin/loans.php">Reset</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr><th>Loan no.</th><th>Member</th><th class="text-end">Amount</th><th class="text-end">Outstanding</th><th>Term</th><th>Status</th><th>Applied</th><th></th></tr>
            </thead>
            <tbody>
            <?php if ($loans === []): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No loans found.</td></tr>
            <?php else: foreach ($loans as $l): ?>
                <tr>
                    <td><?= e($l['loan_no']) ?></td>
                    <td><?= e($l['first_name'] . ' ' . $l['last_name']) ?><br><span class="text-muted small"><?= e($l['membership_no']) ?></span></td>
                    <td class="text-end"><?= money($l['amount']) ?></td>
                    <td class="text-end"><?= money($l['outstanding']) ?></td>
                    <td><?= (int) $l['term_months'] ?> mo</td>
                    <td><?= status_badge($l['status']) ?></td>
                    <td><?= e(fmt_date($l['applied_at'])) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/admin/loan-view.php?id=<?= (int) $l['id'] ?>">Open</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
