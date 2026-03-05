<?php
include_once('includes/db_config.php');
include_once('includes/header.php');

if (!isset($_SESSION['user_id']) || !isset($_GET['appointment_id'])) {
    header("Location: login.php");
    exit();
}
$user_id = $_SESSION['user_id'];
$appointment_id = (int)$_GET['appointment_id'];

$sql = "SELECT b.appointment_date, b.serial_number, u.full_name AS doctor_name FROM bookings b JOIN doctor_schedules ds ON b.doctor_schedule_id = ds.id JOIN doctors d ON ds.doctor_id = d.id JOIN users u ON d.user_id = u.id WHERE b.id = ? AND b.user_id = ? AND b.booking_type = 'doctor' AND b.deleted_at IS NULL";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $appointment_id, $user_id);
$stmt->execute();
$details = $stmt->get_result()->fetch_assoc();

if (!$details) {
    echo "<p class='container'>Appointment not found.</p>";
    include_once('includes/footer.php');
    exit();
}
?>

<main>
    <div class="success-container">
        <div class="success-icon">✅</div>
        <h1>অ্যাপয়েন্টমেন্ট সফল হয়েছে!</h1>
        <p>আপনার অ্যাপয়েন্টমেন্টের বিবরণ নিচে দেওয়া হলো:</p>
        <div class="success-details">
            <p><strong>ডাক্তার:</strong> <?= htmlspecialchars($details['doctor_name']) ?></p>
            <p><strong>তারিখ:</strong> <?= htmlspecialchars(date("d F, Y", strtotime($details['appointment_date']))) ?></p>
            <p><strong>সিরিয়াল নম্বর:</strong> <?= htmlspecialchars($details['serial_number']) ?></p>
        </div>
        <a href="index.php" class="btn">হোম পেজ এ ফিরে যান</a>
    </div>
</main>

<?php
$conn->close();
include_once('includes/footer.php');
?>
