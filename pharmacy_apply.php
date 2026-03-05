<?php
session_start();
require_once('includes/db_config.php');

$success = '';
$error = '';

// Handle Application Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_application'])) {
    $pharmacy_name = trim($_POST['pharmacy_name']);
    $owner_name = trim($_POST['owner_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $license_number = trim($_POST['license_number']);
    
    // Check if email already exists in users or pending applications
    $check_email = $conn->prepare("SELECT id FROM users WHERE email = ? UNION SELECT id FROM pharmacy_applications WHERE email = ? AND status = 'Pending'");
    $check_email->bind_param("ss", $email, $email);
    $check_email->execute();
    if ($check_email->get_result()->num_rows > 0) {
        $error = "This email address is already registered or has a pending application.";
    } else {
        // Handle file uploads
        $upload_dir = 'uploads/pharmacy_applications/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $license_document = '';
        $trade_license = '';
        
        $upload_ok = true;
        
        if (isset($_FILES['license_document']) && $_FILES['license_document']['error'] === 0) {
            $license_document = $upload_dir . uniqid() . '_' . basename($_FILES['license_document']['name']);
            if(!move_uploaded_file($_FILES['license_document']['tmp_name'], $license_document)) $upload_ok = false;
        }
        
        if (isset($_FILES['trade_license']) && $_FILES['trade_license']['error'] === 0) {
            $trade_license = $upload_dir . uniqid() . '_' . basename($_FILES['trade_license']['name']);
            if(!move_uploaded_file($_FILES['trade_license']['tmp_name'], $trade_license)) $upload_ok = false;
        }
        
        if ($upload_ok) {
            $stmt = $conn->prepare("
                INSERT INTO pharmacy_applications 
                (pharmacy_name, owner_name, email, phone, address, license_number, license_document, trade_license, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
            ");
            $stmt->bind_param("ssssssss", $pharmacy_name, $owner_name, $email, $phone, $address, $license_number, $license_document, $trade_license);
            
            if ($stmt->execute()) {
                $success = "Application submitted successfully! We will review your details and contact you via email.";
            } else {
                $error = "Failed to submit application: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Failed to upload documents. Please try again.";
        }
    }
    $check_email->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Registration - MediVerse</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
        }

        /* Header */
        .header {
            background: white;
            padding: 20px 5%;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-logo {
            font-size: 26px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-logo i {
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 28px;
        }

        .btn-login {
            text-decoration: none;
            color: #667eea;
            font-weight: 600;
            padding: 12px 28px;
            border: 2px solid #667eea;
            border-radius: 50px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-login:hover {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        /* Page Wrapper */
        .page-wrapper {
            padding: 50px 20px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: calc(100vh - 80px);
        }

        .application-container {
            width: 100%;
            max-width: 800px;
        }

        /* Application Card */
        .application-card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.08);
            overflow: hidden;
            border: 1px solid #f0f2f5;
        }

        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 45px 35px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .card-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            pointer-events: none;
        }

        .card-header h1 {
            margin: 0 0 10px 0;
            font-size: 32px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .card-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 16px;
        }

        .application-form {
            padding: 40px;
        }

        /* Form Sections */
        .form-section {
            margin-bottom: 35px;
            padding-bottom: 35px;
            border-bottom: 2px solid #f0f2f5;
        }

        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .form-section h3 {
            margin: 0 0 25px 0;
            font-size: 20px;
            color: #2c3e50;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-section h3 i {
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 22px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid #e8ecf1;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            outline: none;
            background: white;
        }

        .hint {
            color: #95a5a6;
            font-size: 13px;
            margin-top: 8px;
            display: block;
            line-height: 1.5;
        }

        .file-upload-wrapper {
            position: relative;
        }

        .file-upload-wrapper input[type="file"] {
            width: 100%;
            padding: 14px 18px;
            border: 2px dashed #e8ecf1;
            border-radius: 12px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-upload-wrapper input[type="file"]:hover {
            border-color: #667eea;
            border-style: solid;
            background: #f8f9fa;
        }

        .file-upload-wrapper input[type="file"]::file-selector-button {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            margin-right: 12px;
            cursor: pointer;
            font-weight: 600;
        }

        /* Submit Button */
        .btn-submit {
            width: 100%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 18px 30px;
            border-radius: 14px;
            border: none;
            font-size: 17px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }

        .btn-submit:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(102, 126, 234, 0.4);
        }

        /* Error Message */
        .error-msg {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #dc2626;
            padding: 18px 24px;
            border-radius: 14px;
            margin: 0 40px 25px 40px;
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: 15px;
            font-weight: 500;
            border-left: 4px solid #dc2626;
        }

        .error-msg i {
            font-size: 22px;
        }

        /* Success Box */
        .success-box {
            text-align: center;
            padding: 60px 40px;
        }

        .success-icon {
            font-size: 80px;
            margin-bottom: 25px;
            display: block;
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .success-box h3 {
            font-size: 28px;
            color: #2c3e50;
            margin-bottom: 15px;
        }

        .success-box p {
            color: #7f8c8d;
            font-size: 16px;
            line-height: 1.7;
            max-width: 500px;
            margin: 0 auto;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 14px 30px;
            text-decoration: none;
            border-radius: 12px;
            margin-top: 30px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.4);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .page-wrapper {
                padding: 20px 15px;
            }

            .card-header {
                padding: 35px 25px;
            }

            .card-header h1 {
                font-size: 24px;
            }

            .application-form {
                padding: 30px 25px;
            }

            .error-msg {
                margin: 0 25px 25px 25px;
            }
        }
    </style>
</head>
<body>

<div class="header">
    <a href="#" class="header-logo">
        <i class="fas fa-heartbeat"></i> MediVerse
    </a>
    <a href="pharmacy_login.php" class="btn-login">Login</a>
</div>

<div class="page-wrapper">
    <div class="application-container">
        <div class="application-card">
            <div class="card-header">
                <h1>🏥 Pharmacy Registration</h1>
                <p>Register your pharmacy on MediVerse</p>
            </div>

            <?php if($success): ?>
                <div class="success-box">
                    <div class="success-icon">✅</div>
                    <h3>Application Submitted!</h3>
                    <p><?= htmlspecialchars($success) ?></p>
                    <a href="pharmacy_login.php" class="btn btn-primary">Go to Login</a>
                </div>
            <?php else: ?>
                
                <?php if($error): ?>
                    <div class="error-msg">
                        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" class="application-form">
                    <input type="hidden" name="submit_application" value="1">
                    
                    <div class="form-section">
                        <h3><i class="fas fa-store"></i> Pharmacy Details</h3>
                        <div class="form-group">
                            <label>Pharmacy Name *</label>
                            <input type="text" name="pharmacy_name" required placeholder="e.g. HealthCare Pharmacy" value="<?= htmlspecialchars($_POST['pharmacy_name'] ?? '') ?>">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>License Number *</label>
                                <input type="text" name="license_number" required placeholder="License No." value="<?= htmlspecialchars($_POST['license_number'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Phone Number *</label>
                                <input type="tel" name="phone" required placeholder="Business Phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Division *</label>
                                <select name="division" id="division" required>
                                    <option value="">Select Division</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>District *</label>
                                <select name="district" id="district" required disabled>
                                    <option value="">Select District</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Upazila *</label>
                                <select name="upazila" id="upazila" required disabled>
                                    <option value="">Select Upazila</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Pharmacy Address (Details) *</label>
                                <textarea name="address_details" id="address_details" required rows="2" placeholder="House No, Road No, Area, etc."><?= htmlspecialchars($_POST['address_details'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <input type="hidden" name="address" id="full_address">
                    </div>

                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const divisionSelect = document.getElementById('division');
                        const districtSelect = document.getElementById('district');
                        const upazilaSelect = document.getElementById('upazila');
                        const addressDetails = document.getElementById('address_details');
                        const fullAddressInput = document.getElementById('full_address');
                        
                        // PHP values for retention
                        const oldDivision = "<?= $_POST['division'] ?? '' ?>";
                        const oldDistrict = "<?= $_POST['district'] ?? '' ?>";
                        const oldUpazila = "<?= $_POST['upazila'] ?? '' ?>";
                        
                        // Load Divisions
                        fetch('ajax_handler.php?action=get_divisions')
                            .then(response => response.json())
                            .then(data => {
                                data.forEach(item => {
                                    const option = document.createElement('option');
                                    option.value = item.id;
                                    option.textContent = item.name;
                                    option.dataset.name = item.name;
                                    if (item.id == oldDivision) option.selected = true;
                                    divisionSelect.appendChild(option);
                                });
                                // Trigger change if retained value exists
                                if(oldDivision) divisionSelect.dispatchEvent(new Event('change'));
                            });
                            
                        // Load Districts
                        divisionSelect.addEventListener('change', function() {
                            districtSelect.innerHTML = '<option value="">Select District</option>';
                            upazilaSelect.innerHTML = '<option value="">Select Upazila</option>';
                            districtSelect.disabled = true;
                            upazilaSelect.disabled = true;
                            
                            if(this.value) {
                                fetch(`ajax_handler.php?action=get_districts&division_id=${this.value}`)
                                    .then(response => response.json())
                                    .then(data => {
                                        data.forEach(item => {
                                            const option = document.createElement('option');
                                            option.value = item.id;
                                            option.textContent = item.name;
                                            option.dataset.name = item.name;
                                            if (item.id == oldDistrict) option.selected = true;
                                            districtSelect.appendChild(option);
                                        });
                                        districtSelect.disabled = false;
                                        // Trigger change if retained value exists
                                        if(oldDistrict && districtSelect.value == oldDistrict) {
                                            districtSelect.dispatchEvent(new Event('change'));
                                        }
                                    });
                            }
                        });
                        
                        // Load Upazilas
                        districtSelect.addEventListener('change', function() {
                            upazilaSelect.innerHTML = '<option value="">Select Upazila</option>';
                            upazilaSelect.disabled = true;
                            
                            if(this.value) {
                                fetch(`ajax_handler.php?action=get_upazilas&district_id=${this.value}`)
                                    .then(response => response.json())
                                    .then(data => {
                                        data.forEach(item => {
                                            const option = document.createElement('option');
                                            option.value = item.id;
                                            option.textContent = item.name;
                                            option.dataset.name = item.name;
                                            if (item.id == oldUpazila) option.selected = true;
                                            upazilaSelect.appendChild(option);
                                        });
                                        upazilaSelect.disabled = false;
                                        // Trigger update to set full address
                                        updateFullAddress();
                                    });
                            }
                        });
                        
                        // Update Full Address
                        function updateFullAddress() {
                            const division = divisionSelect.options[divisionSelect.selectedIndex]?.dataset.name || '';
                            const district = districtSelect.options[districtSelect.selectedIndex]?.dataset.name || '';
                            const upazila = upazilaSelect.options[upazilaSelect.selectedIndex]?.dataset.name || '';
                            const details = addressDetails.value.trim();
                            
                            let fullAddress = details;
                            if(upazila) fullAddress += `, ${upazila}`;
                            if(district) fullAddress += `, ${district}`;
                            if(division) fullAddress += `, ${division}`;
                            
                            fullAddressInput.value = fullAddress;
                        }
                        
                        divisionSelect.addEventListener('change', updateFullAddress);
                        districtSelect.addEventListener('change', updateFullAddress);
                        upazilaSelect.addEventListener('change', updateFullAddress);
                        addressDetails.addEventListener('input', updateFullAddress);
                    });
                    </script>

                    <div class="form-section">
                        <h3><i class="fas fa-user-tie"></i> Owner Information</h3>
                        <div class="form-group">
                            <label>Owner Full Name *</label>
                            <input type="text" name="owner_name" required placeholder="Full Name" value="<?= htmlspecialchars($_POST['owner_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Email Address (For Admin Account) *</label>
                            <input type="email" name="email" required placeholder="owner@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            <small class="hint">This email will be used to create your admin account upon approval.</small>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-file-upload"></i> Documents</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Pharmacy License (Scan) *</label>
                                <div class="file-upload-wrapper">
                                    <input type="file" name="license_document" required accept=".pdf,.jpg,.png,.jpeg">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Trade License (Scan) *</label>
                                <div class="file-upload-wrapper">
                                    <input type="file" name="trade_license" required accept=".pdf,.jpg,.png,.jpeg">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-submit">
                            Submit Application <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
