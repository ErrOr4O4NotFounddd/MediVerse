<?php
require_once 'includes/db_config.php';

header('Content-Type: application/json');

if (!isset($_GET['ride_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing ride_id']);
    exit;
}

$ride_id = (int)$_GET['ride_id'];

$stmt = $conn->prepare("
    SELECT ad.current_latitude, ad.current_longitude, ar.status, ar.id as ride_id
    FROM ambulance_rides ar
    JOIN ambulance_drivers ad ON ar.driver_id = ad.id
    WHERE ar.id = ?
");

if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
    exit;
}

$stmt->bind_param("i", $ride_id);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();

if ($data) {
    echo json_encode([
        'status' => 'success',
        'lat' => (float)$data['current_latitude'],
        'lng' => (float)$data['current_longitude'],
        'ride_status' => $data['status']
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Ride or driver not found']);
}
