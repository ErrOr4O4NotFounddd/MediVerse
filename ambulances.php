
<?php
include_once('includes/db_config.php');
include_once('includes/header.php');

// Get all statistics in one query
$stats_result = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM vw_active_ambulances) as total_ambulances,
        (SELECT COUNT(*) FROM vw_available_ambulances) as available_ambulances,
        (SELECT COUNT(*) FROM hospitals WHERE status = 'Active') as total_hospitals
");
if ($stats_result) {
    $amb_stats = $stats_result->fetch_assoc();
    $total_ambulances = $amb_stats['total_ambulances'];
    $available_ambulances = $amb_stats['available_ambulances'];
    $total_hospitals = $amb_stats['total_hospitals'];
}

// Fetch districts for the filter dropdown
$districts_stmt = $conn->prepare("SELECT id, name FROM districts ORDER BY name");
$districts_stmt->execute();
$districts = $districts_stmt->get_result();
$districts_stmt->close();
$filter_district_id = $_GET['district_id'] ?? '';

// Query for branches with available ambulances
$sql = "
    SELECT 
        hb.id, 
        h.name as hospital_name, 
        hb.branch_name, 
        hb.address,
        COUNT(a.id) as available_ambulances
    FROM hospital_branches hb
    JOIN hospitals h ON hb.hospital_id = h.id
    JOIN upazilas u ON hb.upazila_id = u.id
    JOIN districts d ON u.district_id = d.id
    JOIN vw_available_ambulances a ON hb.id = a.branch_id
    WHERE 
        h.status = 'Active' 
        AND hb.status = 'Active'
";

if (!empty($filter_district_id)) {
    $sql .= " AND d.id = ?";
}

$sql .= " GROUP BY hb.id, h.name, hb.branch_name, hb.address ORDER BY h.name, hb.branch_name";

$stmt = $conn->prepare($sql);
if (!empty($filter_district_id)) {
    $stmt->bind_param("i", $filter_district_id);
}
$stmt->execute();
$branches_with_ambulances = $stmt->get_result();
$stmt->close();
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* Hero Section */
    .ambulance-hero {
        background: linear-gradient(135deg, #e74c3c 0%, #c0392b 50%, #922b21 100%);
        padding: 60px 20px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    
    .ambulance-hero::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="rgba(255,255,255,0.05)"/></svg>');
        background-size: 80px;
        animation: float 20s infinite linear;
    }
    
    @keyframes float {
        0% { transform: translateY(0) rotate(0deg); }
        100% { transform: translateY(-80px) rotate(360deg); }
    }
    
    .ambulance-hero-content {
        position: relative;
        z-index: 1;
        max-width: 700px;
        margin: 0 auto;
    }
    
    .ambulance-hero-icon {
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
    
    .ambulance-hero-icon i {
        font-size: 45px;
        color: white;
    }
    
    .ambulance-hero h1 {
        color: white;
        font-size: 42px;
        font-weight: 800;
        margin: 0 0 15px;
        text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    }
    
    .ambulance-hero p {
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
        background: linear-gradient(90deg, #e74c3c, #ff6b6b);
    }
    
    .stat-card:nth-child(2)::before { background: linear-gradient(90deg, #27ae60, #2ecc71); }
    .stat-card:nth-child(3)::before { background: linear-gradient(90deg, #3498db, #5dade2); }
    
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
    
    .stat-card:nth-child(1) .stat-icon { background: linear-gradient(135deg, #e74c3c, #c0392b); }
    .stat-card:nth-child(2) .stat-icon { background: linear-gradient(135deg, #27ae60, #1e8449); }
    .stat-card:nth-child(3) .stat-icon { background: linear-gradient(135deg, #3498db, #2980b9); }
    
    .stat-icon i {
        font-size: 30px;
        color: white;
    }
    
    .stat-number {
        font-size: 42px;
        font-weight: 800;
        margin-bottom: 5px;
    }
    
    .stat-card:nth-child(1) .stat-number { background: linear-gradient(135deg, #e74c3c, #c0392b); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    .stat-card:nth-child(2) .stat-number { background: linear-gradient(135deg, #27ae60, #1e8449); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    .stat-card:nth-child(3) .stat-number { background: linear-gradient(135deg, #3498db, #2980b9); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    
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
        max-width: 1100px;
        margin: 0 auto;
    }
    
    .search-card {
        background: white;
        border-radius: 25px;
        padding: 35px;
        box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
        border: 1px solid #f0f0f0;
    }
    
    .search-card h3 {
        color: #212529;
        font-size: 24px;
        font-weight: 700;
        margin-bottom: 25px;
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
    }
    
    .search-card h3 i {
        color: #e74c3c;
    }
    
    .search-form {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 15px;
        align-items: end;
    }
    
    .form-group {
        margin-bottom: 0;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 10px;
        font-weight: 600;
        color: #495057;
        font-size: 15px;
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
        border-color: #e74c3c;
        background: white;
        box-shadow: 0 0 0 4px rgba(231, 76, 60, 0.1);
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

    .btn-search {
        padding: 16px 35px;
        font-size: 16px;
        font-weight: 700;
        background: linear-gradient(135deg, #e74c3c, #c0392b);
        color: white;
        border: none;
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 10px 30px rgba(231, 76, 60, 0.3);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .btn-search:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 40px rgba(231, 76, 60, 0.4);
    }

    /* Results Section */
    .results-section {
        padding: 50px 20px;
        background: linear-gradient(180deg, #f8f9fa 0%, #ffffff 100%);
    }
    
    .results-container {
        max-width: 1100px;
        margin: 0 auto;
    }
    
    .results-card {
        background: white;
        border-radius: 25px;
        overflow: hidden;
        box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
        border: 1px solid #f0f0f0;
    }
    
    .results-header {
        background: linear-gradient(135deg, #e74c3c, #c0392b);
        padding: 25px 35px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    
    .results-header h3 {
        color: white;
        font-size: 22px;
        font-weight: 700;
        margin: 0;
    }
    
    .results-header h3 i {
        margin-right: 10px;
    }
    
    .results-count {
        background: rgba(255, 255, 255, 0.2);
        padding: 8px 20px;
        border-radius: 20px;
        color: white;
        font-weight: 600;
        font-size: 14px;
    }
    
    .ambulance-list {
        padding: 20px;
    }
    
    .ambulance-item {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 20px;
        padding: 25px;
        border-bottom: 1px solid #f0f0f0;
        align-items: center;
        transition: all 0.3s ease;
    }
    
    .ambulance-item:last-child {
        border-bottom: none;
    }
    
    .ambulance-item:hover {
        background: #f8f9fa;
        border-radius: 12px;
    }
    
    .ambulance-info h4 {
        color: #212529;
        font-size: 18px;
        font-weight: 700;
        margin: 0 0 5px;
    }
    
    .ambulance-info .branch-name {
        color: #27ae60;
        font-weight: 600;
        font-size: 15px;
        margin-bottom: 5px;
    }
    
    .ambulance-info .address {
        color: #6c757d;
        font-size: 14px;
        margin: 0;
    }
    
    .ambulance-meta {
        display: flex;
        align-items: center;
        gap: 20px;
    }
    
    .available-badge {
        background: linear-gradient(135deg, #27ae60, #1e8449);
        color: white;
        padding: 10px 20px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .btn-book {
        padding: 12px 25px;
        background: linear-gradient(135deg, #e74c3c, #c0392b);
        color: white;
        border: none;
        border-radius: 10px;
        font-weight: 700;
        font-size: 15px;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .btn-book:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(231, 76, 60, 0.3);
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 40px;
    }
    
    .empty-state i {
        font-size: 60px;
        color: #dee2e6;
        margin-bottom: 20px;
    }
    
    .empty-state h4 {
        color: #212529;
        font-size: 20px;
        margin-bottom: 10px;
    }
    
    .empty-state p {
        color: #6c757d;
        font-size: 15px;
        margin: 0;
    }

    /* GPS CTA */
    .gps-cta {
        margin: 30px;
        padding: 30px;
        background: linear-gradient(135deg, #fff5f5, #ffe5e5);
        border-radius: 20px;
        text-align: center;
        border: 2px dashed #e74c3c;
    }
    
    .gps-cta h4 {
        color: #c0392b;
        font-size: 20px;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }
    
    .gps-cta p {
        color: #666;
        margin-bottom: 20px;
    }
    
    .btn-gps {
        display: inline-block;
        padding: 15px 40px;
        background: linear-gradient(135deg, #e74c3c, #c0392b);
        color: white;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 700;
        font-size: 16px;
        transition: all 0.3s ease;
    }
    
    .btn-gps:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(231, 76, 60, 0.4);
    }

    @media (max-width: 768px) {
        .ambulance-hero { padding: 40px 20px; }
        .ambulance-hero h1 { font-size: 28px; }
        .ambulance-hero-icon { width: 70px; height: 70px; }
        .ambulance-hero-icon i { font-size: 30px; }
        .stat-number { font-size: 32px; }
        .search-form { grid-template-columns: 1fr; }
        .btn-search { width: 100%; justify-content: center; }
        .ambulance-item { grid-template-columns: 1fr; text-align: center; }
        .ambulance-meta { flex-direction: column; gap: 15px; }
        .results-header { flex-direction: column; gap: 15px; text-align: center; }
    }
</style>

<!-- Hero Section -->
<section class="ambulance-hero">
    <div class="ambulance-hero-content">
        <div class="ambulance-hero-icon">
            <i class="fas fa-ambulance"></i>
        </div>
        <h1>অ্যাম্বুলেন্স বুকিং</h1>
        <p>জরুরি অ্যাম্বুলেন্স সেবা বুক করুন</p>
    </div>
</section>

<!-- Stats Section -->
<section class="stats-section">
    <div class="stats-container">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-ambulance"></i></div>
            <div class="stat-number"><?php echo $total_ambulances; ?>+</div>
            <div class="stat-label">Total Ambulances</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-number"><?php echo $available_ambulances; ?>+</div>
            <div class="stat-label">Available Now</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-hospital"></i></div>
            <div class="stat-number"><?php echo $total_hospitals; ?>+</div>
            <div class="stat-label">Hospitals</div>
        </div>
    </div>
</section>

<!-- Search Section -->
<section class="search-section">
    <div class="search-container">
        <div class="search-card">
            <h3><i class="fas fa-search"></i> অ্যাম্বুলেন্স খুঁজুন</h3>
            <form action="ambulances.php" method="GET" class="search-form">
                <div class="form-group">
                    <label>জেলা নির্বাচন করুন</label>
                    <select name="district_id" id="district_select">
                        <option value="">-- সকল জেলা --</option>
                        <?php if($districts) { while($dist = $districts->fetch_assoc()): ?>
                        <option value="<?= $dist['id'] ?>" <?= $filter_district_id == $dist['id'] ? 'selected' : '' ?>><?= htmlspecialchars($dist['name']) ?></option>
                        <?php endwhile; } ?>
                    </select>
                </div>
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i> খুঁজুন
                </button>
            </form>
        </div>
    </div>
</section>

<!-- Results Section -->
<section class="results-section">
    <div class="results-container">
        <div class="results-card">
            <div class="results-header">
                <h3><i class="fas fa-list"></i> উপলব্ধ অ্যাম্বুলেন্স</h3>
                <span class="results-count"><?php echo $branches_with_ambulances ? $branches_with_ambulances->num_rows : 0; ?>টি হাসপাতাল</span>
            </div>
            <div class="ambulance-list">
                <?php if($branches_with_ambulances && $branches_with_ambulances->num_rows > 0): ?>
                    <?php while($br = $branches_with_ambulances->fetch_assoc()): ?>
                    <div class="ambulance-item">
                        <div class="ambulance-info">
                            <h4><?php echo htmlspecialchars($br['hospital_name']); ?></h4>
                            <p class="branch-name"><?php echo htmlspecialchars($br['branch_name']); ?></p>
                            <p class="address"><?php echo htmlspecialchars($br['address']); ?></p>
                        </div>
                        <div class="ambulance-meta">
                            <div class="available-badge">
                                <i class="fas fa-check"></i>
                                <?php echo htmlspecialchars($br['available_ambulances']); ?>টি উপলব্ধ
                            </div>
                            <a href="book_ambulance_v2.php?branch_id=<?php echo $br['id']; ?>" class="btn-book">
                                <i class="fas fa-calendar-check"></i> বুক করুন
                            </a>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-car-side"></i>
                        <h4>কোনো অ্যাম্বুলেন্স পাওয়া যায়নি</h4>
                        <p>আপনার নির্বাচিত এলাকায় এই মুহূর্তে কোনো অ্যাম্বুলেন্স উপলব্ধ নেই।</p>
                    </div>
                <?php endif; ?>
                
                <!-- GPS CTA -->
                <div class="gps-cta">
                    <h4><i class="fas fa-map-marker-alt"></i> জরুরি অ্যাম্বুলেন্স দরকার?</h4>
                    <p>আপনার লোকেশন থেকে নিকটতম অ্যাম্বুলেন্স খুঁজে GPS ট্র্যাকিং সহ বুক করুন</p>
                    <a href="book_ambulance_v2.php" class="btn-gps">
                        <i class="fas fa-crosshairs"></i> GPS দিয়ে বুক করুন
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('#district_select').select2({ 
        placeholder: "জেলা নির্বাচন করুন",
        allowClear: true 
    });
});
</script>

<?php include_once('includes/footer.php'); ?>
