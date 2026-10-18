<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = current_user();
if ($user !== null) {
    redirect(is_staff() ? '/admin/index.php' : '/dashboard.php');
}

$errors = [];
$values = [
    'first_name' => '', 'last_name' => '', 'other_names' => '', 'email' => '',
    'phone' => '', 'gender' => '', 'date_of_birth' => '', 'address' => '',
    'occupation' => '', 'monthly_income' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    foreach ($values as $key => $_) {
        $values[$key] = (string) post($key, '');
    }
    $password = (string) post('password', '');
    $confirm  = (string) post('password_confirm', '');

    if ($values['first_name'] === '' || $values['last_name'] === '') {
        $errors[] = 'First and last name are required.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    if ($errors === []) {
        try {
            $data = $values;
            $data['password'] = $password;
            $userId = register_member($data);
            audit('member_registered', 'users', $userId, $values['email']);
            $newMember = member_for_user($userId);
            if ($newMember) {
                safe_notify_member((int) $newMember['id'], 'welcome');
            }
            attempt_login($values['email'], $password);
            flash('success', 'Welcome! Your membership account has been created.');
            redirect('/dashboard.php');
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            $errors[] = 'Registration failed. Please try again.';
        }
    }
}

render_header('Create account', null);
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card p-4 p-md-5">
                <h1 class="h4 mb-1">Become a member</h1>
                <p class="text-muted">Join <?= e(config('app.name')) ?>.</p>

                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-danger py-2"><?= e($error) ?></div>
                <?php endforeach; ?>

                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <h6 class="text-muted text-uppercase small mt-2">Personal details</h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">First name</label>
                            <input class="form-control" name="first_name" value="<?= e($values['first_name']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Last name</label>
                            <input class="form-control" name="last_name" value="<?= e($values['last_name']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Other names</label>
                            <input class="form-control" name="other_names" value="<?= e($values['other_names']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Gender</label>
                            <select class="form-select" name="gender">
                                <option value="">Select…</option>
                                <?php foreach (['male', 'female', 'other'] as $g): ?>
                                    <option value="<?= $g ?>" <?= $values['gender'] === $g ? 'selected' : '' ?>><?= e(label($g)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Date of birth</label>
                            <input type="date" class="form-control" name="date_of_birth" value="<?= e($values['date_of_birth']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Monthly income</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="monthly_income" value="<?= e($values['monthly_income']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Occupation</label>
                            <input class="form-control" name="occupation" value="<?= e($values['occupation']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Address</label>
                            <input class="form-control" name="address" value="<?= e($values['address']) ?>">
                        </div>
                    </div>

                    <h6 class="text-muted text-uppercase small mt-4">Account</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Email address</label>
                            <input type="email" class="form-control" name="email" value="<?= e($values['email']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone number</label>
                            <input class="form-control" name="phone" value="<?= e($values['phone']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" required minlength="6">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm password</label>
                            <input type="password" class="form-control" name="password_confirm" required minlength="6">
                        </div>
                    </div>

                    <button class="btn btn-primary w-100 mt-4" type="submit"><i class="bi bi-person-plus"></i> Create account</button>
                </form>
                <p class="text-center text-muted mt-3 mb-0">
                    Already registered? <a href="/login.php" class="text-brand">Sign in</a>
                </p>
            </div>
        </div>
    </div>
</div>
<?php render_footer(); ?>
