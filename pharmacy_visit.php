<?php
session_start();
require_once('includes/db_config.php');

$pharmacy_id = (int)($_GET['id'] ?? 0);

// Fetch pharmacy details
$stmt = $conn->prepare("
    SELECT 
        p.*,
        hb.branch_name,
        d.name as district,
        h.name as hospital_name
    FROM pharmacies p
    LEFT JOIN hospital_branches hb ON p.branch_id = hb.id
    LEFT JOIN hospitals h ON hb.hospital_id = h.id
    LEFT JOIN upazilas u ON hb.upazila_id = u.id
    LEFT JOIN districts d ON u.district_id = d.id
    WHERE p.id = ? AND p.status = 'Active'
");
$stmt->bind_param("i", $pharmacy_id);
$stmt->execute();
$pharmacy = $stmt->get_result()->fetch_assoc();

if (!$pharmacy) {
    die("Pharmacy not found or inactive.");
}

// Fetch available medicines
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';

$where_clauses = ["ps.pharmacy_id = ? AND ps.quantity > 0"];
$params = [$pharmacy_id];
$types = "i";

if (!empty($search)) {
    $where_clauses[] = "(m.name LIKE ? OR m.generic_name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if (!empty($category)) {
    $where_clauses[] = "m.category = ?";
    $params[] = $category;
    $types .= "s";
}

$where_sql = implode(' AND ', $where_clauses);

$sql = "
    SELECT 
        m.*,
        ps.quantity,
        ps.price_per_piece,
        ps.expiry_date
    FROM pharmacy_stock ps
    JOIN medicines m ON ps.medicine_id = m.id
    WHERE $where_sql
    ORDER BY m.name
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$medicines = $stmt->get_result();

// Get categories
$categories = $conn->query("
    SELECT DISTINCT m.category 
    FROM pharmacy_stock ps
    JOIN medicines m ON ps.medicine_id = m.id
    WHERE ps.pharmacy_id = $pharmacy_id AND ps.quantity > 0
    ORDER BY m.category
");

// Count medicines
$medicine_count = $medicines->num_rows;

// Determine type
$is_hospital = $pharmacy['pharmacy_type'] === 'Hospital';
$type_gradient = $is_hospital
    ? 'linear-gradient(135deg, #0ea5e9, #3b82f6, #6366f1)'
    : 'linear-gradient(135deg, #10b981, #059669, #047857)';
$type_color = $is_hospital ? '#3b82f6' : '#059669';
$type_bg = $is_hospital ? '#eff6ff' : '#ecfdf5';

include_once('includes/header.php');
?>

<style>
/* ==================== PHARMACY VISIT v2 — Premium Redesign ==================== */
@keyframes pv-fadeInUp { from { opacity:0; transform:translateY(25px); } to { opacity:1; transform:translateY(0); } }
@keyframes pv-fadeInScale { from { opacity:0; transform:scale(.93); } to { opacity:1; transform:scale(1); } }
@keyframes pv-float { 0%,100% { transform:translateY(0); } 50% { transform:translateY(-5px); } }

.pv-page { background:#f0f2f5; min-height:100vh; padding-bottom:60px; }

/* ===== HERO ===== */
.pv-hero {
    background: <?= $type_gradient ?>;
    position:relative; padding:45px 0 100px; overflow:hidden;
}
.pv-hero::before {
    content:''; position:absolute; inset:0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
}
.pv-hero::after {
    content:''; position:absolute; bottom:0; left:0; right:0; height:80px;
    background: linear-gradient(to bottom, transparent, #f0f2f5);
}
.pv-hero .container { position:relative; z-index:2; }

.pv-back-link {
    display:inline-flex; align-items:center; gap:8px;
    color:rgba(255,255,255,.85); text-decoration:none;
    font-weight:600; font-size:14px;
    padding:8px 16px; background:rgba(255,255,255,.15);
    border-radius:10px; backdrop-filter:blur(8px);
    border:1px solid rgba(255,255,255,.2);
    transition:all .3s ease; margin-bottom:24px;
}
.pv-back-link:hover { background:rgba(255,255,255,.25); color:#fff; }

.pv-hero-content { display:flex; align-items:center; gap:30px; animation:pv-fadeInUp .7s ease-out; }

.pv-hero-icon {
    width:100px; height:100px; border-radius:26px;
    background:rgba(255,255,255,.18); backdrop-filter:blur(12px);
    border:2px solid rgba(255,255,255,.25);
    display:flex; align-items:center; justify-content:center;
    font-size:48px; flex-shrink:0;
    box-shadow:0 8px 30px rgba(0,0,0,.12);
}

.pv-hero-info { flex:1; color:#fff; }
.pv-hero-badge {
    display:inline-flex; align-items:center; gap:6px;
    padding:6px 16px; background:rgba(255,255,255,.18);
    backdrop-filter:blur(8px); border:1px solid rgba(255,255,255,.25);
    border-radius:50px; font-size:12px; font-weight:700;
    color:#fff; text-transform:uppercase; letter-spacing:.5px;
    margin-bottom:12px;
}
.pv-hero-name { font-size:34px; font-weight:800; margin:0 0 8px; color:#fff; text-shadow:0 2px 10px rgba(0,0,0,.15); line-height:1.3; }
.pv-hero-sub { font-size:15px; color:rgba(255,255,255,.8); margin:0 0 4px; }
.pv-hero-loc { font-size:14px; color:rgba(255,255,255,.7); display:flex; align-items:center; gap:6px; margin:0; }

/* ===== STATS ===== */
.pv-stats-row {
    display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));
    gap:16px; margin-top:-55px; position:relative; z-index:5; margin-bottom:30px;
}
.pv-stat-card {
    background:#fff; border-radius:16px; padding:22px 20px;
    text-align:center; box-shadow:0 6px 24px rgba(0,0,0,.06);
    transition:all .35s ease; animation:pv-fadeInScale .5s ease-out backwards;
    position:relative; overflow:hidden; cursor:default;
}
.pv-stat-card:nth-child(1) { animation-delay:.1s }
.pv-stat-card:nth-child(2) { animation-delay:.2s }
.pv-stat-card:nth-child(3) { animation-delay:.3s }
.pv-stat-card:hover { transform:translateY(-4px); box-shadow:0 12px 30px rgba(0,0,0,.1); }
.pv-stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
.pv-stat-card:nth-child(1)::before { background:linear-gradient(90deg,#10b981,#34d399); }
.pv-stat-card:nth-child(2)::before { background:linear-gradient(90deg,#3b82f6,#60a5fa); }
.pv-stat-card:nth-child(3)::before { background:linear-gradient(90deg,#f59e0b,#fbbf24); }

.pv-stat-icon { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; margin:0 auto 10px; }
.pv-stat-card:nth-child(1) .pv-stat-icon { background:#ecfdf5; color:#10b981; }
.pv-stat-card:nth-child(2) .pv-stat-icon { background:#eff6ff; color:#3b82f6; }
.pv-stat-card:nth-child(3) .pv-stat-icon { background:#fffbeb; color:#f59e0b; }
.pv-stat-value { font-size:26px; font-weight:800; color:#1e293b; margin-bottom:2px; }
.pv-stat-label { font-size:11px; font-weight:600; color:#94a3b8; text-transform:uppercase; letter-spacing:.5px; }

/* ===== CONTENT GRID ===== */
.pv-content-grid { display:grid; grid-template-columns:1fr 340px; gap:24px; align-items:start; }

/* ===== SECTION CARD ===== */
.pv-section-card {
    background:#fff; border-radius:18px; padding:26px;
    box-shadow:0 4px 16px rgba(0,0,0,.05);
    animation:pv-fadeInUp .5s ease-out backwards;
}
.pv-section-title {
    display:flex; align-items:center; gap:12px;
    margin:0 0 20px; font-size:18px; font-weight:700; color:#1e293b;
    padding-bottom:14px; border-bottom:2px solid #f1f5f9;
}
.pv-section-title-icon {
    width:38px; height:38px; border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    font-size:17px; color:#fff; flex-shrink:0;
}

/* ===== SEARCH ===== */
.pv-search-form {
    display:grid; grid-template-columns:1fr auto auto;
    gap:12px; align-items:center; margin-bottom:20px;
}
.pv-search-input {
    position:relative;
}
.pv-search-input i { position:absolute; left:16px; top:50%; transform:translateY(-50%); color:<?= $type_color ?>; font-size:14px; }
.pv-search-input input {
    width:100%; padding:14px 16px 14px 44px;
    border:2px solid #e2e8f0; border-radius:12px;
    font-size:14px; font-weight:600; transition:all .3s ease;
    box-sizing:border-box;
}
.pv-search-input input:focus { outline:none; border-color:<?= $type_color ?>; box-shadow:0 0 0 4px <?= $is_hospital ? 'rgba(59,130,246,.1)' : 'rgba(5,150,105,.1)' ?>; }

.pv-search-form select {
    padding:14px 16px; border:2px solid #e2e8f0; border-radius:12px;
    font-size:14px; font-weight:600; background:#fff; cursor:pointer;
    transition:all .3s ease;
}
.pv-search-form select:focus { outline:none; border-color:<?= $type_color ?>; }

.pv-btn-filter {
    padding:14px 22px; background:<?= $type_gradient ?>; color:#fff;
    border:none; border-radius:12px; font-size:14px; font-weight:700;
    cursor:pointer; transition:all .3s ease;
    display:flex; align-items:center; gap:6px;
}
.pv-btn-filter:hover { transform:translateY(-2px); box-shadow:0 6px 20px <?= $is_hospital ? 'rgba(59,130,246,.3)' : 'rgba(5,150,105,.3)' ?>; }

/* ===== MEDICINE LIST ===== */
.pv-medicine-list { display:flex; flex-direction:column; gap:12px; }

.pv-med-item {
    display:flex; align-items:center; gap:18px;
    padding:20px; background:#f8fafc;
    border:1.5px solid #e2e8f0; border-radius:16px;
    transition:all .3s ease;
}
.pv-med-item:hover {
    background:#fff; border-color:<?= $type_color ?>22;
    transform:translateX(4px);
    box-shadow:0 6px 20px rgba(0,0,0,.06);
}

.pv-med-icon {
    width:54px; height:54px; border-radius:14px;
    background:<?= $type_bg ?>; color:<?= $type_color ?>;
    display:flex; align-items:center; justify-content:center;
    font-size:24px; flex-shrink:0;
    transition:all .3s ease;
}
.pv-med-item:hover .pv-med-icon { background:<?= $type_color ?>; color:#fff; }

.pv-med-details { flex:1; }
.pv-med-name { font-size:16px; font-weight:700; color:#1e293b; margin:0 0 4px; }
.pv-med-generic { font-size:13px; color:#64748b; margin:0 0 10px; }
.pv-med-tags { display:flex; flex-wrap:wrap; gap:6px; }
.pv-med-tag {
    padding:3px 10px; border-radius:6px;
    font-size:11px; font-weight:700; text-transform:uppercase;
}
.pv-med-tag.cat { background:#eff6ff; color:#3b82f6; }
.pv-med-tag.str { background:#f8fafc; color:#64748b; }
.pv-med-tag.mfg { background:#fefce8; color:#ca8a04; }

.pv-med-pricing { text-align:right; min-width:110px; }
.pv-med-price { font-size:22px; font-weight:800; color:<?= $type_color ?>; }
.pv-med-unit { font-size:11px; color:#94a3b8; margin-bottom:6px; }
.pv-med-stock {
    display:inline-flex; align-items:center; gap:5px;
    padding:4px 10px; background:#ecfdf5; color:#059669;
    border-radius:6px; font-size:11px; font-weight:700;
}

/* ===== SIDEBAR ===== */
.pv-sidebar-card {
    background:#fff; border-radius:18px; padding:24px;
    box-shadow:0 4px 16px rgba(0,0,0,.05);
    margin-bottom:20px;
    animation:pv-fadeInUp .5s ease-out backwards;
}

.pv-contact-list { display:flex; flex-direction:column; gap:12px; }
.pv-contact-item {
    display:flex; align-items:center; gap:12px;
    padding:14px 16px; background:#f8fafc;
    border-radius:12px; transition:all .3s ease;
}
.pv-contact-item:hover { background:<?= $type_bg ?>; transform:translateX(3px); }
.pv-contact-icon {
    width:40px; height:40px; border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    font-size:16px; flex-shrink:0;
    background:<?= $type_bg ?>; color:<?= $type_color ?>;
    transition:all .3s ease;
}
.pv-contact-item:hover .pv-contact-icon { background:<?= $type_color ?>; color:#fff; }
.pv-contact-label { font-size:11px; color:#94a3b8; font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
.pv-contact-value { font-size:14px; color:#1e293b; font-weight:600; margin-top:1px; }

.pv-delivery-btn {
    display:flex; align-items:center; justify-content:center; gap:10px;
    width:100%; padding:16px;
    background:linear-gradient(135deg,#ef4444,#dc2626);
    color:#fff; text-decoration:none;
    border-radius:14px; font-size:15px; font-weight:700;
    transition:all .3s ease;
    box-shadow:0 6px 20px rgba(239,68,68,.25);
}
.pv-delivery-btn:hover { transform:translateY(-3px); box-shadow:0 10px 30px rgba(239,68,68,.35); color:#fff; }

/* ===== EMPTY ===== */
.pv-empty { text-align:center; padding:50px 20px; color:#94a3b8; }
.pv-empty-icon { font-size:52px; margin-bottom:14px; animation:pv-float 3s ease-in-out infinite; }
.pv-empty h3 { margin:0 0 6px; color:#64748b; font-size:17px; }
.pv-empty p { margin:0; font-size:13px; }

/* ===== RESPONSIVE ===== */
@media(max-width:900px) { .pv-content-grid { grid-template-columns:1fr; } }
@media(max-width:600px) {
    .pv-hero { padding:30px 0 80px; }
    .pv-hero-content { flex-direction:column; text-align:center; gap:16px; }
    .pv-hero-icon { width:80px; height:80px; font-size:36px; border-radius:20px; }
    .pv-hero-name { font-size:26px; }
    .pv-hero-loc { justify-content:center; }
    .pv-stats-row { grid-template-columns:1fr 1fr 1fr; margin-top:-45px; }
    .pv-search-form { grid-template-columns:1fr; }
    .pv-med-item { flex-direction:column; text-align:center; }
    .pv-med-pricing { text-align:center; }
    .pv-med-tags { justify-content:center; }
}
</style>

<!-- ===== HERO ===== -->
<div class="pv-page">
    <div class="pv-hero">
        <div class="container">
            <a href="pharmacies.php" class="pv-back-link"><i class="fas fa-arrow-left"></i> ফার্মেসী তালিকায় ফিরুন</a>
            <div class="pv-hero-content">
                <div class="pv-hero-icon"><?= $is_hospital ? '🏥' : '💊' ?></div>
                <div class="pv-hero-info">
                    <span class="pv-hero-badge"><?= $is_hospital ? '🏥 Hospital Pharmacy' : '💊 Outside Pharmacy' ?></span>
                    <h1 class="pv-hero-name"><?= htmlspecialchars($pharmacy['name']) ?></h1>
                    <?php if($is_hospital): ?>
                        <p class="pv-hero-sub"><?= htmlspecialchars($pharmacy['hospital_name']) ?> — <?= htmlspecialchars($pharmacy['branch_name']) ?></p>
                        <p class="pv-hero-loc"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($pharmacy['district']) ?></p>
                    <?php else: ?>
                        <p class="pv-hero-loc"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($pharmacy['address']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- ===== STATS ===== -->
        <div class="pv-stats-row">
            <div class="pv-stat-card">
                <div class="pv-stat-icon">💊</div>
                <div class="pv-stat-value"><?= $medicine_count ?></div>
                <div class="pv-stat-label">ঔষধ পাওয়া যায়</div>
            </div>
            <div class="pv-stat-card">
                <div class="pv-stat-icon">📞</div>
                <div class="pv-stat-value"><?= htmlspecialchars($pharmacy['phone'] ?? '—') ?></div>
                <div class="pv-stat-label">যোগাযোগ</div>
            </div>
            <div class="pv-stat-card">
                <div class="pv-stat-icon">🏷️</div>
                <div class="pv-stat-value"><?= $is_hospital ? 'Hospital' : 'Outside' ?></div>
                <div class="pv-stat-label">ফার্মেসী ধরন</div>
            </div>
        </div>

        <!-- ===== CONTENT GRID ===== -->
        <div class="pv-content-grid">
            <!-- LEFT: Medicine List -->
            <div>
                <div class="pv-section-card">
                    <h3 class="pv-section-title">
                        <span class="pv-section-title-icon" style="background:<?= $type_gradient ?>">💊</span>
                        ঔষধের তালিকা
                    </h3>

                    <!-- Search -->
                    <form method="GET" class="pv-search-form">
                        <input type="hidden" name="id" value="<?= $pharmacy_id ?>">
                        <div class="pv-search-input">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" placeholder="ঔষধের নাম খুঁজুন..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <select name="category">
                            <option value="">সব ক্যাটাগরি</option>
                            <?php if($categories): while($cat = $categories->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($cat['category']) ?>" <?= $category === $cat['category'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['category']) ?>
                                </option>
                            <?php endwhile; endif; ?>
                        </select>
                        <button type="submit" class="pv-btn-filter">🔍 খুঁজুন</button>
                    </form>

                    <!-- Medicine List -->
                    <?php if($medicines && $medicines->num_rows > 0): ?>
                        <div class="pv-medicine-list">
                            <?php while($med = $medicines->fetch_assoc()): ?>
                                <div class="pv-med-item">
                                    <div class="pv-med-icon">💊</div>
                                    <div class="pv-med-details">
                                        <h4 class="pv-med-name"><?= htmlspecialchars($med['name']) ?></h4>
                                        <p class="pv-med-generic"><?= htmlspecialchars($med['generic_name']) ?></p>
                                        <div class="pv-med-tags">
                                            <span class="pv-med-tag cat"><?= htmlspecialchars($med['category']) ?></span>
                                            <span class="pv-med-tag str"><?= htmlspecialchars($med['strength']) ?></span>
                                            <span class="pv-med-tag mfg"><?= htmlspecialchars($med['manufacturer']) ?></span>
                                        </div>
                                    </div>
                                    <div class="pv-med-pricing">
                                        <div class="pv-med-price">৳<?= number_format($med['price_per_piece'], 2) ?></div>
                                        <div class="pv-med-unit">per <?= $med['unit_type'] ?></div>
                                        <div class="pv-med-stock">
                                            <i class="fas fa-check-circle"></i> Stock: <?= $med['quantity'] ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="pv-empty">
                            <div class="pv-empty-icon">📦</div>
                            <h3>কোনো ঔষধ পাওয়া যায়নি</h3>
                            <p>আপনার সার্চ বা ফিল্টার পরিবর্তন করে আবার চেষ্টা করুন</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT: Sidebar -->
            <div>
                <!-- Contact Info -->
                <div class="pv-sidebar-card">
                    <h3 class="pv-section-title">
                        <span class="pv-section-title-icon" style="background:<?= $type_gradient ?>">ℹ️</span>
                        যোগাযোগ
                    </h3>
                    <div class="pv-contact-list">
                        <div class="pv-contact-item">
                            <div class="pv-contact-icon">📞</div>
                            <div>
                                <div class="pv-contact-label">Phone</div>
                                <div class="pv-contact-value"><?= htmlspecialchars($pharmacy['phone']) ?></div>
                            </div>
                        </div>
                        <div class="pv-contact-item">
                            <div class="pv-contact-icon">✉️</div>
                            <div>
                                <div class="pv-contact-label">Email</div>
                                <div class="pv-contact-value"><?= htmlspecialchars($pharmacy['email']) ?></div>
                            </div>
                        </div>
                        <?php if(!empty($pharmacy['address'])): ?>
                        <div class="pv-contact-item">
                            <div class="pv-contact-icon">📍</div>
                            <div>
                                <div class="pv-contact-label">ঠিকানা</div>
                                <div class="pv-contact-value"><?= htmlspecialchars($pharmacy['address']) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Delivery Button (Outside pharmacies only) -->
                <?php if(!$is_hospital): ?>
                <div class="pv-sidebar-card">
                    <h3 class="pv-section-title">
                        <span class="pv-section-title-icon" style="background:linear-gradient(135deg,#ef4444,#dc2626)">🚚</span>
                        হোম ডেলিভারি
                    </h3>
                    <p style="font-size:14px;color:#64748b;margin:0 0 16px;line-height:1.6;">ঘরে বসে ঔষধ অর্ডার করুন। ক্যাশ অন ডেলিভারি।</p>
                    <a href="pharmacy_delivery.php?pharmacy_id=<?= $pharmacy_id ?>" class="pv-delivery-btn">
                        🚚 হোম ডেলিভারি অর্ডার করুন
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$stmt->close();
$conn->close();
include_once('includes/footer.php');
?>
