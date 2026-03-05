<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include_once('includes/db_config.php');
include_once('includes/ActivityLog.php');

$user_id = $_SESSION['user_id'] ?? null;

// Log logout before destroying session
if ($user_id) {
    $activityLog = new ActivityLog($conn, $user_id);
    $activityLog->logout();
}

session_unset();
session_destroy();
header("Location: index.php");
exit();
?>
