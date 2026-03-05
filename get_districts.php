<?php
include 'includes/db_config.php';

if (isset($_GET['division_id'])) {
    $division_id = intval($_GET['division_id']);
    $sql = "SELECT id, name FROM districts WHERE division_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $division_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo '<option value="">Select District</option>';
    while ($row = $result->fetch_assoc()) {
        echo '<option value="' . $row['id'] . '">' . $row['name'] . '</option>';
    }
}
?>
