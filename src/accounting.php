<?php
declare(strict_types=1);

/**
 * Financial accounting: double-entry general ledger.
 *
 * Every money movement is recorded as a balanced journal entry (debits =
 * credits). Reports (trial balance, profit & loss, balance sheet) are derived
 * from those entries, so they always agree with the underlying transactions.
 *
 * System account codes used by the automatic postings:
 *   1000 Cash on Hand        1010 Bank Account        1100 Loans Receivable
 *   1200 Inventory
 *   2000 Members' Savings    3000 Share Capital       3200 Opening Balance Equity
 *   4000 Interest Income     4100 Fee & Other Income  4200 Store Sales
 *   5000 Operating Expenses  5100 Loan Loss Expense   5200 Cost of Goods Sold
 */

const ACCOUNT_TYPES = [
    'asset'     => 'Asset',
    'liability' => 'Liability',
    'equity'    => 'Equity',
    'revenue'   => 'Revenue',
    'expense'   => 'Expense',
];

/** The normal (increasing) side for an account type. */
function normal_balance(string $type): string
{
    return in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit';
}

/** Default chart of accounts installed on a fresh database. */
function default_chart_of_accounts(): array
{
    return [
        ['1000', 'Cash on Hand',            'asset',     'debit'],
        ['1010', 'Bank Account',            'asset',     'debit'],
        ['1100', 'Loans Receivable',        'asset',     'debit'],
        ['1200', 'Inventory',               'asset',     'debit'],
        ['2000', "Members' Savings",        'liability', 'credit'],
        ['3000', 'Share Capital',           'equity',    'credit'],
        ['3100', 'Statutory Reserve',       'equity',    'credit'],
        ['3200', 'Opening Balance Equity',  'equity',    'credit'],
        ['4000', 'Interest Income',         'revenue',   'credit'],
        ['4100', 'Fee & Other Income',      'revenue',   'credit'],
        ['4200', 'Store Sales',             'revenue',   'credit'],
        ['5000', 'Operating Expenses',      'expense',   'debit'],
        ['5100', 'Loan Loss Expense',       'expense',   'debit'],
        ['5200', 'Cost of Goods Sold',      'expense',   'debit'],
    ];
}

/** Create any missing default accounts (idempotent). */
function ensure_default_accounts(): void
{
    foreach (default_chart_of_accounts() as [$code, $name, $type, $normal]) {
        $exists = (int) fetch_value('SELECT COUNT(*) FROM accounts WHERE code = ?', [$code]);
        if ($exists === 0) {
            db_insert('accounts', [
                'code' => $code, 'name' => $name, 'type' => $type,
                'normal' => $normal, 'is_system' => 1, 'is_active' => 1,
            ]);
        }
    }
}

/** Look up an account row by code. */
function account_by_code(string $code): ?array
{
    return fetch_one('SELECT * FROM accounts WHERE code = ?', [$code]);
}

/** Return the id for an account code, or null when it does not exist. */
function account_id(string $code): ?int
{
    $id = fetch_value('SELECT id FROM accounts WHERE code = ?', [$code]);
    return $id === false ? null : (int) $id;
}

/** List accounts, optionally filtered. */
function list_accounts(string $search = '', string $type = '', bool $activeOnly = false): array
{
    $sql = 'SELECT a.*,
                   COALESCE(l.debit, 0) AS total_debit,
                   COALESCE(l.credit, 0) AS total_credit
            FROM accounts a
            LEFT JOIN (
                SELECT account_id, SUM(debit) AS debit, SUM(credit) AS credit
                FROM journal_lines GROUP BY account_id
            ) l ON l.account_id = a.id
            WHERE 1 = 1';
    $params = [];
    if ($search !== '') {
        $sql .= ' AND (a.code LIKE ? OR a.name LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like);
    }
    if ($type !== '' && array_key_exists($type, ACCOUNT_TYPES)) {
        $sql .= ' AND a.type = ?';
        $params[] = $type;
    }
    if ($activeOnly) {
        $sql .= ' AND a.is_active = 1';
    }
    $sql .= ' ORDER BY a.code';
    return fetch_all($sql, $params);
}

/** Net balance of an account (in its report column terms). */
function account_balance_raw(array $account, ?string $to = null, ?string $from = null): float
{
    $sql = 'SELECT COALESCE(SUM(debit),0) AS d, COALESCE(SUM(credit),0) AS c
            FROM journal_lines WHERE account_id = ?';
    $params = [(int) $account['id']];
    if ($from !== null) {
        $sql .= ' AND journal_id IN (SELECT id FROM journals WHERE entry_date >= ?)';
        $params[] = $from;
    }
    if ($to !== null) {
        $sql .= ' AND journal_id IN (SELECT id FROM journals WHERE entry_date <= ?)';
        $params[] = $to;
    }
    $row = fetch_one($sql, $params);
    return (float) $row['d'] - (float) $row['c'];
}

/**
 * Post a balanced journal entry. Lines may be given as
 * ['code' => ..., 'debit' => ..., 'credit' => ..., 'memo' => ...] or with
 * 'account_id' instead of 'code'. Throws RuntimeException when unbalanced.
 */
function post_journal(array $data): int
{
    $lines = [];
    foreach ($data['lines'] as $line) {
        $debit = to_amount($line['debit'] ?? 0);
        $credit = to_amount($line['credit'] ?? 0);
        if ($debit <= 0 && $credit <= 0) {
            continue;
        }
        $accountId = $line['account_id'] ?? (account_id((string) ($line['code'] ?? '')) ?? 0);
        if (!$accountId) {
            throw new RuntimeException('Unknown ledger account for a journal line.');
        }
        $lines[] = ['account_id' => (int) $accountId, 'debit' => $debit, 'credit' => $credit, 'memo' => $line['memo'] ?? null];
    }
    if ($lines === []) {
        throw new RuntimeException('A journal entry needs at least one line.');
    }
    $debitTotal = array_sum(array_column($lines, 'debit'));
    $creditTotal = array_sum(array_column($lines, 'credit'));
    if (abs($debitTotal - $creditTotal) > 0.005) {
        throw new RuntimeException('Journal entry is not balanced (debits ' . number_format($debitTotal, 2) . ' vs credits ' . number_format($creditTotal, 2) . ').');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $entryNo = next_reference('journals', 'entry_no', 'JV-' . date('y') . '-', 5);
        $journalId = db_insert('journals', [
            'entry_no'   => $entryNo,
            'entry_date' => $data['entry_date'] ?? date('Y-m-d'),
            'narration'  => isset($data['narration']) ? substr((string) $data['narration'], 0, 255) : null,
            'reference'  => $data['reference'] ?? null,
            'source'     => $data['source'] ?? null,
            'source_id'  => $data['source_id'] ?? null,
            'created_by' => $data['created_by'] ?? ($_SESSION['user_id'] ?? null),
        ]);
        foreach ($lines as $line) {
            db_insert('journal_lines', [
                'journal_id' => $journalId,
                'account_id' => $line['account_id'],
                'debit'      => $line['debit'],
                'credit'     => $line['credit'],
                'memo'       => $line['memo'],
            ]);
        }
        $pdo->commit();
        return $journalId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Post a journal entry, swallowing errors so money flows never break. */
function safe_post_journal(array $data): ?int
{
    try {
        return post_journal($data);
    } catch (Throwable $e) {
        return null;
    }
}

/** Cash-on-hand (1000) or Bank (1010) depending on the payment method. */
function cash_account_code(string $method): string
{
    return strtolower($method) === 'cash' ? '1000' : '1010';
}

/** Post a savings/share-capital contribution. */
function post_savings_entry(string $method, string $product, float $amount, ?string $reference, ?int $sourceId, ?string $date = null): ?int
{
    $creditCode = $product === 'share_capital' ? '3000' : '2000';
    return safe_post_journal([
        'entry_date' => $date ?? date('Y-m-d'),
        'narration'  => 'Savings contribution - ' . label($product),
        'reference'  => $reference,
        'source'     => 'savings_contribution',
        'source_id'  => $sourceId,
        'lines'      => [
            ['code' => cash_account_code($method), 'debit' => $amount],
            ['code' => $creditCode, 'credit' => $amount],
        ],
    ]);
}

/** Post a savings withdrawal. */
function post_savings_withdrawal_entry(string $method, string $product, float $amount, ?int $sourceId = null, ?string $date = null): ?int
{
    $debitCode = $product === 'share_capital' ? '3000' : '2000';
    return safe_post_journal([
        'entry_date' => $date ?? date('Y-m-d'),
        'narration'  => 'Savings withdrawal - ' . label($product),
        'source'     => 'savings_withdrawal',
        'source_id'  => $sourceId,
        'lines'      => [
            ['code' => $debitCode, 'debit' => $amount],
            ['code' => cash_account_code($method), 'credit' => $amount],
        ],
    ]);
}

/**
 * Post a loan disbursement. The principal is credited to Members' Savings
 * (disbursement funds the member's savings account) and the interest portion
 * of the total repayable is recognised as Interest Income.
 */
function post_loan_disbursement_entry(float $principal, float $totalRepayable, string $loanNo, int $loanId, ?string $date = null): ?int
{
    $interest = round($totalRepayable - $principal, 2);
    $lines = [
        ['code' => '1100', 'debit' => round($totalRepayable, 2)],
        ['code' => '2000', 'credit' => round($principal, 2)],
    ];
    if ($interest > 0) {
        $lines[] = ['code' => '4000', 'credit' => $interest];
    }
    return safe_post_journal([
        'entry_date' => $date ?? date('Y-m-d'),
        'narration'  => 'Loan disbursement ' . $loanNo,
        'reference'  => $loanNo,
        'source'     => 'loan_disbursement',
        'source_id'  => $loanId,
        'lines'      => $lines,
    ]);
}

/** Post a loan repayment. */
function post_loan_repayment_entry(string $method, float $amount, string $loanNo, int $loanId, ?int $paymentId = null, ?string $date = null): ?int
{
    return safe_post_journal([
        'entry_date' => $date ?? date('Y-m-d'),
        'narration'  => 'Loan repayment ' . $loanNo,
        'reference'  => $loanNo,
        'source'     => 'loan_repayment',
        'source_id'  => $paymentId,
        'lines'      => [
            ['code' => cash_account_code($method), 'debit' => $amount],
            ['code' => '1100', 'credit' => $amount],
        ],
    ]);
}

/** Post fee / other income. */
function post_other_income_entry(string $method, float $amount, ?string $reference, ?int $paymentId, ?string $date = null): ?int
{
    return safe_post_journal([
        'entry_date' => $date ?? date('Y-m-d'),
        'narration'  => 'Fee & other income',
        'reference'  => $reference,
        'source'     => 'other_income',
        'source_id'  => $paymentId,
        'lines'      => [
            ['code' => cash_account_code($method), 'debit' => $amount],
            ['code' => '4100', 'credit' => $amount],
        ],
    ]);
}

/** Post store sale revenue (Debit cash/bank, Credit Store Sales). */
function post_store_sale_entry(string $method, float $total, string $saleNo, int $saleId, ?string $date = null): ?int
{
    if ($total <= 0) {
        return null;
    }
    return safe_post_journal([
        'entry_date' => $date ?? date('Y-m-d'),
        'narration'  => 'Store sale ' . $saleNo,
        'reference'  => $saleNo,
        'source'     => 'store_sale',
        'source_id'  => $saleId,
        'lines'      => [
            ['code' => cash_account_code($method), 'debit' => $total],
            ['code' => '4200', 'credit' => $total],
        ],
    ]);
}

/** Post cost of goods sold (Debit Cost of Goods Sold, Credit Inventory). */
function post_cogs_entry(float $cost, string $saleNo, int $saleId, ?string $date = null): ?int
{
    if ($cost <= 0) {
        return null;
    }
    return safe_post_journal([
        'entry_date' => $date ?? date('Y-m-d'),
        'narration'  => 'Cost of goods sold ' . $saleNo,
        'reference'  => $saleNo,
        'source'     => 'cogs',
        'source_id'  => $saleId,
        'lines'      => [
            ['code' => '5200', 'debit' => $cost],
            ['code' => '1200', 'credit' => $cost],
        ],
    ]);
}

/* =====================================================================
 * Reports
 * ===================================================================*/

/** Raw debit/credit totals per account, optionally within a date range. */
function account_sums(?string $from = null, ?string $to = null): array
{
    $sql = 'SELECT jl.account_id, COALESCE(SUM(jl.debit),0) AS debit, COALESCE(SUM(jl.credit),0) AS credit
            FROM journal_lines jl JOIN journals j ON j.id = jl.journal_id
            WHERE 1 = 1';
    $params = [];
    if ($from !== null) {
        $sql .= ' AND j.entry_date >= ?';
        $params[] = $from;
    }
    if ($to !== null) {
        $sql .= ' AND j.entry_date <= ?';
        $params[] = $to;
    }
    $sql .= ' GROUP BY jl.account_id';
    $rows = fetch_all($sql, $params);
    $out = [];
    foreach ($rows as $row) {
        $out[(int) $row['account_id']] = ['debit' => (float) $row['debit'], 'credit' => (float) $row['credit']];
    }
    return $out;
}

/**
 * Trial balance as of a date. Each account balance is placed on its report
 * side (net debit shown in the debit column, net credit in the credit column).
 */
function trial_balance(?string $asOf = null): array
{
    $accounts = list_accounts('', '', false);
    $sums = account_sums(null, $asOf);
    $rows = [];
    $totalDebit = 0.0;
    $totalCredit = 0.0;
    foreach ($accounts as $account) {
        $sum = $sums[(int) $account['id']] ?? ['debit' => 0.0, 'credit' => 0.0];
        $net = $sum['debit'] - $sum['credit'];
        if (abs($net) < 0.005) {
            continue;
        }
        $debit = $net > 0 ? $net : 0.0;
        $credit = $net < 0 ? -$net : 0.0;
        $rows[] = [
            'code' => $account['code'], 'name' => $account['name'], 'type' => $account['type'],
            'debit' => $debit, 'credit' => $credit,
        ];
        $totalDebit += $debit;
        $totalCredit += $credit;
    }
    return ['rows' => $rows, 'total_debit' => $totalDebit, 'total_credit' => $totalCredit];
}

/** Profit & loss (income statement) for a period. */
function income_statement(?string $from = null, ?string $to = null): array
{
    $accounts = list_accounts('', '', false);
    $sums = account_sums($from, $to);
    $revenue = [];
    $expenses = [];
    $totalRevenue = 0.0;
    $totalExpense = 0.0;
    foreach ($accounts as $account) {
        if (!in_array($account['type'], ['revenue', 'expense'], true)) {
            continue;
        }
        $sum = $sums[(int) $account['id']] ?? ['debit' => 0.0, 'credit' => 0.0];
        if ($account['type'] === 'revenue') {
            $balance = $sum['credit'] - $sum['debit'];
            if (abs($balance) >= 0.005) {
                $revenue[] = ['code' => $account['code'], 'name' => $account['name'], 'amount' => $balance];
                $totalRevenue += $balance;
            }
        } else {
            $balance = $sum['debit'] - $sum['credit'];
            if (abs($balance) >= 0.005) {
                $expenses[] = ['code' => $account['code'], 'name' => $account['name'], 'amount' => $balance];
                $totalExpense += $balance;
            }
        }
    }
    return [
        'revenue'       => $revenue,
        'expenses'      => $expenses,
        'total_revenue' => $totalRevenue,
        'total_expense' => $totalExpense,
        'net'           => $totalRevenue - $totalExpense,
    ];
}

/** Balance sheet as of a date (retained earnings include profit to date). */
function balance_sheet(?string $asOf = null): array
{
    $accounts = list_accounts('', '', false);
    $sums = account_sums(null, $asOf);
    $assets = [];
    $liabilities = [];
    $equity = [];
    $totalAssets = $totalLiabilities = $totalEquity = 0.0;
    $revenueTotal = $expenseTotal = 0.0;

    foreach ($accounts as $account) {
        $sum = $sums[(int) $account['id']] ?? ['debit' => 0.0, 'credit' => 0.0];
        switch ($account['type']) {
            case 'asset':
                $balance = $sum['debit'] - $sum['credit'];
                if (abs($balance) >= 0.005) {
                    $assets[] = ['code' => $account['code'], 'name' => $account['name'], 'amount' => $balance];
                    $totalAssets += $balance;
                }
                break;
            case 'liability':
                $balance = $sum['credit'] - $sum['debit'];
                if (abs($balance) >= 0.005) {
                    $liabilities[] = ['code' => $account['code'], 'name' => $account['name'], 'amount' => $balance];
                    $totalLiabilities += $balance;
                }
                break;
            case 'equity':
                $balance = $sum['credit'] - $sum['debit'];
                if (abs($balance) >= 0.005) {
                    $equity[] = ['code' => $account['code'], 'name' => $account['name'], 'amount' => $balance];
                    $totalEquity += $balance;
                }
                break;
            case 'revenue':
                $revenueTotal += $sum['credit'] - $sum['debit'];
                break;
            case 'expense':
                $expenseTotal += $sum['debit'] - $sum['credit'];
                break;
        }
    }

    $retained = $revenueTotal - $expenseTotal;
    $totalEquityWithRetained = $totalEquity + $retained;

    return [
        'assets'       => $assets,
        'liabilities'  => $liabilities,
        'equity'       => $equity,
        'total_assets' => $totalAssets,
        'total_liabilities' => $totalLiabilities,
        'total_equity' => $totalEquity,
        'retained'     => $retained,
        'total_equity_and_retained' => $totalEquityWithRetained,
        'balanced'     => abs($totalAssets - ($totalLiabilities + $totalEquityWithRetained)) < 0.01,
    ];
}

/** Journal entries with filters for the ledger screen. */
function list_journals(array $filters = []): array
{
    $sql = 'SELECT j.*, COALESCE(l.total,0) AS amount
            FROM journals j
            LEFT JOIN (SELECT journal_id, SUM(debit) AS total FROM journal_lines GROUP BY journal_id) l
                ON l.journal_id = j.id
            WHERE 1 = 1';
    $params = [];
    if (!empty($filters['source'])) {
        $sql .= ' AND j.source = ?';
        $params[] = $filters['source'];
    }
    if (!empty($filters['from'])) {
        $sql .= ' AND j.entry_date >= ?';
        $params[] = $filters['from'];
    }
    if (!empty($filters['to'])) {
        $sql .= ' AND j.entry_date <= ?';
        $params[] = $filters['to'];
    }
    if (!empty($filters['search'])) {
        $like = '%' . $filters['search'] . '%';
        $sql .= ' AND (j.entry_no LIKE ? OR j.narration LIKE ? OR j.reference LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    $sql .= ' ORDER BY j.entry_date DESC, j.id DESC LIMIT 300';
    return fetch_all($sql, $params);
}

/** A single journal with its lines. */
function journal_detail(int $id): ?array
{
    $journal = fetch_one('SELECT * FROM journals WHERE id = ?', [$id]);
    if (!$journal) {
        return null;
    }
    $journal['lines'] = fetch_all(
        'SELECT jl.*, a.code, a.name FROM journal_lines jl JOIN accounts a ON a.id = jl.account_id
         WHERE jl.journal_id = ? ORDER BY jl.id',
        [$id]
    );
    return $journal;
}

/** Postings for one account with a running balance. */
function ledger_entries(int $accountId, ?string $from = null, ?string $to = null): array
{
    $sql = 'SELECT jl.debit, jl.credit, jl.memo, j.entry_no, j.entry_date, j.narration, j.reference
            FROM journal_lines jl JOIN journals j ON j.id = jl.journal_id
            WHERE jl.account_id = ?';
    $params = [$accountId];
    if ($from !== null) {
        $sql .= ' AND j.entry_date >= ?';
        $params[] = $from;
    }
    if ($to !== null) {
        $sql .= ' AND j.entry_date <= ?';
        $params[] = $to;
    }
    $sql .= ' ORDER BY j.entry_date ASC, j.id ASC';
    $rows = fetch_all($sql, $params);
    $running = 0.0;
    foreach ($rows as &$row) {
        $running += (float) $row['debit'] - (float) $row['credit'];
        $row['running'] = $running;
    }
    return $rows;
}

/** Tab definitions shared by the accounting admin pages. */
function accounting_tabs(): array
{
    return [
        'accounts'   => ['label' => 'Chart of Accounts',    'href' => '/admin/accounts.php',      'icon' => 'list-ol'],
        'journal'    => ['label' => 'Journal Entries',      'href' => '/admin/journal.php',       'icon' => 'journal-text'],
        'ledger'     => ['label' => 'General Ledger',       'href' => '/admin/ledger.php',        'icon' => 'book'],
        'trial'      => ['label' => 'Trial Balance',        'href' => '/admin/trial-balance.php', 'icon' => 'clipboard-check'],
        'financials' => ['label' => 'Financial Statements', 'href' => '/admin/financials.php',    'icon' => 'graph-up-arrow'],
    ];
}
