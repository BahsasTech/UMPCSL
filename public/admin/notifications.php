<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_staff();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) post('action', '');
    try {
        if ($action === 'broadcast') {
            $channels = [];
            if (post('channel_email')) {
                $channels[] = 'email';
            }
            if (post('channel_sms')) {
                $channels[] = 'sms';
            }
            if ($channels === []) {
                throw new RuntimeException('Select at least one delivery channel.');
            }
            $body = (string) post('body', '');
            if (trim($body) === '') {
                throw new RuntimeException('The message body cannot be empty.');
            }
            $result = send_announcement((string) post('subject', '(no subject)'), $body, (string) post('audience', 'active_members'), $channels);
            flash('success', 'Announcement queued to ' . $result['recipients'] . ' recipients (' . $result['messages'] . ' messages).');
        } elseif ($action === 'settings') {
            require_login(['admin']);
            save_notification_settings([
                'email_enabled'    => (bool) post('email_enabled'),
                'email_driver'     => (string) post('email_driver', 'log'),
                'email_from_address' => (string) post('email_from_address', ''),
                'email_from_name'  => (string) post('email_from_name', ''),
                'smtp_host'        => (string) post('smtp_host', ''),
                'smtp_port'        => (string) post('smtp_port', '587'),
                'smtp_username'    => (string) post('smtp_username', ''),
                'smtp_password'    => (string) post('smtp_password', ''),
                'smtp_encryption'  => (string) post('smtp_encryption', 'tls'),
                'sms_enabled'      => (bool) post('sms_enabled'),
                'sms_driver'       => (string) post('sms_driver', 'log'),
                'sms_sender_id'    => (string) post('sms_sender_id', ''),
                'sms_api_key'      => (string) post('sms_api_key', ''),
                'sms_generic_url'  => (string) post('sms_generic_url', ''),
                'sms_url_method'   => (string) post('sms_url_method', 'GET'),
                'sms_termii_url'   => (string) post('sms_termii_url', ''),
                'sms_twilio_sid'   => (string) post('sms_twilio_sid', ''),
                'sms_twilio_token' => (string) post('sms_twilio_token', ''),
                'sms_twilio_from'  => (string) post('sms_twilio_from', ''),
            ]);
            flash('success', 'Notification settings saved.');
        } elseif ($action === 'retry') {
            retry_notification((int) post('id', 0));
            flash('success', 'Delivery re-attempted.');
        } elseif ($action === 'test') {
            require_login(['admin']);
            $channel = post('test_channel') === 'sms' ? 'sms' : 'email';
            $recipient = (string) post('test_recipient', '');
            if ($recipient === '') {
                throw new RuntimeException('Enter a destination for the test message.');
            }
            $id = $channel === 'email'
                ? notify_email($recipient, 'UMPCSL test email', 'This is a test message from the UMPCSL notification system.')
                : notify_sms($recipient, 'UMPCSL test SMS: your gateway configuration works.');
            $row = fetch_one('SELECT status, error FROM notifications WHERE id = ?', [$id]);
            if (($row['status'] ?? '') === 'failed') {
                flash('danger', 'Test failed: ' . ($row['error'] ?? 'unknown error'));
            } else {
                flash('success', 'Test message ' . $row['status'] . ' via ' . $channel . '.');
            }
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/admin/notifications.php' . (post('tab') === 'settings' ? '?tab=settings' : ''));
}

$filters = [
    'channel' => (string) get('channel', ''),
    'status' => (string) get('status', ''),
    'event' => (string) get('event', ''),
    'search' => (string) get('search', ''),
];
$logs = list_notifications($filters);
$stats = notification_stats();
$cfg = notification_config();
$events = array_keys(notification_templates());
$tab = (string) get('tab', 'log');

render_header('Notifications', $user, [
    'actions' => '<a class="btn btn-outline-success btn-sm" href="/export.php?type=notifications"><i class="bi bi-filetype-csv"></i> Export</a>',
]);
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-2"><div class="card p-3"><div class="text-muted small">Total</div><div class="h5 mb-0"><?= number_format($stats['total']) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="card p-3"><div class="text-muted small">Sent</div><div class="h5 mb-0 text-success"><?= number_format($stats['sent']) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="card p-3"><div class="text-muted small">Logged (dev)</div><div class="h5 mb-0 text-secondary"><?= number_format($stats['logged']) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="card p-3"><div class="text-muted small">Failed</div><div class="h5 mb-0 text-danger"><?= number_format($stats['failed']) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="card p-3"><div class="text-muted small">Emails</div><div class="h5 mb-0"><?= number_format($stats['email']) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="card p-3"><div class="text-muted small">SMS</div><div class="h5 mb-0"><?= number_format($stats['sms']) ?></div></div></div>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab === 'log' ? 'active' : '' ?>" href="?tab=log"><i class="bi bi-list"></i> Message log</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'compose' ? 'active' : '' ?>" href="?tab=compose"><i class="bi bi-megaphone"></i> Compose announcement</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'settings' ? 'active' : '' ?>" href="?tab=settings"><i class="bi bi-gear"></i> Provider settings</a></li>
</ul>

<?php if ($tab === 'compose'): ?>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card p-4">
                <h5 class="mb-3">Compose announcement</h5>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="broadcast">
                    <div class="mb-3">
                        <label class="form-label">Audience</label>
                        <select class="form-select" name="audience">
                            <option value="active_members">All active members</option>
                            <option value="all">All members</option>
                            <option value="with_loans">Members with loans</option>
                            <option value="staff">Staff and administrators</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Subject (email)</label>
                        <input class="form-control" name="subject" placeholder="Important announcement">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Message</label>
                        <textarea class="form-control" name="body" rows="6" required></textarea>
                        <div class="form-text">You can use placeholders: <code>{first_name}</code>, <code>{membership_no}</code>, <code>{org}</code>.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label d-block">Channels</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="channel_email" id="channel_email" checked>
                            <label class="form-check-label" for="channel_email">Email</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="channel_sms" id="channel_sms" checked>
                            <label class="form-check-label" for="channel_sms">SMS</label>
                        </div>
                    </div>
                    <button class="btn btn-primary"><i class="bi bi-send"></i> Send announcement</button>
                </form>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card p-4">
                <h5 class="mb-3">Delivery status</h5>
                <ul class="list-unstyled small mb-0">
                    <li class="d-flex justify-content-between py-1"><span>Email provider</span>
                        <span class="badge text-bg-<?= $cfg['email']['enabled'] ? 'success' : 'secondary' ?>"><?= e($cfg['email']['driver']) ?><?= $cfg['email']['enabled'] ? '' : ' (disabled)' ?></span></li>
                    <li class="d-flex justify-content-between py-1"><span>SMS provider</span>
                        <span class="badge text-bg-<?= $cfg['sms']['enabled'] ? 'success' : 'secondary' ?>"><?= e($cfg['sms']['driver']) ?><?= $cfg['sms']['enabled'] ? '' : ' (disabled)' ?></span></li>
                </ul>
                <div class="alert alert-info small mt-3 mb-0">
                    While a channel is disabled or set to the <strong>log</strong> driver, messages are recorded here but not actually transmitted. Configure real providers under
                    <a href="?tab=settings">Provider settings</a>.
                </div>
            </div>
        </div>
    </div>

<?php elseif ($tab === 'settings'): ?>
    <?php if ($user['role'] !== 'admin'): ?>
        <div class="alert alert-warning">Only administrators can change provider settings.</div>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="settings">
        <input type="hidden" name="tab" value="settings">
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card p-4 h-100">
                    <h5 class="mb-3"><i class="bi bi-envelope"></i> Email</h5>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="email_enabled" id="email_enabled" value="1" <?= $cfg['email']['enabled'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="email_enabled">Enable email delivery</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Driver</label>
                        <select class="form-select" name="email_driver">
                            <?php foreach (['log' => 'Log only (development)', 'mail' => 'PHP mail()', 'smtp' => 'SMTP'] as $v => $l): ?>
                                <option value="<?= $v ?>" <?= $cfg['email']['driver'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">From address</label><input class="form-control" name="email_from_address" value="<?= e($cfg['email']['from_address']) ?>"></div>
                        <div class="col-md-6"><label class="form-label">From name</label><input class="form-control" name="email_from_name" value="<?= e($cfg['email']['from_name']) ?>"></div>
                        <div class="col-md-8"><label class="form-label">SMTP host</label><input class="form-control" name="smtp_host" value="<?= e($cfg['email']['smtp']['host']) ?>"></div>
                        <div class="col-md-4"><label class="form-label">Port</label><input class="form-control" name="smtp_port" value="<?= e((string) $cfg['email']['smtp']['port']) ?>"></div>
                        <div class="col-md-6"><label class="form-label">SMTP username</label><input class="form-control" name="smtp_username" value="<?= e($cfg['email']['smtp']['username']) ?>"></div>
                        <div class="col-md-6"><label class="form-label">SMTP password</label><input type="password" class="form-control" name="smtp_password" value="<?= e($cfg['email']['smtp']['password']) ?>"></div>
                        <div class="col-md-6"><label class="form-label">Encryption</label>
                            <select class="form-select" name="smtp_encryption">
                                <?php foreach (['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'None'] as $v => $l): ?>
                                    <option value="<?= $v ?>" <?= $cfg['email']['smtp']['encryption'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card p-4 h-100">
                    <h5 class="mb-3"><i class="bi bi-chat-dots"></i> SMS</h5>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="sms_enabled" id="sms_enabled" value="1" <?= $cfg['sms']['enabled'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="sms_enabled">Enable SMS delivery</label>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Driver</label>
                            <select class="form-select" name="sms_driver">
                                <?php foreach (['log' => 'Log only (development)', 'generic_http' => 'Generic HTTP gateway', 'termii' => 'Termii', 'twilio' => 'Twilio'] as $v => $l): ?>
                                    <option value="<?= $v ?>" <?= $cfg['sms']['driver'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6"><label class="form-label">Sender ID</label><input class="form-control" name="sms_sender_id" value="<?= e($cfg['sms']['sender_id']) ?>"></div>
                        <div class="col-12"><label class="form-label">API key</label><input class="form-control" name="sms_api_key" value="<?= e($cfg['sms']['api_key']) ?>"></div>
                        <div class="col-12"><label class="form-label">Generic gateway URL template</label><input class="form-control" name="sms_generic_url" value="<?= e($cfg['sms']['generic_url']) ?>" placeholder="https://gateway/send?to={to}&from={sender}&msg={message}&key={api_key}"></div>
                        <div class="col-md-6"><label class="form-label">Generic method</label>
                            <select class="form-select" name="sms_url_method"><option value="GET" <?= $cfg['sms']['url_method'] === 'GET' ? 'selected' : '' ?>>GET</option><option value="POST" <?= $cfg['sms']['url_method'] === 'POST' ? 'selected' : '' ?>>POST</option></select>
                        </div>
                        <div class="col-md-6"><label class="form-label">Termii endpoint</label><input class="form-control" name="sms_termii_url" value="<?= e($cfg['sms']['termii_url']) ?>"></div>
                        <div class="col-12"><hr class="my-1"><div class="text-muted small">Twilio</div></div>
                        <div class="col-md-6"><label class="form-label">Account SID</label><input class="form-control" name="sms_twilio_sid" value="<?= e($cfg['sms']['twilio']['sid']) ?>"></div>
                        <div class="col-md-6"><label class="form-label">Auth token</label><input type="password" class="form-control" name="sms_twilio_token" value="<?= e($cfg['sms']['twilio']['token']) ?>"></div>
                        <div class="col-md-6"><label class="form-label">From number</label><input class="form-control" name="sms_twilio_from" value="<?= e($cfg['sms']['twilio']['from']) ?>"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php if ($user['role'] === 'admin'): ?>
            <button class="btn btn-primary mt-3"><i class="bi bi-save"></i> Save settings</button>
        <?php endif; ?>
    </form>

    <?php if ($user['role'] === 'admin'): ?>
        <div class="card p-4 mt-4">
            <h5 class="mb-3"><i class="bi bi-send-check"></i> Send a test message</h5>
            <p class="text-muted small">Save your settings first, then send a test to confirm the gateway works end-to-end.</p>
            <form method="post" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="test">
                <input type="hidden" name="tab" value="settings">
                <div class="col-md-3">
                    <label class="form-label">Channel</label>
                    <select class="form-select" name="test_channel">
                        <option value="email">Email</option>
                        <option value="sms">SMS</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Destination</label>
                    <input class="form-control" name="test_recipient" placeholder="email address or phone number" required>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-outline-primary w-100"><i class="bi bi-send"></i> Send test</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

<?php else: ?>
    <div class="card p-4">
        <form class="row g-2 mb-3" method="get">
            <input type="hidden" name="tab" value="log">
            <div class="col-md-4"><input class="form-control" name="search" value="<?= e($filters['search']) ?>" placeholder="Search recipient, subject or body"></div>
            <div class="col-md-2"><select class="form-select" name="channel"><option value="">All channels</option><option value="email" <?= $filters['channel'] === 'email' ? 'selected' : '' ?>>Email</option><option value="sms" <?= $filters['channel'] === 'sms' ? 'selected' : '' ?>>SMS</option></select></div>
            <div class="col-md-2"><select class="form-select" name="status"><option value="">All statuses</option><?php foreach (['sent', 'logged', 'failed'] as $s): ?><option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><select class="form-select" name="event"><option value="">All events</option><?php foreach ($events as $ev): ?><option value="<?= e($ev) ?>" <?= $filters['event'] === $ev ? 'selected' : '' ?>><?= e(label($ev)) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 d-flex gap-1"><button class="btn btn-outline-primary flex-grow-1">Filter</button><a class="btn btn-outline-secondary" href="/admin/notifications.php">Reset</a></div>
        </form>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>When</th><th>Channel</th><th>Event</th><th>Recipient</th><th>Subject / body</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php if ($logs === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No notifications recorded.</td></tr>
                <?php else: foreach ($logs as $n): ?>
                    <tr>
                        <td class="small"><?= e(fmt_date($n['created_at'], 'd M Y H:i')) ?></td>
                        <td><span class="badge text-bg-light"><i class="bi bi-<?= $n['channel'] === 'email' ? 'envelope' : 'chat-dots' ?>"></i> <?= e(label($n['channel'])) ?></span></td>
                        <td class="small"><?= e(label($n['event'])) ?></td>
                        <td class="small"><?= e($n['recipient']) ?><?= $n['membership_no'] ? '<br><span class="text-muted">' . e($n['membership_no']) . '</span>' : '' ?></td>
                        <td class="small">
                            <?php if ($n['subject']): ?><strong><?= e($n['subject']) ?></strong><br><?php endif; ?>
                            <span class="text-muted"><?= e(mb_strimwidth((string) $n['body'], 0, 90, '…')) ?></span>
                            <?php if ($n['error']): ?><br><span class="text-danger small"><i class="bi bi-exclamation-triangle"></i> <?= e($n['error']) ?></span><?php endif; ?>
                        </td>
                        <td><?= notification_status_badge($n['status']) ?><div class="text-muted small"><?= $n['provider'] ? e($n['provider']) . ' · attempts ' . (int) $n['attempts'] : '' ?></div></td>
                        <td class="text-end">
                            <?php if ($n['status'] !== 'sent'): ?>
                                <form method="post" class="d-inline"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="retry"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                    <button class="btn btn-sm btn-outline-primary">Retry</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
<?php render_footer(); ?>
