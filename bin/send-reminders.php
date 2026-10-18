<?php
declare(strict_types=1);

/**
 * Sends repayment reminders and missed-payment alerts.
 *
 * Usage:
 *   php bin/send-reminders.php                 # reminders due in 3 days + missed
 *   php bin/send-reminders.php --days=7        # change the reminder window
 *   php bin/send-reminders.php --reminders     # reminders only
 *   php bin/send-reminders.php --missed        # missed-payment alerts only
 *   php bin/send-reminders.php --force         # resend even if already notified today
 *
 * Schedule this with Windows Task Scheduler (or cron) to run daily.
 */

const APP_ROOT = __DIR__ . '/..';

require_once APP_ROOT . '/src/helpers.php';
require_once APP_ROOT . '/src/db.php';
require_once APP_ROOT . '/src/auth.php';
require_once APP_ROOT . '/src/members.php';
require_once APP_ROOT . '/src/savings.php';
require_once APP_ROOT . '/src/loans.php';
require_once APP_ROOT . '/src/payments.php';
require_once APP_ROOT . '/src/notifications.php';

$days = 3;
$doReminders = true;
$doMissed = true;
$force = false;

foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = max(0, (int) $m[1]);
    } elseif ($arg === '--reminders') {
        $doReminders = true;
        $doMissed = false;
    } elseif ($arg === '--missed') {
        $doMissed = true;
        $doReminders = false;
    } elseif ($arg === '--force') {
        $force = true;
    }
}

echo "UMPCSL notification scheduler\n";
echo "-----------------------------\n";

/** Skip if we already sent this exact event to this member today (unless --force). */
function already_notified(string $event, int $memberId, string $needle, bool $force): bool
{
    if ($force) {
        return false;
    }
    $count = (int) fetch_value(
        'SELECT COUNT(*) FROM notifications
         WHERE event = ? AND member_id = ? AND body LIKE ? AND DATE(created_at) = CURDATE()',
        [$event, $memberId, '%' . $needle . '%']
    );
    return $count > 0;
}

$sent = 0;
$skipped = 0;

// --- Repayment reminders ------------------------------------------------------
if ($doReminders) {
    $rows = fetch_all(
        'SELECT ls.installment, ls.amount_due, ls.due_date, l.loan_no, l.member_id
         FROM loan_schedule ls
         JOIN loans l ON l.id = ls.loan_id
         WHERE l.status IN ("disbursed","defaulted")
           AND ls.status IN ("pending","partial")
           AND ls.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ' . $days . ' DAY)
         ORDER BY ls.due_date ASC'
    );
    echo '• ' . count($rows) . ' upcoming installment(s) within ' . $days . ' day(s).' . "\n";
    foreach ($rows as $r) {
        $needle = $r['loan_no'] . '|' . $r['installment'];
        if (already_notified('repayment_reminder', (int) $r['member_id'], $r['loan_no'], $force)) {
            $skipped++;
            continue;
        }
        notify_member((int) $r['member_id'], 'repayment_reminder', [
            'loan_no'     => $r['loan_no'],
            'installment' => $r['installment'],
            'amount_due'  => money($r['amount_due']),
            'due_date'    => fmt_date($r['due_date']),
            'marker'      => $needle,
        ]);
        $sent++;
    }
}

// --- Missed-payment alerts ----------------------------------------------------
if ($doMissed) {
    refresh_missed_installments();
    $rows = fetch_all(
        'SELECT ls.installment, ls.amount_due, ls.due_date, l.loan_no, l.member_id
         FROM loan_schedule ls
         JOIN loans l ON l.id = ls.loan_id
         WHERE ls.status = "missed"
         ORDER BY ls.due_date ASC'
    );
    echo '• ' . count($rows) . ' missed installment(s).' . "\n";
    foreach ($rows as $r) {
        if (already_notified('missed_payment', (int) $r['member_id'], $r['loan_no'], $force)) {
            $skipped++;
            continue;
        }
        notify_member((int) $r['member_id'], 'missed_payment', [
            'loan_no'     => $r['loan_no'],
            'installment' => $r['installment'],
            'amount_due'  => money($r['amount_due']),
            'due_date'    => fmt_date($r['due_date']),
        ]);
        $sent++;
    }
}

echo "Done. Sent: $sent, skipped (already notified today): $skipped.\n";
