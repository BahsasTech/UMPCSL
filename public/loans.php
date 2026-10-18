<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = require_login(['member']);
$member = member_for_user((int) $user['id']);
$memberId = (int) $member['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $loanId = (int) post('loan_id', 0);
        $loan = fetch_one('SELECT * FROM loans WHERE id = ? AND member_id = ?', [$loanId, $memberId]);
        if (!$loan) {
            throw new RuntimeException('Loan not found.');
        }
        $result = record_loan_repayment($loanId, to_amount(post('amount', 0)), [
            'method'    => (string) post('method', 'bank_transfer'),
            'reference' => (string) post('reference', ''),
            'narration' => 'Member repayment for ' . $loan['loan_no'],
        ]);
        safe_notify_member($memberId, 'repayment_received', [
            'loan_no'     => $loan['loan_no'],
            'amount'      => money(to_amount(post('amount', 0))),
            'outstanding' => money($result['outstanding']),
            'receipt_no'  => $result['receipt_no'],
        ]);
        flash('success', 'Repayment recorded. Receipt ' . $result['receipt_no']
            . ($result['completed'] ? '. Loan fully repaid!' : '.'));
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/loans.php');
}

refresh_missed_installments($memberId);
$loans = member_loans($memberId);
$eligibility = loan_eligibility($memberId);

render_header('My Loans', $user, [
    'actions' => '<a href="/loan-apply.php" class="btn btn-primary btn-sm"><i class="bi bi-file-earmark-plus"></i> Apply for a loan</a>',
]);
?>
<?php if (!$eligibility['eligible']): ?>
    <div class="alert alert-info">
        <strong>Loan eligibility:</strong>
        <ul class="mb-0 ps-3"><?php foreach ($eligibility['reasons'] as $reason): ?><li><?= e($reason) ?></li><?php endforeach; ?></ul>
    </div>
<?php else: ?>
    <div class="alert alert-success">You are eligible to borrow up to <strong><?= money($eligibility['max_amount']) ?></strong> based on your savings.</div>
<?php endif; ?>

<?php if ($loans === []): ?>
    <div class="card p-5 text-center text-muted">
        <i class="bi bi-cash-coin fs-1"></i>
        <p class="mb-0 mt-2">You have not applied for any loan yet.</p>
    </div>
<?php endif; ?>

<?php foreach ($loans as $loan): $detail = loan_detail((int) $loan['id']); ?>
    <div class="card mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <div>
                <strong><?= e($loan['loan_no']) ?></strong>
                <span class="text-muted ms-2"><?= money($loan['amount']) ?> · <?= (int) $loan['term_months'] ?> months · <?= (float) $loan['interest_rate'] ?>%</span>
            </div>
            <?= status_badge($loan['status']) ?>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-md-4"><div class="text-muted small">Total repayable</div><div class="fw-semibold"><?= money($loan['total_repayable']) ?></div></div>
                <div class="col-md-4"><div class="text-muted small">Outstanding</div><div class="fw-semibold"><?= money($loan['outstanding']) ?></div></div>
                <div class="col-md-4"><div class="text-muted small">Applied</div><div class="fw-semibold"><?= e(fmt_date($loan['applied_at'])) ?></div></div>
            </div>
            <?php if ($loan['remarks']): ?>
                <p class="small text-muted mb-3"><strong>Remarks:</strong> <?= e($loan['remarks']) ?></p>
            <?php endif; ?>

            <?php if ($detail['schedule'] !== []): ?>
                <h6 class="text-muted text-uppercase small">Repayment schedule</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>#</th><th>Due date</th><th class="text-end">Amount due</th><th class="text-end">Paid</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($detail['schedule'] as $inst): ?>
                            <tr>
                                <td><?= (int) $inst['installment'] ?></td>
                                <td><?= e(fmt_date($inst['due_date'])) ?></td>
                                <td class="text-end"><?= money($inst['amount_due']) ?></td>
                                <td class="text-end"><?= money($inst['amount_paid']) ?></td>
                                <td><?= status_badge($inst['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (in_array($loan['status'], ['disbursed', 'defaulted'], true)): ?>
                <form method="post" class="row g-2 align-items-end border-top pt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="loan_id" value="<?= (int) $loan['id'] ?>">
                    <div class="col-md-3">
                        <label class="form-label small mb-0">Amount</label>
                        <input type="number" step="0.01" min="1" max="<?= e((string) $loan['outstanding']) ?>" class="form-control" name="amount" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-0">Method</label>
                        <select class="form-select" name="method">
                            <option value="bank_transfer">Bank transfer</option>
                            <option value="paystack">Paystack</option>
                            <option value="remita">Remita</option>
                            <option value="cash">Cash</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-0">Reference</label>
                        <input class="form-control" name="reference">
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-primary w-100"><i class="bi bi-cash-stack"></i> Repay</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
<?php render_footer(); ?>
