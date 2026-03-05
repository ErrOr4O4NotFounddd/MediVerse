<?php
session_start();
include_once('includes/db_config.php');
include_once('includes/header.php');

// Check login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Get user info
$stmt = $conn->prepare("SELECT full_name, phone, email FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Tab selection
$tab = $_GET['tab'] ?? 'appointments';

// Appointments
$stmt = $conn->prepare("SELECT * FROM vw_patient_appointments WHERE user_id = ? ORDER BY appointment_date DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Prescriptions
$stmt = $conn->prepare("SELECT * FROM vw_patient_prescriptions WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$prescriptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Lab Tests
$stmt = $conn->prepare("SELECT * FROM vw_patient_lab_tests WHERE user_id = ? ORDER BY appointment_date DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$lab_tests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Bed Bookings
$stmt = $conn->prepare("SELECT * FROM vw_patient_bed_bookings WHERE user_id = ? ORDER BY admission_date DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$bed_bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Ambulance Bookings
$stmt = $conn->prepare("SELECT * FROM vw_patient_ambulance_bookings WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$ambulance_bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate statistics
$total_appointments = count($appointments);
$completed_appointments = count(array_filter($appointments, fn($a) => $a['status'] === 'Completed'));
$total_prescriptions = count($prescriptions);
$total_tests = count($lab_tests);
?>

<style>
/* ===================================
   Medical Records Page — Premium
   =================================== */
.records-page {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

/* --- Hero Banner --- */
.records-hero {
    background: linear-gradient(135deg, #1a5f4a 0%, #0d3d2e 50%, #0a2d22 100%);
    border-radius: 20px;
    padding: 45px 40px;
    color: white;
    position: relative;
    overflow: hidden;
    margin: 30px 0;
    box-shadow: 0 15px 40px rgba(26, 95, 74, 0.25);
}

.records-hero::before {
    content: '';
    position: absolute;
    top: -40%;
    right: -15%;
    width: 450px;
    height: 450px;
    border-radius: 50%;
    background: rgba(255,255,255,0.04);
    pointer-events: none;
}

.records-hero::after {
    content: '';
    position: absolute;
    bottom: -35%;
    left: -8%;
    width: 350px;
    height: 350px;
    border-radius: 50%;
    background: rgba(255,255,255,0.03);
    pointer-events: none;
}

.records-hero-inner {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 20px;
}

.records-hero-text h1 {
    font-size: 30px;
    font-weight: 700;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.records-hero-text h1 span { font-size: 34px; }

.records-hero-text p {
    font-size: 15px;
    opacity: 0.85;
    margin: 0;
}

.records-hero-user {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(255,255,255,0.1);
    backdrop-filter: blur(10px);
    padding: 12px 20px;
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,0.15);
}

.records-hero-user i {
    font-size: 24px;
    opacity: 0.8;
}

.records-hero-user .user-name {
    font-weight: 700;
    font-size: 16px;
}

.records-hero-user .user-email {
    font-size: 13px;
    opacity: 0.75;
}

/* --- Stats Grid --- */
.records-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
    margin-bottom: 28px;
}

.rec-stat-card {
    background: white;
    padding: 22px 20px;
    border-radius: 16px;
    text-align: center;
    box-shadow: 0 4px 18px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
    border: 1px solid #f0f2f5;
    position: relative;
    overflow: hidden;
}

.rec-stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
}

.rec-stat-card.stat-appointments::before { background: linear-gradient(90deg, #1a5f4a, #27ae60); }
.rec-stat-card.stat-completed::before { background: linear-gradient(90deg, #27ae60, #2ecc71); }
.rec-stat-card.stat-prescriptions::before { background: linear-gradient(90deg, #8e44ad, #9b59b6); }
.rec-stat-card.stat-tests::before { background: linear-gradient(90deg, #e67e22, #f39c12); }

.rec-stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.rec-stat-icon {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 14px;
    font-size: 22px;
    color: white;
}

.stat-appointments .rec-stat-icon { background: linear-gradient(135deg, #1a5f4a, #27ae60); }
.stat-completed .rec-stat-icon { background: linear-gradient(135deg, #27ae60, #2ecc71); }
.stat-prescriptions .rec-stat-icon { background: linear-gradient(135deg, #8e44ad, #9b59b6); }
.stat-tests .rec-stat-icon { background: linear-gradient(135deg, #e67e22, #f39c12); }

.rec-stat-num {
    font-size: 32px;
    font-weight: 800;
    line-height: 1;
    margin-bottom: 5px;
}

.stat-appointments .rec-stat-num { color: #1a5f4a; }
.stat-completed .rec-stat-num { color: #27ae60; }
.stat-prescriptions .rec-stat-num { color: #8e44ad; }
.stat-tests .rec-stat-num { color: #e67e22; }

.rec-stat-label {
    font-size: 13px;
    color: #888;
    font-weight: 500;
}

/* --- Tab Navigation --- */
.records-tabs-wrap {
    background: white;
    border-radius: 18px;
    box-shadow: 0 6px 25px rgba(0,0,0,0.06);
    overflow: hidden;
    margin-bottom: 40px;
    border: 1px solid #f0f2f5;
}

.records-tab-nav {
    display: flex;
    border-bottom: 2px solid #f0f2f5;
    overflow-x: auto;
    background: linear-gradient(to bottom, #fafbfc, #fff);
    padding: 0;
}

.rec-tab-link {
    padding: 18px 24px;
    text-decoration: none;
    color: #8a9a96;
    white-space: nowrap;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    transition: all 0.3s ease;
    position: relative;
    border: none;
    background: none;
    font-family: 'Hind Siliguri', sans-serif;
    cursor: pointer;
}

.rec-tab-link:hover {
    color: #1a5f4a;
    background: linear-gradient(to bottom, #f0f5f3, transparent);
}

.rec-tab-link.active {
    color: #1a5f4a;
    font-weight: 700;
}

.rec-tab-link::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 15%;
    right: 15%;
    height: 3px;
    background: #1a5f4a;
    border-radius: 3px 3px 0 0;
    transform: scaleX(0);
    transition: transform 0.3s ease;
}

.rec-tab-link:hover::after {
    transform: scaleX(0.6);
}

.rec-tab-link.active::after {
    transform: scaleX(1);
}

.rec-tab-link .tab-count {
    background: #edf2f0;
    color: #5a7a72;
    font-size: 12px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 10px;
    min-width: 22px;
    text-align: center;
}

.rec-tab-link.active .tab-count {
    background: #1a5f4a;
    color: white;
}

/* --- Tab Content --- */
.records-tab-body {
    padding: 28px;
}

/* --- Record Cards --- */
.rec-item {
    background: #f8f9fa;
    padding: 20px 22px;
    border-radius: 14px;
    margin-bottom: 14px;
    transition: all 0.3s ease;
    border: 1px solid #f0f2f5;
    position: relative;
}

.rec-item:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.08);
    border-color: #e0e5e2;
}

.rec-item-border { border-left: 4px solid; }

.rec-item-inner {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 15px;
}

.rec-item-info h4 {
    color: #333;
    margin: 0 0 5px 0;
    font-size: 16px;
}

.rec-item-info .rec-sub {
    color: #666;
    font-size: 14px;
    margin: 0 0 4px 0;
}

.rec-item-info .rec-location {
    color: #888;
    font-size: 13px;
    margin: 5px 0 0 0;
    display: flex;
    align-items: center;
    gap: 5px;
}

.rec-item-info .rec-price {
    color: #27ae60;
    font-weight: 700;
    margin-top: 5px;
    font-size: 15px;
}

.rec-item-meta {
    text-align: right;
    flex-shrink: 0;
}

.rec-item-meta .rec-date {
    font-weight: 600;
    color: #333;
    font-size: 14px;
    margin-bottom: 4px;
}

.rec-item-meta .rec-serial {
    color: #666;
    font-size: 13px;
}

.rec-status-badge {
    display: inline-block;
    margin-top: 8px;
    padding: 4px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.rec-action-btn {
    display: inline-block;
    margin-top: 10px;
    padding: 8px 18px;
    border-radius: 8px;
    color: white;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.rec-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

/* --- Empty State --- */
.rec-empty {
    text-align: center;
    padding: 50px 20px;
}

.rec-empty i {
    font-size: 60px;
    color: #ddd;
    margin-bottom: 18px;
    display: block;
}

.rec-empty p {
    color: #888;
    font-size: 16px;
    margin: 0 0 18px 0;
}

.rec-empty-btn {
    display: inline-block;
    padding: 12px 28px;
    background: linear-gradient(135deg, #1a5f4a, #27ae60);
    color: white;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(26,95,74,0.2);
}

.rec-empty-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(26,95,74,0.3);
}

.rec-empty-btn.btn-red {
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    box-shadow: 0 4px 15px rgba(231,76,60,0.2);
}

/* --- Responsive --- */
@media (max-width: 900px) {
    .records-stats { grid-template-columns: repeat(2, 1fr); }
    .records-hero { padding: 30px 25px; }
    .records-hero-text h1 { font-size: 24px; }
}

@media (max-width: 600px) {
    .records-stats { grid-template-columns: 1fr 1fr; gap: 12px; }
    .records-hero-inner { flex-direction: column; text-align: center; }
    .records-hero-user { justify-content: center; }
    .rec-tab-link { padding: 14px 16px; font-size: 13px; }
    .records-tab-body { padding: 20px; }
    .rec-stat-card { padding: 18px 14px; }
    .rec-stat-num { font-size: 26px; }
}
</style>

<main>
    <div class="records-page">

        <!-- Hero Banner -->
        <div class="records-hero">
            <div class="records-hero-inner">
                <div class="records-hero-text">
                    <h1><span>📋</span> আপনার মেডিকেল রেকর্ড</h1>
                    <p>আপনার সমস্ত চিকিৎসা সংক্রান্ত তথ্য এক জায়গায় — অ্যাপয়েন্টমেন্ট, প্রেসক্রিপশন, ল্যাব টেস্ট এবং আরও অনেক কিছু।</p>
                </div>
                <div class="records-hero-user">
                    <i class="fas fa-user-circle"></i>
                    <div>
                        <div class="user-name"><?= htmlspecialchars($user['full_name']) ?></div>
                        <div class="user-email"><?= htmlspecialchars($user['email']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="records-stats">
            <div class="rec-stat-card stat-appointments">
                <div class="rec-stat-icon"><i class="fas fa-calendar-check"></i></div>
                <div class="rec-stat-num"><?= $total_appointments ?></div>
                <div class="rec-stat-label">মোট অ্যাপয়েন্টমেন্ট</div>
            </div>
            <div class="rec-stat-card stat-completed">
                <div class="rec-stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="rec-stat-num"><?= $completed_appointments ?></div>
                <div class="rec-stat-label">সম্পন্ন</div>
            </div>
            <div class="rec-stat-card stat-prescriptions">
                <div class="rec-stat-icon"><i class="fas fa-prescription-bottle"></i></div>
                <div class="rec-stat-num"><?= $total_prescriptions ?></div>
                <div class="rec-stat-label">প্রেসক্রিপশন</div>
            </div>
            <div class="rec-stat-card stat-tests">
                <div class="rec-stat-icon"><i class="fas fa-flask"></i></div>
                <div class="rec-stat-num"><?= $total_tests ?></div>
                <div class="rec-stat-label">ল্যাব টেস্ট</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="records-tabs-wrap">
            <div class="records-tab-nav">
                <a href="?tab=appointments" class="rec-tab-link <?= $tab === 'appointments' ? 'active' : '' ?>">
                    <i class="fas fa-calendar-check"></i> অ্যাপয়েন্টমেন্ট <span class="tab-count"><?= $total_appointments ?></span>
                </a>
                <a href="?tab=prescriptions" class="rec-tab-link <?= $tab === 'prescriptions' ? 'active' : '' ?>">
                    <i class="fas fa-prescription-bottle"></i> প্রেসক্রিপশন <span class="tab-count"><?= $total_prescriptions ?></span>
                </a>
                <a href="?tab=lab_tests" class="rec-tab-link <?= $tab === 'lab_tests' ? 'active' : '' ?>">
                    <i class="fas fa-flask"></i> ল্যাব টেস্ট <span class="tab-count"><?= $total_tests ?></span>
                </a>
                <a href="?tab=beds" class="rec-tab-link <?= $tab === 'beds' ? 'active' : '' ?>">
                    <i class="fas fa-bed"></i> বেড বুকিং <span class="tab-count"><?= count($bed_bookings) ?></span>
                </a>
                <a href="?tab=ambulance" class="rec-tab-link <?= $tab === 'ambulance' ? 'active' : '' ?>">
                    <i class="fas fa-ambulance"></i> অ্যাম্বুলেন্স <span class="tab-count"><?= count($ambulance_bookings) ?></span>
                </a>
            </div>

            <div class="records-tab-body">

                <?php if ($tab === 'appointments'): ?>
                    <?php if (empty($appointments)): ?>
                        <div class="rec-empty">
                            <i class="fas fa-calendar-alt"></i>
                            <p>কোনো অ্যাপয়েন্টমেন্ট নেই</p>
                            <a href="doctors.php" class="rec-empty-btn">ডাক্তার খুঁজুন</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($appointments as $apt):
                            $sc = ['Pending'=>'#f39c12','Confirmed'=>'#3498db','Scheduled'=>'#3498db','Completed'=>'#27ae60','Cancelled'=>'#e74c3c'];
                            $color = $sc[$apt['status']] ?? '#666';
                        ?>
                        <div class="rec-item rec-item-border" style="border-left-color: <?= $color ?>;">
                            <div class="rec-item-inner">
                                <div class="rec-item-info">
                                    <h4>ডাঃ <?= htmlspecialchars($apt['doctor_name']) ?></h4>
                                    <p class="rec-sub"><?= htmlspecialchars($apt['specialization']) ?></p>
                                    <p class="rec-location"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($apt['hospital_name'] ?? '') ?> - <?= htmlspecialchars($apt['branch_name'] ?? '') ?></p>
                                </div>
                                <div class="rec-item-meta">
                                    <div class="rec-date"><?= $apt['appointment_date'] ? date('d M, Y', strtotime($apt['appointment_date'])) : 'N/A' ?></div>
                                    <div class="rec-serial">Serial: <?= htmlspecialchars($apt['serial_number'] ?? 'N/A') ?></div>
                                    <span class="rec-status-badge" style="background: <?= $color ?>18; color: <?= $color ?>;"><?= $apt['status'] ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($tab === 'prescriptions'): ?>
                    <?php if (empty($prescriptions)): ?>
                        <div class="rec-empty">
                            <i class="fas fa-prescription-bottle-alt"></i>
                            <p>কোনো প্রেসক্রিপশন নেই</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($prescriptions as $presc): ?>
                        <div class="rec-item">
                            <div class="rec-item-inner">
                                <div class="rec-item-info">
                                    <h4>প্রেসক্রিপশন #<?= $presc['id'] ?></h4>
                                    <p class="rec-sub">ডাঃ <?= htmlspecialchars($presc['doctor_name']) ?> (<?= htmlspecialchars($presc['specialization']) ?>)</p>
                                    <?php if ($presc['diagnosis']): ?>
                                        <p class="rec-location"><strong>রোগ নির্ণয়:</strong> <?= htmlspecialchars($presc['diagnosis']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="rec-item-meta">
                                    <div class="rec-date"><?= date('d M, Y', strtotime($presc['created_at'])) ?></div>
                                    <a href="view_prescription.php?id=<?= $presc['id'] ?>" class="rec-action-btn" style="background: #8e44ad;">
                                        <i class="fas fa-eye"></i> দেখুন
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($tab === 'lab_tests'): ?>
                    <?php if (empty($lab_tests)): ?>
                        <div class="rec-empty">
                            <i class="fas fa-vial"></i>
                            <p>কোনো ল্যাব টেস্ট নেই</p>
                            <a href="lab_tests.php" class="rec-empty-btn">টেস্ট বুক করুন</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($lab_tests as $test):
                            $tc = ['Pending'=>'#f39c12','In Progress'=>'#3498db','Report_Ready'=>'#27ae60','Completed'=>'#27ae60','Cancelled'=>'#e74c3c'];
                            $tcolor = $tc[$test['status']] ?? '#666';
                        ?>
                        <div class="rec-item rec-item-border" style="border-left-color: <?= $tcolor ?>;">
                            <div class="rec-item-inner">
                                <div class="rec-item-info">
                                    <h4><?= htmlspecialchars($test['test_name']) ?></h4>
                                    <p class="rec-location"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($test['hospital_name'] ?? 'N/A') ?> - <?= htmlspecialchars($test['branch_name'] ?? '') ?></p>
                                    <p class="rec-price">৳<?= number_format($test['price']) ?></p>
                                </div>
                                <div class="rec-item-meta">
                                    <div class="rec-date"><?= $test['appointment_date'] ? date('d M, Y', strtotime($test['appointment_date'])) : 'N/A' ?></div>
                                    <span class="rec-status-badge" style="background: <?= $tcolor ?>18; color: <?= $tcolor ?>;"><?= $test['status'] ?></span>
                                    <?php if ($test['status'] === 'Report_Ready' && $test['report_file_path']): ?>
                                        <a href="<?= htmlspecialchars($test['report_file_path']) ?>" target="_blank" class="rec-action-btn" style="background: #27ae60; display: block;">
                                            <i class="fas fa-download"></i> রেজাল্ট ডাউনলোড
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($tab === 'beds'): ?>
                    <?php if (empty($bed_bookings)): ?>
                        <div class="rec-empty">
                            <i class="fas fa-bed"></i>
                            <p>কোনো বেড বুকিং নেই</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($bed_bookings as $bed): ?>
                        <div class="rec-item">
                            <div class="rec-item-inner">
                                <div class="rec-item-info">
                                    <h4>বেড #<?= htmlspecialchars($bed['bed_number'] ?? 'N/A') ?></h4>
                                    <p class="rec-sub"><?= htmlspecialchars($bed['bed_type'] ?? 'N/A') ?></p>
                                    <?php if ($bed['final_cost']): ?>
                                        <p class="rec-price">মোট বিল: ৳<?= number_format($bed['final_cost']) ?></p>
                                    <?php endif; ?>
                                    <p class="rec-location"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($bed['hospital_name'] ?? '') ?> - <?= htmlspecialchars($bed['branch_name'] ?? '') ?></p>
                                </div>
                                <div class="rec-item-meta">
                                    <div class="rec-date"><strong>ভর্তি:</strong> <?= date('d M, Y', strtotime($bed['admission_date'])) ?></div>
                                    <?php if ($bed['release_date']): ?>
                                        <div class="rec-serial"><strong>ছাড়পত্র:</strong> <?= date('d M, Y', strtotime($bed['release_date'])) ?></div>
                                    <?php endif; ?>
                                    <span class="rec-status-badge" style="background: <?= $bed['status'] === 'Active' ? '#3498db' : '#27ae60' ?>18; color: <?= $bed['status'] === 'Active' ? '#3498db' : '#27ae60' ?>;">
                                        <?= $bed['status'] === 'Active' ? 'ভর্তি আছেন' : 'ছাড়পত্র পেয়েছেন' ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($tab === 'ambulance'): ?>
                    <?php if (empty($ambulance_bookings)): ?>
                        <div class="rec-empty">
                            <i class="fas fa-ambulance"></i>
                            <p>কোনো অ্যাম্বুলেন্স বুকিং নেই</p>
                            <a href="book_ambulance_v2.php" class="rec-empty-btn btn-red">অ্যাম্বুলেন্স বুক করুন</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($ambulance_bookings as $amb):
                            $ac = ['Pending'=>'#f39c12','Accepted'=>'#3498db','Picked Up'=>'#9b59b6','Completed'=>'#27ae60','Cancelled'=>'#e74c3c'];
                            $acolor = $ac[$amb['status']] ?? '#666';
                        ?>
                        <div class="rec-item rec-item-border" style="border-left-color: <?= $acolor ?>;">
                            <div class="rec-item-inner">
                                <div class="rec-item-info">
                                    <h4>
                                        <?= htmlspecialchars($amb['ambulance_type'] ?? 'অ্যাম্বুলেন্স') ?>
                                        <?php if ($amb['vehicle_number']): ?>
                                            <span style="color:#666;font-weight:normal;font-size:14px;">(<?= htmlspecialchars($amb['vehicle_number']) ?>)</span>
                                        <?php endif; ?>
                                    </h4>
                                    <p class="rec-location"><i class="fas fa-map-pin"></i> <?= htmlspecialchars($amb['pickup_location'] ?? 'N/A') ?></p>
                                    <p class="rec-sub" style="margin:3px 0 0;"><i class="fas fa-arrow-right" style="color:#aaa;margin-right:4px;font-size:12px;"></i> <?= htmlspecialchars($amb['destination'] ?? 'N/A') ?></p>
                                    <?php if ($amb['driver_name']): ?>
                                        <p class="rec-sub" style="color:#1a5f4a;margin-top:5px;"><i class="fas fa-car" style="margin-right:4px;"></i> ড্রাইভার: <?= htmlspecialchars($amb['driver_name']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="rec-item-meta">
                                    <div class="rec-date"><?= date('d M, Y', strtotime($amb['created_at'])) ?></div>
                                    <div class="rec-serial"><?= date('h:i A', strtotime($amb['created_at'])) ?></div>
                                    <?php if ($amb['estimated_fare']): ?>
                                        <p class="rec-price" style="margin:5px 0 0;text-align:right;">৳<?= number_format($amb['estimated_fare']) ?></p>
                                    <?php endif; ?>
                                    <span class="rec-status-badge" style="background: <?= $acolor ?>18; color: <?= $acolor ?>;"><?= $amb['status'] ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>

            </div>
        </div>

    </div>
</main>

<?php include_once('includes/footer.php'); ?>
