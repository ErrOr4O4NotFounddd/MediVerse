<?php
define('BASE_PATH', __DIR__ . '/');
include_once(BASE_PATH . 'includes/db_config.php');
include_once(BASE_PATH . 'includes/header.php');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { 
    echo "<p class='container'>Invalid Hospital ID provided.</p>"; 
    include_once(BASE_PATH . 'includes/footer.php'); 
    exit();
}
$id = (int)$_GET['id'];

// Check if id is a branch_id or hospital_id
$stmt_check = $conn->prepare("SELECT id, hospital_id FROM hospital_branches WHERE id = ? AND status = 'Active'");
$stmt_check->bind_param("i", $id);
$stmt_check->execute();
$check_result = $stmt_check->get_result();
if ($check_result->num_rows > 0) {
    $branch_row = $check_result->fetch_assoc();
    $branch_id = $branch_row['id'];
    $hospital_id = $branch_row['hospital_id'];
} else {
    $hospital_id = $id;
    $stmt_main = $conn->prepare("SELECT id FROM hospital_branches WHERE hospital_id = ? AND is_main_branch = 1 AND status = 'Active' LIMIT 1");
    $stmt_main->bind_param("i", $hospital_id);
    $stmt_main->execute();
    $main_result = $stmt_main->get_result();
    if ($main_result->num_rows > 0) {
        $main_row = $main_result->fetch_assoc();
        $branch_id = $main_row['id'];
    } else {
        echo "<p class='container'>Hospital not found or is not active.</p>"; include_once(BASE_PATH . 'includes/footer.php'); exit();
    }
    $stmt_main->close();
}
$stmt_check->close();

// --- SQL to fetch hospital details ---
$sql_hospital = "SELECT v.hospital_name AS name, v.hospital_type, v.branch_id, v.address, v.hotline, v.avg_rating FROM v_hospital_details v WHERE v.branch_id = ?";
$stmt_hosp = $conn->prepare($sql_hospital); $stmt_hosp->bind_param("i", $branch_id); $stmt_hosp->execute();
$hospital = $stmt_hosp->get_result()->fetch_assoc();
if (!$hospital) { echo "<p class='container'>Hospital not found or is not active.</p>"; include_once(BASE_PATH . 'includes/footer.php'); exit(); }

// --- SQL to fetch doctors ---
$sql_doctors = "
    SELECT d.id, u.full_name, s.name_bn AS specialization, d.qualifications, u.profile_image,
    AVG(r.rating) AS avg_rating,
    COUNT(r.id) AS total_reviews
    FROM doctors d
    JOIN users u ON d.user_id = u.id
    JOIN specializations s ON d.specialization_id = s.id
    JOIN doctor_schedules ds ON d.id = ds.doctor_id AND ds.deleted_at IS NULL
    JOIN hospital_branches hb ON ds.branch_id = hb.id
    LEFT JOIN ratings r ON d.id = r.rateable_id AND r.rateable_type = 'Doctor'
    WHERE hb.id = ? AND d.is_verified = 'Verified' AND hb.status = 'Active'
    GROUP BY d.id
    LIMIT 12
";
$stmt_docs = $conn->prepare($sql_doctors); $stmt_docs->bind_param("i", $branch_id); $stmt_docs->execute();
$doctors_result = $stmt_docs->get_result();

// --- SQL to fetch specializations ---
$sql_specs = "SELECT DISTINCT s.id, s.name_bn FROM specializations s JOIN doctors d ON s.id = d.specialization_id JOIN doctor_schedules ds ON d.id = ds.doctor_id JOIN hospital_branches hb ON ds.branch_id = hb.id WHERE hb.id = ? AND d.is_verified = 'Verified' AND hb.status = 'Active' AND ds.deleted_at IS NULL ORDER BY s.name_bn ASC";
$stmt_specs = $conn->prepare($sql_specs); $stmt_specs->bind_param("i", $branch_id); $stmt_specs->execute();
$specializations = $stmt_specs->get_result();

// --- SQL to fetch reviews ---
$sql_reviews = "SELECT r.rating, r.comment, u.full_name AS reviewer_name, r.created_at FROM vw_active_ratings r LEFT JOIN users u ON r.user_id = u.id WHERE r.rateable_id = ? AND r.rateable_type = 'Branch' AND r.comment IS NOT NULL AND r.comment != '' ORDER BY r.created_at DESC LIMIT 10";
$stmt_reviews = $conn->prepare($sql_reviews); $stmt_reviews->bind_param("i", $hospital['branch_id']); $stmt_reviews->execute();
$reviews_result = $stmt_reviews->get_result();

// Statistics
$total_doctors = $doctors_result->num_rows;
$total_reviews_count = $reviews_result->num_rows;

// --- SQL to fetch branch availability ---
$sql_availability = "SELECT day_of_week, is_open, opening_time, closing_time, is_24_hours FROM branch_availability WHERE branch_id = ? ORDER BY FIELD(day_of_week, 'Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday')";
$stmt_av = $conn->prepare($sql_availability);
$stmt_av->bind_param("i", $branch_id);
$stmt_av->execute();
$availability_result = $stmt_av->get_result();
$availability = [];
while ($row = $availability_result->fetch_assoc()) {
    $availability[$row['day_of_week']] = $row;
}

// Logic to check if open now
$is_open_now = false;
$current_day = date('l');
$current_time = date('H:i:s');

if (isset($availability[$current_day])) {
    $today_av = $availability[$current_day];
    if ($today_av['is_24_hours']) {
        $is_open_now = true;
    } elseif ($today_av['is_open']) {
        if ($current_time >= $today_av['opening_time'] && $current_time <= $today_av['closing_time']) {
            $is_open_now = true;
        }
    }
}

// --- SQL to fetch branch services ---
$sql_services = "SELECT hs.service_name, hs.service_name_bn, hs.icon, bs.is_24x7, bs.notes 
                 FROM branch_services bs 
                 JOIN hospital_services hs ON bs.service_id = hs.id 
                 WHERE bs.branch_id = ?";
$stmt_services = $conn->prepare($sql_services);
$stmt_services->bind_param("i", $branch_id);
$stmt_services->execute();
$services_result = $stmt_services->get_result();
$branch_services = $services_result->fetch_all(MYSQLI_ASSOC);

// Determine type class and gradient
$is_govt = $hospital['hospital_type'] === 'Government';
$type_gradient = $is_govt 
    ? 'linear-gradient(135deg, #059669, #10b981, #34d399)' 
    : 'linear-gradient(135deg, #6366f1, #8b5cf6, #a78bfa)';
$type_color_main = $is_govt ? '#059669' : '#6366f1';
$type_color_light = $is_govt ? '#ecfdf5' : '#eef2ff';
$type_label = $is_govt ? '🏥 সরকারি হাসপাতাল' : '🏥 বেসরকারি হাসপাতাল';
?>

<style>
/* ==================== HOSPITAL PROFILE v2 — Premium Redesign ==================== */
@keyframes fadeInUp { from { opacity:0; transform:translateY(30px); } to { opacity:1; transform:translateY(0); } }
@keyframes fadeInScale { from { opacity:0; transform:scale(.92); } to { opacity:1; transform:scale(1); } }
@keyframes shimmer { 0% { background-position: -200% 0; } 100% { background-position: 200% 0; } }
@keyframes pulseLive { 0%,100% { transform:scale(1); box-shadow:0 0 0 0 rgba(16,185,129,.6);} 50% { transform:scale(1.15); box-shadow:0 0 0 8px rgba(16,185,129,0);} }
@keyframes float { 0%,100% { transform:translateY(0); } 50% { transform:translateY(-6px); } }
@keyframes countUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }

.hp-page { background: #f0f2f5; min-height:100vh; padding-bottom:60px; }

/* ===== HERO BANNER ===== */
.hp-hero {
    background: <?= $type_gradient ?>;
    position: relative;
    padding: 60px 0 100px;
    overflow: hidden;
}
.hp-hero::before {
    content: '';
    position: absolute; inset:0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
    opacity: .5;
}
.hp-hero::after {
    content: '';
    position: absolute; bottom:0; left:0; right:0;
    height: 80px;
    background: linear-gradient(to bottom, transparent, #f0f2f5);
}
.hp-hero .container { position:relative; z-index:2; }

.hp-hero-content {
    display: flex;
    align-items: center;
    gap: 35px;
    animation: fadeInUp .7s ease-out;
}

.hp-hero-icon {
    width: 110px; height: 110px;
    background: rgba(255,255,255,.2);
    backdrop-filter: blur(12px);
    border-radius: 28px;
    display: flex; align-items:center; justify-content:center;
    font-size: 52px;
    border: 2px solid rgba(255,255,255,.3);
    flex-shrink: 0;
    box-shadow: 0 8px 32px rgba(0,0,0,.12);
}

.hp-hero-info { flex:1; color: #fff; }

.hp-hero-badges { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px; }

.hp-type-badge {
    display: inline-flex; align-items:center; gap:6px;
    padding: 6px 16px;
    background: rgba(255,255,255,.2);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255,255,255,.3);
    border-radius: 50px;
    font-size: 13px; font-weight:600;
    color: #fff;
    letter-spacing: .3px;
}

.hp-live-badge {
    display: inline-flex; align-items:center; gap:8px;
    padding: 6px 16px;
    border-radius: 50px;
    font-size: 13px; font-weight:700;
}
.hp-live-badge.open { background:rgba(16,185,129,.25); border:1px solid rgba(16,185,129,.5); color:#d1fae5; }
.hp-live-badge.closed { background:rgba(239,68,68,.25); border:1px solid rgba(239,68,68,.5); color:#fecaca; }
.hp-live-dot { width:9px; height:9px; border-radius:50%; background:currentColor; animation:pulseLive 2s infinite; }

.hp-hero-name {
    font-size: 36px; font-weight:800; margin:0 0 10px;
    color: #fff;
    text-shadow: 0 2px 10px rgba(0,0,0,.15);
    line-height: 1.3;
}

.hp-hero-address {
    font-size: 16px; color:rgba(255,255,255,.85);
    display:flex; align-items:center; gap:8px;
    margin:0;
}

/* ===== STAT CARDS ===== */
.hp-stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-top: -60px;
    position: relative; z-index: 5;
    margin-bottom: 40px;
}

.hp-stat-card {
    background: #fff;
    border-radius: 18px;
    padding: 28px 24px;
    text-align: center;
    box-shadow: 0 8px 30px rgba(0,0,0,.08);
    transition: all .35s cubic-bezier(.25,.46,.45,.94);
    animation: fadeInScale .6s ease-out backwards;
    position: relative;
    overflow: hidden;
    cursor: default;
}
.hp-stat-card:nth-child(1) { animation-delay:.1s }
.hp-stat-card:nth-child(2) { animation-delay:.2s }
.hp-stat-card:nth-child(3) { animation-delay:.3s }
.hp-stat-card:nth-child(4) { animation-delay:.4s }
.hp-stat-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 16px 40px rgba(0,0,0,.12);
}
.hp-stat-card::before {
    content: '';
    position: absolute; top:0; left:0; right:0; height:4px;
    border-radius: 18px 18px 0 0;
}
.hp-stat-card:nth-child(1)::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
.hp-stat-card:nth-child(2)::before { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
.hp-stat-card:nth-child(3)::before { background: linear-gradient(90deg, #10b981, #34d399); }
.hp-stat-card:nth-child(4)::before { background: linear-gradient(90deg, #ef4444, #f87171); }

.hp-stat-icon {
    width: 52px; height:52px;
    border-radius: 14px;
    display: flex; align-items:center; justify-content:center;
    font-size: 24px;
    margin: 0 auto 14px;
}
.hp-stat-card:nth-child(1) .hp-stat-icon { background:#fffbeb;color:#f59e0b; }
.hp-stat-card:nth-child(2) .hp-stat-icon { background:#eff6ff;color:#3b82f6; }
.hp-stat-card:nth-child(3) .hp-stat-icon { background:#ecfdf5;color:#10b981; }
.hp-stat-card:nth-child(4) .hp-stat-icon { background:#fef2f2;color:#ef4444; }

.hp-stat-value {
    font-size: 30px; font-weight:800;
    color: #1e293b;
    margin-bottom: 4px;
    line-height:1;
}
.hp-stat-label {
    font-size: 13px; font-weight:500; color:#64748b;
    text-transform: uppercase; letter-spacing:.5px;
}

/* ===== CONTENT GRID ===== */
.hp-content-grid {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 30px;
    align-items: start;
}

/* ===== SECTION CARD (shared) ===== */
.hp-section-card {
    background: #fff;
    border-radius: 20px;
    padding: 30px;
    box-shadow: 0 4px 20px rgba(0,0,0,.06);
    margin-bottom: 30px;
    animation: fadeInUp .6s ease-out backwards;
}
.hp-section-title {
    display: flex; align-items:center; gap:12px;
    margin: 0 0 24px;
    font-size: 20px; font-weight:700; color:#1e293b;
    padding-bottom: 16px;
    border-bottom: 2px solid #f1f5f9;
}
.hp-section-title-icon {
    width:42px; height:42px;
    border-radius:12px;
    display:flex;align-items:center;justify-content:center;
    font-size:20px; color:#fff;
    flex-shrink:0;
}

/* ===== SCHEDULE ===== */
.hp-schedule-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 8px;
}

.hp-schedule-day {
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 14px;
    padding: 14px 6px;
    text-align: center;
    transition: all .3s ease;
    cursor: default;
}
.hp-schedule-day:hover { transform:translateY(-3px); box-shadow:0 6px 16px rgba(0,0,0,.07); }
.hp-schedule-day.today {
    background: <?= $type_gradient ?>;
    border-color: transparent;
    box-shadow: 0 6px 20px <?= $is_govt ? 'rgba(5,150,105,.35)' : 'rgba(99,102,241,.35)' ?>;
}
.hp-schedule-day.today .hp-sd-name,
.hp-schedule-day.today .hp-sd-time { color:#fff; }
.hp-schedule-day.today .hp-sd-dot { background:#6ee7b7; box-shadow:0 0 8px rgba(110,231,183,.7); }
.hp-schedule-day.off { opacity:.5; }

.hp-sd-name { font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.5px; margin-bottom:8px; }
.hp-sd-dot { width:8px; height:8px; border-radius:50%; margin:0 auto 8px; }
.hp-sd-dot.open { background:#10b981; box-shadow:0 0 5px rgba(16,185,129,.5); }
.hp-sd-dot.full { background:#3b82f6; box-shadow:0 0 5px rgba(59,130,246,.5); }
.hp-sd-dot.closed { background:#cbd5e1; }
.hp-sd-time { font-size:10px; font-weight:600; color:#64748b; line-height:1.5; }

/* ===== SERVICES ===== */
.hp-services-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.hp-service-item {
    display: flex; align-items:center; gap:14px;
    padding: 16px;
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 14px;
    transition: all .3s ease;
}
.hp-service-item:hover {
    background: <?= $type_color_light ?>;
    border-color: <?= $type_color_main ?>;
    transform: translateX(4px);
}
.hp-service-icon {
    width:44px; height:44px;
    background: <?= $type_color_light ?>;
    color: <?= $type_color_main ?>;
    border-radius:12px;
    display:flex;align-items:center;justify-content:center;
    font-size:18px;
    flex-shrink:0;
    transition: all .3s ease;
}
.hp-service-item:hover .hp-service-icon {
    background: <?= $type_color_main ?>;
    color: #fff;
}
.hp-service-name { font-size:14px; font-weight:600; color:#334155; margin:0; }
.hp-service-24x7 {
    display:inline-block;
    background: linear-gradient(135deg,#3b82f6,#60a5fa);
    color:#fff; font-size:10px; font-weight:700;
    padding:2px 8px; border-radius:6px; margin-left:6px;
}
.hp-service-note { font-size:11px; color:#94a3b8; margin:2px 0 0; }

/* ===== ACTION BUTTONS ===== */
.hp-action-btns {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.hp-action-btn {
    display: flex; align-items:center; gap:12px;
    padding: 16px 22px;
    border-radius: 14px;
    font-size: 15px; font-weight:700;
    text-decoration: none;
    transition: all .3s ease;
    border: none;
    cursor: pointer;
}
.hp-action-btn:hover { transform:translateY(-3px); }
.hp-action-btn.ambulance {
    background: linear-gradient(135deg,#ef4444,#dc2626);
    color:#fff;
    box-shadow: 0 8px 25px rgba(239,68,68,.3);
}
.hp-action-btn.ambulance:hover { box-shadow: 0 12px 35px rgba(239,68,68,.4); }
.hp-action-btn.call {
    background: linear-gradient(135deg,#10b981,#059669);
    color:#fff;
    box-shadow: 0 8px 25px rgba(16,185,129,.3);
}
.hp-action-btn.call:hover { box-shadow: 0 12px 35px rgba(16,185,129,.4); }

/* ===== SPECIALIZATIONS ===== */
.hp-spec-tags {
    display: flex; flex-wrap:wrap; gap:10px;
}
.hp-spec-tag {
    padding: 8px 18px;
    background: <?= $type_color_light ?>;
    color: <?= $type_color_main ?>;
    border: 1.5px solid transparent;
    border-radius: 50px;
    font-size: 13px; font-weight:600;
    transition: all .3s ease;
    cursor: default;
}
.hp-spec-tag:hover {
    background: <?= $type_color_main ?>;
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px <?= $is_govt ? 'rgba(5,150,105,.3)' : 'rgba(99,102,241,.3)' ?>;
}

/* ===== DOCTORS SECTION ===== */
.hp-doctors-section {
    margin-top: 10px;
}
.hp-section-header {
    text-align: center;
    margin-bottom: 35px;
}
.hp-section-header h2 {
    font-size: 28px; font-weight:800; color:#1e293b;
    margin: 0 0 8px;
}
.hp-section-header p { color:#64748b; margin:0; font-size:15px; }
.hp-section-header .hp-title-line {
    width:60px; height:4px;
    background: <?= $type_gradient ?>;
    border-radius:4px;
    margin:14px auto 0;
}

.hp-doctors-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
    gap: 24px;
}

.hp-doctor-card {
    background: #fff;
    border-radius: 18px;
    padding: 0;
    overflow: hidden;
    box-shadow: 0 5px 20px rgba(0,0,0,.06);
    transition: all .4s cubic-bezier(.25,.46,.45,.94);
    text-decoration: none;
    color: inherit;
    display: block;
    position: relative;
}
.hp-doctor-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 50px rgba(0,0,0,.12);
}
.hp-doctor-card-top {
    height: 80px;
    background: <?= $type_gradient ?>;
    position: relative;
}
.hp-doctor-avatar {
    width: 90px; height:90px;
    border-radius: 50%;
    border: 4px solid #fff;
    overflow: hidden;
    position: absolute;
    bottom: -45px;
    left: 50%;
    transform: translateX(-50%);
    box-shadow: 0 6px 20px rgba(0,0,0,.12);
    background: #fff;
    z-index: 2;
}
.hp-doctor-avatar img { width:100%; height:100%; object-fit:cover; }
.hp-doc-initial {
    width:100%; height:100%;
    display:flex; align-items:center; justify-content:center;
    background: <?= $type_gradient ?>;
    color:#fff; font-size:36px; font-weight:700;
}
.hp-doctor-body {
    padding: 55px 20px 24px;
    text-align: center;
}
.hp-doctor-name {
    font-size: 17px; font-weight:700; color:#1e293b;
    margin: 0 0 6px;
}
.hp-doctor-spec {
    font-size: 13px; font-weight:600;
    color: <?= $type_color_main ?>;
    margin-bottom: 12px;
}
.hp-doctor-rating {
    display:inline-flex;align-items:center;gap:6px;
    background:#fffbeb;
    padding:5px 12px;border-radius:50px;
    font-size:13px;font-weight:600;color:#b45309;
}
.hp-doctor-rating .stars { color:#f59e0b; }
.hp-doctor-rating .count { color:#9ca3af; font-weight:400; font-size:12px; }

/* ===== REVIEWS ===== */
.hp-reviews-section {
    background: #fff;
    border-radius: 20px;
    padding: 35px;
    box-shadow: 0 4px 20px rgba(0,0,0,.06);
    animation: fadeInUp .6s ease-out backwards;
}

.hp-review-form-card {
    background: #f8fafc;
    border-radius: 16px;
    padding: 28px;
    margin-bottom: 30px;
    border: 1.5px solid #e2e8f0;
}
.hp-review-form-card h4 { margin:0 0 20px; font-size:17px; color:#1e293b; font-weight:700; }

.hp-star-rating { display:flex; gap:4px; flex-direction:row-reverse; justify-content:flex-end; }
.hp-star-rating input { display:none; }
.hp-star-rating label {
    font-size:32px; color:#e2e8f0; cursor:pointer;
    transition: all .2s ease;
}
.hp-star-rating label:hover,
.hp-star-rating label:hover ~ label,
.hp-star-rating input:checked ~ label { color:#f59e0b; transform:scale(1.1); }

.hp-form-group { margin-bottom:16px; }
.hp-form-group label { display:block; font-weight:600; color:#475569; margin-bottom:8px; font-size:14px; }
.hp-form-group textarea {
    width:100%; padding:14px 18px;
    border:2px solid #e2e8f0;
    border-radius:14px; font-size:15px;
    transition:all .3s ease;
    background:#fff;
    resize:vertical;
    font-family: inherit;
    box-sizing: border-box;
}
.hp-form-group textarea:focus { outline:none; border-color:<?= $type_color_main ?>; box-shadow:0 0 0 4px <?= $is_govt ? 'rgba(5,150,105,.1)' : 'rgba(99,102,241,.1)' ?>; }

.hp-submit-btn {
    background: <?= $type_gradient ?>;
    color:#fff; border:none;
    padding:14px 32px;
    border-radius:12px;
    font-size:15px; font-weight:700;
    cursor:pointer;
    transition:all .3s ease;
    display:inline-flex;align-items:center;gap:8px;
}
.hp-submit-btn:hover { transform:translateY(-3px); box-shadow:0 8px 25px <?= $is_govt ? 'rgba(5,150,105,.35)' : 'rgba(99,102,241,.35)' ?>; }

.hp-reviews-list { display:flex; flex-direction:column; gap:16px; }

.hp-review-item {
    display:flex; gap:18px;
    padding:22px;
    background:#f8fafc;
    border-radius:16px;
    border:1.5px solid #e2e8f0;
    transition:all .3s ease;
}
.hp-review-item:hover { background:#fff; border-color:<?= $type_color_main ?>22; box-shadow:0 4px 16px rgba(0,0,0,.05); }

.hp-reviewer-avatar {
    width:48px;height:48px;
    border-radius:14px;
    background: <?= $type_gradient ?>;
    color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-size:20px;font-weight:700;
    flex-shrink:0;
}
.hp-review-content { flex:1; }
.hp-review-top { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:8px; }
.hp-reviewer-name { font-weight:700; color:#1e293b; font-size:15px; }
.hp-review-date { font-size:12px; color:#94a3b8; background:#f1f5f9; padding:3px 10px; border-radius:50px; }
.hp-review-stars { color:#f59e0b; font-size:14px; margin-bottom:10px; letter-spacing:1px; }
.hp-review-comment { color:#64748b; line-height:1.7; margin:0; font-size:14px; }

/* ===== EMPTY & LOGIN ===== */
.hp-empty { text-align:center; padding:50px 20px; color:#94a3b8; }
.hp-empty-icon { font-size:56px; margin-bottom:16px; animation:float 3s ease-in-out infinite; }
.hp-empty h3 { margin:0 0 8px; color:#64748b; font-size:18px; }
.hp-empty p { margin:0; font-size:14px; }

.hp-login-prompt {
    background: <?= $type_color_light ?>;
    border:1.5px solid <?= $type_color_main ?>40;
    border-radius:14px;
    padding:22px;
    text-align:center;
    color:#475569;
    font-size:15px;
}
.hp-login-prompt a { color:<?= $type_color_main ?>; font-weight:700; text-decoration:none; }
.hp-login-prompt a:hover { text-decoration:underline; }

/* ===== ALERT ===== */
.hp-alert { padding:14px 20px; border-radius:12px; margin-top:14px; display:flex; align-items:center; gap:10px; font-size:14px; font-weight:500; }
.hp-alert.success { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
.hp-alert.error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }

/* ===== RESPONSIVE ===== */
@media(max-width:900px) {
    .hp-content-grid { grid-template-columns:1fr; }
    .hp-hero-name { font-size:28px; }
    .hp-hero-icon { width:80px; height:80px; font-size:38px; border-radius:20px; }
}
@media(max-width:600px) {
    .hp-hero { padding: 40px 0 80px; }
    .hp-hero-content { flex-direction:column; text-align:center; gap:16px; }
    .hp-hero-badges { justify-content:center; }
    .hp-hero-address { justify-content:center; }
    .hp-hero-name { font-size:24px; }
    .hp-stats-row { grid-template-columns: 1fr 1fr; margin-top:-50px; }
    .hp-schedule-grid { grid-template-columns: repeat(4, 1fr); }
    .hp-services-grid { grid-template-columns: 1fr; }
    .hp-doctors-grid { grid-template-columns: 1fr; }
    .hp-reviews-section { padding:24px 18px; }
    .hp-review-form-card { padding:20px; }
}
</style>

<!-- ===== HERO BANNER ===== -->
<div class="hp-page">
    <div class="hp-hero">
        <div class="container">
            <div class="hp-hero-content">
                <div class="hp-hero-icon">🏥</div>
                <div class="hp-hero-info">
                    <div class="hp-hero-badges">
                        <span class="hp-type-badge"><?= $type_label ?></span>
                        <?php if ($is_open_now): ?>
                            <span class="hp-live-badge open"><span class="hp-live-dot"></span> এখন খোলা আছে</span>
                        <?php else: ?>
                            <span class="hp-live-badge closed"><span class="hp-live-dot" style="animation:none"></span> এখন বন্ধ</span>
                        <?php endif; ?>
                    </div>
                    <h1 class="hp-hero-name"><?= htmlspecialchars($hospital['name']) ?></h1>
                    <p class="hp-hero-address"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($hospital['address'] ?? 'N/A') ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- ===== STAT CARDS ===== -->
        <div class="hp-stats-row">
            <div class="hp-stat-card">
                <div class="hp-stat-icon">⭐</div>
                <div class="hp-stat-value"><?= $hospital['avg_rating'] ? number_format($hospital['avg_rating'], 1) : '–' ?></div>
                <div class="hp-stat-label">গড় রেটিং</div>
            </div>
            <div class="hp-stat-card">
                <div class="hp-stat-icon">👨‍⚕️</div>
                <div class="hp-stat-value"><?= $total_doctors ?></div>
                <div class="hp-stat-label">বিশেষজ্ঞ ডাক্তার</div>
            </div>
            <div class="hp-stat-card">
                <div class="hp-stat-icon">🛡️</div>
                <div class="hp-stat-value"><?= count($branch_services) ?></div>
                <div class="hp-stat-label">সেবা প্রদান</div>
            </div>
            <div class="hp-stat-card">
                <div class="hp-stat-icon">📝</div>
                <div class="hp-stat-value"><?= $total_reviews_count ?></div>
                <div class="hp-stat-label">রিভিউ</div>
            </div>
        </div>

        <!-- ===== CONTENT GRID ===== -->
        <div class="hp-content-grid">
            <!-- LEFT COLUMN -->
            <div>
                <!-- Schedule -->
                <div class="hp-section-card">
                    <h3 class="hp-section-title">
                        <span class="hp-section-title-icon" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">🕒</span>
                        সেবার সময়সূচী
                    </h3>
                    <div class="hp-schedule-grid">
                        <?php 
                        $days = ['Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday','Friday'];
                        $bn_days_short = ['শনি','রবি','সোম','মঙ্গল','বুধ','বৃহঃ','শুক্র'];
                        foreach ($days as $index => $day):
                            $day_av = $availability[$day] ?? null;
                            $is_today = ($day === date('l'));
                            $is_closed = (!$day_av || !$day_av['is_open']);
                            $is_24h = ($day_av && $day_av['is_24_hours']);
                            
                            $card_class = 'hp-schedule-day';
                            if ($is_today) $card_class .= ' today';
                            if ($is_closed && !$is_24h) $card_class .= ' off';
                            
                            $dot_class = 'hp-sd-dot';
                            if ($is_24h) $dot_class .= ' full';
                            elseif (!$is_closed) $dot_class .= ' open';
                            else $dot_class .= ' closed';
                        ?>
                            <div class="<?= $card_class ?>">
                                <div class="hp-sd-name"><?= $bn_days_short[$index] ?></div>
                                <div class="<?= $dot_class ?>"></div>
                                <div class="hp-sd-time">
                                    <?php 
                                    if ($is_24h) echo '২৪/৭';
                                    elseif ($is_closed) echo 'বন্ধ';
                                    else echo date("g:iA", strtotime($day_av['opening_time'])) . '<br>' . date("g:iA", strtotime($day_av['closing_time']));
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Services -->
                <?php if (!empty($branch_services)): ?>
                <div class="hp-section-card">
                    <h3 class="hp-section-title">
                        <span class="hp-section-title-icon" style="background:linear-gradient(135deg,#10b981,#34d399)">🛠️</span>
                        আমাদের সেবাসমূহ
                    </h3>
                    <div class="hp-services-grid">
                        <?php foreach ($branch_services as $service): ?>
                            <div class="hp-service-item">
                                <div class="hp-service-icon">
                                    <i class="fas <?= htmlspecialchars($service['icon'] ?? 'fa-check-circle') ?>"></i>
                                </div>
                                <div>
                                    <p class="hp-service-name">
                                        <?= htmlspecialchars($service['service_name_bn'] ?? $service['service_name']) ?>
                                        <?php if ($service['is_24x7']): ?>
                                            <span class="hp-service-24x7">২৪/৭</span>
                                        <?php endif; ?>
                                    </p>
                                    <?php if ($service['notes']): ?>
                                        <p class="hp-service-note"><?= htmlspecialchars($service['notes']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Specializations -->
                <?php if($specializations && $specializations->num_rows > 0): ?>
                <div class="hp-section-card">
                    <h3 class="hp-section-title">
                        <span class="hp-section-title-icon" style="background:linear-gradient(135deg,#f59e0b,#fbbf24)">🎯</span>
                        বিশেষজ্ঞতার ক্ষেত্র
                    </h3>
                    <div class="hp-spec-tags">
                        <?php while($spec = $specializations->fetch_assoc()): ?>
                            <span class="hp-spec-tag"><?= htmlspecialchars($spec['name_bn']) ?></span>
                        <?php endwhile; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- RIGHT COLUMN (SIDEBAR) -->
            <div>
                <!-- Quick Actions -->
                <div class="hp-section-card">
                    <h3 class="hp-section-title">
                        <span class="hp-section-title-icon" style="background:linear-gradient(135deg,#ef4444,#f87171)">⚡</span>
                        দ্রুত সেবা
                    </h3>
                    <div class="hp-action-btns">
                        <a href="book_ambulance_v2.php?branch_id=<?= $branch_id ?>" class="hp-action-btn ambulance">
                            🚑 অ্যাম্বুলেন্স বুক করুন
                        </a>
                        <?php if (!empty($hospital['hotline'])): ?>
                        <a href="tel:<?= htmlspecialchars($hospital['hotline']) ?>" class="hp-action-btn call">
                            📞 <?= htmlspecialchars($hospital['hotline']) ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Hospital Info -->
                <div class="hp-section-card">
                    <h3 class="hp-section-title">
                        <span class="hp-section-title-icon" style="background:<?= $type_gradient ?>">ℹ️</span>
                        হাসপাতাল তথ্য
                    </h3>
                    <div style="display:flex;flex-direction:column;gap:14px;">
                        <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:#f8fafc;border-radius:12px;">
                            <span style="font-size:20px;">📍</span>
                            <div>
                                <div style="font-size:12px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">ঠিকানা</div>
                                <div style="font-size:14px;color:#334155;font-weight:500;margin-top:2px;"><?= htmlspecialchars($hospital['address'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:#f8fafc;border-radius:12px;">
                            <span style="font-size:20px;">📞</span>
                            <div>
                                <div style="font-size:12px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">হটলাইন</div>
                                <div style="font-size:14px;color:#334155;font-weight:500;margin-top:2px;"><?= htmlspecialchars($hospital['hotline'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:#f8fafc;border-radius:12px;">
                            <span style="font-size:20px;">🏷️</span>
                            <div>
                                <div style="font-size:12px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">ধরন</div>
                                <div style="font-size:14px;color:#334155;font-weight:500;margin-top:2px;"><?= $is_govt ? 'সরকারি' : 'বেসরকারি' ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== DOCTORS SECTION (Full Width) ===== -->
        <div class="hp-doctors-section">
            <div class="hp-section-header">
                <h2>এই হাসপাতালের বিশেষজ্ঞ ডাক্তারগণ</h2>
                <p><?= $total_doctors ?> জন ডাক্তার এই হাসপাতালে সেবা প্রদান করেন</p>
                <div class="hp-title-line"></div>
            </div>

            <?php if($doctors_result && $doctors_result->num_rows > 0): ?>
                <div class="hp-doctors-grid">
                    <?php while($doc = $doctors_result->fetch_assoc()): ?>
                        <a href="doctor_profile.php?id=<?= $doc['id'] ?>" class="hp-doctor-card">
                            <div class="hp-doctor-card-top">
                                <?php 
                                $profile_path = $doc['profile_image'] ?? '';
                                $found = false;
                                if (!empty($profile_path)) {
                                    if (file_exists($profile_path)) { $found = true; }
                                    else if (file_exists('uploads/users/' . $profile_path)) { $profile_path = 'uploads/users/' . $profile_path; $found = true; }
                                    else if (file_exists('uploads/profile_pics/' . $profile_path)) { $profile_path = 'uploads/profile_pics/' . $profile_path; $found = true; }
                                }
                                ?>
                                <div class="hp-doctor-avatar">
                                    <?php if ($found): ?>
                                        <img src="<?= htmlspecialchars($profile_path) ?>" alt="<?= htmlspecialchars($doc['full_name']) ?>">
                                    <?php else: ?>
                                        <div class="hp-doc-initial"><?= mb_substr($doc['full_name'], 0, 1) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="hp-doctor-body">
                                <h3 class="hp-doctor-name"><?= htmlspecialchars($doc['full_name']) ?></h3>
                                <p class="hp-doctor-spec"><?= htmlspecialchars($doc['specialization']) ?></p>
                                <div class="hp-doctor-rating">
                                    <span class="stars">★</span>
                                    <?= $doc['avg_rating'] ? number_format($doc['avg_rating'], 1) : 'N/A' ?>
                                    <span class="count">(<?= $doc['total_reviews'] ?> রিভিউ)</span>
                                </div>
                            </div>
                        </a>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="hp-empty">
                    <div class="hp-empty-icon">👨‍⚕️</div>
                    <h3>কোনো ডাক্তার পাওয়া যায়নি</h3>
                    <p>এই হাসপাতালে এখনো কোনো ডাক্তার নিবন্ধিত নেই।</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- ===== REVIEWS SECTION ===== -->
        <div class="hp-reviews-section" style="margin-top:40px;">
            <h3 class="hp-section-title">
                <span class="hp-section-title-icon" style="background:linear-gradient(135deg,#f59e0b,#fbbf24)">📝</span>
                হাসপাতাল সম্পর্কে রিভিউ
            </h3>

            <!-- Review Form -->
            <div class="hp-review-form-card">
                <h4>আপনার অভিজ্ঞতা শেয়ার করুন</h4>
                <?php if(isset($_SESSION['user_id'])): ?>
                    <form id="review-form">
                        <input type="hidden" name="rateable_id" value="<?= $hospital['branch_id'] ?>">
                        <input type="hidden" name="rateable_type" value="Branch">
                        
                        <div class="hp-form-group">
                            <label>আপনার রেটিং</label>
                            <div class="hp-star-rating">
                                <input type="radio" id="star5" name="rating" value="5"><label for="star5" title="5 stars">★</label>
                                <input type="radio" id="star4" name="rating" value="4"><label for="star4" title="4 stars">★</label>
                                <input type="radio" id="star3" name="rating" value="3"><label for="star3" title="3 stars">★</label>
                                <input type="radio" id="star2" name="rating" value="2"><label for="star2" title="2 stars">★</label>
                                <input type="radio" id="star1" name="rating" value="1"><label for="star1" title="1 star">★</label>
                            </div>
                        </div>
                        
                        <div class="hp-form-group">
                            <label for="comment">আপনার মন্তব্য</label>
                            <textarea name="comment" rows="3" placeholder="হাসপাতাল সম্পর্কে আপনার অভিজ্ঞতা লিখুন..." required></textarea>
                        </div>
                        
                        <button type="submit" class="hp-submit-btn"><i class="fas fa-paper-plane"></i> মতামত জমা দিন</button>
                        <div id="review-message"></div>
                    </form>
                <?php else: ?>
                    <div class="hp-login-prompt">
                        <p>রিভিউ দেওয়ার জন্য অনুগ্রহ করে <a href="login.php">লগ-ইন করুন</a> অথবা <a href="register.php">রেজিস্ট্রেশন করুন</a>।</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Reviews List -->
            <?php if($reviews_result && $reviews_result->num_rows > 0): ?>
                <div class="hp-reviews-list">
                    <?php while($review = $reviews_result->fetch_assoc()): ?>
                        <div class="hp-review-item">
                            <div class="hp-reviewer-avatar"><?= mb_substr($review['reviewer_name'] ?? 'অ', 0, 1) ?></div>
                            <div class="hp-review-content">
                                <div class="hp-review-top">
                                    <span class="hp-reviewer-name"><?= htmlspecialchars($review['reviewer_name'] ?? 'বেনামে') ?></span>
                                    <span class="hp-review-date"><?= date("d M, Y", strtotime($review['created_at'])) ?></span>
                                </div>
                                <div class="hp-review-stars">
                                    <?php for($i=1; $i<=5; $i++): ?>
                                        <span style="color:<?= $i <= $review['rating'] ? '#f59e0b' : '#e2e8f0' ?>">★</span>
                                    <?php endfor; ?>
                                </div>
                                <p class="hp-review-comment">"<?= htmlspecialchars($review['comment']) ?>"</p>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="hp-empty">
                    <div class="hp-empty-icon">💬</div>
                    <h3>কোনো রিভিউ নেই</h3>
                    <p>এই হাসপাতাল সম্পর্কে এখনো কোনো রিভিউ জমা পড়েনি।</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Review Form Handler
    const reviewForm = document.getElementById('review-form');
    if (reviewForm) {
        reviewForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const messageDiv = document.getElementById('review-message');
            
            if (!formData.get('rating')) {
                messageDiv.innerHTML = '<div class="hp-alert error">⚠️ অনুগ্রহ করে একটি স্টার রেটিং দিন।</div>';
                return;
            }

            fetch('submit_rating.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        messageDiv.innerHTML = '<div class="hp-alert success">✅ ' + data.success + '</div>';
                        this.reset();
                    } else {
                        messageDiv.innerHTML = '<div class="hp-alert error">⚠️ ' + (data.error || 'একটি ত্রুটি ঘটেছে।') + '</div>';
                    }
                }).catch(() => {
                    messageDiv.innerHTML = '<div class="hp-alert error">⚠️ সংযোগ ত্রুটি। আবার চেষ্টা করুন।</div>';
                });
        });
    }

    // Animate stats on scroll
    const observerOptions = { threshold: 0.3 };
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.animation = 'fadeInScale .6s ease-out forwards';
                observer.unobserve(entry.target);
            }
        });
    }, observerOptions);
    
    document.querySelectorAll('.hp-stat-card, .hp-section-card, .hp-doctor-card, .hp-review-item').forEach(el => {
        observer.observe(el);
    });
});
</script>

<?php
$stmt_hosp->close();
$stmt_docs->close();
$stmt_specs->close();
$stmt_reviews->close();
include_once(BASE_PATH . 'includes/footer.php');
?>
