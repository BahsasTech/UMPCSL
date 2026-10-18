<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$type = (string) get('type', '');
$from = (string) get('from', '');
$to = (string) get('to', '');
$search = (string) get('search', '');
$status = (string) get('status', '');
$filters = [
    'channel' => (string) get('channel', ''),
    'status'  => (string) get('status', ''),
    'event'   => (string) get('event', ''),
    'search'  => (string) get('search', ''),
];

try {
    switch ($type) {
        case 'statement':
            $member = member_for_user((int) $user['id']);
            if (!$member) {
                throw new RuntimeException('No member profile.');
            }
            $history = member_payment_history((int) $member['id'], 1000);
            $rows = array_map(fn($r) => [
                fmt_date($r['paid_at']), $r['receipt_no'], label($r['type']),
                $r['product'] ? label($r['product']) : ($r['loan_no'] ?? ''), label($r['method']), number_format((float) $r['amount'], 2, '.', ''),
            ], $history);
            audit('export_statement', 'members', (int) $member['id']);
            csv_output('statement-' . $member['membership_no'] . '.csv',
                ['Date', 'Receipt', 'Type', 'Applied to', 'Method', 'Amount'], $rows);
            break;

        case 'payments':
            require_staff();
            $rows = array_map(fn($r) => [
                fmt_date($r['paid_at'], 'Y-m-d H:i'), $r['receipt_no'], $r['membership_no'],
                $r['first_name'] . ' ' . $r['last_name'], label($r['type']),
                $r['product'] ? label($r['product']) : ($r['loan_no'] ?? ''), label($r['method']),
                number_format((float) $r['amount'], 2, '.', ''),
            ], list_payments(['from' => $from, 'to' => $to, 'search' => $search]));
            csv_output('payments-' . date('Ymd') . '.csv',
                ['Date', 'Receipt', 'Membership', 'Member', 'Type', 'Applied to', 'Method', 'Amount'], $rows);
            break;

        case 'loans':
            require_staff();
            $rows = array_map(fn($r) => [
                $r['loan_no'], $r['membership_no'], $r['first_name'] . ' ' . $r['last_name'],
                number_format((float) $r['amount'], 2, '.', ''), (float) $r['interest_rate'],
                (int) $r['term_months'], number_format((float) $r['total_repayable'], 2, '.', ''),
                number_format((float) $r['outstanding'], 2, '.', ''), label($r['status']),
                fmt_date($r['applied_at'], 'Y-m-d'),
            ], list_loans($status, $search));
            csv_output('loans-' . date('Ymd') . '.csv',
                ['Loan no', 'Membership', 'Member', 'Amount', 'Rate', 'Term', 'Total repayable', 'Outstanding', 'Status', 'Applied'], $rows);
            break;

        case 'members':
            require_staff();
            $rows = array_map(fn($r) => [
                $r['membership_no'], $r['first_name'] . ' ' . $r['last_name'], $r['email'],
                $r['phone'] ?? '', label($r['status']), number_format((float) $r['savings_total'], 2, '.', ''),
                number_format((float) $r['loan_outstanding'], 2, '.', ''), fmt_date($r['joined_at'], 'Y-m-d'),
            ], list_members($search, $status));
            csv_output('members-' . date('Ymd') . '.csv',
                ['Membership', 'Name', 'Email', 'Phone', 'Status', 'Savings', 'Loan outstanding', 'Joined'], $rows);
            break;

        case 'member_positions':
            require_staff();
            $rows = array_map(fn($r) => [
                $r['membership_no'], $r['first_name'] . ' ' . $r['last_name'], label($r['status']),
                number_format((float) $r['savings'], 2, '.', ''), number_format((float) $r['outstanding'], 2, '.', ''),
            ], report_member_positions());
            csv_output('member-positions-' . date('Ymd') . '.csv',
                ['Membership', 'Name', 'Status', 'Savings', 'Loan outstanding'], $rows);
            break;

        case 'notifications':
            require_staff();
            $rows = array_map(fn($r) => [
                fmt_date($r['created_at'], 'Y-m-d H:i'), label($r['channel']), label($r['event']),
                $r['recipient'], $r['subject'] ?? '', $r['body'], label($r['status']),
                $r['provider'] ?? '', $r['error'] ?? '',
            ], list_notifications($filters));
            csv_output('notifications-' . date('Ymd') . '.csv',
                ['When', 'Channel', 'Event', 'Recipient', 'Subject', 'Body', 'Status', 'Provider', 'Error'], $rows);
            break;

        case 'journal':
            require_staff();
            $rows = array_map(fn($r) => [
                $r['entry_no'], fmt_date($r['entry_date'], 'Y-m-d'), $r['narration'] ?? '',
                label((string) $r['source']), $r['reference'] ?? '', number_format((float) $r['amount'], 2, '.', ''),
            ], list_journals(['source' => (string) get('source', ''), 'from' => $from, 'to' => $to, 'search' => (string) get('q', '')]));
            csv_output('journal-' . date('Ymd') . '.csv',
                ['Entry', 'Date', 'Narration', 'Source', 'Reference', 'Amount'], $rows);
            break;

        case 'trial_balance':
            require_staff();
            $asOf = (string) get('as_of', date('Y-m-d'));
            $tb = trial_balance($asOf);
            $rows = array_map(fn($r) => [
                $r['code'], $r['name'], ACCOUNT_TYPES[$r['type']],
                number_format((float) $r['debit'], 2, '.', ''), number_format((float) $r['credit'], 2, '.', ''),
            ], $tb['rows']);
            $rows[] = ['', 'Total', '', number_format($tb['total_debit'], 2, '.', ''), number_format($tb['total_credit'], 2, '.', '')];
            csv_output('trial-balance-' . $asOf . '.csv', ['Code', 'Account', 'Type', 'Debit', 'Credit'], $rows);
            break;

        case 'income_statement':
            require_staff();
            $pl = income_statement($from ?: null, $to ?: null);
            $rows = [];
            foreach ($pl['revenue'] as $r) {
                $rows[] = ['Income', $r['code'], $r['name'], number_format((float) $r['amount'], 2, '.', '')];
            }
            $rows[] = ['', '', 'Total income', number_format($pl['total_revenue'], 2, '.', '')];
            foreach ($pl['expenses'] as $r) {
                $rows[] = ['Expense', $r['code'], $r['name'], number_format((float) $r['amount'], 2, '.', '')];
            }
            $rows[] = ['', '', 'Total expenses', number_format($pl['total_expense'], 2, '.', '')];
            $rows[] = ['', '', $pl['net'] >= 0 ? 'Net surplus' : 'Net deficit', number_format(abs($pl['net']), 2, '.', '')];
            csv_output('income-statement-' . date('Ymd') . '.csv', ['Section', 'Code', 'Account', 'Amount'], $rows);
            break;

        case 'balance_sheet':
            require_staff();
            $bs = balance_sheet($to ?: null);
            $rows = [];
            foreach ($bs['assets'] as $r) {
                $rows[] = ['Asset', $r['code'], $r['name'], number_format((float) $r['amount'], 2, '.', '')];
            }
            $rows[] = ['', '', 'Total assets', number_format($bs['total_assets'], 2, '.', '')];
            foreach ($bs['liabilities'] as $r) {
                $rows[] = ['Liability', $r['code'], $r['name'], number_format((float) $r['amount'], 2, '.', '')];
            }
            $rows[] = ['', '', 'Total liabilities', number_format($bs['total_liabilities'], 2, '.', '')];
            foreach ($bs['equity'] as $r) {
                $rows[] = ['Equity', $r['code'], $r['name'], number_format((float) $r['amount'], 2, '.', '')];
            }
            $rows[] = ['', '', 'Retained surplus', number_format($bs['retained'], 2, '.', '')];
            $rows[] = ['', '', 'Total equity', number_format($bs['total_equity_and_retained'], 2, '.', '')];
            csv_output('balance-sheet-' . date('Ymd') . '.csv', ['Section', 'Code', 'Account', 'Amount'], $rows);
            break;

        case 'products':
            require_staff();
            $rows = array_map(fn($r) => [
                $r['sku'], $r['name'], $r['category_name'] ?? '', label($r['unit']),
                number_format((float) $r['cost_price'], 2, '.', ''), number_format((float) $r['price'], 2, '.', ''),
                (int) $r['stock_qty'], (int) $r['reorder_level'],
                number_format((float) $r['cost_price'] * (int) $r['stock_qty'], 2, '.', ''),
                $r['is_active'] ? 'Active' : 'Inactive', $r['is_online'] ? 'Online' : 'In-store only',
            ], list_products(['search' => (string) get('q', ''), 'category' => (int) get('category', 0) ?: null]));
            csv_output('products-' . date('Ymd') . '.csv',
                ['SKU', 'Name', 'Category', 'Unit', 'Cost', 'Price', 'Stock', 'Reorder', 'Stock value', 'Status', 'Channel'], $rows);
            break;

        case 'stock':
            require_staff();
            $rows = array_map(fn($r) => [
                fmt_date($r['created_at'], 'Y-m-d H:i'), $r['sku'], $r['product_name'],
                label($r['type']), (int) $r['change_qty'], (int) $r['balance_after'],
                $r['reference'] ?? '', $r['notes'] ?? '', $r['user_name'] ?? '',
            ], list_movements(['product' => (int) get('product_id', 0) ?: null, 'type' => (string) get('mtype', '') ?: null, 'from' => $from, 'to' => $to]));
            csv_output('stock-movements-' . date('Ymd') . '.csv',
                ['When', 'SKU', 'Product', 'Type', 'Change', 'Balance', 'Reference', 'Notes', 'By'], $rows);
            break;

        case 'sales':
            require_staff();
            $rows = array_map(fn($r) => [
                $r['sale_no'], fmt_date($r['created_at'], 'Y-m-d H:i'), $r['customer_name'],
                $r['membership_no'] ?? '', label($r['channel']), label($r['status']),
                number_format((float) $r['subtotal'], 2, '.', ''), number_format((float) $r['discount'], 2, '.', ''),
                number_format((float) $r['total'], 2, '.', ''), number_format((float) $r['cost_total'], 2, '.', ''),
                number_format((float) $r['total'] - (float) $r['cost_total'], 2, '.', ''),
                $r['payment_method'] ? label($r['payment_method']) : '', $r['seller'] ?? '',
            ], list_sales(['status' => $status, 'channel' => $filters['channel'], 'search' => $search, 'from' => $from, 'to' => $to]));
            csv_output('sales-' . date('Ymd') . '.csv',
                ['Sale no', 'When', 'Customer', 'Membership', 'Channel', 'Status', 'Subtotal', 'Discount', 'Total', 'Cost', 'Profit', 'Payment', 'Sold by'], $rows);
            break;

        default:
            http_response_code(400);
            exit('Unknown export type.');
    }
} catch (RuntimeException $e) {
    http_response_code(403);
    exit($e->getMessage());
}
