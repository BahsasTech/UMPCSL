<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = require_login(['member']);
$member = member_for_user((int) $user['id']);
$memberId = (int) $member['id'];

$channel = (string) get('channel', '');
$logs = list_notifications([
    'member_id' => $memberId,
    'channel'   => in_array($channel, ['email', 'sms'], true) ? $channel : '',
]);

render_header('My Notifications', $user);
?>
<div class="card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Messages sent to you</h5>
        <div class="btn-group btn-group-sm">
            <a class="btn btn-outline-secondary <?= $channel === '' ? 'active' : '' ?>" href="/notifications.php">All</a>
            <a class="btn btn-outline-secondary <?= $channel === 'email' ? 'active' : '' ?>" href="/notifications.php?channel=email">Email</a>
            <a class="btn btn-outline-secondary <?= $channel === 'sms' ? 'active' : '' ?>" href="/notifications.php?channel=sms">SMS</a>
        </div>
    </div>

    <?php if ($logs === []): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-bell-slash fs-1"></i>
            <p class="mb-0 mt-2">You have no notifications yet.</p>
        </div>
    <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($logs as $n): ?>
                <div class="list-group-item px-0 py-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="d-flex gap-3">
                            <div class="stat-icon <?= $n['channel'] === 'email' ? 'bg-soft-blue' : 'bg-soft-green' ?>" style="width:40px;height:40px;font-size:1.1rem;">
                                <i class="bi bi-<?= $n['channel'] === 'email' ? 'envelope' : 'chat-dots' ?>"></i>
                            </div>
                            <div>
                                <div class="fw-semibold"><?= e($n['subject'] ?: label($n['event'])) ?></div>
                                <div class="text-muted small" style="white-space:pre-line;"><?= e($n['body']) ?></div>
                            </div>
                        </div>
                        <div class="text-end text-nowrap ms-3">
                            <div class="small text-muted"><?= e(fmt_date($n['created_at'], 'd M Y H:i')) ?></div>
                            <div class="mt-1"><?= notification_status_badge($n['status']) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
