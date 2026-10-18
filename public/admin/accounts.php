<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();
ensure_default_accounts();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_login(['admin']);
    $action = (string) post('action', '');
    $do = (string) post('do', '');
    if ($do === 'toggle' || $do === 'delete') {
        $action = $do;
    }
    try {
        if ($action === 'create') {
            $code = trim((string) post('code', ''));
            $name = trim((string) post('name', ''));
            $type = (string) post('type', '');
            if ($code === '' || $name === '') {
                throw new RuntimeException('Enter both an account code and a name.');
            }
            if (!array_key_exists($type, ACCOUNT_TYPES)) {
                throw new RuntimeException('Choose a valid account type.');
            }
            if (account_by_code($code)) {
                throw new RuntimeException('That account code already exists.');
            }
            db_insert('accounts', [
                'code' => $code, 'name' => $name, 'type' => $type,
                'normal' => normal_balance($type), 'is_system' => 0, 'is_active' => 1,
            ]);
            audit('account_created', 'accounts', 0, $code . ' ' . $name);
            flash('success', 'Account ' . $code . ' created.');
        } elseif ($action === 'rename') {
            $id = (int) post('id', 0);
            $account = fetch_one('SELECT * FROM accounts WHERE id = ?', [$id]);
            if (!$account) {
                throw new RuntimeException('Account not found.');
            }
            db_update('accounts', $id, ['name' => trim((string) post('name', $account['name']))]);
            flash('success', 'Account updated.');
        } elseif ($action === 'toggle') {
            $id = (int) post('id', 0);
            $account = fetch_one('SELECT * FROM accounts WHERE id = ?', [$id]);
            if (!$account) {
                throw new RuntimeException('Account not found.');
            }
            db_update('accounts', $id, ['is_active' => $account['is_active'] ? 0 : 1]);
            flash('success', 'Account ' . ($account['is_active'] ? 'deactivated' : 'reactivated') . '.');
        } elseif ($action === 'delete') {
            $id = (int) post('id', 0);
            $account = fetch_one('SELECT * FROM accounts WHERE id = ?', [$id]);
            if (!$account) {
                throw new RuntimeException('Account not found.');
            }
            if ($account['is_system']) {
                throw new RuntimeException('System accounts cannot be deleted.');
            }
            $lines = (int) fetch_value('SELECT COUNT(*) FROM journal_lines WHERE account_id = ?', [$id]);
            if ($lines > 0) {
                throw new RuntimeException('Account has postings and cannot be deleted; deactivate it instead.');
            }
            q('DELETE FROM accounts WHERE id = ?', [$id]);
            flash('success', 'Account deleted.');
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/admin/accounts.php');
}

$search = (string) get('q', '');
$typeFilter = (string) get('type', '');
$accounts = list_accounts($search, $typeFilter, false);

$grouped = [];
foreach ($accounts as $account) {
    $net = (float) $account['total_debit'] - (float) $account['total_credit'];
    $account['balance'] = $account['normal'] === 'debit' ? $net : -$net;
    $grouped[$account['type']][] = $account;
}

render_header('Chart of Accounts', $user);
render_tabs(accounting_tabs(), 'accounts');
?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Accounts</h5>
                <form class="d-flex gap-2 no-print" method="get">
                    <input class="form-control form-control-sm" type="search" name="q" value="<?= e($search) ?>" placeholder="Search code or name">
                    <select class="form-select form-select-sm" name="type" onchange="this.form.submit()">
                        <option value="">All types</option>
                        <?php foreach (ACCOUNT_TYPES as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $typeFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <?php foreach (ACCOUNT_TYPES as $type => $label): if (empty($grouped[$type])) { continue; } ?>
                <h6 class="text-uppercase text-muted small mt-3"><?= e($label) ?></h6>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light"><tr><th>Code</th><th>Name</th><th class="text-end">Balance</th><th class="text-end no-print"></th></tr></thead>
                        <tbody>
                        <?php foreach ($grouped[$type] as $account): ?>
                            <tr class="<?= $account['is_active'] ? '' : 'text-muted' ?>">
                                <td><code><?= e($account['code']) ?></code></td>
                                <td>
                                    <?= e($account['name']) ?>
                                    <?php if ($account['is_system']): ?><i class="bi bi-shield-lock text-muted ms-1" title="System account"></i><?php endif; ?>
                                    <?php if (!$account['is_active']): ?><span class="badge text-bg-secondary ms-1">inactive</span><?php endif; ?>
                                </td>
                                <td class="text-end"><?= money($account['balance']) ?></td>
                                <td class="text-end no-print">
                                    <?php if ($user['role'] === 'admin'): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="/admin/ledger.php?account=<?= (int) $account['id'] ?>"><i class="bi bi-book"></i></a>
                                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#edit<?= (int) $account['id'] ?>"><i class="bi bi-pencil"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($user['role'] === 'admin'): foreach ($grouped[$type] as $account): ?>
                    <div class="modal fade" id="edit<?= (int) $account['id'] ?>" tabindex="-1">
                        <div class="modal-dialog"><div class="modal-content">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="rename">
                                <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
                                <div class="modal-header"><h6 class="modal-title">Edit <?= e($account['code']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                <div class="modal-body">
                                    <label class="form-label">Account name</label>
                                    <input class="form-control" name="name" value="<?= e($account['name']) ?>" required>
                                    <p class="text-muted small mt-2 mb-0">Type: <?= e(ACCOUNT_TYPES[$account['type']]) ?> &middot; Normal side: <?= e($account['normal']) ?></p>
                                </div>
                                <div class="modal-footer justify-content-between">
                                    <div>
                                        <button class="btn btn-outline-secondary btn-sm" name="do" value="toggle"><?= $account['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                                        <?php if (!$account['is_system']): ?>
                                            <button class="btn btn-outline-danger btn-sm" name="do" value="delete" onclick="return confirm('Delete this account?')">Delete</button>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Close</button>
                                        <button class="btn btn-primary btn-sm">Save</button>
                                    </div>
                                </div>
                            </form>
                        </div></div>
                    </div>
                <?php endforeach; endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($user['role'] === 'admin'): ?>
    <div class="col-lg-4">
        <div class="card p-4">
            <h5 class="mb-3">Add account</h5>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="mb-3">
                    <label class="form-label">Code</label>
                    <input class="form-control" name="code" placeholder="e.g. 4200" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input class="form-control" name="name" placeholder="e.g. Donation Income" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Type</label>
                    <select class="form-select" name="type" required>
                        <?php foreach (ACCOUNT_TYPES as $key => $label): ?>
                            <option value="<?= e($key) ?>"><?= e($label) ?> (normal <?= e(normal_balance($key)) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> Create account</button>
            </form>
            <p class="text-muted small mt-3 mb-0">System accounts (shield icon) are created automatically and power the automatic postings from savings, loans and payments.</p>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
