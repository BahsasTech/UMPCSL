<?php
declare(strict_types=1);

/**
 * Legacy data migration: import existing members, savings balances, loans and
 * repayment history from CSV files. Every posted opening balance is recorded
 * against Opening Balance Equity (3200) so the ledger stays balanced.
 */

/** The available import types and their labels. */
function import_types(): array
{
    return [
        'members'    => ['label' => 'Members', 'icon' => 'people'],
        'savings'    => ['label' => 'Savings Balances', 'icon' => 'piggy-bank'],
        'loans'      => ['label' => 'Loans', 'icon' => 'cash-coin'],
        'repayments' => ['label' => 'Loan Repayments', 'icon' => 'receipt'],
    ];
}

/** Field definitions (key => label/required/hint) for a given import type. */
function import_fields(string $type): array
{
    switch ($type) {
        case 'members':
            return [
                'membership_no'  => ['label' => 'Membership No', 'required' => false, 'hint' => 'Kept if provided, otherwise generated.'],
                'first_name'     => ['label' => 'First Name', 'required' => true],
                'last_name'      => ['label' => 'Last Name', 'required' => true],
                'other_names'    => ['label' => 'Other Names', 'required' => false],
                'email'          => ['label' => 'Email', 'required' => false, 'hint' => 'Generated if blank.'],
                'phone'          => ['label' => 'Phone', 'required' => false],
                'gender'         => ['label' => 'Gender', 'required' => false],
                'date_of_birth'  => ['label' => 'Date of Birth', 'required' => false],
                'address'        => ['label' => 'Address', 'required' => false],
                'occupation'     => ['label' => 'Occupation', 'required' => false],
                'monthly_income' => ['label' => 'Monthly Income', 'required' => false],
                'joined_at'      => ['label' => 'Date Joined', 'required' => false],
                'password'       => ['label' => 'Password', 'required' => false, 'hint' => 'Defaults to password123.'],
            ];
        case 'savings':
            return [
                'membership_no'   => ['label' => 'Membership No', 'required' => false],
                'email'           => ['label' => 'Email', 'required' => false, 'hint' => 'Match by membership no or email.'],
                'monthly'         => ['label' => 'Monthly Savings', 'required' => false],
                'share_capital'   => ['label' => 'Share Capital', 'required' => false],
                'special_land'    => ['label' => 'Special (Land)', 'required' => false],
                'special_housing' => ['label' => 'Special (Housing)', 'required' => false],
                'as_of_date'      => ['label' => 'As of Date', 'required' => false],
            ];
        case 'loans':
            return [
                'membership_no'   => ['label' => 'Membership No', 'required' => false],
                'email'           => ['label' => 'Email', 'required' => false, 'hint' => 'Match by membership no or email.'],
                'loan_no'         => ['label' => 'Loan No', 'required' => false, 'hint' => 'Generated if blank.'],
                'amount'          => ['label' => 'Principal', 'required' => true],
                'interest_rate'   => ['label' => 'Interest Rate %', 'required' => false, 'hint' => 'Defaults to policy rate.'],
                'term_months'     => ['label' => 'Term (months)', 'required' => false, 'hint' => 'Defaults to 12.'],
                'total_repayable' => ['label' => 'Total Repayable', 'required' => false, 'hint' => 'Computed if blank.'],
                'outstanding'     => ['label' => 'Outstanding', 'required' => false, 'hint' => 'Defaults to total repayable.'],
                'purpose'         => ['label' => 'Purpose', 'required' => false],
                'status'          => ['label' => 'Status', 'required' => false, 'hint' => 'disbursed / completed / defaulted.'],
                'disbursed_at'    => ['label' => 'Disbursed Date', 'required' => false],
                'remarks'         => ['label' => 'Remarks', 'required' => false],
            ];
        case 'repayments':
            return [
                'loan_no'       => ['label' => 'Loan No', 'required' => false],
                'membership_no' => ['label' => 'Membership No', 'required' => false, 'hint' => 'Used when loan no is blank.'],
                'amount'        => ['label' => 'Amount', 'required' => true],
                'method'        => ['label' => 'Method', 'required' => false, 'hint' => 'cash / bank_transfer.'],
                'reference'     => ['label' => 'Reference', 'required' => false],
                'paid_at'       => ['label' => 'Paid Date', 'required' => false],
                'narration'     => ['label' => 'Narration', 'required' => false],
            ];
    }
    return [];
}

/** Sample value used when exporting a blank import template. */
function import_example_value(string $type, string $field): string
{
    $samples = [
        'membership_no'   => 'UMP-2019-00001',
        'first_name'      => 'Aisha',
        'last_name'       => 'Bello',
        'other_names'     => '',
        'email'           => 'aisha@example.com',
        'phone'           => '08030000000',
        'gender'          => 'female',
        'date_of_birth'   => '1988-05-14',
        'address'         => 'Kaduna, Nigeria',
        'occupation'      => 'Trader',
        'monthly_income'  => '50000',
        'joined_at'       => '2019-01-15',
        'password'        => 'password123',
        'monthly'         => '25000',
        'share_capital'   => '10000',
        'special_land'    => '0',
        'special_housing' => '0',
        'as_of_date'      => date('Y-m-d'),
        'loan_no'         => 'LN-' . date('y') . '-00001',
        'amount'          => '30000',
        'interest_rate'   => '5',
        'term_months'     => '6',
        'total_repayable' => '31500',
        'outstanding'     => '31500',
        'purpose'         => 'Business expansion',
        'status'          => 'disbursed',
        'disbursed_at'    => date('Y-m-d'),
        'remarks'         => '',
        'method'          => 'cash',
        'reference'       => '',
        'paid_at'         => date('Y-m-d'),
        'narration'       => 'Monthly repayment',
    ];
    return $samples[$field] ?? '';
}

/** Normalise a CSV header into a comparable token. */
function import_normalize_header(string $header): string
{
    $header = strtolower(trim($header));
    $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? $header;
    return trim($header, '_');
}

/** Common header aliases used when auto-guessing a column mapping. */
function import_aliases(): array
{
    return [
        'membership_no'   => ['membership_no', 'membership', 'member_no', 'memberno', 'mno', 'reg_no', 'registration_no'],
        'first_name'      => ['first_name', 'firstname', 'first', 'given_name', 'fname'],
        'last_name'       => ['last_name', 'lastname', 'last', 'surname', 'family_name', 'lname'],
        'other_names'     => ['other_names', 'othernames', 'middle_name', 'middlename'],
        'email'           => ['email', 'email_address', 'e_mail'],
        'phone'           => ['phone', 'phone_number', 'mobile', 'telephone', 'tel', 'gsm'],
        'gender'          => ['gender', 'sex'],
        'date_of_birth'   => ['date_of_birth', 'dob', 'birth_date', 'birthday'],
        'address'         => ['address', 'residential_address', 'home_address'],
        'occupation'      => ['occupation', 'job', 'profession'],
        'monthly_income'  => ['monthly_income', 'income', 'salary'],
        'joined_at'       => ['joined_at', 'date_joined', 'join_date', 'joined'],
        'password'        => ['password', 'pass'],
        'monthly'         => ['monthly', 'monthly_savings', 'savings', 'regular_savings'],
        'share_capital'   => ['share_capital', 'shares', 'share'],
        'special_land'    => ['special_land', 'land', 'special_savings_land'],
        'special_housing' => ['special_housing', 'housing', 'special_savings_housing'],
        'as_of_date'      => ['as_of_date', 'asof', 'as_at', 'balance_date'],
        'loan_no'         => ['loan_no', 'loanno', 'loan_number', 'loan_id'],
        'amount'          => ['amount', 'principal', 'loan_amount'],
        'interest_rate'   => ['interest_rate', 'rate', 'interest'],
        'term_months'     => ['term_months', 'term', 'tenor', 'duration', 'months'],
        'total_repayable' => ['total_repayable', 'total_due', 'total'],
        'outstanding'     => ['outstanding', 'outstanding_balance', 'loan_balance'],
        'purpose'         => ['purpose', 'reason'],
        'status'          => ['status', 'loan_status'],
        'disbursed_at'    => ['disbursed_at', 'disbursed_date', 'disbursement_date'],
        'remarks'         => ['remarks', 'comment', 'notes'],
        'method'          => ['method', 'payment_method', 'mode'],
        'reference'       => ['reference', 'ref', 'transaction_reference'],
        'paid_at'         => ['paid_at', 'payment_date', 'date_paid'],
        'narration'       => ['narration', 'description', 'details'],
    ];
}

/** Auto-guess the mapping (field => header index) from the CSV headers. */
function import_guess_mapping(array $headers, string $type): array
{
    $normalized = array_map('import_normalize_header', $headers);
    $aliases = import_aliases();
    $map = [];
    foreach (array_keys(import_fields($type)) as $field) {
        $candidates = array_merge([$field], $aliases[$field] ?? []);
        $found = '';
        foreach ($candidates as $candidate) {
            $index = array_search($candidate, $normalized, true);
            if ($index !== false) {
                $found = (string) $index;
                break;
            }
        }
        if ($found === '') {
            foreach ($normalized as $index => $header) {
                foreach ($candidates as $candidate) {
                    if ($header !== '' && strpos($header, $candidate) !== false) {
                        $found = (string) $index;
                        break 2;
                    }
                }
            }
        }
        $map[$field] = $found;
    }
    return $map;
}

/** Parse a CSV file into headers and rows. */
function import_parse_csv(string $path): array
{
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException('Could not read the uploaded file.');
    }
    $headers = [];
    $rows = [];
    $first = true;
    while (($row = fgetcsv($handle)) !== false) {
        if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
            continue;
        }
        if ($first) {
            $first = false;
            if (isset($row[0])) {
                $row[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
            }
            $headers = array_map(fn($v) => trim((string) $v), $row);
            continue;
        }
        $rows[] = $row;
    }
    fclose($handle);

    if ($headers === []) {
        throw new RuntimeException('The file has no header row.');
    }
    return ['headers' => $headers, 'rows' => $rows];
}

/** Build an associative field => value row using the column mapping. */
function import_apply_map(array $row, array $map): array
{
    $out = [];
    foreach ($map as $field => $index) {
        if ($index === '' || $index === null) {
            continue;
        }
        $out[$field] = (string) ($row[(int) $index] ?? '');
    }
    return $out;
}

/* =====================================================================
 * Upload storage
 * ===================================================================*/

function import_storage_dir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'umpcsl-imports';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/** Store an uploaded CSV and return its token. */
function import_save_upload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed. Please choose a CSV file.');
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('The file is larger than 5MB.');
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt'], true)) {
        throw new RuntimeException('Please upload a .csv file.');
    }
    $token = bin2hex(random_bytes(16)) . '.csv';
    $dest = import_storage_dir() . DIRECTORY_SEPARATOR . $token;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not store the uploaded file.');
    }
    return $token;
}

/** Resolve a token to a readable path, guarding against traversal. */
function import_token_path(string $token): string
{
    if (!preg_match('/^[a-f0-9]{32}\.csv$/', $token)) {
        throw new RuntimeException('Invalid import reference.');
    }
    $path = import_storage_dir() . DIRECTORY_SEPARATOR . $token;
    if (!is_file($path)) {
        throw new RuntimeException('The uploaded file has expired. Please upload it again.');
    }
    return $path;
}

/** Delete a stored upload. */
function import_delete_token(string $token): void
{
    if (!preg_match('/^[a-f0-9]{32}\.csv$/', $token)) {
        return;
    }
    $path = import_storage_dir() . DIRECTORY_SEPARATOR . $token;
    if (is_file($path)) {
        @unlink($path);
    }
}

/* =====================================================================
 * Value helpers
 * ===================================================================*/

function import_parse_date($value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

function import_gender($value): ?string
{
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return null;
    }
    if (in_array($value, ['m', 'male', 'man'], true)) {
        return 'male';
    }
    if (in_array($value, ['f', 'female', 'woman'], true)) {
        return 'female';
    }
    if (in_array($value, ['o', 'other'], true)) {
        return 'other';
    }
    return null;
}

function import_unique_email(string $first, string $last, string $membershipNo = ''): string
{
    $base = (string) preg_replace('/[^a-z0-9]+/', '.', strtolower($first . '.' . $last));
    $base = trim($base, '.');
    if ($base === '') {
        $base = $membershipNo !== '' ? (string) preg_replace('/[^a-z0-9]+/', '.', strtolower($membershipNo)) : 'member';
        $base = trim($base, '.') ?: 'member';
    }
    $email = $base . '@import.local';
    $n = 1;
    while ((int) fetch_value('SELECT COUNT(*) FROM users WHERE email = ?', [$email]) > 0) {
        $email = $base . $n . '@import.local';
        $n++;
    }
    return $email;
}

/** Find a member by membership number or email. */
function import_find_member($membershipNo, $email): ?array
{
    $membershipNo = trim((string) $membershipNo);
    $email = strtolower(trim((string) $email));
    if ($membershipNo !== '') {
        $member = fetch_one('SELECT * FROM members WHERE membership_no = ?', [$membershipNo]);
        if ($member) {
            return $member;
        }
    }
    if ($email !== '') {
        $member = fetch_one(
            'SELECT m.* FROM members m JOIN users u ON u.id = m.user_id WHERE u.email = ?',
            [$email]
        );
        if ($member) {
            return $member;
        }
    }
    return null;
}

/** Find a loan by loan number, or the member's most recent loan. */
function import_find_loan(array $row): ?array
{
    $loanNo = trim((string) ($row['loan_no'] ?? ''));
    if ($loanNo !== '') {
        $loan = fetch_one('SELECT * FROM loans WHERE loan_no = ?', [$loanNo]);
        if ($loan) {
            return $loan;
        }
    }
    $member = import_find_member($row['membership_no'] ?? '', '');
    if ($member) {
        return fetch_one('SELECT * FROM loans WHERE member_id = ? ORDER BY id DESC LIMIT 1', [(int) $member['id']]);
    }
    return null;
}

/* =====================================================================
 * Import runners
 * ===================================================================*/

/** Dispatch to the correct importer. */
function import_run(string $type, array $rows, bool $commit): array
{
    switch ($type) {
        case 'members':
            return import_members($rows, $commit);
        case 'savings':
            return import_savings($rows, $commit);
        case 'loans':
            return import_loans($rows, $commit);
        case 'repayments':
            return import_repayments($rows, $commit);
    }
    throw new RuntimeException('Unknown import type.');
}

function import_members(array $rows, bool $commit): array
{
    $report = ['total' => count($rows), 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    foreach ($rows as $i => $r) {
        $line = $i + 2;
        try {
            $first = trim((string) ($r['first_name'] ?? ''));
            $last = trim((string) ($r['last_name'] ?? ''));
            if ($first === '' || $last === '') {
                throw new RuntimeException('First and last name are required.');
            }
            $membershipNo = trim((string) ($r['membership_no'] ?? ''));
            $email = strtolower(trim((string) ($r['email'] ?? '')));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email address: ' . $email);
            }
            if ($membershipNo !== '' && fetch_one('SELECT id FROM members WHERE membership_no = ?', [$membershipNo])) {
                $report['skipped']++;
                $report['errors'][] = ['row' => $line, 'message' => 'Membership ' . $membershipNo . ' already exists — skipped.'];
                continue;
            }
            if ($email !== '' && (int) fetch_value('SELECT COUNT(*) FROM users WHERE email = ?', [$email]) > 0) {
                $report['skipped']++;
                $report['errors'][] = ['row' => $line, 'message' => 'Email ' . $email . ' already exists — skipped.'];
                continue;
            }
            if (!$commit) {
                $report['imported']++;
                continue;
            }

            $pdo = db();
            $pdo->beginTransaction();
            try {
                if ($email === '') {
                    $email = import_unique_email($first, $last, $membershipNo);
                }
                $password = trim((string) ($r['password'] ?? '')) ?: 'password123';
                $userId = db_insert('users', [
                    'email'         => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'full_name'     => $first . ' ' . $last,
                    'phone'         => trim((string) ($r['phone'] ?? '')) ?: null,
                    'role'          => 'member',
                    'status'        => 'active',
                ]);
                $memberNo = $membershipNo !== '' ? $membershipNo : next_reference('members', 'membership_no', 'UMP-' . date('y'), 5);
                $memberId = db_insert('members', [
                    'user_id'           => $userId,
                    'membership_no'     => $memberNo,
                    'first_name'        => $first,
                    'last_name'         => $last,
                    'other_names'       => trim((string) ($r['other_names'] ?? '')) ?: null,
                    'gender'            => import_gender($r['gender'] ?? ''),
                    'date_of_birth'     => import_parse_date($r['date_of_birth'] ?? ''),
                    'address'           => trim((string) ($r['address'] ?? '')) ?: null,
                    'occupation'        => trim((string) ($r['occupation'] ?? '')) ?: null,
                    'monthly_income'    => to_amount($r['monthly_income'] ?? 0),
                    'joined_at'         => import_parse_date($r['joined_at'] ?? '') ?? date('Y-m-d'),
                    'status'            => 'active',
                ]);
                create_savings_accounts($memberId);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            audit('import_member', 'members', $memberId, $memberNo . ' (' . $email . ')');
            $report['imported']++;
        } catch (Throwable $e) {
            $report['failed']++;
            $report['errors'][] = ['row' => $line, 'message' => $e->getMessage()];
        }
    }
    return $report;
}

function import_savings(array $rows, bool $commit): array
{
    $report = ['total' => count($rows), 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    foreach ($rows as $i => $r) {
        $line = $i + 2;
        try {
            $member = import_find_member($r['membership_no'] ?? '', $r['email'] ?? '');
            if (!$member) {
                $report['skipped']++;
                $report['errors'][] = ['row' => $line, 'message' => 'No matching member found — skipped.'];
                continue;
            }
            $amounts = [];
            foreach (array_keys(SAVINGS_PRODUCTS) as $type) {
                $value = trim((string) ($r[$type] ?? ''));
                if ($value === '') {
                    continue;
                }
                $amount = to_amount($value);
                if ($amount < 0) {
                    throw new RuntimeException('Negative balance for ' . label($type) . '.');
                }
                if ($amount > 0) {
                    $amounts[$type] = $amount;
                }
            }
            if ($amounts === []) {
                $report['skipped']++;
                $report['errors'][] = ['row' => $line, 'message' => 'No balances to import — skipped.'];
                continue;
            }
            if (!$commit) {
                $report['imported']++;
                continue;
            }
            $memberId = (int) $member['id'];
            foreach ($amounts as $type => $amount) {
                $account = get_savings_account($memberId, $type);
                q('UPDATE savings_accounts SET balance = balance + ? WHERE id = ?', [$amount, (int) $account['id']]);
            }
            $asOf = import_parse_date($r['as_of_date'] ?? '') ?? date('Y-m-d');
            import_post_opening_savings($memberId, $amounts, $asOf);
            audit('import_savings', 'members', $memberId, money(array_sum($amounts)) . ' opening balance');
            $report['imported']++;
        } catch (Throwable $e) {
            $report['failed']++;
            $report['errors'][] = ['row' => $line, 'message' => $e->getMessage()];
        }
    }
    return $report;
}

function import_loans(array $rows, bool $commit): array
{
    $settings = loan_settings();
    $report = ['total' => count($rows), 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    foreach ($rows as $i => $r) {
        $line = $i + 2;
        try {
            $member = import_find_member($r['membership_no'] ?? '', $r['email'] ?? '');
            if (!$member) {
                $report['skipped']++;
                $report['errors'][] = ['row' => $line, 'message' => 'No matching member found — skipped.'];
                continue;
            }
            $amount = to_amount($r['amount'] ?? 0);
            if ($amount <= 0) {
                throw new RuntimeException('A positive principal amount is required.');
            }
            $rate = trim((string) ($r['interest_rate'] ?? '')) !== '' ? (float) $r['interest_rate'] : (float) $settings['default_rate'];
            $term = trim((string) ($r['term_months'] ?? '')) !== '' ? max(1, (int) $r['term_months']) : 12;
            $total = trim((string) ($r['total_repayable'] ?? '')) !== '' && to_amount($r['total_repayable']) > 0
                ? to_amount($r['total_repayable'])
                : round($amount + ($amount * $rate / 100), 2);
            $outstanding = trim((string) ($r['outstanding'] ?? '')) !== ''
                ? max(0, to_amount($r['outstanding']))
                : $total;
            $status = strtolower(trim((string) ($r['status'] ?? '')));
            if (!in_array($status, ['disbursed', 'completed', 'defaulted'], true)) {
                $status = 'disbursed';
            }
            if ($outstanding <= 0) {
                $status = 'completed';
            }
            $disbursedAt = import_parse_date($r['disbursed_at'] ?? '') ?? date('Y-m-d');
            $loanNo = trim((string) ($r['loan_no'] ?? ''));
            if ($loanNo !== '' && fetch_one('SELECT id FROM loans WHERE loan_no = ?', [$loanNo])) {
                $report['skipped']++;
                $report['errors'][] = ['row' => $line, 'message' => 'Loan ' . $loanNo . ' already exists — skipped.'];
                continue;
            }
            if (!$commit) {
                $report['imported']++;
                continue;
            }

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $loanNo = $loanNo !== '' ? $loanNo : next_reference('loans', 'loan_no', 'LN-' . date('y') . '-', 5);
                $loanId = db_insert('loans', [
                    'loan_no'         => $loanNo,
                    'member_id'       => (int) $member['id'],
                    'amount'          => $amount,
                    'interest_rate'   => $rate,
                    'term_months'     => $term,
                    'purpose'         => trim((string) ($r['purpose'] ?? '')) ?: 'Legacy loan',
                    'status'          => $status,
                    'total_repayable' => $total,
                    'outstanding'     => $outstanding,
                    'applied_at'      => $disbursedAt . ' 00:00:00',
                    'disbursed_at'    => $disbursedAt . ' 00:00:00',
                    'remarks'         => trim((string) ($r['remarks'] ?? '')) ?: 'Imported from legacy data.',
                ]);
                generate_schedule($loanId, $total, $term);
                import_sync_schedule($loanId, round($total - $outstanding, 2));
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            import_post_opening_loan($loanId, $loanNo, $outstanding, $disbursedAt);
            audit('import_loan', 'loans', $loanId, $loanNo . ' ' . money($amount) . ' outstanding ' . money($outstanding));
            $report['imported']++;
        } catch (Throwable $e) {
            $report['failed']++;
            $report['errors'][] = ['row' => $line, 'message' => $e->getMessage()];
        }
    }
    return $report;
}

function import_repayments(array $rows, bool $commit): array
{
    $report = ['total' => count($rows), 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    foreach ($rows as $i => $r) {
        $line = $i + 2;
        try {
            $loan = import_find_loan($r);
            if (!$loan) {
                $report['skipped']++;
                $report['errors'][] = ['row' => $line, 'message' => 'No matching loan found — skipped.'];
                continue;
            }
            $amount = to_amount($r['amount'] ?? 0);
            if ($amount <= 0) {
                throw new RuntimeException('A positive repayment amount is required.');
            }
            if (!$commit) {
                $report['imported']++;
                continue;
            }
            record_loan_repayment((int) $loan['id'], $amount, [
                'method'    => trim((string) ($r['method'] ?? '')) ?: 'cash',
                'reference' => trim((string) ($r['reference'] ?? '')) ?: null,
                'narration' => trim((string) ($r['narration'] ?? '')) ?: ('Imported repayment for ' . $loan['loan_no']),
                'paid_at'   => (import_parse_date($r['paid_at'] ?? '') ?? date('Y-m-d')) . ' 00:00:00',
            ]);
            audit('import_repayment', 'loans', (int) $loan['id'], money($amount) . ' to ' . $loan['loan_no']);
            $report['imported']++;
        } catch (Throwable $e) {
            $report['failed']++;
            $report['errors'][] = ['row' => $line, 'message' => $e->getMessage()];
        }
    }
    return $report;
}

/** Mark schedule installments paid up to the already-repaid amount. */
function import_sync_schedule(int $loanId, float $paid): void
{
    if ($paid <= 0) {
        return;
    }
    $remaining = $paid;
    foreach (fetch_all('SELECT * FROM loan_schedule WHERE loan_id = ? ORDER BY installment ASC', [$loanId]) as $row) {
        if ($remaining <= 0) {
            break;
        }
        $due = round((float) $row['amount_due'] - (float) $row['amount_paid'], 2);
        if ($due <= 0) {
            continue;
        }
        $pay = min($remaining, $due);
        $newPaid = round((float) $row['amount_paid'] + $pay, 2);
        db_update('loan_schedule', (int) $row['id'], [
            'amount_paid' => $newPaid,
            'status'      => $newPaid >= (float) $row['amount_due'] ? 'paid' : 'partial',
        ]);
        $remaining = round($remaining - $pay, 2);
    }
}

/** Post an opening balance for imported savings (Dr 3200 / Cr 2000 & 3000). */
function import_post_opening_savings(int $memberId, array $amounts, string $date): void
{
    $savings = 0.0;
    $share = 0.0;
    foreach ($amounts as $type => $amount) {
        if ($type === 'share_capital') {
            $share += $amount;
        } else {
            $savings += $amount;
        }
    }
    safe_post_journal([
        'entry_date' => $date,
        'narration'  => 'Opening savings balance',
        'source'     => 'opening_balance',
        'source_id'  => $memberId,
        'lines'      => [
            ['code' => '3200', 'debit' => round($savings + $share, 2)],
            ['code' => '2000', 'credit' => round($savings, 2)],
            ['code' => '3000', 'credit' => round($share, 2)],
        ],
    ]);
}

/** Post an opening balance for an imported loan (Dr 1100 / Cr 3200). */
function import_post_opening_loan(int $loanId, string $loanNo, float $outstanding, string $date): void
{
    if ($outstanding <= 0) {
        return;
    }
    safe_post_journal([
        'entry_date' => $date,
        'narration'  => 'Opening loan balance ' . $loanNo,
        'reference'  => $loanNo,
        'source'     => 'opening_balance',
        'source_id'  => $loanId,
        'lines'      => [
            ['code' => '1100', 'debit' => $outstanding],
            ['code' => '3200', 'credit' => $outstanding],
        ],
    ]);
}

/* =====================================================================
 * Logging
 * ===================================================================*/

function import_log(string $type, ?string $filename, array $report, ?int $userId): void
{
    $imported = (int) ($report['imported'] ?? 0);
    $failed = (int) ($report['failed'] ?? 0);
    $errors = $report['errors'] ?? [];
    $status = $failed > 0 ? ($imported > 0 ? 'partial' : 'failed') : 'completed';
    db_insert('import_logs', [
        'batch_no'    => 'IMP-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3))),
        'import_type' => $type,
        'filename'    => $filename,
        'total_rows'  => (int) ($report['total'] ?? 0),
        'imported'    => $imported,
        'skipped'     => (int) ($report['skipped'] ?? 0),
        'failed'      => $failed,
        'status'      => $status,
        'details'     => $errors === [] ? null : json_encode(array_slice($errors, 0, 100)),
        'imported_by' => $userId,
    ]);
}

function list_import_logs(int $limit = 100): array
{
    return fetch_all(
        'SELECT il.*, u.full_name FROM import_logs il
         LEFT JOIN users u ON u.id = il.imported_by
         ORDER BY il.id DESC LIMIT ' . (int) $limit
    );
}
