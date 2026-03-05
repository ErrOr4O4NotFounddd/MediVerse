<?php
include_once('includes/db_config.php');
include_once('includes/header.php');

$hospitals_stmt = $conn->prepare("SELECT DISTINCT h.id, h.name FROM hospitals h JOIN hospital_branches hb ON h.id = hb.hospital_id WHERE hb.status = 'Active' AND h.status = 'Active' ORDER BY h.name ASC");
$hospitals_stmt->execute();
$hospitals = $hospitals_stmt->get_result();

$specializations_stmt = $conn->prepare("SELECT id, name_en, name_bn FROM specializations ORDER BY name_en");
$specializations_stmt->execute();
$specializations = $specializations_stmt->get_result();

// --- Get actual statistics (Consolidated Query) ---
$stats_sql = "SELECT 
    (SELECT COUNT(*) FROM doctors WHERE is_verified = 'Verified') as doctor_count,
    (SELECT COUNT(DISTINCT h.id) FROM hospitals h JOIN hospital_branches hb ON h.id = hb.hospital_id WHERE h.status = 'Active' AND hb.status = 'Active') as hospital_count,
    (SELECT COUNT(*) FROM specializations) as specialization_count";
$stats_result = $conn->query($stats_sql)->fetch_assoc();

$doctor_count = $stats_result['doctor_count'];
$hospital_count = $stats_result['hospital_count'];
$specialization_count = $stats_result['specialization_count'];

// --- FINAL SQL: Using vw_doctors_directory view ---
$sql = "SELECT * FROM vw_doctors_directory";

// Base conditions: Doctor must be verified AND have a schedule in an Active branch of an Active hospital.
$where_clauses = [
    "is_verified = 'Verified'",
    "doctor_id IN (
        SELECT ds.doctor_id FROM doctor_schedules ds
        JOIN hospital_branches hb ON ds.branch_id = hb.id
        JOIN hospitals h ON hb.hospital_id = h.id
        WHERE hb.status = 'Active' AND h.status = 'Active' AND ds.deleted_at IS NULL
    )"
];
$params = [];
$types = '';

if (!empty($_GET['hospital_id'])) {
    $hospital_id = (int)$_GET['hospital_id'];
    $where_clauses[] = "doctor_id IN (SELECT ds.doctor_id FROM doctor_schedules ds JOIN hospital_branches hb ON ds.branch_id = hb.id WHERE hb.hospital_id = ? AND hb.status = 'Active' AND ds.deleted_at IS NULL)";
    $params[] = $hospital_id;
    $types .= 'i';
}
if (!empty($_GET['spec_id'])) {
    $spec_id = (int)$_GET['spec_id'];
    $where_clauses[] = "specialization_id = ?";
    $params[] = $spec_id;
    $types .= 'i';
}
if (!empty($_GET['search'])) {
    $search_term = $_GET['search'];
    $where_clauses[] = "full_name LIKE CONCAT('%', ?, '%')";
    $params[] = $search_term;
    $types .= 's';
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(' AND ', $where_clauses);
}

$sql .= " ORDER BY avg_rating DESC, full_name ASC";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$doctors_result = $stmt->get_result();

if (!$doctors_result) {
    die("SQL Error: " . $conn->error);
}
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* Hero Section */
    .doctor-hero {
        background: linear-gradient(135deg, #20c997 0%, #0ca678 50%, #087f5b 100%);
        padding: 60px 20px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    
    .doctor-hero::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="30" fill="rgba(255,255,255,0.05)"/></svg>');
        background-size: 80px;
        animation: float 20s infinite linear;
    }
    
    @keyframes float {
        0% { transform: translateY(0) rotate(0deg); }
        100% { transform: translateY(-80px) rotate(360deg); }
    }
    
    .doctor-hero-content {
        position: relative;
        z-index: 1;
        max-width: 700px;
        margin: 0 auto;
    }
    
    .doctor-hero-icon {
        width: 100px;
        height: 100px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 50%;
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
    
    .doctor-hero-icon i {
        font-size: 45px;
        color: white;
    }
    
    .doctor-hero h1 {
        color: white;
        font-size: 42px;
        font-weight: 800;
        margin: 0 0 15px;
        text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    }
    
    .doctor-hero p {
        color: rgba(255, 255, 255, 0.95);
        font-size: 18px;
        margin: 0;
    }

    /* Stats Section */
    .stats-section {
        padding: 30px 20px 50px;
        background: linear-gradient(180deg, #f8f9fa 0%, #ffffff 100%);
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
        background: linear-gradient(90deg, #20c997, #12b886);
    }
    
    .stat-card:nth-child(2)::before { background: linear-gradient(90deg, #0d6efd, #6ea8fe); }
    .stat-card:nth-child(3)::before { background: linear-gradient(90deg, #6f42c1, #9775fa); }
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
    
    .stat-card:nth-child(1) .stat-icon { background: linear-gradient(135deg, #20c997, #0ca678); }
    .stat-card:nth-child(2) .stat-icon { background: linear-gradient(135deg, #0d6efd, #084298); }
    .stat-card:nth-child(3) .stat-icon { background: linear-gradient(135deg, #6f42c1, #9775fa); }
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
    
    .stat-card:nth-child(1) .stat-number { background: linear-gradient(135deg, #20c997, #0ca678); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    .stat-card:nth-child(2) .stat-number { background: linear-gradient(135deg, #0d6efd, #084298); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    .stat-card:nth-child(3) .stat-number { background: linear-gradient(135deg, #6f42c1, #9775fa); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
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
        color: #20c997;
    }
    
    .search-form-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 15px;
        align-items: end;
    }
    
    .search-form-row {
        display: contents;
    }
    
    .search-button-row {
        grid-column: 1 / -1;
        display: flex;
        justify-content: flex-start;
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
        border-color: #20c997;
        background: white;
        box-shadow: 0 0 0 4px rgba(32, 201, 151, 0.1);
    }
    
    .btn-search {
        padding: 0 25px;
        background: linear-gradient(135deg, #20c997 0%, #0ca678 100%);
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
        margin-top: 2px;
    }
    
    .btn-search:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(32, 201, 151, 0.4);
    }

    /* Select2 Styling */
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
        color: #20c997;
    }
    
    .count-badge {
        background: linear-gradient(135deg, #20c997, #0ca678);
        color: white;
        padding: 10px 22px;
        border-radius: 30px;
        font-weight: 700;
        font-size: 14px;
        box-shadow: 0 5px 20px rgba(32, 201, 151, 0.3);
    }
    
    .results-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: 25px;
    }
    
    .doctor-card {
        background: white;
        border-radius: 16px;
        padding: 25px;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    
    .doctor-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(90deg, #20c997, #12b886);
    }
    
    .doctor-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 50px rgba(32, 201, 151, 0.15);
    }
    
    .doctor-header {
        display: flex;
        gap: 15px;
        margin-bottom: 12px;
    }
    
    .doctor-photo {
        width: 60px;
        height: 60px;
        border-radius: 12px;
        overflow: hidden;
        flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
    
    .doctor-photo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .doctor-header-info {
        flex: 1;
        display: block;
    }
    
    .doctor-name {
        font-size: 16px;
        font-weight: 700;
        color: #212529;
        margin: 0 0 4px 0;
        line-height: 1.3;
    }
    
    .doctor-spec {
        font-size: 13px;
        color: #20c997;
        font-weight: 600;
        margin: 0 0 6px 0;
        display: block;
    }
    
    .doctor-rating {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: #fff3cd;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        clear: both;
        display: inline-block;
    }
    
    .doctor-rating span {
        color: #6c757d;
        font-weight: 400;
    }
    
    .doctor-hospital {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        margin-bottom: 12px;
        color: #6c757d;
        font-size: 14px;
    }
    
    .doctor-hospital i {
        color: #0d6efd;
        margin-top: 3px;
    }
    
    .doctor-schedule {
        margin-bottom: 15px;
    }
    
    .schedule-title {
        font-size: 12px;
        font-weight: 600;
        color: #6c757d;
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .schedule-days {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    
    .day-badge {
        padding: 5px 10px;
        background: #e7f5ff;
        color: #0d6efd;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
    }
    
    .btn-doctor {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 14px 25px;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 700;
        font-size: 14px;
        transition: all 0.3s ease;
        background: linear-gradient(135deg, #20c997, #0ca678);
        color: white;
        box-shadow: 0 5px 20px rgba(32, 201, 151, 0.3);
    }
    
    .btn-doctor:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(32, 201, 151, 0.4);
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
        background: linear-gradient(135deg, #20c997, #0ca678);
        color: white;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 700;
        font-size: 16px;
        transition: all 0.3s ease;
    }
    
    .btn-cta:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(32, 201, 151, 0.4);
    }

    @media (max-width: 768px) {
        .doctor-hero { padding: 40px 20px; }
        .doctor-hero h1 { font-size: 28px; }
        .doctor-hero-icon { width: 70px; height: 70px; }
        .doctor-hero-icon i { font-size: 30px; }
        .search-form-grid { grid-template-columns: 1fr; }
        .btn-search { width: 100%; }
        .results-header { flex-direction: column; gap: 15px; text-align: center; }
        .results-grid { grid-template-columns: 1fr; }
        .stat-number { font-size: 32px; }
        .doctor-header { flex-direction: column; align-items: center; text-align: center; }
    }
</style>

<!-- Hero Section -->
<section class="doctor-hero">
    <div class="doctor-hero-content">
        <div class="doctor-hero-icon">
            <i class="fas fa-user-md"></i>
        </div>
        <h1>Find Doctors</h1>
        <p>Connect with verified specialists for your healthcare needs</p>
    </div>
</section>

<!-- Stats Section -->
<section class="stats-section">
    <div class="stats-container">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-user-md"></i></div>
            <div class="stat-number"><?php echo number_format($doctor_count); ?>+</div>
            <div class="stat-label">Verified Doctors</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-hospital"></i></div>
            <div class="stat-number"><?php echo number_format($hospital_count); ?>+</div>
            <div class="stat-label">Hospitals</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-stethoscope"></i></div>
            <div class="stat-number"><?php echo $specialization_count; ?></div>
            <div class="stat-label">Specializations</div>
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
            <h3><i class="fas fa-search"></i> Search Doctors</h3>
            
            <form action="doctors.php" method="GET" class="search-form-grid">
                <div class="form-group">
                    <label>Search</label>
                    <input type="text" name="search" placeholder="Doctor name" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Hospital</label>
                    <select name="hospital_id" id="hospital_select">
                        <option value="">Any Hospital</option>
                        <?php if($hospitals) { $hospitals->data_seek(0); while($h = $hospitals->fetch_assoc()): ?>
                            <option value="<?= $h['id'] ?>" <?= (($_GET['hospital_id'] ?? '') == $h['id']) ? 'selected' : '' ?>><?= htmlspecialchars($h['name']) ?></option>
                        <?php endwhile; } ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Specialization</label>
                    <select name="spec_id" id="specialization_select">
                        <option value="">Any Specialization</option>
                        <?php if($specializations) { $specializations->data_seek(0); while($spec = $specializations->fetch_assoc()): ?>
                            <option value="<?= $spec['id'] ?>" <?= (($_GET['spec_id'] ?? '') == $spec['id']) ? 'selected' : '' ?>><?= htmlspecialchars($spec['name_bn']) ?> (<?= htmlspecialchars($spec['name_en']) ?>)</option>
                        <?php endwhile; } ?>
                    </select>
                </div>
                <div class="search-button-row">
                    <button type="submit" class="btn-search">
                        <i class="fas fa-search"></i> Search Doctors
                    </button>
                </div>
            </form>
        </div>
    </div>
</section>

<!-- Results Section -->
<section class="results-section">
    <div class="results-container">
        <div class="results-header">
            <h4><i class="fas fa-check-circle"></i> Search Results</h4>
            <span class="count-badge"><i class="fas fa-user-md"></i> <?php echo $doctors_result->num_rows; ?> Doctor(s)</span>
        </div>
        
        <?php if ($doctors_result && $doctors_result->num_rows > 0): ?>
            <div class="results-grid">
                <?php while ($doctor = $doctors_result->fetch_assoc()): ?>
                    <div class="doctor-card">
                        <div class="doctor-header">
                            <div class="doctor-photo">
                                <?php 
                                $profile_path = $doctor['profile_image'];
                                // Check multiple possible locations for the profile image
                                $found = false;
                                if (!empty($profile_path)) {
                                    if (file_exists($profile_path)) {
                                        $found = true;
                                    } else if (file_exists('uploads/users/' . $profile_path)) {
                                        $profile_path = 'uploads/users/' . $profile_path;
                                        $found = true;
                                    } else if (file_exists('uploads/profile_pics/' . $profile_path)) {
                                        $profile_path = 'uploads/profile_pics/' . $profile_path;
                                        $found = true;
                                    }
                                }
                                if ($found): ?>
                                    <img src="<?= htmlspecialchars($profile_path) ?>" alt="<?= htmlspecialchars($doctor['full_name']) ?>">
                                <?php else: ?>
                                    <img src="https://i.ibb.co/L1b1sDS/default-doctor-avatar.png" alt="<?= htmlspecialchars($doctor['full_name']) ?>">
                                <?php endif; ?>
                            </div>
                            <div class="doctor-header-info">
                                <h3 class="doctor-name"><?= htmlspecialchars($doctor['full_name']) ?></h3>
                                <p class="doctor-spec"><?= htmlspecialchars($doctor['specialization_bn']) ?></p>
                                <div class="doctor-rating">
                                    ⭐ <?= $doctor['avg_rating'] ? number_format($doctor['avg_rating'], 1) : 'N/A' ?>
                                    <span>(<?= $doctor['total_reviews'] ?>)</span>
                                </div>
                            </div>
                        </div>
                        <div class="doctor-hospital">
                            <i class="fas fa-hospital"></i>
                            <span><?= htmlspecialchars($doctor['hospital_name'] ?? 'Chamber / Online') ?></span>
                        </div>
                        <?php if (!empty($doctor['schedule_days'])): ?>
                            <div class="doctor-schedule">
                                <div class="schedule-title">Available Days</div>
                                <div class="schedule-days">
                                    <?php $days = explode(',', $doctor['schedule_days']); foreach($days as $day): ?>
                                        <span class="day-badge"><?= substr($day, 0, 3) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <a href="doctor_profile.php?id=<?= $doctor['doctor_id'] ?>" class="btn-doctor">
                            <i class="fas fa-user-circle"></i> View Profile
                        </a>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-user-md"></i>
                <h4>No Doctors Found</h4>
                <p>Try different search criteria or filters</p>
            </div>
        <?php endif; ?>
    </div>
</section>



<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('#hospital_select').select2({ placeholder: "Any Hospital", allowClear: true });
    $('#specialization_select').select2({ placeholder: "Any Specialization", allowClear: true });
});
</script>

<?php $conn->close(); include_once('includes/footer.php'); ?>
