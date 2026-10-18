<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_login(['admin']);

$search = (string) get('search', '');
$sql = 'SELECT a.*, u.full_name, u.role FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE 1 = 1';
$params = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $sql .= ' AND (a.action LIKE ? OR a.entity LIKE ? OR u.full_name LIKE ? OR a.details LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
$sql .= ' ORDER BY a.id DESC LIMIT 300';
$logs = fetch_all($sql, $params);

render_header('Activity Log', $user);
?>
<div class="card p-4">
    <form class="row g-2 mb-3" method="get">
        <div class="col-md-9"><input class="form-control" name="search" value="<?= e($search) ?>" placeholder="Search action, user or details"></div>
        <div class="col-md-3 d-flex gap-2"><button class="btn btn-outline-primary flex-grow-1">Search</button><a class="btn btn-outline-secondary" href="/admin/audit.php">Reset</a></div>
    </form>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>When</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr></thead>
            <tbody>
            <?php if ($logs === []): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No activity recorded.</td></tr>
            <?php else: foreach ($logs as $log): ?>
                <tr>
                    <td class="small"><?= e(fmt_date($log['created_at'], 'd M Y H:i')) ?></td>
                    <td class="small"><?= e($log['full_name'] ?? 'System') ?><?= $log['role'] ? ' (' . e(label($log['role'])) . ')' : '' ?></td>
                    <td><span class="badge text-bg-light"><?= e($log['action']) ?></span></td>
                    <td class="small"><?= e(($log['entity'] ?? '') . ($log['entity_id'] ? ' #' . $log['entity_id'] : '')) ?></td>
                    <td class="small"><?= e($log['details']) ?></td>
                    <td class="small text-muted"><?= e($log['ip_address']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
