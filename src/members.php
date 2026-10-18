<?php
declare(strict_types=1);

/**
 * Membership management: profiles, KYC, nominees and savings accounts.
 */

const SAVINGS_PRODUCTS = [
    'monthly'         => 'Monthly Savings',
    'share_capital'   => 'Share Capital',
    'special_land'    => 'Special Savings (Land)',
    'special_housing' => 'Special Savings (Housing)',
];

/** Create the standard savings accounts for a new member. */
function create_savings_accounts(int $memberId): void
{
    $member = fetch_one('SELECT membership_no FROM members WHERE id = ?', [$memberId]);
    foreach (SAVINGS_PRODUCTS as $type => $name) {
        $accountNo = 'SAV' . str_pad((string) $memberId, 5, '0', STR_PAD_LEFT) . '-' . strtoupper(substr($type, 0, 3));
        $exists = (int) fetch_value('SELECT COUNT(*) FROM savings_accounts WHERE member_id = ? AND type = ?', [$memberId, $type]);
        if ($exists === 0) {
            db_insert('savings_accounts', [
                'member_id'  => $memberId,
                'account_no' => $accountNo,
                'type'       => $type,
                'balance'    => 0,
            ]);
        }
    }
}

/** Get a member profile by user id. */
function member_for_user(int $userId): ?array
{
    return fetch_one('SELECT * FROM members WHERE user_id = ?', [$userId]);
}

/** Get a member profile (joined with user account) by member id. */
function get_member(int $memberId): ?array
{
    return fetch_one(
        'SELECT m.*, u.email, u.phone AS user_phone, u.status AS user_status
         FROM members m JOIN users u ON u.id = m.user_id
         WHERE m.id = ?',
        [$memberId]
    );
}

/** Aggregate savings balances for a member. Returns [total, per-type map]. */
function savings_summary(int $memberId): array
{
    $rows = fetch_all('SELECT type, balance, target_amount FROM savings_accounts WHERE member_id = ?', [$memberId]);
    $byType = [];
    $total = 0.0;
    foreach ($rows as $row) {
        $byType[$row['type']] = $row;
        $total += (float) $row['balance'];
    }
    return ['total' => $total, 'accounts' => $byType];
}

/** List members with optional search. */
function list_members(string $search = '', string $status = ''): array
{
    $sql = 'SELECT m.*, u.email, u.status AS user_status,
                   COALESCE((SELECT SUM(balance) FROM savings_accounts s WHERE s.member_id = m.id), 0) AS savings_total,
                   COALESCE((SELECT SUM(outstanding) FROM loans l WHERE l.member_id = m.id AND l.status IN ("disbursed","approved")), 0) AS loan_outstanding
            FROM members m JOIN users u ON u.id = m.user_id
            WHERE 1 = 1';
    $params = [];
    if ($search !== '') {
        $sql .= ' AND (m.first_name LIKE ? OR m.last_name LIKE ? OR m.membership_no LIKE ? OR u.email LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($status !== '') {
        $sql .= ' AND m.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY m.created_at DESC';
    return fetch_all($sql, $params);
}

/** Update a member's editable profile fields. */
function update_member_profile(int $memberId, array $data): void
{
    $allowed = [
        'first_name', 'last_name', 'other_names', 'gender', 'date_of_birth',
        'marital_status', 'address', 'occupation', 'monthly_income',
        'bank_name', 'bank_account_name', 'bank_account_no', 'status',
    ];
    $clean = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $data)) {
            $clean[$field] = $data[$field] === '' ? null : $data[$field];
        }
    }
    if (isset($clean['monthly_income'])) {
        $clean['monthly_income'] = to_amount($clean['monthly_income']);
    }
    if (!empty($clean)) {
        db_update('members', $memberId, $clean);
    }
}

/** Save an uploaded passport photograph. Returns the stored filename. */
function save_passport_photo(array $file, int $memberId): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Photo upload failed. Please try again.');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Photo must be smaller than 2MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        throw new RuntimeException('The uploaded file is not a valid image.');
    }
    $ext = image_type_to_extension($info[2], false) ?: 'jpg';
    $name = 'member-' . $memberId . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dir = (string) config('uploads.photos_dir');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    if (!move_uploaded_file($file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $name)) {
        throw new RuntimeException('Could not store the uploaded photo.');
    }
    return $name;
}

/** URL for a member's passport photo or a placeholder. */
function photo_url(?string $filename): string
{
    if ($filename && file_exists((string) config('uploads.photos_dir') . DIRECTORY_SEPARATOR . $filename)) {
        return '/uploads/photos/' . rawurlencode($filename);
    }
    return '/assets/img/avatar.svg';
}
