<?php
include 'includes/db_config.php';

if (isset($_GET['district_id'])) {
    $district_id = intval($_GET['district_id']);
    $sql = "SELECT id, name FROM upazilas WHERE district_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $district_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo '<option value="">Select Upazila</option>';
    while ($row = $result->fetch_assoc()) {
        echo '<option value="' . $row['id'] . '">' . $row['name'] . '</option>';
    }
}
?>
