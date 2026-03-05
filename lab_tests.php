<?php
include_once('includes/db_config.php');
include_once('includes/header.php');

// Get available tests from lab_tests table
$tests = [];
$tests_table_check = $conn->query("SHOW TABLES LIKE 'lab_tests'");
if ($tests_table_check && $tests_table_check->num_rows > 0) {
    $tests_stmt = $conn->prepare("SELECT id, test_name FROM lab_tests WHERE deleted_at IS NULL ORDER BY test_name ASC");
    $tests_stmt->execute();
    $tests_result = $tests_stmt->get_result();
    while ($row = $tests_result->fetch_assoc()) {
        $tests[] = $row;
    }
}

// Get statistics - with table existence checks
$total_tests = 0;
$total_hospitals = 0;
$avg_price = 0;

$tests_check = $conn->query("SHOW TABLES LIKE 'lab_test_prices'");
$hospitals_check = $conn->query("SHOW TABLES LIKE 'hospitals'");

// Count all unique lab tests from lab_tests table
$total_tests_result = $conn->query("SELECT COUNT(*) as count FROM lab_tests WHERE deleted_at IS NULL");
if ($total_tests_result) {
    $total_tests = $total_tests_result->fetch_assoc()['count'];
}

// Count hospitals that have at least one lab test
if ($hospitals_check && $hospitals_check->num_rows > 0) {
    if ($tests_check && $tests_check->num_rows > 0) {
        // Count hospitals with lab test prices
        $total_hospitals_result = $conn->query("SELECT COUNT(DISTINCT h.id) as count FROM hospitals h JOIN hospital_branches hb ON h.id = hb.hospital_id WHERE h.status = 'Active' AND hb.status = 'Active' AND hb.id IN (SELECT DISTINCT branch_id FROM lab_test_prices)");
    } else {
        // Fallback: count all active hospitals
        $total_hospitals_result = $conn->query("SELECT COUNT(*) as count FROM hospitals WHERE status = 'Active'");
    }
    if ($total_hospitals_result) {
        $total_hospitals = $total_hospitals_result->fetch_assoc()['count'];
    }
}

// Get average price from lab_test_prices if it exists
$avg_price = 0;
if ($tests_check && $tests_check->num_rows > 0) {
    $avg_price_result = $conn->query("SELECT AVG(price) as avg FROM lab_test_prices");
    if ($avg_price_result) {
        $avg_price = $avg_price_result->fetch_assoc()['avg'];
    }
}
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* Hero Section */
    .lab-hero {
        background: linear-gradient(135deg, #27ae60 0%, #1e8449 50%, #145a32 100%);
        padding: 60px 20px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    
    .lab-hero::before {
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
    
    .lab-hero-content {
        position: relative;
        z-index: 1;
        max-width: 700px;
        margin: 0 auto;
    }
    
    .lab-hero-icon {
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
    
    .lab-hero-icon i {
        font-size: 45px;
        color: white;
    }
    
    .lab-hero h1 {
        color: white;
        font-size: 42px;
        font-weight: 800;
        margin: 0 0 15px;
        text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    }
    
    .lab-hero p {
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
    
    .stat-card:nth-child(2)::before { background: linear-gradient(90deg, #0d6efd, #6ea8fe); }
    .stat-card:nth-child(3)::before { background: linear-gradient(90deg, #6f42c1, #9775fa); }
    
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
    
    .stat-card:nth-child(1) .stat-icon { background: linear-gradient(135deg, #27ae60, #1e8449); }
    .stat-card:nth-child(2) .stat-icon { background: linear-gradient(135deg, #0d6efd, #084298); }
    .stat-card:nth-child(3) .stat-icon { background: linear-gradient(135deg, #6f42c1, #4c2f8f); }
    
    .stat-icon i {
        font-size: 30px;
        color: white;
    }
    
    .stat-number {
        font-size: 42px;
        font-weight: 800;
        margin-bottom: 5px;
    }
    
    .stat-card:nth-child(1) .stat-number { background: linear-gradient(135deg, #27ae60, #1e8449); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    .stat-card:nth-child(2) .stat-number { background: linear-gradient(135deg, #0d6efd, #084298); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    .stat-card:nth-child(3) .stat-number { background: linear-gradient(135deg, #6f42c1, #4c2f8f); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    
    .stat-label {
        color: #6c757d;
        font-size: 14px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    /* Booking Section */
    .booking-section {
        padding: 50px 20px;
        background: linear-gradient(180deg, #e9ecef 0%, #ffffff 50%);
    }
    
    .booking-container {
        max-width: 700px;
        margin: 0 auto;
    }
    
    .booking-card {
        background: white;
        border-radius: 25px;
        padding: 45px;
        box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
        border: 1px solid #f0f0f0;
    }
    
    .booking-card h3 {
        color: #212529;
        font-size: 26px;
        font-weight: 700;
        margin-bottom: 30px;
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
    }
    
    .booking-card h3 i {
        color: #27ae60;
    }
    
    .form-group {
        margin-bottom: 20px;
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
        border-color: #27ae60;
        background: white;
        box-shadow: 0 0 0 4px rgba(39, 174, 96, 0.1);
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

    .price-display {
        background: linear-gradient(135deg, #27ae60, #1e8449);
        color: white;
        padding: 25px;
        border-radius: 15px;
        text-align: center;
        margin: 25px 0;
        box-shadow: 0 10px 30px rgba(39, 174, 96, 0.3);
    }
    
    .price-display h4 {
        margin: 0 0 8px;
        font-size: 16px;
        font-weight: 600;
        opacity: 0.9;
    }
    
    .price-display .price-amount {
        font-size: 36px;
        font-weight: 800;
    }
    
    .btn-book {
        width: 100%;
        padding: 18px;
        font-size: 18px;
        font-weight: 700;
        background: linear-gradient(135deg, #27ae60, #1e8449);
        color: white;
        border: none;
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 10px 30px rgba(39, 174, 96, 0.3);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }
    
    .btn-book:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 40px rgba(39, 174, 96, 0.4);
    }

    /* Prescription Upload */
    .upload-area {
        border: 2px dashed #27ae60;
        border-radius: 12px;
        padding: 30px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s ease;
        background: #f8fdf9;
    }
    
    .upload-area:hover {
        background: #e8f5e9;
        border-color: #2ecc71;
    }
    
    .upload-area i {
        font-size: 40px;
        color: #27ae60;
        margin-bottom: 10px;
    }
    
    .upload-area p {
        margin: 0;
        color: #666;
        font-size: 14px;
    }
    
    .upload-area small {
        color: #999;
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
        background: linear-gradient(135deg, #27ae60, #1e8449);
        color: white;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 700;
        font-size: 16px;
        transition: all 0.3s ease;
    }
    
    .btn-cta:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(39, 174, 96, 0.4);
    }

    @media (max-width: 768px) {
        .lab-hero { padding: 40px 20px; }
        .lab-hero h1 { font-size: 28px; }
        .lab-hero-icon { width: 70px; height: 70px; }
        .lab-hero-icon i { font-size: 30px; }
        .stat-number { font-size: 32px; }
        .booking-card { padding: 25px; }
    }
</style>

<!-- Hero Section -->
<section class="lab-hero">
    <div class="lab-hero-content">
        <div class="lab-hero-icon">
            <i class="fas fa-flask"></i>
        </div>
        <h1>Lab Test Booking</h1>
        <p>Book your lab tests online with easy booking process</p>
    </div>
</section>

<!-- Stats Section -->
<section class="stats-section">
    <div class="stats-container">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-vial"></i></div>
            <div class="stat-number"><?php echo $total_tests; ?>+</div>
            <div class="stat-label">Unique Lab Tests</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-hospital"></i></div>
            <div class="stat-number"><?php echo $total_hospitals; ?>+</div>
            <div class="stat-label">Hospitals</div>
        </div>
       
    </div>
</section>

<!-- Booking Section -->
<section class="booking-section">
    <div class="booking-container">
        <div class="booking-card">
            <h3><i class="fas fa-clipboard-check"></i> Book Lab Test</h3>
            
            <form id="lab-booking-form" action="book_lab_test.php" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Select Test</label>
                    <select name="test_id" id="test_select" required>
                        <option value="">Choose a test</option>
                        <?php if (!empty($tests)): ?>
                            <?php foreach($tests as $test): ?>
                                <option value="<?= $test['id'] ?>"><?= htmlspecialchars($test['test_name']) ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Select Hospital/Branch</label>
                    <select name="branch_id" id="branch_select" required disabled>
                        <option value="">Select test first</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Select Date</label>
                    <select name="appointment_date" id="date_select" required disabled>
                        <option value="">Select branch first</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Upload Prescription</label>
                    <div class="upload-area" id="prescription-drop-zone">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <p>Click to upload prescription (Image or PDF)</p>
                        <small>Max file size: 5MB</small>
                    </div>
                    <input type="file" name="prescription" id="prescription_file" accept="image/*,.pdf" required style="display: none;">
                </div>
                
                <div class="price-display">
                    <h4>Test Price</h4>
                    <div class="price-amount" id="test_price">৳0</div>
                </div>
                
                <button type="submit" class="btn-book">
                    <i class="fas fa-paper-plane"></i> Submit Booking
                </button>
            </form>
        </div>
    </div>
</section>



<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('#test_select').select2({ placeholder: "Choose a test", allowClear: true });
    $('#branch_select').select2({ placeholder: "Select test first", allowClear: true });
    $('#date_select').select2({ placeholder: "Select branch first", allowClear: true });

    $('#test_select').on('change', function() {
        const testId = $(this).val();
        $('#test_price').text('৳0');
        $('#branch_select').prop('disabled', true).html('<option>Loading...</option>');

        if (testId) {
            $.get('ajax_handler.php', { action: 'find_branches_for_test', test_id: testId }, function(data) {
                let options = '<option value="">-- Select Branch --</option>';
                if(data.length > 0) {
                    data.forEach(branch => {
                        options += `<option value="${branch.id}" data-price="${branch.price}">${branch.name}</option>`;
                    });
                } else {
                    options = '<option value="">No branches available</option>';
                }
                $('#branch_select').html(options).prop('disabled', false);
            }, 'json');
        }
    });

    $('#branch_select').on('change', function() {
        const branchId = $(this).val();
        const price = $(this).find(':selected').data('price') || 0;
        if (branchId) {
            $('#test_price').text('৳' + price);
        }
        $('#date_select').prop('disabled', true).html('<option>Loading...</option>');
        if (branchId) {
            $.get('ajax_handler.php', { action: 'find_dates_for_lab', branch_id: branchId }, function(data) {
                let options = '<option value="">-- Select Date --</option>';
                if(data.length > 0) {
                    data.forEach(date => {
                        options += `<option value="${date.value}">${date.name}</option>`;
                    });
                } else {
                    options = '<option value="">No available dates</option>';
                }
                $('#date_select').html(options).prop('disabled', false);
            }, 'json');
        }
    });

    // File upload handling
    const dropZone = document.getElementById('prescription-drop-zone');
    const fileInput = document.getElementById('prescription_file');
    
    dropZone.addEventListener('click', function() {
        fileInput.click();
    });
    
    dropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        dropZone.style.background = '#e8f5e9';
        dropZone.style.borderColor = '#2ecc71';
    });
    
    dropZone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        dropZone.style.background = '#f8fdf9';
        dropZone.style.borderColor = '#27ae60';
    });
    
    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        dropZone.style.background = '#f8fdf9';
        dropZone.style.borderColor = '#27ae60';
        if (e.dataTransfer.files.length > 0) {
            fileInput.files = e.dataTransfer.files;
        }
    });
    
    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            dropZone.innerHTML = '<i class="fas fa-check-circle"></i><p>' + this.files[0].name + '</p>';
            dropZone.style.background = '#d4edda';
            dropZone.style.borderColor = '#28a745';
        }
    });
});
</script>

<?php include_once('includes/footer.php'); ?>
