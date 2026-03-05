<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include_once('includes/db_config.php');
include_once('includes/header.php');

// Get divisions for dropdown
$divisions_stmt = $conn->prepare("SELECT id, name FROM divisions ORDER BY name ASC");
if (!$divisions_stmt) {
    die("Divisions query failed: " . $conn->error);
}
$divisions_stmt->execute();
$divisions = $divisions_stmt->get_result();

$donors = null;

// Debug: Count total donors in database
$debug_sql = "SELECT COUNT(*) as total FROM users WHERE is_donor = 1 AND donor_availability = 'Available' AND is_active = 1 AND deleted_at IS NULL";
$debug_result = $conn->query($debug_sql);
$debug_data = $debug_result->fetch_assoc();
echo "<!-- Debug: Total available donors in DB: " . $debug_data['total'] . " -->\n";

if (isset($_GET['find'])) {
    $blood_group = trim($_GET['blood_group'] ?? '');
    $division_id = isset($_GET['division_id']) ? (int)$_GET['division_id'] : 0;
    $district_id = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
    
    // Build query with conditional filters
    $sql = "SELECT u.full_name, u.phone, u.blood_group, 
            COALESCE(d.name, 'Unknown') as district_name, 
            u.last_donation_date
            FROM users u
            LEFT JOIN districts d ON u.district_id = d.id
            WHERE u.is_donor = 1
              AND (u.donor_availability = 'Available' OR u.donor_availability = '')
              AND u.is_active = 1
              AND u.deleted_at IS NULL";

    $params = [];
    $types = '';

    // Blood group filter (optional)
    if (!empty($blood_group)) {
        $sql .= " AND u.blood_group = ?";
        $params[] = $blood_group;
        $types .= 's';
    }

    // District filter (takes priority over division)
    if ($district_id > 0) {
        $sql .= " AND u.district_id = ?";
        $params[] = $district_id;
        $types .= 'i';
    } elseif ($division_id > 0) {
        // Division filter (only if district not selected)
        // Use u.division_id for direct match OR d.division_id for district-based match
        $sql .= " AND (u.division_id = ? OR d.division_id = ?)";
        $params[] = $division_id;
        $params[] = $division_id;
        $types .= 'ii';
    }
    
    $sql .= " ORDER BY d.name ASC, u.full_name ASC LIMIT 20";
    
    echo "<!-- Debug: SQL = " . htmlspecialchars($sql) . " -->\n";
    echo "<!-- Debug: Params = " . json_encode($params) . " -->\n";
    echo "<!-- Debug: Types = $types -->\n";
    
    // Execute query if at least one filter is provided
    if (count($params) > 0) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo "<!-- Debug: Prepare failed: " . $conn->error . " -->\n";
            $donors = null;
        } else {
            if (!empty($types)) {
                $stmt->bind_param($types, ...$params);
            }
            if (!$stmt->execute()) {
                echo "<!-- Debug: Execute failed: " . $stmt->error . " -->\n";
                $donors = null;
            } else {
                $result = $stmt->get_result();
                $donors = $result;
                echo "<!-- Debug: Query returned " . $donors->num_rows . " donors -->\n";
            }
        }
    } else {
        // No filters provided, don't return all donors
        $donors = null;
        echo "<!-- Debug: No filters provided, returning no results -->\n";
    }
}
?>

<style>
    /* Hero Section */
    .blood-hero {
        background: linear-gradient(135deg, #dc3545 0%, #b02a37 50%, #8b1e24 100%);
        padding: 60px 20px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    
    .blood-hero::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="rgba(255,255,255,0.05)"/></svg>');
        background-size: 100px;
        animation: float 20s infinite linear;
    }
    
    @keyframes float {
        0% { transform: translateY(0) rotate(0deg); }
        100% { transform: translateY(-100px) rotate(360deg); }
    }
    
    .blood-hero-content {
        position: relative;
        z-index: 1;
        max-width: 700px;
        margin: 0 auto;
    }
    
    .blood-hero-icon {
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
    
    .blood-hero-icon i {
        font-size: 45px;
        color: white;
    }
    
    .blood-hero h1 {
        color: white;
        font-size: 42px;
        font-weight: 800;
        margin: 0 0 15px;
        text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    }
    
    .blood-hero p {
        color: rgba(255, 255, 255, 0.95);
        font-size: 18px;
        margin: 0;
    }

    /* Stats Section */
    .stats-section {
        padding: 50px 20px;
        background: linear-gradient(180deg, #f8f9fa 0%, #ffffff 100%);
    }
    
    .stats-container {
        max-width: 1100px;
        margin: 0 auto;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 25px;
    }
    
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 35px 25px;
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
        background: linear-gradient(90deg, #dc3545, #ff6b6b);
    }
    
    .stat-card:hover {
        transform: translateY(-10px);
        box-shadow: 0 20px 50px rgba(220, 53, 69, 0.15);
    }
    
    .stat-icon {
        width: 80px;
        height: 80px;
        background: linear-gradient(135deg, #dc3545, #b02a37);
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        box-shadow: 0 10px 30px rgba(220, 53, 69, 0.3);
    }
    
    .stat-icon i {
        font-size: 35px;
        color: white;
    }
    
    .stat-number {
        font-size: 48px;
        font-weight: 800;
        background: linear-gradient(135deg, #dc3545, #b02a37);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 5px;
    }
    
    .stat-label {
        color: #6c757d;
        font-size: 16px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    /* Search Section */
    .search-section {
        padding: 50px 20px;
        background: white;
    }
    
    .search-container {
        max-width: 900px;
        margin: 0 auto;
    }
    
    .search-card {
        background: white;
        border-radius: 25px;
        padding: 45px;
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
        color: #dc3545;
    }
    
    .search-form {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr 1fr;
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
    
    .form-group select {
        width: 100%;
        padding: 16px 20px;
        border: 2px solid #e9ecef;
        border-radius: 12px;
        font-size: 16px;
        background: #f8f9fa;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .form-group select:focus {
        outline: none;
        border-color: #dc3545;
        background: white;
        box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.1);
    }
    
    .btn-search {
        padding: 16px 35px;
        background: linear-gradient(135deg, #dc3545 0%, #b02a37 100%);
        color: white;
        border: none;
        border-radius: 12px;
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    
    .btn-search:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(220, 53, 69, 0.4);
    }

    /* Blood Group Quick Select */
    .quick-select {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: center;
        margin-bottom: 25px;
    }
    
    .blood-quick-btn {
        padding: 12px 20px;
        border: 2px solid #e9ecef;
        border-radius: 10px;
        background: white;
        cursor: pointer;
        font-size: 14px;
        font-weight: 700;
        color: #495057;
        transition: all 0.3s ease;
    }
    
    .blood-quick-btn:hover, .blood-quick-btn.active {
        border-color: #dc3545;
        background: linear-gradient(135deg, #dc3545, #b02a37);
        color: white;
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
        margin-bottom: 35px;
        padding: 20px 30px;
        background: white;
        border-radius: 15px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
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
        color: #28a745;
    }
    
    .count-badge {
        background: linear-gradient(135deg, #dc3545, #b02a37);
        color: white;
        padding: 10px 20px;
        border-radius: 30px;
        font-weight: 700;
        font-size: 14px;
        box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
    }
    
    .donor-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 25px;
    }
    
    .donor-card {
        background: white;
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    
    .donor-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(90deg, #dc3545, #ff6b6b);
    }
    
    .donor-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 50px rgba(220, 53, 69, 0.15);
    }
    
    .donor-header {
        display: flex;
        align-items: center;
        gap: 18px;
        margin-bottom: 18px;
    }
    
    .donor-avatar {
        width: 70px;
        height: 70px;
        background: linear-gradient(135deg, #dc3545, #b02a37);
        border-radius: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 8px 20px rgba(220, 53, 69, 0.3);
    }
    
    .donor-avatar i {
        font-size: 30px;
        color: white;
    }
    
    .donor-name {
        font-size: 20px;
        font-weight: 700;
        color: #212529;
        margin-bottom: 5px;
    }
    
    .blood-tag {
        display: inline-block;
        background: linear-gradient(135deg, #dc3545, #b02a37);
        color: white;
        padding: 6px 16px;
        border-radius: 20px;
        font-weight: 700;
        font-size: 14px;
        box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
    }
    
    .donor-info {
        margin-bottom: 20px;
    }
    
    .donor-info p {
        color: #6c757d;
        font-size: 15px;
        margin: 12px 0;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .donor-info p i {
        color: #dc3545;
        width: 20px;
        text-align: center;
    }
    
    .btn-call {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        background: linear-gradient(135deg, #28a745, #20c997);
        color: white;
        padding: 14px 25px;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 700;
        font-size: 15px;
        transition: all 0.3s ease;
        box-shadow: 0 5px 20px rgba(40, 167, 69, 0.3);
    }
    
    .btn-call:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(40, 167, 69, 0.4);
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
        background: linear-gradient(135deg, #dc3545 0%, #b02a37 100%);
        color: white;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 700;
        font-size: 16px;
        transition: all 0.3s ease;
        box-shadow: 0 5px 20px rgba(220, 53, 69, 0.3);
    }
    
    .btn-cta:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(220, 53, 69, 0.4);
    }
    
    .btn-cta i {
        margin-right: 10px;
    }

    @media (max-width: 768px) {
        .blood-hero { padding: 40px 20px; }
        .blood-hero h1 { font-size: 28px; }
        .blood-hero-icon { width: 70px; height: 70px; }
        .blood-hero-icon i { font-size: 30px; }
        .search-form { grid-template-columns: 1fr; }
        .btn-search { width: 100%; }
        .results-header { flex-direction: column; gap: 15px; text-align: center; }
        .donor-grid { grid-template-columns: 1fr; }
        .stat-number { font-size: 36px; }
    }
</style>

<!-- Hero Section -->
<section class="blood-hero">
    <div class="blood-hero-content">
        <div class="blood-hero-icon">
            <i class="fas fa-hand-holding-heart"></i>
        </div>
        <h1>Find Blood Donors</h1>
        <p>Connect with life-savers in your area. Every drop counts.</p>
    </div>
</section>

<!-- Stats Section -->
<section class="stats-section">
    <div class="stats-container">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-number">2,500+</div>
            <div class="stat-label">Active Donors</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-tint"></i></div>
            <div class="stat-number">10,000+</div>
            <div class="stat-label">Lives Saved</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-map-marker-alt"></i></div>
            <div class="stat-number">64</div>
            <div class="stat-label">Districts</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-heart"></i></div>
            <div class="stat-number">500+</div>
            <div class="stat-label">Monthly Donations</div>
        </div>
    </div>
</section>

<!-- Search Section -->
<section class="search-section">
    <div class="search-container">
        <div class="search-card">
            <h3><i class="fas fa-search"></i> Search for Blood Donors</h3>
            
            <form method="GET" class="search-form" onsubmit="return validateSearch()">
                <div class="form-group">
                    <label>Blood Group</label>
                    <select name="blood_group" id="bloodGroup">
                        <option value="">Any Blood Group</option>
                        <option value="A+" <?php echo (isset($_GET['blood_group']) && $_GET['blood_group'] == 'A+') ? 'selected' : ''; ?>>A+</option>
                        <option value="A-" <?php echo (isset($_GET['blood_group']) && $_GET['blood_group'] == 'A-') ? 'selected' : ''; ?>>A-</option>
                        <option value="B+" <?php echo (isset($_GET['blood_group']) && $_GET['blood_group'] == 'B+') ? 'selected' : ''; ?>>B+</option>
                        <option value="B-" <?php echo (isset($_GET['blood_group']) && $_GET['blood_group'] == 'B-') ? 'selected' : ''; ?>>B-</option>
                        <option value="AB+" <?php echo (isset($_GET['blood_group']) && $_GET['blood_group'] == 'AB+') ? 'selected' : ''; ?>>AB+</option>
                        <option value="AB-" <?php echo (isset($_GET['blood_group']) && $_GET['blood_group'] == 'AB-') ? 'selected' : ''; ?>>AB-</option>
                        <option value="O+" <?php echo (isset($_GET['blood_group']) && $_GET['blood_group'] == 'O+') ? 'selected' : ''; ?>>O+</option>
                        <option value="O-" <?php echo (isset($_GET['blood_group']) && $_GET['blood_group'] == 'O-') ? 'selected' : ''; ?>>O-</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Division</label>
                    <select name="division_id" id="divisionSelect" onchange="loadDistricts()">
                        <option value="">Any Division</option>
                        <?php 
                        $divisions->data_seek(0);
                        while ($division = $divisions->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $division['id']; ?>" <?php echo (isset($_GET['division_id']) && $_GET['division_id'] == $division['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($division['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>District</label>
                    <select name="district_id" id="districtSelect">
                        <option value="">Any District</option>
                        <?php 
                        // If division is selected, show districts for that division
                        if (isset($_GET['division_id']) && $_GET['division_id'] > 0) {
                            $selected_division = (int)$_GET['division_id'];
                            $districts_stmt = $conn->prepare("SELECT id, name FROM districts WHERE division_id = ? ORDER BY name ASC");
                            $districts_stmt->bind_param("i", $selected_division);
                            $districts_stmt->execute();
                            $districts_result = $districts_stmt->get_result();
                            while ($district = $districts_result->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $district['id']; ?>" <?php echo (isset($_GET['district_id']) && $_GET['district_id'] == $district['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($district['name']); ?></option>
                        <?php 
                            endwhile;
                        }
                        ?>
                    </select>
                </div>
                <button type="submit" name="find" class="btn-search">
                    <i class="fas fa-search"></i> Search
                </button>
            </form>
        </div>
    </div>
</section>

<!-- Results Section -->
<?php if (isset($_GET['find'])): ?>
    <section class="results-section">
        <div class="results-container">
            <div class="results-header">
                <h4><i class="fas fa-check-circle"></i> Search Results</h4>
                <span class="count-badge"><i class="fas fa-user"></i> <?php echo $donors ? $donors->num_rows : 0; ?> Donor(s) Found</span>
            </div>
            
            <?php if ($donors && $donors->num_rows > 0): ?>
                <div class="donor-grid">
                    <?php while ($donor = $donors->fetch_assoc()): ?>
                        <div class="donor-card">
                            <div class="donor-header">
                                <div class="donor-avatar">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div>
                                    <div class="donor-name"><?php echo htmlspecialchars($donor['full_name']); ?></div>
                                    <span class="blood-tag"><?php echo htmlspecialchars($donor['blood_group']); ?></span>
                                </div>
                            </div>
                            <div class="donor-info">
                                <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($donor['district_name'] ?? 'N/A'); ?></p>
                                <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($donor['phone']); ?></p>
                                <?php if (!empty($donor['last_donation_date'])): ?>
                                <p><i class="fas fa-calendar"></i> Last donated: <?php echo htmlspecialchars($donor['last_donation_date']); ?></p>
                                <?php endif; ?>
                            </div>
                            <a href="tel:<?php echo htmlspecialchars($donor['phone']); ?>" class="btn-call">
                                <i class="fas fa-phone-alt"></i> Call Now
                            </a>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-search"></i>
                    <h4>No Donors Found</h4>
                    <p>No donors available for the selected blood group and district. Try different options or become a donor yourself.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<!-- CTA Section -->
<section class="cta-section">
    <h3><i class="fas fa-hand-holding-heart"></i> Become a Blood Donor</h3>
    <p>Your one donation can save up to three lives. Join our community of life-savers today.</p>
    <a href="register.php" class="btn-cta"><i class="fas fa-user-plus"></i> Register as Donor</a>
</section>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
function loadDistricts() {
    var divisionId = document.getElementById('divisionSelect').value;
    var districtSelect = document.getElementById('districtSelect');
    
    // Clear existing options
    districtSelect.innerHTML = '<option value="">Any District</option>';
    
    if (divisionId) {
        // Fetch districts via AJAX
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'ajax_handler.php?action=get_districts&division_id=' + divisionId, true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState == 4 && xhr.status == 200) {
                try {
                    var districts = JSON.parse(xhr.responseText);
                    districts.forEach(function(district) {
                        var option = document.createElement('option');
                        option.value = district.id;
                        option.textContent = district.name;
                        districtSelect.appendChild(option);
                    });
                    // Reinitialize Select2 for district dropdown
                    $('#districtSelect').select2({
                        placeholder: 'Any District',
                        allowClear: true
                    });
                } catch (e) {
                    console.error('Error parsing districts:', e);
                }
            }
        };
        xhr.send();
    } else {
        // Reinitialize Select2
        $('#districtSelect').select2({
            placeholder: 'Any District',
            allowClear: true
        });
    }
}

function validateSearch() {
    var bloodGroup = document.getElementById('bloodGroup').value;
    var divisionId = document.getElementById('divisionSelect').value;
    var districtId = document.getElementById('districtSelect').value;
    
    // At least one filter must be selected
    if (!bloodGroup && !divisionId && !districtId) {
        alert('Please select at least one filter (Blood Group, Division, or District) to search.');
        return false;
    }
    
    return true;
}

$(document).ready(function() {
    $('#bloodGroup, #divisionSelect').select2({
        placeholder: 'Select option',
        allowClear: true
    });
    
    $('#districtSelect').select2({
        placeholder: 'Any District',
        allowClear: true
    });
    
    // Smooth scroll for anchor links
    $('a[href^="#"]').on('click', function(e) {
        e.preventDefault();
        $('html, body').animate({
            scrollTop: $($(this).attr('href')).offset().top
        }, 500);
    });
});
</script>

<?php include_once('includes/footer.php'); ?>
