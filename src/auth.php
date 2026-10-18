<?php
declare(strict_types=1);

/**
 * Authentication, session and role helpers.
 */

/** The currently authenticated user (with member profile when applicable). */
function current_user(): ?array
{
    static $cached = false;
    static $user = null;

    if ($cached) {
        return $user;
    }
    $cached = true;

    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return null;
    }

    $user = fetch_one(
        'SELECT u.*, m.id AS member_id, m.membership_no, m.first_name, m.last_name, m.passport_photo
         FROM users u
         LEFT JOIN members m ON m.user_id = u.id
         WHERE u.id = ? AND u.status = "active"',
        [$id]
    );

    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_member(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'member';
}

function is_staff(): bool
{
    $u = current_user();
    return $u !== null && in_array($u['role'], ['admin', 'staff'], true);
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

/** Require an authenticated session, optionally restricted to given roles. */
function require_login(array $roles = []): array
{
    $user = current_user();
    if ($user === null) {
        flash('warning', 'Please sign in to continue.');
        redirect('/login.php');
    }
    if ($roles !== [] && !in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
    return $user;
}

/** Require an admin or staff session. */
function require_staff(): array
{
    return require_login(['admin', 'staff']);
}

/**
 * Register a new member account together with profile and savings accounts.
 * Returns the new user id. Throws RuntimeException on validation failure.
 */
function register_member(array $data): int
{
    $email = strtolower(trim((string) $data['email']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Please enter a valid email address.');
    }
    if (strlen((string) $data['password']) < 6) {
        throw new RuntimeException('Password must be at least 6 characters long.');
    }
    if ((int) fetch_value('SELECT COUNT(*) FROM users WHERE email = ?', [$email]) > 0) {
        throw new RuntimeException('An account with that email already exists.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $userId = db_insert('users', [
            'email'         => $email,
            'password_hash' => password_hash((string) $data['password'], PASSWORD_DEFAULT),
            'full_name'     => trim($data['first_name'] . ' ' . $data['last_name']),
            'phone'         => $data['phone'] ?? null,
            'role'          => 'member',
            'status'        => 'active',
        ]);

        $membershipNo = next_reference('members', 'membership_no', 'UMP-' . date('y'), 5);

        $memberId = db_insert('members', [
            'user_id'         => $userId,
            'membership_no'   => $membershipNo,
            'first_name'      => $data['first_name'],
            'last_name'       => $data['last_name'],
            'other_names'     => $data['other_names'] ?? null,
            'gender'          => $data['gender'] ?? null,
            'date_of_birth'   => !empty($data['date_of_birth']) ? $data['date_of_birth'] : null,
            'marital_status'  => $data['marital_status'] ?? null,
            'address'         => $data['address'] ?? null,
            'occupation'      => $data['occupation'] ?? null,
            'monthly_income'  => to_amount($data['monthly_income'] ?? 0),
            'bank_name'       => $data['bank_name'] ?? null,
            'bank_account_name' => $data['bank_account_name'] ?? null,
            'bank_account_no' => $data['bank_account_no'] ?? null,
            'joined_at'       => date('Y-m-d'),
            'status'          => 'active',
        ]);

        create_savings_accounts($memberId);

        $pdo->commit();
        return $userId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Attempt to authenticate a user. Returns the user row on success. */
function attempt_login(string $email, string $password): ?array
{
    $user = fetch_one('SELECT * FROM users WHERE email = ?', [strtolower(trim($email))]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return null;
    }
    if ($user['status'] !== 'active') {
        return null;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    db_update('users', (int) $user['id'], ['last_login_at' => date('Y-m-d H:i:s')]);
    audit('login', 'users', (int) $user['id'], 'User signed in');
    return $user;
}

/** Destroy the current session. */
function logout(): void
{
    $user = current_user();
    if ($user) {
        audit('logout', 'users', (int) $user['id'], 'User signed out');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Write an entry to the audit trail. */
function audit(string $action, ?string $entity = null, ?int $entityId = null, ?string $details = null): void
{
    try {
        db_insert('audit_logs', [
            'user_id'    => $_SESSION['user_id'] ?? null,
            'action'     => $action,
            'entity'     => $entity,
            'entity_id'  => $entityId,
            'details'    => $details !== null ? substr($details, 0, 255) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Never let auditing break the request.
    }
}
