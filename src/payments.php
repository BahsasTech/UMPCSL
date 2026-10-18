<?php
declare(strict_types=1);

/**
 * Payments and repayments: a unified view over all money received.
 */

/** Record a payment, routing it to savings or loans as appropriate. */
function record_payment(int $memberId, string $type, float $amount, array $meta = []): array
{
    switch ($type) {
        case 'savings_contribution':
            return record_savings_contribution($memberId, 'monthly', $amount, $meta);
        case 'share_capital':
            return record_savings_contribution($memberId, 'share_capital', $amount, $meta);
        case 'special_savings':
            $product = $meta['product'] ?? 'special_land';
            return record_savings_contribution($memberId, $product, $amount, $meta);
        case 'loan_repayment':
            $loanId = (int) ($meta['loan_id'] ?? 0);
            if ($loanId <= 0) {
                throw new RuntimeException('Select the loan this repayment applies to.');
            }
            return record_loan_repayment($loanId, $amount, $meta);
        case 'fee':
        case 'other':
            $receipt = next_reference('payments', 'receipt_no', 'RCP-' . date('ymd') . '-', 4);
            $id = db_insert('payments', [
                'receipt_no'  => $receipt,
                'member_id'   => $memberId,
                'type'        => $type,
                'amount'      => $amount,
                'method'      => $meta['method'] ?? 'cash',
                'reference'   => $meta['reference'] ?? null,
                'status'      => 'confirmed',
                'narration'   => $meta['narration'] ?? label($type),
                'paid_at'      => $meta['paid_at'] ?? date('Y-m-d H:i:s'),
                'recorded_by' => $_SESSION['user_id'] ?? null,
            ]);
            post_other_income_entry((string) ($meta['method'] ?? 'cash'), $amount, $meta['reference'] ?? null, $id);
            return ['receipt_no' => $receipt, 'payment_id' => $id];
        default:
            throw new RuntimeException('Unsupported payment type.');
    }
}

/** List payments for the admin console. */
function list_payments(array $filters = []): array
{
    $sql = 'SELECT p.*, m.membership_no, m.first_name, m.last_name,
                   s.type AS product, l.loan_no
            FROM payments p
            JOIN members m ON m.id = p.member_id
            LEFT JOIN savings_accounts s ON s.id = p.savings_account_id
            LEFT JOIN loans l ON l.id = p.loan_id
            WHERE 1 = 1';
    $params = [];
    if (!empty($filters['type'])) {
        $sql .= ' AND p.type = ?';
        $params[] = $filters['type'];
    }
    if (!empty($filters['method'])) {
        $sql .= ' AND p.method = ?';
        $params[] = $filters['method'];
    }
    if (!empty($filters['search'])) {
        $like = '%' . $filters['search'] . '%';
        $sql .= ' AND (p.receipt_no LIKE ? OR m.membership_no LIKE ? OR p.reference LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    if (!empty($filters['from'])) {
        $sql .= ' AND p.paid_at >= ?';
        $params[] = $filters['from'] . ' 00:00:00';
    }
    if (!empty($filters['to'])) {
        $sql .= ' AND p.paid_at <= ?';
        $params[] = $filters['to'] . ' 23:59:59';
    }
    $sql .= ' ORDER BY p.paid_at DESC, p.id DESC LIMIT 500';
    return fetch_all($sql, $params);
}

/** All payment history for a single member. */
function member_payment_history(int $memberId, int $limit = 200): array
{
    return fetch_all(
        'SELECT p.*, s.type AS product, l.loan_no
         FROM payments p
         LEFT JOIN savings_accounts s ON s.id = p.savings_account_id
         LEFT JOIN loans l ON l.id = p.loan_id
         WHERE p.member_id = ? AND p.status = "confirmed"
         ORDER BY p.paid_at DESC, p.id DESC
         LIMIT ' . (int) $limit,
        [$memberId]
    );
}

/** A single payment row. */
function get_payment(int $id): ?array
{
    return fetch_one(
        'SELECT p.*, m.membership_no, m.first_name, m.last_name, u.email,
                s.type AS product, l.loan_no
         FROM payments p
         JOIN members m ON m.id = p.member_id
         JOIN users u ON u.id = m.user_id
         LEFT JOIN savings_accounts s ON s.id = p.savings_account_id
         LEFT JOIN loans l ON l.id = p.loan_id
         WHERE p.id = ?',
        [$id]
    );
}

/** Missed instalments across the portfolio. */
function missed_payments(): array
{
    refresh_missed_installments();
    return fetch_all(
        'SELECT ls.*, l.loan_no, l.id AS loan_id, m.membership_no, m.first_name, m.last_name
         FROM loan_schedule ls
         JOIN loans l ON l.id = ls.loan_id
         JOIN members m ON m.id = l.member_id
         WHERE ls.status = "missed"
         ORDER BY ls.due_date ASC'
    );
}

/** Confirmed income totals for a period. */
function payment_totals(?string $from = null, ?string $to = null): array
{
    $sql = 'SELECT type, COALESCE(SUM(amount),0) AS total, COUNT(*) AS count
            FROM payments WHERE status = "confirmed"';
    $params = [];
    if ($from) {
        $sql .= ' AND paid_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if ($to) {
        $sql .= ' AND paid_at <= ?';
        $params[] = $to . ' 23:59:59';
    }
    $sql .= ' GROUP BY type';
    $rows = fetch_all($sql, $params);
    $out = ['grand_total' => 0.0, 'by_type' => []];
    foreach ($rows as $row) {
        $out['by_type'][$row['type']] = ['total' => (float) $row['total'], 'count' => (int) $row['count']];
        $out['grand_total'] += (float) $row['total'];
    }
    return $out;
}
