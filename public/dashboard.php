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

refresh_missed_installments($memberId);
$summary = savings_summary($memberId);
$loans = member_loans($memberId);
$activeLoan = null;
foreach ($loans as $loan) {
    if (in_array($loan['status'], LOAN_ACTIVE_STATUSES, true) && $loan['status'] !== 'pending') {
        $activeLoan = $loan;
        break;
    }
}
$eligibility = loan_eligibility($memberId);
$recent = array_slice(member_payment_history($memberId, 8), 0, 8);

render_header('Dashboard', $user);
?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100 p-3">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-soft-green"><i class="bi bi-piggy-bank"></i></div>
                <div>
                    <div class="text-muted small">Total savings</div>
                    <div class="h5 mb-0"><?= money($summary['total']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100 p-3">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-soft-gold"><i class="bi bi-person-vcard"></i></div>
                <div>
                    <div class="text-muted small">Membership no.</div>
                    <div class="h5 mb-0"><?= e($member['membership_no']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100 p-3">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-soft-blue"><i class="bi bi-cash-coin"></i></div>
                <div>
                    <div class="text-muted small">Loan outstanding</div>
                    <div class="h5 mb-0"><?= money($activeLoan['outstanding'] ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100 p-3">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-soft-<?= $eligibility['eligible'] ? 'green' : 'red' ?>">
                    <i class="bi bi-<?= $eligibility['eligible'] ? 'check-circle' : 'x-circle' ?>"></i>
                </div>
                <div>
                    <div class="text-muted small">Loan eligibility</div>
                    <div class="h6 mb-0"><?= $eligibility['eligible'] ? 'Eligible up to ' . money($eligibility['max_amount']) : 'Not eligible' ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card p-4 h-100">
            <h5 class="mb-3">My savings</h5>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Product</th><th class="text-end">Balance</th><th class="text-end">Target</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach (SAVINGS_PRODUCTS as $type => $name): $acc = $summary['accounts'][$type] ?? null; ?>
                        <tr>
                            <td><?= e($name) ?></td>
                            <td class="text-end"><?= money($acc['balance'] ?? 0) ?></td>
                            <td class="text-end text-muted"><?= ($acc && (float) $acc['target_amount'] > 0) ? money($acc['target_amount']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr><th>Total</th><th class="text-end"><?= money($summary['total']) ?></th><th></th></tr>
                    </tfoot>
                </table>
            </div>
            <a href="/savings.php" class="btn btn-outline-primary mt-3"><i class="bi bi-arrow-right"></i> View savings &amp; make a contribution</a>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card p-4 h-100">
            <h5 class="mb-3">Quick actions</h5>
            <div class="d-grid gap-2">
                <a href="/loan-apply.php" class="btn btn-primary"><i class="bi bi-file-earmark-plus"></i> Apply for a loan</a>
                <a href="/repayments.php" class="btn btn-outline-primary"><i class="bi bi-cash-stack"></i> Make a repayment</a>
                <a href="/statements.php" class="btn btn-outline-primary"><i class="bi bi-file-text"></i> Account statement</a>
                <a href="/profile.php" class="btn btn-outline-secondary"><i class="bi bi-person-gear"></i> Update my profile</a>
            </div>
            <?php if (!$eligibility['eligible']): ?>
                <div class="alert alert-warning mt-3 mb-0 small">
                    <strong>Loan eligibility:</strong>
                    <ul class="mb-0 ps-3"><?php foreach ($eligibility['reasons'] as $reason): ?><li><?= e($reason) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card mt-4 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Recent transactions</h5>
        <a href="/repayments.php" class="small text-brand">View all</a>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Date</th><th>Receipt</th><th>Type</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            <?php if ($recent === []): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">No transactions yet.</td></tr>
            <?php else: foreach ($recent as $row): ?>
                <tr>
                    <td><?= e(fmt_date($row['paid_at'])) ?></td>
                    <td><?= e($row['receipt_no']) ?></td>
                    <td><?= e(label($row['type'])) ?><?= $row['product'] ? ' · ' . e(label($row['product'])) : '' ?></td>
                    <td class="text-end"><?= money($row['amount']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
