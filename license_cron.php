<?php
/**
 * License Automation Cron Job
 * MediVerse Project
 * 
 * This script should be run daily (via Windows Task Scheduler or manually).
 * It handles:
 *   1. Sending progressive pre-expiry notifications (30, 25, 20, 15, 10, 5, 1 days)
 *   2. Auto-enforcing expired licenses (setting status to 'LicenseExpired')
 * 
 * Usage: 
 *   - Browser: http://localhost/mediverse/license_cron.php
 *   - CLI: php license_cron.php
 *   - Windows Task Scheduler: schtasks /create /tn "MediVerse License Cron" /tr "c:\xampp\php\php.exe c:\xampp\htdocs\mediverse\license_cron.php" /sc daily /st 01:00
 */

// Prevent web access in production (remove this block for testing)
// if (php_sapi_name() !== 'cli') { die('CLI only'); }

include_once(__DIR__ . '/includes/db_config.php');

$log = [];
$notification_days = [30, 25, 20, 15, 10, 5, 1];

// ============================================================
// PART 1: Progressive Pre-Expiry Notifications
// ============================================================

$log[] = "===== License Cron Job Started: " . date('Y-m-d H:i:s') . " =====";

// Find all active hospitals (Private only) with license_expiry_date within 30 days
$stmt = $conn->prepare("
    SELECT h.id, h.name, h.license_expiry_date, 
           DATEDIFF(h.license_expiry_date, CURDATE()) AS days_remaining
    FROM hospitals h
    WHERE h.status = 'Active' 
      AND h.hospital_type = 'Private'
      AND h.license_expiry_date IS NOT NULL
      AND h.deleted_at IS NULL
      AND DATEDIFF(h.license_expiry_date, CURDATE()) BETWEEN 0 AND 30
    ORDER BY days_remaining ASC
");
$stmt->execute();
$expiring_hospitals = $stmt->get_result();

while ($hospital = $expiring_hospitals->fetch_assoc()) {
    $days_remaining = (int)$hospital['days_remaining'];
    $hospital_id = $hospital['id'];
    $hospital_name = $hospital['name'];
    
    // Check if this day matches one of our notification milestones
    $notify_day = null;
    foreach ($notification_days as $day) {
        if ($days_remaining <= $day) {
            $notify_day = $day;
            // We want the closest milestone that hasn't been sent yet
            break;
        }
    }
    
    // Find the exact matching notification day
    if (!in_array($days_remaining, $notification_days)) {
        // Not a notification day, skip
        continue;
    }
    $notify_day = $days_remaining;
    
    // Check if notification already sent for this hospital + day combination
    $check_stmt = $conn->prepare("
        SELECT id FROM license_notifications 
        WHERE hospital_id = ? AND days_remaining = ?
    ");
    $check_stmt->bind_param("ii", $hospital_id, $notify_day);
    $check_stmt->execute();
    $existing = $check_stmt->get_result();
    
    if ($existing->num_rows > 0) {
        $log[] = "  [SKIP] Hospital '{$hospital_name}' (ID: {$hospital_id}) - Notification for {$notify_day} days already sent.";
        continue;
    }
    
    // Find the Super Admin(s) of this hospital
    $admin_stmt = $conn->prepare("
        SELECT u.id AS user_id, u.full_name, u.email
        FROM users u
        JOIN branch_admins ba ON u.id = ba.user_id
        JOIN hospital_branches hb ON ba.branch_id = hb.id
        WHERE hb.hospital_id = ? 
          AND u.role = 'SuperHospitalAdmin'
          AND hb.deleted_at IS NULL
    ");
    $admin_stmt->bind_param("i", $hospital_id);
    $admin_stmt->execute();
    $admins = $admin_stmt->get_result();
    
    $notification_message = "আপনার হাসপাতালের ({$hospital_name}) লাইসেন্স {$notify_day} দিনের মধ্যে মেয়াদ উত্তীর্ণ হবে। লাইসেন্স মেয়াদ উত্তীর্ণের আগে নবায়ন না করলে, লাইসেন্স বাতিল হবে এবং সিস্টেম অ্যাক্সেস ব্লক করা হবে। অনুগ্রহ করে যত তাড়াতাড়ি সম্ভব আপনার লাইসেন্স নবায়ন করুন।";
    
    $notified_count = 0;
    while ($admin = $admins->fetch_assoc()) {
        // Insert notification into the notifications table
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, message, is_read, created_at) 
            VALUES (?, ?, 0, NOW())
        ");
        $notif_stmt->bind_param("is", $admin['user_id'], $notification_message);
        $notif_stmt->execute();
        $notified_count++;
    }
    
    // Record that this notification has been sent
    if ($notified_count > 0) {
        $record_stmt = $conn->prepare("
            INSERT INTO license_notifications (hospital_id, days_remaining) 
            VALUES (?, ?)
        ");
        $record_stmt->bind_param("ii", $hospital_id, $notify_day);
        $record_stmt->execute();
        
        $log[] = "  [SENT] Hospital '{$hospital_name}' (ID: {$hospital_id}) - {$notify_day} days remaining, notified {$notified_count} admin(s).";
    } else {
        $log[] = "  [WARN] Hospital '{$hospital_name}' (ID: {$hospital_id}) - No Super Admin found to notify.";
    }
}

// ============================================================
// PART 2: Auto-Enforce Expired Licenses
// ============================================================

$log[] = "";
$log[] = "--- Checking for expired licenses ---";

$expired_stmt = $conn->prepare("
    SELECT h.id, h.name, h.license_expiry_date
    FROM hospitals h
    WHERE h.status = 'Active' 
      AND h.hospital_type = 'Private'
      AND h.license_expiry_date IS NOT NULL
      AND h.license_expiry_date < CURDATE()
      AND h.deleted_at IS NULL
");
$expired_stmt->execute();
$expired_hospitals = $expired_stmt->get_result();

while ($hospital = $expired_hospitals->fetch_assoc()) {
    $hospital_id = $hospital['id'];
    $hospital_name = $hospital['name'];
    
    // Set hospital status to LicenseExpired
    $update_stmt = $conn->prepare("UPDATE hospitals SET status = 'LicenseExpired' WHERE id = ?");
    $update_stmt->bind_param("i", $hospital_id);
    $update_stmt->execute();
    
    // Also set all branches to Inactive
    $branch_stmt = $conn->prepare("UPDATE hospital_branches SET status = 'Inactive' WHERE hospital_id = ? AND deleted_at IS NULL");
    $branch_stmt->bind_param("i", $hospital_id);
    $branch_stmt->execute();
    
    // Send final expiry notification to Super Admin(s)
    $admin_stmt = $conn->prepare("
        SELECT u.id AS user_id
        FROM users u
        JOIN branch_admins ba ON u.id = ba.user_id
        JOIN hospital_branches hb ON ba.branch_id = hb.id
        WHERE hb.hospital_id = ? 
          AND u.role = 'SuperHospitalAdmin'
          AND hb.deleted_at IS NULL
    ");
    $admin_stmt->bind_param("i", $hospital_id);
    $admin_stmt->execute();
    $admins = $admin_stmt->get_result();
    
    $expiry_message = "আপনার হাসপাতালের ({$hospital_name}) লাইসেন্সের মেয়াদ উত্তীর্ণ হয়েছে। সিস্টেম অ্যাক্সেস ব্লক করা হয়েছে। অনুগ্রহ করে লাইসেন্স নবায়নের জন্য সিস্টেম অ্যাডমিনের সাথে যোগাযোগ করুন।";
    
    while ($admin = $admins->fetch_assoc()) {
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (user_id, message, is_read, created_at) 
            VALUES (?, ?, 0, NOW())
        ");
        $notif_stmt->bind_param("is", $admin['user_id'], $expiry_message);
        $notif_stmt->execute();
    }
    
    $log[] = "  [EXPIRED] Hospital '{$hospital_name}' (ID: {$hospital_id}) - Status set to 'LicenseExpired', branches deactivated.";
}

if ($expired_hospitals->num_rows === 0) {
    $log[] = "  No expired licenses found.";
}

$log[] = "";
$log[] = "===== License Cron Job Completed: " . date('Y-m-d H:i:s') . " =====";

$conn->close();

// Output the log
$output = implode("\n", $log);

if (php_sapi_name() === 'cli') {
    echo $output . "\n";
} else {
    // Browser output
    echo "<pre style='font-family: monospace; padding: 20px; background: #1a1a2e; color: #16c784; border-radius: 10px; max-width: 900px; margin: 40px auto;'>";
    echo "<h2 style='color: #e94560; margin-top:0;'>🔑 MediVerse License Cron Job</h2>";
    echo htmlspecialchars($output);
    echo "</pre>";
}
