<?php
include_once('includes/db_config.php');
include_once('includes/header.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?redirect_url=" . urlencode($_SERVER['REQUEST_URI']));
    exit();
}
if (!isset($_GET['branch_id']) || !isset($_GET['bed_type'])) {
    echo "<p class='container'>Invalid Request.</p>"; include_once('includes/footer.php'); exit();
}

$branch_id = (int)$_GET['branch_id'];
$bed_type = $_GET['bed_type'];
$patient_id = $_SESSION['user_id'];
$error = ''; $success = '';

// Fetch details for display
$stmt = $conn->prepare("SELECT hospital_name AS name, branch_name FROM vw_active_branches WHERE id = ?");
$stmt->bind_param("i", $branch_id);
$stmt->execute();
$details_q = $stmt->get_result();
$details = $details_q->fetch_assoc();
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patient_name = $_POST['patient_name'];
    $patient_phone = $_POST['patient_phone'];
    $notes = $_POST['notes'];
    
    // Handle prescription file upload
    $prescription_file = null;
    if (isset($_FILES['prescription']) && $_FILES['prescription']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['prescription'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        if (!in_array($file['type'], $allowed_types)) {
            $error = "শুধুমাত্র ছবি (JPG, PNG, GIF, WebP) অথবা PDF ফাইল আপলোড করুন!";
        } elseif ($file['size'] > $max_size) {
            $error = "ফাইল সাইজ 5MB এর বেশি হতে পারবে না!";
        } else {
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'bed_prescription_' . $patient_id . '_' . time() . '_' . uniqid() . '.' . $extension;
            $upload_path = 'uploads/prescriptions/' . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                $prescription_file = $upload_path;
            } else {
                $error = "ফাইল আপলোড করতে ব্যর্থ হয়েছে!";
            }
        }
    } else {
        $error = "ডাক্তারের প্রেসক্রিপশন আপলোড করা আবশ্যক!";
    }

    // Continue only if no error
    if (empty($error)) {
        // Append patient_name and patient_phone into notes for reference
        $full_notes = "রোগী: " . $patient_name . " | ফোন: " . $patient_phone;
        if (!empty($notes)) {
            $full_notes .= " | " . $notes;
        }
        $stmt = $conn->prepare("INSERT INTO bookings (user_id, branch_id, booking_type, bed_type, notes, prescription_file) VALUES (?, ?, 'bed', ?, ?, ?)");
        $stmt->bind_param("iisss", $patient_id, $branch_id, $bed_type, $full_notes, $prescription_file);
        if ($stmt->execute()) {
            $success = "আপনার বেড বুকিং-এর আবেদনটি সফলভাবে জমা হয়েছে। হাসপাতাল কর্তৃপক্ষ প্রেসক্রিপশন যাচাই করে অনুমোদন করবে।";
        } else {
            $error = "আবেদন জমা দিতে সমস্যা হয়েছে।";
            // Delete uploaded file if db insert fails
            if ($prescription_file && file_exists($prescription_file)) {
                unlink($prescription_file);
            }
        }
    }
}
?>
<head>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .booking-container {
            max-width: 700px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .booking-title {
            text-align: center;
            color: #2c3e50;
            margin-bottom: 30px;
            font-size: 28px;
        }
        .booking-details-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            text-align: center;
        }
        .booking-details-card h2 {
            margin: 0 0 5px;
            font-size: 22px;
        }
        .booking-details-card p {
            margin: 0;
            opacity: 0.9;
        }
        .booking-details-card hr {
            border: none;
            border-top: 1px solid rgba(255,255,255,0.3);
            margin: 15px 0;
        }
        .info-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .booking-form-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }
        .form-group input, .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        .form-group input:focus, .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        /* Prescription Upload Styling */
        .prescription-upload-area {
            border: 2px dashed #667eea;
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f8f9ff;
        }
        .prescription-upload-area:hover {
            background: #eef0ff;
            border-color: #764ba2;
        }
        .prescription-upload-area.dragover {
            background: #e0e4ff;
            border-color: #667eea;
        }
        .btn-remove-file {
            margin-top: 10px;
            padding: 8px 16px;
            background: #e74c3c;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-remove-file:hover {
            background: #c0392b;
        }
        
        .btn-confirm-booking {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-confirm-booking:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }
        .success-msg {
            background: #d4edda;
            color: #155724;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }
        .error-msg {
            background: #f8d7da;
            color: #721c24;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }
        .btn-primary-action {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
        }
    </style>
</head>
<main>
    <div class="booking-container">
        <h1 class="booking-title"><i class="fas fa-bed"></i> বেডের জন্য আবেদন</h1>
        
        <?php if($success): ?>
            <div style="text-align: center; background: white; padding: 40px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);">
                <div style="font-size: 60px; margin-bottom: 20px;">✅</div>
                <h2 style="color: #27ae60; margin-bottom: 15px;">আবেদন সফল হয়েছে!</h2>
                <p style="color: #666; margin-bottom: 20px;"><?= htmlspecialchars($success) ?></p>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                    <i class="fas fa-info-circle" style="color: #3498db;"></i>
                    <span style="color: #555;">হাসপাতাল অ্যাডমিন আপনার প্রেসক্রিপশন যাচাই করবে এবং নোটিফিকেশনের মাধ্যমে জানাবে।</span>
                </div>
                <a href="index.php" class="btn-primary-action">হোম পেজে ফিরে যান</a>
            </div>
        <?php else: ?>
            <?php if($error): ?><p class="error-msg"><i class="fas fa-exclamation-circle"></i> <?= $error ?></p><?php endif; ?>
            
            <div class="booking-details-card">
                <h2><?= htmlspecialchars($details['name']) ?></h2>
                <p><?= htmlspecialchars($details['branch_name']) ?></p>
                <hr>
                <div class="info-row">
                    <span style="font-size: 24px;">🛌</span>
                    <p><strong>বেডের ধরন: <?= htmlspecialchars($bed_type) ?></strong></p>
                </div>
            </div>
            
            <div class="booking-form-card">
                <form action="book_bed.php?branch_id=<?= $branch_id ?>&bed_type=<?= urlencode($bed_type) ?>" method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> রোগীর নাম</label>
                        <input type="text" name="patient_name" required placeholder="রোগীর পূর্ণ নাম লিখুন">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> যোগাযোগের নম্বর</label>
                        <input type="text" name="patient_phone" required placeholder="01XXXXXXXXX">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-notes-medical"></i> রোগীর অবস্থা সম্পর্কে সংক্ষিপ্ত নোট</label>
                        <textarea name="notes" rows="3" placeholder="রোগীর বর্তমান অবস্থা, রোগের বিবরণ ইত্যাদি..."></textarea>
                    </div>
                    
                    <!-- Prescription Upload -->
                    <div class="form-group">
                        <label><i class="fas fa-file-medical"></i> ডাক্তারের প্রেসক্রিপশন আপলোড করুন <span style="color: #e74c3c;">*</span></label>
                        <div class="prescription-upload-area" id="prescription-drop-zone">
                            <input type="file" name="prescription" id="prescription_file" accept="image/*,.pdf" required style="display: none;">
                            <div class="upload-placeholder" id="upload-placeholder">
                                <i class="fas fa-cloud-upload-alt" style="font-size: 40px; color: #667eea; margin-bottom: 10px;"></i>
                                <p style="margin: 0; color: #666;">ছবি বা PDF ফাইল আপলোড করতে ক্লিক করুন</p>
                                <small style="color: #999;">সর্বোচ্চ ফাইল সাইজ: 5MB</small>
                            </div>
                            <div class="upload-preview" id="upload-preview" style="display: none;">
                                <img id="preview-image" src="" alt="Preview" style="max-width: 100%; max-height: 150px; border-radius: 8px;">
                                <p id="preview-filename" style="margin: 10px 0 0; font-weight: 600; color: #667eea;"></p>
                                <button type="button" id="remove-file" class="btn-remove-file">
                                    <i class="fas fa-times"></i> সরান
                                </button>
                            </div>
                        </div>
                        <small style="color: #888; display: block; margin-top: 8px;">
                            <i class="fas fa-info-circle"></i> 
                            হাসপাতাল অ্যাডমিন প্রেসক্রিপশন যাচাই করে আপনার আবেদন অনুমোদন করবে।
                        </small>
                    </div>
                    
                    <button type="submit" class="btn-confirm-booking">
                        <i class="fas fa-paper-plane"></i> আবেদন জমা দিন
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const dropZone = document.getElementById('prescription-drop-zone');
    const fileInput = document.getElementById('prescription_file');
    const placeholder = document.getElementById('upload-placeholder');
    const preview = document.getElementById('upload-preview');
    const previewImage = document.getElementById('preview-image');
    const previewFilename = document.getElementById('preview-filename');
    const removeBtn = document.getElementById('remove-file');
    
    if (!dropZone) return;
    
    // Click to upload
    dropZone.addEventListener('click', function(e) {
        if (e.target !== removeBtn && !removeBtn.contains(e.target)) {
            fileInput.click();
        }
    });
    
    // Drag and drop
    dropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        dropZone.classList.add('dragover');
    });
    
    dropZone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        dropZone.classList.remove('dragover');
    });
    
    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInput.files = files;
            handleFileSelect(files[0]);
        }
    });
    
    // File input change
    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            handleFileSelect(this.files[0]);
        }
    });
    
    // Handle file selection
    function handleFileSelect(file) {
        // Validate file size (5MB max)
        if (file.size > 5 * 1024 * 1024) {
            alert('ফাইল সাইজ 5MB এর বেশি হতে পারবে না!');
            fileInput.value = '';
            return;
        }
        
        // Validate file type
        const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        if (!validTypes.includes(file.type)) {
            alert('শুধুমাত্র ছবি (JPG, PNG, GIF, WebP) অথবা PDF ফাইল আপলোড করুন!');
            fileInput.value = '';
            return;
        }
        
        placeholder.style.display = 'none';
        preview.style.display = 'block';
        previewFilename.textContent = file.name;
        
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImage.src = e.target.result;
                previewImage.style.display = 'block';
            };
            reader.readAsDataURL(file);
        } else {
            // PDF file
            previewImage.src = 'https://cdn-icons-png.flaticon.com/512/337/337946.png';
            previewImage.style.display = 'block';
        }
    }
    
    // Remove file
    removeBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        fileInput.value = '';
        placeholder.style.display = 'block';
        preview.style.display = 'none';
        previewImage.src = '';
        previewFilename.textContent = '';
    });
});
</script>

<?php include_once('includes/footer.php'); ?>
