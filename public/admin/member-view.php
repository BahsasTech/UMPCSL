<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();
$member = get_member((int) get('id', 0));
if (!$member) {
    http_response_code(404);
    exit('Member not found.');
}
$memberId = (int) $member['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) post('action', '');
    try {
        switch ($action) {
            case 'profile':
                update_member_profile($memberId, [
                    'first_name' => post('first_name'), 'last_name' => post('last_name'),
                    'other_names' => post('other_names'), 'gender' => post('gender'),
                    'date_of_birth' => post('date_of_birth'), 'marital_status' => post('marital_status'),
                    'address' => post('address'), 'occupation' => post('occupation'),
                    'monthly_income' => post('monthly_income'), 'bank_name' => post('bank_name'),
                    'bank_account_name' => post('bank_account_name'), 'bank_account_no' => post('bank_account_no'),
                ]);
                flash('success', 'Member profile updated.');
                break;
            case 'status':
                $new = post('status') === 'inactive' ? 'inactive' : 'active';
                db_update('members', $memberId, ['status' => $new]);
                audit('member_status', 'members', $memberId, 'Status set to ' . $new);
                flash('success', 'Member status changed to ' . $new . '.');
                break;
            case 'savings':
                $result = record_savings_contribution($memberId, (string) post('type'), to_amount(post('amount', 0)), [
                    'method' => (string) post('method', 'cash'),
                    'reference' => (string) post('reference', ''),
                    'narration' => (string) post('narration', 'Staff-recorded contribution'),
                ]);
                safe_notify_member($memberId, 'savings_contribution', [
                    'amount'     => money(to_amount(post('amount', 0))),
                    'receipt_no' => $result['receipt_no'],
                ]);
                flash('success', 'Contribution posted. Receipt ' . $result['receipt_no'] . '.');
                break;
            case 'withdrawal':
                record_savings_withdrawal($memberId, (string) post('type'), to_amount(post('amount', 0)));
                flash('success', 'Withdrawal posted.');
                break;
            case 'repayment':
                $loanId = (int) post('loan_id', 0);
                $result = record_loan_repayment($loanId, to_amount(post('amount', 0)), [
                    'method' => (string) post('method', 'cash'),
                    'reference' => (string) post('reference', ''),
                ]);
                $repaidLoan = fetch_one('SELECT loan_no FROM loans WHERE id = ?', [$loanId]);
                safe_notify_member($memberId, 'repayment_received', [
                    'loan_no'     => $repaidLoan['loan_no'] ?? '',
                    'amount'      => money(to_amount(post('amount', 0))),
                    'outstanding' => money($result['outstanding']),
                    'receipt_no'  => $result['receipt_no'],
                ]);
                flash('success', 'Repayment posted. Receipt ' . $result['receipt_no'] . '.');
                break;
            case 'referral':
                db_update('referrals', (int) post('referral_id', 0), ['status' => (string) post('referral_status', 'pending')]);
                flash('success', 'Referral updated.');
                break;
            case 'password':
                $new = (string) post('password', '');
                if (strlen($new) < 6) {
                    throw new RuntimeException('Password must be at least 6 characters.');
                }
                db_update('users', (int) $member['user_id'], ['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);
                audit('password_reset', 'users', (int) $member['user_id'], 'Reset by staff');
                flash('success', 'Member password reset.');
                break;
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/admin/member-view.php?id=' . $memberId);
}

$summary = savings_summary($memberId);
$loans = member_loans($memberId);
$payments = member_payment_history($memberId, 50);
$nok = fetch_all('SELECT * FROM next_of_kin WHERE member_id = ?', [$memberId]);
$nominees = fetch_all('SELECT * FROM nominees WHERE member_id = ?', [$memberId]);
$referrals = fetch_all('SELECT * FROM referrals WHERE member_id = ? ORDER BY id DESC', [$memberId]);
$activeLoans = array_filter($loans, fn($l) => in_array($l['status'], ['disbursed', 'defaulted'], true));

render_header('Member · ' . $member['membership_no'], $user, [
    'actions' => '<a href="/admin/members.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> All members</a>',
]);
?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card p-4 text-center mb-4">
            <img src="<?= e(photo_url($member['passport_photo'])) ?>" class="passport-photo mx-auto mb-3" alt="Passport">
            <div class="fw-semibold"><?= e($member['first_name'] . ' ' . $member['last_name']) ?></div>
            <div class="text-muted small"><?= e($member['email']) ?></div>
            <div class="mt-2"><?= status_badge($member['status']) ?></div>
            <form method="post" class="mt-3 d-flex gap-2" data-confirm="Change this member's status?">
                <?= csrf_field() ?><input type="hidden" name="action" value="status">
                <input type="hidden" name="status" value="<?= $member['status'] === 'active' ? 'inactive' : 'active' ?>">
                <button class="btn btn-sm btn-outline-<?= $member['status'] === 'active' ? 'danger' : 'success' ?> w-100">
                    <i class="bi bi-power"></i> <?= $member['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                </button>
            </form>
        </div>

        <div class="card p-4 mb-4">
            <h6 class="mb-3">Record savings</h6>
            <form method="post" class="row g-2">
                <?= csrf_field() ?><input type="hidden" name="action" value="savings">
                <div class="col-12"><select class="form-select form-select-sm" name="type"><?php foreach (SAVINGS_PRODUCTS as $t => $n): ?><option value="<?= $t ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><input type="number" step="0.01" class="form-control form-control-sm" name="amount" placeholder="Amount" required></div>
                <div class="col-6"><select class="form-select form-select-sm" name="method"><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="paystack">Paystack</option><option value="remita">Remita</option></select></div>
                <div class="col-12"><input class="form-control form-control-sm" name="reference" placeholder="Reference"></div>
                <div class="col-12"><button class="btn btn-sm btn-primary w-100">Post contribution</button></div>
            </form>
            <hr>
            <h6 class="mb-2">Post withdrawal</h6>
            <form method="post" class="row g-2" data-confirm="Confirm withdrawal from member savings?">
                <?= csrf_field() ?><input type="hidden" name="action" value="withdrawal">
                <div class="col-7"><select class="form-select form-select-sm" name="type"><?php foreach (SAVINGS_PRODUCTS as $t => $n): ?><option value="<?= $t ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
                <div class="col-5"><input type="number" step="0.01" class="form-control form-control-sm" name="amount" placeholder="Amount" required></div>
                <div class="col-12"><button class="btn btn-sm btn-outline-danger w-100">Post withdrawal</button></div>
            </form>
        </div>

        <div class="card p-4">
            <h6 class="mb-3">Reset password</h6>
            <form method="post" class="row g-2">
                <?= csrf_field() ?><input type="hidden" name="action" value="password">
                <div class="col-8"><input class="form-control form-control-sm" name="password" placeholder="New password" required></div>
                <div class="col-4"><button class="btn btn-sm btn-outline-secondary w-100">Reset</button></div>
            </form>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card p-4 mb-4">
            <h5 class="mb-3">Savings position</h5>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Product</th><th class="text-end">Balance</th><th class="text-end">Target</th></tr></thead>
                    <tbody>
                    <?php foreach (SAVINGS_PRODUCTS as $t => $n): ?>
                        <tr><td><?= e($n) ?></td><td class="text-end"><?= money($summary['accounts'][$t]['balance'] ?? 0) ?></td><td class="text-end text-muted"><?= money($summary['accounts'][$t]['target_amount'] ?? 0) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light"><tr><th>Total</th><th class="text-end"><?= money($summary['total']) ?></th><th></th></tr></tfoot>
                </table>
            </div>
        </div>

        <div class="card p-4 mb-4">
            <h5 class="mb-3">Loans</h5>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Loan no.</th><th class="text-end">Amount</th><th class="text-end">Outstanding</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if ($loans === []): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">No loans.</td></tr>
                    <?php else: foreach ($loans as $l): ?>
                        <tr>
                            <td><?= e($l['loan_no']) ?></td>
                            <td class="text-end"><?= money($l['amount']) ?></td>
                            <td class="text-end"><?= money($l['outstanding']) ?></td>
                            <td><?= status_badge($l['status']) ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/admin/loan-view.php?id=<?= (int) $l['id'] ?>">Open</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($activeLoans !== []): ?>
                <hr>
                <h6 class="mb-2">Record loan repayment</h6>
                <form method="post" class="row g-2">
                    <?= csrf_field() ?><input type="hidden" name="action" value="repayment">
                    <div class="col-md-4">
                        <select class="form-select form-select-sm" name="loan_id">
                            <?php foreach ($activeLoans as $l): ?><option value="<?= (int) $l['id'] ?>"><?= e($l['loan_no']) ?> · <?= money($l['outstanding']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3"><input type="number" step="0.01" class="form-control form-control-sm" name="amount" placeholder="Amount" required></div>
                    <div class="col-md-2"><select class="form-select form-select-sm" name="method"><option value="cash">Cash</option><option value="bank_transfer">Transfer</option><option value="paystack">Paystack</option><option value="remita">Remita</option></select></div>
                    <div class="col-md-3"><button class="btn btn-sm btn-primary w-100">Post repayment</button></div>
                </form>
            <?php endif; ?>
        </div>

        <div class="card p-4 mb-4">
            <h5 class="mb-3">Payment history</h5>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Date</th><th>Receipt</th><th>Type</th><th class="text-end">Amount</th><th></th></tr></thead>
                    <tbody>
                    <?php if ($payments === []): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">No payments.</td></tr>
                    <?php else: foreach ($payments as $p): ?>
                        <tr>
                            <td><?= e(fmt_date($p['paid_at'])) ?></td><td><?= e($p['receipt_no']) ?></td>
                            <td><?= e(label($p['type'])) ?><?= $p['product'] ? ' · ' . e(label($p['product'])) : '' ?></td>
                            <td class="text-end"><?= money($p['amount']) ?></td>
                            <td class="text-end"><a class="small text-brand" href="/receipt.php?id=<?= (int) $p['id'] ?>">Receipt</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card p-4">
            <h5 class="mb-3">KYC · nominee · referral</h5>
            <div class="row g-3">
                <div class="col-md-4">
                    <h6 class="text-muted small text-uppercase">Next of kin</h6>
                    <ul class="small mb-0 ps-3"><?php foreach ($nok as $x): ?><li><?= e($x['name']) ?> (<?= e($x['relationship']) ?>)</li><?php endforeach; ?><?php if ($nok === []): ?><li class="text-muted">None</li><?php endif; ?></ul>
                </div>
                <div class="col-md-4">
                    <h6 class="text-muted small text-uppercase">Nominees</h6>
                    <ul class="small mb-0 ps-3"><?php foreach ($nominees as $x): ?><li><?= e($x['name']) ?> (<?= (float) $x['percentage'] ?>%)</li><?php endforeach; ?><?php if ($nominees === []): ?><li class="text-muted">None</li><?php endif; ?></ul>
                </div>
                <div class="col-md-4">
                    <h6 class="text-muted small text-uppercase">Referrals</h6>
                    <ul class="small mb-0 ps-3"><?php foreach ($referrals as $x): ?><li><?= e($x['referred_name']) ?> — <?= e(label($x['status'])) ?></li><?php endforeach; ?><?php if ($referrals === []): ?><li class="text-muted">None</li><?php endif; ?></ul>
                </div>
            </div>
        </div>
    </div>
</div>
<?php render_footer(); ?>
