<?php
include_once('includes/db_config.php');
include_once('includes/header.php');

$search_term = isset($_GET['query']) ? trim($_GET['query']) : '';
$like_term = "%{$search_term}%";
$filter_type = $_GET['type'] ?? 'all';
$filter_specialization = $_GET['specialization'] ?? '';
$filter_district = $_GET['district'] ?? '';

// Get filter options
$specializations = $conn->query("SELECT id, name_bn FROM specializations ORDER BY name_bn")->fetch_all(MYSQLI_ASSOC);
$districts = $conn->query("SELECT id, name FROM districts ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>

<style>
/* ==================== SEARCH v2 — Premium Redesign ==================== */
@keyframes sv-fadeInUp { from { opacity:0; transform:translateY(25px); } to { opacity:1; transform:translateY(0); } }
@keyframes sv-fadeInScale { from { opacity:0; transform:scale(.93); } to { opacity:1; transform:scale(1); } }
@keyframes sv-float { 0%,100% { transform:translateY(0); } 50% { transform:translateY(-6px); } }

.sv-page { background:#f0f2f5; min-height:100vh; padding-bottom:60px; }

/* ===== HERO ===== */
.sv-hero {
    background: linear-gradient(135deg, #0f766e, #0d9488, #14b8a6);
    position:relative; padding:50px 0 110px; overflow:hidden;
}
.sv-hero::before {
    content:''; position:absolute; inset:0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
}
.sv-hero::after { content:''; position:absolute; bottom:0; left:0; right:0; height:90px; background:linear-gradient(to bottom, transparent, #f0f2f5); }
.sv-hero .container { position:relative; z-index:2; }

.sv-hero-content { text-align:center; animation:sv-fadeInUp .7s ease-out; }
.sv-hero-icon {
    width:70px; height:70px; border-radius:20px;
    background:rgba(255,255,255,.18); backdrop-filter:blur(10px);
    border:2px solid rgba(255,255,255,.25);
    display:inline-flex; align-items:center; justify-content:center;
    font-size:32px; margin-bottom:16px;
}
.sv-hero-title { font-size:36px; font-weight:800; color:#fff; margin:0 0 10px; text-shadow:0 2px 10px rgba(0,0,0,.15); }
.sv-hero-sub { font-size:16px; color:rgba(255,255,255,.85); margin:0; }

/* ===== SEARCH FORM ===== */
.sv-search-card {
    background:#fff; border-radius:20px; padding:28px;
    box-shadow:0 10px 40px rgba(0,0,0,.1);
    margin-top:-60px; position:relative; z-index:10;
    margin-bottom:30px;
    animation:sv-fadeInScale .5s ease-out;
}
.sv-search-form { display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end; }
.sv-form-group { flex:1; min-width:180px; }
.sv-form-group.main { flex:2.5; min-width:250px; }
.sv-form-label { display:block; margin-bottom:7px; color:#0f766e; font-weight:700; font-size:13px; text-transform:uppercase; letter-spacing:.3px; }
.sv-form-input-wrap { position:relative; }
.sv-form-input-wrap i { position:absolute; left:16px; top:50%; transform:translateY(-50%); color:#0d9488; font-size:14px; }
.sv-form-input-wrap input {
    width:100%; padding:14px 16px 14px 44px;
    border:2px solid #e2e8f0; border-radius:12px;
    font-size:15px; font-weight:600; transition:all .3s ease; box-sizing:border-box;
}
.sv-form-input-wrap input:focus { outline:none; border-color:#0d9488; box-shadow:0 0 0 4px rgba(13,148,136,.1); }
.sv-form-select {
    width:100%; padding:14px 16px; border:2px solid #e2e8f0;
    border-radius:12px; font-size:14px; font-weight:600;
    background:#fff; cursor:pointer; transition:all .3s ease;
}
.sv-form-select:focus { outline:none; border-color:#0d9488; box-shadow:0 0 0 4px rgba(13,148,136,.1); }
.sv-btn-search {
    padding:14px 30px; background:linear-gradient(135deg,#0f766e,#0d9488);
    color:#fff; border:none; border-radius:12px;
    font-size:15px; font-weight:700; cursor:pointer;
    transition:all .3s ease; display:flex; align-items:center; gap:8px;
    white-space:nowrap;
}
.sv-btn-search:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(13,148,136,.35); }

/* ===== RESULT SECTION ===== */
.sv-result-section {
    background:#fff; border-radius:20px; padding:28px;
    box-shadow:0 4px 18px rgba(0,0,0,.05);
    margin-bottom:24px;
    animation:sv-fadeInUp .5s ease-out backwards;
}
.sv-result-header {
    display:flex; align-items:center; gap:12px;
    margin:0 0 22px; padding-bottom:14px;
    border-bottom:2px solid #f1f5f9;
    font-size:19px; font-weight:700;
}
.sv-result-icon {
    width:40px; height:40px; border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    font-size:16px; color:#fff; flex-shrink:0;
}
.sv-result-count { margin-left:auto; font-size:13px; font-weight:600; color:#94a3b8; }

.sv-results-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:16px; }

/* ===== RESULT CARD ===== */
.sv-result-card {
    display:flex; gap:16px; align-items:center;
    padding:18px; background:#f8fafc;
    border:1.5px solid #e2e8f0; border-radius:16px;
    text-decoration:none; color:inherit;
    transition:all .3s ease;
}
.sv-result-card:hover {
    background:#fff; border-color:#0d948833;
    transform:translateY(-3px); box-shadow:0 8px 24px rgba(0,0,0,.07);
}
.sv-card-avatar {
    width:60px; height:60px; border-radius:16px;
    display:flex; align-items:center; justify-content:center;
    font-size:24px; flex-shrink:0;
    transition:all .3s ease;
}
.sv-card-avatar img { width:100%; height:100%; border-radius:16px; object-fit:cover; }
.sv-card-info { flex:1; }
.sv-card-name { font-size:15px; font-weight:700; color:#1e293b; margin:0 0 4px; }
.sv-card-sub { font-size:12px; color:#64748b; margin:0 0 8px; display:flex; align-items:center; gap:5px; }
.sv-card-tags { display:flex; flex-wrap:wrap; gap:6px; }
.sv-tag {
    padding:3px 10px; border-radius:50px;
    font-size:11px; font-weight:700;
}
.sv-tag.rating { background:#fffbeb; color:#b45309; }
.sv-tag.fee { background:#ecfdf5; color:#059669; }
.sv-tag.open { background:#ecfdf5; color:#059669; }
.sv-tag.closed { background:#fef2f2; color:#dc2626; }
.sv-tag.location { background:#f1f5f9; color:#64748b; }
.sv-tag.phone { background:#f1f5f9; color:#64748b; }
.sv-tag.price { background:#ecfdf5; color:#059669; font-size:15px; font-weight:800; }
.sv-tag.mfg { background:#fefce8; color:#ca8a04; }
.sv-tag.blood { background:#fef2f2; color:#dc2626; font-size:14px; font-weight:800; }

.sv-card-right { text-align:right; min-width:80px; }

/* ===== QUICK SEARCH ===== */
.sv-quick-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:16px; }
.sv-quick-card {
    padding:26px 18px; border-radius:18px;
    text-decoration:none; text-align:center;
    transition:all .35s ease; border:1.5px solid transparent;
}
.sv-quick-card:hover { transform:translateY(-6px); box-shadow:0 12px 30px rgba(0,0,0,.1); }
.sv-quick-icon {
    width:56px; height:56px; background:#fff;
    border-radius:50%; display:flex; align-items:center; justify-content:center;
    margin:0 auto 14px; box-shadow:0 4px 12px rgba(0,0,0,.08);
    font-size:24px; transition:all .3s ease;
}
.sv-quick-card:hover .sv-quick-icon { transform:scale(1.1); }
.sv-quick-label { font-size:14px; font-weight:700; }

/* doctor */
.sv-quick-card.doctor { background:linear-gradient(135deg,#ecfdf5,#d1fae5); } .sv-quick-card.doctor .sv-quick-label { color:#059669; }
.sv-quick-card.hospital { background:linear-gradient(135deg,#eff6ff,#dbeafe); } .sv-quick-card.hospital .sv-quick-label { color:#2563eb; }
.sv-quick-card.lab { background:linear-gradient(135deg,#faf5ff,#f3e8ff); } .sv-quick-card.lab .sv-quick-label { color:#7c3aed; }
.sv-quick-card.medicine { background:linear-gradient(135deg,#fffbeb,#fef3c7); } .sv-quick-card.medicine .sv-quick-label { color:#d97706; }
.sv-quick-card.blood { background:linear-gradient(135deg,#fef2f2,#fecaca); } .sv-quick-card.blood .sv-quick-label { color:#dc2626; }
.sv-quick-card.ambulance { background:linear-gradient(135deg,#fdf2f8,#fce7f3); } .sv-quick-card.ambulance .sv-quick-label { color:#be185d; }

/* ===== EMPTY ===== */
.sv-empty { text-align:center; padding:50px 20px; color:#94a3b8; }
.sv-empty-icon { font-size:52px; margin-bottom:14px; animation:sv-float 3s ease-in-out infinite; }
.sv-empty h3 { margin:0 0 6px; color:#64748b; font-size:17px; }
.sv-empty p { margin:0; font-size:13px; }

.sv-result-hint { margin-bottom:20px; color:#64748b; font-size:14px; }
.sv-result-hint strong { color:#0f766e; }

/* ===== RESPONSIVE ===== */
@media(max-width:768px) {
    .sv-hero { padding:35px 0 90px; }
    .sv-hero-title { font-size:28px; }
    .sv-search-form { flex-direction:column; }
    .sv-form-group, .sv-form-group.main { min-width:100%; }
    .sv-results-grid { grid-template-columns:1fr; }
    .sv-quick-grid { grid-template-columns:1fr 1fr; }
}
</style>

<div class="sv-page">
    <!-- ===== HERO ===== -->
    <div class="sv-hero">
        <div class="container">
            <div class="sv-hero-content">
                <div class="sv-hero-icon">🔍</div>
                <h1 class="sv-hero-title">উন্নত সার্চ</h1>
                <p class="sv-hero-sub">ডাক্তার, হাসপাতাল, ল্যাব টেস্ট, ঔষধ এবং রক্তদাতা খুঁজুন</p>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- ===== SEARCH FORM ===== -->
        <div class="sv-search-card">
            <form method="GET" class="sv-search-form">
                <div class="sv-form-group main">
                    <label class="sv-form-label"><i class="fas fa-search" style="margin-right:4px"></i> অনুসন্ধান</label>
                    <div class="sv-form-input-wrap">
                        <i class="fas fa-search"></i>
                        <input type="text" name="query" value="<?= htmlspecialchars($search_term) ?>" placeholder="ডাক্তার, হাসপাতাল, টেস্ট বা ঔষধের নাম লিখুন...">
                    </div>
                </div>
                <div class="sv-form-group">
                    <label class="sv-form-label"><i class="fas fa-filter" style="margin-right:4px"></i> ধরন</label>
                    <select name="type" class="sv-form-select">
                        <option value="all" <?= $filter_type === 'all' ? 'selected' : '' ?>>📋 সব</option>
                        <option value="doctor" <?= $filter_type === 'doctor' ? 'selected' : '' ?>>👨‍⚕️ ডাক্তার</option>
                        <option value="hospital" <?= $filter_type === 'hospital' ? 'selected' : '' ?>>🏥 হাসপাতাল</option>
                        <option value="lab_test" <?= $filter_type === 'lab_test' ? 'selected' : '' ?>>🧪 ল্যাব টেস্ট</option>
                        <option value="medicine" <?= $filter_type === 'medicine' ? 'selected' : '' ?>>💊 ঔষধ</option>
                        <option value="blood_donor" <?= $filter_type === 'blood_donor' ? 'selected' : '' ?>>🩸 রক্তদাতা</option>
                    </select>
                </div>
                <div class="sv-form-group">
                    <label class="sv-form-label"><i class="fas fa-stethoscope" style="margin-right:4px"></i> বিশেষত্ব</label>
                    <select name="specialization" class="sv-form-select">
                        <option value="">সব বিশেষত্ব</option>
                        <?php foreach ($specializations as $spec): ?>
                            <option value="<?= $spec['id'] ?>" <?= $filter_specialization == $spec['id'] ? 'selected' : '' ?>><?= htmlspecialchars($spec['name_bn']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sv-form-group">
                    <label class="sv-form-label"><i class="fas fa-map-marker-alt" style="margin-right:4px"></i> জেলা</label>
                    <select name="district" class="sv-form-select">
                        <option value="">সব জেলা</option>
                        <?php foreach ($districts as $dist): ?>
                            <option value="<?= $dist['id'] ?>" <?= $filter_district == $dist['id'] ? 'selected' : '' ?>><?= htmlspecialchars($dist['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="sv-btn-search"><i class="fas fa-search"></i> খুঁজুন</button>
            </form>
        </div>

        <?php if (!empty($search_term) || $filter_type !== 'all'): ?>
            <p class="sv-result-hint">"<strong><?= htmlspecialchars($search_term) ?></strong>" এর জন্য ফলাফল</p>

            <?php if ($filter_type === 'all' || $filter_type === 'doctor'): ?>
            <?php
            $sql = "SELECT vd.id, vd.full_name, vd.profile_image, s.name_bn as specialization, h.name as hospital_name, AVG(r.rating) as avg_rating, MIN(ds.consultation_fee) as consultation_fee FROM vw_verified_doctors vd JOIN specializations s ON vd.specialization_id = s.id LEFT JOIN doctor_schedules ds ON vd.id = ds.doctor_id LEFT JOIN hospital_branches hb ON ds.branch_id = hb.id LEFT JOIN hospitals h ON hb.hospital_id = h.id LEFT JOIN ratings r ON vd.id = r.rateable_id AND r.rateable_type = 'Doctor' WHERE 1=1";
            $params = []; $types = '';
            if (!empty($search_term)) { $sql .= " AND (vd.full_name LIKE ? OR s.name_bn LIKE ? OR s.name_en LIKE ?)"; $params[] = $like_term; $params[] = $like_term; $params[] = $like_term; $types .= 'sss'; }
            if ($filter_specialization) { $sql .= " AND vd.specialization_id = ?"; $params[] = $filter_specialization; $types .= 'i'; }
            $sql .= " GROUP BY vd.id ORDER BY avg_rating DESC LIMIT 10";
            $stmt = $conn->prepare($sql);
            if (!empty($params)) { $stmt->bind_param($types, ...$params); }
            $stmt->execute();
            $doctors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            ?>
            <div id="search-skeleton" class="sv-results-grid" style="display: none;">
                <?php for($i=0; $i<6; $i++): ?>
                    <div class="sv-result-card skeleton">
                        <div class="sv-card-avatar" style="width:60px; height:60px; border-radius:12px;"></div>
                        <div class="sv-card-info">
                            <div style="height:18px; width:70%; margin-bottom:8px;" class="skeleton"></div>
                            <div style="height:14px; width:50%;" class="skeleton"></div>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>

            <?php if (!empty($doctors)): ?>
            <div class="sv-result-section">
                <h3 class="sv-result-header">
                    <span class="sv-result-icon" style="background:linear-gradient(135deg,#059669,#10b981)"><i class="fas fa-user-md"></i></span>
                    ডাক্তার
                    <span class="sv-result-count"><?= count($doctors) ?> জন</span>
                </h3>
                <div class="sv-results-grid">
                    <?php foreach ($doctors as $doc): ?>
                    <?php
                    $profileImg = $doc['profile_image'] ?? ''; $imgSrc = '';
                    if ($profileImg) { if (file_exists($profileImg)) $imgSrc = $profileImg; elseif (file_exists('uploads/users/'.$profileImg)) $imgSrc = 'uploads/users/'.$profileImg; elseif (file_exists('uploads/profile_pics/'.$profileImg)) $imgSrc = 'uploads/profile_pics/'.$profileImg; }
                    ?>
                    <a href="doctor_profile.php?id=<?= $doc['id'] ?>" class="sv-result-card">
                        <div class="sv-card-avatar" style="background:linear-gradient(135deg,#ecfdf5,#d1fae5);color:#059669;">
                            <?php if ($imgSrc): ?>
                                <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($doc['full_name']) ?>">
                            <?php else: ?>
                                <i class="fas fa-user-md"></i>
                            <?php endif; ?>
                        </div>
                        <div class="sv-card-info">
                            <p class="sv-card-name"><?= htmlspecialchars($doc['full_name']) ?></p>
                            <p class="sv-card-sub"><i class="fas fa-stethoscope"></i> <?= htmlspecialchars($doc['specialization']) ?></p>
                            <div class="sv-card-tags">
                                <?php if ($doc['avg_rating']): ?><span class="sv-tag rating">⭐ <?= number_format($doc['avg_rating'], 1) ?></span><?php endif; ?>
                                <?php if ($doc['consultation_fee']): ?><span class="sv-tag fee">৳<?= $doc['consultation_fee'] ?></span><?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; endif; ?>

            <?php if ($filter_type === 'all' || $filter_type === 'hospital'): ?>
            <?php
            $sql = "SELECT h.id, h.name, hb.id as branch_id, hb.branch_name, hb.address, hb.hotline, d.name as district_name, (SELECT COUNT(*) FROM branch_availability ba WHERE ba.branch_id = hb.id AND ba.day_of_week = DAYNAME(CURRENT_DATE) AND (ba.is_24_hours = 1 OR (ba.is_open = 1 AND CURRENT_TIME BETWEEN ba.opening_time AND ba.closing_time))) AS is_currently_open FROM hospitals h JOIN hospital_branches hb ON h.id = hb.hospital_id LEFT JOIN upazilas up ON hb.upazila_id = up.id LEFT JOIN districts d ON up.district_id = d.id WHERE h.status = 'Active' AND hb.status = 'Active' AND h.deleted_at IS NULL AND hb.deleted_at IS NULL";
            $params = []; $types = '';
            if (!empty($search_term)) { $sql .= " AND (h.name LIKE ? OR hb.branch_name LIKE ? OR hb.address LIKE ?)"; $params[] = $like_term; $params[] = $like_term; $params[] = $like_term; $types .= 'sss'; }
            if ($filter_district) { $sql .= " AND d.id = ?"; $params[] = $filter_district; $types .= 'i'; }
            $sql .= " LIMIT 10";
            $stmt = $conn->prepare($sql);
            if (!empty($params)) { $stmt->bind_param($types, ...$params); }
            $stmt->execute();
            $hospitals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            ?>
            <?php if (!empty($hospitals)): ?>
            <div class="sv-result-section">
                <h3 class="sv-result-header">
                    <span class="sv-result-icon" style="background:linear-gradient(135deg,#2563eb,#3b82f6)"><i class="fas fa-hospital"></i></span>
                    হাসপাতাল
                    <span class="sv-result-count"><?= count($hospitals) ?> টি</span>
                </h3>
                <div class="sv-results-grid">
                    <?php foreach ($hospitals as $hosp): ?>
                    <a href="hospital_profile.php?id=<?= $hosp['id'] ?>" class="sv-result-card">
                        <div class="sv-card-avatar" style="background:linear-gradient(135deg,#eff6ff,#dbeafe);color:#2563eb;">
                            <i class="fas fa-hospital"></i>
                        </div>
                        <div class="sv-card-info">
                            <p class="sv-card-name"><?= htmlspecialchars($hosp['name']) ?></p>
                            <p class="sv-card-sub"><?= htmlspecialchars($hosp['branch_name']) ?></p>
                            <div class="sv-card-tags">
                                <?php if ($hosp['is_currently_open']): ?><span class="sv-tag open">✓ Open</span><?php else: ?><span class="sv-tag closed">✕ Closed</span><?php endif; ?>
                                <?php if ($hosp['district_name']): ?><span class="sv-tag location">📍 <?= htmlspecialchars($hosp['district_name']) ?></span><?php endif; ?>
                                <?php if ($hosp['hotline']): ?><span class="sv-tag phone">📞 <?= htmlspecialchars($hosp['hotline']) ?></span><?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; endif; ?>

            <?php if ($filter_type === 'all' || $filter_type === 'lab_test'): ?>
            <?php
            $sql = "SELECT lt.id, lt.test_name as name, blt.price, h.name as hospital_name FROM vw_active_lab_tests lt JOIN branch_lab_tests blt ON lt.id = blt.test_id LEFT JOIN hospital_branches hb ON blt.branch_id = hb.id LEFT JOIN hospitals h ON hb.hospital_id = h.id WHERE blt.is_available = 1";
            $params = []; $types = '';
            if (!empty($search_term)) { $sql .= " AND (lt.test_name LIKE ? OR lt.test_code LIKE ?)"; $params[] = $like_term; $params[] = $like_term; $types .= 'ss'; }
            $sql .= " ORDER BY blt.price ASC LIMIT 10";
            $stmt = $conn->prepare($sql);
            if (!empty($params)) { $stmt->bind_param($types, ...$params); }
            $stmt->execute();
            $tests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            ?>
            <?php if (!empty($tests)): ?>
            <div class="sv-result-section">
                <h3 class="sv-result-header">
                    <span class="sv-result-icon" style="background:linear-gradient(135deg,#7c3aed,#8b5cf6)"><i class="fas fa-flask"></i></span>
                    ল্যাব টেস্ট
                    <span class="sv-result-count"><?= count($tests) ?> টি</span>
                </h3>
                <div class="sv-results-grid">
                    <?php foreach ($tests as $test): ?>
                    <a href="book_lab_test.php?test_id=<?= $test['id'] ?>" class="sv-result-card">
                        <div class="sv-card-avatar" style="background:linear-gradient(135deg,#faf5ff,#f3e8ff);color:#7c3aed;">🧪</div>
                        <div class="sv-card-info">
                            <p class="sv-card-name"><?= htmlspecialchars($test['name']) ?></p>
                            <p class="sv-card-sub">🏥 <?= htmlspecialchars($test['hospital_name'] ?? 'Multiple Locations') ?></p>
                        </div>
                        <div class="sv-card-right"><span class="sv-tag price">৳<?= number_format($test['price']) ?></span></div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; endif; ?>

            <?php if ($filter_type === 'all' || $filter_type === 'medicine'): ?>
            <?php
            if (!empty($search_term)) {
                $stmt = $conn->prepare("SELECT id, name, generic_name, manufacturer FROM medicines WHERE name LIKE ? OR generic_name LIKE ? LIMIT 20");
                $stmt->bind_param("ss", $like_term, $like_term); $stmt->execute();
                $medicines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            } elseif ($filter_type === 'medicine') {
                $medicines = $conn->query("SELECT id, name, generic_name, manufacturer FROM medicines ORDER BY name LIMIT 20")->fetch_all(MYSQLI_ASSOC);
            } else { $medicines = []; }
            ?>
            <?php if (!empty($medicines)): ?>
            <div class="sv-result-section">
                <h3 class="sv-result-header">
                    <span class="sv-result-icon" style="background:linear-gradient(135deg,#d97706,#f59e0b)"><i class="fas fa-pills"></i></span>
                    ঔষধ
                    <span class="sv-result-count"><?= count($medicines) ?> টি</span>
                </h3>
                <div class="sv-results-grid">
                    <?php foreach ($medicines as $med): ?>
                    <div class="sv-result-card">
                        <div class="sv-card-avatar" style="background:linear-gradient(135deg,#fffbeb,#fef3c7);color:#d97706;">💊</div>
                        <div class="sv-card-info">
                            <p class="sv-card-name"><?= htmlspecialchars($med['name']) ?></p>
                            <p class="sv-card-sub">💊 জেনেরিক: <?= htmlspecialchars($med['generic_name'] ?? 'N/A') ?></p>
                            <div class="sv-card-tags">
                                <?php if ($med['manufacturer']): ?><span class="sv-tag mfg"><?= htmlspecialchars($med['manufacturer']) ?></span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; endif; ?>

            <?php if ($filter_type === 'blood_donor'): ?>
            <?php
            $blood_groups = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
            $found_group = null;
            foreach ($blood_groups as $group) { if (stripos($search_term, $group) !== false) { $found_group = $group; break; } }
            $donors = [];
            if ($found_group) {
                $stmt = $conn->prepare("SELECT u.full_name, u.phone, u.blood_group, d.name as district_name FROM users u LEFT JOIN districts d ON u.district_id = d.id WHERE u.blood_group = ? AND u.is_donor = 1 AND u.donor_availability = 'Available' AND u.is_active = 1 LIMIT 20");
                $stmt->bind_param("s", $found_group); $stmt->execute();
                $donors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            } else {
                $donors = $conn->query("SELECT u.full_name, u.phone, u.blood_group, d.name as district_name FROM users u LEFT JOIN districts d ON u.district_id = d.id WHERE u.is_donor = 1 AND u.donor_availability = 'Available' AND u.is_active = 1 ORDER BY u.blood_group LIMIT 30")->fetch_all(MYSQLI_ASSOC);
            }
            ?>
            <?php if (!empty($donors)): ?>
            <div class="sv-result-section">
                <h3 class="sv-result-header">
                    <span class="sv-result-icon" style="background:linear-gradient(135deg,#dc2626,#ef4444)"><i class="fas fa-tint"></i></span>
                    <?= $found_group ? $found_group . ' রক্তদাতা' : 'সকল রক্তদাতা' ?>
                    <span class="sv-result-count"><?= count($donors) ?> জন</span>
                </h3>
                <div class="sv-results-grid">
                    <?php foreach ($donors as $donor): ?>
                    <div class="sv-result-card">
                        <div class="sv-card-avatar" style="background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;font-weight:800;font-size:16px;">
                            <?= $donor['blood_group'] ?>
                        </div>
                        <div class="sv-card-info">
                            <p class="sv-card-name"><?= htmlspecialchars($donor['full_name']) ?></p>
                            <p class="sv-card-sub">📍 <?= htmlspecialchars($donor['district_name'] ?? 'N/A') ?></p>
                            <div class="sv-card-tags">
                                <a href="tel:<?= $donor['phone'] ?>" class="sv-tag fee" style="text-decoration:none;">📞 <?= htmlspecialchars($donor['phone']) ?></a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="sv-result-section">
                <div class="sv-empty">
                    <div class="sv-empty-icon">🩸</div>
                    <h3>কোনো রক্তদাতা পাওয়া যায়নি</h3>
                    <p>নির্দিষ্ট গ্রুপ খুঁজতে সার্চে লিখুন: A+, B-, O+ ইত্যাদি</p>
                </div>
            </div>
            <?php endif; endif; ?>

        <?php else: ?>
            <!-- ===== QUICK SEARCH ===== -->
            <div class="sv-result-section">
                <h3 class="sv-result-header">
                    <span class="sv-result-icon" style="background:linear-gradient(135deg,#0f766e,#14b8a6)">🧭</span>
                    দ্রুত অনুসন্ধান করুন
                </h3>
                <div class="sv-quick-grid">
                    <a href="?type=doctor" class="sv-quick-card doctor">
                        <div class="sv-quick-icon">👨‍⚕️</div>
                        <span class="sv-quick-label">ডাক্তার খুঁজুন</span>
                    </a>
                    <a href="?type=hospital" class="sv-quick-card hospital">
                        <div class="sv-quick-icon">🏥</div>
                        <span class="sv-quick-label">হাসপাতাল খুঁজুন</span>
                    </a>
                    <a href="?type=lab_test" class="sv-quick-card lab">
                        <div class="sv-quick-icon">🧪</div>
                        <span class="sv-quick-label">ল্যাব টেস্ট</span>
                    </a>
                    <a href="?type=medicine&query=napa" class="sv-quick-card medicine">
                        <div class="sv-quick-icon">💊</div>
                        <span class="sv-quick-label">ঔষধ খুঁজুন</span>
                    </a>
                    <a href="?type=blood_donor&query=O+" class="sv-quick-card blood">
                        <div class="sv-quick-icon">🩸</div>
                        <span class="sv-quick-label">রক্তদাতা খুঁজুন</span>
                    </a>
                    <a href="book_ambulance_v2.php" class="sv-quick-card ambulance">
                        <div class="sv-quick-icon">🚑</div>
                        <span class="sv-quick-label">অ্যাম্বুলেন্স</span>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include_once('includes/footer.php'); ?>
