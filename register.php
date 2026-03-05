<?php
include_once('includes/db_config.php');
include_once('includes/validation.php');
include_once('includes/csrf.php');
include_once('includes/rate_limiter.php');
include_once('includes/ActivityLog.php');
include_once('includes/header.php');

$error = '';
$old_input = [];
$rate_limited = false;

// Fetch districts for the dropdown
$districts_stmt = $conn->prepare("SELECT id, name FROM districts ORDER BY name ASC");
$districts_stmt->execute();
$districts = $districts_stmt->get_result();

// Check if rate limited before processing
if (is_registration_limited()) {
    $rate_limited = true;
    $error = "অনেক বেশি রেজিস্ট্রেশন চেষ্টা করা হয়েছে। অনুগ্রহ করে ১ ঘন্টা পর আবার চেষ্টা করুন।";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$rate_limited) {
    // Verify CSRF token
    if (!csrf_verify()) {
        $error = 'অবৈধ অনুরোধ। অনুগ্রহ করে পেজ রিফ্রেশ করে আবার চেষ্টা করুন।';
    } else {
        // Store old input for form repopulation
        $old_input = [
            'full_name' => $_POST['full_name'] ?? '',
            'email' => $_POST['email'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'district_id' => $_POST['district_id'] ?? ''
        ];
        
        // Validate input
        $validator = new Validator();
        
        $validator->required('full_name', $_POST['full_name'] ?? '', 'পুরো নাম');
        $validator->minLength('full_name', $_POST['full_name'] ?? '', 2, 'পুরো নাম');
        $validator->maxLength('full_name', $_POST['full_name'] ?? '', 100, 'পুরো নাম');
        
        $validator->required('email', $_POST['email'] ?? '', 'ইমেইল');
        $validator->email('email', $_POST['email'] ?? '');
        
        $validator->required('phone', $_POST['phone'] ?? '', 'ফোন নম্বর');
        $validator->phone('phone', $_POST['phone'] ?? '');
        
        $validator->required('district_id', $_POST['district_id'] ?? '', 'জেলা');
        
        $validator->required('password', $_POST['password'] ?? '', 'পাসওয়ার্ড');
        $validator->password('password', $_POST['password'] ?? '', 6);
        
        $validator->confirmPassword('confirm_password', $_POST['password'] ?? '', $_POST['confirm_password'] ?? '');
        
        if ($validator->fails()) {
            $error = $validator->getFirstError();
        } else {
            // Sanitize inputs
            $full_name = sanitize_string($_POST['full_name']);
            $email = sanitize_email($_POST['email']);
            $phone = sanitize_phone($_POST['phone']);
            $district_id = (int)$_POST['district_id'];
            $password_hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
            
            try {
                $stmt = $conn->prepare("INSERT INTO users (full_name, email, phone, district_id, password_hash, role) VALUES (?, ?, ?, ?, ?, 'Patient')");
                $stmt->bind_param("sssis", $full_name, $email, $phone, $district_id, $password_hash);
                $stmt->execute();
                $new_user_id = $stmt->insert_id;

                // Log successful registration
                $activityLog = new ActivityLog($conn, $new_user_id);
                $activityLog->log(ActivityLog::CATEGORY_AUTH, 'REGISTER_SUCCESS', "New user registered: $full_name", 'user', $new_user_id, ActivityLog::STATUS_SUCCESS, ['email' => $email, 'phone' => $phone]);

                // Record registration attempt for rate limiting
                record_registration_attempt();
                
                // Regenerate CSRF token after successful submission
                CSRF::regenerate();
                
                header("Location: login.php?registration=success");
                exit();

            } catch (mysqli_sql_exception $exception) {
                // Log failed registration
                $activityLog = new ActivityLog($conn);
                $activityLog->log(ActivityLog::CATEGORY_AUTH, 'REGISTER_SUCCESS', "Registration failed for email: $email", 'user', null, ActivityLog::STATUS_FAILED, ['reason' => $exception->getMessage()]);
                
                if ($conn->errno === 1062) {
                    if (strpos($exception->getMessage(), 'email')) {
                        $error = 'এই ইমেইল অ্যাড্রেসটি ইতিমধ্যে ব্যবহৃত হয়েছে। অনুগ্রহ করে অন্য একটি ইমেইল ব্যবহার করুন।';
                    } elseif (strpos($exception->getMessage(), 'phone')) {
                        $error = 'এই ফোন নম্বরটি ইতিমধ্যে ব্যবহৃত হয়েছে। অনুগ্রহ করে অন্য একটি ফোন নম্বর ব্যবহার করুন।';
                    } else {
                        $error = 'এই তথ্যটি ইতিমধ্যে ব্যবহৃত হয়েছে।';
                    }
                } else {
                    $error = 'রেজিস্ট্রেশন ব্যর্থ হয়েছে। অনুগ্রহ করে কিছুক্ষণ পর আবার চেষ্টা করুন।';
                }
            }
        }
    }
}
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
/* ===================================
   Register Page — Premium Split Layout
   =================================== */
.reg-page {
    display: flex;
    min-height: calc(100vh - 80px);
    background: #f7f9fb;
}

.reg-brand-panel {
    flex: 0 0 420px;
    background: linear-gradient(135deg, #1a8a4a 0%, #0d6b3a 40%, #0a5730 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 60px 35px;
    position: relative;
    overflow: hidden;
}

.reg-brand-panel::before {
    content: '';
    position: absolute;
    top: -20%;
    right: -15%;
    width: 500px;
    height: 500px;
    border-radius: 50%;
    background: rgba(255,255,255,0.04);
}

.reg-brand-panel::after {
    content: '';
    position: absolute;
    bottom: -25%;
    left: -10%;
    width: 400px;
    height: 400px;
    border-radius: 50%;
    background: rgba(255,255,255,0.03);
}

.reg-brand-content {
    text-align: center;
    color: white;
    position: relative;
    z-index: 1;
    max-width: 360px;
}

.reg-brand-content .brand-icon {
    font-size: 80px;
    margin-bottom: 25px;
    display: block;
    animation: regFloat 3s ease-in-out infinite;
}

@keyframes regFloat {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
}

.reg-brand-content h2 {
    font-size: 27px;
    font-weight: 700;
    margin: 0 0 12px 0;
    line-height: 1.3;
}

.reg-brand-content p {
    font-size: 15px;
    opacity: 0.85;
    margin: 0 0 30px 0;
    line-height: 1.6;
}

.reg-steps {
    text-align: left;
    list-style: none;
    padding: 0;
    margin: 0;
}

.reg-steps li {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 12px 0;
    font-size: 14px;
    opacity: 0.9;
    line-height: 1.4;
}

.reg-steps li .step-num {
    width: 30px;
    height: 30px;
    min-width: 30px;
    background: rgba(255,255,255,0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
}

.reg-form-panel {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px;
    overflow-y: auto;
}

.reg-card {
    width: 100%;
    max-width: 500px;
    animation: regSlideUp 0.6s ease-out;
}

@keyframes regSlideUp {
    from { opacity: 0; transform: translateY(25px); }
    to { opacity: 1; transform: translateY(0); }
}

.reg-card-header {
    text-align: center;
    margin-bottom: 28px;
}

.reg-card-header .greeting {
    font-size: 13px;
    color: var(--primary-color);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    margin: 0 0 8px 0;
}

.reg-card-header h2 {
    font-size: 24px;
    margin: 0;
    color: var(--secondary-color);
    font-weight: 700;
}

.reg-card-header .subtitle {
    font-size: 14px;
    color: #888;
    margin: 8px 0 0 0;
}

/* Reuse auth-toast, auth-field, auth-input-wrap, etc from login */
.reg-toast {
    padding: 12px 16px;
    border-radius: 10px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    font-weight: 600;
    animation: regSlideUp 0.3s ease;
}

.reg-toast.toast-error {
    background: linear-gradient(135deg, #fff5f5, #ffe0e0);
    color: #c0392b;
    border: 1px solid #f5c6cb;
}

.reg-field {
    margin-bottom: 18px;
}

.reg-field label {
    display: block;
    font-weight: 600;
    font-size: 14px;
    color: var(--secondary-color);
    margin-bottom: 7px;
}

.reg-field label i {
    margin-right: 6px;
    color: var(--primary-color);
    width: 16px;
    text-align: center;
}

.reg-input-wrap {
    position: relative;
}

.reg-input-wrap .field-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #bbb;
    font-size: 15px;
    pointer-events: none;
    transition: color 0.3s ease;
}

.reg-input-wrap input,
.reg-input-wrap select {
    width: 100%;
    box-sizing: border-box;
    padding: 13px 16px 13px 44px;
    border: 1.5px solid #e8ecef;
    border-radius: 12px;
    font-size: 15px;
    font-family: 'Hind Siliguri', sans-serif;
    background: #fafbfc;
    color: var(--text-color);
    transition: all 0.3s ease;
    outline: none;
}

.reg-input-wrap input:focus {
    border-color: var(--primary-color);
    background: white;
    box-shadow: 0 0 0 4px rgba(39,174,96,0.08);
}

.reg-input-wrap input:disabled {
    background: #f1f3f5;
    color: #999;
    cursor: not-allowed;
}

.reg-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.reg-submit-btn {
    width: 100%;
    padding: 14px;
    border: none;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 700;
    font-family: 'Hind Siliguri', sans-serif;
    cursor: pointer;
    background: linear-gradient(135deg, #27ae60, #2ecc71);
    color: white;
    box-shadow: 0 4px 15px rgba(39,174,96,0.3);
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 22px;
}

.reg-submit-btn:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(39,174,96,0.4);
}

.reg-submit-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.reg-divider {
    display: flex;
    align-items: center;
    gap: 15px;
    margin: 22px 0;
}

.reg-divider::before,
.reg-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e8ecef;
}

.reg-divider span {
    font-size: 13px;
    color: #aaa;
    font-weight: 500;
}

.reg-footer-links {
    text-align: center;
}

.reg-footer-links p {
    font-size: 15px;
    color: #777;
    margin: 0 0 12px 0;
}

.reg-footer-links a {
    color: var(--primary-color);
    font-weight: 700;
    transition: color 0.3s ease;
}

.reg-footer-links a:hover {
    color: #1a8a4a;
}

.reg-alt-link {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 20px;
    background: #f7f9fb;
    border-radius: 10px;
    font-size: 14px;
    color: #666;
    transition: all 0.3s ease;
    margin-top: 12px;
}

.reg-alt-link:hover {
    background: #eef5ee;
    color: var(--primary-color);
}

/* Select2 for Register District */
.reg-field .select2-container { width: 100% !important; }
.reg-field .select2-container--default .select2-selection--single {
    height: 48px !important;
    min-height: 48px;
    border: 1.5px solid #e8ecef !important;
    border-radius: 12px !important;
    padding: 0 14px 0 44px;
    background: #fafbfc;
    display: flex;
    align-items: center;
    transition: all 0.3s ease;
}
.reg-field .select2-container--default.select2-container--focus .select2-selection--single {
    border-color: var(--primary-color) !important;
    box-shadow: 0 0 0 4px rgba(39,174,96,0.08);
    background: white;
}
.reg-field .select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 46px;
    font-size: 15px;
    padding-left: 0;
    font-family: 'Hind Siliguri', sans-serif;
    color: var(--text-color);
}
.reg-field .select2-container--default .select2-selection--single .select2-selection__placeholder {
    color: #aaa;
}
.reg-field .select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 46px !important;
    right: 10px;
}

/* Dropdown Styling */
.reg-district-dropdown { 
    border: none !important;
    border-radius: 12px !important;
    box-shadow: 0 12px 40px rgba(0,0,0,0.12) !important;
    overflow: hidden;
    margin-top: 6px;
}
.reg-district-dropdown .select2-search--dropdown {
    padding: 12px;
    background: #fafbfc;
    border-bottom: 1px solid #eee;
}
.reg-district-dropdown .select2-search--dropdown .select2-search__field {
    padding: 10px 14px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
    font-family: 'Hind Siliguri', sans-serif;
    outline: none;
}
.reg-district-dropdown .select2-search--dropdown .select2-search__field:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(39,174,96,0.1);
}
.reg-district-dropdown .select2-results__options { max-height: 250px; }
.reg-district-dropdown .select2-results__option {
    padding: 10px 16px;
    font-family: 'Hind Siliguri', sans-serif;
    font-size: 14px;
    border-radius: 6px;
    margin: 2px 8px;
    transition: all 0.2s ease;
}
.reg-district-dropdown .select2-results__option--highlighted[aria-selected] {
    background: linear-gradient(135deg, rgba(39,174,96,0.1), rgba(39,174,96,0.05));
    color: #1a5f4a;
}
.reg-district-dropdown .select2-results__option[aria-selected="true"] {
    background: #e8f5e9;
    color: #1a5f4a;
    font-weight: 600;
}

.reg-toggle-pass {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    color: #bbb;
    font-size: 15px;
    transition: color 0.3s ease;
    padding: 4px;
}

.reg-toggle-pass:hover {
    color: var(--primary-color);
}

@media (max-width: 900px) {
    .reg-page { flex-direction: column; }
    .reg-brand-panel { display: none; }
    .reg-form-panel { padding: 30px 20px; }
    .reg-form-row { grid-template-columns: 1fr; }
}
</style>

<main>
    <div class="reg-page">
        
        <!-- Left Brand Panel -->
        <div class="reg-brand-panel">
            <div class="reg-brand-content">
                <span class="brand-icon">📋</span>
                <h2>নতুন অ্যাকাউন্ট তৈরি করুন</h2>
                <p>মাত্র কয়েকটি ধাপে আপনার একাউন্ট তৈরি করে MediVerse এর সকল সুবিধা উপভোগ করুন।</p>
                <ul class="reg-steps">
                    <li><span class="step-num">১</span> আপনার ব্যক্তিগত তথ্য দিন — নাম, ইমেইল ও ফোন</li>
                    <li><span class="step-num">২</span> আপনার জেলা নির্বাচন করুন</li>
                    <li><span class="step-num">৩</span> একটি শক্তিশালী পাসওয়ার্ড সেট করুন</li>
                    <li><span class="step-num">৪</span> রেজিস্ট্রেশন সম্পন্ন — এবার লগ-ইন করুন!</li>
                </ul>
            </div>
        </div>

        <!-- Right Form Panel -->
        <div class="reg-form-panel">
            <form action="register.php" method="POST" class="reg-card">
                <?= csrf_field() ?>

                <div class="reg-card-header">
                    <p class="greeting">শুরু করুন</p>
                    <h2>রেজিস্ট্রেশন ফর্ম</h2>
                    <p class="subtitle">সকল তথ্য সঠিকভাবে পূরণ করুন</p>
                </div>

                <?php if($error): ?>
                    <div class="reg-toast toast-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div>
                <?php endif; ?>

                <div class="reg-field">
                    <label><i class="fas fa-user"></i> পুরো নাম</label>
                    <div class="reg-input-wrap">
                        <input type="text" name="full_name" value="<?= attr($old_input['full_name'] ?? '') ?>" placeholder="আপনার পুরো নাম" required <?= $rate_limited ? 'disabled' : '' ?>>
                        <i class="fas fa-user field-icon"></i>
                    </div>
                </div>

                <div class="reg-form-row">
                    <div class="reg-field">
                        <label><i class="fas fa-envelope"></i> ইমেইল</label>
                        <div class="reg-input-wrap">
                            <input type="email" name="email" value="<?= attr($old_input['email'] ?? '') ?>" placeholder="you@example.com" required <?= $rate_limited ? 'disabled' : '' ?>>
                            <i class="fas fa-envelope field-icon"></i>
                        </div>
                    </div>
                    <div class="reg-field">
                        <label><i class="fas fa-phone"></i> ফোন নম্বর</label>
                        <div class="reg-input-wrap">
                            <input type="text" name="phone" value="<?= attr($old_input['phone'] ?? '') ?>" placeholder="01XXXXXXXXX" required <?= $rate_limited ? 'disabled' : '' ?>>
                            <i class="fas fa-phone field-icon"></i>
                        </div>
                    </div>
                </div>

                <div class="reg-field">
                    <label><i class="fas fa-map-marker-alt"></i> জেলা</label>
                    <div class="reg-input-wrap">
                        <i class="fas fa-map-marker-alt field-icon"></i>
                        <select name="district_id" id="district_id" required>
                            <option value="">-- জেলা নির্বাচন করুন --</option>
                            <?php if($districts) { while($dist = $districts->fetch_assoc()): ?>
                                <option value="<?= $dist['id'] ?>" <?= (isset($old_input['district_id']) && $old_input['district_id'] == $dist['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dist['name']) ?>
                                </option>
                            <?php endwhile; } ?>
                        </select>
                    </div>
                </div>

                <div class="reg-form-row">
                    <div class="reg-field">
                        <label><i class="fas fa-lock"></i> পাসওয়ার্ড</label>
                        <div class="reg-input-wrap">
                            <input type="password" name="password" id="reg-password" placeholder="কমপক্ষে ৬ অক্ষর" required minlength="6" <?= $rate_limited ? 'disabled' : '' ?>>
                            <i class="fas fa-lock field-icon"></i>
                            <button type="button" class="reg-toggle-pass" onclick="toggleRegPass('reg-password', this)"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>
                    <div class="reg-field">
                        <label><i class="fas fa-shield-alt"></i> কনফার্ম পাসওয়ার্ড</label>
                        <div class="reg-input-wrap">
                            <input type="password" name="confirm_password" id="reg-confirm" placeholder="পুনরায় লিখুন" required minlength="6" <?= $rate_limited ? 'disabled' : '' ?>>
                            <i class="fas fa-shield-alt field-icon"></i>
                            <button type="button" class="reg-toggle-pass" onclick="toggleRegPass('reg-confirm', this)"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>
                </div>

                <button type="submit" class="reg-submit-btn" <?= $rate_limited ? 'disabled' : '' ?>>
                    <i class="fas fa-user-plus"></i> রেজিস্টার করুন
                </button>

                <div class="reg-divider"><span>অথবা</span></div>

                <div class="reg-footer-links">
                    <p>ইতিমধ্যে অ্যাকাউন্ট আছে? <a href="login.php">লগ-ইন করুন</a></p>
                    <a href="driver/register.php" class="reg-alt-link">
                        <i class="fas fa-ambulance"></i> অ্যাম্বুলেন্স ড্রাইভার রেজিস্ট্রেশন
                    </a>
                </div>
            </form>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    jQuery('#district_id').select2({
        placeholder: '-- জেলা নির্বাচন করুন --',
        allowClear: true,
        minimumInputLength: 0,
        language: {
            inputTooShort: function() { return 'জেলার নাম লিখুন...'; },
            searching: function() { return 'খুঁজছি...'; },
            noResults: function() { return 'কোনো জেলা পাওয়া যায়নি'; }
        },
        dropdownCssClass: 'reg-district-dropdown'
    });
});

function toggleRegPass(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}
</script>

<?php include_once('includes/footer.php'); ?>
