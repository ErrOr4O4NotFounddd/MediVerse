<?php
define('BASE_PATH', __DIR__ . '/');
include_once(BASE_PATH . 'includes/db_config.php');
include_once(BASE_PATH . 'includes/header.php');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "<p class='container'>Invalid Doctor ID provided.</p>";
    include_once(BASE_PATH . 'includes/footer.php');
    exit();
}
$doctor_id = (int)$_GET['id'];

// Fetch Doctor's profile information
$sql_doctor_profile = "
    SELECT 
        u.full_name, u.profile_image,
        d.qualifications, d.experience_years, d.bio,
        s.name_bn AS specialization_bn,
        s.name_en AS specialization_en,
        AVG(r.rating) AS avg_rating,
        COUNT(r.id) AS total_reviews
    FROM doctors d
    JOIN users u ON d.user_id = u.id
    JOIN specializations s ON d.specialization_id = s.id
    LEFT JOIN ratings r ON d.id = r.rateable_id AND r.rateable_type = 'Doctor'
    WHERE d.id = ? AND d.is_verified = 1
    GROUP BY d.id
";
$stmt_profile = $conn->prepare($sql_doctor_profile);
$stmt_profile->bind_param("i", $doctor_id);
$stmt_profile->execute();
$result_profile = $stmt_profile->get_result();
$doctor = $result_profile->fetch_assoc();

if (!$doctor) {
    echo "<p class='container'>Doctor not found or is not verified.</p>";
    include_once(BASE_PATH . 'includes/footer.php');
    exit();
}

// Fetch Doctor's all schedules
$sql_doctor_schedules = "
    SELECT
        ds.id AS schedule_id,
        ds.day_of_week,
        ds.start_time,
        ds.end_time,
        ds.consultation_fee,
        hb.branch_name,
        hb.address,
        hb.hotline,
        h.name AS hospital_name
    FROM doctor_schedules ds
    JOIN hospital_branches hb ON ds.branch_id = hb.id
    JOIN hospitals h ON hb.hospital_id = h.id
    WHERE ds.doctor_id = ? AND ds.deleted_at IS NULL
    ORDER BY FIELD(ds.day_of_week, 'Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'), ds.start_time
";
$stmt_schedules = $conn->prepare($sql_doctor_schedules);
$stmt_schedules->bind_param("i", $doctor_id);
$stmt_schedules->execute();
$schedules_result = $stmt_schedules->get_result();

$schedules = [];
while($schedule = $schedules_result->fetch_assoc()) {
    $schedule['start_time_formatted'] = date('h:i A', strtotime($schedule['start_time']));
    $schedule['end_time_formatted'] = date('h:i A', strtotime($schedule['end_time']));
    $schedules[] = $schedule;
}
$total_schedules = count($schedules);

// Fetch Reviews
$sql_reviews = "
    SELECT r.rating, r.comment, u.full_name AS reviewer_name, r.created_at
    FROM vw_active_ratings r
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.rateable_id = ? AND r.rateable_type = 'Doctor' AND r.comment IS NOT NULL AND r.comment != ''
    ORDER BY r.created_at DESC
    LIMIT 10
";
$stmt_reviews = $conn->prepare($sql_reviews);
$stmt_reviews->bind_param("i", $doctor_id);
$stmt_reviews->execute();
$reviews_result = $stmt_reviews->get_result();

$reviews = [];
while($review = $reviews_result->fetch_assoc()) {
    $reviews[] = $review;
}
$total_reviews_count = count($reviews);

// Bangla day map
$bn_day_map = [
    'Saturday' => 'শনিবার', 'Sunday' => 'রবিবার', 'Monday' => 'সোমবার',
    'Tuesday' => 'মঙ্গলবার', 'Wednesday' => 'বুধবার', 'Thursday' => 'বৃহস্পতিবার', 'Friday' => 'শুক্রবার'
];

// Profile image resolution
$profile_path = $doctor['profile_image'] ?? '';
$profile_found = false;
if (!empty($profile_path)) {
    if (file_exists($profile_path)) { $profile_found = true; }
    else if (file_exists('uploads/users/' . $profile_path)) { $profile_path = 'uploads/users/' . $profile_path; $profile_found = true; }
    else if (file_exists('uploads/profile_pics/' . $profile_path)) { $profile_path = 'uploads/profile_pics/' . $profile_path; $profile_found = true; }
}
?>

<style>
/* ==================== DOCTOR PROFILE v2 — Premium Redesign ==================== */
@keyframes dp-fadeInUp { from { opacity:0; transform:translateY(30px); } to { opacity:1; transform:translateY(0); } }
@keyframes dp-fadeInScale { from { opacity:0; transform:scale(.92); } to { opacity:1; transform:scale(1); } }
@keyframes dp-pulseLive { 0%,100% { box-shadow:0 0 0 0 rgba(99,102,241,.4);} 50% { box-shadow:0 0 0 12px rgba(99,102,241,0);} }
@keyframes dp-float { 0%,100% { transform:translateY(0); } 50% { transform:translateY(-6px); } }
@keyframes dp-shimmer { 0% { background-position:-200% 0; } 100% { background-position:200% 0; } }

:root {
    --dp-primary: #6366f1;
    --dp-primary-light: #818cf8;
    --dp-primary-bg: #eef2ff;
    --dp-accent: #8b5cf6;
    --dp-gradient: linear-gradient(135deg, #6366f1, #8b5cf6, #a78bfa);
    --dp-gradient-r: linear-gradient(135deg, #8b5cf6, #6366f1);
    --dp-text-dark: #1e293b;
    --dp-text-body: #475569;
    --dp-text-muted: #94a3b8;
    --dp-surface: #f8fafc;
    --dp-border: #e2e8f0;
    --dp-green: #10b981;
    --dp-gold: #f59e0b;
}

.dp-page { background:#f0f2f5; min-height:100vh; padding-bottom:60px; }

/* ===== HERO BANNER ===== */
.dp-hero {
    background: var(--dp-gradient);
    position: relative;
    padding: 50px 0 110px;
    overflow: hidden;
}
.dp-hero::before {
    content: '';
    position: absolute; inset:0;
    background: url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='.05'%3E%3Cpath d='M20 20.5V18H22V20.5H24.5V22.5H22V25H20V22.5H17.5V20.5H20z'/%3E%3C/g%3E%3C/svg%3E") repeat;
}
.dp-hero::after {
    content: '';
    position: absolute; bottom:0; left:0; right:0;
    height: 90px;
    background: linear-gradient(to bottom, transparent, #f0f2f5);
}
.dp-hero .container { position:relative; z-index:2; }

.dp-hero-content {
    display: flex;
    align-items: center;
    gap: 40px;
    animation: dp-fadeInUp .7s ease-out;
}

.dp-hero-avatar {
    width: 150px; height:150px;
    border-radius: 30px;
    overflow: hidden;
    border: 4px solid rgba(255,255,255,.3);
    box-shadow: 0 12px 40px rgba(0,0,0,.2);
    flex-shrink: 0;
    background: rgba(255,255,255,.15);
    backdrop-filter: blur(10px);
    position: relative;
}
.dp-hero-avatar img { width:100%; height:100%; object-fit:cover; }
.dp-hero-avatar .dp-avatar-initial {
    width:100%; height:100%;
    display:flex; align-items:center; justify-content:center;
    font-size:60px; font-weight:800; color:rgba(255,255,255,.9);
    background: rgba(255,255,255,.1);
}
.dp-hero-avatar::after {
    content:'';
    position:absolute; bottom:0; left:0; right:0; height:40%;
    background: linear-gradient(to top, rgba(99,102,241,.4), transparent);
    pointer-events:none;
}

.dp-hero-info { flex:1; color:#fff; }
.dp-hero-spec {
    display: inline-flex; align-items:center; gap:6px;
    padding: 6px 18px;
    background: rgba(255,255,255,.18);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255,255,255,.25);
    border-radius: 50px;
    font-size: 13px; font-weight:600;
    color: #fff;
    margin-bottom: 14px;
}
.dp-hero-name {
    font-size: 38px; font-weight:800; margin:0 0 10px;
    color: #fff;
    text-shadow: 0 2px 12px rgba(0,0,0,.15);
    line-height: 1.25;
}
.dp-hero-quals {
    font-size: 15px;
    color: rgba(255,255,255,.8);
    margin: 0;
    line-height: 1.7;
    max-width: 600px;
}

/* ===== STAT CARDS ===== */
.dp-stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 18px;
    margin-top: -65px;
    position: relative; z-index:5;
    margin-bottom: 36px;
}
.dp-stat-card {
    background: #fff;
    border-radius: 18px;
    padding: 26px 22px;
    text-align: center;
    box-shadow: 0 8px 28px rgba(0,0,0,.07);
    transition: all .35s cubic-bezier(.25,.46,.45,.94);
    animation: dp-fadeInScale .6s ease-out backwards;
    position: relative; overflow:hidden; cursor:default;
}
.dp-stat-card:nth-child(1) { animation-delay:.1s }
.dp-stat-card:nth-child(2) { animation-delay:.2s }
.dp-stat-card:nth-child(3) { animation-delay:.3s }
.dp-stat-card:hover { transform:translateY(-5px); box-shadow:0 14px 36px rgba(0,0,0,.1); }
.dp-stat-card::before {
    content:''; position:absolute; top:0; left:0; right:0; height:4px;
    border-radius:18px 18px 0 0;
}
.dp-stat-card:nth-child(1)::before { background:linear-gradient(90deg,#f59e0b,#fbbf24); }
.dp-stat-card:nth-child(2)::before { background:linear-gradient(90deg,#6366f1,#818cf8); }
.dp-stat-card:nth-child(3)::before { background:linear-gradient(90deg,#10b981,#34d399); }

.dp-stat-icon {
    width:48px; height:48px; border-radius:14px;
    display:flex; align-items:center; justify-content:center;
    font-size:22px; margin:0 auto 12px;
}
.dp-stat-card:nth-child(1) .dp-stat-icon { background:#fffbeb; color:#f59e0b; }
.dp-stat-card:nth-child(2) .dp-stat-icon { background:#eef2ff; color:#6366f1; }
.dp-stat-card:nth-child(3) .dp-stat-icon { background:#ecfdf5; color:#10b981; }
.dp-stat-value { font-size:28px; font-weight:800; color:var(--dp-text-dark); margin-bottom:3px; line-height:1; }
.dp-stat-label { font-size:12px; font-weight:600; color:var(--dp-text-muted); text-transform:uppercase; letter-spacing:.5px; }

/* ===== CONTENT GRID ===== */
.dp-content-grid {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 28px;
    align-items: start;
}

/* ===== SECTION CARD ===== */
.dp-section-card {
    background: #fff;
    border-radius: 20px;
    padding: 28px;
    box-shadow: 0 4px 18px rgba(0,0,0,.05);
    margin-bottom: 28px;
    animation: dp-fadeInUp .6s ease-out backwards;
}
.dp-section-title {
    display: flex; align-items:center; gap:12px;
    margin: 0 0 22px;
    font-size: 19px; font-weight:700; color:var(--dp-text-dark);
    padding-bottom: 14px;
    border-bottom: 2px solid #f1f5f9;
}
.dp-section-title-icon {
    width:40px; height:40px; border-radius:12px;
    display:flex; align-items:center; justify-content:center;
    font-size:18px; color:#fff; flex-shrink:0;
}

/* ===== SCHEDULE CARDS ===== */
.dp-schedules-list { display:flex; flex-direction:column; gap:16px; }

.dp-schedule-card {
    background: #fff;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 4px 18px rgba(0,0,0,.05);
    border: 1.5px solid var(--dp-border);
    transition: all .35s ease;
    animation: dp-fadeInUp .5s ease-out backwards;
}
.dp-schedule-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 35px rgba(99,102,241,.12);
    border-color: rgba(99,102,241,.2);
}

.dp-sched-header {
    background: var(--dp-gradient);
    padding: 18px 22px;
    color: #fff;
    display:flex; align-items:center; gap:14px;
}
.dp-sched-hosp-icon {
    width:44px; height:44px;
    background: rgba(255,255,255,.2);
    border-radius:12px;
    display:flex; align-items:center; justify-content:center;
    font-size:20px; flex-shrink:0;
    backdrop-filter: blur(8px);
}
.dp-sched-hosp-info { flex:1; }
.dp-sched-hosp-name { font-size:16px; font-weight:700; margin:0 0 3px; color:#fff; }
.dp-sched-hosp-branch { font-size:12px; opacity:.85; margin:0; }
.dp-sched-hosp-addr { font-size:12px; opacity:.75; margin:4px 0 0; display:flex; align-items:center; gap:4px; }

.dp-sched-body {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0;
    border-bottom: 1px solid var(--dp-border);
}
.dp-sched-detail {
    text-align: center;
    padding: 18px 12px;
    border-right: 1px solid var(--dp-border);
}
.dp-sched-detail:last-child { border-right:none; }
.dp-sched-detail-label {
    font-size:11px; color:var(--dp-text-muted);
    text-transform:uppercase; letter-spacing:.5px;
    margin-bottom:8px; display:block; font-weight:600;
}
.dp-sched-detail-value { font-size:14px; font-weight:700; color:var(--dp-text-dark); }

.dp-sched-day-pill {
    display:inline-block;
    background: var(--dp-primary-bg);
    color: var(--dp-primary);
    padding:5px 14px; border-radius:50px;
    font-size:12px; font-weight:700;
}
.dp-sched-fee { color:#059669; font-size:17px; }

.dp-sched-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 22px;
    gap: 12px;
}
.dp-sched-hotline {
    display:flex; align-items:center; gap:10px;
    font-size:13px; color:var(--dp-text-body);
}
.dp-sched-hotline-icon {
    width:34px; height:34px;
    background:linear-gradient(135deg,#10b981,#34d399);
    border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-size:14px;
}
.dp-sched-hotline span { font-size:11px; color:var(--dp-text-muted); display:block; }
.dp-sched-hotline strong { font-size:13px; color:var(--dp-text-dark); }

.dp-btn-book {
    display:inline-flex; align-items:center; gap:8px;
    padding:10px 22px;
    background: var(--dp-gradient);
    color:#fff;
    border-radius:12px;
    text-decoration:none;
    font-weight:700; font-size:13px;
    transition: all .3s ease;
    white-space:nowrap;
    border:none; cursor:pointer;
}
.dp-btn-book:hover { transform:translateY(-2px); box-shadow:0 8px 22px rgba(99,102,241,.35); }

/* ===== SIDEBAR ===== */
.dp-sidebar-card {
    background:#fff;
    border-radius:20px;
    padding:28px;
    box-shadow:0 4px 18px rgba(0,0,0,.05);
    margin-bottom:24px;
    animation: dp-fadeInUp .6s ease-out backwards;
}

.dp-info-list { display:flex; flex-direction:column; gap:14px; }
.dp-info-item {
    display:flex; align-items:center; gap:14px;
    padding:14px 16px;
    background: var(--dp-surface);
    border-radius:14px;
    transition: all .3s ease;
}
.dp-info-item:hover { background:var(--dp-primary-bg); transform:translateX(4px); }
.dp-info-icon {
    width:42px; height:42px;
    border-radius:12px;
    display:flex; align-items:center; justify-content:center;
    font-size:18px; flex-shrink:0;
    background: var(--dp-primary-bg);
    color: var(--dp-primary);
    transition: all .3s ease;
}
.dp-info-item:hover .dp-info-icon { background:var(--dp-primary); color:#fff; }
.dp-info-label { font-size:11px; color:var(--dp-text-muted); font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
.dp-info-value { font-size:14px; color:var(--dp-text-dark); font-weight:600; margin-top:2px; }

/* ===== QUALIFICATIONS CARD ===== */
.dp-quals-box {
    background: var(--dp-primary-bg);
    border-radius: 14px;
    padding: 20px;
    border-left: 4px solid var(--dp-primary);
    font-size: 14px;
    color: var(--dp-text-body);
    line-height: 1.8;
}

/* ===== REVIEWS ===== */
.dp-reviews-section {
    background:#fff;
    border-radius:20px;
    padding:32px;
    box-shadow:0 4px 18px rgba(0,0,0,.05);
    animation: dp-fadeInUp .6s ease-out backwards;
}

.dp-review-form {
    background: var(--dp-surface);
    border-radius:16px;
    padding:26px;
    margin-bottom:28px;
    border:1.5px solid var(--dp-border);
}
.dp-review-form h4 { margin:0 0 20px; font-size:17px; color:var(--dp-text-dark); font-weight:700; }

.dp-star-rating { display:flex; gap:4px; flex-direction:row-reverse; justify-content:flex-end; }
.dp-star-rating input { display:none; }
.dp-star-rating label {
    font-size:32px; color:#e2e8f0; cursor:pointer;
    transition: all .2s ease;
}
.dp-star-rating label:hover,
.dp-star-rating label:hover ~ label,
.dp-star-rating input:checked ~ label { color:#f59e0b; transform:scale(1.12); }

.dp-form-group { margin-bottom:16px; }
.dp-form-group label { display:block; font-weight:600; color:var(--dp-text-body); margin-bottom:8px; font-size:14px; }
.dp-form-group textarea {
    width:100%; padding:14px 18px;
    border:2px solid var(--dp-border);
    border-radius:14px; font-size:15px;
    transition:all .3s ease;
    background:#fff;
    resize:vertical;
    font-family:inherit;
    box-sizing:border-box;
}
.dp-form-group textarea:focus { outline:none; border-color:var(--dp-primary); box-shadow:0 0 0 4px rgba(99,102,241,.1); }

.dp-submit-btn {
    background: var(--dp-gradient);
    color:#fff; border:none;
    padding:14px 30px;
    border-radius:12px;
    font-size:15px; font-weight:700;
    cursor:pointer;
    transition:all .3s ease;
    display:inline-flex; align-items:center; gap:8px;
}
.dp-submit-btn:hover { transform:translateY(-3px); box-shadow:0 8px 25px rgba(99,102,241,.35); }

.dp-reviews-list { display:flex; flex-direction:column; gap:14px; }
.dp-review-item {
    display:flex; gap:16px;
    padding:20px;
    background:var(--dp-surface);
    border-radius:16px;
    border:1.5px solid var(--dp-border);
    transition:all .3s ease;
}
.dp-review-item:hover { background:#fff; border-color:rgba(99,102,241,.15); box-shadow:0 4px 14px rgba(0,0,0,.04); }

.dp-reviewer-avatar {
    width:46px; height:46px;
    border-radius:14px;
    background: var(--dp-gradient);
    color:#fff;
    display:flex; align-items:center; justify-content:center;
    font-size:18px; font-weight:700;
    flex-shrink:0;
}
.dp-review-content { flex:1; }
.dp-review-top { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:8px; }
.dp-reviewer-name { font-weight:700; color:var(--dp-text-dark); font-size:14px; }
.dp-review-date { font-size:11px; color:var(--dp-text-muted); background:#f1f5f9; padding:3px 10px; border-radius:50px; }
.dp-review-stars { color:#f59e0b; font-size:14px; margin-bottom:10px; letter-spacing:1px; }
.dp-review-comment { color:var(--dp-text-body); line-height:1.7; margin:0; font-size:14px; }

/* ===== EMPTY & LOGIN ===== */
.dp-empty { text-align:center; padding:45px 20px; color:var(--dp-text-muted); }
.dp-empty-icon { font-size:52px; margin-bottom:14px; animation:dp-float 3s ease-in-out infinite; }
.dp-empty h3 { margin:0 0 6px; color:var(--dp-text-body); font-size:17px; }
.dp-empty p { margin:0; font-size:13px; }

.dp-login-prompt {
    background:var(--dp-primary-bg);
    border:1.5px solid rgba(99,102,241,.25);
    border-radius:14px;
    padding:22px;
    text-align:center;
    color:var(--dp-text-body);
    font-size:15px;
}
.dp-login-prompt a { color:var(--dp-primary); font-weight:700; text-decoration:none; }
.dp-login-prompt a:hover { text-decoration:underline; }

/* ===== ALERT ===== */
.dp-alert { padding:14px 20px; border-radius:12px; margin-top:14px; display:flex; align-items:center; gap:10px; font-size:14px; font-weight:500; }
.dp-alert.success { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
.dp-alert.error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }

/* ===== RESPONSIVE ===== */
@media(max-width:900px) {
    .dp-content-grid { grid-template-columns:1fr; }
}
@media(max-width:600px) {
    .dp-hero { padding:35px 0 90px; }
    .dp-hero-content { flex-direction:column; text-align:center; gap:20px; }
    .dp-hero-avatar { width:120px; height:120px; border-radius:24px; margin:0 auto; }
    .dp-hero-name { font-size:26px; }
    .dp-hero-quals { text-align:center; }
    .dp-stats-row { grid-template-columns:1fr 1fr 1fr; margin-top:-50px; }
    .dp-sched-body { grid-template-columns:1fr; }
    .dp-sched-detail { border-right:none; border-bottom:1px solid var(--dp-border); }
    .dp-sched-detail:last-child { border-bottom:none; }
    .dp-sched-footer { flex-direction:column; align-items:stretch; }
    .dp-btn-book { justify-content:center; }
    .dp-reviews-section { padding:22px 16px; }
}
</style>

<!-- ===== HERO BANNER ===== -->
<div class="dp-page">
    <div class="dp-hero">
        <div class="container">
            <div class="dp-hero-content">
                <div class="dp-hero-avatar">
                    <?php if ($profile_found): ?>
                        <img src="<?= htmlspecialchars($profile_path) ?>" alt="<?= htmlspecialchars($doctor['full_name']) ?>">
                    <?php else: ?>
                        <div class="dp-avatar-initial"><?= mb_substr($doctor['full_name'], 0, 1) ?></div>
                    <?php endif; ?>
                </div>
                <div class="dp-hero-info">
                    <span class="dp-hero-spec">🩺 <?= htmlspecialchars($doctor['specialization_bn']) ?></span>
                    <h1 class="dp-hero-name"><?= htmlspecialchars($doctor['full_name']) ?></h1>
                    <?php if (!empty($doctor['qualifications'])): ?>
                        <p class="dp-hero-quals">🎓 <?= htmlspecialchars($doctor['qualifications']) ?></p>
                    <?php endif; ?>
                    <?php if (isset($doctor['experience_years']) && $doctor['experience_years'] > 0): ?>
                        <p class="dp-hero-quals" style="margin-top: 5px; opacity: 0.9;">⏳ অভিজ্ঞতা: <?= htmlspecialchars($doctor['experience_years']) ?>+ বছর</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- ===== STAT CARDS ===== -->
        <div class="dp-stats-row">
            <div class="dp-stat-card">
                <div class="dp-stat-icon">⭐</div>
                <div class="dp-stat-value"><?= $doctor['avg_rating'] ? number_format($doctor['avg_rating'], 1) : '–' ?></div>
                <div class="dp-stat-label">গড় রেটিং</div>
            </div>
            <div class="dp-stat-card">
                <div class="dp-stat-icon">📝</div>
                <div class="dp-stat-value"><?= $total_reviews_count ?></div>
                <div class="dp-stat-label">রিভিউ</div>
            </div>
            <div class="dp-stat-card">
                <div class="dp-stat-icon">🏥</div>
                <div class="dp-stat-value"><?= $total_schedules ?></div>
                <div class="dp-stat-label">চেম্বার</div>
            </div>
        </div>

        <!-- ===== CONTENT GRID ===== -->
        <div class="dp-content-grid">
            <!-- LEFT: Schedules -->
            <div>
                <div class="dp-section-card">
                    <h3 class="dp-section-title">
                        <span class="dp-section-title-icon" style="background:var(--dp-gradient)">🏥</span>
                        চেম্বার এবং সময়সূচী
                    </h3>

                    <?php if(!empty($schedules)): ?>
                        <div class="dp-schedules-list">
                            <?php foreach($schedules as $i => $schedule): ?>
                                <div class="dp-schedule-card" style="animation-delay:<?= ($i * 0.1) ?>s">
                                    <div class="dp-sched-header">
                                        <div class="dp-sched-hosp-icon">🏥</div>
                                        <div class="dp-sched-hosp-info">
                                            <p class="dp-sched-hosp-name"><?= htmlspecialchars($schedule['hospital_name'] ?? 'Hospital') ?></p>
                                            <p class="dp-sched-hosp-branch"><?= htmlspecialchars($schedule['branch_name'] ?? 'Branch') ?></p>
                                            <p class="dp-sched-hosp-addr"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($schedule['address'] ?? 'N/A') ?></p>
                                        </div>
                                    </div>
                                    <div class="dp-sched-body">
                                        <div class="dp-sched-detail">
                                            <span class="dp-sched-detail-label">বার</span>
                                            <span class="dp-sched-detail-value">
                                                <span class="dp-sched-day-pill"><?= $bn_day_map[$schedule['day_of_week']] ?? $schedule['day_of_week'] ?></span>
                                            </span>
                                        </div>
                                        <div class="dp-sched-detail">
                                            <span class="dp-sched-detail-label">সময়</span>
                                            <span class="dp-sched-detail-value"><?= $schedule['start_time_formatted'] ?> – <?= $schedule['end_time_formatted'] ?></span>
                                        </div>
                                        <div class="dp-sched-detail">
                                            <span class="dp-sched-detail-label">ফি</span>
                                            <span class="dp-sched-detail-value dp-sched-fee">৳<?= htmlspecialchars($schedule['consultation_fee']) ?></span>
                                        </div>
                                    </div>
                                    <div class="dp-sched-footer">
                                        <div class="dp-sched-hotline">
                                            <div class="dp-sched-hotline-icon">📞</div>
                                            <div>
                                                <span>হটলাইন</span>
                                                <strong><?= htmlspecialchars($schedule['hotline'] ?? 'N/A') ?></strong>
                                            </div>
                                        </div>
                                        <a href="book_appointment.php?schedule_id=<?= $schedule['schedule_id'] ?>" class="dp-btn-book">
                                            📅 অ্যাপয়েন্টমেন্ট বুক করুন
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="dp-empty">
                            <div class="dp-empty-icon">🏥</div>
                            <h3>কোনো চেম্বার নেই</h3>
                            <p>এই ডাক্তারের কোনো চেম্বারের তথ্য পাওয়া যায়নি।</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT: Sidebar -->
            <div>
                <!-- Doctor Info -->
                <div class="dp-sidebar-card">
                    <h3 class="dp-section-title">
                        <span class="dp-section-title-icon" style="background:var(--dp-gradient)">ℹ️</span>
                        ডাক্তার তথ্য
                    </h3>
                    <div class="dp-info-list">
                        <div class="dp-info-item">
                            <div class="dp-info-icon">🩺</div>
                            <div>
                                <div class="dp-info-label">বিশেষজ্ঞতা</div>
                                <div class="dp-info-value"><?= htmlspecialchars($doctor['specialization_bn']) ?></div>
                            </div>
                        </div>
                        <?php if (!empty($doctor['specialization_en'])): ?>
                        <div class="dp-info-item">
                            <div class="dp-info-icon">🌐</div>
                            <div>
                                <div class="dp-info-label">Specialization</div>
                                <div class="dp-info-value"><?= htmlspecialchars($doctor['specialization_en']) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="dp-info-item">
                            <div class="dp-info-icon">⭐</div>
                            <div>
                                <div class="dp-info-label">রেটিং</div>
                                <div class="dp-info-value"><?= $doctor['avg_rating'] ? number_format($doctor['avg_rating'], 1) . ' / 5.0' : 'এখনো রেটিং নেই' ?></div>
                            </div>
                        </div>
                        <?php if (isset($doctor['experience_years']) && $doctor['experience_years'] > 0): ?>
                        <div class="dp-info-item">
                            <div class="dp-info-icon">⏳</div>
                            <div>
                                <div class="dp-info-label">অভিজ্ঞতা</div>
                                <div class="dp-info-value"><?= htmlspecialchars($doctor['experience_years']) ?> বছর</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>



                <!-- Qualifications -->
                <?php if (!empty($doctor['qualifications'])): ?>
                <div class="dp-sidebar-card">
                    <h3 class="dp-section-title">
                        <span class="dp-section-title-icon" style="background:linear-gradient(135deg,#f59e0b,#fbbf24)">🎓</span>
                        শিক্ষাগত যোগ্যতা
                    </h3>
                    <div class="dp-quals-box">
                        <?= nl2br(htmlspecialchars($doctor['qualifications'])) ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Bio / Identity -->
                <?php if (!empty($doctor['bio'])): ?>
                <div class="dp-sidebar-card">
                    <h3 class="dp-section-title">
                        <span class="dp-section-title-icon" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">👤</span>
                        পরিচয় / বায়ো
                    </h3>
                    <div class="dp-quals-box" style="border-left-color: var(--dp-accent); background: #f5f3ff;">
                        <?= nl2br(htmlspecialchars($doctor['bio'])) ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== REVIEWS SECTION (Full Width) ===== -->
        <div class="dp-reviews-section" style="margin-top:12px;">
            <h3 class="dp-section-title">
                <span class="dp-section-title-icon" style="background:linear-gradient(135deg,#f59e0b,#fbbf24)">📝</span>
                রিভিউ এবং রেটিং
                <span style="margin-left:auto;font-size:14px;font-weight:500;color:var(--dp-text-muted)"><?= $total_reviews_count ?>টি রিভিউ</span>
            </h3>

            <!-- Review Form -->
            <div class="dp-review-form">
                <h4>💬 আপনার অভিজ্ঞতা শেয়ার করুন</h4>
                <?php if(isset($_SESSION['user_id'])): ?>
                    <form id="review-form">
                        <input type="hidden" name="rateable_id" value="<?= $doctor_id ?>">
                        <input type="hidden" name="rateable_type" value="Doctor">
                        
                        <div class="dp-form-group">
                            <label>আপনার রেটিং</label>
                            <div class="dp-star-rating">
                                <input type="radio" id="star5" name="rating" value="5"><label for="star5">★</label>
                                <input type="radio" id="star4" name="rating" value="4"><label for="star4">★</label>
                                <input type="radio" id="star3" name="rating" value="3"><label for="star3">★</label>
                                <input type="radio" id="star2" name="rating" value="2"><label for="star2">★</label>
                                <input type="radio" id="star1" name="rating" value="1"><label for="star1">★</label>
                            </div>
                        </div>
                        
                        <div class="dp-form-group">
                            <label for="comment">আপনার মন্তব্য</label>
                            <textarea name="comment" rows="3" placeholder="ডাক্তার সম্পর্কে আপনার অভিজ্ঞতা লিখুন..." required></textarea>
                        </div>
                        
                        <button type="submit" class="dp-submit-btn"><i class="fas fa-paper-plane"></i> মতামত জমা দিন</button>
                        <div id="review-message"></div>
                    </form>
                <?php else: ?>
                    <div class="dp-login-prompt">
                        <p>রিভিউ দেওয়ার জন্য অনুগ্রহ করে <a href="login.php">লগ-ইন করুন</a> অথবা <a href="register.php">রেজিস্ট্রেশন করুন</a>।</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Reviews List -->
            <?php if(!empty($reviews)): ?>
                <div class="dp-reviews-list">
                    <?php foreach($reviews as $review): ?>
                        <div class="dp-review-item">
                            <div class="dp-reviewer-avatar"><?= mb_substr($review['reviewer_name'] ?? 'অ', 0, 1) ?></div>
                            <div class="dp-review-content">
                                <div class="dp-review-top">
                                    <span class="dp-reviewer-name"><?= htmlspecialchars($review['reviewer_name'] ?? 'বেনামে') ?></span>
                                    <span class="dp-review-date"><?= date("d M, Y", strtotime($review['created_at'])) ?></span>
                                </div>
                                <div class="dp-review-stars">
                                    <?php for($i=1; $i<=5; $i++): ?>
                                        <span style="color:<?= $i <= $review['rating'] ? '#f59e0b' : '#e2e8f0' ?>">★</span>
                                    <?php endfor; ?>
                                </div>
                                <p class="dp-review-comment">"<?= htmlspecialchars($review['comment']) ?>"</p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="dp-empty">
                    <div class="dp-empty-icon">💬</div>
                    <h3>কোনো রিভিউ নেই</h3>
                    <p>এই ডাক্তার সম্পর্কে এখনো কোনো রিভিউ জমা পড়েনি।</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const reviewForm = document.getElementById('review-form');
    if (reviewForm) {
        reviewForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const messageDiv = document.getElementById('review-message');
            
            if (!formData.get('rating')) {
                messageDiv.innerHTML = '<div class="dp-alert error">⚠️ অনুগ্রহ করে একটি স্টার রেটিং দিন।</div>';
                return;
            }

            fetch('submit_rating.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        messageDiv.innerHTML = '<div class="dp-alert success">✅ ' + data.success + '</div>';
                        this.reset();
                    } else {
                        messageDiv.innerHTML = '<div class="dp-alert error">⚠️ ' + (data.error || 'একটি ত্রুটি ঘটেছে।') + '</div>';
                    }
                }).catch(() => {
                    messageDiv.innerHTML = '<div class="dp-alert error">⚠️ সংযোগ ত্রুটি। আবার চেষ্টা করুন।</div>';
                });
        });
    }

    // Scroll animations
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15 });
    
    document.querySelectorAll('.dp-schedule-card, .dp-review-item, .dp-stat-card').forEach(el => {
        observer.observe(el);
    });
});
</script>

<?php
$stmt_profile->close();
$stmt_schedules->close();
$stmt_reviews->close();
include_once(BASE_PATH . 'includes/footer.php');
?>
