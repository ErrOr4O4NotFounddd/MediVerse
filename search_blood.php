<?php
include_once('includes/db_config.php');
include_once('includes/header.php');
include_once('includes/csrf.php');

// Blood Request Submission logic removed.


$divisions_stmt = $conn->prepare("SELECT id, name FROM divisions ORDER BY name ASC");
$divisions_stmt->execute();
$divisions_result = $divisions_stmt->get_result();
$divisions = [];
while ($row = $divisions_result->fetch_assoc()) {
    $divisions[] = $row;
}
$blood_groups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

// Live Blood Requests fetch logic removed.
$live_requests = [];


$donors = null;
$hospitals = null;
$search_performed = false;

if (isset($_GET['search'])) {
    $search_type = $_GET['search_type'] ?? 'donor';
    
    // Get values based on search type
    if ($search_type == 'donor') {
        $blood_group = trim($_GET['blood_group_donor'] ?? '');
        $division_id = (int)($_GET['division_id_donor'] ?? 0);
        $district_id = (int)($_GET['district_id_donor'] ?? 0);
    } else {
        $blood_group = trim($_GET['blood_group_hospital'] ?? '');
        $division_id = (int)($_GET['division_id_hospital'] ?? 0);
        $district_id = (int)($_GET['district_id_hospital'] ?? 0);
    }
    
    $search_performed = true;

    if ($search_type == 'donor') {
        
        $sql = "SELECT u.full_name, u.phone, u.blood_group, 
                COALESCE(d.name, 'Unknown') as district_name,
                d.name as district_name_raw
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
            // Use d.division_id from the districts table (division_id removed from users)
            $sql .= " AND d.division_id = ?";
            $params[] = $division_id;
            $types .= 'i';
        }

        $sql .= " ORDER BY d.name ASC, u.full_name ASC LIMIT 20";



        // Execute query if at least one filter is provided
        if (count($params) > 0) {
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if (!empty($types)) {
                    $stmt->bind_param($types, ...$params);
                }
                if ($stmt->execute()) {
                    $donors = $stmt->get_result();
                } else {
                    $donors = null;
                }
            } else {
                $donors = null;
            }
        } else {
            // If no filters provided, don't return all donors
            $donors = null;
        }
    } else {
        // Check if blood_bank_stock table exists
        $table_check = $conn->query("SHOW TABLES LIKE 'blood_bank_stock'");
        if ($table_check && $table_check->num_rows > 0) {
            $sql = "SELECT hospital_name, COALESCE(NULLIF(hb.blood_bank_hotline, ''), vbs.branch_name) as phone, district_name,
                    blood_group, units_available
                    FROM vw_blood_bank_stock vbs
                    JOIN hospital_branches hb ON vbs.branch_id = hb.id
                    WHERE 1=1";
            $params = [];
            $types = '';
            
            // Blood group filter
            if (!empty($blood_group)) {
                $sql .= " AND vbs.blood_group = ?";
                $params[] = $blood_group;
                $types .= 's';
            }
            
            // District filter (takes priority over division)
            if ($district_id > 0) {
                $sql .= " AND vbs.district_name = (SELECT name FROM districts WHERE id = ?)";
                $params[] = $district_id;
                $types .= 'i';
            } elseif ($division_id > 0) {
                // Division filter (only if district not selected)
                $sql .= " AND vbs.division_name = (SELECT name FROM divisions WHERE id = ?)";
                $params[] = $division_id;
                $types .= 'i';
            }
            
            $sql .= " ORDER BY vbs.district_name ASC, vbs.hospital_name ASC, vbs.units_available DESC LIMIT 20";
            

            
            if (count($params) > 0) {
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    if (!empty($types)) {
                        $stmt->bind_param($types, ...$params);
                    }
                    if ($stmt->execute()) {
                        $hospitals = $stmt->get_result();
                    } else {
                        $hospitals = null;
                    }
                } else {
                    $hospitals = null;
                }
            } else {
                // No filters - don't show all hospitals
                $hospitals = null;
            }
        } else {
            $hospitals = null;
        }
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
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 25px;
    }
    
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 30px 20px;
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
    
    .stat-card:nth-child(2)::before { background: linear-gradient(90deg, #28a745, #20c997); }
    .stat-card:nth-child(3)::before { background: linear-gradient(90deg, #007bff, #6610f2); }
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
    
    .stat-card:nth-child(1) .stat-icon { background: linear-gradient(135deg, #dc3545, #b02a37); }
    .stat-card:nth-child(2) .stat-icon { background: linear-gradient(135deg, #28a745, #20c997); }
    .stat-card:nth-child(3) .stat-icon { background: linear-gradient(135deg, #007bff, #6610f2); }
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
    
    .stat-card:nth-child(1) .stat-number { background: linear-gradient(135deg, #dc3545, #b02a37); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    .stat-card:nth-child(2) .stat-number { background: linear-gradient(135deg, #28a745, #20c997); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    .stat-card:nth-child(3) .stat-number { background: linear-gradient(135deg, #007bff, #6610f2); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
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
    
    /* Tab Styles */
    .tab-container {
        display: flex;
        gap: 15px;
        margin-bottom: 30px;
    }
    
    .tab-btn {
        flex: 1;
        padding: 18px 25px;
        background: #f8f9fa;
        border: 3px solid #e9ecef;
        border-radius: 15px;
        font-size: 16px;
        font-weight: 700;
        color: #6c757d;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        transition: all 0.3s ease;
    }
    
    .tab-btn i {
        font-size: 20px;
    }
    
    .tab-btn:hover {
        border-color: #dc3545;
        color: #dc3545;
    }
    
    .tab-btn.active {
        background: linear-gradient(135deg, #dc3545, #b02a37);
        border-color: #dc3545;
        color: white;
        box-shadow: 0 8px 25px rgba(220, 53, 69, 0.3);
    }
    
    .tab-content {
        display: none;
    }
    
    .tab-content.active {
        display: block;
        animation: fadeIn 0.3s ease;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .search-form-grid {
        display: grid;
        /* grid-template-columns: repeat(4, 1fr); */
        gap: 15px;
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
        padding: 0 25px;
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
        gap: 8px;
        transition: all 0.3s ease;
        height: 52px;
        margin-top: 2px;
    }
    
    .btn-search:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(220, 53, 69, 0.4);
    }
    
    .search-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        justify-content: center;
        margin-top: 25px;
        padding-top: 25px;
        border-top: 2px solid #f0f0f0;
    }
    
    .legend-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        background: #f8f9fa;
        border-radius: 25px;
        font-size: 14px;
        font-weight: 600;
        color: #6c757d;
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
        padding: 10px 22px;
        border-radius: 30px;
        font-weight: 700;
        font-size: 14px;
        box-shadow: 0 5px 20px rgba(220, 53, 69, 0.3);
    }
    
    .results-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 25px;
    }
    
    .result-card {
        background: white;
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    
    .result-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
    }
    
    .result-card.donor::before {
        background: linear-gradient(90deg, #28a745, #20c997);
    }
    
    .result-card.hospital::before {
        background: linear-gradient(90deg, #007bff, #6610f2);
    }
    
    .result-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.12);
    }
    
    .card-header {
        display: flex;
        align-items: center;
        gap: 18px;
        margin-bottom: 18px;
    }
    
    .card-avatar {
        width: 65px;
        height: 65px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
    }
    
    .result-card.donor .card-avatar {
        background: linear-gradient(135deg, #28a745, #20c997);
    }
    
    .result-card.hospital .card-avatar {
        background: linear-gradient(135deg, #007bff, #6610f2);
    }
    
    .card-avatar i {
        font-size: 28px;
        color: white;
    }
    
    .card-title {
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
    
    .card-info {
        margin-bottom: 20px;
    }
    
    .card-info p {
        color: #6c757d;
        font-size: 15px;
        margin: 12px 0;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .card-info p i {
        width: 20px;
        text-align: center;
    }
    
    .result-card.donor .card-info p i { color: #28a745; }
    .result-card.hospital .card-info p i { color: #007bff; }
    
    .stock-info {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }
    
    .stock-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 15px;
        background: #f8f9fa;
        border-radius: 10px;
        font-size: 14px;
        color: #6c757d;
    }
    
    .stock-item i {
        color: #007bff;
    }
    
    .stock-units {
        background: linear-gradient(135deg, #28a745, #20c997);
        color: white;
        padding: 8px 15px;
        border-radius: 10px;
        font-weight: 700;
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    }
    
    .btn-action {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 14px 25px;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 700;
        font-size: 15px;
        transition: all 0.3s ease;
    }
    
    .result-card.donor .btn-action {
        background: linear-gradient(135deg, #28a745, #20c997);
        color: white;
        box-shadow: 0 5px 20px rgba(40, 167, 69, 0.3);
    }
    
    .result-card.hospital .btn-action {
        background: linear-gradient(135deg, #007bff, #6610f2);
        color: white;
        box-shadow: 0 5px 20px rgba(0, 123, 255, 0.3);
    }
    
    .btn-action:hover {
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
        background: linear-gradient(135deg, #dc3545, #b02a37);
        color: white;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 700;
        font-size: 16px;
        transition: all 0.3s ease;
    }
    
    .btn-cta:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(220, 53, 69, 0.4);
    }

    @media (max-width: 480px) {
        .results-grid { grid-template-columns: 1fr; }
    }

    /* Integrated Request Form Styles */
    .integrated-request-section {
        margin-top: 50px;
        padding: 50px 20px;
        background: #fff;
        border-top: 2px solid #f0f0f0;
    }
    .request-box {
        max-width: 900px;
        margin: 0 auto;
        background: #fdfdfd;
        border: 2px dashed #dc3545;
        border-radius: 20px;
        padding: 40px;
    }
    .request-box h2 {
        color: #dc3545;
        margin-bottom: 20px;
        text-align: center;
    }
    .request-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
    }
    .full-width { grid-column: 1 / -1; }

    /* Live Requests Section */
    .live-requests-section {
        padding: 50px 20px;
        background: #fdfdfd;
    }
    .live-requests-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 20px;
        max-width: 1200px;
        margin: 30px auto;
    }
    .request-card {
        background: white;
        border-radius: 15px;
        padding: 20px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        border: 1px solid #eee;
        transition: transform 0.3s;
    }
    .request-card:hover { transform: translateY(-5px); }
    .urgency-badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        color: white;
    }
</style>

<?php
// Get Blood Statistics for the stats section
$stats_sql = "SELECT 
    (SELECT COUNT(*) FROM users WHERE is_donor = 1 AND (donor_availability = 'Available' OR donor_availability = '') AND is_active = 1 AND deleted_at IS NULL) as total_donors,
    (SELECT COUNT(DISTINCT hb.hospital_id) FROM blood_bank_stock bs JOIN hospital_branches hb ON bs.branch_id = hb.id WHERE bs.units_available > 0) as hospitals_with_blood,
    (SELECT COALESCE(SUM(units_available), 0) FROM blood_bank_stock) as total_blood_units,
    (SELECT COUNT(*) FROM bookings WHERE status = 'Completed') as lives_saved";

$stats = $conn->query($stats_sql)->fetch_assoc();

$total_donors = $stats['total_donors'];
$hospitals_with_blood = $stats['hospitals_with_blood'];
$total_blood_units = $stats['total_blood_units'];
$lives_saved = $stats['lives_saved'];
?>

<!-- Hero Section -->
<section class="blood-hero">
    <div class="blood-hero-content">
        <div class="blood-hero-icon">
            <i class="fas fa-tint"></i>
        </div>
        <h1>Search Blood</h1>
        <p>Find blood donors or hospital blood stock in your area</p>
    </div>
</section>

<!-- Stats Section -->
<section class="stats-section">
    <div class="stats-container">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-number"><?php echo number_format($total_donors); ?></div>
            <div class="stat-label">Active Donors</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-hospital"></i></div>
            <div class="stat-number"><?php echo number_format($hospitals_with_blood); ?></div>
            <div class="stat-label">Hospitals</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-tint"></i></div>
            <div class="stat-number"><?php echo number_format($total_blood_units); ?></div>
            <div class="stat-label">Blood Units</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-heart"></i></div>
            <div class="stat-number"><?php echo number_format($lives_saved); ?></div>
            <div class="stat-label">Lives Saved</div>
        </div>
    </div>
</section>

<!-- Search Section -->
<section class="search-section">
    <div class="search-container">
        <div class="search-card">
            <h3><i class="fas fa-search"></i> Search Blood</h3>
            
            <form method="GET">
                <input type="hidden" name="search_type" id="search_type" value="<?php echo isset($_GET['search_type']) ? $_GET['search_type'] : 'donor'; ?>">
                
                <div class="tab-container">
                    <button type="button" class="tab-btn active" data-tab="donor" onclick="switchTab('donor')">
                        <i class="fas fa-user"></i> Find Donor
                    </button>
                    <button type="button" class="tab-btn" data-tab="hospital" onclick="switchTab('hospital')">
                        <i class="fas fa-hospital"></i> Hospital Stock
                    </button>
                </div>

                <div id="donor-tab" class="tab-content active">
                    <div class="search-form-grid">
                        <div class="form-group">
                            <label>Blood Group</label>
                            <select name="blood_group_donor" id="blood_group_donor">
                                <option value="">Any Blood Group</option>
                                <?php foreach ($blood_groups as $bg): ?>
                                    <option value="<?php echo $bg; ?>" <?php echo (isset($_GET['blood_group_donor']) && $_GET['blood_group_donor'] == $bg) ? 'selected' : ''; ?>><?php echo $bg; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Division</label>
                            <select name="division_id_donor" id="division_id_donor" onchange="loadDistricts('donor')">
                                <option value="">Any Division</option>
                                <?php foreach ($divisions as $division): ?>
                                    <option value="<?php echo $division['id']; ?>" <?php echo (isset($_GET['division_id_donor']) && $_GET['division_id_donor'] == $division['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($division['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>District</label>
                            <select name="district_id_donor" id="district_id_donor">
                                <option value="">Any District</option>
                                <?php 
                                // If division is selected, show districts for that division
                                if (isset($_GET['division_id_donor']) && $_GET['division_id_donor'] > 0) {
                                    $selected_division = (int)$_GET['division_id_donor'];
                                    $districts_stmt = $conn->prepare("SELECT id, name FROM districts WHERE division_id = ? ORDER BY name ASC");
                                    $districts_stmt->bind_param("i", $selected_division);
                                    $districts_stmt->execute();
                                    $districts_result = $districts_stmt->get_result();
                                    while ($district = $districts_result->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $district['id']; ?>" <?php echo (isset($_GET['district_id_donor']) && $_GET['district_id_donor'] == $district['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($district['name']); ?></option>
                                <?php 
                                    endwhile;
                                }
                                ?>
                            </select>
                        </div>
                        <button type="submit" name="search" class="btn-search" onclick="document.getElementById('search_type').value='donor'">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </div>

                <div id="hospital-tab" class="tab-content">
                    <div class="search-form-grid">
                        <div class="form-group">
                            <label>Blood Group</label>
                            <select name="blood_group_hospital" id="blood_group_hospital">
                                <option value="">Any Blood Group</option>
                                <?php foreach ($blood_groups as $bg): ?>
                                    <option value="<?php echo $bg; ?>" <?php echo (isset($_GET['blood_group_hospital']) && $_GET['blood_group_hospital'] == $bg) ? 'selected' : ''; ?>><?php echo $bg; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Division</label>
                            <select name="division_id_hospital" id="division_id_hospital" onchange="loadDistricts('hospital')">
                                <option value="">Any Division</option>
                                <?php foreach ($divisions as $division): ?>
                                    <option value="<?php echo $division['id']; ?>" <?php echo (isset($_GET['division_id_hospital']) && $_GET['division_id_hospital'] == $division['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($division['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>District</label>
                            <select name="district_id_hospital" id="district_id_hospital">
                                <option value="">Any District</option>
                                <?php 
                                // If division is selected for hospital search, show districts for that division
                                if (isset($_GET['division_id_hospital']) && $_GET['division_id_hospital'] > 0) {
                                    $selected_division = (int)$_GET['division_id_hospital'];
                                    $districts_stmt = $conn->prepare("SELECT id, name FROM districts WHERE division_id = ? ORDER BY name ASC");
                                    $districts_stmt->bind_param("i", $selected_division);
                                    $districts_stmt->execute();
                                    $districts_result = $districts_stmt->get_result();
                                    while ($district = $districts_result->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $district['id']; ?>" <?php echo (isset($_GET['district_id_hospital']) && $_GET['district_id_hospital'] == $district['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($district['name']); ?></option>
                                <?php 
                                    endwhile;
                                }
                                ?>
                            </select>
                        </div>
                        <button type="submit" name="search" class="btn-search" onclick="document.getElementById('search_type').value='hospital'">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </div>
            </form>
            
            <div class="search-legend">
                <div class="legend-item"><i class="fas fa-tint"></i> 8 Blood Groups</div>
                <div class="legend-item"><i class="fas fa-hospital"></i> <?= number_format($hospitals_with_blood) ?> Hospitals</div>
                <div class="legend-item"><i class="fas fa-clock"></i> Real-time Data</div>
            </div>
        </div>
    </div>
</section>

<!-- Results Section -->
<?php if ($search_performed): ?>
    <section class="results-section">
        <div class="results-container">
            <div class="results-header">
                <h4><i class="fas fa-check-circle"></i> Search Results</h4>
                <?php if ($donors !== null): ?>
                    <span class="count-badge"><i class="fas fa-user"></i> <?php echo $donors->num_rows; ?> Donor(s) Found</span>
                <?php elseif ($hospitals !== null): ?>
                    <span class="count-badge"><i class="fas fa-hospital"></i> <?php echo $hospitals->num_rows; ?> Hospital(s) Found</span>
                <?php endif; ?>
            </div>
            
            <?php if ($donors && $donors->num_rows > 0): ?>
                <div class="results-grid">
                    <?php while ($donor = $donors->fetch_assoc()): ?>
                        <div class="result-card donor">
                            <div class="card-header">
                                <div class="card-avatar">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div>
                                    <div class="card-title"><?php echo htmlspecialchars($donor['full_name']); ?></div>
                                    <span class="blood-tag"><?php echo htmlspecialchars($donor['blood_group']); ?></span>
                                </div>
                            </div>
                            <div class="card-info">
                                <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($donor['district_name'] ?? 'N/A'); ?></p>
                                <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($donor['phone']); ?></p>
                            </div>
                            <a href="tel:<?php echo htmlspecialchars($donor['phone']); ?>" class="btn-action">
                                <i class="fas fa-phone-alt"></i> Call Donor
                            </a>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php elseif ($hospitals && $hospitals->num_rows > 0): ?>
                <div class="results-grid">
                    <?php while ($hospital = $hospitals->fetch_assoc()): ?>
                        <div class="result-card hospital">
                            <div class="card-header">
                                <div class="card-avatar">
                                    <i class="fas fa-hospital"></i>
                                </div>
                                <div>
                                    <div class="card-title"><?php echo htmlspecialchars($hospital['hospital_name']); ?></div>
                                    <div class="card-title" style="font-size: 16px; font-weight: 500; color: #6c757d;"><?php echo htmlspecialchars($hospital['district_name']); ?></div>
                                </div>
                            </div>
                            <div class="card-info">
                                <div class="stock-info">
                                    <div class="stock-item">
                                        <i class="fas fa-tint"></i> <?php echo htmlspecialchars($hospital['blood_group']); ?>
                                    </div>
                                    <div class="stock-units">
                                        <?php echo $hospital['units_available']; ?> Units
                                    </div>
                                </div>
                                <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($hospital['district_name']); ?></p>
                                <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($hospital['phone']); ?></p>
                            </div>
                            <a href="tel:<?php echo htmlspecialchars($hospital['phone']); ?>" class="btn-action">
                                <i class="fas fa-phone-alt"></i> Call Hospital
                            </a>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-search-minus" style="font-size: 60px; color: #dc3545; margin-bottom: 20px;"></i>
                    <h4>কোন ফলাফল পাওয়া যায়নি (No Results Found)</h4>
                    <p>দুঃখিত, আপনার এলাকা বা গ্রুপ অনুযায়ী কোন রক্তদাতা বা হাসপাতালের তথ্য পাওয়া যায়নি।</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<!-- Blood Request sections removed -->


<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
        if (btn.dataset.tab === tab) btn.classList.add('active');
    });
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.getElementById(tab + '-tab').classList.add('active');
    document.getElementById('search_type').value = tab;
}

function loadDistricts(tabType) {
    var divisionId = document.getElementById('division_id_' + tabType).value;
    var districtSelect = document.getElementById('district_id_' + tabType);
    
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
                    $('#district_id_' + tabType).select2({
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
        $('#district_id_' + tabType).select2({
            placeholder: 'Any District',
            allowClear: true
        });
    }
}

$(document).ready(function() {
    // Set active tab based on search_type
    var searchType = '<?php echo isset($_GET["search_type"]) ? $_GET["search_type"] : "donor"; ?>';
    if (searchType === 'hospital') {
        switchTab('hospital');
    }
    
    $('#blood_group_donor, #division_id_donor, #blood_group_hospital, #division_id_hospital').select2({
        placeholder: 'Select option',
        allowClear: true
    });
    
    // Initialize Select2 for district dropdowns
    $('#district_id_donor, #district_id_hospital').select2({
        placeholder: 'Any District',
        allowClear: true
    });
    
    // Load districts if division is already selected
    <?php if (isset($_GET['division_id_donor']) && $_GET['division_id_donor'] > 0 && (!isset($_GET['search_type']) || $_GET['search_type'] == 'donor')): ?>
        loadDistricts('donor');
    <?php elseif (isset($_GET['division_id_hospital']) && $_GET['division_id_hospital'] > 0 && isset($_GET['search_type']) && $_GET['search_type'] == 'hospital'): ?>
        loadDistricts('hospital');
    <?php endif; ?>

    $('.select2-simple').select2({
        placeholder: 'সিলেক্ট করুন',
        allowClear: true
    });
});

// JavaScript helpers for blood requests removed.
</script>

<?php include_once('includes/footer.php'); ?>
