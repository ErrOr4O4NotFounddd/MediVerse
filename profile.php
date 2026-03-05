<?php
include_once('includes/db_config.php');
include_once('includes/header.php');

$divisions_stmt = $conn->prepare("SELECT id, name FROM divisions ORDER BY name ASC");
$divisions_stmt->execute();
$divisions = $divisions_stmt->get_result();

// Security: Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$user_id = $_SESSION['user_id'];
$error = ''; $success = '';

// --- Handle ALL Form Submissions ---
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // 1. Handle Profile Picture Upload
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === 0) {
        $upload_dir = 'uploads/profile_pics/';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
        $file = $_FILES['profile_image'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];

        if (in_array($file['type'], $allowed_types) && $file['size'] <= 2 * 1024 * 1024) {
            $old_pic_stmt = $conn->prepare("SELECT profile_image FROM users WHERE id = ?");
            $old_pic_stmt->bind_param("i", $user_id);
            $old_pic_stmt->execute();
            $old_pic_q = $old_pic_stmt->get_result();
            if($old_pic_q && $old_pic_q->num_rows > 0) {
                $old_pic_path = $old_pic_q->fetch_assoc()['profile_image'];
                if ($old_pic_path && file_exists($old_pic_path)) { @unlink($old_pic_path); }
            }
            
            $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $new_filename = "user_{$user_id}_" . time() . "." . $file_extension;
            $new_filepath = $upload_dir . $new_filename;

            if (move_uploaded_file($file['tmp_name'], $new_filepath)) {
                $stmt = $conn->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                $stmt->bind_param("si", $new_filepath, $user_id);
                if ($stmt->execute()) {
                    $_SESSION['user_profile_image'] = $new_filepath;
                    $success = "প্রোফাইল ছবি সফলভাবে আপডেট হয়েছে।";
                }
            }
        } else { $error = "অবৈধ ফাইল। শুধুমাত্র JPG, PNG, GIF (সর্বোচ্চ 2MB) আপলোড করা যাবে।"; }
    }
    // 2. Handle Profile Information Update
    elseif (isset($_POST['update_profile'])) {
        $full_name = $_POST['full_name'];
        $phone = $_POST['phone'];
        $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        $blood_group = !empty($_POST['blood_group']) ? $_POST['blood_group'] : null;

        $stmt = $conn->prepare("UPDATE users SET full_name = ?, phone = ?, date_of_birth = ?, blood_group = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $full_name, $phone, $date_of_birth, $blood_group, $user_id);
        if ($stmt->execute()) {
            $_SESSION['user_full_name'] = $full_name;
            $success = "আপনার প্রোফাইল সফলভাবে আপডেট করা হয়েছে।";
        }
    }
    // 3. Handle Password Change                                                                                                
    elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_new_password = $_POST['confirm_new_password'];

        if ($new_password !== $confirm_new_password) {
            $error = "নতুন পাসওয়ার্ড এবং কনফার্ম পাসওয়ার্ড মিলছে না।";
        } elseif (strlen($new_password) < 6) {
            $error = "নতুন পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের হতে হবে।";
        } else {
            $user_pass_stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
            $user_pass_stmt->bind_param("i", $user_id);
            $user_pass_stmt->execute();
            $user_data = $user_pass_stmt->get_result()->fetch_assoc();
            if (password_verify($current_password, $user_data['password_hash'])) {
                $new_password_hash = password_hash($new_password, PASSWORD_BCRYPT);
                $update_pass_stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $update_pass_stmt->bind_param("si", $new_password_hash, $user_id);
                if ($update_pass_stmt->execute()) { $success = "পাসওয়ার্ড সফলভাবে পরিবর্তন করা হয়েছে।"; }
            } else { $error = "আপনার বর্তমান পাসওয়ার্ডটি সঠিক নয়।"; }
        }
    }
    // 4. Handle "Register as Donor" Action
    elseif (isset($_POST['register_as_donor'])) {
        $division_id = $_POST['division_id'];
        $district_id = $_POST['district_id'];
        $user_stmt = $conn->prepare("SELECT blood_group FROM users WHERE id = ?");
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_q = $user_stmt->get_result();
        $user_data = $user_q->fetch_assoc();
        if (empty($user_data['blood_group'])) {
            $error = "রক্তদাতা হিসেবে রেজিস্টার করার আগে, অনুগ্রহ করে 'ব্যক্তিগত তথ্য' সেকশন থেকে আপনার রক্তের গ্রুপ সেট করুন।";
        } else {
            $stmt = $conn->prepare("UPDATE users SET is_donor = 1, donor_availability = 'Available', district_id = ? WHERE id = ?");
            $stmt->bind_param("ii", $district_id, $user_id);
            if ($stmt->execute()) { $success = "রক্তদাতা হিসেবে আপনাকে সফলভাবে নিবন্ধন করা হয়েছে। ধন্যবাদ!"; }
        }
    }
    // 5. Handle "Update Donor Info" Action
    elseif (isset($_POST['update_donor_info'])) {
        $current_stmt = $conn->prepare("SELECT donor_availability FROM users WHERE id = ?");
        $current_stmt->bind_param("i", $user_id);
        $current_stmt->execute();
        $current_data = $current_stmt->get_result()->fetch_assoc();
        $donor_availability = $current_data['donor_availability'] ?? 'Available';
        $current_stmt->close();
        
        $division_id = $_POST['division_id'];
        $district_id = $_POST['district_id'];
        $last_donation_date = !empty($_POST['last_donation_date']) ? $_POST['last_donation_date'] : null;
        $stmt = $conn->prepare("UPDATE users SET donor_availability = ?, district_id = ?, last_donation_date = ? WHERE id = ?");
        $stmt->bind_param("sisi", $donor_availability, $district_id, $last_donation_date, $user_id);
        if ($stmt->execute()) { $success = "আপনার ডোনার তথ্য সফলভাবে আপডেট করা হয়েছে।"; }
    }
} catch (mysqli_sql_exception $e) {
    if ($conn->errno === 1062) { $error = "এই ফোন নম্বর বা ইমেইলটি ইতিমধ্যে ব্যবহৃত হয়েছে।"; } 
    else { $error = "একটি অপ্রত্যাশিত ডেটাবেস ত্রুটি ঘটেছে।"; }
}

// Fetch current user data
$user_stmt = $conn->prepare("SELECT u.full_name, u.email, u.phone, u.date_of_birth, u.blood_group, u.profile_image, u.is_donor, u.donor_availability, d.division_id, u.district_id, u.last_donation_date FROM users u LEFT JOIN districts d ON u.district_id = d.id WHERE u.id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();

// Calculate member since
$member_stmt = $conn->prepare("SELECT created_at FROM users WHERE id = ?");
$member_stmt->bind_param("i", $user_id);
$member_stmt->execute();
$member_data = $member_stmt->get_result()->fetch_assoc();
$member_since = $member_data ? date('F Y', strtotime($member_data['created_at'])) : 'N/A';

// Count bookings
$booking_stmt = $conn->prepare("SELECT COUNT(*) as total FROM vw_user_bookings WHERE user_id = ?");
$booking_stmt->bind_param("i", $user_id);
$booking_stmt->execute();
$booking_count = $booking_stmt->get_result()->fetch_assoc()['total'] ?? 0;
?>

<style>
/* ===================================
   Profile Page — Premium Design
   =================================== */
.profile-page {
    max-width: 900px;
    margin: 0 auto;
    padding: 40px 20px 60px;
}

/* --- Profile Header Card --- */
.profile-header-card {
    background: linear-gradient(135deg, #1a8a4a 0%, #27ae60 40%, #2ecc71 100%);
    border-radius: 20px;
    padding: 40px;
    color: white;
    position: relative;
    overflow: hidden;
    margin-bottom: 30px;
    box-shadow: 0 15px 40px rgba(39, 174, 96, 0.3);
}

.profile-header-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 400px;
    height: 400px;
    border-radius: 50%;
    background: rgba(255,255,255,0.06);
    pointer-events: none;
}

.profile-header-card::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -10%;
    width: 300px;
    height: 300px;
    border-radius: 50%;
    background: rgba(255,255,255,0.04);
    pointer-events: none;
}

.profile-header-inner {
    display: flex;
    align-items: center;
    gap: 30px;
    position: relative;
    z-index: 1;
}

.profile-avatar-wrapper {
    position: relative;
    flex-shrink: 0;
}

.profile-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 4px solid rgba(255,255,255,0.5);
    overflow: hidden;
    background: rgba(255,255,255,0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 25px rgba(0,0,0,0.2);
    transition: transform 0.3s ease;
}

.profile-avatar:hover {
    transform: scale(1.05);
}

.profile-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-avatar .avatar-initial {
    font-size: 52px;
    font-weight: 700;
    color: white;
    text-transform: uppercase;
}

.avatar-edit-badge {
    position: absolute;
    bottom: 4px;
    right: 4px;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: white;
    color: var(--primary-color);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 3px 10px rgba(0,0,0,0.2);
    transition: all 0.3s ease;
    font-size: 14px;
}

.avatar-edit-badge:hover {
    transform: scale(1.1);
    background: #f0fff0;
}

.profile-header-info {
    flex: 1;
}

.profile-header-info h1 {
    font-size: 28px;
    font-weight: 700;
    margin: 0 0 6px 0;
    line-height: 1.2;
}

.profile-header-info .profile-email {
    font-size: 15px;
    opacity: 0.85;
    margin: 0 0 16px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.profile-meta-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.meta-chip {
    background: rgba(255,255,255,0.18);
    backdrop-filter: blur(8px);
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 6px;
    border: 1px solid rgba(255,255,255,0.2);
}

.meta-chip i {
    font-size: 12px;
}

/* --- Notification Toasts --- */
.profile-toast {
    padding: 14px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 600;
    font-size: 15px;
    animation: slideDown 0.4s ease;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.profile-toast.toast-error {
    background: linear-gradient(135deg, #fff5f5 0%, #ffe0e0 100%);
    color: #c0392b;
    border: 1px solid #f5c6cb;
}

.profile-toast.toast-success {
    background: linear-gradient(135deg, #f0fff4 0%, #d4edda 100%);
    color: #155724;
    border: 1px solid #c3e6cb;
}

.profile-toast i {
    font-size: 20px;
    flex-shrink: 0;
}

/* --- Tab Navigation --- */
.profile-tabs {
    display: flex;
    gap: 6px;
    margin-bottom: 0;
    background: var(--white-color);
    border-radius: 16px 16px 0 0;
    padding: 8px 8px 0 8px;
    box-shadow: 0 -2px 15px rgba(0,0,0,0.04);
    overflow-x: auto;
}

.profile-tab-btn {
    padding: 14px 22px;
    border: none;
    background: transparent;
    cursor: pointer;
    font-size: 15px;
    font-weight: 600;
    color: #777;
    border-radius: 12px 12px 0 0;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
    font-family: 'Hind Siliguri', sans-serif;
    position: relative;
}

.profile-tab-btn:hover {
    color: var(--primary-color);
    background: #f0fff4;
}

.profile-tab-btn.active {
    color: var(--primary-color);
    background: var(--white-color);
    box-shadow: 0 -3px 8px rgba(39,174,96,0.1);
}

.profile-tab-btn.active::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 20%;
    right: 20%;
    height: 3px;
    background: var(--primary-color);
    border-radius: 3px 3px 0 0;
}

.profile-tab-btn i {
    font-size: 16px;
}

/* --- Tab Content --- */
.profile-tab-content {
    background: var(--white-color);
    border-radius: 0 0 16px 16px;
    padding: 30px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.06);
    display: none;
    animation: fadeIn 0.4s ease;
}

.profile-tab-content.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

/* --- Section Titles --- */
.pf-section-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--secondary-color);
    margin: 0 0 6px 0;
}

.pf-section-desc {
    font-size: 14px;
    color: #888;
    margin: 0 0 25px 0;
}

/* --- Form Styles --- */
.pf-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.pf-form-grid .full-width {
    grid-column: 1 / -1;
}

.pf-field {
    margin-bottom: 0;
}

.pf-field label {
    display: block;
    font-weight: 600;
    font-size: 14px;
    color: var(--secondary-color);
    margin-bottom: 8px;
}

.pf-field label i {
    margin-right: 6px;
    color: var(--primary-color);
    width: 16px;
    text-align: center;
}

.pf-field input,
.pf-field select {
    width: 100%;
    box-sizing: border-box;
    padding: 12px 16px;
    border: 1.5px solid #e8ecef;
    border-radius: 10px;
    font-size: 15px;
    font-family: 'Hind Siliguri', sans-serif;
    background: #fafbfc;
    color: var(--text-color);
    transition: all 0.3s ease;
    outline: none;
}

.pf-field input:focus,
.pf-field select:focus {
    border-color: var(--primary-color);
    background: white;
    box-shadow: 0 0 0 4px rgba(39,174,96,0.08);
}

.pf-field input:disabled {
    background: #f1f3f5;
    color: #999;
    cursor: not-allowed;
}

.pf-field .field-hint {
    font-size: 12px;
    color: #aaa;
    margin-top: 4px;
}

/* --- Buttons --- */
.pf-btn {
    padding: 13px 28px;
    border: none;
    border-radius: 10px;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Hind Siliguri', sans-serif;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 10px;
}

.pf-btn-primary {
    background: linear-gradient(135deg, #27ae60, #2ecc71);
    color: white;
    box-shadow: 0 4px 15px rgba(39,174,96,0.3);
}

.pf-btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(39,174,96,0.4);
}

.pf-btn-warning {
    background: linear-gradient(135deg, #e67e22, #f39c12);
    color: white;
    box-shadow: 0 4px 15px rgba(230,126,34,0.3);
}

.pf-btn-warning:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(230,126,34,0.4);
}

.pf-btn-danger {
    background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
    color: white;
    box-shadow: 0 4px 15px rgba(231,76,60,0.3);
    letter-spacing: 0.5px;
}

.pf-btn-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(231,76,60,0.45);
}

/* --- Donor Section --- */
.donor-status-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 25px;
}

.donor-status-banner.is-available {
    background: linear-gradient(135deg, #f0fff4, #d4edda);
    border: 1.5px solid #a3d9b1;
}

.donor-status-banner.is-unavailable {
    background: linear-gradient(135deg, #fff8f0, #ffecd2);
    border: 1.5px solid #f0c89a;
}

.donor-status-text {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    font-size: 15px;
}

.donor-status-text .status-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    animation: pulse-dot 2s infinite;
}

@keyframes pulse-dot {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.6; transform: scale(1.3); }
}

.donor-status-text .status-dot.green { background: #27ae60; }
.donor-status-text .status-dot.orange { background: #e67e22; }

.pf-toggle-btn {
    padding: 8px 20px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Hind Siliguri', sans-serif;
    transition: all 0.3s ease;
}

.pf-toggle-btn.toggle-available {
    background: #27ae60;
    color: white;
}

.pf-toggle-btn.toggle-unavailable {
    background: #e67e22;
    color: white;
}

.pf-toggle-btn:hover {
    transform: scale(1.05);
}

/* --- Donor CTA (not registered) --- */
.donor-cta-card {
    background: linear-gradient(135deg, #fff5f5 0%, #ffe8e8 100%);
    border: 2px dashed #e74c3c;
    border-radius: 16px;
    padding: 35px;
    text-align: center;
}

.donor-cta-card .cta-icon {
    font-size: 50px;
    margin-bottom: 15px;
}

.donor-cta-card h3 {
    color: #c0392b;
    font-size: 22px;
    margin: 0 0 10px 0;
}

.donor-cta-card p {
    color: #666;
    margin: 0 0 25px 0;
    font-size: 15px;
    max-width: 480px;
    margin-left: auto;
    margin-right: auto;
}

.donor-cta-card .pf-form-grid {
    text-align: left;
    max-width: 500px;
    margin: 0 auto;
}

/* --- Profile Photo Upload Section --- */
.photo-upload-area {
    border: 2px dashed #d0d5dd;
    border-radius: 14px;
    padding: 30px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    background: #fafbfc;
    margin-top: 20px;
}

.photo-upload-area:hover {
    border-color: var(--primary-color);
    background: #f0fff4;
}

.photo-upload-area.dragover {
    border-color: var(--primary-color);
    background: #e8faf0;
    transform: scale(1.01);
}

.photo-upload-area i {
    font-size: 36px;
    color: #bbb;
    margin-bottom: 10px;
    display: block;
}

.photo-upload-area .upload-text {
    font-weight: 600;
    font-size: 16px;
    color: #555;
    margin: 0 0 4px 0;
}

.photo-upload-area .upload-hint {
    font-size: 13px;
    color: #aaa;
    margin: 0;
}

/* --- Divider --- */
.pf-divider {
    border: 0;
    border-top: 1.5px solid #f0f2f5;
    margin: 30px 0;
}

/* --- Responsive --- */
@media (max-width: 768px) {
    .profile-page { padding: 20px 15px 40px; }
    .profile-header-card { padding: 25px; }
    .profile-header-inner { flex-direction: column; text-align: center; }
    .profile-header-info .profile-email { justify-content: center; }
    .profile-meta-chips { justify-content: center; }
    .profile-avatar { width: 100px; height: 100px; }
    .profile-avatar .avatar-initial { font-size: 42px; }
    .pf-form-grid { grid-template-columns: 1fr; }
    .profile-tabs { gap: 2px; padding: 6px 6px 0 6px; }
    .profile-tab-btn { padding: 12px 14px; font-size: 13px; }
    .profile-tab-content { padding: 20px; }
    .donor-status-banner { flex-direction: column; gap: 12px; }
    .profile-header-info h1 { font-size: 22px; }
}

/* Select2 overrides for profile page */
.pf-field .select2-container--default .select2-selection--single {
    height: 48px !important;
    border: 1.5px solid #e8ecef !important;
    border-radius: 10px !important;
    padding: 10px 14px;
    background: #fafbfc;
    transition: all 0.3s ease;
}

.pf-field .select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 46px !important;
    right: 8px;
}

.pf-field .select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 28px;
    font-size: 15px;
    color: var(--text-color);
}

.pf-field .select2-container--default.select2-container--focus .select2-selection--single {
    border-color: var(--primary-color) !important;
    box-shadow: 0 0 0 4px rgba(39,174,96,0.08);
}
</style>

<main>
    <div class="profile-page">

        <!-- Hero Header Card -->
        <div class="profile-header-card">
            <div class="profile-header-inner">
                <form action="profile.php" method="POST" enctype="multipart/form-data" id="avatar-form">
                    <div class="profile-avatar-wrapper">
                        <div class="profile-avatar">
                            <?php if (!empty($user['profile_image']) && file_exists($user['profile_image'])): ?>
                                <img src="<?= htmlspecialchars($user['profile_image']) ?>" alt="Profile">
                            <?php else: ?>
                                <span class="avatar-initial"><?= mb_substr($user['full_name'], 0, 1) ?></span>
                            <?php endif; ?>
                        </div>
                        <label for="profile_image_upload" class="avatar-edit-badge" title="ছবি পরিবর্তন করুন">
                            <i class="fas fa-camera"></i>
                        </label>
                        <input type="file" name="profile_image" id="profile_image_upload" style="display:none;" accept="image/*">
                    </div>
                </form>
                <div class="profile-header-info">
                    <h1><?= htmlspecialchars($user['full_name']) ?></h1>
                    <p class="profile-email"><i class="fas fa-envelope"></i> <?= htmlspecialchars($user['email']) ?></p>
                    <div class="profile-meta-chips">
                        <?php if (!empty($user['phone'])): ?>
                            <span class="meta-chip"><i class="fas fa-phone"></i> <?= htmlspecialchars($user['phone']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($user['blood_group'])): ?>
                            <span class="meta-chip"><i class="fas fa-tint"></i> <?= htmlspecialchars($user['blood_group']) ?></span>
                        <?php endif; ?>
                        <?php if ($user['is_donor']): ?>
                            <span class="meta-chip"><i class="fas fa-heart"></i> রক্তদাতা</span>
                        <?php endif; ?>
                        <span class="meta-chip"><i class="fas fa-calendar-alt"></i> সদস্য: <?= $member_since ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Toast Messages -->
        <?php if($error): ?>
            <div class="profile-toast toast-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if($success): ?>
            <div class="profile-toast toast-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="profile-tabs">
            <button class="profile-tab-btn active" data-tab="personal" id="tab-btn-personal">
                <i class="fas fa-user"></i> ব্যক্তিগত তথ্য
            </button>
            <button class="profile-tab-btn" data-tab="password" id="tab-btn-password">
                <i class="fas fa-lock"></i> পাসওয়ার্ড
            </button>
            <button class="profile-tab-btn" data-tab="donor" id="tab-btn-donor">
                <i class="fas fa-hand-holding-heart"></i> রক্তদান
            </button>
            <button class="profile-tab-btn" data-tab="photo" id="tab-btn-photo">
                <i class="fas fa-image"></i> প্রোফাইল ছবি
            </button>
        </div>

        <!-- TAB 1: Personal Info -->
        <div class="profile-tab-content active" id="tab-personal">
            <h3 class="pf-section-title">ব্যক্তিগত তথ্য আপডেট করুন</h3>
            <p class="pf-section-desc">আপনার নাম, ফোন নম্বর, জন্মতারিখ এবং রক্তের গ্রুপ আপডেট করুন।</p>
            
            <form action="profile.php" method="POST">
                <div class="pf-form-grid">
                    <div class="pf-field full-width">
                        <label><i class="fas fa-user"></i> পুরো নাম</label>
                        <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" required placeholder="আপনার পুরো নাম লিখুন">
                    </div>
                    <div class="pf-field full-width">
                        <label><i class="fas fa-envelope"></i> ইমেইল</label>
                        <input type="email" value="<?= htmlspecialchars($user['email']) ?>" disabled>
                        <span class="field-hint">ইমেইল পরিবর্তনযোগ্য নয়।</span>
                    </div>
                    <div class="pf-field">
                        <label><i class="fas fa-phone"></i> ফোন নম্বর</label>
                        <input type="text" name="phone" value="<?= htmlspecialchars($user['phone']) ?>" required placeholder="01XXXXXXXXX">
                    </div>
                    <div class="pf-field">
                        <label><i class="fas fa-calendar"></i> জন্মতারিখ</label>
                        <input type="date" name="date_of_birth" value="<?= htmlspecialchars($user['date_of_birth']) ?>">
                    </div>
                    <div class="pf-field full-width">
                        <label><i class="fas fa-tint"></i> রক্তের গ্রুপ</label>
                        <select name="blood_group" id="blood_group_select" style="width:100%;">
                            <option value=""></option>
                            <?php
                            $blood_groups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
                            foreach ($blood_groups as $bg) {
                                $selected = ($user['blood_group'] == $bg) ? 'selected' : '';
                                echo "<option value='$bg' $selected>$bg</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <button type="submit" name="update_profile" class="pf-btn pf-btn-primary">
                    <i class="fas fa-save"></i> তথ্য আপডেট করুন
                </button>
            </form>
        </div>

        <!-- TAB 2: Password -->
        <div class="profile-tab-content" id="tab-password">
            <h3 class="pf-section-title">পাসওয়ার্ড পরিবর্তন করুন</h3>
            <p class="pf-section-desc">আপনার অ্যাকাউন্টের নিরাপত্তার জন্য একটি শক্তিশালী পাসওয়ার্ড ব্যবহার করুন।</p>

            <form action="profile.php" method="POST">
                <div class="pf-form-grid">
                    <div class="pf-field full-width">
                        <label><i class="fas fa-key"></i> বর্তমান পাসওয়ার্ড</label>
                        <input type="password" name="current_password" required placeholder="আপনার বর্তমান পাসওয়ার্ড">
                    </div>
                    <div class="pf-field">
                        <label><i class="fas fa-lock"></i> নতুন পাসওয়ার্ড</label>
                        <input type="password" name="new_password" required placeholder="কমপক্ষে ৬ অক্ষর">
                    </div>
                    <div class="pf-field">
                        <label><i class="fas fa-shield-alt"></i> কনফার্ম নতুন পাসওয়ার্ড</label>
                        <input type="password" name="confirm_new_password" required placeholder="পুনরায় লিখুন">
                    </div>
                </div>
                <button type="submit" name="change_password" class="pf-btn pf-btn-warning">
                    <i class="fas fa-sync-alt"></i> পাসওয়ার্ড পরিবর্তন করুন
                </button>
            </form>
        </div>

        <!-- TAB 3: Donor -->
        <div class="profile-tab-content" id="tab-donor">
            <?php if ($user['is_donor']): ?>
                <h3 class="pf-section-title">রক্তদাতা তথ্য</h3>
                <p class="pf-section-desc">আপনার ডোনার তথ্য ও প্রাপ্যতা আপডেট করুন।</p>

                <div class="donor-status-banner <?= ($user['donor_availability'] === 'Available') ? 'is-available' : 'is-unavailable' ?>" id="donor-banner">
                    <div class="donor-status-text">
                        <span class="status-dot <?= ($user['donor_availability'] === 'Available') ? 'green' : 'orange' ?>" id="status-dot"></span>
                        <span id="status-label">আপনি বর্তমানে: <strong id="status-value"><?= htmlspecialchars($user['donor_availability'] === 'Available' ? 'একটিভ — রক্তদানে প্রস্তুত' : 'ইনএকটিভ — বর্তমানে প্রস্তুত নন') ?></strong></span>
                    </div>
                    <button id="toggle-availability" class="pf-toggle-btn <?= ($user['donor_availability'] === 'Available') ? 'toggle-available' : 'toggle-unavailable' ?>" data-current="<?= htmlspecialchars($user['donor_availability']) ?>">
                        <i class="fas fa-exchange-alt"></i> 
                        <?= ($user['donor_availability'] === 'Available') ? 'ইনএকটিভ করুন' : 'একটিভ করুন' ?>
                    </button>
                </div>

                <?php 
                $divisions_stmt = $conn->prepare("SELECT id, name FROM divisions ORDER BY name ASC");
                $divisions_stmt->execute();
                $divisions = $divisions_stmt->get_result();
                
                $current_division_id = $user['division_id'] ?? 0;
                $current_district_id = $user['district_id'] ?? 0;
                
                $districts_for_form = null;
                if ($current_division_id) {
                    $districts_stmt2 = $conn->prepare("SELECT id, name FROM districts WHERE division_id = ? ORDER BY name ASC");
                    $districts_stmt2->bind_param("i", $current_division_id);
                    $districts_stmt2->execute();
                    $districts_for_form = $districts_stmt2->get_result();
                    $districts_stmt2->close();
                }
                ?>

                <form action="profile.php" method="POST">
                    <div class="pf-form-grid">
                        <div class="pf-field">
                            <label><i class="fas fa-map-marker-alt"></i> বিভাগ</label>
                            <select name="division_id" id="donor_division_id" required>
                                <option value="">-- বিভাগ নির্বাচন করুন --</option>
                                <?php while($div = $divisions->fetch_assoc()): ?>
                                    <option value="<?= $div['id'] ?>" <?= ($current_division_id == $div['id']) ? 'selected' : '' ?>><?= $div['name'] ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="pf-field">
                            <label><i class="fas fa-building"></i> জেলা</label>
                            <select name="district_id" id="donor_district_id" required>
                                <option value="">-- জেলা নির্বাচন করুন --</option>
                                <?php if($districts_for_form): while($dist = $districts_for_form->fetch_assoc()): ?>
                                    <option value="<?= $dist['id'] ?>" <?= ($current_district_id == $dist['id']) ? 'selected' : '' ?>><?= $dist['name'] ?></option>
                                <?php endwhile; endif; ?>
                            </select>
                        </div>
                        <div class="pf-field full-width">
                            <label><i class="fas fa-calendar-check"></i> শেষ রক্তদানের তারিখ</label>
                            <input type="date" name="last_donation_date" value="<?= htmlspecialchars($user['last_donation_date']) ?>">
                        </div>
                    </div>
                    <button type="submit" name="update_donor_info" class="pf-btn pf-btn-primary">
                        <i class="fas fa-save"></i> ডোনার তথ্য আপডেট
                    </button>
                </form>

            <?php else: ?>
                <div class="donor-cta-card">
                    <div class="cta-icon">❤️</div>
                    <h3>রক্তদাতা হিসেবে নিবন্ধন করুন</h3>
                    <p>আপনিও একজন রক্তদাতা হয়ে জীবন বাঁচাতে সাহায্য করতে পারেন। রক্তদাতা হিসেবে নিবন্ধন করুন এবং আপনার এলাকার মানুষের জীবন বাঁচাতে সাহায্য করুন!</p>
                    
                    <?php 
                    $divisions_stmt = $conn->prepare("SELECT id, name FROM divisions ORDER BY name ASC");
                    $divisions_stmt->execute();
                    $divisions_for_reg = $divisions_stmt->get_result();
                    ?>
                    <form action="profile.php" method="POST">
                        <div class="pf-form-grid">
                            <div class="pf-field">
                                <label><i class="fas fa-map-marker-alt"></i> বিভাগ</label>
                                <select name="division_id" id="division_id" required>
                                    <option value="">-- বিভাগ নির্বাচন করুন --</option>
                                    <?php while($div = $divisions_for_reg->fetch_assoc()): ?>
                                        <option value="<?= $div['id'] ?>"><?= $div['name'] ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="pf-field">
                                <label><i class="fas fa-building"></i> জেলা</label>
                                <select name="district_id" id="district_id" required disabled>
                                    <option value="">-- প্রথমে বিভাগ নির্বাচন করুন --</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" name="register_as_donor" class="pf-btn pf-btn-danger" style="margin-top:20px;">
                            <i class="fas fa-heart"></i> রক্তদাতা হিসেবে রেজিস্টার করুন
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB 4: Photo Upload -->
        <div class="profile-tab-content" id="tab-photo">
            <h3 class="pf-section-title">প্রোফাইল ছবি আপলোড করুন</h3>
            <p class="pf-section-desc">আপনার ছবি JPG, PNG বা GIF ফরম্যাটে আপলোড করুন (সর্বোচ্চ 2MB)।</p>

            <div style="text-align:center; margin-bottom:25px;">
                <div class="profile-avatar" style="width:160px;height:160px;margin:0 auto;border:4px solid var(--primary-color);">
                    <?php if (!empty($user['profile_image']) && file_exists($user['profile_image'])): ?>
                        <img src="<?= htmlspecialchars($user['profile_image']) ?>" alt="Profile">
                    <?php else: ?>
                        <span class="avatar-initial" style="font-size:65px;"><?= mb_substr($user['full_name'], 0, 1) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <form action="profile.php" method="POST" enctype="multipart/form-data" id="photo-upload-form">
                <div class="photo-upload-area" id="drop-zone">
                    <input type="file" name="profile_image" id="photo_file_input" style="display:none;" accept="image/*">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p class="upload-text">ছবি এখানে ড্র্যাগ ও ড্রপ করুন</p>
                    <p class="upload-hint">অথবা ক্লিক করে ব্রাউজ করুন (সর্বোচ্চ 2MB)</p>
                </div>
            </form>
        </div>

    </div><!-- /profile-page -->
</main>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('#blood_group_select').select2({
        placeholder: "রক্তের গ্রুপ নির্বাচন করুন",
        allowClear: true,
        dropdownParent: $('#tab-personal')
    });
});

// --- Tab System ---
document.querySelectorAll('.profile-tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.profile-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.profile-tab-content').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        document.getElementById('tab-' + this.dataset.tab).classList.add('active');
    });
});

// --- Photo Upload (Drop Zone) ---
const dropZone = document.getElementById('drop-zone');
const photoInput = document.getElementById('photo_file_input');
const avatarInput = document.getElementById('profile_image_upload');

if (dropZone && photoInput) {
    dropZone.addEventListener('click', () => photoInput.click());
    photoInput.addEventListener('change', () => {
        if (photoInput.files.length > 0) photoInput.closest('form').submit();
    });
    dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('dragover'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        if (e.dataTransfer.files.length > 0) {
            photoInput.files = e.dataTransfer.files;
            photoInput.closest('form').submit();
        }
    });
}

// Avatar quick-change in header
if (avatarInput) {
    avatarInput.addEventListener('change', () => {
        if (avatarInput.files.length > 0) avatarInput.closest('form').submit();
    });
}

// --- Division/District AJAX for Registration ---
const divisionSelect = document.getElementById('division_id');
const districtSelect = document.getElementById('district_id');

if (divisionSelect && districtSelect) {
    divisionSelect.addEventListener('change', function() {
        const divisionId = this.value;
        districtSelect.innerHTML = '<option value="">-- জেলা নির্বাচন করুন --</option>';
        if (divisionId) {
            fetch(`ajax_handler.php?action=get_districts&division_id=${divisionId}`)
                .then(r => r.json()).then(data => {
                    data.forEach(d => { districtSelect.innerHTML += `<option value="${d.id}">${d.name}</option>`; });
                    districtSelect.disabled = false;
                }).catch(err => console.error('Error:', err));
        } else { districtSelect.disabled = true; }
    });
}

// --- Division/District AJAX for Donor Update ---
const donorDivisionSelect = document.getElementById('donor_division_id');
const donorDistrictSelect = document.getElementById('donor_district_id');

if (donorDivisionSelect && donorDistrictSelect) {
    donorDivisionSelect.addEventListener('change', function() {
        const divisionId = this.value;
        donorDistrictSelect.innerHTML = '<option value="">-- জেলা নির্বাচন করুন --</option>';
        if (divisionId) {
            fetch(`ajax_handler.php?action=get_districts&division_id=${divisionId}`)
                .then(r => r.json()).then(data => {
                    data.forEach(d => { donorDistrictSelect.innerHTML += `<option value="${d.id}">${d.name}</option>`; });
                    donorDistrictSelect.disabled = false;
                }).catch(err => console.error('Error:', err));
        } else { donorDistrictSelect.disabled = true; }
    });
}

// --- Toggle Donor Availability ---
const toggleBtn = document.getElementById('toggle-availability');
if (toggleBtn) {
    toggleBtn.addEventListener('click', function() {
        fetch('ajax_handler.php?action=toggle_donor_availability')
            .then(r => r.json()).then(data => {
                if (data.success) {
                    const isAvail = data.new_availability === 'Available';
                    const banner = document.getElementById('donor-banner');
                    const dot = document.getElementById('status-dot');
                    const statusVal = document.getElementById('status-value');

                    banner.className = 'donor-status-banner ' + (isAvail ? 'is-available' : 'is-unavailable');
                    dot.className = 'status-dot ' + (isAvail ? 'green' : 'orange');
                    statusVal.textContent = isAvail ? 'একটিভ — রক্তদানে প্রস্তুত' : 'ইনএকটিভ — বর্তমানে প্রস্তুত নন';
                    toggleBtn.className = 'pf-toggle-btn ' + (isAvail ? 'toggle-available' : 'toggle-unavailable');
                    toggleBtn.innerHTML = '<i class="fas fa-exchange-alt"></i> ' + (isAvail ? 'ইনএকটিভ করুন' : 'একটিভ করুন');
                    toggleBtn.dataset.current = data.new_availability;
                } else { alert('Error: ' + data.error); }
            }).catch(err => { console.error(err); alert('একটি ত্রুটি ঘটেছে।'); });
    });
}

// Auto-dismiss toasts
setTimeout(() => {
    document.querySelectorAll('.profile-toast').forEach(t => {
        t.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        t.style.opacity = '0';
        t.style.transform = 'translateY(-10px)';
        setTimeout(() => t.remove(), 500);
    });
}, 5000);
</script>
<?php $conn->close(); include_once('includes/footer.php'); ?>
