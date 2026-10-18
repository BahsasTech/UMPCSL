<?php
declare(strict_types=1);

/**
 * Online payment gateways: Paystack, Remita and a built-in test simulator.
 *
 * A "transaction" records every checkout attempt. Once the gateway confirms
 * payment (callback or webhook), finalize_transaction() applies it to the
 * relevant domain (savings contribution, loan repayment or store order) using
 * the same functions as a counter payment, so the ledger stays consistent.
 */

const PAYMENT_PROVIDERS = [
    'test'     => 'Test (simulator)',
    'paystack' => 'Paystack',
    'remita'   => 'Remita',
];

const TRANSACTION_STATUSES = [
    'pending'   => 'Pending',
    'success'   => 'Success',
    'failed'    => 'Failed',
    'cancelled' => 'Cancelled',
];

const TRANSACTION_PURPOSES = [
    'savings'        => 'Savings',
    'loan_repayment' => 'Loan repayment',
    'order'          => 'Store order',
    'other'          => 'Other',
];

/** Resolved gateway configuration (config defaults overridden by `pay_*` settings). */
function gateway_config(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cfg = config('payments', []);
    $rows = fetch_all("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'pay_%'");
    $saved = [];
    foreach ($rows as $row) {
        $saved[substr((string) $row['key'], 4)] = $row['value'];
    }
    $pick = fn(string $key, $default) => (array_key_exists($key, $saved) && $saved[$key] !== '') ? $saved[$key] : $default;
    $bool = fn(string $key, bool $default) => in_array((string) $pick($key, $default ? '1' : '0'), ['1', 'true', 'yes', 'on'], true);

    return $cache = [
        'currency'        => (string) $pick('currency', $cfg['currency'] ?? 'NGN'),
        'default_gateway' => (string) $pick('default_gateway', $cfg['default_gateway'] ?? 'test'),
        'test_enabled'    => $bool('test_enabled', (bool) ($cfg['test_enabled'] ?? true)),
        'paystack' => [
            'enabled'    => $bool('paystack_enabled', (bool) ($cfg['paystack']['enabled'] ?? false)),
            'public_key' => (string) $pick('paystack_public_key', $cfg['paystack']['public_key'] ?? ''),
            'secret_key' => (string) $pick('paystack_secret_key', $cfg['paystack']['secret_key'] ?? ''),
        ],
        'remita' => [
            'enabled'         => $bool('remita_enabled', (bool) ($cfg['remita']['enabled'] ?? false)),
            'merchant_id'     => (string) $pick('remita_merchant_id', $cfg['remita']['merchant_id'] ?? ''),
            'service_type_id' => (string) $pick('remita_service_type_id', $cfg['remita']['service_type_id'] ?? ''),
            'api_key'         => (string) $pick('remita_api_key', $cfg['remita']['api_key'] ?? ''),
            'api_hash_key'    => (string) $pick('remita_api_hash_key', $cfg['remita']['api_hash_key'] ?? ''),
            'base_url'        => rtrim((string) $pick('remita_base_url', $cfg['remita']['base_url'] ?? 'https://remitademo.net'), '/'),
        ],
    ];
}

/** Persist gateway settings to the `settings` table (keys prefixed `pay_`). */
function save_gateway_settings(array $data): void
{
    $keys = [
        'currency', 'default_gateway', 'test_enabled',
        'paystack_enabled', 'paystack_public_key', 'paystack_secret_key',
        'remita_enabled', 'remita_merchant_id', 'remita_service_type_id',
        'remita_api_key', 'remita_api_hash_key', 'remita_base_url',
    ];
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }
        $value = is_bool($data[$key]) ? ($data[$key] ? '1' : '0') : (string) $data[$key];
        q('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            ['pay_' . $key, $value]);
    }
    audit('gateway_settings', null, null, 'Online payment gateway settings updated');
}

/** All gateways with their enabled state and description. */
function payment_gateways(): array
{
    $cfg = gateway_config();
    return [
        'test'     => ['label' => PAYMENT_PROVIDERS['test'], 'enabled' => (bool) $cfg['test_enabled'], 'description' => 'Built-in simulator — no real money moves.'],
        'paystack' => ['label' => PAYMENT_PROVIDERS['paystack'], 'enabled' => (bool) $cfg['paystack']['enabled'], 'description' => 'Cards, bank transfer & USSD via Paystack.'],
        'remita'   => ['label' => PAYMENT_PROVIDERS['remita'], 'enabled' => (bool) $cfg['remita']['enabled'], 'description' => 'Remita RRR payment reference.'],
    ];
}

/** Only the gateways that are currently enabled. */
function enabled_gateways(): array
{
    return array_filter(payment_gateways(), fn($g) => $g['enabled']);
}

/** The gateway to preselect: the configured default if usable, else the first enabled. */
function default_gateway(): string
{
    $cfg = gateway_config();
    $enabled = enabled_gateways();
    if (isset($enabled[$cfg['default_gateway']])) {
        return $cfg['default_gateway'];
    }
    $first = array_key_first($enabled);
    return $first !== null ? (string) $first : 'test';
}

/* =====================================================================
 * Transactions
 * ===================================================================*/

function transaction_create(array $data): int
{
    $reference = (string) ($data['reference'] ?? ('TXN-' . strtoupper(bin2hex(random_bytes(6)))));
    return db_insert('transactions', [
        'reference'         => $reference,
        'provider'          => (string) $data['provider'],
        'purpose'           => (string) $data['purpose'],
        'member_id'         => !empty($data['member_id']) ? (int) $data['member_id'] : null,
        'amount'            => to_amount($data['amount'] ?? 0),
        'currency'          => (string) ($data['currency'] ?? gateway_config()['currency']),
        'status'            => 'pending',
        'related_id'        => !empty($data['related_id']) ? (int) $data['related_id'] : null,
        'authorization_url' => $data['authorization_url'] ?? null,
        'meta'              => !empty($data['meta']) ? json_encode($data['meta']) : null,
        'initiated_by'      => $data['initiated_by'] ?? ($_SESSION['user_id'] ?? null),
    ]);
}

function get_transaction(string $reference): ?array
{
    return fetch_one('SELECT * FROM transactions WHERE reference = ?', [$reference]);
}

function get_transaction_by_id(int $id): ?array
{
    return fetch_one('SELECT * FROM transactions WHERE id = ?', [$id]);
}

function transaction_meta(array $txn): array
{
    $meta = json_decode((string) ($txn['meta'] ?? ''), true);
    return is_array($meta) ? $meta : [];
}

function transaction_update(int $id, array $data): void
{
    db_update('transactions', $id, $data);
}

/** Customer contact details for a transaction, derived from its member. */
function transaction_customer(array $txn): array
{
    $member = !empty($txn['member_id']) ? get_member((int) $txn['member_id']) : null;
    return [
        'email' => (string) ($member['email'] ?? 'no-reply@umpcsl.com'),
        'name'  => $member ? trim($member['first_name'] . ' ' . $member['last_name']) : 'Customer',
        'phone' => (string) ($member['user_phone'] ?? ''),
    ];
}

function list_transactions(array $filters = []): array
{
    $sql = 'SELECT t.*, m.membership_no, m.first_name, m.last_name
            FROM transactions t
            LEFT JOIN members m ON m.id = t.member_id
            WHERE 1 = 1';
    $params = [];
    if (!empty($filters['status']) && isset(TRANSACTION_STATUSES[$filters['status']])) {
        $sql .= ' AND t.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['provider'])) {
        $sql .= ' AND t.provider = ?';
        $params[] = $filters['provider'];
    }
    if (!empty($filters['purpose'])) {
        $sql .= ' AND t.purpose = ?';
        $params[] = $filters['purpose'];
    }
    if (!empty($filters['member_id'])) {
        $sql .= ' AND t.member_id = ?';
        $params[] = (int) $filters['member_id'];
    }
    if (!empty($filters['search'])) {
        $like = '%' . $filters['search'] . '%';
        $sql .= ' AND (t.reference LIKE ? OR t.provider_reference LIKE ? OR m.membership_no LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    $sql .= ' ORDER BY t.id DESC LIMIT 300';
    return fetch_all($sql, $params);
}

function transaction_stats(): array
{
    $rows = fetch_all('SELECT status, COUNT(*) AS c, COALESCE(SUM(amount),0) AS total FROM transactions GROUP BY status');
    $out = ['count' => 0, 'success_total' => 0.0, 'pending' => 0, 'failed' => 0];
    foreach ($rows as $row) {
        $out['count'] += (int) $row['c'];
        if ($row['status'] === 'success') {
            $out['success_total'] = (float) $row['total'];
        } elseif ($row['status'] === 'pending') {
            $out['pending'] = (int) $row['c'];
        } elseif ($row['status'] === 'failed') {
            $out['failed'] = (int) $row['c'];
        }
    }
    return $out;
}

/* =====================================================================
 * Gateway initialisation
 * ===================================================================*/

/** Start checkout with a gateway. Returns ['redirect_url' => ..., 'provider_reference' => ...]. */
function gateway_initialize(string $provider, array $txn): array
{
    switch ($provider) {
        case 'test':
            return ['redirect_url' => '/test-gateway.php?reference=' . urlencode((string) $txn['reference']), 'provider_reference' => null];
        case 'paystack':
            return gateway_init_paystack($txn);
        case 'remita':
            return gateway_init_remita($txn);
    }
    throw new RuntimeException('Unsupported payment gateway: ' . $provider);
}

function gateway_init_paystack(array $txn): array
{
    $cfg = gateway_config()['paystack'];
    if ($cfg['secret_key'] === '') {
        throw new RuntimeException('Paystack secret key is not configured.');
    }
    $customer = transaction_customer($txn);
    $body = json_encode([
        'email'        => $customer['email'],
        'amount'       => (int) round(((float) $txn['amount']) * 100),
        'reference'    => (string) $txn['reference'],
        'currency'     => gateway_config()['currency'],
        'callback_url' => rtrim((string) config('app.url'), '/') . '/payment-callback.php',
        'metadata'     => ['purpose' => $txn['purpose'], 'member_id' => $txn['member_id'] ?? null],
    ]);
    [$status, $resp] = http_request('POST', 'https://api.paystack.co/transaction/initialize', $body, [
        'Authorization: Bearer ' . $cfg['secret_key'],
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    $json = json_decode($resp, true);
    if ($status < 200 || $status >= 300 || !is_array($json) || empty($json['status']) || empty($json['data']['authorization_url'])) {
        $msg = is_array($json) ? (string) ($json['message'] ?? 'Paystack initialization failed.') : 'Paystack initialization failed.';
        throw new RuntimeException('Paystack: ' . $msg);
    }
    return [
        'redirect_url'         => (string) $json['data']['authorization_url'],
        'provider_reference'   => (string) ($json['data']['reference'] ?? $txn['reference']),
    ];
}

function gateway_init_remita(array $txn): array
{
    $cfg = gateway_config()['remita'];
    if ($cfg['merchant_id'] === '' || $cfg['api_key'] === '') {
        throw new RuntimeException('Remita merchant ID and API key are not configured.');
    }
    $orderId = (string) $txn['reference'];
    $amount = number_format((float) $txn['amount'], 2, '.', '');
    $hash = hash('sha512', $cfg['merchant_id'] . $cfg['service_type_id'] . $orderId . $amount . $cfg['api_key']);
    $customer = transaction_customer($txn);
    $payload = json_encode([
        'serviceTypeId' => $cfg['service_type_id'],
        'amount'        => $amount,
        'orderId'       => $orderId,
        'payerName'     => $customer['name'],
        'payerEmail'    => $customer['email'],
        'payerPhone'    => $customer['phone'],
        'description'   => 'UMPCSL ' . label((string) $txn['purpose']) . ' ' . $orderId,
        'hash'          => $hash,
    ]);
    [$status, $resp] = http_request('POST', $cfg['base_url'] . '/remitaexe/v1/collections', $payload, [
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    $json = json_decode($resp, true);
    if (($json['statuscode'] ?? '') !== '025' || empty($json['RRR'])) {
        $msg = is_array($json) ? (string) ($json['status'] ?? 'could not generate a payment reference.') : 'could not generate a payment reference.';
        throw new RuntimeException('Remita: ' . $msg);
    }
    $rrr = (string) $json['RRR'];
    $responseUrl = rtrim((string) config('app.url'), '/') . '/payment-callback.php?provider=remita';
    $redirect = $cfg['base_url'] . '/remita/ecomm/finalize.reg?merchantId=' . urlencode($cfg['merchant_id'])
        . '&rrr=' . urlencode($rrr) . '&responseurl=' . urlencode($responseUrl);
    return ['redirect_url' => $redirect, 'provider_reference' => $rrr];
}

/* =====================================================================
 * Verification & webhooks
 * ===================================================================*/

/** Ask the gateway whether a transaction succeeded. */
function gateway_verify(string $provider, array $txn): array
{
    switch ($provider) {
        case 'test':
            return ['status' => 'success', 'amount' => (float) $txn['amount'], 'message' => 'Simulated payment approved.', 'raw' => []];
        case 'paystack':
            return gateway_verify_paystack($txn);
        case 'remita':
            return gateway_verify_remita($txn);
    }
    throw new RuntimeException('Cannot verify an unknown gateway.');
}

function gateway_verify_paystack(array $txn): array
{
    $cfg = gateway_config()['paystack'];
    if ($cfg['secret_key'] === '') {
        throw new RuntimeException('Paystack secret key is not configured.');
    }
    $ref = (string) ($txn['provider_reference'] ?: $txn['reference']);
    [$status, $resp] = http_request('GET', 'https://api.paystack.co/transaction/verify/' . rawurlencode($ref), null, [
        'Authorization: Bearer ' . $cfg['secret_key'],
        'Accept: application/json',
    ]);
    $json = json_decode($resp, true);
    $data = is_array($json) ? ($json['data'] ?? []) : [];
    $ok = $status >= 200 && $status < 300 && !empty($json['status']) && (($data['status'] ?? '') === 'success');
    return [
        'status'  => $ok ? 'success' : 'failed',
        'amount'  => isset($data['amount']) ? ((float) $data['amount']) / 100 : null,
        'message' => (string) ($data['gateway_response'] ?? ($json['message'] ?? 'Payment not successful.')),
        'raw'     => is_array($data) ? $data : [],
    ];
}

function gateway_verify_remita(array $txn): array
{
    $cfg = gateway_config()['remita'];
    $rrr = (string) ($txn['provider_reference'] ?: '');
    if ($rrr === '' || $cfg['merchant_id'] === '') {
        throw new RuntimeException('Remita transaction is missing its reference.');
    }
    $url = $cfg['base_url'] . '/remitaexe/v1/collections/' . urlencode($cfg['merchant_id'])
        . '/' . urlencode($rrr) . '/' . urlencode($cfg['api_hash_key']) . '/status.reg';
    [$status, $resp] = http_request('GET', $url, null, ['Accept: application/json']);
    $json = json_decode($resp, true);
    $code = (string) ($json['statuscode'] ?? $json['status'] ?? '');
    $ok = in_array($code, ['00', '025', 'success', 'SUCCESS', 'Successful'], true);
    return [
        'status'  => $ok ? 'success' : 'failed',
        'amount'  => isset($json['amount']) ? (float) $json['amount'] : null,
        'message' => (string) ($json['statusMessage'] ?? $json['message'] ?? $code),
        'raw'     => is_array($json) ? $json : [],
    ];
}

/** Verify a gateway webhook signature. Returns false when it cannot be trusted. */
function webhook_verify(string $provider, string $rawBody, array $headers = []): bool
{
    if ($provider === 'paystack') {
        $cfg = gateway_config()['paystack'];
        $signature = $headers['x-paystack-signature'] ?? ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '');
        if ($cfg['secret_key'] === '' || $signature === '') {
            return false;
        }
        return hash_equals(hash_hmac('sha512', $rawBody, $cfg['secret_key']), (string) $signature);
    }
    if ($provider === 'remita') {
        // Remita signatures vary by product; authenticity is confirmed by the
        // server-side status query in finalize path before any money moves.
        $cfg = gateway_config()['remita'];
        return $cfg['api_key'] !== '';
    }
    return false;
}

/* =====================================================================
 * Finalisation
 * ===================================================================*/

/**
 * Apply a confirmed payment to its domain once (idempotent). Posts through the
 * normal savings/loan/sale functions so the ledger and stock stay correct.
 */
function finalize_transaction(string $reference, array $verify): array
{
    $txn = get_transaction($reference);
    if (!$txn) {
        throw new RuntimeException('Transaction not found.');
    }
    if ($txn['status'] === 'success') {
        return ['status' => 'success', 'duplicate' => true, 'transaction' => $txn];
    }

    $gatewayStatus = (string) ($verify['status'] ?? 'failed');
    if ($gatewayStatus !== 'success') {
        transaction_update((int) $txn['id'], [
            'status'           => $gatewayStatus === 'pending' ? 'pending' : 'failed',
            'gateway_response' => json_encode($verify['raw'] ?? $verify),
        ]);
        return ['status' => $gatewayStatus, 'duplicate' => false, 'transaction' => $txn];
    }

    $expected = (float) $txn['amount'];
    if (isset($verify['amount']) && $verify['amount'] !== null && abs((float) $verify['amount'] - $expected) > 0.01) {
        transaction_update((int) $txn['id'], [
            'status'           => 'failed',
            'gateway_response' => json_encode(['error' => 'amount mismatch', 'expected' => $expected, 'actual' => (float) $verify['amount']]),
        ]);
        throw new RuntimeException('The paid amount (' . money((float) $verify['amount']) . ') does not match the amount due (' . money($expected) . ').');
    }

    $memberId = (int) $txn['member_id'];
    $amount = $expected;
    $meta = transaction_meta($txn);
    $method = in_array((string) $txn['provider'], ['paystack', 'remita'], true) ? (string) $txn['provider'] : 'other';
    $paymentMeta = [
        'method'    => $method,
        'reference' => $reference,
        'paid_at'   => date('Y-m-d H:i:s'),
        'narration' => 'Online payment (' . (PAYMENT_PROVIDERS[$txn['provider']] ?? $txn['provider']) . ')',
    ];

    $paymentId = null;
    $orderNo = '';
    $purpose = (string) $txn['purpose'];
    try {
        if ($purpose === 'savings') {
            $product = (string) ($meta['product'] ?? 'monthly');
            $res = record_savings_contribution($memberId, $product, $amount, $paymentMeta);
            $paymentId = (int) $res['payment_id'];
        } elseif ($purpose === 'loan_repayment') {
            $loan = fetch_one('SELECT * FROM loans WHERE id = ?', [(int) $txn['related_id']]);
            if (!$loan) {
                throw new RuntimeException('Loan not found for this payment.');
            }
            $pay = min($amount, (float) $loan['outstanding']);
            if ($pay <= 0) {
                throw new RuntimeException('This loan has no outstanding balance.');
            }
            $res = record_loan_repayment((int) $loan['id'], $pay, $paymentMeta);
            $paymentId = (int) $res['payment_id'];
        } elseif ($purpose === 'order') {
            $sale = get_sale((int) $txn['related_id']);
            if (!$sale) {
                throw new RuntimeException('Order not found for this payment.');
            }
            if ($sale['status'] === 'cancelled') {
                throw new RuntimeException('This order was cancelled.');
            }
            $orderNo = (string) $sale['sale_no'];
            mark_sale_paid((int) $sale['id'], $method, $reference);
        } else {
            $res = record_payment($memberId, 'other', $amount, $paymentMeta);
            $paymentId = !empty($res['payment_id']) ? (int) $res['payment_id'] : null;
        }
    } catch (Throwable $e) {
        transaction_update((int) $txn['id'], [
            'status'           => 'failed',
            'gateway_response' => json_encode(['error' => $e->getMessage()]),
        ]);
        throw $e;
    }

    transaction_update((int) $txn['id'], [
        'status'             => 'success',
        'paid_at'            => date('Y-m-d H:i:s'),
        'provider_reference' => $txn['provider_reference'] ?: $reference,
        'payment_id'         => $paymentId,
        'gateway_response'   => json_encode($verify['raw'] ?? $verify),
    ]);

    if ($memberId > 0) {
        safe_notify_member($memberId, 'payment_received', [
            'amount'    => money($amount),
            'purpose'   => label($purpose),
            'reference' => $reference,
            'order_no'  => $orderNo,
        ]);
    }
    audit('payment_received', 'transactions', (int) $txn['id'], $reference . ' ' . money($amount) . ' via ' . $txn['provider']);

    return ['status' => 'success', 'duplicate' => false, 'transaction' => get_transaction($reference)];
}
