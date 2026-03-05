<?php
include_once('includes/db_config.php');
include_once('includes/header.php');

// --- Fetch data for filters ---
$districts_stmt = $conn->prepare("SELECT id, name FROM districts ORDER BY name ASC");
$districts_stmt->execute();
$districts = $districts_stmt->get_result();
$districts_stmt->close();

// --- Get actual statistics ---
$hospital_count = $conn->query("SELECT COUNT(DISTINCT h.id) as count FROM hospitals h JOIN hospital_branches hb ON h.id = hb.hospital_id WHERE h.status = 'Active' AND hb.status = 'Active'")->fetch_assoc()['count'];
$district_count = $conn->query("SELECT COUNT(*) as count FROM districts")->fetch_assoc()['count'];
$total_beds = $conn->query("SELECT SUM(CASE WHEN status = 'Available' THEN 1 ELSE 0 END) as count FROM beds WHERE deleted_at IS NULL")->fetch_assoc()['count'];

// --- Using the View `v_live_bed_status` for faster and cleaner queries ---
$sql = "SELECT hospital_name, branch_name, bed_type, available_beds, avg_cost_per_day, branch_id, district_id, hospital_type FROM v_live_bed_status";

// --- Apply filters on the View based on user selection ---
$where_clauses = [];
$params = [];
$types = "";
if (!empty($_GET['district_id'])) {
    $district_id = (int)$_GET['district_id'];
    $where_clauses[] = "district_id = ?";
    $params[] = $district_id;
    $types .= "i";
}
if (!empty($_GET['hospital_type'])) {
    $hospital_type = $_GET['hospital_type'];
    $where_clauses[] = "hospital_type = ?";
    $params[] = $hospital_type;
    $types .= "s";
}
if (!empty($_GET['bed_type'])) {
    $bed_type = $_GET['bed_type'];
    $where_clauses[] = "bed_type = ?";
    $params[] = $bed_type;
    $types .= "s";
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(' AND ', $where_clauses);
}

// Order the final results
$sql .= " ORDER BY hospital_name ASC, FIELD(bed_type, 'ICU', 'CCU', 'Cabin', 'Ward')";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$beds_result = $stmt->get_result();
$stmt->close();

// Failsafe for debugging
if (!$beds_result) {
    die("SQL Error: " . $conn->error);
}
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* Hero Section */
    .bed-hero {
        background: linear-gradient(135deg, #6f42c1 0%, #4c2f8f 50%, #3a1f7a 100%);
        padding: 60px 20px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    
    .bed-hero::before {
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
    
    .bed-hero-content {
        position: relative;
        z-index: 1;
        max-width: 700px;
        margin: 0 auto;
    }
    
    .bed-hero-icon {
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
    
    .bed-hero-icon i {
        font-size: 45px;
        color: white;
    }
    
    .bed-hero h1 {
        color: white;
        font-size: 42px;
        font-weight: 800;
        margin: 0 0 15px;
        text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    }
    
    .bed-hero p {
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
        background: linear-gradient(90deg, #6f42c1, #9775fa);
    }
    
    .stat-card:nth-child(2)::before { background: linear-gradient(90deg, #20c997, #12b886); }
    .stat-card:nth-child(3)::before { background: linear-gradient(90deg, #0d6efd, #6ea8fe); }
    
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
    
    .stat-card:nth-child(1) .stat-icon { background: linear-gradient(135deg, #6f42c1, #4c2f8f); }
    .stat-card:nth-child(2) .stat-icon { background: linear-gradient(135deg, #20c997, #0ca678); }
    .stat-card:nth-child(3) .stat-icon { background: linear-gradient(135deg, #0d6efd, #084298); }
    
    .stat-icon i {
        font-size: 30px;
        color: white;
    }
    
    .stat-number {
        font-size: 42px;
        font-weight: 800;
        margin-bottom: 5px;
    }
    
    .stat-card:nth-child(1) .stat-number { background: linear-gradient(135deg, #6f42c1, #4c2f8f); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    .stat-card:nth-child(2) .stat-number { background: linear-gradient(135deg, #20c997, #0ca678); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    .stat-card:nth-child(3) .stat-number { background: linear-gradient(135deg, #0d6efd, #084298); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    
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
        color: #6f42c1;
    }
    
    .search-form-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 15px;
        align-items: end;
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
    
    .form-group select:focus {
        outline: none;
        border-color: #6f42c1;
        background: white;
        box-shadow: 0 0 0 4px rgba(111, 66, 193, 0.1);
    }
    
    .btn-search {
        padding: 0 25px;
        background: linear-gradient(135deg, #6f42c1 0%, #4c2f8f 100%);
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
        box-shadow: 0 10px 30px rgba(111, 66, 193, 0.4);
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
        background: linear-gradient(135deg, #6f42c1, #4c2f8f);
        color: white;
        padding: 10px 22px;
        border-radius: 30px;
        font-weight: 700;
        font-size: 14px;
        box-shadow: 0 5px 20px rgba(111, 66, 193, 0.3);
    }
    
    .results-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: 25px;
    }
    
    .bed-card {
        background: white;
        border-radius: 16px;
        padding: 25px;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    
    .bed-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(90deg, #6f42c1, #9775fa);
    }
    
    .bed-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 50px rgba(111, 66, 193, 0.15);
    }
    
    .bed-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
    }
    
    .hospital-info h3 {
        font-size: 18px;
        font-weight: 700;
        color: #212529;
        margin: 0 0 4px 0;
    }
    
    .hospital-info .branch-name {
        font-size: 14px;
        color: #6c757d;
        margin: 0;
    }
    
    .bed-type-badge {
        display: inline-block;
        padding: 6px 14px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 700;
    }
    
    .bed-type-badge.type-icu { background: linear-gradient(135deg, #dc3545, #c82333); color: white; }
    .bed-type-badge.type-ccu { background: linear-gradient(135deg, #fd7e14, #e67e22); color: white; }
    .bed-type-badge.type-cabin { background: linear-gradient(135deg, #0d6efd, #084298); color: white; }
    .bed-type-badge.type-ward, .bed-type-badge.type-general-ward { background: linear-gradient(135deg, #20c997, #0ca678); color: white; }
    .bed-type-badge.type-vip-cabin { background: linear-gradient(135deg, #e83e8c, #c2185b); color: white; }
    
    .bed-stats {
        display: flex;
        gap: 15px;
        margin-bottom: 15px;
        flex-wrap: wrap;
    }
    
    .bed-stat {
        flex: 1;
        min-width: 120px;
        background: #f8f9fa;
        padding: 12px 15px;
        border-radius: 10px;
        text-align: center;
    }
    
    .bed-stat-number {
        font-size: 24px;
        font-weight: 800;
        color: #20c997;
        display: block;
    }
    
    .bed-stat-label {
        font-size: 12px;
        color: #6c757d;
        font-weight: 600;
    }
    
    .bed-stat.price .bed-stat-number {
        color: #6f42c1;
    }
    
    .btn-bed {
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
        background: linear-gradient(135deg, #6f42c1, #4c2f8f);
        color: white;
        box-shadow: 0 5px 20px rgba(111, 66, 193, 0.3);
    }
    
    .btn-bed:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(111, 66, 193, 0.4);
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
        background: linear-gradient(135deg, #6f42c1, #4c2f8f);
        color: white;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 700;
        font-size: 16px;
        transition: all 0.3s ease;
    }
    
    .btn-cta:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(111, 66, 193, 0.4);
    }

    @media (max-width: 768px) {
        .bed-hero { padding: 40px 20px; }
        .bed-hero h1 { font-size: 28px; }
        .bed-hero-icon { width: 70px; height: 70px; }
        .bed-hero-icon i { font-size: 30px; }
        .search-form-grid { grid-template-columns: 1fr; }
        .btn-search { width: 100%; }
        .results-header { flex-direction: column; gap: 15px; text-align: center; }
        .results-grid { grid-template-columns: 1fr; }
        .stat-number { font-size: 32px; }
    }
</style>

<!-- Hero Section -->
<section class="bed-hero">
    <div class="bed-hero-content">
        <div class="bed-hero-icon">
            <i class="fas fa-bed"></i>
        </div>
        <h1>Live Bed Status</h1>
        <p>Find available beds in hospitals across Bangladesh</p>
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
            <div class="stat-icon"><i class="fas fa-bed"></i></div>
            <div class="stat-number"><?php echo number_format($total_beds); ?>+</div>
            <div class="stat-label">Available Beds</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-map-marker-alt"></i></div>
            <div class="stat-number"><?php echo $district_count; ?></div>
            <div class="stat-label">Districts</div>
        </div>
    </div>
</section>

<!-- Search Section -->
<section class="search-section">
    <div class="search-container">
        <div class="search-card">
            <h3><i class="fas fa-filter"></i> Filter Beds</h3>
            
            <form action="bed_status.php" method="GET" class="search-form-grid">
                <div class="form-group">
                    <label>District</label>
                    <select name="district_id" id="district_select">
                        <option value="">Any District</option>
                        <?php if($districts && $districts->num_rows > 0) { $districts->data_seek(0); while($dist = $districts->fetch_assoc()): ?>
                            <option value="<?= $dist['id'] ?>" <?= (($_GET['district_id'] ?? '') == $dist['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dist['name']) ?>
                            </option>
                        <?php endwhile; } ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Hospital Type</label>
                    <select name="hospital_type" id="hospital_type_select">
                        <option value="">Any Type</option>
                        <option value="Government" <?= (($_GET['hospital_type'] ?? '') == 'Government') ? 'selected' : '' ?>>Government</option>
                        <option value="Private" <?= (($_GET['hospital_type'] ?? '') == 'Private') ? 'selected' : '' ?>>Private</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Bed Type</label>
                    <select name="bed_type" id="bed_type_select">
                        <option value="">Any Type</option>
                        <option value="ICU" <?= (($_GET['bed_type'] ?? '') == 'ICU') ? 'selected' : '' ?>>ICU</option>
                        <option value="CCU" <?= (($_GET['bed_type'] ?? '') == 'CCU') ? 'selected' : '' ?>>CCU</option>
                        <option value="Cabin" <?= (($_GET['bed_type'] ?? '') == 'Cabin') ? 'selected' : '' ?>>Cabin</option>
                        <option value="Ward" <?= (($_GET['bed_type'] ?? '') == 'Ward') ? 'selected' : '' ?>>Ward</option>
                        <option value="General Ward" <?= (($_GET['bed_type'] ?? '') == 'General Ward') ? 'selected' : '' ?>>General Ward</option>
                        <option value="VIP Cabin" <?= (($_GET['bed_type'] ?? '') == 'VIP Cabin') ? 'selected' : '' ?>>VIP Cabin</option>
                    </select>
                </div>
                <div class="search-button-row">
                    <button type="submit" class="btn-search">
                        <i class="fas fa-search"></i> Search Beds
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
            <span class="count-badge"><i class="fas fa-bed"></i> <?php echo $beds_result->num_rows; ?> Result(s)</span>
        </div>
        
        <?php if($beds_result && $beds_result->num_rows > 0): ?>
            <div class="results-grid">
                <?php while($bed = $beds_result->fetch_assoc()): ?>
                    <div class="bed-card">
                        <div class="bed-card-header">
                            <div class="hospital-info">
                                <h3><?= htmlspecialchars($bed['hospital_name']) ?></h3>
                                <p class="branch-name"><?= htmlspecialchars($bed['branch_name']) ?></p>
                            </div>
                            <span class="bed-type-badge type-<?= strtolower(str_replace(' ', '-', $bed['bed_type'])) ?>"><?= htmlspecialchars($bed['bed_type']) ?></span>
                        </div>
                        <div class="bed-stats">
                            <div class="bed-stat">
                                <span class="bed-stat-number"><?= htmlspecialchars($bed['available_beds']) ?></span>
                                <span class="bed-stat-label">Available</span>
                            </div>
                            <div class="bed-stat price">
                                <span class="bed-stat-number">৳<?= $bed['avg_cost_per_day'] > 0 ? number_format($bed['avg_cost_per_day'], 0) : 'N/A' ?></span>
                                <span class="bed-stat-label">Daily Rent</span>
                            </div>
                        </div>
                        <a href="book_bed.php?branch_id=<?= $bed['branch_id'] ?>&bed_type=<?= $bed['bed_type'] ?>" class="btn-bed">
                            <i class="fas fa-calendar-check"></i> Book Now
                        </a>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-bed"></i>
                <h4>No Beds Available</h4>
                <p>Try different filters to find available beds</p>
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
    $('#bed_type_select').select2({ placeholder: "Any Bed Type", allowClear: true, minimumResultsForSearch: Infinity });
});
</script>

<?php
if($districts) { $districts->data_seek(0); }
$conn->close();
include_once('includes/footer.php');
?>
