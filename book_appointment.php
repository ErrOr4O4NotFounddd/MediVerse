<?php
// Includes
include_once('includes/db_config.php');
include_once('includes/ActivityLog.php');
include_once('includes/header.php');

// --- User Authentication ---
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?redirect_url=" . urlencode($_SERVER['REQUEST_URI']));
    exit();
}
$logged_in_patient_id = $_SESSION['user_id']; 

if (!isset($_GET['schedule_id']) || empty($_GET['schedule_id'])) {
    echo "<p class='container'>Invalid Schedule ID.</p>"; include_once('includes/footer.php'); exit();
}
$schedule_id = (int)$_GET['schedule_id'];

// --- Fetch Schedule and Doctor Details ---
$sql_schedule = "SELECT ds.id, ds.doctor_id, ds.branch_id, ds.day_of_week, ds.consultation_fee, u.full_name, s.name_bn as specialization_bn, h.name as hospital_name, hb.address FROM doctor_schedules ds JOIN doctors d ON ds.doctor_id = d.id JOIN users u ON d.user_id = u.id JOIN specializations s ON d.specialization_id = s.id JOIN hospital_branches hb ON ds.branch_id = hb.id JOIN hospitals h ON hb.hospital_id = h.id WHERE ds.id = ? AND ds.deleted_at IS NULL AND d.deleted_at IS NULL AND u.deleted_at IS NULL";
$stmt = $conn->prepare($sql_schedule);
$stmt->bind_param("i", $schedule_id);
$stmt->execute();
$schedule_details = $stmt->get_result()->fetch_assoc();

if (!$schedule_details) {
    echo "<p class='container'>Schedule not found.</p>"; include_once('includes/footer.php'); exit();
}
$doctor_id = $schedule_details['doctor_id'];
$branch_id = $schedule_details['branch_id'];

$day_of_week_map = ['Sunday'=>0, 'Monday'=>1, 'Tuesday'=>2, 'Wednesday'=>3, 'Thursday'=>4, 'Friday'=>5, 'Saturday'=>6];
$day_index = $day_of_week_map[$schedule_details['day_of_week']];

// --- Handle Form Submission ---
$error_message = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $appointment_date = $_POST['appointment_date'];

    $date_obj = DateTime::createFromFormat('Y-m-d', $appointment_date);
    if (!$date_obj || $date_obj->format('Y-m-d') !== $appointment_date) {
        $error_message = 'অবৈধ তারিখ ফরম্যাট। অনুগ্রহ করে সঠিক তারিখ দিন।';
    } else {
        // Check if patient already has a booking with this doctor on the same date
        $stmt_check = $conn->prepare("SELECT b.id FROM vw_appointments b JOIN doctor_schedules ds ON b.doctor_schedule_id = ds.id WHERE b.patient_id = ? AND ds.doctor_id = ? AND b.appointment_date = ? AND b.status NOT IN ('Cancelled', 'Rejected')");
        $stmt_check->bind_param("iis", $logged_in_patient_id, $doctor_id, $appointment_date);
        $stmt_check->execute();
        
        if ($stmt_check->get_result()->num_rows > 0) {
            $error_message = "আপনি ইতিমধ্যে এই ডাক্তারের কাছে {$appointment_date} তারিখে একটি অ্যাপয়েন্টমেন্ট বুক করেছেন।";
        } else {
            // Count existing bookings for this schedule on this date
            $stmt_count = $conn->prepare("SELECT COUNT(id) as total_booked FROM vw_appointments WHERE doctor_schedule_id = ? AND appointment_date = ?");
            $stmt_count->bind_param("is", $schedule_id, $appointment_date);
            $stmt_count->execute();
            $next_serial = $stmt_count->get_result()->fetch_assoc()['total_booked'] + 1;

            // Insert into unified bookings table
            $sql_insert = "INSERT INTO bookings (user_id, branch_id, booking_type, status, doctor_schedule_id, appointment_date, serial_number, consultation_type, final_cost, created_at) VALUES (?, ?, 'doctor', 'Scheduled', ?, ?, ?, 'In-Person', ?, NOW())";
            $stmt_insert = $conn->prepare($sql_insert);
            $stmt_insert->bind_param("iiisid", $logged_in_patient_id, $branch_id, $schedule_id, $appointment_date, $next_serial, $schedule_details['consultation_fee']);
            
            if ($stmt_insert->execute()) {
                $appointment_id = $stmt_insert->insert_id;                
                // Log appointment booking
                $activityLog = new ActivityLog($conn, $logged_in_patient_id);
                $activityLog->appointmentBooked($appointment_id, $logged_in_patient_id, $schedule_details['doctor_id'], "Appointment booked with Dr. {$schedule_details['full_name']} on $appointment_date", [
                    'doctor_id' => $schedule_details['doctor_id'],
                    'doctor_name' => $schedule_details['full_name'],
                    'appointment_date' => $appointment_date,
                    'serial_number' => $next_serial,
                    'consultation_fee' => $schedule_details['consultation_fee']
                ]);
                $message = "Dr. {$schedule_details['full_name']} এর সাথে আপনার অ্যাপয়েন্টমেন্টটি {$appointment_date} তারিখে সফলভাবে বুক করা হয়েছে। আপনার সিরিয়াল নম্বর: {$next_serial}।";
                $stmt_notify = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                $stmt_notify->bind_param("is", $logged_in_patient_id, $message);
                $stmt_notify->execute();
                
                header("Location: appointment_success.php?appointment_id=" . $appointment_id);
                exit();
            } else {
                $error_message = "অ্যাপয়েন্টমেন্ট বুকিং ব্যর্থ হয়েছে।";
            }
        }
    }
}
?>

<style>
/* Booking Page Styles */
.booking-page {
    background: linear-gradient(135deg, #f0f4f8 0%, #e8ecf1 100%);
    min-height: 100vh;
    padding: 40px 20px;
}

.booking-container {
    max-width: 600px;
    margin: 0 auto;
}

/* Page Header */
.booking-header {
    text-align: center;
    margin-bottom: 30px;
}

.booking-header h1 {
    font-size: 32px;
    font-weight: 700;
    color: #1a1a2e;
    margin: 0 0 10px 0;
}

.booking-header p {
    color: #718096;
    margin: 0;
}

/* Doctor Info Card */
.doctor-info-card {
    background: #fff;
    border-radius: 24px;
    padding: 30px;
    margin-bottom: 25px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
    position: relative;
    overflow: hidden;
}

.doctor-info-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 6px;
    background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
}

.doctor-avatar-section {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 25px;
    padding-bottom: 25px;
    border-bottom: 1px solid #e2e8f0;
}

.doctor-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    overflow: hidden;
    border: 4px solid #667eea;
    flex-shrink: 0;
}

.doctor-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.doctor-info h2 {
    font-size: 22px;
    font-weight: 700;
    color: #1a1a2e;
    margin: 0 0 6px 0;
}

.doctor-specialty {
    font-size: 15px;
    color: #667eea;
    font-weight: 600;
    margin: 0;
}

.hospital-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 20px;
}

/* Info Grid */
.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.info-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 15px;
    background: #f8fafc;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
}

.info-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

.info-icon.hospital { background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%); }
.info-icon.day { background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%); }
.info-icon.fee { background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); }
.info-icon.calendar { background: linear-gradient(135deg, #fce4ec 0%, #f8bbd9 100%); }

.info-content {
    flex: 1;
}

.info-label {
    font-size: 11px;
    color: #718096;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}

.info-value {
    font-size: 14px;
    font-weight: 700;
    color: #1a1a2e;
}

.info-value.highlight {
    color: #27ae60;
    font-size: 16px;
}

/* Date Selection Card */
.date-selection-card {
    background: #fff;
    border-radius: 24px;
    padding: 30px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
}

.date-selection-card h2 {
    font-size: 22px;
    font-weight: 700;
    color: #1a1a2e;
    margin: 0 0 25px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.date-selection-card h2::before {
    content: '';
    width: 4px;
    height: 24px;
    background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
    border-radius: 2px;
}

/* Week Days Display */
.week-days-section {
    margin-bottom: 25px;
}

.week-days-title {
    font-size: 14px;
    font-weight: 600;
    color: #4a5568;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.week-days-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 10px;
}

.day-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 12px 8px;
    border-radius: 12px;
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    cursor: pointer;
    transition: all 0.3s ease;
}

.day-item:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
}

.day-item.selected {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-color: #667eea;
    color: #fff;
}

.day-item .day-name {
    font-size: 11px;
    font-weight: 600;
    color: #718096;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.day-item.selected .day-name {
    color: rgba(255, 255, 255, 0.9);
}

.day-item .day-bn {
    font-size: 13px;
    font-weight: 700;
    color: #1a1a2e;
    margin-top: 4px;
}

.day-item.selected .day-bn {
    color: #fff;
}

/* Calendar Input Section */
.calendar-section {
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-weight: 600;
    color: #4a5568;
    margin-bottom: 10px;
    font-size: 14px;
}

.calendar-input-wrapper {
    position: relative;
}

.calendar-input-wrapper::before {
    
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 18px;
    pointer-events: none;
    z-index: 1;
}

.form-control {
    width: 100%;
    padding: 16px 50px 16px 48px;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    font-size: 16px;
    font-weight: 600;
    transition: all 0.3s ease;
    background: #fff;
    box-sizing: border-box;
    color: #1a1a2e;
    cursor: pointer;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
}

.form-control::-webkit-calendar-picker-indicator {
    cursor: pointer;
    opacity: 0.6;
}

.form-control::-webkit-calendar-picker-indicator:hover {
    opacity: 1;
}

/* Info Box */
.info-box {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 16px 20px;
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border: 1px solid #fbbf24;
    border-radius: 14px;
    margin-bottom: 25px;
}

.info-box-icon {
    font-size: 24px;
    flex-shrink: 0;
}

.info-box-content {
    flex: 1;
}

.info-box-title {
    font-weight: 700;
    color: #92400e;
    margin-bottom: 4px;
    font-size: 14px;
}

.info-box-text {
    font-size: 13px;
    color: #a16207;
    line-height: 1.5;
}

/* Alert Messages */
.alert {
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.alert-error {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
    border: 1px solid #f87171;
}

/* Submit Button */
.btn-submit {
    width: 100%;
    padding: 18px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    border: none;
    border-radius: 14px;
    font-size: 17px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    letter-spacing: 0.5px;
    position: relative;
    overflow: hidden;
}

.btn-submit::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left 0.5s ease;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
}

.btn-submit:hover::before {
    left: 100%;
}

/* Responsive */
@media (max-width: 600px) {
    .booking-header h1 {
        font-size: 26px;
    }
    
    .doctor-avatar-section {
        flex-direction: column;
        text-align: center;
    }
    
    .info-grid {
        grid-template-columns: 1fr;
    }
    
    .week-days-grid {
        grid-template-columns: repeat(7, 1fr);
        gap: 6px;
    }
    
    .day-item {
        padding: 10px 4px;
    }
    
    .day-item .day-bn {
        font-size: 11px;
    }
}
</style>

<div class="booking-page">
    <div class="booking-container">
        <!-- Page Header -->
        <div class="booking-header">
            <h1>📅 অ্যাপয়েন্টমেন্ট বুক করুন</h1>
            <p>আপনার সুবিধার জন্য নিচের তথ্য পূরণ করুন</p>
        </div>

        <!-- Doctor Info Card -->
        <div class="doctor-info-card">
            <div class="doctor-avatar-section">
                <div class="doctor-avatar">
                    <img src="https://i.ibb.co/L1b1sDS/default-doctor-avatar.png" alt="<?= htmlspecialchars($schedule_details['full_name']) ?>">
                </div>
                <div class="doctor-info">
                    <h2>Dr. <?= htmlspecialchars($schedule_details['full_name']) ?></h2>
                    <p class="doctor-specialty"><?= htmlspecialchars($schedule_details['specialization_bn']) ?></p>
                </div>
            </div>

            <div class="hospital-badge">
                🏥 <?= htmlspecialchars($schedule_details['hospital_name']) ?>
            </div>

            <div class="info-grid">
                <div class="info-item">
                    <div class="info-icon hospital">📍</div>
                    <div class="info-content">
                        <div class="info-label">ঠিকানা</div>
                        <div class="info-value"><?= htmlspecialchars($schedule_details['address']) ?></div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon day">🗓️</div>
                    <div class="info-content">
                        <div class="info-label">চেম্বারের দিন</div>
                        <div class="info-value"><?= htmlspecialchars($schedule_details['day_of_week']) ?></div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon fee">💰</div>
                    <div class="info-content">
                        <div class="info-label">ডাক্তার ফি</div>
                        <div class="info-value highlight">৳<?= number_format($schedule_details['consultation_fee'], 0) ?></div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon calendar">📋</div>
                    <div class="info-content">
                        <div class="info-label">বুকিং টাইপ</div>
                        <div class="info-value">অনলাইন</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Date Selection Card -->
        <div class="date-selection-card">
            <h2>📆 তারিখ নির্বাচন করুন</h2>
            
            <!-- Week Days Display -->
            <div class="week-days-section">
                <div class="week-days-title">সপ্তাহের দিনসমূহ</div>
                <div class="week-days-grid">
                    <?php
                    $days_bn = ['Sun' => 'রবি', 'Mon' => 'সোম', 'Tue' => 'মঙ্গল', 'Wed' => 'বুধ', 'Thu' => 'বৃহ', 'Fri' => 'শুক্র', 'Sat' => 'শনি'];
                    $days_en = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                    $target_day = $schedule_details['day_of_week'];
                    
                    foreach ($days_en as $index => $day) {
                        $is_selected = ($day === $target_day);
                        $day_short = substr($day, 0, 3);
                        echo '<div class="day-item' . ($is_selected ? ' selected' : '') . '" data-day="' . $day . '">';
                        echo '<span class="day-name">' . $day_short . '</span>';
                        echo '<span class="day-bn">' . $days_bn[$day_short] . '</span>';
                        echo '</div>';
                    }
                    ?>
                </div>
            </div>

            <!-- Info Box -->
            <div class="info-box">
                <div class="info-box-icon">💡</div>
                <div class="info-box-content">
                    <div class="info-box-title">গুরুত্বপূর্ণ তথ্য</div>
                    <div class="info-box-text">
                        অনুগ্রহ করে শুধুমাত্র <strong><?= htmlspecialchars($schedule_details['day_of_week']) ?></strong> দিনে অ্যাপয়েন্টমেন্ট বুক করুন। অন্য কোনো দিনে বুকিং সম্ভব হবে না।
                    </div>
                </div>
            </div>

            <!-- Calendar Input -->
            <form action="book_appointment.php?schedule_id=<?= $schedule_id ?>" method="POST" id="booking-form">
                <div class="calendar-section">
                    <div class="form-group">
                        <label for="appointment_date">অ্যাপয়েন্টমেন্টের তারিখ নির্বাচন করুন</label>
                        <div class="calendar-input-wrapper">
                            <input type="date" id="appointment_date" name="appointment_date" class="form-control" required>
                        </div>
                    </div>
                </div>

                <?php if($error_message): ?>
                    <div class="alert alert-error">
                        ⚠️ <?= htmlspecialchars($error_message) ?>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn-submit">
                    ✅ অ্যাপয়েন্টমেন্ট কনফার্ম করুন
                </button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const datePicker = document.getElementById('appointment_date');
    const form = document.getElementById('booking-form');
    const validDayIndex = <?= $day_index ?>;
    const validDayName = "<?= htmlspecialchars($schedule_details['day_of_week']) ?>";
    const dayItems = document.querySelectorAll('.day-item');
    
    // Highlight the valid day
    dayItems.forEach(item => {
        if (item.classList.contains('selected')) {
            item.style.transform = 'scale(1.05)';
        }
    });
    
    // Set minimum date to today
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    datePicker.setAttribute('min', `${year}-${month}-${day}`);
    
    form.addEventListener('submit', function(event) {
        if (!datePicker.value) {
            alert('অনুগ্রহ করে একটি তারিখ নির্বাচন করুন।');
            event.preventDefault();
            return;
        }
        
        const selectedDate = new Date(datePicker.value + 'T00:00:00');
        if (selectedDate.getDay() !== validDayIndex) {
            alert(`অনুগ্রহ করে একটি বৈধ ${validDayName} নির্বাচন করুন।`);
            event.preventDefault();
            return;
        }
    });
    
    // Visual feedback when date is selected
    datePicker.addEventListener('change', function() {
        if (this.value) {
            const selectedDate = new Date(this.value + 'T00:00:00');
            const dayNames = ['রবিবার', 'সোমবার', 'মঙ্গলবার', 'বুধবার', 'বৃহস্পতিবার', 'শুক্রবার', 'শনিবার'];
            console.log(`Selected: ${dayNames[selectedDate.getDay()]}`);
        }
    });
});
</script>

<?php
$stmt->close();
$conn->close();
include_once('includes/footer.php');
?>
