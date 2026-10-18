<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = current_user();
if ($user !== null) {
    redirect(is_staff() ? '/admin/index.php' : '/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = (string) post('email', '');
    $password = (string) post('password', '');

    $authenticated = attempt_login($email, $password);
    if ($authenticated === null) {
        flash('danger', 'The email or password you entered is incorrect.');
        redirect('/login.php');
    }
    redirect($authenticated['role'] === 'member' ? '/dashboard.php' : '/admin/index.php');
}

render_header('Sign in', null);
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card p-4 p-md-5">
                <h1 class="h4 mb-1">Welcome back</h1>
                <p class="text-muted">Sign in to your <?= e(config('app.short_name')) ?> account.</p>
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="email">Email address</label>
                        <input type="email" class="form-control" id="email" name="email" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-box-arrow-in-right"></i> Sign in</button>
                </form>
                <p class="text-center text-muted mt-3 mb-0">
                    Not a member? <a href="/register.php" class="text-brand">Create an account</a>
                </p>
            </div>
        </div>
    </div>
</div>
<?php render_footer(); ?>
