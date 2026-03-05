<?php
include_once('includes/db_config.php');
include_once('includes/header.php');

// --- Fetch data for filters ---
// --- Fetch data for filters ---
$districts_stmt = $conn->prepare("SELECT id, name FROM districts ORDER BY name ASC");
$districts_stmt->execute();
$districts = $districts_stmt->get_result();

// --- Get actual statistics ---
$hospital_count = $conn->query("SELECT COUNT(DISTINCT h.id) as count FROM hospitals h JOIN hospital_branches hb ON h.id = hb.hospital_id WHERE h.status = 'Active' AND hb.status = 'Active'")->fetch_assoc()['count'];
$district_count = $conn->query("SELECT COUNT(*) as count FROM districts")->fetch_assoc()['count'];
$doctor_count = $conn->query("SELECT COUNT(*) as count FROM doctors WHERE is_verified = 'Verified'")->fetch_assoc()['count'];

// --- UPDATED SQL: Join with doctor schedules to get actual ratings and doctor count ---
$sql = "
    SELECT
        h.id AS hospital_id,
        h.name AS hospital_name,
        h.hospital_type,
        hb.id AS branch_id,
        hb.branch_name,
        hb.address,
        hb.hotline,
        d.name AS district_name,
        COUNT(DISTINCT doc.id) AS doctor_count,
        AVG(r.rating) AS avg_rating,
        COUNT(DISTINCT r.id) AS total_reviews,
        MAX(CASE WHEN ba.day_of_week = DAYNAME(CURRENT_DATE) 
                 AND (ba.is_24_hours = 1 OR (ba.is_open = 1 AND CURRENT_TIME BETWEEN ba.opening_time AND ba.closing_time))
            THEN 1 ELSE 0 END) AS is_currently_open,
        MAX(CASE WHEN ba.day_of_week = DAYNAME(CURRENT_DATE) AND ba.is_open = 1 
            THEN CONCAT(ba.opening_time, '-', ba.closing_time) END) AS todays_hours,
        MAX(CASE WHEN ba.day_of_week = DAYNAME(CURRENT_DATE) 
            THEN ba.is_24_hours END) AS is_24_hours
    FROM hospitals h
    JOIN hospital_branches hb ON h.id = hb.hospital_id
    LEFT JOIN upazilas u ON hb.upazila_id = u.id
    LEFT JOIN districts d ON u.district_id = d.id
    LEFT JOIN doctor_schedules ds ON ds.branch_id = hb.id AND ds.deleted_at IS NULL
    LEFT JOIN doctors doc ON doc.id = ds.doctor_id AND doc.is_verified = 'Verified'
    LEFT JOIN ratings r ON r.rateable_id = hb.id AND r.rateable_type = 'Branch'
    LEFT JOIN branch_availability ba ON ba.branch_id = hb.id
    WHERE h.status = 'Active' AND hb.status = 'Active'
";

// Base conditions: The view already filters for active hospitals and branches
$where_clauses = [];
$params = [];
$types = '';

// Add user filters conditionally
if (!empty($_GET['district_id'])) {
    $district_id = (int)$_GET['district_id'];
    $where_clauses[] = "d.id = ?";
    $params[] = $district_id;
    $types .= 'i';
}
if (!empty($_GET['hospital_type'])) {
    $hospital_type = $_GET['hospital_type'];
    $where_clauses[] = "h.hospital_type = ?";
    $params[] = $hospital_type;
    $types .= 's';
}
if (!empty($_GET['search'])) {
    $search_term = $_GET['search'];
    // Search in both hospital name and branch name
    $where_clauses[] = "(h.name LIKE CONCAT('%', ?, '%') OR hb.branch_name LIKE CONCAT('%', ?, '%'))";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ss';
}

// Append all WHERE conditions only if there are any
if (!empty($where_clauses)) {
    $sql .= " AND " . implode(' AND ', $where_clauses);
}

$sql .= " GROUP BY h.id, h.name, h.hospital_type, hb.id, hb.branch_name, hb.address, hb.hotline, d.name ORDER BY h.name ASC, hb.branch_name ASC";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$branches_result = $stmt->get_result();

if (!$branches_result) {
    die("SQL Error: " . $conn->error);
}
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    .select2-container { width: 100% !important; }
    .select2-container--default .select2-selection--single { 
        height: 52px !important; 
        border: 2px solid #e9ecef !important; 
        border-radius: 12px !important; 
        padding: 12px 15px;
        background: #f8f9fa;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow { 
        height: 50px !important; 
        right: 10px;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 28px !important;
        font-size: 15px;
        color: #495057;
    }
    
    /* Hero Section */
    .hospital-hero {
        background: linear-gradient(135deg, #0d6efd 0%, #084298 50%, #063d9a 100%);
        padding: 60px 20px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    
    .hospital-hero::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect x="20" y="20" width="60" height="60" rx="10" fill="rgba(255,255,255,0.05)"/></svg>');
        background-size: 80px;
        animation: float 20s infinite linear;
    }
    
    @keyframes float {
        0% { transform: translateY(0) rotate(0deg); }
        100% { transform: translateY(-80px) rotate(360deg); }
    }
    
    .hospital-hero-content {
        position: relative;
        z-index: 1;
        max-width: 700px;
        margin: 0 auto;
    }
    
    .hospital-hero-icon {
        width: 100px;
        height: 100px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 25px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 25px;
        backdrop-filter: blur(10px);
        border: 3px solid rgba(255, 255, 255, 0.3);
        animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
        0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.4); }
        50% { transform: scale(1.05); box-shadow: 0 0 0 20px rgba(255, 255, 255, 0); }
    }
    
    .hospital-hero-icon i {
        font-size: 45px;
        color: white;
    }
    
    .hospital-hero h1 {
        color: white;
        font-size: 42px;
        font-weight: 800;
        margin: 0 0 15px;
        text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    }
    
    .hospital-hero p {
        color: rgba(255, 255, 255, 0.95);
        font-size: 18px;
        margin: 0;
    }

    /* Stats Section */
    .stats-section {
        padding: 30px 20px 50px;
        background: linear-gradient(180deg, #f1f3f5 0%, #ffffff 100%);
    }
    
    .stats-container {
        max-width: 1100px;
        margin: 0 auto;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 25px;
    }
    
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 25px 20px;
        text-align: center;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    
    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 5px;
        background: linear-gradient(90deg, #0d6efd, #6ea8fe);
    }
    
    .stat-card:nth-child(2)::before { background: linear-gradient(90deg, #198754, #20c997); }
    .stat-card:nth-child(3)::before { background: linear-gradient(90deg, #dc3545, #f8d7da); }
    .stat-card:nth-child(4)::before { background: linear-gradient(90deg, #fd7e14, #ffc107); }
    
    .stat-card:hover {
        transform: translateY(-10px);
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.12);
    }
    
    .stat-icon {
        width: 70px;
        height: 70px;
        border-radius: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 18px;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    }
    
    .stat-card:nth-child(1) .stat-icon { background: linear-gradient(135deg, #0d6efd, #084298); }
    .stat-card:nth-child(2) .stat-icon { background: linear-gradient(135deg, #198754, #20c997); }
    .stat-card:nth-child(3) .stat-icon { background: linear-gradient(135deg, #dc3545, #f8d7da); }
    .stat-card:nth-child(4) .stat-icon { background: linear-gradient(135deg, #fd7e14, #ffc107); }
    
    .stat-icon i {
        font-size: 30px;
        color: white;
    }
    
    .stat-number {
        font-size: 42px;
        font-weight: 800;
        margin-bottom: 5px;
    }
    
    .stat-card:nth-child(1) .stat-number { background: linear-gradient(135deg, #0d6efd, #084298); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    .stat-card:nth-child(2) .stat-number { background: linear-gradient(135deg, #198754, #20c997); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    .stat-card:nth-child(3) .stat-number { background: linear-gradient(135deg, #dc3545, #f8d7da); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    .stat-card:nth-child(4) .stat-number { background: linear-gradient(135deg, #fd7e14, #ffc107); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    
    .stat-label {
        color: #6c757d;
        font-size: 14px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    /* Search Section */
    .search-section {
        padding: 50px 20px;
        background: linear-gradient(180deg, #e9ecef 0%, #ffffff 50%);
    }
    
    .search-container {
        max-width: 900px;
        margin: 0 auto;
    }
    
    .search-card {
        background: white;
        border-radius: 25px;
        padding: 40px;
        box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
        border: 1px solid #f0f0f0;
    }
    
    .search-card h3 {
        color: #212529;
        font-size: 24px;
        font-weight: 700;
        margin-bottom: 30px;
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
    }
    
    .search-card h3 i {
        color: #0d6efd;
    }
    
    .search-form-grid {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr;
        gap: 20px;
        align-items: end;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 10px;
        font-weight: 600;
        color: #495057;
        font-size: 14px;
    }
    
    .form-group input,
    .form-group select {
        width: 100%;
        height: 52px;
        padding: 12px 20px;
        border: 2px solid #e9ecef;
        border-radius: 12px;
        font-size: 15px;
        background: #f8f9fa;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .form-group input:focus,
    .form-group select:focus {
        outline: none;
        border-color: #0d6efd;
        background: white;
        box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.1);
    }
    
    .btn-search {
        padding: 0 25px;
        background: linear-gradient(135deg, #0d6efd 0%, #084298 100%);
        color: white;
        border: none;
        border-radius: 12px;
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: all 0.3s ease;
        height: 52px;
    }
    
    .btn-search:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(13, 110, 253, 0.4);
    }

    /* Results Section */
    .results-section {
        padding: 50px 20px;
        background: linear-gradient(180deg, #f8f9fa 0%, #ffffff 100%);
    }
    
    .results-container {
        max-width: 1200px;
        margin: 0 auto;
    }
    
    .results-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        padding: 15px 25px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }
    
    .results-header h4 {
        color: #212529;
        font-size: 20px;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .results-header h4 i {
        color: #198754;
    }
    
    .count-badge {
        background: linear-gradient(135deg, #0d6efd, #084298);
        color: white;
        padding: 10px 22px;
        border-radius: 30px;
        font-weight: 700;
        font-size: 14px;
        box-shadow: 0 5px 20px rgba(13, 110, 253, 0.3);
    }
    
    .results-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: 25px;
    }
    
    .hospital-card {
        background: white;
        border-radius: 16px;
        padding: 25px;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    
    .hospital-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(90deg, #0d6efd, #6ea8fe);
    }
    
    .hospital-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.12);
    }
    
    .hospital-type-badge {
        display: inline-block;
        padding: 6px 16px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 700;
        margin-bottom: 15px;
    }
    
    .hospital-type-badge.government {
        background: linear-gradient(135deg, #198754, #20c997);
        color: white;
    }
    
    .hospital-type-badge.private {
        background: linear-gradient(135deg, #0d6efd, #6ea8fe);
        color: white;
    }

    .live-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 700;
        margin-bottom: 10px;
    }
    
    .live-status-badge.open {
        background: #ecfdf5;
        color: #10b981;
    }
    
    .live-status-badge.closed {
        background: #fef2f2;
        color: #ef4444;
    }
    
    .status-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: currentColor;
    }
    
    .live-status-badge.open .status-dot {
        animation: pulse-dot 2s infinite;
    }
    
    @keyframes pulse-dot {
        0% { transform: scale(0.95); opacity: 1; }
        50% { transform: scale(1.2); opacity: 0.7; }
        100% { transform: scale(0.95); opacity: 1; }
    }
    
    .hospital-header-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
    }
    
    .hospital-name {
        font-size: 18px;
        font-weight: 700;
        color: #212529;
        margin: 0 0 5px 0;
    }
    
    .hospital-branch {
        font-size: 14px;
        color: #6c757d;
        margin: 0 0 12px 0;
    }
    
    .hospital-rating {
        display: flex;
        align-items: center;
        gap: 6px;
        background: #fff3cd;
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
    }
    
    .hospital-rating span {
        color: #6c757d;
        font-weight: 400;
    }
    
    .hospital-address {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        margin-bottom: 12px;
        color: #6c757d;
        font-size: 14px;
    }
    
    .hospital-address i {
        color: #0d6efd;
        margin-top: 3px;
    }
    
    .hospital-meta {
        margin-bottom: 15px;
    }
    
    .doctor-count {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        background: #e7f1ff;
        color: #0d6efd;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
    }
    
    .doctor-count i {
        font-size: 12px;
    }
    
    .hospital-footer {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }
    
    .btn-hospital {
        flex: 1;
        min-width: 140px;
        padding: 14px 20px;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 700;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: all 0.3s ease;
    }
    
    .btn-hotline {
        background: linear-gradient(135deg, #198754, #20c997);
        color: white;
        box-shadow: 0 5px 20px rgba(25, 135, 84, 0.3);
    }
    
    .btn-details {
        background: linear-gradient(135deg, #0d6efd, #084298);
        color: white;
        box-shadow: 0 5px 20px rgba(13, 110, 253, 0.3);
    }
    
    .btn-hospital:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 80px 40px;
        background: white;
        border-radius: 25px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
    }
    
    .empty-state i {
        font-size: 80px;
        color: #dee2e6;
        margin-bottom: 25px;
    }
    
    .empty-state h4 {
        color: #212529;
        font-size: 24px;
        margin-bottom: 12px;
    }
    
    .empty-state p {
        color: #6c757d;
        font-size: 16px;
    }

    /* CTA Section */
    .cta-section {
        padding: 60px 20px;
        background: linear-gradient(135deg, #212529 0%, #343a40 100%);
        text-align: center;
    }
    
    .cta-section h3 {
        color: white;
        font-size: 28px;
        margin-bottom: 15px;
    }
    
    .cta-section p {
        color: rgba(255, 255, 255, 0.8);
        font-size: 16px;
        margin-bottom: 30px;
    }
    
    .btn-cta {
        display: inline-block;
        padding: 16px 40px;
        background: linear-gradient(135deg, #0d6efd, #084298);
        color: white;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 700;
        font-size: 16px;
        transition: all 0.3s ease;
    }
    
    .btn-cta:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(13, 110, 253, 0.4);
    }

    @media (max-width: 768px) {
        .hospital-hero { padding: 40px 20px; }
        .hospital-hero h1 { font-size: 28px; }
        .hospital-hero-icon { width: 70px; height: 70px; }
        .hospital-hero-icon i { font-size: 30px; }
        .search-form-grid { grid-template-columns: 1fr; }
        .btn-search { width: 100%; }
        .results-header { flex-direction: column; gap: 15px; text-align: center; }
        .results-grid { grid-template-columns: 1fr; }
        .stat-number { font-size: 32px; }
        .hospital-footer { flex-direction: column; }
        .btn-hospital { width: 100%; }
    }
</style>

<!-- Hero Section -->
<section class="hospital-hero">
    <div class="hospital-hero-content">
        <div class="hospital-hero-icon">
            <i class="fas fa-hospital"></i>
        </div>
        <h1>Find Hospitals</h1>
        <p>Discover verified hospitals and branches in your area</p>
    </div>
</section>

<!-- Stats Section -->
<section class="stats-section">
    <div class="stats-container">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-hospital"></i></div>
            <div class="stat-number"><?php echo number_format($hospital_count); ?>+</div>
            <div class="stat-label">Hospitals</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-map-marker-alt"></i></div>
            <div class="stat-number"><?php echo $district_count; ?></div>
            <div class="stat-label">Districts</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-user-md"></i></div>
            <div class="stat-number"><?php echo number_format($doctor_count); ?>+</div>
            <div class="stat-label">Doctors</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-star"></i></div>
            <div class="stat-number">4.5</div>
            <div class="stat-label">Avg Rating</div>
        </div>
    </div>
</section>

<!-- Search Section -->
<section class="search-section">
    <div class="search-container">
        <div class="search-card">
            <h3><i class="fas fa-search"></i> Search Hospitals</h3>
            
            <form action="hospitals.php" method="GET" class="search-form-grid">
                <div class="form-group">
                    <label>Search</label>
                    <input type="text" name="search" placeholder="Hospital or branch name" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>District</label>
                    <select name="district_id" id="district_select">
                        <option value="">Any District</option>
                        <?php if($districts) { $districts->data_seek(0); while($dist = $districts->fetch_assoc()): ?>
                            <option value="<?= $dist['id'] ?>" <?= (($_GET['district_id'] ?? '') == $dist['id']) ? 'selected' : '' ?>><?= htmlspecialchars($dist['name']) ?></option>
                        <?php endwhile; } ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="hospital_type" id="hospital_type_select">
                        <option value="">Any Type</option>
                        <option value="Government" <?= (($_GET['hospital_type'] ?? '') == 'Government') ? 'selected' : '' ?>>Government</option>
                        <option value="Private" <?= (($_GET['hospital_type'] ?? '') == 'Private') ? 'selected' : '' ?>>Private</option>
                    </select>
                </div>
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i> Search
                </button>
            </form>
        </div>
    </div>
</section>

<!-- Results Section -->
<section class="results-section">
    <div class="results-container">
        <div class="results-header">
            <h4><i class="fas fa-check-circle"></i> Search Results</h4>
            <span class="count-badge"><i class="fas fa-hospital"></i> <?php echo $branches_result->num_rows; ?> Result(s)</span>
        </div>
        
        <div id="hospital-skeleton" class="results-grid" style="display: none;">
            <?php for($i=0; $i<6; $i++): ?>
                <div class="hospital-card skeleton">
                    <div class="hospital-header-row"><div style="height:20px; width:60%;" class="skeleton"></div></div>
                    <div style="height:15px; width:40%; margin-top:10px;" class="skeleton"></div>
                    <div style="height:15px; width:80%; margin-top:10px;" class="skeleton"></div>
                </div>
            <?php endfor; ?>
        </div>

        <?php if ($branches_result && $branches_result->num_rows > 0): ?>
            <div id="hospital-results" class="results-grid">
                <?php while ($branch = $branches_result->fetch_assoc()): ?>
                    <div class="hospital-card">
                        <div class="hospital-header-row">
                            <div>
                                <span class="hospital-type-badge <?= strtolower($branch['hospital_type']) ?>">
                                    <?= $branch['hospital_type'] === 'Government' ? 'Government' : 'Private' ?>
                                </span>
                                <?php if ($branch['is_currently_open']): ?>
                                    <div class="live-status-badge open">
                                        <span class="status-dot"></span>
                                        Open Now
                                    </div>
                                <?php else: ?>
                                    <div class="live-status-badge closed">
                                        Closed
                                    </div>
                                <?php endif; ?>
                                <h3 class="hospital-name"><?= htmlspecialchars($branch['hospital_name']) ?></h3>
                                <p class="hospital-branch"><?= htmlspecialchars($branch['branch_name']) ?></p>
                            </div>
                            <div class="hospital-rating">
                                ⭐ <?= $branch['avg_rating'] ? number_format($branch['avg_rating'], 1) : 'N/A' ?>
                                <span>(<?= $branch['total_reviews'] ?>)</span>
                            </div>
                        </div>
                        <div class="hospital-address">
                            <i class="fas fa-map-marker-alt"></i>
                            <span><?= htmlspecialchars($branch['address']) ?>, <?= htmlspecialchars($branch['district_name'] ?? '') ?></span>
                        </div>
                        <div class="hospital-meta">
                            <span class="doctor-count"><i class="fas fa-user-md"></i> <?= $branch['doctor_count'] ?? 0 ?> Doctors</span>
                        </div>
                        <div class="hospital-footer">
                            <a href="tel:<?= htmlspecialchars($branch['hotline'] ?? '') ?>" class="btn-hospital btn-hotline">
                                <i class="fas fa-phone-alt"></i> <?= htmlspecialchars($branch['hotline'] ?? 'N/A') ?>
                            </a>
                            <a href="hospital_profile.php?id=<?= $branch['branch_id'] ?>" class="btn-hospital btn-details">
                                <i class="fas fa-info-circle"></i> Details
                            </a>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-search"></i>
                <h4>No Hospitals Found</h4>
                <p>Try different search criteria or filters</p>
            </div>
        <?php endif; ?>
    </div>
</section>



<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('#district_select').select2({ placeholder: "Any District", allowClear: true });
    $('#hospital_type_select').select2({ placeholder: "Any Type", allowClear: true, minimumResultsForSearch: Infinity });
});
</script>

<?php $conn->close(); include_once('includes/footer.php'); ?>
