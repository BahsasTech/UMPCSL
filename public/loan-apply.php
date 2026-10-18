<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = require_login(['member']);
$member = member_for_user((int) $user['id']);
$memberId = (int) $member['id'];

$eligibility = loan_eligibility($memberId);
$values = ['amount' => '', 'term_months' => '12', 'interest_rate' => (string) $eligibility['rate'], 'purpose' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    foreach ($values as $k => $_) {
        $values[$k] = (string) post($k, '');
    }
    $guarantors = $_POST['guarantors'] ?? [];
    $collateral = $_POST['collateral'] ?? [];
    try {
        $loanId = apply_for_loan($memberId, $values, is_array($guarantors) ? $guarantors : [], is_array($collateral) ? $collateral : []);
        $applied = fetch_one('SELECT loan_no, amount FROM loans WHERE id = ?', [$loanId]);
        if ($applied) {
            safe_notify_member($memberId, 'loan_applied', [
                'loan_no' => $applied['loan_no'],
                'amount'  => money($applied['amount']),
            ]);
        }
        flash('success', 'Your loan application has been submitted. You can track its status under My Loans.');
        redirect('/loans.php?highlight=' . $loanId);
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
}

render_header('Apply for a Loan', $user, [
    'actions' => '<a href="/loans.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> My loans</a>',
]);
?>
<?php if (!$eligibility['eligible']): ?>
    <div class="alert alert-warning">
        <strong>You are not currently eligible to apply.</strong>
        <ul class="mb-0 ps-3"><?php foreach ($eligibility['reasons'] as $reason): ?><li><?= e($reason) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post">
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card p-4 mb-4">
                <h5 class="mb-3">Loan request</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Amount</label>
                        <input type="number" step="0.01" min="1" class="form-control" id="loan-amount" name="amount"
                               value="<?= e($values['amount']) ?>" max="<?= e((string) $eligibility['max_amount']) ?>" required>
                        <div class="form-text">Maximum eligible: <?= money($eligibility['max_amount']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Term (months)</label>
                        <input type="number" min="1" max="60" class="form-control" id="loan-term" name="term_months" value="<?= e($values['term_months']) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Interest rate (%)</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="loan-rate" name="interest_rate" value="<?= e($values['interest_rate']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Purpose</label>
                        <input class="form-control" name="purpose" value="<?= e($values['purpose']) ?>" placeholder="e.g. Business expansion, school fees">
                    </div>
                    <div class="col-12">
                        <div class="alert alert-secondary py-2 mb-0 small" id="loan-preview">Total repayable: —</div>
                    </div>
                </div>
            </div>

            <div class="card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Guarantors</h5>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="add-guarantor"><i class="bi bi-plus"></i> Add</button>
                </div>
                <p class="text-muted small">At least <?= (int) $eligibility['min_guarantors'] ?> guarantors are required.</p>
                <div id="guarantor-rows">
                    <?php for ($i = 0; $i < max(2, (int) $eligibility['min_guarantors']); $i++): ?>
                        <div class="row g-2 mb-2 guarantor-row">
                            <div class="col-md-4"><input class="form-control" name="guarantors[<?= $i ?>][name]" placeholder="Full name" required></div>
                            <div class="col-md-3"><input class="form-control" name="guarantors[<?= $i ?>][phone]" placeholder="Phone"></div>
                            <div class="col-md-3"><input class="form-control" name="guarantors[<?= $i ?>][relationship]" placeholder="Relationship"></div>
                            <div class="col-md-2"><input class="form-control" name="guarantors[<?= $i ?>][amount]" placeholder="Amount"></div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card p-4 mb-4">
                <h5 class="mb-3">Collateral (optional)</h5>
                <div class="mb-2">
                    <label class="form-label">Description</label>
                    <input class="form-control" name="collateral[0][description]" placeholder="e.g. Vehicle, property">
                </div>
                <div>
                    <label class="form-label">Estimated value</label>
                    <input type="number" step="0.01" min="0" class="form-control" name="collateral[0][value]">
                </div>
            </div>
            <div class="card p-4">
                <h5 class="mb-3">Summary</h5>
                <ul class="list-unstyled small mb-0">
                    <li class="d-flex justify-content-between"><span>Savings used for limit</span><strong><?= money($eligibility['savings']) ?></strong></li>
                    <li class="d-flex justify-content-between"><span>Borrowing multiple</span><strong><?= (float) $eligibility['multiple'] ?>x</strong></li>
                    <li class="d-flex justify-content-between"><span>Max amount</span><strong><?= money($eligibility['max_amount']) ?></strong></li>
                </ul>
            </div>
            <button class="btn btn-primary btn-lg w-100 mt-4" type="submit" <?= $eligibility['eligible'] ? '' : 'disabled' ?>>
                <i class="bi bi-send"></i> Submit application
            </button>
        </div>
    </div>
</form>
<?php render_footer(); ?>
