<?php
include_once('includes/db_config.php');
include_once('includes/header.php');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?redirect=register_pharmacy.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Fetch divisions and districts
$divisions = $conn->query("SELECT id, name FROM divisions ORDER BY name")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register_pharmacy'])) {
    $pharmacy_name = trim($_POST['pharmacy_name']);
    $license_number = trim($_POST['license_number']);
    $owner_name = trim($_POST['owner_name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $division_id = (int)$_POST['division_id'];
    $district_id = (int)$_POST['district_id'];
    $upazila_id = !empty($_POST['upazila_id']) ? (int)$_POST['upazila_id'] : null;
    $has_delivery = isset($_POST['has_delivery']) ? 1 : 0;
    $delivery_radius = $has_delivery && !empty($_POST['delivery_radius']) ? floatval($_POST['delivery_radius']) : null;
    
    // Validation
    if (empty($pharmacy_name) || empty($license_number) || empty($phone) || empty($address)) {
        $error = "সকল প্রয়োজনীয় ফিল্ড পূরণ করুন।";
    } else {
        $conn->begin_transaction();
        try {
            // Handle file uploads
            $license_document = null;
            
            if (isset($_FILES['license_document']) && $_FILES['license_document']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/pharmacy_docs/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $ext = pathinfo($_FILES['license_document']['name'], PATHINFO_EXTENSION);
                $filename = 'license_' . time() . '_' . uniqid() . '.' . $ext;
                
                if (move_uploaded_file($_FILES['license_document']['tmp_name'], $upload_dir . $filename)) {
                    $license_document = $filename;
                }
            }
            
            // Insert pharmacy
            $stmt = $conn->prepare("
                INSERT INTO pharmacies (pharmacy_name, pharmacy_type, license_number, license_document, 
                    owner_name, owner_user_id, phone, email, address, division_id, district_id, upazila_id,
                    has_delivery, delivery_radius_km, status)
                VALUES (?, 'Outside', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
            ");
            $stmt->bind_param("sssssssiiiid", 
                $pharmacy_name, $license_number, $license_document, $owner_name, $user_id, 
                $phone, $email, $address, $division_id, $district_id, $upazila_id, $has_delivery, $delivery_radius
            );
            $stmt->execute();
            $pharmacy_id = $conn->insert_id;
            $stmt->close();
            
            // Add user as pharmacy admin
            $admin_stmt = $conn->prepare("INSERT INTO pharmacy_admins (pharmacy_id, user_id, role) VALUES (?, ?, 'PharmacyAdmin')");
            $admin_stmt->bind_param("ii", $pharmacy_id, $user_id);
            $admin_stmt->execute();
            $admin_stmt->close();
            
            // Update user role
            $role_stmt = $conn->prepare("UPDATE users SET role = 'PharmacyAdmin' WHERE id = ? AND role = 'Patient'");
            $role_stmt->bind_param("i", $user_id);
            $role_stmt->execute();
            $role_stmt->close();
            
            // Handle additional documents
            if (!empty($_FILES['other_documents']['name'][0])) {
                $doc_stmt = $conn->prepare("INSERT INTO pharmacy_documents (pharmacy_id, document_type, document_name, file_path) VALUES (?, ?, ?, ?)");
                
                foreach ($_FILES['other_documents']['name'] as $key => $name) {
                    if ($_FILES['other_documents']['error'][$key] === UPLOAD_ERR_OK) {
                        $ext = pathinfo($name, PATHINFO_EXTENSION);
                        $filename = 'doc_' . time() . '_' . $key . '.' . $ext;
                        
                        if (move_uploaded_file($_FILES['other_documents']['tmp_name'][$key], $upload_dir . $filename)) {
                            $doc_type = $_POST['doc_types'][$key] ?? 'Other';
                            $doc_stmt->bind_param("isss", $pharmacy_id, $doc_type, $name, $filename);
                            $doc_stmt->execute();
                        }
                    }
                }
                $doc_stmt->close();
            }
            
            $conn->commit();
            $success = "আপনার ফার্মেসি রেজিস্ট্রেশন সম্পন্ন হয়েছে! অনুমোদনের জন্য অপেক্ষা করুন।";
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "রেজিস্ট্রেশন ব্যর্থ: " . $e->getMessage();
        }
    }
}
?>

<style>
.register-container {
    max-width: 800px;
    margin: 40px auto;
    padding: 0 20px;
}
.register-card {
    background: #fff;
    border-radius: 15px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.1);
    overflow: hidden;
}
.register-header {
    background: linear-gradient(135deg, #27ae60, #2ecc71);
    color: white;
    padding: 30px;
    text-align: center;
}
.register-header h1 {
    margin: 0;
    font-size: 28px;
}
.register-header p {
    margin: 10px 0 0 0;
    opacity: 0.9;
}
.register-body {
    padding: 30px;
}
.form-section {
    margin-bottom: 25px;
}
.form-section h3 {
    color: #27ae60;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #27ae60;
    display: flex;
    align-items: center;
    gap: 10px;
}
.form-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
    margin-bottom: 15px;
}
.form-group {
    margin-bottom: 15px;
}
.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #2c3e50;
}
.form-group label .required {
    color: #e74c3c;
}
.form-group input, .form-group select, .form-group textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 14px;
    transition: border-color 0.3s;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    border-color: #27ae60;
    outline: none;
}
.file-upload-area {
    border: 2px dashed #ddd;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
}
.file-upload-area:hover {
    border-color: #27ae60;
    background: #f8f9fa;
}
.file-upload-area i {
    font-size: 32px;
    color: #27ae60;
    margin-bottom: 10px;
}
.file-upload-area input[type="file"] {
    display: none;
}
.checkbox-group {
    display: flex;
    align-items: center;
    gap: 10px;
}
.checkbox-group input[type="checkbox"] {
    width: 20px;
    height: 20px;
}
.btn-submit {
    background: linear-gradient(135deg, #27ae60, #2ecc71);
    color: white;
    border: none;
    padding: 15px 40px;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    width: 100%;
    transition: transform 0.3s, box-shadow 0.3s;
}
.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(39, 174, 96, 0.4);
}
.alert {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}
.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}
.alert-danger {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}
.benefits-list {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-top: 15px;
}
.benefit-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
}
.benefit-item i {
    color: #27ae60;
}
@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }
    .benefits-list {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="register-container">
    <div class="register-card">
        <div class="register-header">
            <h1><i class="fas fa-clinic-medical"></i> ফার্মেসি রেজিস্ট্রেশন</h1>
            <p>MediVerse-এ আপনার ফার্মেসি যোগ করুন এবং হাজারো গ্রাহকের কাছে পৌঁছান</p>
            <div class="benefits-list">
                <div class="benefit-item"><i class="fas fa-check-circle"></i> অনলাইন অর্ডার গ্রহণ</div>
                <div class="benefit-item"><i class="fas fa-check-circle"></i> হোম ডেলিভারি সুবিধা</div>
                <div class="benefit-item"><i class="fas fa-check-circle"></i> ইনভেন্টরি ম্যানেজমেন্ট</div>
                <div class="benefit-item"><i class="fas fa-check-circle"></i> বিক্রয় রিপোর্ট</div>
            </div>
        </div>
        
        <div class="register-body">
            <?php if($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= $success ?>
                <br><br>
                <a href="dashboard.php" class="btn btn-primary">ড্যাশবোর্ডে যান</a>
            </div>
            <?php else: ?>
            
            <?php if($error): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div><?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <!-- Basic Information -->
                <div class="form-section">
                    <h3><i class="fas fa-store"></i> ফার্মেসির তথ্য</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>ফার্মেসির নাম <span class="required">*</span></label>
                            <input type="text" name="pharmacy_name" required placeholder="ফার্মেসির নাম লিখুন">
                        </div>
                        <div class="form-group">
                            <label>ড্রাগ লাইসেন্স নম্বর <span class="required">*</span></label>
                            <input type="text" name="license_number" required placeholder="লাইসেন্স নম্বর">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>মালিকের নাম <span class="required">*</span></label>
                            <input type="text" name="owner_name" required placeholder="মালিকের পূর্ণ নাম">
                        </div>
                        <div class="form-group">
                            <label>ফোন নম্বর <span class="required">*</span></label>
                            <input type="tel" name="phone" required placeholder="01XXXXXXXXX">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>ইমেইল</label>
                        <input type="email" name="email" placeholder="pharmacy@example.com">
                    </div>
                </div>
                
                <!-- Location -->
                <div class="form-section">
                    <h3><i class="fas fa-map-marker-alt"></i> অবস্থান</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>বিভাগ <span class="required">*</span></label>
                            <select name="division_id" id="division_id" required>
                                <option value="">-- বিভাগ নির্বাচন করুন --</option>
                                <?php foreach ($divisions as $div): ?>
                                <option value="<?= $div['id'] ?>"><?= htmlspecialchars($div['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>জেলা <span class="required">*</span></label>
                            <select name="district_id" id="district_id" required>
                                <option value="">-- জেলা নির্বাচন করুন --</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>উপজেলা</label>
                            <select name="upazila_id" id="upazila_id">
                                <option value="">-- উপজেলা নির্বাচন করুন --</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>সম্পূর্ণ ঠিকানা <span class="required">*</span></label>
                        <textarea name="address" rows="2" required placeholder="বিস্তারিত ঠিকানা লিখুন"></textarea>
                    </div>
                </div>
                
                <!-- Delivery -->
                <div class="form-section">
                    <h3><i class="fas fa-shipping-fast"></i> ডেলিভারি সেবা</h3>
                    <div class="checkbox-group">
                        <input type="checkbox" name="has_delivery" id="has_delivery" value="1">
                        <label for="has_delivery" style="margin: 0;">হোম ডেলিভারি সেবা প্রদান করি</label>
                    </div>
                    <div class="form-group" id="delivery_radius_group" style="display: none; margin-top: 15px;">
                        <label>ডেলিভারি রেডিয়াস (কিমি)</label>
                        <input type="number" name="delivery_radius" step="0.5" min="1" placeholder="যেমন: 5">
                    </div>
                </div>
                
                <!-- Documents -->
                <div class="form-section">
                    <h3><i class="fas fa-file-alt"></i> ডকুমেন্টস</h3>
                    <div class="form-group">
                        <label>ড্রাগ লাইসেন্স কপি <span class="required">*</span></label>
                        <label class="file-upload-area" for="license_document">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <p>ক্লিক করে লাইসেন্স আপলোড করুন</p>
                            <small>PDF, JPG, PNG (সর্বোচ্চ 5MB)</small>
                            <input type="file" name="license_document" id="license_document" accept=".pdf,.jpg,.jpeg,.png" required>
                        </label>
                    </div>
                    <div class="form-group">
                        <label>অন্যান্য ডকুমেন্ট (ঐচ্ছিক)</label>
                        <label class="file-upload-area" for="other_documents">
                            <i class="fas fa-folder-plus"></i>
                            <p>অতিরিক্ত ডকুমেন্ট আপলোড করুন</p>
                            <input type="file" name="other_documents[]" id="other_documents" multiple accept=".pdf,.jpg,.jpeg,.png">
                        </label>
                    </div>
                </div>
                
                <button type="submit" name="register_pharmacy" class="btn-submit">
                    <i class="fas fa-paper-plane"></i> রেজিস্ট্রেশন জমা দিন
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.getElementById('division_id').addEventListener('change', function() {
    const divisionId = this.value;
    const districtSelect = document.getElementById('district_id');
    const upazilaSelect = document.getElementById('upazila_id');
    
    districtSelect.innerHTML = '<option value="">-- জেলা নির্বাচন করুন --</option>';
    upazilaSelect.innerHTML = '<option value="">-- উপজেলা নির্বাচন করুন --</option>';
    
    if (divisionId) {
        fetch('ajax_handler.php?action=get_districts&division_id=' + divisionId)
            .then(response => response.json())
            .then(data => {
                data.forEach(district => {
                    districtSelect.innerHTML += `<option value="${district.id}">${district.name}</option>`;
                });
            });
    }
});

document.getElementById('district_id').addEventListener('change', function() {
    const districtId = this.value;
    const upazilaSelect = document.getElementById('upazila_id');
    
    upazilaSelect.innerHTML = '<option value="">-- উপজেলা নির্বাচন করুন --</option>';
    
    if (districtId) {
        fetch('ajax_handler.php?action=get_upazilas&district_id=' + districtId)
            .then(response => response.json())
            .then(data => {
                data.forEach(upazila => {
                    upazilaSelect.innerHTML += `<option value="${upazila.id}">${upazila.name}</option>`;
                });
            });
    }
});

document.getElementById('has_delivery').addEventListener('change', function() {
    document.getElementById('delivery_radius_group').style.display = this.checked ? 'block' : 'none';
});
</script>

<?php include_once('includes/footer.php'); ?>
