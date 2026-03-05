<?php
session_start();
require_once('includes/db_config.php');

// Redirect if already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
    if ($_SESSION['user_role'] === 'PharmacyAdmin') {
        header("Location: pharmacy_admin/dashboard.php");
        exit();
    } elseif ($_SESSION['user_role'] === 'PharmacyStaff') {
        header("Location: pharmacy_staff/dashboard.php");
        exit();
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = "সকল তথ্য পূরণ করুন।";
    } else {
        $stmt = $conn->prepare("SELECT id, full_name, password_hash, role FROM users WHERE email = ? AND role IN ('PharmacyAdmin', 'PharmacyStaff')");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_role'] = $user['role'];
                
                if ($user['role'] === 'PharmacyAdmin') {
                    header("Location: pharmacy_admin/dashboard.php");
                } else {
                    header("Location: pharmacy_staff/dashboard.php");
                }
                exit();
            } else {
                $error = "ভুল পাসওয়ার্ড।";
            }
        } else {
            $stmt_app = $conn->prepare("SELECT status, rejection_reason FROM pharmacy_applications WHERE email = ? ORDER BY created_at DESC LIMIT 1");
            $stmt_app->bind_param("s", $email);
            $stmt_app->execute();
            $result_app = $stmt_app->get_result();
            
            if ($app = $result_app->fetch_assoc()) {
                if ($app['status'] === 'Pending') {
                    $error = "আপনার ফার্মেসী রিকুয়েস্টটি এখনো পেন্ডিং আছে। অনুগ্রহ করে অ্যাডমিন অ্যাপ্রুভাল এর জন্য অপেক্ষা করুন।";
                } elseif ($app['status'] === 'Rejected') {
                    $reason = htmlspecialchars($app['rejection_reason']);
                    $error = "আপনার ফার্মেসী রিকুয়েস্টটি রিজেক্ট করা হয়েছে। কারণ: $reason";
                } else {
                    $error = "ভুল ইমেইল অথবা অনুমোদনহীন।";
                }
            } else {
                $error = "ভুল ইমেইল অথবা অনুমোদনহীন।";
            }
            $stmt_app->close();
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ফার্মেসী লগইন - MediVerse</title>
    <link rel="stylesheet" href="css/portal-auth.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="portal-auth theme-pharmacy">
    <div class="pa-shape pa-shape-1"></div>
    <div class="pa-shape pa-shape-2"></div>

    <div class="pa-wrapper">
        <div class="pa-card">
            <div class="pa-hero hero-pharmacy">
                <div class="pa-hero-icon"><i class="fas fa-prescription-bottle-alt"></i></div>
                <h1>ফার্মেসী পোর্টাল</h1>
                <p>অনুমোদিত কর্মীদের জন্য</p>
            </div>
            <div class="pa-body">
                <?php if($error): ?>
                    <div class="pa-alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="pa-field">
                        <label><i class="fas fa-envelope"></i> ইমেইল</label>
                        <div class="pa-input-wrap">
                            <input type="email" name="email" placeholder="আপনার ইমেইল লিখুন" required>
                        </div>
                    </div>
                    <div class="pa-field">
                        <label><i class="fas fa-lock"></i> পাসওয়ার্ড</label>
                        <div class="pa-input-wrap">
                            <input type="password" name="password" id="pharm-pass" placeholder="আপনার পাসওয়ার্ড লিখুন" required>
                            <button type="button" class="pa-toggle-pass" onclick="togglePass('pharm-pass',this)"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>
                    <button type="submit" class="pa-submit btn-pharmacy"><i class="fas fa-sign-in-alt"></i> লগইন করুন</button>
                </form>
                <div class="pa-links">
                    <p>নতুন ফার্মেসী? <a href="pharmacy_apply.php"><i class="fas fa-paper-plane"></i> আবেদন করুন</a></p>
                    <a href="index.php" class="pa-home-link"><i class="fas fa-home"></i> হোমপেজে ফিরে যান</a>
                </div>
            </div>
        </div>
    </div>
<script>
function togglePass(id, btn) {
    const i = document.getElementById(id), ic = btn.querySelector('i');
    if (i.type === 'password') { i.type = 'text'; ic.classList.replace('fa-eye','fa-eye-slash'); }
    else { i.type = 'password'; ic.classList.replace('fa-eye-slash','fa-eye'); }
}
</script>
</body>
</html>
