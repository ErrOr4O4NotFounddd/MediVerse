<?php
include_once('includes/db_config.php');
include_once('includes/header.php');

// --- Fetch Top Rated Doctors ---
$top_doctors_sql = "
    SELECT d.id as doctor_id, u.full_name, s.name_bn AS specialization, u.profile_image,
    COALESCE(AVG(r.rating), 0) AS avg_rating, COUNT(DISTINCT r.id) AS total_reviews
    FROM doctors d
    JOIN users u ON d.user_id = u.id
    LEFT JOIN specializations s ON d.specialization_id = s.id
    LEFT JOIN ratings r ON d.id = r.rateable_id AND r.rateable_type = 'Doctor'
    WHERE d.is_verified = 'Verified' AND u.deleted_at IS NULL AND d.deleted_at IS NULL
    GROUP BY d.id, u.full_name, s.name_bn, u.profile_image
    ORDER BY avg_rating DESC, total_reviews DESC
    LIMIT 3
";
$top_doctors_stmt = $conn->prepare($top_doctors_sql);
$top_doctors_stmt->execute();
$top_doctors = $top_doctors_stmt->get_result();
$top_doctors_stmt->close();

// --- UPDATED QUERY for Top Rated Branches ---
$top_branches_sql = "
    SELECT
        v.hospital_id,
        v.hospital_name,
        v.branch_id,
        v.branch_name,
        v.hospital_type,
        v.avg_rating
    FROM v_hospital_details v
    ORDER BY v.avg_rating DESC
    LIMIT 3
";
$top_branches_stmt = $conn->prepare($top_branches_sql);
$top_branches_stmt->execute();
$top_branches = $top_branches_stmt->get_result();
$top_branches_stmt->close();

// Failsafe for debugging
if (!$top_branches) {
    die("Branch Query Error: " . $conn->error);
}

// --- Fetch Stats ---
$stats_sql = "
    SELECT 
        (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) as total_patients,
        (SELECT COUNT(*) FROM v_verified_doctors_details) as total_doctors,
        (SELECT COUNT(DISTINCT branch_id) FROM v_hospital_details) as total_hospitals
";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();
?>

<main>
    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <h1>Intelligent Healthcare for a Better Bangladesh</h1>
            <p>আপনার স্বাস্থ্য, আমাদের অগ্রাধিকার - দেশের সকল স্বাস্থ্যসেবা এখন এক প্ল্যাটফর্মে।</p>
            <form action="search_v2.php" method="GET" class="search-bar-wrapper">
                <div class="search-bar">
                    <input type="text" name="query" id="hero-search-input" placeholder="হাসপাতাল, ডাক্তার বা সেবা খুঁজুন..." autocomplete="off">
                    <button type="submit" class="btn">🔍 অনুসন্ধান</button>
                </div>
                <div id="search-suggestions" class="suggestions-box"></div>
            </form>
        </div>
    </section>

    <!-- Stats Banner -->
    <section class="stats-banner">
        <div class="container">
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-number"><?= number_format($stats['total_patients'] ?? 0) ?>+</div>
                    <div class="stat-label">মোট রোগী</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= number_format($stats['total_doctors'] ?? 0) ?>+</div>
                    <div class="stat-label">যাচাইকৃত ডাক্তার</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= number_format($stats['total_hospitals'] ?? 0) ?>+</div>
                    <div class="stat-label">হাসপাতাল</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= number_format($stats['total_doctors'] ?? 0) ?>+</div>
                    <div class="stat-label">উপলব্ধ ডাক্তার</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Core Services Section -->
    <section class="speciality-section">
        <div class="container">
            <h2 class="section-title">আমাদের প্রধান সেবাসমূহ</h2>
            <div class="speciality-grid">
                <a href="hospitals.php" class="speciality-item">
                    <div class="service-icon">
                        <img src="image/hospital.png" alt="Find Hospital">
                    </div>
                    <p>হাসপাতাল খুঁজুন</p>
                </a>
                <a href="doctors.php" class="speciality-item">
                    <div class="service-icon">
                        <img src="image/doctor.png" alt="Book Doctor">
                    </div>
                    <p>ডাক্তারের অ্যাপয়েন্টমেন্ট</p>
                </a>
                <a href="bed_status.php" class="speciality-item">
                    <div class="service-icon">
                        <img src="image/bed.png" alt="Live Bed Status">
                    </div>
                    <p>লাইভ বেড স্ট্যাটাস</p>
                </a>
                <a href="lab_tests.php" class="speciality-item">
                    <div class="service-icon">
                        <img src="image/lab.png" alt="Lab Test">
                    </div>
                    <p>ল্যাব টেস্ট</p>
                </a>
                <a href="ambulances.php" class="speciality-item">
                    <div class="service-icon">
                        <img src="image/ambulance.png" alt="Ambulance">
                    </div>
                    <p>অ্যাম্বুলেন্স সেবা</p>
                </a>
                <a href="find_donors.php" class="speciality-item">
                    <div class="service-icon">
                        <img src="image/blood.png" alt="Blood Bank">
                    </div>
                    <p>ব্লাড ব্যাংক</p>
                </a>
                <a href="pharmacies.php" class="speciality-item">
                    <div class="service-icon">
                        <img src="image/pharmacy.svg" alt="Pharmacy">
                    </div>
                    <p>ফার্মেসি</p>
                </a>
                <div class="speciality-item" id="open-chatbot-from-service">
                    <div class="service-icon">
                        <img src="image/medibuddy_icon.svg" alt="Symptom Checker">
                    </div>
                    <p>লক্ষণ পরীক্ষক</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Top Rated Doctors Section -->
    <section class="featured-section">
        <div class="container">
            <h2 class="section-title">প্ল্যাটফর্মের সেরা ডাক্তারগণ</h2>
            <div class="doctor-grid">
                <?php if($top_doctors && $top_doctors->num_rows > 0): ?>
                    <?php while($doctor = $top_doctors->fetch_assoc()): ?>
                        <div class="doctor-card">
                            <div class="doctor-photo">
                                <?php 
                                $profile_path = $doctor['profile_image'] ?? '';
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
                            <div class="doctor-info">
                                <div class="doctor-rating">⭐ <?= $doctor['avg_rating'] ? number_format($doctor['avg_rating'], 1) : 'N/A' ?></div>
                                <h3 class="doctor-name"><?= htmlspecialchars($doctor['full_name']) ?></h3>
                                <p class="doctor-spec"><?= htmlspecialchars($doctor['specialization']) ?></p>
                            </div>
                            <div class="doctor-card-footer">
                                <a href="doctor_profile.php?id=<?= $doctor['doctor_id'] ?>" class="btn-profile">প্রোফাইল দেখুন</a>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="no-results">
                        <p>কোনো ডাক্তার পাওয়া যায়নি</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Top Rated Hospitals Section -->
    <section class="featured-section" style="background: var(--white-color);">
        <div class="container">
            <h2 class="section-title">প্ল্যাটফর্মের সেরা হাসপাতালসমূহ</h2>
            <div class="hospital-grid">
                <?php if($top_branches && $top_branches->num_rows > 0): ?>
                    <?php while($branch = $top_branches->fetch_assoc()): ?>
                        <div class="hospital-card">
                            <div class="hospital-card-header">
                                <span class="hospital-type <?= strtolower($branch['hospital_type']) ?>"><?= $branch['hospital_type'] === 'Government' ? 'সরকারি' : 'বেসরকারি' ?></span>
                                <div class="hospital-rating">⭐ <?= $branch['avg_rating'] ? number_format($branch['avg_rating'], 1) : 'N/A' ?></div>
                            </div>
                            <div class="hospital-card-body">
                                <h3><?= htmlspecialchars($branch['hospital_name']) ?></h3>
                                <p class="branch-name"><?= htmlspecialchars($branch['branch_name']) ?></p>
                            </div>
                            <div class="hospital-card-footer">
                                <a href="hospital_profile.php?id=<?= $branch['branch_id'] ?>" class="btn-details">বিস্তারিত দেখুন</a>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="no-results">
                        <p>কোনো হাসপাতাল পাওয়া যায়নি</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- CTA Section (Only show to non-logged in users) -->
    <?php if (!isset($_SESSION['user_id'])): ?>
    <section class="cta-section">
        <div class="container" style="text-align: center; color: white;">
            <h2 style="font-size: 36px; margin-bottom: 20px; font-weight: 700;">আজই স্বাস্থ্যসেবা শুরু করুন</h2>
            <p style="font-size: 18px; margin-bottom: 30px; opacity: 0.95; max-width: 600px; margin-left: auto; margin-right: auto;">দেশের সেরা ডাক্তার এবং হাসপাতালে এখনই অ্যাপয়েন্টমেন্ট বুক করুন। আপনার স্বাস্থ্য আমাদের কাছে গুরুত্বপূর্ণ।</p>
            <div style="display: flex; gap: 20px; justify-content: center; flex-wrap: wrap;">
                <a href="register.php" class="btn" style="background: white; color: #3498db; padding: 16px 35px; border-radius: 50px; font-weight: 700; font-size: 18px; transition: all 0.3s;">এখনই নিবন্ধন করুন</a>
                <a href="doctors.php" class="btn" style="background: transparent; color: white; border: 3px solid white; padding: 14px 35px; border-radius: 50px; font-weight: 700; font-size: 18px; transition: all 0.3s;">ডাক্তার খুঁজুন</a>
            </div>
        </div>
    </section>
    <?php endif; ?>
</main>

<?php
include_once('includes/footer.php');
?>
