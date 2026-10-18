<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();
ensure_default_accounts();

$accounts = list_accounts('', '', false);
$accountId = (int) get('account', 0);
if ($accountId <= 0 && $accounts !== []) {
    $accountId = (int) $accounts[0]['id'];
}
$account = $accountId > 0 ? fetch_one('SELECT * FROM accounts WHERE id = ?', [$accountId]) : null;

$from = (string) get('from', '');
$to = (string) get('to', '');

$opening = 0.0;
$rows = [];
$closing = 0.0;
if ($account) {
    if ($from !== '') {
        $opening = (float) fetch_value(
            'SELECT COALESCE(SUM(jl.debit),0) - COALESCE(SUM(jl.credit),0)
             FROM journal_lines jl JOIN journals j ON j.id = jl.journal_id
             WHERE jl.account_id = ? AND j.entry_date < ?',
            [$accountId, $from]
        );
    }
    $rows = ledger_entries($accountId, $from !== '' ? $from : null, $to !== '' ? $to : null);
    $closing = $opening;
    foreach ($rows as $i => $row) {
        $closing += (float) $row['debit'] - (float) $row['credit'];
        $rows[$i]['running'] = $closing;
    }
}

render_header('General Ledger', $user, [
    'actions' => '<button class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>',
]);
render_tabs(accounting_tabs(), 'ledger');
?>
<div class="card p-4 mb-4 no-print">
    <form class="row g-3 align-items-end" method="get">
        <div class="col-md-5">
            <label class="form-label">Account</label>
            <select class="form-select" name="account" onchange="this.form.submit()">
                <?php foreach ($accounts as $a): ?>
                    <option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === $accountId ? 'selected' : '' ?>>
                        <?= e($a['code'] . ' — ' . $a['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">From</label>
            <input class="form-control" type="date" name="from" value="<?= e($from) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">To</label>
            <input class="form-control" type="date" name="to" value="<?= e($to) ?>">
        </div>
        <div class="col-md-3">
            <button class="btn btn-primary"><i class="bi bi-funnel"></i> Apply</button>
            <a class="btn btn-outline-secondary" href="/admin/ledger.php?account=<?= $accountId ?>">Reset</a>
        </div>
    </form>
</div>

<?php if (!$account): ?>
    <div class="alert alert-info">No accounts available.</div>
<?php else: ?>
<div class="card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 class="mb-0"><?= e($account['code'] . ' — ' . $account['name']) ?></h5>
            <span class="text-muted small"><?= e(ACCOUNT_TYPES[$account['type']]) ?> &middot; normal <?= e($account['normal']) ?></span>
        </div>
        <div class="text-end">
            <div class="text-muted small">Closing balance</div>
            <div class="h5 mb-0"><?= money(abs($closing)) ?> <span class="text-muted small"><?= $closing >= 0 ? 'Dr' : 'Cr' ?></span></div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Date</th><th>Entry</th><th>Narration</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead>
            <tbody>
            <?php if ($from !== '' || $opening != 0.0): ?>
                <tr class="table-light"><td colspan="5" class="text-end fw-semibold">Opening balance</td><td class="text-end fw-semibold"><?= money(abs($opening)) ?></td></tr>
            <?php endif; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="text-center text-muted">No postings in this period.</td></tr>
            <?php else: foreach ($rows as $row): ?>
                <tr>
                    <td><?= e(date('d M Y', strtotime($row['entry_date']))) ?></td>
                    <td><code><?= e($row['entry_no']) ?></code></td>
                    <td><?= e((string) ($row['memo'] ?: $row['narration'])) ?> <?= $row['reference'] ? '<span class="text-muted small">(' . e($row['reference']) . ')</span>' : '' ?></td>
                    <td class="text-end"><?= (float) $row['debit'] > 0 ? money($row['debit']) : '' ?></td>
                    <td class="text-end"><?= (float) $row['credit'] > 0 ? money($row['credit']) : '' ?></td>
                    <td class="text-end"><?= money(abs($row['running'])) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php render_footer(); ?>
