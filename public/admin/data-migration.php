<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_login(['admin']);

$types = import_types();
$type = (string) get('type', 'members');
if (!isset($types[$type])) {
    $type = 'members';
}

// Blank template download
$template = (string) get('template', '');
if ($template !== '' && isset($types[$template])) {
    $fields = import_fields($template);
    $headers = [];
    $example = [];
    foreach ($fields as $key => $def) {
        $headers[] = $def['label'];
        $example[] = import_example_value($template, $key);
    }
    csv_output('umpcsl-' . $template . '-template.csv', $headers, [$example]);
}

$mode = 'upload';
$pendingToken = '';
$pendingName = '';
$mapping = [];
$headers = [];
$previewRows = [];
$report = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) post('action', '');
    $type = (string) post('type', 'members');
    if (!isset($types[$type])) {
        $type = 'members';
    }
    try {
        if ($action === 'upload') {
            if (!isset($_FILES['file'])) {
                throw new RuntimeException('Choose a CSV file to upload.');
            }
            $token = import_save_upload($_FILES['file']);
            flash('success', 'File uploaded. Review the column mapping, then import.');
            redirect('/admin/data-migration.php?type=' . $type . '&token=' . urlencode($token) . '&name=' . urlencode((string) $_FILES['file']['name']));
        }

        if ($action === 'preview' || $action === 'import') {
            $token = (string) post('token', '');
            $name = (string) post('name', '');
            $parsed = import_parse_csv(import_token_path($token));
            $headers = $parsed['headers'];
            $fields = import_fields($type);
            $posted = $_POST['map'] ?? [];
            foreach (array_keys($fields) as $field) {
                $mapping[$field] = isset($posted[$field]) ? (string) $posted[$field] : '';
            }
            $mapped = [];
            foreach ($parsed['rows'] as $raw) {
                $mapped[] = import_apply_map($raw, $mapping);
            }

            if ($action === 'import') {
                $report = import_run($type, $mapped, true);
                import_log($type, $name, $report, (int) $user['id']);
                import_delete_token($token);
                $_SESSION['import_report'] = ['type' => $type, 'name' => $name, 'report' => $report];
                flash('success', 'Import finished: ' . $report['imported'] . ' imported, ' . $report['skipped'] . ' skipped, ' . $report['failed'] . ' failed.');
                redirect('/admin/data-migration.php?type=' . $type . '&done=1');
            }

            $report = import_run($type, $mapped, false);
            $pendingToken = $token;
            $pendingName = $name;
            $previewRows = array_slice($mapped, 0, 10);
            $mode = 'review';
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
        if (!in_array($action, ['preview', 'import'], true)) {
            redirect('/admin/data-migration.php?type=' . $type);
        }
    }
} elseif (get('token') !== null) {
    try {
        $pendingToken = (string) get('token');
        $pendingName = (string) get('name', '');
        $parsed = import_parse_csv(import_token_path($pendingToken));
        $headers = $parsed['headers'];
        $mapping = import_guess_mapping($headers, $type);
        $mapped = [];
        foreach ($parsed['rows'] as $raw) {
            $mapped[] = import_apply_map($raw, $mapping);
        }
        $report = import_run($type, $mapped, false);
        $previewRows = array_slice($mapped, 0, 10);
        $mode = 'review';
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
        $pendingToken = '';
    }
}

if (get('done') === '1' && isset($_SESSION['import_report'])) {
    $stored = $_SESSION['import_report'];
    unset($_SESSION['import_report']);
    $report = $stored['report'];
    $type = $stored['type'];
    $mode = 'done';
}

$fields = import_fields($type);
$logs = list_import_logs(30);

$ttabs = [];
foreach ($types as $key => $meta) {
    $ttabs[$key] = [
        'label' => $meta['label'],
        'href'  => '/admin/data-migration.php?type=' . $key,
        'icon'  => $meta['icon'],
    ];
}

render_header('Data Migration', $user);
render_tabs($ttabs, $type);
?>

<div class="alert alert-info no-print d-flex gap-2">
    <i class="bi bi-info-circle"></i>
    <div>
        Import legacy records in order: <strong>Members</strong> first, then <strong>Savings Balances</strong>,
        <strong>Loans</strong> and <strong>Loan Repayments</strong>. Opening balances are posted to the
        <strong>Opening Balance Equity</strong> account so the ledger stays balanced. No emails or SMS are sent during import.
    </div>
</div>

<?php if ($mode === 'upload'): ?>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card p-4">
            <h5 class="mb-3">Upload <?= e($types[$type]['label']) ?> CSV</h5>
            <form method="post" enctype="multipart/form-data" class="row g-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="type" value="<?= e($type) ?>">
                <div class="col-12">
                    <input class="form-control" type="file" name="file" accept=".csv,text/csv" required>
                    <div class="form-text">Comma-separated values, first row must be a header row. Maximum 5MB.</div>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary"><i class="bi bi-upload"></i> Upload &amp; map</button>
                    <a class="btn btn-outline-secondary" href="/admin/data-migration.php?type=<?= e($type) ?>&template=<?= e($type) ?>"><i class="bi bi-download"></i> Blank template</a>
                </div>
            </form>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card p-4">
            <h5 class="mb-3">Expected columns</h5>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Column</th><th>Required</th><th>Notes</th></tr></thead>
                    <tbody>
                    <?php foreach ($fields as $def): ?>
                        <tr>
                            <td><?= e($def['label']) ?></td>
                            <td><?= !empty($def['required']) ? '<span class="badge text-bg-danger">Yes</span>' : '<span class="badge text-bg-secondary">No</span>' ?></td>
                            <td class="text-muted small"><?= e($def['hint'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php elseif ($mode === 'review'): ?>
<div class="card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Map columns &amp; preview</h5>
        <span class="text-muted small"><?= e($pendingName !== '' ? $pendingName : 'uploaded.csv') ?></span>
    </div>

    <?php if ($report && $report['errors'] !== []): ?>
        <div class="alert alert-warning small mb-3">
            <strong><?= count($report['errors']) ?> row(s) need attention</strong> (showing up to 10):
            <ul class="mb-0">
                <?php foreach (array_slice($report['errors'], 0, 10) as $err): ?>
                    <li>Row <?= (int) $err['row'] ?>: <?= e($err['message']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" class="row g-3">
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <input type="hidden" name="token" value="<?= e($pendingToken) ?>">
        <input type="hidden" name="name" value="<?= e($pendingName) ?>">

        <div class="col-12">
            <div class="row g-2">
                <?php foreach ($fields as $key => $def): ?>
                    <div class="col-sm-6 col-lg-4">
                        <label class="form-label small mb-0">
                            <?= e($def['label']) ?><?= !empty($def['required']) ? ' <span class="text-danger">*</span>' : '' ?>
                        </label>
                        <select class="form-select form-select-sm" name="map[<?= e($key) ?>]">
                            <option value="">— not mapped —</option>
                            <?php foreach ($headers as $index => $header): ?>
                                <option value="<?= (int) $index ?>" <?= (string) ($mapping[$key] ?? '') === (string) $index ? 'selected' : '' ?>>
                                    <?= e($header !== '' ? $header : 'Column ' . ($index + 1)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="col-12 d-flex gap-2">
            <button class="btn btn-outline-secondary" name="action" value="preview"><i class="bi bi-eye"></i> Refresh preview</button>
            <button class="btn btn-primary" name="action" value="import" onclick="return confirm('Import these rows into the database now?')"><i class="bi bi-database-add"></i> Import now</button>
        </div>
    </form>
</div>

<?php if ($report): ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card p-3"><div class="text-muted small">Rows in file</div><div class="h5 mb-0"><?= number_format($report['total']) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="card p-3"><div class="text-muted small">Ready / imported</div><div class="h5 mb-0 text-success"><?= number_format($report['imported']) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="card p-3"><div class="text-muted small">Skipped</div><div class="h5 mb-0"><?= number_format($report['skipped']) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="card p-3"><div class="text-muted small">Errors</div><div class="h5 mb-0 <?= $report['failed'] ? 'text-danger' : '' ?>"><?= number_format($report['failed']) ?></div></div></div>
</div>
<?php endif; ?>

<div class="card p-4 mb-4">
    <h5 class="mb-3">Preview (first 10 rows, as mapped)</h5>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><?php foreach ($fields as $key => $def): ?><?php if ((string) ($mapping[$key] ?? '') !== ''): ?><th><?= e($def['label']) ?></th><?php endif; ?><?php endforeach; ?></tr></thead>
            <tbody>
            <?php if ($previewRows === []): ?>
                <tr><td colspan="99" class="text-center text-muted py-4">No data rows found.</td></tr>
            <?php else: foreach ($previewRows as $row): ?>
                <tr>
                    <?php foreach ($fields as $key => $def): ?>
                        <?php if ((string) ($mapping[$key] ?? '') !== ''): ?>
                            <td><?= e((string) ($row[$key] ?? '')) ?></td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: // done ?>
<?php if ($report): ?>
<div class="card p-4 mb-4">
    <h5 class="mb-3"><i class="bi bi-check2-circle text-success"></i> Import complete</h5>
    <div class="row g-3">
        <div class="col-6 col-lg-3"><div class="text-muted small">Rows in file</div><div class="h5 mb-0"><?= number_format($report['total']) ?></div></div>
        <div class="col-6 col-lg-3"><div class="text-muted small">Imported</div><div class="h5 mb-0 text-success"><?= number_format($report['imported']) ?></div></div>
        <div class="col-6 col-lg-3"><div class="text-muted small">Skipped</div><div class="h5 mb-0"><?= number_format($report['skipped']) ?></div></div>
        <div class="col-6 col-lg-3"><div class="text-muted small">Errors</div><div class="h5 mb-0 <?= $report['failed'] ? 'text-danger' : '' ?>"><?= number_format($report['failed']) ?></div></div>
    </div>
    <?php if ($report['errors'] !== []): ?>
        <hr>
        <ul class="small mb-0">
            <?php foreach (array_slice($report['errors'], 0, 25) as $err): ?>
                <li>Row <?= (int) $err['row'] ?>: <?= e($err['message']) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card p-4">
            <h5 class="mb-3">Import more</h5>
            <p class="text-muted small">Continue with the next dataset.</p>
            <a class="btn btn-primary" href="/admin/data-migration.php?type=<?= e($type) ?>"><i class="bi bi-upload"></i> Upload <?= e($types[$type]['label']) ?> CSV</a>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card p-4">
            <h5 class="mb-3">Migration history</h5>
            <?php if ($logs === []): ?>
                <p class="text-muted mb-0">No imports have been run yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Batch</th><th>Type</th><th class="text-end">Imported</th><th class="text-end">Skipped</th><th class="text-end">Errors</th><th>By</th><th>When</th></tr></thead>
                        <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="small"><?= e($log['batch_no']) ?></td>
                                <td><?= e($types[$log['import_type']]['label'] ?? label($log['import_type'])) ?></td>
                                <td class="text-end text-success"><?= number_format((int) $log['imported']) ?></td>
                                <td class="text-end"><?= number_format((int) $log['skipped']) ?></td>
                                <td class="text-end <?= (int) $log['failed'] ? 'text-danger' : '' ?>"><?= number_format((int) $log['failed']) ?></td>
                                <td class="small"><?= e($log['full_name'] ?? '—') ?></td>
                                <td class="small text-muted"><?= e(fmt_date($log['created_at'], 'd M Y H:i')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php render_footer(); ?>
