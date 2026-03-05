<?php
include_once('includes/db_config.php');
session_start();


if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied.']);
    exit();
}

$user_id = $_SESSION['user_id'];
$rateable_id = (int)$_POST['rateable_id'];
$rateable_type = $_POST['rateable_type'];
$rating = (int)$_POST['rating'];
$comment = trim($_POST['comment']);


if (!in_array($rateable_type, ['Doctor', 'Branch']) || $rating < 1 || $rating > 5) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data provided.']);
    exit();
}


if ($rateable_type === 'Doctor') {
    $stmt_check = $conn->prepare("
        SELECT a.id FROM bookings a WHERE a.booking_type = 'doctor'
        JOIN doctor_schedules ds ON a.doctor_schedule_id = ds.id
        WHERE a.user_id = ? AND ds.doctor_id = ? AND a.status = 'Completed'
    ");
    $stmt_check->bind_param("ii", $user_id, $rateable_id);
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['error' => 'রিভিউ দেওয়ার জন্য আপনাকে এই ডাক্তারের কাছে অন্তত একটি অ্যাপয়েন্টমেন্ট সম্পন্ন করতে হবে।']);
        exit();
    }
}

try {
    
    $stmt = $conn->prepare("
        INSERT INTO ratings (user_id, rateable_id, rateable_type, rating, comment)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment)
    ");
    $stmt->bind_param("iisis", $user_id, $rateable_id, $rateable_type, $rating, $comment);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => 'আপনার মতামত সফলভাবে জমা হয়েছে!']);
    } else {
        throw new Exception("Database execution failed.");
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'একটি সমস্যা হয়েছে। অনুগ্রহ করে আবার চেষ্টা করুন।']);
}
?>
