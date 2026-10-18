<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();
$loan = loan_detail((int) get('id', 0));
if (!$loan) {
    http_response_code(404);
    exit('Loan not found.');
}
$loanId = (int) $loan['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) post('action', '');
    try {
        switch ($action) {
            case 'approve':
                approve_loan($loanId, (int) $user['id'], to_amount(post('amount', $loan['amount'])), (string) post('remarks', ''));
                $refreshed = fetch_one('SELECT * FROM loans WHERE id = ?', [$loanId]);
                safe_notify_member((int) $refreshed['member_id'], 'loan_approved', [
                    'loan_no'         => $refreshed['loan_no'],
                    'amount'          => money($refreshed['amount']),
                    'total_repayable' => money($refreshed['total_repayable']),
                    'term_months'     => $refreshed['term_months'],
                ]);
                flash('success', 'Loan approved and repayment schedule generated.');
                break;
            case 'reject':
                reject_loan($loanId, (int) $user['id'], (string) post('remarks', ''));
                safe_notify_member((int) $loan['member_id'], 'loan_rejected', [
                    'loan_no' => $loan['loan_no'],
                    'remarks' => (string) post('remarks', 'Not specified'),
                ]);
                flash('success', 'Loan rejected.');
                break;
            case 'disburse':
                disburse_loan($loanId, (int) $user['id']);
                safe_notify_member((int) $loan['member_id'], 'loan_disbursed', [
                    'loan_no' => $loan['loan_no'],
                    'amount'  => money($loan['amount']),
                ]);
                flash('success', 'Loan disbursed to member.');
                break;
            case 'repayment':
                $result = record_loan_repayment($loanId, to_amount(post('amount', 0)), [
                    'method' => (string) post('method', 'cash'),
                    'reference' => (string) post('reference', ''),
                ]);
                safe_notify_member((int) $loan['member_id'], 'repayment_received', [
                    'loan_no'     => $loan['loan_no'],
                    'amount'      => money(to_amount(post('amount', 0))),
                    'outstanding' => money($result['outstanding']),
                    'receipt_no'  => $result['receipt_no'],
                ]);
                flash('success', 'Repayment posted. Receipt ' . $result['receipt_no']
                    . ($result['completed'] ? '. Loan completed.' : '.'));
                break;
            case 'guarantor':
                set_guarantor_status((int) post('guarantor_id', 0), (string) post('guarantor_status', 'pending'));
                flash('success', 'Guarantor updated.');
                break;
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/admin/loan-view.php?id=' . $loanId);
}

$memberSummary = savings_summary((int) $loan['member_id']);

render_header('Loan · ' . $loan['loan_no'], $user, [
    'actions' => '<a href="/admin/loans.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> All loans</a>',
]);
?>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h5 class="mb-0"><?= e($loan['loan_no']) ?></h5>
                    <div class="text-muted"><?= e($loan['first_name'] . ' ' . $loan['last_name']) ?> · <?= e($loan['membership_no']) ?></div>
                </div>
                <?= status_badge($loan['status']) ?>
            </div>
            <div class="row g-3">
                <div class="col-md-3"><div class="text-muted small">Amount</div><div class="fw-semibold"><?= money($loan['amount']) ?></div></div>
                <div class="col-md-3"><div class="text-muted small">Rate</div><div class="fw-semibold"><?= (float) $loan['interest_rate'] ?>%</div></div>
                <div class="col-md-3"><div class="text-muted small">Term</div><div class="fw-semibold"><?= (int) $loan['term_months'] ?> months</div></div>
                <div class="col-md-3"><div class="text-muted small">Total repayable</div><div class="fw-semibold"><?= money($loan['total_repayable']) ?></div></div>
                <div class="col-md-3"><div class="text-muted small">Outstanding</div><div class="fw-semibold text-danger"><?= money($loan['outstanding']) ?></div></div>
                <div class="col-md-3"><div class="text-muted small">Applied</div><div class="fw-semibold"><?= e(fmt_date($loan['applied_at'])) ?></div></div>
                <div class="col-md-3"><div class="text-muted small">Disbursed</div><div class="fw-semibold"><?= e(fmt_date($loan['disbursed_at'])) ?></div></div>
                <div class="col-md-3"><div class="text-muted small">Member savings</div><div class="fw-semibold"><?= money($memberSummary['total']) ?></div></div>
                <div class="col-12"><div class="text-muted small">Purpose</div><div><?= e($loan['purpose'] ?: '—') ?></div></div>
            </div>
        </div>

        <div class="card p-4 mb-4">
            <h5 class="mb-3">Repayment schedule</h5>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>#</th><th>Due date</th><th class="text-end">Amount</th><th class="text-end">Paid</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if ($loan['schedule'] === []): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">Schedule not generated yet.</td></tr>
                    <?php else: foreach ($loan['schedule'] as $inst): ?>
                        <tr>
                            <td><?= (int) $inst['installment'] ?></td>
                            <td><?= e(fmt_date($inst['due_date'])) ?></td>
                            <td class="text-end"><?= money($inst['amount_due']) ?></td>
                            <td class="text-end"><?= money($inst['amount_paid']) ?></td>
                            <td><?= status_badge($inst['status']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card p-4">
            <h5 class="mb-3">Guarantors &amp; collateral</h5>
            <table class="table table-sm align-middle">
                <thead class="table-light"><tr><th>Guarantor</th><th>Phone</th><th class="text-end">Guaranteed</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php if ($loan['guarantors'] === []): ?>
                    <tr><td colspan="5" class="text-muted text-center">None.</td></tr>
                <?php else: foreach ($loan['guarantors'] as $g): ?>
                    <tr>
                        <td><?= e($g['name']) ?><br><span class="text-muted small"><?= e($g['relationship']) ?></span></td>
                        <td><?= e($g['phone']) ?></td>
                        <td class="text-end"><?= money($g['amount_guaranteed']) ?></td>
                        <td><?= status_badge($g['status']) ?></td>
                        <td class="text-end">
                            <?php if ($g['status'] === 'pending'): ?>
                                <form method="post" class="d-inline"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="guarantor"><input type="hidden" name="guarantor_id" value="<?= (int) $g['id'] ?>">
                                    <button name="guarantor_status" value="verified" class="btn btn-sm btn-outline-success">Verify</button>
                                    <button name="guarantor_status" value="rejected" class="btn btn-sm btn-outline-danger">Reject</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            <?php if ($loan['collateral'] !== []): ?>
                <h6 class="text-muted small text-uppercase mt-3">Collateral</h6>
                <ul class="small mb-0"><?php foreach ($loan['collateral'] as $c): ?><li><?= e($c['description']) ?> — <?= money($c['estimated_value']) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <?php if (in_array($loan['status'], ['pending', 'under_review'], true)): ?>
            <div class="card p-4 mb-4">
                <h5 class="mb-3">Review application</h5>
                <form method="post" class="mb-3">
                    <?= csrf_field() ?><input type="hidden" name="action" value="approve">
                    <label class="form-label">Approved amount</label>
                    <input type="number" step="0.01" class="form-control mb-2" name="amount" value="<?= e((string) $loan['amount']) ?>">
                    <label class="form-label">Remarks</label>
                    <input class="form-control mb-2" name="remarks">
                    <button class="btn btn-success w-100"><i class="bi bi-check-circle"></i> Approve loan</button>
                </form>
                <form method="post" data-confirm="Reject this loan application?">
                    <?= csrf_field() ?><input type="hidden" name="action" value="reject">
                    <input class="form-control mb-2" name="remarks" placeholder="Reason for rejection">
                    <button class="btn btn-outline-danger w-100"><i class="bi bi-x-circle"></i> Reject loan</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($loan['status'] === 'approved'): ?>
            <div class="card p-4 mb-4">
                <h5 class="mb-3">Disbursement</h5>
                <p class="text-muted small">Disbursing credits the loan amount to the member's monthly savings account.</p>
                <form method="post" data-confirm="Disburse this loan to the member?">
                    <?= csrf_field() ?><input type="hidden" name="action" value="disburse">
                    <button class="btn btn-primary w-100"><i class="bi bi-send-check"></i> Disburse <?= money($loan['amount']) ?></button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (in_array($loan['status'], ['disbursed', 'defaulted'], true)): ?>
            <div class="card p-4 mb-4">
                <h5 class="mb-3">Record repayment</h5>
                <form method="post" class="row g-2">
                    <?= csrf_field() ?><input type="hidden" name="action" value="repayment">
                    <div class="col-12"><input type="number" step="0.01" min="1" max="<?= e((string) $loan['outstanding']) ?>" class="form-control" name="amount" placeholder="Amount" required></div>
                    <div class="col-6"><select class="form-select" name="method"><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="paystack">Paystack</option><option value="remita">Remita</option></select></div>
                    <div class="col-6"><input class="form-control" name="reference" placeholder="Reference"></div>
                    <div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-cash-stack"></i> Post repayment</button></div>
                </form>
            </div>
        <?php endif; ?>

        <div class="card p-4">
            <h5 class="mb-3">Notes</h5>
            <p class="small text-muted mb-0"><?= e($loan['remarks'] ?: 'No remarks recorded.') ?></p>
        </div>
    </div>
</div>
<?php render_footer(); ?>
