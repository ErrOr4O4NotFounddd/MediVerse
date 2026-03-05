<?php
session_start();
require_once('includes/db_config.php');

// Get search parameters
$search_district = $_GET['district'] ?? '';
$search_type = $_GET['type'] ?? '';

// Build query
$where_clauses = ["p.status = 'Active'"];
$params = [];
$types = "";

if (!empty($search_district)) {
    $where_clauses[] = "(p.address LIKE ? OR d.name LIKE ?)";
    $search_param = "%$search_district%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if (!empty($search_type)) {
    $where_clauses[] = "p.pharmacy_type = ?";
    $params[] = $search_type;
    $types .= "s";
}

$where_sql = implode(' AND ', $where_clauses);

// Fetch pharmacies (correlated subquery replaced with LEFT JOIN)
$sql = "
    SELECT 
        p.*,
        hb.branch_name,
        IF(p.pharmacy_type = 'Hospital', d.name, SUBSTRING_INDEX(p.address, ',', -1)) as district,
        h.name as hospital_name,
        COALESCE(stock.med_count, 0) as available_medicines
    FROM pharmacies p
    LEFT JOIN hospital_branches hb ON p.branch_id = hb.id
    LEFT JOIN hospitals h ON hb.hospital_id = h.id
    LEFT JOIN upazilas u ON hb.upazila_id = u.id
    LEFT JOIN districts d ON u.district_id = d.id
    LEFT JOIN (
        SELECT pharmacy_id, COUNT(*) as med_count
        FROM pharmacy_stock WHERE quantity > 0
        GROUP BY pharmacy_id
    ) stock ON stock.pharmacy_id = p.id
    WHERE $where_sql
    ORDER BY p.pharmacy_type, p.name
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$pharmacies = $stmt->get_result();

// Get all districts for filter (show all 64 districts)
$districts = $conn->query("SELECT name as district FROM districts ORDER BY district ASC");

// Get statistics (single query instead of 3)
$pharmacy_stats = $conn->query("
    SELECT
        COUNT(*) as total,
        SUM(pharmacy_type = 'Hospital' AND status = 'Active') as hospital,
        SUM(pharmacy_type = 'Outside' AND status = 'Active') as outside_count
    FROM pharmacies WHERE status = 'Active'
")->fetch_assoc();
$pharmacy_count = $pharmacy_stats['total'];
$hospital_pharmacy_count = $pharmacy_stats['hospital'];
$outside_pharmacy_count = $pharmacy_stats['outside_count'];

include_once('includes/header.php');
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* Pharmacies Listing Page Styles */
.pharmacy-listing-page {
    background: linear-gradient(180deg, #f8f9fa 0%, #ffffff 100%);
    min-height: 100vh;
    padding-bottom: 40px;
}

/* Hero Section - Matching Doctor Page Style */
.pharmacy-hero {
    background: linear-gradient(135deg, #27ae60 0%, #1e8449 50%, #145a32 100%);
    padding: 60px 20px;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.pharmacy-hero::before {
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

.pharmacy-hero-content {
    position: relative;
    z-index: 1;
    max-width: 700px;
    margin: 0 auto;
}

.pharmacy-hero-icon {
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

.pharmacy-hero-icon i {
    font-size: 45px;
    color: white;
}

.pharmacy-hero h1 {
    color: white;
    font-size: 42px;
    font-weight: 800;
    margin: 0 0 15px;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

.pharmacy-hero p {
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
    background: linear-gradient(90deg, #27ae60, #2ecc71);
}

.stat-card:nth-child(2)::before { background: linear-gradient(90deg, #3498db, #5dade2); }
.stat-card:nth-child(3)::before { background: linear-gradient(90deg, #9b59b6, #af7ac5); }

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
    font-size: 30px;
}

.stat-icon.green {
    background: linear-gradient(135deg, #d4efdf 0%, #a9dfbf 100%);
    color: #27ae60;
}

.stat-icon.blue {
    background: linear-gradient(135deg, #d6eaf8 0%, #aed6f1 100%);
    color: #3498db;
}

.stat-icon.purple {
    background: linear-gradient(135deg, #ebdef0 0%, #d7bde2 100%);
    color: #9b59b6;
}

.stat-number {
    font-size: 32px;
    font-weight: 800;
    color: #1a1a2e;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 14px;
    color: #5a6c7d;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Main Content */
.pharmacy-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

/* Search Filter Card */
.search-filter-card {
    background: #fff;
    border-radius: 20px;
    padding: 30px;
    margin: -30px auto 30px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
    position: relative;
    z-index: 10;
    max-width: 1100px;
}

.search-filter-card h2 {
    font-size: 20px;
    font-weight: 700;
    color: #1a1a2e;
    margin: 0 0 25px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.filter-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    align-items: end;
}

.filter-group {
    display: flex;
    flex-direction: column;
}

.filter-group label {
    font-size: 13px;
    font-weight: 600;
    color: #5a6c7d;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-group select {
    padding: 14px 18px;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 600;
    background: #fff;
    color: #1a1a2e;
    cursor: pointer;
    transition: all 0.3s ease;
}

.filter-group select:focus {
    outline: none;
    border-color: #27ae60;
    box-shadow: 0 0 0 4px rgba(39, 174, 96, 0.1);
}

.btn-search {
    padding: 14px 28px;
    background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 10px;
    justify-content: center;
}

.btn-search:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(39, 174, 96, 0.35);
}

/* Pharmacy Grid */
.pharmacy-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 25px;
}

/* Pharmacy Card */
.pharmacy-card {
    background: #fff;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
    transition: all 0.3s ease;
    position: relative;
}

.pharmacy-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 40px rgba(39, 174, 96, 0.15);
}

.pharmacy-card-header {
    padding: 25px 25px 20px;
    position: relative;
}

.pharmacy-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 15px;
}

.pharmacy-badge.hospital {
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    color: #1565c0;
}

.pharmacy-badge.outside {
    background: linear-gradient(135deg, #fce4ec 0%, #f8bbd9 100%);
    color: #c62828;
}

.pharmacy-info h3 {
    font-size: 20px;
    font-weight: 700;
    color: #1a1a2e;
    margin: 0 0 12px 0;
}

.pharmacy-location {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    font-size: 14px;
    color: #5a6c7d;
    margin-bottom: 8px;
    line-height: 1.5;
}

.pharmacy-location i {
    color: #27ae60;
    margin-top: 3px;
    flex-shrink: 0;
}

.pharmacy-contact-info {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #f0f0f0;
}

.contact-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: #5a6c7d;
}

.contact-item i {
    color: #27ae60;
}

.pharmacy-stats {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 15px;
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    border-radius: 10px;
    margin-top: 15px;
}

.pharmacy-stats i {
    font-size: 18px;
    color: #27ae60;
}

.pharmacy-stats span {
    font-size: 14px;
    font-weight: 600;
    color: #2e7d32;
}

.pharmacy-card-actions {
    padding: 20px 25px 25px;
    display: flex;
    gap: 12px;
}

.btn-visit, .btn-delivery {
    flex: 1;
    padding: 14px 18px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 700;
    text-align: center;
    text-decoration: none;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-visit {
    background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
    color: #fff;
    border: none;
}

.btn-visit:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(39, 174, 96, 0.35);
    color: #fff;
}

.btn-delivery {
    background: #fff;
    color: #e74c3c;
    border: 2px solid #e74c3c;
}

.btn-delivery:hover {
    background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
    color: #fff;
    border-color: transparent;
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(231, 76, 60, 0.35);
}

/* No Results */
.no-results {
    text-align: center;
    padding: 80px 40px;
    background: #fff;
    border-radius: 20px;
    grid-column: 1 / -1;
}

.no-results i {
    font-size: 64px;
    color: #cbd5e0;
    margin-bottom: 20px;
}

.no-results h3 {
    font-size: 24px;
    font-weight: 700;
    color: #1a1a2e;
    margin: 0 0 10px 0;
}

.no-results p {
    color: #718096;
    margin: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .pharmacy-hero {
        padding: 50px 15px;
    }
    
    .pharmacy-hero h1 {
        font-size: 32px;
    }
    
    .pharmacy-hero p {
        font-size: 16px;
    }
    
    .pharmacy-hero-icon {
        width: 80px;
        height: 80px;
    }
    
    .pharmacy-hero-icon i {
        font-size: 35px;
    }
    
    .search-filter-card {
        margin: -20px 15px 25px;
        padding: 20px;
    }
    
    .filter-form {
        grid-template-columns: 1fr;
    }
    
    .pharmacy-grid {
        grid-template-columns: 1fr;
    }
    
    .pharmacy-card-actions {
        flex-direction: column;
    }
}
</style>

<div class="pharmacy-listing-page">
    <!-- Hero Section -->
    <div class="pharmacy-hero">
        <div class="pharmacy-hero-content">
            <div class="pharmacy-hero-icon">
                <i class="fas fa-pills"></i>
            </div>
            <h1>💊 Pharmacies</h1>
            <p>Find hospital and outside pharmacies near you</p>
        </div>
    </div>

    <!-- Stats Section -->
    <div class="stats-section">
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="fas fa-store"></i>
                </div>
                <div class="stat-number"><?= number_format($pharmacy_count) ?></div>
                <div class="stat-label">Total Pharmacies</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue">
                    <i class="fas fa-hospital"></i>
                </div>
                <div class="stat-number"><?= number_format($hospital_pharmacy_count) ?></div>
                <div class="stat-label">Hospital Pharmacies</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="stat-number"><?= number_format($outside_pharmacy_count) ?></div>
                <div class="stat-label">Outside Pharmacies</div>
            </div>
        </div>
    </div>

    <div class="pharmacy-container">
        <!-- Search Filter Card -->
        <div class="search-filter-card">
            <h2>🔍 Find Your Pharmacy</h2>
            
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <label>District / Area</label>
                    <select name="district">
                        <option value="">All Districts</option>
                        <?php if($districts): while($d = $districts->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($d['district']) ?>" <?= $search_district === $d['district'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['district']) ?>
                            </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Pharmacy Type</label>
                    <select name="type">
                        <option value="">All Types</option>
                        <option value="Hospital" <?= $search_type === 'Hospital' ? 'selected' : '' ?>>🏥 Hospital Pharmacy</option>
                        <option value="Outside" <?= $search_type === 'Outside' ? 'selected' : '' ?>>💊 Outside Pharmacy</option>
                    </select>
                </div>

                <div class="filter-group">
                    <button type="submit" class="btn-search">
                        🔍 Search
                    </button>
                </div>
            </form>
        </div>

        <!-- Pharmacy List -->
        <div class="pharmacy-grid">
            <?php if($pharmacies && $pharmacies->num_rows > 0): ?>
                <?php while($pharmacy = $pharmacies->fetch_assoc()): ?>
                <div class="pharmacy-card">
                    <div class="pharmacy-card-header">
                        <div class="pharmacy-badge <?= strtolower($pharmacy['pharmacy_type']) ?>">
                            <?= $pharmacy['pharmacy_type'] === 'Hospital' ? '🏥 Hospital' : '💊 Outside' ?>
                        </div>
                        
                        <div class="pharmacy-info">
                            <h3><?= htmlspecialchars($pharmacy['name']) ?></h3>
                            
                            <?php if($pharmacy['pharmacy_type'] === 'Hospital'): ?>
                                <p class="pharmacy-location">
                                    <i class="fas fa-hospital"></i>
                                    <?= htmlspecialchars($pharmacy['hospital_name']) ?> - <?= htmlspecialchars($pharmacy['branch_name']) ?>
                                </p>
                                <p class="pharmacy-location">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?= htmlspecialchars($pharmacy['district']) ?>
                                </p>
                            <?php else: ?>
                                <p class="pharmacy-location">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?= htmlspecialchars($pharmacy['address']) ?>
                                </p>
                            <?php endif; ?>

                            <div class="pharmacy-contact-info">
                                <div class="contact-item">
                                    <i class="fas fa-phone"></i>
                                    <?= htmlspecialchars($pharmacy['phone']) ?>
                                </div>
                                <div class="contact-item">
                                    <i class="fas fa-envelope"></i>
                                    <?= htmlspecialchars($pharmacy['email']) ?>
                                </div>
                            </div>

                            <div class="pharmacy-stats">
                                <i class="fas fa-pills"></i>
                                <span><?= $pharmacy['available_medicines'] ?> Medicines Available</span>
                            </div>
                        </div>
                    </div>

                    <div class="pharmacy-card-actions">
                        <a href="pharmacy_visit.php?id=<?= $pharmacy['id'] ?>" class="btn-visit">
                            👁️ View Medicines
                        </a>
                        <?php if($pharmacy['pharmacy_type'] === 'Outside'): ?>
                            <a href="pharmacy_delivery.php?pharmacy_id=<?= $pharmacy['id'] ?>" class="btn-delivery">
                                🚚 Home Delivery
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="no-results">
                    <i class="fas fa-search"></i>
                    <h3>No pharmacies found</h3>
                    <p>Try adjusting your search filters</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$stmt->close();
$conn->close();
include_once('includes/footer.php');
?>
