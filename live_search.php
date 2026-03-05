<?php
header('Content-Type: application/json');
include_once('includes/db_config.php');

$results = ['doctors' => [], 'hospitals' => []];
$query = isset($_GET['query']) ? trim($_GET['query']) : '';

if (strlen($query) >= 2) { 
    $like_term = "%{$query}%";

    // --- Search for Doctors (Case-Insensitive) ---
    $sql_doctors = "
        SELECT u.full_name, d.id 
        FROM doctors d
        JOIN users u ON d.user_id = u.id
        WHERE d.is_verified = 'Verified' AND LOWER(u.full_name) LIKE LOWER(?)
        LIMIT 3
    ";
    $stmt_doc = $conn->prepare($sql_doctors);
    if ($stmt_doc) {
        $stmt_doc->bind_param("s", $like_term);
        $stmt_doc->execute();
        $doctors_result = $stmt_doc->get_result();
        while ($row = $doctors_result->fetch_assoc()) {
            // Add .php to the URL
            $results['doctors'][] = ['name' => $row['full_name'], 'url' => 'doctor_profile.php?id=' . $row['id']];
        }
        $stmt_doc->close();
    }

    // --- Search for Hospitals (Case-Insensitive) ---
    $sql_hospitals = "
        SELECT name, id 
        FROM vw_active_hospitals 
        WHERE status = 'Active' AND LOWER(name) LIKE LOWER(?)
        LIMIT 3
    ";
    $stmt_hosp = $conn->prepare($sql_hospitals);
    if ($stmt_hosp) {
        $stmt_hosp->bind_param("s", $like_term);
        $stmt_hosp->execute();
        $hospitals_result = $stmt_hosp->get_result();
        while ($row = $hospitals_result->fetch_assoc()) {
            // Add .php to the URL
            $results['hospitals'][] = ['name' => $row['name'], 'url' => 'hospital_profile.php?id=' . $row['id']]; 
        }
        $stmt_hosp->close();
    }
}

echo json_encode($results);
$conn->close();
?>
