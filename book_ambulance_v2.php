<?php
session_start();
require_once 'includes/db_config.php';
require_once 'includes/ActivityLog.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?redirect_url=" . urlencode($_SERVER['REQUEST_URI']));
    exit();
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';
$ride_id = null;

// Get branch_id from URL parameter if provided (from district ambulance page)
$preselected_branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

// Fetch hospital address if branch_id is preselected (for auto-fill destination)
$preselected_address = '';
if ($preselected_branch_id > 0) {
    $stmt_addr = $conn->prepare("SELECT address, hospital_name AS name FROM vw_active_branches WHERE id = ?");
    $stmt_addr->bind_param("i", $preselected_branch_id);
    $stmt_addr->execute();
    $branch_info = $stmt_addr->get_result()->fetch_assoc();
    if ($branch_info) {
        $preselected_address = $branch_info['name'] . ', ' . $branch_info['address'];
    }
    $stmt_addr->close();
}

// Get user info
$stmt = $conn->prepare("SELECT full_name, phone FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Get ambulance pricing
$pricing = $conn->query("SELECT ambulance_type, base_fare, per_km_rate, waiting_charge_per_min FROM ambulance_pricing WHERE is_active = 1 ORDER BY ambulance_type")->fetch_all(MYSQLI_ASSOC);

// Get hospitals for hospital-based booking option
$hospitals = $conn->query("SELECT h.id, h.name, hb.id as branch_id, hb.branch_name FROM hospitals h JOIN hospital_branches hb ON h.id = hb.hospital_id WHERE hb.status = 'Active' ORDER BY h.name, hb.branch_name")->fetch_all(MYSQLI_ASSOC);

// Handle cancel ride
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_ride'])) {
    $cancel_ride_id = (int)$_POST['ride_id'];
    $stmt = $conn->prepare("
        UPDATE ambulance_rides 
        SET status = 'Cancelled', cancelled_at = NOW()
        WHERE id = ? AND patient_user_id = ? AND status = 'Requested'
    ");
    $stmt->bind_param("ii", $cancel_ride_id, $user_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        // Also update bookings table if it exists
        $stmt_sync = $conn->prepare("UPDATE bookings SET status = 'Cancelled' WHERE id = (SELECT booking_id FROM ambulance_rides WHERE id = ?)");
        $stmt_sync->bind_param("i", $cancel_ride_id);
        $stmt_sync->execute();
        $stmt_sync->close();

        $_SESSION['ride_cancelled_flash'] = true;
        header("Location: book_ambulance_v2.php");
        exit();
    } else {
        $error = "রাইড বাতিল করতে ব্যর্থ হয়েছে। ড্রাইভার ইতিমধ্যে গ্রহণ করে থাকতে পারে।";
    }
}

// Check for cancel success message
$show_cancelled_card = false;
if (isset($_GET['cancelled']) && $_GET['cancelled'] == 1) {
    $success = "রাইড সফলভাবে বাতিল করা হয়েছে।";
    $show_cancelled_card = true;
} elseif (isset($_SESSION['ride_cancelled_flash'])) {
    $success = "রাইড সফলভাবে বাতিল করা হয়েছে।";
    $show_cancelled_card = true;
    unset($_SESSION['ride_cancelled_flash']);
}

// Handle booking submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_ambulance'])) {
    $ambulance_type = $_POST['ambulance_type'];
    $pickup_address = trim($_POST['pickup_address']);
    $pickup_lat = floatval($_POST['pickup_lat']);
    $pickup_lng = floatval($_POST['pickup_lng']);
    $dropoff_address = trim($_POST['dropoff_address']);
    $dropoff_lat = floatval($_POST['dropoff_lat']);
    $dropoff_lng = floatval($_POST['dropoff_lng']);
    $patient_name = trim($_POST['patient_name']);
    $patient_phone = trim($_POST['patient_phone']);
    $emergency_type = $_POST['emergency_type'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $hospital_branch_id = isset($_POST['hospital_branch_id']) ? (int)$_POST['hospital_branch_id'] : 0;
    
    // Calculate distance and fare
    $distance = 0;
    if ($pickup_lat && $pickup_lng && $dropoff_lat && $dropoff_lng) {
        $earth_radius = 6371;
        $lat_diff = deg2rad($dropoff_lat - $pickup_lat);
        $lng_diff = deg2rad($dropoff_lng - $pickup_lng);
        $a = sin($lat_diff/2) * sin($lat_diff/2) + cos(deg2rad($pickup_lat)) * cos(deg2rad($dropoff_lat)) * sin($lng_diff/2) * sin($lng_diff/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $distance = $earth_radius * $c;
    }
    
    $type_pricing = null;
    foreach ($pricing as $p) {
        if ($p['ambulance_type'] === $ambulance_type) {
            $type_pricing = $p;
            break;
        }
    }
    
    $estimated_fare = 0;
    if ($type_pricing) {
        $estimated_fare = $type_pricing['base_fare'] + ($distance * $type_pricing['per_km_rate']);
        if ($type_pricing['waiting_charge_per_min'] > 0) {
            $estimated_fare += 10 * $type_pricing['waiting_charge_per_min'];
        }
    }
    
    $active_check = $conn->prepare("
        SELECT id FROM ambulance_rides 
        WHERE patient_user_id = ? 
        AND status IN ('Requested', 'Accepted', 'Driver En Route', 'Arrived', 'In Progress')
        LIMIT 1
    ");
    $active_check->bind_param("i", $user_id);
    $active_check->execute();
    $existing_ride = $active_check->get_result()->fetch_assoc();
    
    if ($existing_ride) {
        $error = "আপনার ইতিমধ্যে একটি চলমান রাইড রয়েছে! নতুন রিকোয়েস্ট পাঠাতে আগেরটি সম্পন্ন বা বাতিল করুন।";
    } elseif (empty($pickup_address) || empty($dropoff_address)) {
        $error = "পিকআপ ও গন্তব্য ঠিকানা আবশ্যক!";
    } elseif (!$pickup_lat || !$pickup_lng) {
        $error = "পিকআপ লোকেশন ম্যাপে নির্বাচন করুন!";
    } else {
        $conn->begin_transaction();
        
        try {
            // Get hospital_id from branch_id
            $hospital_id = 0;
            if ($hospital_branch_id > 0) {
                $stmt_hosp = $conn->prepare("SELECT hospital_id FROM hospital_branches WHERE id = ?");
                $stmt_hosp->bind_param("i", $hospital_branch_id);
                $stmt_hosp->execute();
                $hospital_id = $stmt_hosp->get_result()->fetch_assoc()['hospital_id'] ?? 0;
                $stmt_hosp->close();
            }
            
            // Insert into ambulance_rides (always)
            $branch_id_val = ($hospital_branch_id > 0) ? $hospital_branch_id : null;
            
            $stmt = $conn->prepare("
                INSERT INTO ambulance_rides 
                (patient_user_id, ambulance_type, pickup_address, pickup_latitude, pickup_longitude, 
                 destination_address, destination_latitude, destination_longitude, patient_name, patient_phone,
                 destination_branch_id, branch_id, estimated_distance_km, estimated_fare, status, requested_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Requested', NOW())
            ");
            $stmt->bind_param("issddssddiiidd", 
                $user_id, $ambulance_type, $pickup_address, $pickup_lat, $pickup_lng,
                $dropoff_address, $dropoff_lat, $dropoff_lng, $patient_name, $patient_phone,
                $branch_id_val, $branch_id_val, $distance, $estimated_fare
            );
            $stmt->execute();
            $ride_id = $conn->insert_id;
            
            // If hospital branch is selected, also create a record in bookings table
            if ($hospital_branch_id > 0 && $hospital_id > 0) {
                $stmt_booking = $conn->prepare("
                    INSERT INTO bookings 
                    (user_id, branch_id, booking_type, pickup_location, destination,
                     status, created_at)
                    VALUES (?, ?, 'ambulance', ?, ?, 'Pending', NOW())
                ");
                $stmt_booking->bind_param("iiss", 
                    $user_id, $hospital_branch_id, $pickup_address, $dropoff_address
                );
                $stmt_booking->execute();
                $booking_id = $stmt_booking->insert_id;
                $stmt_booking->close();
                
                // Link ambulance_rides to bookings
                $stmt_link = $conn->prepare("UPDATE ambulance_rides SET booking_id = ? WHERE id = ?");
                $stmt_link->bind_param("ii", $booking_id, $ride_id);
                $stmt_link->execute();
                $stmt_link->close();
            }
            
            $activityLog = new ActivityLog($conn, $user_id);
            $activityLog->ambulanceBooked($ride_id, $user_id, [
                'pickup_location' => $pickup_address,
                'destination' => $dropoff_address,
                'ambulance_type' => $ambulance_type
            ]);
            
            $conn->commit();
            
            header("Location: book_ambulance_v2.php?booked=1");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "রিকোয়েস্ট পাঠাতে ব্যর্থ হয়েছে! অনুগ্রহ করে আবার চেষ্টা করুন।";
        }
    }
}

// Check for success message from redirect
$show_completed_card = false;
if (isset($_GET['booked']) && $_GET['booked'] == 1) {
    $success = "অ্যাম্বুলেন্স রিকোয়েস্ট সফলভাবে পাঠানো হয়েছে! নিকটস্থ ড্রাইভার শীঘ্রই আপনার সাথে যোগাযোগ করবে।";
} elseif (isset($_GET['completed']) && $_GET['completed'] == 1) {
    $show_completed_card = true;
} elseif (isset($_SESSION['ride_completed_flash'])) {
    $show_completed_card = true;
    unset($_SESSION['ride_completed_flash']);
}

// Check for active ride
$active_ride = null;
$stmt = $conn->prepare("
    SELECT ar.id, ar.patient_user_id, ar.driver_id, ar.status, ar.ambulance_type,
           ar.pickup_latitude, ar.pickup_longitude, ar.pickup_address,
           ar.destination_latitude, ar.destination_longitude, ar.destination_address,
           ar.estimated_fare, ar.final_fare, ar.created_at,
           ad.ambulance_number as vehicle_number, u.full_name as driver_name, u.phone as driver_phone,
           ad.current_latitude, ad.current_longitude
    FROM ambulance_rides ar
    LEFT JOIN ambulance_drivers ad ON ar.driver_id = ad.id
    LEFT JOIN users u ON ad.user_id = u.id
    WHERE ar.patient_user_id = ? AND ar.status IN ('Requested', 'Accepted', 'Driver En Route', 'Arrived', 'In Progress')
    ORDER BY ar.created_at DESC LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$active_ride = $stmt->get_result()->fetch_assoc();

// Check for recently completed ride (within last 5 minutes) to show success message
$last_completed = null;
if (!$active_ride && $show_completed_card && !isset($_GET['new'])) {
    $stmt = $conn->prepare("
        SELECT ar.id, ar.pickup_address, ar.destination_address, ar.final_fare, ar.completed_at, u.full_name as driver_name
        FROM ambulance_rides ar
        LEFT JOIN ambulance_drivers ad ON ar.driver_id = ad.id
        LEFT JOIN users u ON ad.user_id = u.id
        WHERE ar.patient_user_id = ? 
        AND ar.status = 'Completed'
        ORDER BY ar.completed_at DESC LIMIT 1
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $last_completed = $stmt->get_result()->fetch_assoc();
}

// Check for recently cancelled ride (within last 5 minutes) to show message
$last_cancelled = null;
if (!$active_ride && !$last_completed && $show_cancelled_card && !isset($_GET['new'])) {
    $stmt = $conn->prepare("
        SELECT ar.id, ar.pickup_address, ar.destination_address, ar.cancelled_at
        FROM ambulance_rides ar
        WHERE ar.patient_user_id = ? 
        AND ar.status = 'Cancelled'
        ORDER BY ar.cancelled_at DESC LIMIT 1
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $last_cancelled = $stmt->get_result()->fetch_assoc();
}

// Include common header
include_once('includes/header.php');
?>

<!-- Page Specific Styles -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
    /* Hero Section */
    .ambulance-hero {
        background: linear-gradient(135deg, #e74c3c 0%, #c0392b 50%, #922b21 100%);
        padding: 60px 20px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    
    .ambulance-hero::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="rgba(255,255,255,0.05)"/></svg>');
        background-size: 80px;
        animation: float 20s infinite linear;
    }
    
    @keyframes float {
        0% { transform: translateY(0) rotate(0deg); }
        100% { transform: translateY(-80px) rotate(360deg); }
    }
    
    .ambulance-hero-content {
        position: relative;
        z-index: 1;
        max-width: 700px;
        margin: 0 auto;
    }
    
    .ambulance-hero-icon {
        width: 100px;
        height: 100px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 25px;
        backdrop-filter: blur(10px);
        border: 3px solid rgba(255, 255, 255, 0.3);
        animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
        0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.4); }
        50% { transform: scale(1.05); box-shadow: 0 0 0 20px rgba(255, 255, 255, 0); }
    }
    
    .ambulance-hero-icon i {
        font-size: 45px;
        color: white;
    }
    
    .ambulance-hero h1 {
        color: white;
        font-size: 42px;
        font-weight: 800;
        margin: 0 0 15px;
        text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    }
    
    .ambulance-hero p {
        color: rgba(255, 255, 255, 0.95);
        font-size: 18px;
        margin: 0;
    }
    
    /* Main Container */
    .ambulance-container {
        max-width: 900px;
        margin: 0 auto;
        padding: 50px 20px;
    }
    
    /* Card Styles */
    .booking-card {
        background: white;
        border-radius: 25px;
        box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
        margin-bottom: 30px;
        overflow: hidden;
        border: 1px solid #f0f0f0;
    }
    
    .booking-card-header {
        background: linear-gradient(135deg, #e74c3c, #c0392b);
        padding: 25px 35px;
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .booking-card-header h2 {
        color: white;
        font-size: 22px;
        font-weight: 700;
        margin: 0;
    }
    
    .booking-card-header i {
        font-size: 28px;
        color: rgba(255, 255, 255, 0.9);
    }
    
    .booking-card-body {
        padding: 35px;
    }
    
    /* Alert Styles */
    .alert {
        padding: 18px 25px;
        border-radius: 15px;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 15px;
        font-size: 16px;
    }
    
    .alert-success {
        background: linear-gradient(135deg, #d4edda, #c3e6cb);
        color: #155724;
        border: 1px solid #b1dfbb;
    }
    
    .alert-error {
        background: linear-gradient(135deg, #f8d7da, #f5c6cb);
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    .alert i {
        font-size: 24px;
    }
    
    /* Active Ride Card */
    .active-ride-card {
        background: white;
        border-radius: 25px;
        overflow: hidden;
        box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
        border: 2px solid #e74c3c;
    }
    
    .active-ride-header {
        background: linear-gradient(135deg, #e74c3c, #c0392b);
        padding: 25px 35px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .active-ride-header h3 {
        color: white;
        font-size: 22px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 0;
    }
    
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        border-radius: 25px;
        font-weight: 700;
        font-size: 14px;
    }
    
    .status-pending { background: rgba(255, 255, 255, 0.2); color: white; }
    .status-accepted { background: #27ae60; color: white; }
    .status-enroute { background: #3498db; color: white; }
    .status-arrived { background: #9b59b6; color: white; }
    .status-picked { background: #2c3e50; color: white; }
    
    .ride-details {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        padding: 30px 35px;
        background: #f8f9fa;
    }
    
    .ride-detail-item {
        background: white;
        padding: 20px;
        border-radius: 15px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
    }
    
    .ride-detail-item label {
        display: block;
        font-size: 13px;
        color: #6c757d;
        text-transform: uppercase;
        font-weight: 600;
        margin-bottom: 8px;
    }
    
    .ride-detail-item strong {
        display: block;
        font-size: 16px;
        color: #212529;
    }
    
    /* Form Styles */
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
    }
    
    @media (max-width: 768px) {
        .form-row { grid-template-columns: 1fr; }
        .ambulance-hero h1 { font-size: 28px; }
        .ambulance-hero-icon { width: 70px; height: 70px; }
        .ambulance-hero-icon i { font-size: 30px; }
        .booking-card-header, .active-ride-header { padding: 20px 25px; flex-direction: column; text-align: center; }
        .booking-card-body { padding: 25px; }
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
        margin-bottom: 10px;
        color: #495057;
        font-size: 15px;
    }
    
    .form-group label i {
        color: #e74c3c;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 14px 20px;
        border: 2px solid #e9ecef;
        border-radius: 12px;
        font-size: 15px;
        font-family: 'Hind Siliguri', sans-serif;
        transition: all 0.3s ease;
        background: #f8f9fa;
    }
    
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #e74c3c;
        background: white;
        box-shadow: 0 0 0 4px rgba(231, 76, 60, 0.1);
    }
    
    /* Map Styles */
    #map {
        height: 400px;
        width: 100%;
        border-radius: 15px;
        margin-bottom: 20px;
        border: 2px solid #e9ecef;
    }
    
    .map-controls {
        display: flex;
        gap: 15px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }
    
    .btn-map {
        padding: 14px 25px;
        background: #f8f9fa;
        color: #495057;
        border: 2px solid #e9ecef;
        border-radius: 12px;
        cursor: pointer;
        font-weight: 600;
        font-size: 15px;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        transition: all 0.3s ease;
    }
    
    .btn-map:hover {
        border-color: #e74c3c;
        color: #e74c3c;
    }
    
    .btn-map.active {
        background: linear-gradient(135deg, #e74c3c, #c0392b);
        color: white;
        border-color: #e74c3c;
    }
    
    .location-hint {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 15px;
        background: #fff3cd;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 14px;
        color: #856404;
    }
    
    .location-hint i {
        font-size: 18px;
        color: #e74c3c;
    }
    
    /* Form Styles */
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
    }
    
    @media (max-width: 768px) { .form-row { grid-template-columns: 1fr; } }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        font-weight: 600;
        margin-bottom: 8px;
        color: #2c3e50;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 14px 18px;
        border: 2px solid #e0e0e0;
        border-radius: 10px;
        font-size: 16px;
        font-family: 'Hind Siliguri', sans-serif;
        transition: all 0.3s ease;
    }
    
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #e74c3c;
        box-shadow: 0 0 0 4px rgba(231, 76, 60, 0.1);
    }
    
    /* Ambulance Types */
    .ambulance-types {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 25px;
    }
    
    @media (max-width: 992px) { .ambulance-types { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 576px) { .ambulance-types { grid-template-columns: 1fr; } }
    
    .ambulance-type {
        border: 2px solid #e0e0e0;
        border-radius: 12px;
        padding: 25px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s ease;
        background: white;
    }
    
    .ambulance-type:hover {
        border-color: #e74c3c;
        transform: translateY(-3px);
        box-shadow: 0 5px 20px rgba(231, 76, 60, 0.15);
    }
    
    .ambulance-type.selected {
        border-color: #e74c3c;
        background: #fff5f5;
    }
    
    .ambulance-type i {
        font-size: 42px;
        color: #e74c3c;
        margin-bottom: 15px;
        display: block;
    }
    
    .ambulance-type h4 {
        font-size: 20px;
        color: #333;
        margin: 0 0 10px 0;
    }
    
    .ambulance-type .price {
        color: #27ae60;
        font-size: 24px;
        font-weight: 700;
    }
    
    .ambulance-type .rate {
        color: #666;
        font-size: 13px;
        margin-top: 5px;
    }
    
    /* Emergency Types */
    .emergency-types {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 25px;
    }
    
    .emergency-tag {
        padding: 10px 18px;
        background: #f0f0f0;
        border: 2px solid transparent;
        border-radius: 25px;
        cursor: pointer;
        font-size: 14px;
        transition: all 0.3s ease;
        font-family: 'Hind Siliguri', sans-serif;
    }
    
    .emergency-tag:hover {
        background: #e0e0e0;
    }
    
    .emergency-tag.selected {
        background: #e74c3c;
        color: white;
        border-color: #c0392b;
    }
    
    /* Fare Estimate */
    .fare-estimate {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 25px;
    }
    
    .fare-estimate h4 {
        margin: 0 0 15px 0;
        color: #2c3e50;
        font-size: 18px;
    }
    
    .fare-row {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid #ddd;
    }
    
    .fare-row:last-child {
        border-bottom: none;
    }
    
    .fare-row.total {
        font-weight: 700;
        font-size: 20px;
        color: #27ae60;
        padding-top: 15px;
    }
    
    .fare-label {
        color: #666;
    }
    
    .fare-value {
        font-weight: 600;
        color: #333;
    }
    
    /* Driver Info */
    .driver-info {
        background: white;
        border-radius: 12px;
        padding: 20px;
        margin-top: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    
    .driver-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 15px;
    }
    
    .driver-avatar {
        width: 60px;
        height: 60px;
        background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 24px;
    }
    
    .driver-details h4 {
        margin: 0;
        font-size: 18px;
        color: #333;
    }
    
    .driver-details p {
        margin: 5px 0 0 0;
        color: #666;
    }
    
    .driver-contact {
        display: flex;
        gap: 15px;
    }
    
    .driver-contact a {
        padding: 10px 20px;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .driver-contact a.call {
        background: #27ae60;
        color: white;
    }
    
    .driver-contact a.track {
        background: #3498db;
        color: white;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #666;
    }
    
    .empty-state i {
        font-size: 60px;
        color: #ddd;
        margin-bottom: 20px;
        display: block;
    }
    
    .empty-state p {
        font-size: 18px;
        margin-bottom: 25px;
    }
    
    /* Fare Estimate */
    .fare-estimate {
        background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        border-radius: 15px;
        padding: 25px;
    }
    
    .fare-estimate h4 {
        margin: 0 0 20px 0;
        color: #212529;
        font-size: 18px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .fare-estimate h4 i { color: #e74c3c; }
    
    .fare-row {
        display: flex;
        justify-content: space-between;
        padding: 12px 0;
        border-bottom: 1px solid #ddd;
    }
    
    .fare-row:last-child { border-bottom: none; }
    
    .fare-row.total {
        font-weight: 800;
        font-size: 22px;
        color: #27ae60;
        padding-top: 15px;
        margin-top: 10px;
        border-top: 2px dashed #ddd;
    }
    
    .fare-label { color: #666; }
    .fare-value { font-weight: 600; color: #333; }
    
    /* Emergency Types */
    .emergency-types {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 25px;
    }
    
    .emergency-tag {
        padding: 12px 22px;
        background: #f8f9fa;
        border: 2px solid transparent;
        border-radius: 25px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.3s ease;
        font-family: 'Hind Siliguri', sans-serif;
    }
    
    .emergency-tag:hover {
        background: #e9ecef;
        border-color: #dee2e6;
    }
    
    .emergency-tag.selected {
        background: linear-gradient(135deg, #e74c3c, #c0392b);
        color: white;
        border-color: #e74c3c;
    }
    
    /* Buttons */
    .btn {
        padding: 14px 30px;
        border-radius: 12px;
        border: none;
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        transition: all 0.3s ease;
    }
    
    .btn-primary {
        width: 100%;
        justify-content: center;
        background: linear-gradient(135deg, #e74c3c, #c0392b);
        color: white;
        padding: 18px;
        font-size: 18px;
        box-shadow: 0 10px 30px rgba(231, 76, 60, 0.3);
    }
    
    .btn-primary:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 40px rgba(231, 76, 60, 0.4);
    }
    
    .btn-primary:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }
    
    .btn-danger {
        background: linear-gradient(135deg, #e74c3c, #c0392b);
        color: white;
    }
    
    .btn-danger:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(231, 76, 60, 0.3);
    }
    
    .btn-back {
        background: #6c757d;
        color: white;
        text-decoration: none;
    }
    
    .btn-back:hover {
        background: #5a6268;
        color: white;
    }
    
    /* Driver Info */
    .driver-info {
        background: white;
        border-radius: 15px;
        padding: 25px;
        margin: 25px 35px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
    }
    
    .driver-header {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-bottom: 20px;
    }
    
    .driver-avatar {
        width: 70px;
        height: 70px;
        background: linear-gradient(135deg, #e74c3c, #c0392b);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 28px;
    }
    
    .driver-details h4 {
        margin: 0;
        font-size: 20px;
        color: #333;
    }
    
    .driver-details p {
        margin: 5px 0 0 0;
        color: #666;
    }
    
    .driver-contact {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
    }
    
    .driver-contact a {
        padding: 12px 25px;
        border-radius: 10px;
        text-decoration: none;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .driver-contact a.call { background: #27ae60; color: white; }
    .driver-contact a.track { background: #3498db; color: white; }
    
    .empty-state {
        text-align: center;
        padding: 50px;
    }
    
    .empty-state i {
        font-size: 60px;
        color: #dee2e6;
        margin-bottom: 20px;
    }
    
    .empty-state p { font-size: 16px; color: #666; margin: 0; }
</style>
</style>

<div class="ambulance-hero">
    <div class="ambulance-hero-content">
        <div class="ambulance-hero-icon">
            <i class="fas fa-ambulance"></i>
        </div>
        <h1>জরুরি অ্যাম্বুলেন্স সেবা</h1>
        <p>২৪/৭ জরুরি চিকিৎসা পরিবহন - আপনার নিকটস্থ সেবা</p>
    </div>
</div>

<div class="ambulance-container">
    <!-- Success/Error Messages -->
    <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <span><?= htmlspecialchars($success) ?></span>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($error)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>
    
    <?php if ($active_ride): ?>
        <!-- Active Ride Section -->
        <div class="active-ride-card">
            <div class="active-ride-header">
                <h3><i class="fas fa-car-side"></i> আপনার চলমান রাইড</h3>
                <?php
                $status_class = 'status-pending';
                $status_text = 'অপেক্ষমাণ';
                $status_icon = 'fa-clock';
                
                switch($active_ride['status']) {
                    case 'Accepted':
                        $status_class = 'status-accepted';
                        $status_text = 'গ্রহণ করা হয়েছে';
                        $status_icon = 'fa-check-circle';
                        break;
                    case 'Driver En Route':
                        $status_class = 'status-enroute';
                        $status_text = 'ড্রাইভার আসছে';
                        $status_icon = 'fa-car';
                        break;
                    case 'Arrived':
                        $status_class = 'status-arrived';
                        $status_text = 'ড্রাইভার পৌঁছে গেছে';
                        $status_icon = 'fa-map-marker-alt';
                        break;
                    case 'In Progress':
                        $status_class = 'status-picked';
                        $status_text = 'রোগী তোলা হয়েছে';
                        $status_icon = 'fa-ambulance';
                        break;
                }
                ?>
                <span id="status-badge" class="status-badge <?= $status_class ?>">
                    <i class="fas <?= $status_icon ?>"></i> <?= $status_text ?>
                </span>
            </div>
            
            <div class="ride-details">
                <div class="ride-detail-item">
                    <label>অ্যাম্বুলেন্স টাইপ</label>
                    <strong><?= htmlspecialchars($active_ride['ambulance_type']) ?></strong>
                </div>
                <div class="ride-detail-item">
                    <label>পিকআপ লোকেশন</label>
                    <strong><?= htmlspecialchars($active_ride['pickup_address']) ?></strong>
                </div>
                <div class="ride-detail-item">
                    <label>গন্তব্য</label>
                    <strong><?= htmlspecialchars($active_ride['destination_address']) ?></strong>
                </div>
                <div class="ride-detail-item">
                    <label>আনুমানিক ভাড়া</label>
                    <strong>৳<?= number_format($active_ride['estimated_fare'], 2) ?></strong>
                </div>
            </div>
            
            <?php if ($active_ride['driver_id']): ?>
                <div id="driver-info-section" class="driver-info">
                    <div class="driver-header">
                        <div class="driver-avatar"><i class="fas fa-user"></i></div>
                        <div class="driver-details">
                            <h4><?= htmlspecialchars($active_ride['driver_name']) ?></h4>
                            <p><?= htmlspecialchars($active_ride['vehicle_number']) ?></p>
                        </div>
                    </div>
                    <div class="driver-contact">
                        <a href="tel:<?= htmlspecialchars($active_ride['driver_phone']) ?>" class="call">
                            <i class="fas fa-phone"></i> কল করুন
                        </a>
                        <?php if ($active_ride['current_latitude'] && $active_ride['current_longitude']): ?>
                            <a href="https://maps.google.com/?q=<?= $active_ride['current_latitude'] ?>,<?= $active_ride['current_longitude'] ?>" target="_blank" class="track">
                                <i class="fas fa-location-arrow"></i> ট্র্যাক করুন
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <p style="color: #666; margin: 0 35px 25px; font-size: 15px;"><i class="fas fa-info-circle"></i> ড্রাইভার নির্ধারণের জন্য অপেক্ষা করুন...</p>
            <?php endif; ?>
            
            <?php if ($active_ride['status'] === 'Requested'): ?>
                <div class="ride-actions" style="padding: 0 35px 35px; display: flex; gap: 15px;">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="ride_id" value="<?= $active_ride['id'] ?>">
                        <button type="submit" name="cancel_ride" class="btn btn-danger" onclick="return confirm('আপনি কি নিশ্চিতভাবে এই রাইড বাতিল করতে চান?')">
                            <i class="fas fa-times"></i> রাইড বাতিল করুন
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php elseif ($last_cancelled): ?>
        <!-- Recently Cancelled Ride -->
        <div class="booking-card">
            <div class="booking-card-header" style="background: #e74c3c;">
                <i class="fas fa-times-circle"></i>
                <h2>রাইড বাতিল</h2>
            </div>
            <div class="booking-card-body">
                <div class="alert alert-danger">
                    <i class="fas fa-times-circle"></i>
                    <span>দুঃখিত, আপনার অ্যাম্বুলেন্স রাইড রিকোয়েস্টটি বাতিল করা হয়েছে।</span>
                </div>
                <div style="margin-bottom: 25px; color: #666;">
                    <p>পিকআপ: <strong><?= htmlspecialchars($last_cancelled['pickup_address']) ?></strong></p>
                    <p>গন্তব্য: <strong><?= htmlspecialchars($last_cancelled['destination_address']) ?></strong></p>
                    <p>সময়: <strong><?= date('h:i A', strtotime($last_cancelled['cancelled_at'])) ?></strong></p>
                </div>
                <a href="book_ambulance_v2.php?new=1" class="btn btn-primary">
                    <i class="fas fa-plus"></i> নতুন রাইড বুক করুন
                </a>
            </div>
        </div>
    <?php elseif ($last_completed): ?>
        <!-- Recently Completed Ride -->
        <div class="booking-card">
            <div class="booking-card-header">
                <i class="fas fa-check-circle"></i>
                <h2>রাইড সম্পন্ন</h2>
            </div>
            <div class="booking-card-body">
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span>আপনার রাইড সফলভাবে সম্পন্ন হয়েছে! ড্রাইভার: <?= htmlspecialchars($last_completed['driver_name']) ?></span>
                </div>
                <a href="book_ambulance_v2.php?new=1" class="btn btn-primary">
                    <i class="fas fa-plus"></i> নতুন রাইড বুক করুন
                </a>
            </div>
        </div>
    <?php else: ?>
        <!-- Booking Form -->
        <form method="POST" id="bookingForm">
            <input type="hidden" name="book_ambulance" value="1">
            <input type="hidden" name="ambulance_type" id="ambulance_type" value="Basic">
            <input type="hidden" name="pickup_lat" id="pickup_lat">
            <input type="hidden" name="pickup_lng" id="pickup_lng">
            <input type="hidden" name="dropoff_lat" id="dropoff_lat">
            <input type="hidden" name="dropoff_lng" id="dropoff_lng">
            <input type="hidden" name="emergency_type" id="emergency_type">
            
            <div class="booking-card">
                <div class="booking-card-header">
                    <i class="fas fa-map-marker-alt"></i>
                    <h2>লোকেশন নির্বাচন করুন</h2>
                </div>
                <div class="booking-card-body">
                    <div class="map-controls">
                        <button type="button" class="btn-map active" id="btn-pickup">
                            <i class="fas fa-play"></i> পিকআপ লোকেশন
                        </button>
                        <button type="button" class="btn-map" id="btn-dropoff">
                            <i class="fas fa-flag"></i> গন্তব্য লোকেশন
                        </button>
                        <button type="button" class="btn-map" id="btn-current-location">
                            <i class="fas fa-crosshairs"></i> বর্তমান লোকেশন
                        </button>
                    </div>
                    
                    <div class="location-hint">
                        <i class="fas fa-info-circle"></i>
                        <span id="location-hint-text">ম্যাপে পিকআপ লোকেশনে ক্লিক করুন</span>
                    </div>
                    
                    <div id="map"></div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-map-marker-alt" style="color: #e74c3c;"></i> পিকআপ ঠিকানা</label>
                            <input type="text" name="pickup_address" id="pickup_address" placeholder="পিকআপ ঠিকানা লিখুন বা ম্যাপে ক্লিক করুন" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-flag-checkered" style="color: #27ae60;"></i> গন্তব্য ঠিকানা</label>
                            <input type="text" name="dropoff_address" id="dropoff_address" placeholder="গন্তব্য ঠিকানা লিখুন বা ম্যাপে ক্লিক করুন" required>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="booking-card">
                <div class="booking-card-header">
                    <i class="fas fa-ambulance"></i>
                    <h2>অ্যাম্বুলেন্সের ধরন নির্বাচন করুন</h2>
                </div>
                <div class="booking-card-body">
                    <?php if (empty($pricing)): ?>
                        <div class="empty-state">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>বর্তমানে কোনো অ্যাম্বুলেন্স সেবা উপলব্ধ নেই। অনুগ্রহ করে পরে চেষ্টা করুন।</p>
                        </div>
                    <?php else: ?>
                        <div class="ambulance-types">
                            <?php foreach ($pricing as $p): ?>
                                <div class="ambulance-type <?php echo $p['ambulance_type'] === 'Basic' ? 'selected' : ''; ?>"
                                     data-type="<?php echo $p['ambulance_type']; ?>"
                                     data-base="<?php echo $p['base_fare']; ?>"
                                     data-rate="<?php echo $p['per_km_rate']; ?>">
                                    <i class="fas fa-<?php echo $p['ambulance_type'] === 'ICU' ? 'heart-pulse' : 'ambulance'; ?>"></i>
                                    <h4><?php echo $p['ambulance_type']; ?></h4>
                                    <div class="price">৳<?php echo number_format($p['base_fare']); ?></div>
                                    <div class="rate">+ ৳<?php echo number_format($p['per_km_rate']); ?>/কিমি</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="fare-estimate">
                            <h4><i class="fas fa-calculator"></i> আনুমানিক ভাড়া</h4>
                            <div class="fare-row">
                                <span class="fare-label">বেস ফেয়ার</span>
                                <span class="fare-value" id="baseFare">৳0</span>
                            </div>
                            <div class="fare-row">
                                <span class="fare-label">দূরত্ব</span>
                                <span class="fare-value"><span id="distanceKm">0.0</span> কিমি</span>
                            </div>
                            <div class="fare-row">
                                <span class="fare-label">দূরত্ব ভাড়া</span>
                                <span class="fare-value" id="distanceFare">৳0</span>
                            </div>
                            <div class="fare-row total">
                                <span class="fare-label">মোট (আনুমানিক)</span>
                                <span class="fare-value" id="totalFare">৳0</span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="booking-card">
                <div class="booking-card-header">
                    <i class="fas fa-hospital"></i>
                    <h2>হাসপাতাল নির্বাচন (ঐচ্ছিক)</h2>
                </div>
                <div class="booking-card-body">
                    <p style="margin-bottom: 15px; color: #666; font-size: 14px;">
                        <i class="fas fa-info-circle"></i> 
                        কোনো নির্দিষ্ট হাসপাতালে যেতে চাইলে নিচের তালিকা থেকে সেই হাসপাতালের শাখা নির্বাচন করুন। এতে ঐ হাসপাতালের অ্যাডমিন আপনার বুকিং দেখতে পারবেন।
                    </p>
                    <div class="form-group">
                        <label><i class="fas fa-hospital"></i> হাসপাতাল/শাখা নির্বাচন করুন</label>
                        <select name="hospital_branch_id" id="hospital_branch_id">
                            <option value="0">-- কোনো হাসপাতাল নির্বাচন করবেন না (সাধারণ অ্যাম্বুলেন্স) --</option>
                            <?php if (!empty($hospitals)): ?>
                                <?php $last_hospital = ''; ?>
                                <?php foreach ($hospitals as $h): ?>
                                    <?php if ($h['name'] !== $last_hospital): ?>
                                        <?php if ($last_hospital !== ''): ?>
                                                </optgroup>
                                        <?php endif; ?>
                                        <optgroup label="<?= htmlspecialchars($h['name']) ?>">
                                        <?php $last_hospital = $h['name']; ?>
                                    <?php endif; ?>
                                    <option value="<?= $h['branch_id'] ?>" data-address="<?= htmlspecialchars($h['name'] . ', ' . $h['branch_name'] . (empty($h['address']) ? '' : ', ' . $h['address'])) ?>" <?= ($preselected_branch_id == $h['branch_id']) ? 'selected' : '' ?>><?= htmlspecialchars($h['branch_name']) ?></option>
                                <?php endforeach; ?>
                                <?php if ($last_hospital !== ''): ?>
                                        </optgroup>
                                <?php endif; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="booking-card">
                <div class="booking-card-header">
                    <i class="fas fa-user-injured"></i>
                    <h2>রোগীর তথ্য</h2>
                </div>
                <div class="booking-card-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> রোগীর নাম</label>
                            <input type="text" name="patient_name" id="patient_name" placeholder="রোগীর নাম" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> রোগীর ফোন</label>
                            <input type="tel" name="patient_phone" id="patient_phone" placeholder="ফোন নম্বর" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-exclamation-circle"></i> জরুরি অবস্থার ধরন</label>
                        <div class="emergency-types">
                            <span class="emergency-tag selected" data-type="General">সাধারণ</span>
                            <span class="emergency-tag" data-type="Heart">হৃদরোগ</span>
                            <span class="emergency-tag" data-type="Accident">দুর্ঘটনা</span>
                            <span class="emergency-tag" data-type="Pregnancy">গর্ভাবস্থা</span>
                            <span class="emergency-tag" data-type="Child">শিশু</span>
                            <span class="emergency-tag" data-type="Other">অন্যান্য</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-sticky-note"></i> অতিরিক্ত নোট (ঐচ্ছিক)</label>
                        <textarea name="notes" id="notes" rows="3" placeholder="অতিরিক্ত তথ্য যেমন: রোগীর বয়স, লক্ষণ ইত্যাদি..."></textarea>
                    </div>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary" <?php echo empty($pricing) ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''; ?>>
                <i class="fas fa-ambulance"></i> অ্যাম্বুলেন্স বুক করুন
            </button>
        </form>
    <?php endif; ?>
</div>

<script>
    // Location selection state - global variables
    let selectingPickup = true;
    let pickupMarker = null;
    let dropoffMarker = null;
    let routeLine = null;
    let map = null;
    
    // Icons - defined globally
    let pickupIcon, dropoffIcon;
    
    function reverseGeocode(lat, lng, inputId) {
        const input = document.getElementById(inputId);
        if (!input) return;
        input.value = 'ঠিকানা খোঁজা হচ্ছে...';
        
        fetch(`ajax_geocode.php?lat=${lat}&lng=${lng}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.address) {
                    input.value = data.address;
                } else {
                    input.value = 'ঠিকানা লিখুন';
                }
            })
            .catch(error => {
                console.log('Geocoding error:', error);
                input.value = 'ঠিকানা লিখুন';
            });
    }
    
    function getCurrentLocation() {
        if (!map) {
            console.log('Map not initialized yet');
            return;
        }
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(position) {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                
                map.setView([lat, lng], 15);
                
                if (pickupMarker) map.removeLayer(pickupMarker);
                pickupMarker = L.marker([lat, lng], {icon: pickupIcon}).addTo(map);
                document.getElementById('pickup_lat').value = lat;
                document.getElementById('pickup_lng').value = lng;
                reverseGeocode(lat, lng, 'pickup_address');
                selectingPickup = false;
                updateButtonStates();
            }, function(error) {
                console.log('Geolocation error:', error);
                alert('লোকেশন পেতে ব্যর্থ হয়েছে। অনুগ্রহ করে ম্যাপে ক্লিক করে লোকেশন নির্বাচন করুন।');
            });
        } else {
            alert('আপনার ব্রাউজার জিপিএস সাপোর্ট করে না। অনুগ্রহ করে ম্যাপে ক্লিক করে লোকেশন নির্বাচন করুন।');
        }
    }
    
    function selectLocation(type) {
        if (type === 'pickup') {
            selectingPickup = true;
        } else {
            selectingPickup = false;
        }
        updateButtonStates();
    }
    
    function updateButtonStates() {
        const btnPickup = document.getElementById('btn-pickup');
        const btnDropoff = document.getElementById('btn-dropoff');
        if (btnPickup) btnPickup.classList.toggle('active', selectingPickup);
        if (btnDropoff) btnDropoff.classList.toggle('active', !selectingPickup);
        
        const hintText = document.getElementById('location-hint-text');
        if (hintText) {
            hintText.textContent = selectingPickup ? 'ম্যাপে পিকআপ লোকেশনে ক্লিক করুন' : 'ম্যাপে গন্তব্য লোকেশনে ক্লিক করুন';
        }
    }
    
    function calculateFare() {
        if (!map) return;
        const selected = document.querySelector('.ambulance-type.selected');
        if (!selected) return;
        
        const baseFare = parseFloat(selected.dataset.base) || 0;
        const perKmRate = parseFloat(selected.dataset.rate) || 0;
        
        let distance = 0;
        if (pickupMarker && dropoffMarker) {
            const pickup = pickupMarker.getLatLng();
            const dropoff = dropoffMarker.getLatLng();
            distance = pickup.distanceTo(dropoff) / 1000;
        }
        
        const distanceFare = distance * perKmRate;
        const total = baseFare + distanceFare;
        
        const baseFareEl = document.getElementById('baseFare');
        const distanceKmEl = document.getElementById('distanceKm');
        const distanceFareEl = document.getElementById('distanceFare');
        const totalFareEl = document.getElementById('totalFare');
        
        if (baseFareEl) baseFareEl.textContent = '৳' + baseFare.toFixed(0);
        if (distanceKmEl) distanceKmEl.textContent = distance.toFixed(1);
        if (distanceFareEl) distanceFareEl.textContent = '৳' + distanceFare.toFixed(0);
        if (totalFareEl) totalFareEl.textContent = '৳' + total.toFixed(0);
    }
    
    // Initialize map when page loads
    document.addEventListener('DOMContentLoaded', function() {
        const mapElement = document.getElementById('map');
        if (mapElement) {
            // Default to Dhaka
            map = L.map('map').setView([23.8103, 90.4125], 13);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);
            
            // Custom icons
            pickupIcon = L.divIcon({
                html: '<i class="fas fa-map-marker" style="color: #e74c3c; font-size: 40px; text-shadow: 2px 2px 4px rgba(0,0,0,0.3);"></i>',
                iconSize: [40, 40],
                iconAnchor: [20, 40],
                className: 'custom-marker'
            });
            
            dropoffIcon = L.divIcon({
                html: '<i class="fas fa-flag-checkered" style="color: #27ae60; font-size: 35px;"></i>',
                iconSize: [35, 35],
                iconAnchor: [17, 35],
                className: 'custom-marker'
            });
            
            map.on('click', function(e) {
                if (selectingPickup) {
                    if (pickupMarker) map.removeLayer(pickupMarker);
                    pickupMarker = L.marker(e.latlng, {icon: pickupIcon}).addTo(map);
                    document.getElementById('pickup_lat').value = e.latlng.lat;
                    document.getElementById('pickup_lng').value = e.latlng.lng;
                    reverseGeocode(e.latlng.lat, e.latlng.lng, 'pickup_address');
                    selectingPickup = false;
                    updateButtonStates();
                } else {
                    if (dropoffMarker) map.removeLayer(dropoffMarker);
                    dropoffMarker = L.marker(e.latlng, {icon: dropoffIcon}).addTo(map);
                    document.getElementById('dropoff_lat').value = e.latlng.lat;
                    document.getElementById('dropoff_lng').value = e.latlng.lng;
                    reverseGeocode(e.latlng.lat, e.latlng.lng, 'dropoff_address');
                    selectingPickup = true;
                    updateButtonStates();
                    
                    if (routeLine) map.removeLayer(routeLine);
                    if (pickupMarker && dropoffMarker) {
                        routeLine = L.polyline([pickupMarker.getLatLng(), dropoffMarker.getLatLng()], {
                            color: '#e74c3c',
                            dashArray: '10, 10'
                        }).addTo(map);
                        calculateFare();
                    }
                }
            });
            
            // Button event listeners
            document.getElementById('btn-pickup').addEventListener('click', function() {
                selectingPickup = true;
                updateButtonStates();
            });
            
            document.getElementById('btn-dropoff').addEventListener('click', function() {
                selectingPickup = false;
                updateButtonStates();
            });
            
            document.getElementById('btn-current-location').addEventListener('click', getCurrentLocation);
            
            // Auto-fill destination from preselected hospital branch
            <?php if (!empty($preselected_address)): ?>
            (function() {
                const preselectedAddress = '<?= addslashes($preselected_address) ?>';
                document.getElementById('dropoff_address').value = preselectedAddress;
                // Switch to pickup mode so user sets pickup next
                selectingPickup = true;
                updateButtonStates();
                
                // Forward geocode the hospital address to place map marker
                fetch(`ajax_geocode.php?address=${encodeURIComponent(preselectedAddress)}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.lat && data.lng) {
                            if (dropoffMarker) map.removeLayer(dropoffMarker);
                            dropoffMarker = L.marker([data.lat, data.lng], {icon: dropoffIcon}).addTo(map);
                            document.getElementById('dropoff_lat').value = data.lat;
                            document.getElementById('dropoff_lng').value = data.lng;
                            map.setView([data.lat, data.lng], 14);
                        }
                    })
                    .catch(err => console.log('Forward geocoding failed:', err));
            })();
            <?php endif; ?>
            
            // Ambulance type selection
            document.querySelectorAll('.ambulance-type').forEach(el => {
                el.addEventListener('click', function() {
                    document.querySelectorAll('.ambulance-type').forEach(e => e.classList.remove('selected'));
                    this.classList.add('selected');
                    document.getElementById('ambulance_type').value = this.dataset.type;
                    calculateFare();
                });
            });
            
            // Auto-fill from branch dropdown
            document.getElementById('hospital_branch_id').addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                if (this.value > 0 && selectedOption.dataset.address) {
                    const preselectedAddress = selectedOption.dataset.address;
                    document.getElementById('dropoff_address').value = preselectedAddress;
                    selectingPickup = true;
                    updateButtonStates();
                    
                    fetch(`ajax_geocode.php?address=${encodeURIComponent(preselectedAddress)}`)
                        .then(r => r.json())
                        .then(data => {
                            if (data.success && data.lat && data.lng) {
                                if (dropoffMarker) map.removeLayer(dropoffMarker);
                                dropoffMarker = L.marker([data.lat, data.lng], {icon: dropoffIcon}).addTo(map);
                                document.getElementById('dropoff_lat').value = data.lat;
                                document.getElementById('dropoff_lng').value = data.lng;
                                map.setView([data.lat, data.lng], 14);
                                
                                if (pickupMarker && dropoffMarker) {
                                    if (routeLine) map.removeLayer(routeLine);
                                    routeLine = L.polyline([pickupMarker.getLatLng(), dropoffMarker.getLatLng()], {
                                        color: '#e74c3c',
                                        dashArray: '10, 10'
                                    }).addTo(map);
                                    calculateFare();
                                }
                            }
                        })
                        .catch(err => console.log('Forward geocoding failed:', err));
                }
            });
            
            // Emergency type selection
            document.querySelectorAll('.emergency-tag').forEach(el => {
                el.addEventListener('click', function() {
                    document.querySelectorAll('.emergency-tag').forEach(e => e.classList.remove('selected'));
                    this.classList.add('selected');
                    document.getElementById('emergency_type').value = this.dataset.type;
                });
            });
            
            // Auto-refresh for active ride
            <?php if ($active_ride && !in_array($active_ride['status'], ['Completed', 'Cancelled'])): ?>
            let lastStatus = '<?php echo $active_ride['status']; ?>';
            let hasDriver = <?php echo $active_ride['driver_id'] ? 'true' : 'false'; ?>;
            
            function updateDriverInfo(driver) {
                const driverSection = document.getElementById('driver-info-section');
                if (driverSection && driver) {
                    driverSection.innerHTML = `
                        <div class="driver-info">
                            <div class="driver-header">
                                <div class="driver-avatar"><i class="fas fa-user"></i></div>
                                <div class="driver-details">
                                    <h4>${driver.name}</h4>
                                    <p>${driver.vehicle}</p>
                                </div>
                            </div>
                            <div class="driver-contact">
                                <a href="tel:${driver.phone}" class="call">
                                    <i class="fas fa-phone"></i> কল করুন
                                </a>
                                ${driver.lat ? `
                                <a href="https://maps.google.com/?q=${driver.lat},${driver.lng}" target="_blank" class="track">
                                    <i class="fas fa-location-arrow"></i> ট্র্যাক করুন
                                </a>
                                ` : ''}
                            </div>
                        </div>
                    `;
                }
            }
            
            function updateStatusBadge(status) {
                const statusBadge = document.getElementById('status-badge');
                if (!statusBadge) return;
                
                let statusClass = 'status-pending';
                let statusText = 'অপেক্ষমাণ';
                let statusIcon = 'fa-clock';
                
                switch(status) {
                    case 'Accepted':
                        statusClass = 'status-accepted';
                        statusText = 'গ্রহণ করা হয়েছে';
                        statusIcon = 'fa-check-circle';
                        break;
                    case 'Driver En Route':
                        statusClass = 'status-enroute';
                        statusText = 'ড্রাইভার আসছে';
                        statusIcon = 'fa-car';
                        break;
                    case 'Arrived':
                        statusClass = 'status-arrived';
                        statusText = 'ড্রাইভার পৌঁছে গেছে';
                        statusIcon = 'fa-map-marker-alt';
                        break;
                    case 'In Progress':
                        statusClass = 'status-picked';
                        statusText = 'রোগী তোলা হয়েছে';
                        statusIcon = 'fa-ambulance';
                        break;
                }
                
                statusBadge.className = 'status-badge ' + statusClass;
                statusBadge.innerHTML = `<i class="fas ${statusIcon}"></i> ${statusText}`;
            }
            
            function checkRideStatus() {
                fetch('ajax_handler.php?action=check_ride_status&ride_id=<?php echo $active_ride['id']; ?>')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (data.status !== lastStatus) {
                                updateStatusBadge(data.status);
                                lastStatus = data.status;
                            }
                            
                            if (data.has_driver && !hasDriver && data.driver) {
                                updateDriverInfo(data.driver);
                                hasDriver = true;
                            }
                            
                            if (data.status === 'Completed' || data.status === 'Cancelled') {
                                location.reload();
                            }
                        }
                    })
                    .catch(err => console.log('Status check failed:', err));
            }
            
            setInterval(checkRideStatus, 5000);
            <?php endif; ?>
        }
    });
</script>

<?php include_once('includes/footer.php'); ?>
