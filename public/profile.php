<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = require_login(['member']);
$member = member_for_user((int) $user['id']);
$memberId = (int) $member['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) post('action', '');
    try {
        switch ($action) {
            case 'profile':
                update_member_profile($memberId, [
                    'first_name' => post('first_name'),
                    'last_name' => post('last_name'),
                    'other_names' => post('other_names'),
                    'gender' => post('gender'),
                    'date_of_birth' => post('date_of_birth'),
                    'marital_status' => post('marital_status'),
                    'address' => post('address'),
                    'occupation' => post('occupation'),
                    'monthly_income' => post('monthly_income'),
                    'bank_name' => post('bank_name'),
                    'bank_account_name' => post('bank_account_name'),
                    'bank_account_no' => post('bank_account_no'),
                ]);
                flash('success', 'Profile updated.');
                break;
            case 'photo':
                $name = save_passport_photo($_FILES['photo'] ?? [], $memberId);
                db_update('members', $memberId, ['passport_photo' => $name]);
                flash('success', 'Passport photograph updated.');
                break;
            case 'nok':
                db_insert('next_of_kin', [
                    'member_id' => $memberId,
                    'name' => (string) post('name'),
                    'relationship' => (string) post('relationship'),
                    'phone' => (string) post('phone'),
                    'address' => (string) post('address'),
                ]);
                flash('success', 'Next of kin added.');
                break;
            case 'nominee':
                db_insert('nominees', [
                    'member_id' => $memberId,
                    'name' => (string) post('name'),
                    'relationship' => (string) post('relationship'),
                    'phone' => (string) post('phone'),
                    'percentage' => to_amount(post('percentage', 0)),
                ]);
                flash('success', 'Nominee added.');
                break;
            case 'referral':
                db_insert('referrals', [
                    'member_id' => $memberId,
                    'referred_name' => (string) post('referred_name'),
                    'referred_phone' => (string) post('referred_phone'),
                    'referred_email' => (string) post('referred_email'),
                ]);
                flash('success', 'Referral submitted.');
                break;
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/profile.php');
}

$nok = fetch_all('SELECT * FROM next_of_kin WHERE member_id = ?', [$memberId]);
$nominees = fetch_all('SELECT * FROM nominees WHERE member_id = ?', [$memberId]);
$referrals = fetch_all('SELECT * FROM referrals WHERE member_id = ? ORDER BY id DESC', [$memberId]);

render_header('My Profile', $user);
?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card p-4 text-center">
            <img src="<?= e(photo_url($member['passport_photo'])) ?>" alt="Passport" class="passport-photo mx-auto mb-3">
            <div class="fw-semibold"><?= e($member['first_name'] . ' ' . $member['last_name']) ?></div>
            <div class="text-muted small mb-3"><?= e($member['membership_no']) ?></div>
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="photo">
                <input type="file" class="form-control form-control-sm mb-2" name="photo" accept="image/*" required>
                <button class="btn btn-sm btn-primary w-100">Upload photo</button>
            </form>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card p-4">
            <h5 class="mb-3">Personal &amp; bank details</h5>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="profile">
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">First name</label><input class="form-control" name="first_name" value="<?= e($member['first_name']) ?>"></div>
                    <div class="col-md-4"><label class="form-label">Last name</label><input class="form-control" name="last_name" value="<?= e($member['last_name']) ?>"></div>
                    <div class="col-md-4"><label class="form-label">Other names</label><input class="form-control" name="other_names" value="<?= e($member['other_names']) ?>"></div>
                    <div class="col-md-4"><label class="form-label">Gender</label>
                        <select class="form-select" name="gender">
                            <option value="">Select…</option>
                            <?php foreach (['male', 'female', 'other'] as $g): ?><option value="<?= $g ?>" <?= $member['gender'] === $g ? 'selected' : '' ?>><?= e(label($g)) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Date of birth</label><input type="date" class="form-control" name="date_of_birth" value="<?= e($member['date_of_birth']) ?>"></div>
                    <div class="col-md-4"><label class="form-label">Marital status</label>
                        <select class="form-select" name="marital_status">
                            <option value="">Select…</option>
                            <?php foreach (['single', 'married', 'divorced', 'widowed'] as $m): ?><option value="<?= $m ?>" <?= $member['marital_status'] === $m ? 'selected' : '' ?>><?= e(label($m)) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8"><label class="form-label">Address</label><input class="form-control" name="address" value="<?= e($member['address']) ?>"></div>
                    <div class="col-md-4"><label class="form-label">Occupation</label><input class="form-control" name="occupation" value="<?= e($member['occupation']) ?>"></div>
                    <div class="col-md-4"><label class="form-label">Monthly income</label><input type="number" step="0.01" class="form-control" name="monthly_income" value="<?= e($member['monthly_income']) ?>"></div>
                    <div class="col-md-4"><label class="form-label">Bank name</label><input class="form-control" name="bank_name" value="<?= e($member['bank_name']) ?>"></div>
                    <div class="col-md-4"><label class="form-label">Account name</label><input class="form-control" name="bank_account_name" value="<?= e($member['bank_account_name']) ?>"></div>
                    <div class="col-md-4"><label class="form-label">Account number</label><input class="form-control" name="bank_account_no" value="<?= e($member['bank_account_no']) ?>"></div>
                </div>
                <button class="btn btn-primary mt-3"><i class="bi bi-save"></i> Save changes</button>
            </form>
        </div>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-4">
        <div class="card p-4 h-100">
            <h6 class="mb-3">Next of kin</h6>
            <ul class="list-group list-group-flush mb-3">
                <?php foreach ($nok as $item): ?>
                    <li class="list-group-item px-0"><strong><?= e($item['name']) ?></strong><br><span class="text-muted small"><?= e($item['relationship']) ?> · <?= e($item['phone']) ?></span></li>
                <?php endforeach; ?>
                <?php if ($nok === []): ?><li class="list-group-item px-0 text-muted small">None added.</li><?php endif; ?>
            </ul>
            <form method="post" class="row g-2">
                <?= csrf_field() ?><input type="hidden" name="action" value="nok">
                <div class="col-12"><input class="form-control form-control-sm" name="name" placeholder="Full name" required></div>
                <div class="col-6"><input class="form-control form-control-sm" name="relationship" placeholder="Relationship"></div>
                <div class="col-6"><input class="form-control form-control-sm" name="phone" placeholder="Phone"></div>
                <div class="col-12"><input class="form-control form-control-sm" name="address" placeholder="Address"></div>
                <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Add next of kin</button></div>
            </form>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card p-4 h-100">
            <h6 class="mb-3">Nominees</h6>
            <ul class="list-group list-group-flush mb-3">
                <?php foreach ($nominees as $item): ?>
                    <li class="list-group-item px-0"><strong><?= e($item['name']) ?></strong><br><span class="text-muted small"><?= e($item['relationship']) ?> · <?= (float) $item['percentage'] ?>%</span></li>
                <?php endforeach; ?>
                <?php if ($nominees === []): ?><li class="list-group-item px-0 text-muted small">None added.</li><?php endif; ?>
            </ul>
            <form method="post" class="row g-2">
                <?= csrf_field() ?><input type="hidden" name="action" value="nominee">
                <div class="col-12"><input class="form-control form-control-sm" name="name" placeholder="Full name" required></div>
                <div class="col-6"><input class="form-control form-control-sm" name="relationship" placeholder="Relationship"></div>
                <div class="col-6"><input class="form-control form-control-sm" name="phone" placeholder="Phone"></div>
                <div class="col-6"><input type="number" step="0.01" class="form-control form-control-sm" name="percentage" placeholder="Share %"></div>
                <div class="col-6"><button class="btn btn-sm btn-outline-primary w-100">Add nominee</button></div>
            </form>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card p-4 h-100">
            <h6 class="mb-3">Referrals</h6>
            <ul class="list-group list-group-flush mb-3">
                <?php foreach ($referrals as $item): ?>
                    <li class="list-group-item px-0 d-flex justify-content-between"><span><?= e($item['referred_name']) ?></span><?= status_badge($item['status']) ?></li>
                <?php endforeach; ?>
                <?php if ($referrals === []): ?><li class="list-group-item px-0 text-muted small">No referrals yet.</li><?php endif; ?>
            </ul>
            <form method="post" class="row g-2">
                <?= csrf_field() ?><input type="hidden" name="action" value="referral">
                <div class="col-12"><input class="form-control form-control-sm" name="referred_name" placeholder="Name" required></div>
                <div class="col-6"><input class="form-control form-control-sm" name="referred_phone" placeholder="Phone"></div>
                <div class="col-6"><input class="form-control form-control-sm" name="referred_email" placeholder="Email"></div>
                <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Submit referral</button></div>
            </form>
        </div>
    </div>
</div>
<?php render_footer(); ?>
