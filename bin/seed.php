<?php
declare(strict_types=1);

/**
 * Seeds UMPCSL with organisation settings, an administrator account and
 * optional demo data. Usage:
 *     php bin/seed.php            # settings + admin + a few demo records
 *     php bin/seed.php --reset    # truncate all tables first
 */

const APP_ROOT = __DIR__ . '/..';

require_once APP_ROOT . '/src/helpers.php';
require_once APP_ROOT . '/src/db.php';
require_once APP_ROOT . '/src/auth.php';
require_once APP_ROOT . '/src/members.php';
require_once APP_ROOT . '/src/savings.php';
require_once APP_ROOT . '/src/loans.php';
require_once APP_ROOT . '/src/payments.php';
require_once APP_ROOT . '/src/reports.php';
require_once APP_ROOT . '/src/notifications.php';
require_once APP_ROOT . '/src/accounting.php';
require_once APP_ROOT . '/src/store.php';
require_once APP_ROOT . '/src/import.php';

$reset = in_array('--reset', $argv, true);

echo "UMPCSL seeder\n-------------\n";

if ($reset) {
    echo "• Resetting tables...\n";
    db()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['sale_items', 'sales', 'stock_movements', 'products', 'product_categories',
              'journal_lines', 'journals', 'accounts',
              'notifications', 'transactions', 'payments', 'loan_schedule', 'loan_collateral', 'loan_guarantors', 'loans',
              'savings_accounts', 'referrals', 'nominees', 'next_of_kin', 'members',
              'import_logs', 'audit_logs', 'users', 'settings'] as $table) {
        db()->exec("TRUNCATE TABLE `$table`");
    }
    db()->exec('SET FOREIGN_KEY_CHECKS = 1');
}

ensure_default_accounts();

// --- Settings -----------------------------------------------------------------
$settings = [
    'org_name'           => 'Unique Multi Purposes Cooperative Society Limited',
    'loan_max_multiple'  => '3',
    'loan_interest_rate' => '5',
    'loan_min_guarantors'=> '2',
];
foreach ($settings as $key => $value) {
    q('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$key, $value]);
}
echo "✓ Settings saved.\n";

// --- Administrator & staff ----------------------------------------------------
function ensure_user(string $email, string $password, string $name, string $role, string $phone = ''): int
{
    $existing = fetch_one('SELECT id FROM users WHERE email = ?', [$email]);
    if ($existing) {
        return (int) $existing['id'];
    }
    return db_insert('users', [
        'email'         => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'full_name'     => $name,
        'phone'         => $phone,
        'role'          => $role,
        'status'        => 'active',
    ]);
}

$adminId = ensure_user('admin@umpcsl.com', 'admin123', 'System Administrator', 'admin', '08030000000');
$staffId = ensure_user('staff@umpcsl.com', 'staff123', 'Grace Officer', 'staff', '08030000001');
echo "✓ Administrator: admin@umpcsl.com / admin123\n";
echo "✓ Staff account: staff@umpcsl.com / staff123\n";

// --- Demo members -------------------------------------------------------------
$demo = [
    ['Aisha', 'Bello', 'aisha@umpcsl.com', 'female', 25000],
    ['Musa', 'Ibrahim', 'musa@umpcsl.com', 'male', 40000],
    ['Grace', 'Okoro', 'grace@umpcsl.com', 'female', 60000],
];

$memberIds = [];
foreach ($demo as [$first, $last, $email, $gender, $income]) {
    if (fetch_one('SELECT id FROM users WHERE email = ?', [$email])) {
        continue;
    }
    $userId = register_member([
        'first_name' => $first, 'last_name' => $last, 'email' => $email,
        'password' => 'password123', 'phone' => '0803000' . random_int(1000, 9999),
        'gender' => $gender, 'monthly_income' => $income, 'occupation' => 'Entrepreneur',
        'address' => 'Kaduna, Nigeria',
    ]);
    $member = member_for_user($userId);
    $memberIds[] = (int) $member['id'];
    echo "✓ Member {$member['membership_no']}: $email / password123\n";
}

if ($memberIds === []) {
    echo "• Demo members already present, skipping demo transactions.\n";
    exit(0);
}

// Savings contributions
foreach ($memberIds as $i => $id) {
    record_savings_contribution($id, 'monthly', 20000 + ($i * 5000));
    record_savings_contribution($id, 'share_capital', 10000 + ($i * 5000));
    record_savings_contribution($id, 'special_land', 5000);
}
echo "✓ Demo savings contributions posted.\n";

// A loan for the first two members: apply, approve, disburse, part-repay
foreach (array_slice($memberIds, 0, 2) as $index => $id) {
    $loanId = apply_for_loan($id, [
        'amount' => 30000 + ($index * 20000),
        'term_months' => 6,
        'interest_rate' => 5,
        'purpose' => 'Business expansion',
    ], [
        ['name' => 'Guarantor One', 'phone' => '08000000001', 'relationship' => 'Friend', 'amount' => 15000],
        ['name' => 'Guarantor Two', 'phone' => '08000000002', 'relationship' => 'Colleague', 'amount' => 15000],
    ]);
    approve_loan($loanId, $staffId, null, 'Approved during seeding.');
    disburse_loan($loanId, $staffId);
    record_loan_repayment($loanId, 5000, ['method' => 'cash', 'narration' => 'Demo repayment']);
}
echo "✓ Demo loans created, approved, disbursed and part-repaid.\n";

// Store catalogue and a demo sale
$_SESSION['user_id'] = $staffId;
$categories = [];
foreach (['Foodstuff', 'Farm Inputs', 'Household'] as $cat) {
    $categories[$cat] = save_category(null, $cat, null);
}
$catalogue = [
    ['RICE-50',  'Rice (50kg bag)',        'Foodstuff',   'bag',    42000, 48000, 30, 5],
    ['BEANS-25', 'Beans (25kg bag)',       'Foodstuff',   'bag',    28000, 33000, 20, 5],
    ['OIL-5L',   'Vegetable Oil (5L)',     'Foodstuff',   'unit',   6500,  7800,  50, 10],
    ['FERT-NPK', 'NPK Fertiliser (50kg)',  'Farm Inputs', 'bag',    18000, 22000, 40, 8],
    ['SEED-MZ',  'Maize Seed (1kg)',       'Farm Inputs', 'kg',     2500,  3500,  80, 15],
    ['DET-1KG',  'Detergent (1kg)',        'Household',   'pack',   1800,  2500,  100, 20],
];
$productIds = [];
foreach ($catalogue as [$sku, $name, $cat, $unit, $cost, $price, $stock, $reorder]) {
    $pid = save_product(null, [
        'sku' => $sku, 'name' => $name, 'category_id' => $categories[$cat], 'unit' => $unit,
        'cost_price' => $cost, 'price' => $price, 'opening_stock' => $stock,
        'reorder_level' => $reorder, 'is_active' => 1, 'is_online' => 1,
        'description' => $name . ' available at the cooperative store.',
    ]);
    $productIds[$sku] = $pid;
}
create_sale([
    'customer_name' => 'Walk-in customer',
    'channel' => 'store', 'status' => 'completed', 'payment_method' => 'cash',
], [
    ['product_id' => $productIds['RICE-50'], 'quantity' => 2],
    ['product_id' => $productIds['OIL-5L'], 'quantity' => 3],
]);
create_sale([
    'customer_name' => 'Online shopper',
    'customer_phone' => '08031234567', 'customer_email' => 'shopper@example.com',
    'channel' => 'online', 'status' => 'pending',
], [
    ['product_id' => $productIds['FERT-NPK'], 'quantity' => 1],
    ['product_id' => $productIds['SEED-MZ'], 'quantity' => 4],
]);
echo "✓ Demo store catalogue and sales created.\n";

echo "Seeding complete.\n";
