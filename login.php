<?php
// Safe session start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

include_once('includes/db_config.php');
include_once('includes/validation.php');
include_once('includes/csrf.php');
include_once('includes/rate_limiter.php');
include_once('includes/ActivityLog.php');

$error = '';
$old_email = '';
$rate_limited = false;

// Check if rate limited before processing
if (is_login_limited()) {
    $rate_limited = true;
    $wait_time = login_available_in();
    $wait_minutes = ceil($wait_time / 60);
    $error = "অনেক বেশি লগইন চেষ্টা করা হয়েছে। অনুগ্রহ করে {$wait_minutes} মিনিট পর আবার চেষ্টা করুন।";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$rate_limited) {
    // Verify CSRF token
    if (!csrf_verify()) {
        $error = 'অবৈধ অনুরোধ। অনুগ্রহ করে পেজ রিফ্রেশ করে আবার চেষ্টা করুন।';
    } else {
        $old_email = $_POST['email'] ?? '';
        
        // Validate input
        $validator = new Validator();
        $validator->required('email', $_POST['email'] ?? '', 'ইমেইল');
        $validator->email('email', $_POST['email'] ?? '');
        $validator->required('password', $_POST['password'] ?? '', 'পাসওয়ার্ড');
        
        if ($validator->fails()) {
            $error = $validator->getFirstError();
        } else {
            $email = sanitize_email($_POST['email']);
            $password = $_POST['password'];

            $stmt = $conn->prepare("SELECT id, password_hash, role FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $login_success = false;
            
            if ($user = $result->fetch_assoc()) {
                if (password_verify($password, $user['password_hash'])) {
                    $login_success = true;
                    
                    // Clear rate limit on successful login
                    clear_login_attempts($email);
                    
                    // Regenerate session ID for security
                    session_regenerate_id(true); 
                    
                    $_SESSION['user_id'] = $user['id'];
                    
                    // Regenerate CSRF token after successful login
                    CSRF::regenerate();
                    
                    // Log successful login
                    $activityLog = new ActivityLog($conn, $user['id']);
                    $activityLog->loginSuccess($user['id'], ['email' => $email]);

                    // Determine Redirect URL based on Role
                    $role = $user['role'];
                    $redirect_target = 'index.php'; // Default

                    if ($role === 'SuperHospitalAdmin' || $role === 'HospitalAdmin') {
                        $redirect_target = 'admin/dashboard.php';
                    } elseif ($role === 'Doctor') {
                        $redirect_target = 'doctor/dashboard.php';
                    } elseif ($role === 'PharmacyAdmin') {
                        $redirect_target = 'pharmacy_admin/dashboard.php';
                    }
                    
                    // Override with requested URL if valid
                    $requested_url = $_GET['redirect_url'] ?? '';
                    if (!empty($requested_url) && preg_match('/^[a-zA-Z0-9_\-\/\.]+\.php(\?.*)?$/', $requested_url)) {
                        $redirect_target = $requested_url;
                    }
                    
                    header("Location: " . $redirect_target);
                    exit();
                }
            }
            
            if (!$login_success) {
                // Log failed login attempt
                $activityLog = new ActivityLog($conn);
                $activityLog->loginFailed($email, ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown']);
                
                // Record failed login attempt
                record_login_attempt($email);
                
                $remaining = remaining_login_attempts($email);
                if ($remaining > 0) {
                    $error = "ভুল ইমেইল অথবা পাসওয়ার্ড। আর {$remaining}টি চেষ্টা বাকি আছে।";
                } else {
                    $wait_time = login_available_in($email);
                    $wait_minutes = ceil($wait_time / 60);
                    $error = "অনেক বেশি লগইন চেষ্টা করা হয়েছে। অনুগ্রহ করে {$wait_minutes} মিনিট পর আবার চেষ্টা করুন।";
                }
            }
        }
    }
}

include_once('includes/header.php'); 
?>

<style>
/* ===================================
   Auth Page — Premium Split Layout
   =================================== */
.auth-page {
    display: flex;
    min-height: calc(100vh - 80px);
    background: #f7f9fb;
}

.auth-brand-panel {
    flex: 1;
    background: linear-gradient(135deg, #1a8a4a 0%, #0d6b3a 40%, #0a5730 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 60px 40px;
    position: relative;
    overflow: hidden;
}

.auth-brand-panel::before {
    content: '';
    position: absolute;
    top: -20%;
    right: -15%;
    width: 500px;
    height: 500px;
    border-radius: 50%;
    background: rgba(255,255,255,0.04);
}

.auth-brand-panel::after {
    content: '';
    position: absolute;
    bottom: -25%;
    left: -10%;
    width: 400px;
    height: 400px;
    border-radius: 50%;
    background: rgba(255,255,255,0.03);
}

.brand-content {
    text-align: center;
    color: white;
    position: relative;
    z-index: 1;
    max-width: 400px;
}

.brand-content .brand-icon {
    font-size: 80px;
    margin-bottom: 25px;
    display: block;
    animation: float 3s ease-in-out infinite;
}

@keyframes float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
}

.brand-content h2 {
    font-size: 30px;
    font-weight: 700;
    margin: 0 0 12px 0;
    line-height: 1.3;
}

.brand-content p {
    font-size: 16px;
    opacity: 0.85;
    margin: 0 0 30px 0;
    line-height: 1.6;
}

.brand-features {
    text-align: left;
    list-style: none;
    padding: 0;
    margin: 0;
}

.brand-features li {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    font-size: 15px;
    opacity: 0.9;
}

.brand-features li i {
    width: 30px;
    height: 30px;
    background: rgba(255,255,255,0.15);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    flex-shrink: 0;
}

.auth-form-panel {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px;
}

.auth-card {
    width: 100%;
    max-width: 440px;
    animation: authSlideUp 0.6s ease-out;
}

@keyframes authSlideUp {
    from { opacity: 0; transform: translateY(25px); }
    to { opacity: 1; transform: translateY(0); }
}

.auth-card-header {
    text-align: center;
    margin-bottom: 30px;
}

.auth-card-header .greeting {
    font-size: 14px;
    color: var(--primary-color);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    margin: 0 0 8px 0;
}

.auth-card-header h2 {
    font-size: 26px;
    margin: 0;
    color: var(--secondary-color);
    font-weight: 700;
}

.auth-card-header .subtitle {
    font-size: 14px;
    color: #888;
    margin: 8px 0 0 0;
}

/* --- Auth Toast --- */
.auth-toast {
    padding: 12px 16px;
    border-radius: 10px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    font-weight: 600;
    animation: authSlideUp 0.3s ease-out;
}

.auth-toast.toast-error {
    background: linear-gradient(135deg, #fff5f5, #ffe0e0);
    color: #c0392b;
    border: 1px solid #f5c6cb;
}

.auth-toast.toast-success {
    background: linear-gradient(135deg, #f0fff4, #d4edda);
    color: #155724;
    border: 1px solid #c3e6cb;
}

.auth-toast i { font-size: 18px; flex-shrink: 0; }

/* --- Form Fields --- */
.auth-field {
    margin-bottom: 20px;
}

.auth-field label {
    display: block;
    font-weight: 600;
    font-size: 14px;
    color: var(--secondary-color);
    margin-bottom: 8px;
}

.auth-field label i {
    margin-right: 6px;
    color: var(--primary-color);
    width: 16px;
    text-align: center;
}

.auth-input-wrap {
    position: relative;
}

.auth-input-wrap .field-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #bbb;
    font-size: 15px;
    transition: color 0.3s ease;
    pointer-events: none;
}

.auth-input-wrap input {
    width: 100%;
    box-sizing: border-box;
    padding: 14px 16px 14px 44px;
    border: 1.5px solid #e8ecef;
    border-radius: 12px;
    font-size: 15px;
    font-family: 'Hind Siliguri', sans-serif;
    background: #fafbfc;
    color: var(--text-color);
    transition: all 0.3s ease;
    outline: none;
}

.auth-input-wrap input:focus {
    border-color: var(--primary-color);
    background: white;
    box-shadow: 0 0 0 4px rgba(39,174,96,0.08);
}

.auth-input-wrap input:focus + .field-icon,
.auth-input-wrap input:focus ~ .field-icon {
    color: var(--primary-color);
}

.auth-input-wrap input:disabled {
    background: #f1f3f5;
    color: #999;
    cursor: not-allowed;
}

.auth-toggle-pass {
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

.auth-toggle-pass:hover {
    color: var(--primary-color);
}

/* --- Auth Button --- */
.auth-submit-btn {
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
    margin-top: 25px;
}

.auth-submit-btn:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(39,174,96,0.4);
}

.auth-submit-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* --- Divider --- */
.auth-divider {
    display: flex;
    align-items: center;
    gap: 15px;
    margin: 25px 0;
}

.auth-divider::before,
.auth-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e8ecef;
}

.auth-divider span {
    font-size: 13px;
    color: #aaa;
    font-weight: 500;
}

/* --- Links --- */
.auth-footer-links {
    text-align: center;
}

.auth-footer-links p {
    font-size: 15px;
    color: #777;
    margin: 0 0 12px 0;
}

.auth-footer-links a {
    color: var(--primary-color);
    font-weight: 700;
    transition: color 0.3s ease;
}

.auth-footer-links a:hover {
    color: #1a8a4a;
}

.auth-alt-link {
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

.auth-alt-link:hover {
    background: #eef5ee;
    color: var(--primary-color);
}

.auth-alt-link i {
    font-size: 16px;
}

/* --- Responsive --- */
@media (max-width: 900px) {
    .auth-page { flex-direction: column; }
    .auth-brand-panel { display: none; }
    .auth-form-panel { padding: 30px 20px; }
}
</style>

<main>
    <div class="auth-page">
        
        <!-- Left Brand Panel -->
        <div class="auth-brand-panel">
            <div class="brand-content">
                <span class="brand-icon">🏥</span>
                <h2>MediVerse এ স্বাগতম</h2>
                <p>বাংলাদেশের প্রথম সমন্বিত স্বাস্থ্যসেবা প্ল্যাটফর্ম — আপনার স্বাস্থ্য, আমাদের অঙ্গীকার।</p>
                <ul class="brand-features">
                    <li><i class="fas fa-hospital"></i> সারাদেশের হাসপাতাল ও ডাক্তার খুঁজুন</li>
                    <li><i class="fas fa-calendar-check"></i> অনলাইনে অ্যাপয়েন্টমেন্ট বুক করুন</li>
                    <li><i class="fas fa-bed"></i> লাইভ বেড স্ট্যাটাস দেখুন</li>
                    <li><i class="fas fa-ambulance"></i> জরুরি অ্যাম্বুলেন্স সেবা পান</li>
                    <li><i class="fas fa-tint"></i> রক্তদাতা খুঁজুন বা রক্তদান করুন</li>
                </ul>
            </div>
        </div>

        <!-- Right Form Panel -->
        <div class="auth-form-panel">
            <form action="login.php" method="POST" class="auth-card">
                <?= csrf_field() ?>
                
                <div class="auth-card-header">
                    <p class="greeting">ওয়েলকাম ব্যাক</p>
                    <h2>আপনার অ্যাকাউন্টে লগ-ইন করুন</h2>
                    <p class="subtitle">আপনার ইমেইল ও পাসওয়ার্ড দিয়ে প্রবেশ করুন</p>
                </div>

                <?php if($error): ?>
                    <div class="auth-toast toast-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div>
                <?php endif; ?>
                <?php if(isset($_GET['registration']) && $_GET['registration'] == 'success'): ?>
                    <div class="auth-toast toast-success"><i class="fas fa-check-circle"></i> রেজিস্ট্রেশন সফল হয়েছে! অনুগ্রহ করে লগ-ইন করুন।</div>
                <?php endif; ?>

                <div class="auth-field">
                    <label><i class="fas fa-envelope"></i> ইমেইল</label>
                    <div class="auth-input-wrap">
                        <input type="email" name="email" value="<?= attr($old_email) ?>" placeholder="আপনার ইমেইল লিখুন" required <?= $rate_limited ? 'disabled' : '' ?>>
                        <i class="fas fa-envelope field-icon"></i>
                    </div>
                </div>

                <div class="auth-field">
                    <label><i class="fas fa-lock"></i> পাসওয়ার্ড</label>
                    <div class="auth-input-wrap">
                        <input type="password" name="password" id="login-password" placeholder="আপনার পাসওয়ার্ড লিখুন" required <?= $rate_limited ? 'disabled' : '' ?>>
                        <i class="fas fa-lock field-icon"></i>
                        <button type="button" class="auth-toggle-pass" onclick="togglePassword('login-password', this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="auth-submit-btn" <?= $rate_limited ? 'disabled' : '' ?>>
                    <i class="fas fa-sign-in-alt"></i> লগ-ইন করুন
                </button>

                <div class="auth-divider"><span>অথবা</span></div>

                <div class="auth-footer-links">
                    <p>অ্যাকাউন্ট নেই? <a href="register.php">রেজিস্ট্রেশন করুন</a></p>
                    <a href="driver/login.php" class="auth-alt-link">
                        <i class="fas fa-ambulance"></i> অ্যাম্বুলেন্স ড্রাইভার লগইন
                    </a>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
function togglePassword(inputId, btn) {
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
