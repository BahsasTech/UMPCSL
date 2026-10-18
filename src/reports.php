<?php
declare(strict_types=1);

/**
 * Reporting queries and export helpers.
 */

/** High level figures for the admin dashboard. */
function admin_overview(): array
{
    return [
        'members'          => (int) fetch_value('SELECT COUNT(*) FROM members'),
        'active_members'   => (int) fetch_value('SELECT COUNT(*) FROM members WHERE status = "active"'),
        'savings_total'    => (float) fetch_value('SELECT COALESCE(SUM(balance),0) FROM savings_accounts'),
        'share_capital'    => (float) fetch_value('SELECT COALESCE(SUM(balance),0) FROM savings_accounts WHERE type = "share_capital"'),
        'loan_outstanding' => (float) fetch_value('SELECT COALESCE(SUM(outstanding),0) FROM loans WHERE status IN ("disbursed","defaulted")'),
        'loans_active'     => (int) fetch_value('SELECT COUNT(*) FROM loans WHERE status IN ("disbursed","defaulted")'),
        'loans_pending'    => (int) fetch_value('SELECT COUNT(*) FROM loans WHERE status IN ("pending","under_review")'),
        'missed'           => (int) fetch_value('SELECT COUNT(*) FROM loan_schedule WHERE status = "missed"'),
        'contributions_month' => (float) fetch_value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = "confirmed" AND paid_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")'),
        'repayments_month' => (float) fetch_value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = "confirmed" AND type = "loan_repayment" AND paid_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")'),
    ];
}

/** Totals per savings product. */
function report_savings_by_product(): array
{
    return fetch_all(
        'SELECT s.type, COUNT(DISTINCT s.member_id) AS members,
                COALESCE(SUM(s.balance),0) AS balance, COALESCE(SUM(s.target_amount),0) AS target
         FROM savings_accounts s GROUP BY s.type ORDER BY s.type'
    );
}

/** Monthly contribution trend for the current year. */
function report_monthly_contributions(int $year): array
{
    return fetch_all(
        'SELECT DATE_FORMAT(paid_at, "%Y-%m") AS period, COALESCE(SUM(amount),0) AS total, COUNT(*) AS count
         FROM payments
         WHERE status = "confirmed" AND YEAR(paid_at) = ?
         GROUP BY period ORDER BY period',
        [$year]
    );
}

/** Loan portfolio breakdown by status. */
function report_loans_by_status(): array
{
    return fetch_all(
        'SELECT status, COUNT(*) AS count, COALESCE(SUM(amount),0) AS amount, COALESCE(SUM(outstanding),0) AS outstanding
         FROM loans GROUP BY status ORDER BY status'
    );
}

/** Payments grouped by method. */
function report_payments_by_method(?string $from = null, ?string $to = null): array
{
    $sql = 'SELECT method, COUNT(*) AS count, COALESCE(SUM(amount),0) AS total
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
    $sql .= ' GROUP BY method ORDER BY method';
    return fetch_all($sql, $params);
}

/** Members with their savings and loan position. */
function report_member_positions(): array
{
    return fetch_all(
        'SELECT m.membership_no, m.first_name, m.last_name, m.status,
                COALESCE((SELECT SUM(balance) FROM savings_accounts s WHERE s.member_id = m.id),0) AS savings,
                COALESCE((SELECT SUM(outstanding) FROM loans l WHERE l.member_id = m.id AND l.status IN ("disbursed","defaulted")),0) AS outstanding
         FROM members m ORDER BY m.membership_no'
    );
}

/**
 * Stream a CSV download. Rows are arrays in the same order as headers.
 */
function csv_output(string $filename, array $headers, array $rows): void
{
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, array_values($row));
    }
    fclose($out);
    exit;
}
