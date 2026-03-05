<?php
include_once('includes/db_config.php');
include_once('includes/header.php');

// Security check for logged-in user
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?redirect_url=lab_tests.php");
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patient_id = $_SESSION['user_id'];
    $test_id = (int)$_POST['test_id'];
    $branch_id = (int)$_POST['branch_id'];
    $appointment_date = $_POST['appointment_date'];
    
    // Handle prescription file upload
    $prescription_file = null;
    if (isset($_FILES['prescription']) && $_FILES['prescription']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['prescription'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        if (!in_array($file['type'], $allowed_types)) {
            $error = "শুধুমাত্র ছবি (JPG, PNG, GIF, WebP) অথবা PDF ফাইল আপলোড করুন!";
        } elseif ($file['size'] > $max_size) {
            $error = "ফাইল সাইজ 5MB এর বেশি হতে পারবে না!";
        } else {
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'prescription_' . $patient_id . '_' . time() . '_' . uniqid() . '.' . $extension;
            $upload_path = 'uploads/prescriptions/' . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                $prescription_file = $upload_path;
            } else {
                $error = "ফাইল আপলোড করতে ব্যর্থ হয়েছে!";
            }
        }
    } else {
        $error = "প্রেসক্রিপশন আপলোড করা আবশ্যক!";
    }

    // Continue only if no error
    if (empty($error)) {
        // Find the correct schedule ID based on branch and date's weekday
        $day_name = date('l', strtotime($appointment_date));
        $schedule_stmt = $conn->prepare("SELECT id FROM lab_schedules WHERE branch_id = ? AND day_of_week = ? LIMIT 1");
        $schedule_stmt->bind_param("is", $branch_id, $day_name);
        $schedule_stmt->execute();
        $schedule_q = $schedule_stmt->get_result();
        
        if ($schedule_q && $schedule_q->num_rows > 0) {
            $schedule_id = $schedule_q->fetch_assoc()['id'];

            try {
                $patient_name = $_SESSION['user_full_name'] ?? '';
                $patient_phone_stmt = $conn->prepare("SELECT phone FROM users WHERE id = ?");
                $patient_phone_stmt->bind_param("i", $patient_id);
                $patient_phone_stmt->execute();
                $patient_phone = $patient_phone_stmt->get_result()->fetch_assoc()['phone'] ?? '';
                $patient_phone_stmt->close();

                // Insert with prescription file - doctor_schedule_id is NULL for lab tests
                // (lab tests use lab_schedules table, not doctor_schedules)
                $stmt = $conn->prepare("
                    INSERT INTO bookings (user_id, branch_id, booking_type, reference_id, doctor_schedule_id, appointment_date, prescription_file, status) 
                    VALUES (?, ?, 'lab_test', ?, NULL, ?, ?, 'Pending')
                ");
                $stmt->bind_param("iiiss", $patient_id, $branch_id, $test_id, $appointment_date, $prescription_file);
                
                if ($stmt->execute()) {
                    $success = "আপনার ল্যাব টেস্টের আবেদনটি সফলভাবে জমা হয়েছে। ল্যাব অ্যাডমিন প্রেসক্রিপশন যাচাই করে অনুমোদন করবে।";
                } else {
                    $error = "ডাটাবেস এ সংরক্ষণ করতে ব্যর্থ হয়েছে!";
                    // Delete uploaded file if db insert fails
                    if ($prescription_file && file_exists($prescription_file)) {
                        unlink($prescription_file);
                    }
                }
            } catch (Exception $e) {
                $error = "একটি ত্রুটি ঘটেছে: " . $e->getMessage();
                // Delete uploaded file on error
                if ($prescription_file && file_exists($prescription_file)) {
                    unlink($prescription_file);
                }
            }
        } else {
            $error = "নির্বাচিত তারিখে এই শাখায় কোনো ল্যাব শিডিউল পাওয়া যায়নি।";
            // Delete uploaded file if schedule not found
            if ($prescription_file && file_exists($prescription_file)) {
                unlink($prescription_file);
            }
        }
    }
} else {
    // If someone accesses this page directly, redirect them
    header("Location: lab_tests.php");
    exit();
}
?>
<main>
    <div class="booking-container" style="max-width: 600px; margin: 50px auto; padding: 20px;">
        <?php if ($success): ?>
            <div class="success-container" style="text-align: center; background: white; padding: 40px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);">
                <div class="success-icon" style="font-size: 60px;">✅</div>
                <h1 style="color: #27ae60; margin: 20px 0;">আবেদন সফল হয়েছে!</h1>
                <p style="color: #666; font-size: 16px; margin-bottom: 20px;"><?= htmlspecialchars($success) ?></p>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                    <i class="fas fa-info-circle" style="color: #3498db;"></i>
                    <span style="color: #555;">ল্যাব অ্যাডমিন আপনার প্রেসক্রিপশন যাচাই করবে এবং নোটিফিকেশনের মাধ্যমে জানাবে।</span>
                </div>
                <a href="index.php" class="btn" style="display: inline-block; padding: 12px 30px; background: linear-gradient(135deg, #27ae60, #2ecc71); color: white; text-decoration: none; border-radius: 8px; font-weight: 600;">হোম পেজ এ ফিরে যান</a>
            </div>
        <?php else: ?>
            <div class="error-container" style="text-align: center; background: white; padding: 40px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);">
                <div class="error-icon" style="font-size: 60px;">❌</div>
                <h1 style="color: #e74c3c; margin: 20px 0;">আবেদন ব্যর্থ হয়েছে!</h1>
                <p style="color: #666; font-size: 16px; margin-bottom: 20px;"><?= htmlspecialchars($error) ?></p>
                <a href="lab_tests.php" class="btn" style="display: inline-block; padding: 12px 30px; background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; text-decoration: none; border-radius: 8px; font-weight: 600;">আবার চেষ্টা করুন</a>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php include_once('includes/footer.php'); ?>
