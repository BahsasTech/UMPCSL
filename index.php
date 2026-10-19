<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = current_user();
if ($user !== null) {
    redirect(is_staff() ? '/admin/index.php' : '/dashboard.php');
}

$features = [
    ['icon' => 'people', 'title' => 'Membership', 'text' => 'Simple registration with KYC, nominees, next of kin and referrals.'],
    ['icon' => 'piggy-bank', 'title' => 'Savings', 'text' => 'Monthly savings, share capital and special savings with targets.'],
    ['icon' => 'cash-coin', 'title' => 'Loans', 'text' => 'Apply, get assessed, add guarantors and repay on a clear schedule.'],
    ['icon' => 'receipt', 'title' => 'Payments', 'text' => 'Track every contribution and repayment with instant receipts.'],
    ['icon' => 'bar-chart', 'title' => 'Reports', 'text' => 'Statements and exports for members, savings, loans and payments.'],
    ['icon' => 'shield-lock', 'title' => 'Secure', 'text' => 'Role based access, hashed passwords and an audit trail.'],
];

render_header('Home', null);
?>
<section class="hero text-center mb-5">
    <h1 class="display-6 fw-bold"><?= e(config('app.name')) ?></h1>
    <p class="lead mb-4">A modern cooperative society management platform for savings, loans, members and payments.</p>
    <div class="d-flex gap-2 justify-content-center">
        <a href="/register.php" class="btn btn-warning btn-lg"><i class="bi bi-person-plus"></i> Become a member</a>
        <a href="/login.php" class="btn btn-outline-light btn-lg"><i class="bi bi-box-arrow-in-right"></i> Member login</a>
    </div>
</section>

<div class="container pb-4">
    <div class="row g-4">
        <?php foreach ($features as $feature): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 p-4">
                    <div class="feature-icon bg-soft-green mb-3"><i class="bi bi-<?= e($feature['icon']) ?>"></i></div>
                    <h5><?= e($feature['title']) ?></h5>
                    <p class="text-muted mb-0"><?= e($feature['text']) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php render_footer(); ?>
