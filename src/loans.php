<?php
declare(strict_types=1);

/**
 * Loan management: eligibility, applications, guarantors, approval,
 * disbursement, repayment schedules and repayments.
 */

const LOAN_ACTIVE_STATUSES = ['pending', 'under_review', 'approved', 'disbursed', 'defaulted'];

/** Organisation policy values loaded from the settings table with defaults. */
function loan_settings(): array
{
    $rows = fetch_all('SELECT `key`, `value` FROM settings');
    $map = [];
    foreach ($rows as $row) {
        $map[$row['key']] = $row['value'];
    }
    return [
        'max_multiple'    => (float) ($map['loan_max_multiple'] ?? 3),
        'default_rate'    => (float) ($map['loan_interest_rate'] ?? 5),
        'min_guarantors'  => (int) ($map['loan_min_guarantors'] ?? 2),
    ];
}

/**
 * Assess whether a member may take a loan.
 * Returns ['eligible' => bool, 'max_amount' => float, 'savings' => float, 'reasons' => []]
 */
function loan_eligibility(int $memberId): array
{
    $member = get_member($memberId);
    $summary = savings_summary($memberId);
    $settings = loan_settings();
    $reasons = [];

    if (!$member || $member['status'] !== 'active') {
        $reasons[] = 'Member account is not active.';
    }

    $savings = $summary['accounts']['share_capital']['balance'] ?? 0;
    $savings = (float) $savings + (float) ($summary['accounts']['monthly']['balance'] ?? 0);

    $active = fetch_one(
        'SELECT id, loan_no, status FROM loans WHERE member_id = ? AND status IN ("' . implode('","', LOAN_ACTIVE_STATUSES) . '") ORDER BY id DESC LIMIT 1',
        [$memberId]
    );
    if ($active) {
        $reasons[] = 'You already have a loan in progress (' . $active['loan_no'] . ').';
    }
    if ($savings <= 0) {
        $reasons[] = 'You need an active savings balance to qualify.';
    }

    $maxAmount = round($savings * $settings['max_multiple'], 2);

    return [
        'eligible'   => $reasons === [],
        'max_amount' => $maxAmount,
        'savings'    => $savings,
        'multiple'   => $settings['max_multiple'],
        'rate'       => $settings['default_rate'],
        'min_guarantors' => $settings['min_guarantors'],
        'reasons'    => $reasons,
    ];
}

/**
 * Submit a loan application with guarantors and optional collateral.
 * Returns the new loan id.
 */
function apply_for_loan(int $memberId, array $data, array $guarantors = [], array $collateral = []): int
{
    $eligibility = loan_eligibility($memberId);
    $amount = to_amount($data['amount'] ?? 0);
    $term = max(1, (int) ($data['term_months'] ?? 1));
    $rate = isset($data['interest_rate']) && $data['interest_rate'] !== ''
        ? (float) $data['interest_rate']
        : $eligibility['rate'];

    $guarantors = array_values(array_filter($guarantors, fn($g) => trim((string) ($g['name'] ?? '')) !== ''));

    if ($amount <= 0) {
        throw new RuntimeException('Enter a valid loan amount.');
    }
    if ($eligibility['max_amount'] > 0 && $amount > $eligibility['max_amount']) {
        throw new RuntimeException('Requested amount exceeds your eligible limit of ' . money($eligibility['max_amount']) . '.');
    }
    if (count($guarantors) < $eligibility['min_guarantors']) {
        throw new RuntimeException('At least ' . $eligibility['min_guarantors'] . ' guarantors are required.');
    }

    $totalRepayable = round($amount + ($amount * $rate / 100), 2);
    $loanNo = next_reference('loans', 'loan_no', 'LN-' . date('y') . '-', 5);

    $loanId = db_insert('loans', [
        'loan_no'         => $loanNo,
        'member_id'       => $memberId,
        'amount'          => $amount,
        'interest_rate'   => $rate,
        'term_months'     => $term,
        'purpose'         => $data['purpose'] ?? null,
        'status'          => 'pending',
        'total_repayable' => $totalRepayable,
        'outstanding'     => $totalRepayable,
        'applied_at'      => date('Y-m-d H:i:s'),
    ]);

    foreach ($guarantors as $g) {
        db_insert('loan_guarantors', [
            'loan_id'           => $loanId,
            'name'              => $g['name'],
            'phone'             => $g['phone'] ?? null,
            'relationship'      => $g['relationship'] ?? null,
            'amount_guaranteed' => to_amount($g['amount'] ?? 0),
            'status'            => 'pending',
        ]);
    }

    foreach ($collateral as $c) {
        if (trim((string) ($c['description'] ?? '')) === '') {
            continue;
        }
        db_insert('loan_collateral', [
            'loan_id'         => $loanId,
            'description'     => $c['description'],
            'estimated_value' => to_amount($c['value'] ?? 0),
        ]);
    }

    audit('loan_application', 'loans', $loanId, $loanNo . ' for ' . money($amount));
    return $loanId;
}

/** Build the repayment schedule for a loan (annuity-free, flat amounts). */
function generate_schedule(int $loanId, float $totalRepayable, int $termMonths): void
{
    q('DELETE FROM loan_schedule WHERE loan_id = ?', [$loanId]);
    $installment = round($totalRepayable / $termMonths, 2);
    $allocated = 0.0;
    for ($i = 1; $i <= $termMonths; $i++) {
        $due = ($i === $termMonths) ? round($totalRepayable - $allocated, 2) : $installment;
        $allocated += $due;
        db_insert('loan_schedule', [
            'loan_id'    => $loanId,
            'installment'=> $i,
            'due_date'   => date('Y-m-d', strtotime("+$i month")),
            'amount_due' => $due,
        ]);
    }
}

/** Mark overdue unpaid installments as missed. */
function refresh_missed_installments(?int $memberId = null): void
{
    $sql = 'UPDATE loan_schedule ls
            JOIN loans l ON l.id = ls.loan_id
            SET ls.status = "missed"
            WHERE ls.due_date < CURDATE() AND ls.status IN ("pending","partial")
              AND l.status IN ("disbursed","completed","defaulted")';
    if ($memberId !== null) {
        $sql .= ' AND l.member_id = ' . (int) $memberId;
    }
    db()->exec($sql);
}

/** Approve a loan and generate its schedule. */
function approve_loan(int $loanId, int $staffId, ?float $approvedAmount, ?string $remarks): void
{
    $loan = fetch_one('SELECT * FROM loans WHERE id = ?', [$loanId]);
    if (!$loan) {
        throw new RuntimeException('Loan not found.');
    }
    $amount = $approvedAmount ?? (float) $loan['amount'];
    $total = round($amount + ($amount * (float) $loan['interest_rate'] / 100), 2);

    db_update('loans', $loanId, [
        'status'          => 'approved',
        'amount'          => $amount,
        'total_repayable' => $total,
        'outstanding'     => $total,
        'reviewed_by'     => $staffId,
        'reviewed_at'     => date('Y-m-d H:i:s'),
        'remarks'         => $remarks,
    ]);
    generate_schedule($loanId, $total, (int) $loan['term_months']);
    audit('loan_approved', 'loans', $loanId, $loan['loan_no'] . ' approved for ' . money($amount));
}

/** Disburse an approved loan: credit the net amount to the member's savings. */
function disburse_loan(int $loanId, int $staffId): void
{
    $loan = fetch_one('SELECT * FROM loans WHERE id = ?', [$loanId]);
    if (!$loan || $loan['status'] !== 'approved') {
        throw new RuntimeException('Only approved loans can be disbursed.');
    }
    $account = get_savings_account((int) $loan['member_id'], 'monthly');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('UPDATE savings_accounts SET balance = balance + ? WHERE id = ?', [(float) $loan['amount'], $account['id']]);
        db_update('loans', $loanId, [
            'status'       => 'disbursed',
            'disbursed_at' => date('Y-m-d H:i:s'),
            'reviewed_by'  => $staffId,
        ]);
        $pdo->commit();
        audit('loan_disbursed', 'loans', $loanId, $loan['loan_no'] . ' disbursed');
        post_loan_disbursement_entry((float) $loan['amount'], (float) $loan['total_repayable'], (string) $loan['loan_no'], $loanId);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Reject a loan application. */
function reject_loan(int $loanId, int $staffId, string $remarks): void
{
    $loan = fetch_one('SELECT * FROM loans WHERE id = ?', [$loanId]);
    if (!$loan) {
        throw new RuntimeException('Loan not found.');
    }
    db_update('loans', $loanId, [
        'status'      => 'rejected',
        'reviewed_by' => $staffId,
        'reviewed_at' => date('Y-m-d H:i:s'),
        'remarks'     => $remarks,
    ]);
    audit('loan_rejected', 'loans', $loanId, $loan['loan_no'] . ' rejected');
}

/** Verify or reject a guarantor. */
function set_guarantor_status(int $guarantorId, string $status): void
{
    db_update('loan_guarantors', $guarantorId, [
        'status'      => $status,
        'verified_at' => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Allocate a repayment to a loan: reduces outstanding, settles installments
 * oldest first and completes the loan when fully repaid.
 * Returns ['outstanding' => float, 'completed' => bool, 'receipt_no' => string].
 */
function record_loan_repayment(int $loanId, float $amount, array $meta = []): array
{
    $loan = fetch_one('SELECT * FROM loans WHERE id = ?', [$loanId]);
    if (!$loan) {
        throw new RuntimeException('Loan not found.');
    }
    if (!in_array($loan['status'], ['disbursed', 'completed', 'defaulted'], true)) {
        throw new RuntimeException('Repayments can only be posted against a disbursed loan.');
    }
    if ($amount <= 0) {
        throw new RuntimeException('Repayment amount must be greater than zero.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $outstanding = max(0, round((float) $loan['outstanding'] - $amount, 2));
        $status = $loan['status'];
        if ($outstanding <= 0) {
            $status = 'completed';
        } elseif ($status === 'completed') {
            $status = 'disbursed';
        }

        db_update('loans', $loanId, ['outstanding' => $outstanding, 'status' => $status]);

        // Settle the schedule oldest-first.
        $remaining = $amount;
        $installments = fetch_all(
            'SELECT * FROM loan_schedule WHERE loan_id = ? AND status IN ("pending","partial","missed") ORDER BY installment ASC',
            [$loanId]
        );
        foreach ($installments as $row) {
            if ($remaining <= 0) {
                break;
            }
            $due = round((float) $row['amount_due'] - (float) $row['amount_paid'], 2);
            if ($due <= 0) {
                continue;
            }
            $pay = min($remaining, $due);
            $paid = round((float) $row['amount_paid'] + $pay, 2);
            $state = $paid >= (float) $row['amount_due'] ? 'paid' : 'partial';
            db_update('loan_schedule', (int) $row['id'], ['amount_paid' => $paid, 'status' => $state]);
            $remaining = round($remaining - $pay, 2);
        }

        $receipt = next_reference('payments', 'receipt_no', 'RCP-' . date('ymd') . '-', 4);
        $paymentId = db_insert('payments', [
            'receipt_no'  => $receipt,
            'member_id'   => (int) $loan['member_id'],
            'loan_id'     => $loanId,
            'type'        => 'loan_repayment',
            'amount'      => $amount,
            'method'      => $meta['method'] ?? 'cash',
            'reference'   => $meta['reference'] ?? null,
            'status'      => 'confirmed',
            'narration'   => $meta['narration'] ?? ('Loan repayment for ' . $loan['loan_no']),
            'paid_at'     => $meta['paid_at'] ?? date('Y-m-d H:i:s'),
            'recorded_by' => $_SESSION['user_id'] ?? null,
        ]);

        $pdo->commit();
        audit('loan_repayment', 'loans', $loanId, money($amount) . ' received');
        post_loan_repayment_entry((string) ($meta['method'] ?? 'cash'), $amount, (string) $loan['loan_no'], $loanId, $paymentId);
        return ['outstanding' => $outstanding, 'completed' => $outstanding <= 0, 'receipt_no' => $receipt, 'payment_id' => $paymentId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Loans belonging to a member. */
function member_loans(int $memberId): array
{
    return fetch_all('SELECT * FROM loans WHERE member_id = ? ORDER BY id DESC', [$memberId]);
}

/** Full loan detail with member, guarantors, collateral and schedule. */
function loan_detail(int $loanId): ?array
{
    $loan = fetch_one(
        'SELECT l.*, m.membership_no, m.first_name, m.last_name, u.email
         FROM loans l JOIN members m ON m.id = l.member_id JOIN users u ON u.id = m.user_id
         WHERE l.id = ?',
        [$loanId]
    );
    if (!$loan) {
        return null;
    }
    $loan['guarantors'] = fetch_all('SELECT * FROM loan_guarantors WHERE loan_id = ?', [$loanId]);
    $loan['collateral'] = fetch_all('SELECT * FROM loan_collateral WHERE loan_id = ?', [$loanId]);
    $loan['schedule']   = fetch_all('SELECT * FROM loan_schedule WHERE loan_id = ? ORDER BY installment', [$loanId]);
    return $loan;
}

/** List loans for the admin console with filters. */
function list_loans(string $status = '', string $search = ''): array
{
    $sql = 'SELECT l.*, m.membership_no, m.first_name, m.last_name
            FROM loans l JOIN members m ON m.id = l.member_id WHERE 1 = 1';
    $params = [];
    if ($status !== '') {
        $sql .= ' AND l.status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= ' AND (l.loan_no LIKE ? OR m.membership_no LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY l.id DESC';
    return fetch_all($sql, $params);
}

/** Portfolio totals for dashboards. */
function loan_totals(): array
{
    return [
        'disbursed' => (float) fetch_value('SELECT COALESCE(SUM(amount),0) FROM loans WHERE status IN ("disbursed","completed","defaulted")'),
        'outstanding' => (float) fetch_value('SELECT COALESCE(SUM(outstanding),0) FROM loans WHERE status IN ("disbursed","defaulted")'),
        'pending' => (int) fetch_value('SELECT COUNT(*) FROM loans WHERE status IN ("pending","under_review")'),
        'active' => (int) fetch_value('SELECT COUNT(*) FROM loans WHERE status IN ("disbursed","defaulted")'),
        'completed' => (int) fetch_value('SELECT COUNT(*) FROM loans WHERE status = "completed"'),
    ];
}
