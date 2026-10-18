<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();
ensure_default_accounts();

$sources = [
    'manual'               => 'Manual entry',
    'savings_contribution' => 'Savings contribution',
    'savings_withdrawal'   => 'Savings withdrawal',
    'loan_disbursement'    => 'Loan disbursement',
    'loan_repayment'       => 'Loan repayment',
    'other_income'         => 'Fee & other income',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_login(['admin']);
    try {
        if (post('action') === 'create') {
            $codes = (array) ($_POST['line_code'] ?? []);
            $debits = (array) ($_POST['line_debit'] ?? []);
            $credits = (array) ($_POST['line_credit'] ?? []);
            $memos = (array) ($_POST['line_memo'] ?? []);
            $lines = [];
            foreach ($codes as $i => $code) {
                $lines[] = [
                    'code'   => trim((string) $code),
                    'debit'  => $debits[$i] ?? 0,
                    'credit' => $credits[$i] ?? 0,
                    'memo'   => $memos[$i] ?? null,
                ];
            }
            $id = post_journal([
                'entry_date' => (string) post('entry_date', date('Y-m-d')),
                'narration'  => (string) post('narration', ''),
                'reference'  => (string) post('reference', '') ?: null,
                'source'     => 'manual',
                'lines'      => $lines,
            ]);
            $entry = fetch_one('SELECT entry_no FROM journals WHERE id = ?', [$id]);
            audit('journal_posted', 'journals', $id, (string) $entry['entry_no']);
            flash('success', 'Journal entry ' . $entry['entry_no'] . ' posted.');
            redirect('/admin/journal.php?id=' . $id);
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
        redirect('/admin/journal.php?action=new');
    }
}

$viewId = (int) get('id', 0);
$showNew = get('action') === 'new';
$journal = $viewId > 0 ? journal_detail($viewId) : null;

$filters = [
    'source' => (string) get('source', ''),
    'from'   => (string) get('from', ''),
    'to'     => (string) get('to', ''),
    'search' => (string) get('q', ''),
];
$journals = list_journals($filters);
$accountOptions = list_accounts('', '', true);

render_header('Journal Entries', $user, [
    'actions' => $user['role'] === 'admin'
        ? '<a class="btn btn-primary btn-sm" href="/admin/journal.php?action=new"><i class="bi bi-plus-lg"></i> New entry</a>'
        : '',
]);
render_tabs(accounting_tabs(), 'journal');

if ($journal):
?>
<div class="card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h5 class="mb-1"><?= e($journal['entry_no']) ?> <span class="badge text-bg-light"><?= e($sources[$journal['source']] ?? label((string) $journal['source'])) ?></span></h5>
            <div class="text-muted small"><?= e(date('d M Y', strtotime($journal['entry_date']))) ?><?= $journal['reference'] ? ' &middot; Ref: ' . e($journal['reference']) : '' ?></div>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="/admin/journal.php"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
    <p><?= e($journal['narration']) ?></p>
    <table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>Account</th><th>Memo</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
        <tbody>
        <?php $td = 0.0; $tc = 0.0; foreach ($journal['lines'] as $line): $td += (float) $line['debit']; $tc += (float) $line['credit']; ?>
            <tr>
                <td><code><?= e($line['code']) ?></code> <?= e($line['name']) ?></td>
                <td class="text-muted small"><?= e((string) $line['memo']) ?></td>
                <td class="text-end"><?= (float) $line['debit'] > 0 ? money($line['debit']) : '' ?></td>
                <td class="text-end"><?= (float) $line['credit'] > 0 ? money($line['credit']) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="fw-semibold"><tr><td colspan="2" class="text-end">Totals</td><td class="text-end"><?= money($td) ?></td><td class="text-end"><?= money($tc) ?></td></tr></tfoot>
    </table>
</div>
<?php endif; ?>

<?php if ($showNew && $user['role'] === 'admin'): ?>
<div class="card p-4 mb-4">
    <h5 class="mb-3">New journal entry</h5>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <label class="form-label">Date</label>
                <input type="date" class="form-control" name="entry_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Reference</label>
                <input class="form-control" name="reference" placeholder="Optional reference">
            </div>
            <div class="col-md-5">
                <label class="form-label">Narration</label>
                <input class="form-control" name="narration" placeholder="Description of the entry">
            </div>
        </div>
        <div class="table-responsive">
            <table class="table align-middle" id="linesTable">
                <thead class="table-light"><tr><th style="min-width:220px">Account</th><th>Memo</th><th style="width:150px">Debit</th><th style="width:150px">Credit</th><th></th></tr></thead>
                <tbody>
                    <?php for ($r = 0; $r < 2; $r++): ?>
                    <tr>
                        <td>
                            <select class="form-select form-select-sm" name="line_code[]">
                                <option value="">Select account…</option>
                                <?php foreach ($accountOptions as $a): ?>
                                    <option value="<?= e($a['code']) ?>"><?= e($a['code'] . ' — ' . $a['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input class="form-control form-control-sm" name="line_memo[]"></td>
                        <td><input class="form-control form-control-sm text-end" type="number" step="0.01" min="0" name="line_debit[]" value=""></td>
                        <td><input class="form-control form-control-sm text-end" type="number" step="0.01" min="0" name="line_credit[]" value=""></td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-x"></i></button></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <div class="d-flex justify-content-between">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addLine()"><i class="bi bi-plus-lg"></i> Add line</button>
            <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Post entry</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Recent entries</h5>
        <form class="d-flex gap-2 no-print" method="get">
            <input class="form-control form-control-sm" type="search" name="q" value="<?= e($filters['search']) ?>" placeholder="Search">
            <select class="form-select form-select-sm" name="source" onchange="this.form.submit()">
                <option value="">All sources</option>
                <?php foreach ($sources as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $filters['source'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <input class="form-control form-control-sm" type="date" name="from" value="<?= e($filters['from']) ?>">
            <input class="form-control form-control-sm" type="date" name="to" value="<?= e($filters['to']) ?>">
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Entry</th><th>Date</th><th>Narration</th><th>Source</th><th class="text-end">Amount</th><th></th></tr></thead>
            <tbody>
            <?php if ($journals === []): ?>
                <tr><td colspan="6" class="text-center text-muted">No journal entries yet.</td></tr>
            <?php else: foreach ($journals as $j): ?>
                <tr>
                    <td><code><?= e($j['entry_no']) ?></code></td>
                    <td><?= e(date('d M Y', strtotime($j['entry_date']))) ?></td>
                    <td><?= e((string) $j['narration']) ?></td>
                    <td><span class="badge text-bg-light"><?= e($sources[$j['source']] ?? label((string) $j['source'])) ?></span></td>
                    <td class="text-end"><?= money($j['amount']) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/admin/journal.php?id=<?= (int) $j['id'] ?>"><i class="bi bi-eye"></i></a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <a class="btn btn-sm btn-outline-success mt-3 no-print" href="/export.php?type=journal"><i class="bi bi-filetype-csv"></i> Export CSV</a>
</div>

<script>
function addLine() {
    const tbody = document.querySelector('#linesTable tbody');
    const row = tbody.rows[0].cloneNode(true);
    row.querySelectorAll('input').forEach(i => i.value = '');
    row.querySelector('select').selectedIndex = 0;
    tbody.appendChild(row);
}
</script>
<?php render_footer(); ?>
