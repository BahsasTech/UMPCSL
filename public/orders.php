<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = require_login(['member']);
$member = member_for_user((int) $user['id']);
if (!$member) {
    flash('danger', 'No member profile is linked to your account.');
    redirect('/logout.php');
}
$memberId = (int) $member['id'];

$orders = member_orders($memberId);
$sales = [];
foreach ($orders as $o) {
    $s = get_sale((int) $o['id']);
    if ($s) {
        $sales[] = $s;
    }
}

render_header('My Orders', $user, []);
?>
<h3 class="mb-4">My Orders</h3>
<div class="card p-4">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Order no.</th><th>Date</th><th>Channel</th><th>Status</th><th class="text-end">Total</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php if ($sales === []): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">You haven't placed any orders yet.</td></tr>
            <?php else: foreach ($sales as $s): ?>
                <tr>
                    <td><code><?= e($s['sale_no']) ?></code></td>
                    <td class="text-muted small"><?= e(fmt_date($s['created_at'], 'd M Y H:i')) ?></td>
                    <td><?= e(label($s['channel'])) ?></td>
                    <td><?= status_badge($s['status']) ?></td>
                    <td class="text-end"><?= money($s['total']) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/order.php?id=<?= (int) $s['id'] ?>">View</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
