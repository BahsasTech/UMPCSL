<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'create') {
    require_csrf();
    try {
        $data = [
            'first_name' => (string) post('first_name'),
            'last_name' => (string) post('last_name'),
            'other_names' => (string) post('other_names'),
            'email' => (string) post('email'),
            'phone' => (string) post('phone'),
            'gender' => (string) post('gender'),
            'date_of_birth' => (string) post('date_of_birth'),
            'marital_status' => (string) post('marital_status'),
            'address' => (string) post('address'),
            'occupation' => (string) post('occupation'),
            'monthly_income' => (string) post('monthly_income'),
            'bank_name' => (string) post('bank_name'),
            'bank_account_name' => (string) post('bank_account_name'),
            'bank_account_no' => (string) post('bank_account_no'),
            'password' => (string) post('password'),
        ];
        $userId = register_member($data);
        $member = member_for_user($userId);
        $opening = to_amount(post('opening_savings', 0));
        if ($opening > 0) {
            record_savings_contribution((int) $member['id'], 'monthly', $opening, [
                'method' => 'cash',
                'narration' => 'Opening savings balance',
            ]);
        }
        audit('member_created', 'members', (int) $member['id'], $data['email'] . ' created by staff');
        safe_notify_member((int) $member['id'], 'welcome');
        flash('success', 'Member ' . $member['membership_no'] . ' created.');
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/admin/members.php');
}

$search = (string) get('search', '');
$status = (string) get('status', '');
$members = list_members($search, $status);

render_header('Members', $user, [
    'actions' => '<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newMember"><i class="bi bi-person-plus"></i> New member</button>'
        . '<a class="btn btn-outline-success btn-sm" href="/export.php?type=members&search=' . urlencode($search) . '&status=' . urlencode($status) . '"><i class="bi bi-filetype-csv"></i> Export</a>',
]);
?>
<div class="card p-4">
    <form class="row g-2 mb-3" method="get">
        <div class="col-md-6"><input class="form-control" name="search" value="<?= e($search) ?>" placeholder="Search by name, membership no. or email"></div>
        <div class="col-md-3">
            <select class="form-select" name="status">
                <option value="">All statuses</option>
                <?php foreach (['active', 'inactive'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-outline-primary flex-grow-1"><i class="bi bi-search"></i> Filter</button>
            <a class="btn btn-outline-secondary" href="/admin/members.php">Reset</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr><th>Membership</th><th>Name</th><th>Contact</th><th class="text-end">Savings</th><th class="text-end">Loan outstanding</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php if ($members === []): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No members found.</td></tr>
            <?php else: foreach ($members as $m): ?>
                <tr>
                    <td><?= e($m['membership_no']) ?></td>
                    <td><?= e($m['first_name'] . ' ' . $m['last_name']) ?></td>
                    <td><span class="small"><?= e($m['email']) ?><br><?= e($m['user_phone'] ?? '') ?></span></td>
                    <td class="text-end"><?= money($m['savings_total']) ?></td>
                    <td class="text-end"><?= money($m['loan_outstanding']) ?></td>
                    <td><?= status_badge($m['status']) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/admin/member-view.php?id=<?= (int) $m['id'] ?>">Open</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="newMember" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-header"><h5 class="modal-title">Register new member</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">First name</label><input class="form-control" name="first_name" required></div>
                    <div class="col-md-4"><label class="form-label">Last name</label><input class="form-control" name="last_name" required></div>
                    <div class="col-md-4"><label class="form-label">Other names</label><input class="form-control" name="other_names"></div>
                    <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" required></div>
                    <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone"></div>
                    <div class="col-md-4"><label class="form-label">Gender</label>
                        <select class="form-select" name="gender"><option value="">Select…</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Date of birth</label><input type="date" class="form-control" name="date_of_birth"></div>
                    <div class="col-md-4"><label class="form-label">Marital status</label>
                        <select class="form-select" name="marital_status"><option value="">Select…</option><option value="single">Single</option><option value="married">Married</option><option value="divorced">Divorced</option><option value="widowed">Widowed</option></select>
                    </div>
                    <div class="col-md-8"><label class="form-label">Address</label><input class="form-control" name="address"></div>
                    <div class="col-md-4"><label class="form-label">Occupation</label><input class="form-control" name="occupation"></div>
                    <div class="col-md-4"><label class="form-label">Monthly income</label><input type="number" step="0.01" class="form-control" name="monthly_income"></div>
                    <div class="col-md-4"><label class="form-label">Bank</label><input class="form-control" name="bank_name"></div>
                    <div class="col-md-4"><label class="form-label">Account name</label><input class="form-control" name="bank_account_name"></div>
                    <div class="col-md-4"><label class="form-label">Account number</label><input class="form-control" name="bank_account_no"></div>
                    <div class="col-md-4"><label class="form-label">Opening savings</label><input type="number" step="0.01" class="form-control" name="opening_savings" value="0"></div>
                    <div class="col-md-4"><label class="form-label">Password</label><input type="text" class="form-control" name="password" value="password123" required></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary">Create member</button>
            </div>
        </form>
    </div>
</div>
<?php render_footer(); ?>
