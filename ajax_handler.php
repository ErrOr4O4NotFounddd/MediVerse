<?php
// Start output buffering to prevent any stray output before headers
ob_start();

include_once('includes/db_config.php');

// Clean buffer and set header
ob_end_clean();
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {
    // Check ambulance ride status for real-time updates
    case 'check_ride_status':
        session_start();
        $ride_id = (int)($_GET['ride_id'] ?? 0);
        $user_id = $_SESSION['user_id'] ?? 0;
        
        if ($ride_id > 0 && $user_id > 0) {
            $stmt = $conn->prepare("
                SELECT ar.status, ar.driver_id, 
                       ad.ambulance_number as vehicle_number,
                       u.full_name as driver_name, u.phone as driver_phone,
                       ad.current_latitude, ad.current_longitude
                FROM ambulance_rides ar
                LEFT JOIN ambulance_drivers ad ON ar.driver_id = ad.id
                LEFT JOIN users u ON ad.user_id = u.id
                WHERE ar.id = ? AND ar.patient_user_id = ?
            ");
            $stmt->bind_param("ii", $ride_id, $user_id);
            $stmt->execute();
            $ride = $stmt->get_result()->fetch_assoc();
            
            if ($ride) {
                echo json_encode([
                    'success' => true,
                    'status' => $ride['status'],
                    'has_driver' => !empty($ride['driver_id']),
                    'driver' => $ride['driver_id'] ? [
                        'name' => $ride['driver_name'],
                        'phone' => $ride['driver_phone'],
                        'vehicle' => $ride['vehicle_number'],
                        'lat' => $ride['current_latitude'],
                        'lng' => $ride['current_longitude']
                    ] : null
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Ride not found']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid request']);
        }
        break;

    // --- THIS IS THE KEY CHANGE ---
    case 'find_branches_for_test':
        $test_id = (int)($_GET['test_id'] ?? 0);
        $branches = [];
        if ($test_id > 0) {
            // Find branches where the selected test is available and active
            $sql = "
                SELECT
                    hb.id,
                    CONCAT(h.name, ' - ', hb.branch_name) as name,
                    blt.price
                FROM branch_lab_tests blt
                JOIN hospital_branches hb ON blt.branch_id = hb.id
                JOIN hospitals h ON hb.hospital_id = h.id
                WHERE
                    blt.test_id = ?
                    AND blt.is_available = 1
                    AND hb.status = 'Active'
                    AND h.status = 'Active'
                ORDER BY h.name, hb.branch_name
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $test_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $branches = $result->fetch_all(MYSQLI_ASSOC);
        }
        echo json_encode($branches);
        break;

    // --- This part remains the same ---
    case 'find_dates_for_lab':
        $branch_id = (int)($_GET['branch_id'] ?? 0);
        $dates = [];
        if ($branch_id > 0) {
            $sql = "SELECT DISTINCT day_of_week FROM lab_schedules WHERE branch_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $branch_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $scheduled_days = [];
            while ($row = $result->fetch_assoc()) { $scheduled_days[] = $row['day_of_week']; }
            
            if (!empty($scheduled_days)) {
                for ($i = 0; $i < 14; $i++) {
                    $date = new DateTime();
                    $date->modify("+$i day");
                    $day_name = $date->format('l');
                    if (in_array($day_name, $scheduled_days)) {
                        $dates[] = ['value' => $date->format('Y-m-d'), 'name' => $date->format('d F, Y (l)')];
                    }
                    if(count($dates) >= 7) break;
                }
            }
        }
        echo json_encode($dates);
        break;

    case 'get_divisions':
        $divisions = [];
        $result = $conn->query("SELECT id, name FROM divisions ORDER BY name");
        if ($result) {
            $divisions = $result->fetch_all(MYSQLI_ASSOC);
        }
        echo json_encode($divisions);
        break;

    case 'get_districts':
        $division_id = (int)($_GET['division_id'] ?? 0);
        $districts = [];
        if ($division_id > 0) {
            $stmt = $conn->prepare("SELECT id, name FROM districts WHERE division_id = ? ORDER BY name");
            $stmt->bind_param("i", $division_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $districts = $result->fetch_all(MYSQLI_ASSOC);
        }
        echo json_encode($districts);
        break;

    case 'get_upazilas':
        $district_id = (int)($_GET['district_id'] ?? 0);
        $upazilas = [];
        if ($district_id > 0) {
            $stmt = $conn->prepare("SELECT id, name FROM upazilas WHERE district_id = ? ORDER BY name");
            $stmt->bind_param("i", $district_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $upazilas = $result->fetch_all(MYSQLI_ASSOC);
        }
        echo json_encode($upazilas);
        break;

    case 'toggle_donor_availability':
        session_start();
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['error' => 'Not logged in']);
            break;
        }
        $user_id = $_SESSION['user_id'];
        // Check if donor
        $stmt = $conn->prepare("SELECT is_donor, donor_availability FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows == 0) {
            echo json_encode(['error' => 'User not found']);
            break;
        }
        $user = $result->fetch_assoc();
        if (!$user['is_donor']) {
            echo json_encode(['error' => 'Not a donor']);
            break;
        }
        $new_availability = ($user['donor_availability'] == 'Available') ? 'Unavailable' : 'Available';
        $update_stmt = $conn->prepare("UPDATE users SET donor_availability = ? WHERE id = ?");
        $update_stmt->bind_param("si", $new_availability, $user_id);
        if ($update_stmt->execute()) {
            echo json_encode(['success' => true, 'new_availability' => $new_availability]);
        } else {
            echo json_encode(['error' => 'Update failed']);
        }
        break;

    // Search donors for blood bank
    case 'search_donors':
        $query = trim($_GET['query'] ?? '');
        $donors = [];
        if (strlen($query) >= 2) {
            $search = "%$query%";
            $stmt = $conn->prepare("
                SELECT id, full_name, phone, blood_group 
                FROM users 
                WHERE (full_name LIKE ? OR phone LIKE ?) 
                    AND is_active = 1 
                    AND deleted_at IS NULL
                ORDER BY full_name 
                LIMIT 10
            ");
            $stmt->bind_param("ss", $search, $search);
            $stmt->execute();
            $donors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        echo json_encode($donors);
        break;

    // Search blood bank stock
    case 'search_blood_stock':
        $blood_group = $_GET['blood_group'] ?? '';
        $district_id = (int)($_GET['district_id'] ?? 0);
        $results = [];
        
        $sql = "SELECT * FROM vw_blood_bank_search WHERE 1=1";
        $params = [];
        $types = '';
        
        if (!empty($blood_group)) {
            $sql .= " AND blood_group = ?";
            $params[] = $blood_group;
            $types .= 's';
        }
        if ($district_id > 0) {
            $sql .= " AND district_id = ?";
            $params[] = $district_id;
            $types .= 'i';
        }
        
        $sql .= " ORDER BY bbs.units_available DESC LIMIT 20";
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode($results);
        break;

    // Search available ambulances
    case 'search_ambulances':
        $lat = floatval($_GET['lat'] ?? 0);
        $lng = floatval($_GET['lng'] ?? 0);
        $radius = floatval($_GET['radius'] ?? 10); // km
        $type = $_GET['type'] ?? '';
        
        $results = [];
        
        if ($lat && $lng) {
            $sql = "
                SELECT ad.*, u.full_name as driver_name, u.phone,
                       ap.base_fare, ap.per_km_rate,
                       (6371 * acos(cos(radians(?)) * cos(radians(current_latitude)) 
                       * cos(radians(current_longitude) - radians(?)) 
                       + sin(radians(?)) * sin(radians(current_latitude)))) AS distance
                FROM ambulance_drivers ad
                JOIN users u ON ad.user_id = u.id
                LEFT JOIN ambulance_pricing ap ON ad.ambulance_type = ap.ambulance_type
                WHERE ad.status = 'Verified'
                    AND ad.is_available = 1
                    AND ad.is_online = 1
                    AND ad.deleted_at IS NULL
                    AND ad.current_latitude IS NOT NULL
            ";
            $params = [$lat, $lng, $lat];
            $types = 'ddd';
            
            if (!empty($type)) {
                $sql .= " AND ad.ambulance_type = ?";
                $params[] = $type;
                $types .= 's';
            }
            
            $sql .= " HAVING distance < ? ORDER BY distance ASC LIMIT 10";
            $params[] = $radius;
            $types .= 'd';
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        echo json_encode($results);
        break;

    // Update driver location
    case 'update_driver_location':
        session_start();
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['error' => 'Not logged in']);
            break;
        }
        
        $lat = floatval($_POST['lat'] ?? 0);
        $lng = floatval($_POST['lng'] ?? 0);
        $user_id = $_SESSION['user_id'];
        
        if ($lat && $lng) {
            $stmt = $conn->prepare("
                UPDATE ambulance_drivers 
                SET current_latitude = ?, current_longitude = ?, last_location_update = NOW() 
                WHERE user_id = ?
            ");
            $stmt->bind_param("ddi", $lat, $lng, $user_id);
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Update failed']);
            }
        } else {
            echo json_encode(['error' => 'Invalid coordinates']);
        }
        break;

    // Get pharmacy inventory
    case 'search_medicines':
        $pharmacy_id = (int)($_GET['pharmacy_id'] ?? 0);
        $query = trim($_GET['query'] ?? '');
        $results = [];
        
        if ($pharmacy_id > 0 && strlen($query) >= 2) {
            $search = "%$query%";
            $stmt = $conn->prepare("
                SELECT pi.*, m.medicine_name, m.generic_name, m.manufacturer
                FROM pharmacy_inventory pi
                JOIN medicines m ON pi.medicine_id = m.id
                WHERE pi.pharmacy_id = ? 
                    AND (m.medicine_name LIKE ? OR m.generic_name LIKE ?)
                    AND pi.quantity > 0
                    AND pi.deleted_at IS NULL
                ORDER BY m.medicine_name
                LIMIT 20
            ");
            $stmt->bind_param("iss", $pharmacy_id, $search, $search);
            $stmt->execute();
            $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        echo json_encode($results);
        break;
        
    case 'get_order_details':
        $order_id = (int)($_GET['order_id'] ?? 0);
        if ($order_id > 0) {
            // Get order info
            $stmt = $conn->prepare("
                SELECT ps.*, u.full_name as customer_name, u.phone as customer_phone
                FROM pharmacy_sales ps
                LEFT JOIN users u ON ps.customer_id = u.id
                WHERE ps.id = ?
            ");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            
            if ($order) {
                // Get order items
                $items_stmt = $conn->prepare("
                    SELECT psi.*, m.medicine_name
                    FROM pharmacy_sale_items psi
                    JOIN medicines m ON psi.medicine_id = m.id
                    WHERE psi.sale_id = ?
                ");
                $items_stmt->bind_param("i", $order_id);
                $items_stmt->execute();
                $items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'order' => $order,
                    'items' => $items
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Order not found']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
        }
        break;
    case 'mark_notification_read':
        session_start();
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['error' => 'Not logged in']);
            break;
        }
        $notification_id = (int)($_POST['notification_id'] ?? 0);
        $user_id = $_SESSION['user_id'];
        
        if ($notification_id > 0) {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $notification_id, $user_id);
            $stmt->execute();
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Invalid notification ID']);
        }
        break;
    
    // Get districts by division (alias for compatibility)
    case 'get_districts_by_division':
        $division_id = (int)($_GET['division_id'] ?? 0);
        $districts = [];
        if ($division_id > 0) {
            $stmt = $conn->prepare("SELECT id, name FROM districts WHERE division_id = ? ORDER BY name");
            $stmt->bind_param("i", $division_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $districts = $result->fetch_all(MYSQLI_ASSOC);
        }
        echo json_encode($districts);
        break;
    
    // Search blood donors
    case 'search_blood_donors':
        $division_id = (int)($_GET['division_id'] ?? 0);
        $district_id = (int)($_GET['district_id'] ?? 0);
        $blood_group = $_GET['blood_group'] ?? '';
        $donors = [];
        
        if ($division_id > 0 && $district_id > 0 && !empty($blood_group)) {
            try {
                $sql = "
                    SELECT u.full_name, u.phone, u.blood_group, 
                           d.name as district_name, divs.name as division_name
                    FROM users u
                    LEFT JOIN districts d ON u.district_id = d.id
                    LEFT JOIN divisions divs ON d.division_id = divs.id
                    WHERE u.is_donor = 1
                      AND (u.donor_availability = 'Available' OR u.donor_availability = '')
                      AND u.blood_group = ?
                      AND (u.division_id = ? OR d.division_id = ?)
                      AND (u.district_id = ? OR d.id = ?)
                      AND u.is_active = 1
                      AND u.deleted_at IS NULL
                      AND (u.last_donation_date IS NULL OR u.last_donation_date <= CURDATE() - INTERVAL 3 MONTH)
                    ORDER BY u.full_name
                    LIMIT 50
                ";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("siiii", $blood_group, $division_id, $division_id, $district_id, $district_id);
                $stmt->execute();
                $donors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            } catch (Exception $e) {
                echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
                exit;
            }
        }
        echo json_encode($donors);
        break;
    
    // Search hospital blood banks
    case 'search_hospital_blood_banks':
        $division_id = (int)($_GET['division_id'] ?? 0);
        $district_id = (int)($_GET['district_id'] ?? 0);
        $blood_group = $_GET['blood_group'] ?? '';
        $hospitals = [];
        
        if ($division_id > 0 && $district_id > 0 && !empty($blood_group)) {
            try {
                $sql = "
                    SELECT * FROM vw_blood_bank_search
                    WHERE blood_group = ?
                      AND division_id = ?
                      AND district_id = ?
                    ORDER BY units_available DESC, hospital_name
                    LIMIT 50
                ";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sii", $blood_group, $division_id, $district_id);
                $stmt->execute();
                $hospitals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            } catch (Exception $e) {
                echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
                exit;
            }
        }
        echo json_encode($hospitals);
        break;
        
    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
}

$conn->close();
?>
