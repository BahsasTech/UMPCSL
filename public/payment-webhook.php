<?php
declare(strict_types=1);

/**
 * Gateway webhook endpoint. Configure your provider to POST here:
 *   Paystack: https://your-domain/payment-webhook.php?provider=paystack
 *   Remita:   https://your-domain/payment-webhook.php?provider=remita
 *
 * The transaction is always re-confirmed with the gateway before any money is
 * applied, so duplicate or forged callbacks are harmless.
 */

require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json');

$provider = (string) ($_GET['provider'] ?? '');
if (!isset(PAYMENT_PROVIDERS[$provider]) || $provider === 'test') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown provider.']);
    exit;
}

$raw = (string) file_get_contents('php://input');
if (!webhook_verify($provider, $raw)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid signature.']);
    exit;
}

$data = json_decode($raw, true);
$reference = '';
if (is_array($data)) {
    if ($provider === 'paystack') {
        $reference = (string) ($data['data']['reference'] ?? '');
    } elseif ($provider === 'remita') {
        $reference = (string) ($data['orderId'] ?? $data['orderID'] ?? '');
    }
}

$txn = $reference !== '' ? get_transaction($reference) : null;
if (!$txn && $provider === 'remita' && is_array($data)) {
    $rrr = (string) ($data['RRR'] ?? '');
    if ($rrr !== '') {
        $txn = fetch_one('SELECT * FROM transactions WHERE provider_reference = ?', [$rrr]);
    }
}
if (!$txn) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Transaction not found.']);
    exit;
}

try {
    $verify = gateway_verify((string) $txn['provider'], $txn);
    finalize_transaction((string) $txn['reference'], $verify);
} catch (Throwable $e) {
    // Acknowledge receipt to avoid gateway retry storms; the callback and the
    // admin "re-check" action can still settle the transaction.
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}

echo json_encode(['status' => 'ok']);
