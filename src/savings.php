<?php
declare(strict_types=1);

/**
 * Savings management: contributions, withdrawals and statements.
 */

/** Fetch a member's savings account of a given type. */
function get_savings_account(int $memberId, string $type): ?array
{
    create_savings_accounts($memberId);
    return fetch_one('SELECT * FROM savings_accounts WHERE member_id = ? AND type = ?', [$memberId, $type]);
}

/**
 * Post a savings contribution (credit) and record the matching payment.
 */
function record_savings_contribution(int $memberId, string $type, float $amount, array $meta = []): array
{
    if ($amount <= 0) {
        throw new RuntimeException('Contribution amount must be greater than zero.');
    }
    if (!array_key_exists($type, SAVINGS_PRODUCTS)) {
        throw new RuntimeException('Unknown savings product.');
    }

    $account = get_savings_account($memberId, $type);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('UPDATE savings_accounts SET balance = balance + ? WHERE id = ?', [$amount, $account['id']]);
        $newBalance = (float) fetch_value('SELECT balance FROM savings_accounts WHERE id = ?', [$account['id']]);

        $receipt = next_reference('payments', 'receipt_no', 'RCP-' . date('ymd') . '-', 4);
        $paymentId = db_insert('payments', [
            'receipt_no'         => $receipt,
            'member_id'          => $memberId,
            'savings_account_id' => (int) $account['id'],
            'type'               => $type === 'share_capital' ? 'share_capital'
                                    : ($type === 'monthly' ? 'savings_contribution' : 'special_savings'),
            'amount'             => $amount,
            'method'             => $meta['method'] ?? 'cash',
            'reference'          => $meta['reference'] ?? null,
            'status'             => 'confirmed',
            'narration'          => $meta['narration'] ?? ('Contribution to ' . SAVINGS_PRODUCTS[$type]),
            'paid_at'            => $meta['paid_at'] ?? date('Y-m-d H:i:s'),
            'recorded_by'        => $_SESSION['user_id'] ?? null,
        ]);

        $pdo->commit();
        audit('savings_contribution', 'savings_accounts', (int) $account['id'], money($amount) . ' to ' . $type);
        post_savings_entry((string) ($meta['method'] ?? 'cash'), $type, $amount, $meta['reference'] ?? null, $paymentId);
        return ['receipt_no' => $receipt, 'payment_id' => $paymentId, 'balance' => $newBalance];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Post a savings withdrawal (debit). */
function record_savings_withdrawal(int $memberId, string $type, float $amount, array $meta = []): array
{
    if ($amount <= 0) {
        throw new RuntimeException('Withdrawal amount must be greater than zero.');
    }
    $account = get_savings_account($memberId, $type);
    if ((float) $account['balance'] < $amount) {
        throw new RuntimeException('Insufficient balance in the ' . SAVINGS_PRODUCTS[$type] . ' account.');
    }

    q('UPDATE savings_accounts SET balance = balance - ? WHERE id = ?', [$amount, $account['id']]);
    $newBalance = (float) fetch_value('SELECT balance FROM savings_accounts WHERE id = ?', [$account['id']]);

    audit('savings_withdrawal', 'savings_accounts', (int) $account['id'], money($amount) . ' from ' . $type);
    post_savings_withdrawal_entry((string) ($meta['method'] ?? 'cash'), $type, $amount, (int) $account['id']);
    return ['balance' => $newBalance];
}

/** Update a savings target. */
function set_savings_target(int $memberId, string $type, float $target): void
{
    $account = get_savings_account($memberId, $type);
    db_update('savings_accounts', (int) $account['id'], ['target_amount' => $target]);
}

/** Transaction history for a member's savings, optionally for one product. */
function savings_statement(int $memberId, ?string $type = null, int $limit = 200): array
{
    $sql = 'SELECT p.*, s.type AS product
            FROM payments p
            LEFT JOIN savings_accounts s ON s.id = p.savings_account_id
            WHERE p.member_id = ? AND p.savings_account_id IS NOT NULL AND p.status = "confirmed"';
    $params = [$memberId];
    if ($type !== null) {
        $sql .= ' AND s.type = ?';
        $params[] = $type;
    }
    $sql .= ' ORDER BY p.paid_at DESC, p.id DESC LIMIT ' . (int) $limit;
    return fetch_all($sql, $params);
}
