<?php
declare(strict_types=1);

/**
 * Shared page chrome: header (nav + sidebar) and footer.
 */

/** Navigation links for a given user. */
function nav_items(array $user): array
{
    if (in_array($user['role'], ['admin', 'staff'], true)) {
        $items = [
            ['label' => 'Dashboard',   'href' => '/admin/index.php',    'icon' => 'speedometer2'],
            ['label' => 'Members',     'href' => '/admin/members.php',  'icon' => 'people'],
            ['label' => 'Loans',       'href' => '/admin/loans.php',    'icon' => 'cash-coin'],
            ['label' => 'Savings',     'href' => '/admin/savings.php',  'icon' => 'piggy-bank'],
            ['label' => 'Payments',    'href' => '/admin/payments.php', 'icon' => 'receipt'],
            ['label' => 'Online Payments', 'href' => '/admin/transactions.php', 'icon' => 'credit-card'],
            ['label' => 'Inventory',   'href' => '/admin/inventory.php', 'icon' => 'box-seam',
             'match' => ['inventory.php', 'product-form.php']],
            ['label' => 'Sales',       'href' => '/admin/sales.php', 'icon' => 'bag-check',
             'match' => ['sales.php', 'sale-new.php', 'sale-view.php', 'performance.php']],
            ['label' => 'Notifications','href' => '/admin/notifications.php', 'icon' => 'bell'],
            ['label' => 'Reports',     'href' => '/admin/reports.php',  'icon' => 'bar-chart'],
            ['label' => 'Accounting',  'href' => '/admin/accounts.php', 'icon' => 'journal-account',
             'match' => ['accounts.php', 'journal.php', 'ledger.php', 'trial-balance.php', 'financials.php']],
        ];
        if ($user['role'] === 'admin') {
            $items[] = ['label' => 'Data Migration', 'href' => '/admin/data-migration.php', 'icon' => 'database-add'];
            $items[] = ['label' => 'Activity Log', 'href' => '/admin/audit.php', 'icon' => 'shield-check'];
        }
        return $items;
    }
    return [
        ['label' => 'Dashboard',  'href' => '/dashboard.php',  'icon' => 'speedometer2'],
        ['label' => 'My Savings', 'href' => '/savings.php',    'icon' => 'piggy-bank'],
        ['label' => 'My Loans',   'href' => '/loans.php',      'icon' => 'cash-coin'],
        ['label' => 'Payments',   'href' => '/repayments.php', 'icon' => 'receipt'],
        ['label' => 'Make a Payment', 'href' => '/pay.php',    'icon' => 'credit-card'],
        ['label' => 'Store',      'href' => '/store.php',      'icon' => 'shop'],
        ['label' => 'My Orders',  'href' => '/orders.php',     'icon' => 'bag-check'],
        ['label' => 'Statements', 'href' => '/statements.php', 'icon' => 'file-text'],
        ['label' => 'Notifications', 'href' => '/notifications.php', 'icon' => 'bell'],
        ['label' => 'Profile',    'href' => '/profile.php',    'icon' => 'person-gear'],
    ];
}

/** Whether a nav item is active for the current script. */
function nav_is_active(array $item, string $current): bool
{
    if ($current === basename($item['href'])) {
        return true;
    }
    return isset($item['match']) && in_array($current, $item['match'], true);
}

/** Render a horizontal tab strip (Bootstrap nav-pills). */
function render_tabs(array $tabs, string $active): void
{
    echo '<ul class="nav nav-pills mb-4 no-print">';
    foreach ($tabs as $key => $tab) {
        $isActive = $key === $active;
        echo '<li class="nav-item"><a class="nav-link' . ($isActive ? ' active' : '') . '" href="' . e($tab['href']) . '">'
            . (isset($tab['icon']) ? '<i class="bi bi-' . e($tab['icon']) . ' me-1"></i>' : '')
            . e($tab['label']) . '</a></li>';
    }
    echo '</ul>';
}

/** Render the page header. */
function render_header(string $title, ?array $user = null, array $opts = []): void
{
    $current = basename($_SERVER['SCRIPT_NAME']);
    $brand = config('app.short_name', 'UMPCSL');
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> &middot; <?= e($brand) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/assets/css/app.css" rel="stylesheet">
</head>
<body class="<?= $user ? 'app-shell' : 'app-public' ?>">
<?php if ($user): ?>
    <nav class="navbar navbar-expand-lg navbar-dark app-topbar sticky-top">
        <div class="container-fluid">
            <button class="btn btn-sm btn-outline-light d-lg-none me-2" type="button"
                    data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-label="Menu">
                <i class="bi bi-list"></i>
            </button>
            <a class="navbar-brand fw-semibold" href="/">
                <i class="bi bi-buildings me-1"></i><?= e($brand) ?>
            </a>
            <div class="ms-auto d-flex align-items-center gap-3">
                <span class="text-white-50 small d-none d-md-inline">
                    <?= e($user['full_name']) ?>
                    <span class="badge bg-light text-dark ms-1"><?= e(label($user['role'])) ?></span>
                </span>
                <a class="btn btn-sm btn-outline-light" href="/logout.php">
                    <i class="bi bi-box-arrow-right"></i> <span class="d-none d-sm-inline">Sign out</span>
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <aside class="col-lg-2 d-none d-lg-block app-sidebar p-0">
                <div class="list-group list-group-flush">
                    <?php foreach (nav_items($user) as $item): ?>
                        <a class="list-group-item list-group-item-action <?= nav_is_active($item, $current) ? 'active' : '' ?>"
                           href="<?= e($item['href']) ?>">
                            <i class="bi bi-<?= e($item['icon']) ?> me-2"></i><?= e($item['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </aside>

            <div class="offcanvas offcanvas-start app-sidebar" tabindex="-1" id="appSidebar">
                <div class="offcanvas-header">
                    <h6 class="offcanvas-title"><?= e($brand) ?></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
                </div>
                <div class="offcanvas-body p-0">
                    <div class="list-group list-group-flush">
                        <?php foreach (nav_items($user) as $item): ?>
                            <a class="list-group-item list-group-item-action <?= nav_is_active($item, $current) ? 'active' : '' ?>"
                               href="<?= e($item['href']) ?>">
                                <i class="bi bi-<?= e($item['icon']) ?> me-2"></i><?= e($item['label']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <main class="col-lg-10 app-main py-4 px-lg-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h1 class="h4 mb-0"><?= e($title) ?></h1>
                    <?php if (!empty($opts['actions'])): ?>
                        <div class="d-flex gap-2"><?= $opts['actions'] ?></div>
                    <?php endif; ?>
                </div>
                <?php render_flashes(); ?>
<?php else: ?>
    <nav class="navbar navbar-expand-lg navbar-dark app-topbar">
        <div class="container">
            <a class="navbar-brand fw-semibold" href="/">
                <i class="bi bi-buildings me-1"></i><?= e($brand) ?>
            </a>
            <div class="ms-auto d-flex align-items-center">
                <a class="btn btn-sm btn-outline-light me-1" href="/store.php"><i class="bi bi-shop"></i> Store</a>
                <a class="btn btn-sm btn-outline-light me-1" href="/cart.php"><i class="bi bi-cart3"></i> Cart<?= cart_count() > 0 ? ' (' . (int) cart_count() . ')' : '' ?></a>
                <a class="btn btn-sm btn-outline-light me-1" href="/login.php">Sign in</a>
                <a class="btn btn-sm btn-warning" href="/register.php">Join now</a>
            </div>
        </div>
    </nav>
    <main class="app-main">
        <?php render_flashes(); ?>
<?php endif;
}

/** Render the page footer. */
function render_footer(): void
{
    if (is_logged_in()) {
        echo '</main></div></div>';
    } else {
        echo '</main>';
    }
    ?>
    <footer class="app-footer text-center text-muted small py-4 mt-auto">
        &copy; <?= date('Y') ?> <?= e(config('app.name')) ?>. All rights reserved.
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/app.js"></script>
</body>
</html><?php
}

/** Print all queued flash messages as dismissible alerts. */
function render_flashes(): void
{
    foreach (flashes() as $flash) {
        $type = in_array($flash['type'], ['success', 'danger', 'warning', 'info'], true) ? $flash['type'] : 'info';
        $icon = ['success' => 'check-circle', 'danger' => 'exclamation-octagon', 'warning' => 'exclamation-triangle', 'info' => 'info-circle'][$type];
        echo '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">'
            . '<i class="bi bi-' . $icon . ' me-1"></i>' . e($flash['message'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}

/** Render a status pill for a loan/payment/guarantor status. */
function status_badge(string $status): string
{
    $map = [
        'pending' => 'warning', 'under_review' => 'info', 'approved' => 'primary',
        'rejected' => 'danger', 'disbursed' => 'success', 'completed' => 'success',
        'defaulted' => 'danger', 'missed' => 'danger', 'paid' => 'success',
        'partial' => 'warning', 'verified' => 'success', 'active' => 'success',
        'inactive' => 'secondary', 'closed' => 'secondary', 'confirmed' => 'success',
        'failed' => 'danger', 'registered' => 'success', 'declined' => 'secondary',
        'suspended' => 'danger',
        'paid' => 'success', 'fulfilled' => 'info', 'cancelled' => 'danger',
        'success' => 'success',
    ];
    $color = $map[$status] ?? 'secondary';
    return '<span class="badge text-bg-' . $color . '">' . e(label($status)) . '</span>';
}
