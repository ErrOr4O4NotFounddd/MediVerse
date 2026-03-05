<?php
session_start();
require_once('includes/db_config.php');

// Security: Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_record'])) {
    $record_type = $_POST['record_type'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $record_date = $_POST['record_date'];
    
    // Validate file upload
    if (isset($_FILES['medical_file']) && $_FILES['medical_file']['error'] === 0) {
        $file = $_FILES['medical_file'];
        $file_size = $file['size'];
        $file_tmp = $file['tmp_name'];
        $file_name = $file['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Allowed file types
        $allowed_types = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        if (!in_array($file_ext, $allowed_types)) {
            $error = "Invalid file type. Allowed: PDF, JPG, PNG, DOC, DOCX";
        } elseif ($file_size > $max_size) {
            $error = "File too large. Maximum size: 5MB";
        } else {
            // Create user-specific directory
            $upload_dir = "uploads/medical_records/$user_id/";
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $new_filename = uniqid() . '_' . time() . '.' . $file_ext;
            $file_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($file_tmp, $file_path)) {
                // Insert into database
                $stmt = $conn->prepare("
                    INSERT INTO medical_records 
                    (user_id, record_type, title, description, file_path, file_type, file_size, record_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("isssssss", $user_id, $record_type, $title, $description, $file_path, $file_ext, $file_size, $record_date);
                
                if ($stmt->execute()) {
                    $success = "Medical record uploaded successfully!";
                } else {
                    $error = "Failed to save record: " . $stmt->error;
                    unlink($file_path); // Delete uploaded file
                }
                $stmt->close();
            } else {
                $error = "Failed to upload file.";
            }
        }
    } else {
        $error = "Please select a file to upload.";
    }
}

// Handle delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $record_id = (int)$_GET['id'];
    $stmt = $conn->prepare("UPDATE medical_records SET deleted_at = NOW() WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $record_id, $user_id);
    if ($stmt->execute()) {
        $success = "Record deleted successfully!";
    }
    $stmt->close();
}

// Fetch user's medical records
$filter_type = $_GET['type'] ?? '';
$search = $_GET['search'] ?? '';

$where_clauses = ["user_id = ?"];
$params = [$user_id];
$types = "i";

if (!empty($filter_type)) {
    $where_clauses[] = "record_type = ?";
    $params[] = $filter_type;
    $types .= "s";
}

if (!empty($search)) {
    $where_clauses[] = "(title LIKE ? OR description LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

$where_sql = implode(' AND ', $where_clauses);

$sql = "SELECT id, record_type, title, description, file_path, file_type, file_size, record_date, uploaded_at FROM vw_active_medical_records WHERE $where_sql ORDER BY record_date DESC, uploaded_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result();

// Fetch Lab Reports from bookings table (uploaded by technicians)
$lab_reports_sql = "
    SELECT b.id, b.appointment_date as record_date, b.report_file_path, b.updated_at as uploaded_at,
           lt.test_name as title, h.name as hospital_name
    FROM bookings b
    JOIN lab_tests lt ON b.reference_id = lt.id
    JOIN lab_schedules ls ON b.doctor_schedule_id = ls.id
    JOIN hospital_branches hb ON ls.branch_id = hb.id
    JOIN hospitals h ON hb.hospital_id = h.id
    WHERE b.user_id = ? AND b.booking_type = 'lab_test' AND b.status = 'Report_Ready' 
    AND b.report_file_path IS NOT NULL AND b.report_file_path != ''
    ORDER BY b.updated_at DESC
";
$lab_stmt = $conn->prepare($lab_reports_sql);
$lab_stmt->bind_param("i", $user_id);
$lab_stmt->execute();
$lab_reports = $lab_stmt->get_result();

include_once('includes/header.php');
?>

<main>
    <section class="page-header">
        <div class="container">
            <h1>📋 My Medical Records</h1>
            <p>Upload and manage your medical documents</p>
        </div>
    </section>

    <div class="dashboard-container">
        <?php include_once('includes/dashboard_sidebar.php'); ?>
        
        <section class="dashboard-content">
            <?php if($success): ?>
                <p class="success-msg"><?= htmlspecialchars($success) ?></p>
            <?php endif; ?>
            <?php if($error): ?>
                <p class="error-msg"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>

            <!-- Upload Form -->
            <div class="upload-section">
                <h3>📤 Upload New Record</h3>
                <form method="POST" enctype="multipart/form-data" class="upload-form">
                    <input type="hidden" name="upload_record" value="1">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Record Type *</label>
                            <select name="record_type" required>
                                <option value="">Select Type</option>
                                <option value="Lab Report">Lab Report</option>
                                <option value="X-Ray">X-Ray</option>
                                <option value="Prescription">Prescription</option>
                                <option value="Medical Certificate">Medical Certificate</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Record Date *</label>
                            <input type="date" name="record_date" required max="<?= date('Y-m-d') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Title *</label>
                        <input type="text" name="title" required placeholder="e.g., Blood Test Report">
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="3" placeholder="Optional notes about this record"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Upload File * (PDF, JPG, PNG, DOC - Max 5MB)</label>
                        <input type="file" name="medical_file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Upload Record
                    </button>
                </form>
            </div>

         

            <!-- Records List -->
            <div class="records-grid">
                <?php if($records && $records->num_rows > 0): ?>
                    <?php while($record = $records->fetch_assoc()): ?>
                    <div class="record-card">
                        <div class="record-icon">
                            <?php
                            $icon = 'fa-file';
                            if ($record['file_type'] === 'pdf') $icon = 'fa-file-pdf';
                            elseif (in_array($record['file_type'], ['jpg', 'jpeg', 'png'])) $icon = 'fa-file-image';
                            elseif (in_array($record['file_type'], ['doc', 'docx'])) $icon = 'fa-file-word';
                            ?>
                            <i class="fas <?= $icon ?>"></i>
                        </div>
                        
                        <div class="record-details">
                            <span class="record-type-badge"><?= htmlspecialchars($record['record_type']) ?></span>
                            <h4><?= htmlspecialchars($record['title']) ?></h4>
                            <?php if($record['description']): ?>
                                <p class="record-desc"><?= htmlspecialchars($record['description']) ?></p>
                            <?php endif; ?>
                            
                            <div class="record-meta">
                                <span><i class="fas fa-calendar"></i> <?= date('d M Y', strtotime($record['record_date'])) ?></span>
                                <span><i class="fas fa-clock"></i> Uploaded: <?= date('d M Y', strtotime($record['uploaded_at'])) ?></span>
                                <span><i class="fas fa-file"></i> <?= number_format($record['file_size'] / 1024, 2) ?> KB</span>
                            </div>
                        </div>

                        <div class="record-actions">
                            <a href="<?= htmlspecialchars($record['file_path']) ?>" target="_blank" class="btn-view">
                                <i class="fas fa-eye"></i> View
                            </a>
                            
                            <?php if($record['record_type'] === 'Prescription'): ?>
                                <a href="<?= htmlspecialchars($record['file_path']) ?>&download=true" target="_blank" class="btn-download">
                                    <i class="fas fa-file-pdf"></i> Download PDF
                                </a>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars($record['file_path']) ?>" download class="btn-download">
                                    <i class="fas fa-download"></i> Download
                                </a>
                            <?php endif; ?>
                            
                            <a href="?action=delete&id=<?= $record['id'] ?>" class="btn-delete" onclick="return confirm('Delete this record?')">
                                <i class="fas fa-trash"></i> Delete
                            </a>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="no-records">
                        <i class="fas fa-folder-open"></i>
                        <h3>No records found</h3>
                        <p>Upload your first medical record above</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Lab Reports from Hospital (Uploaded by Technicians) -->
            <?php if($lab_reports && $lab_reports->num_rows > 0): ?>
            <div class="filter-section" style="margin-top: 30px;">
                <h3>🔬 Lab Reports from Hospital</h3>
                <p style="color: #7f8c8d; font-size: 14px; margin-bottom: 15px;">Reports uploaded by lab technicians for your tests</p>
            </div>
            <div class="records-grid">
                <?php while($lab = $lab_reports->fetch_assoc()): ?>
                <?php 
                $file_ext = strtolower(pathinfo($lab['report_file_path'], PATHINFO_EXTENSION));
                $icon = 'fa-file';
                if ($file_ext === 'pdf') $icon = 'fa-file-pdf';
                elseif (in_array($file_ext, ['jpg', 'jpeg', 'png', 'webp'])) $icon = 'fa-file-image';
                ?>
                <div class="record-card">
                    <div class="record-icon" style="background: linear-gradient(135deg, #11998e, #38ef7d);">
                        <i class="fas <?= $icon ?>"></i>
                    </div>
                    
                    <div class="record-details">
                        <span class="record-type-badge" style="background: #e8f8f0; color: #11998e;">Lab Report</span>
                        <h4><?= htmlspecialchars($lab['title']) ?></h4>
                        <p class="record-desc"><?= htmlspecialchars($lab['hospital_name']) ?></p>
                        
                        <div class="record-meta">
                            <span><i class="fas fa-calendar"></i> <?= date('d M Y', strtotime($lab['record_date'])) ?></span>
                            <span><i class="fas fa-clock"></i> Uploaded: <?= date('d M Y', strtotime($lab['uploaded_at'])) ?></span>
                        </div>
                    </div>

                    <div class="record-actions">
                        <a href="<?= htmlspecialchars($lab['report_file_path']) ?>" target="_blank" class="btn-view" style="background: linear-gradient(135deg, #11998e, #38ef7d);">
                            <i class="fas fa-eye"></i> View
                        </a>
                        <a href="<?= htmlspecialchars($lab['report_file_path']) ?>" download class="btn-download">
                            <i class="fas fa-download"></i> Download
                        </a>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<style>
.upload-section {
    background: white;
    padding: 30px;
    border-radius: 15px;
    margin-bottom: 30px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
}

.upload-section h3 {
    margin: 0 0 20px 0;
    color: #2c3e50;
}

.upload-form .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.upload-form .form-group {
    margin-bottom: 20px;
}

.upload-form label {
    display: block;
    margin-bottom: 8px;
    color: #2c3e50;
    font-weight: 600;
}

.upload-form input,
.upload-form select,
.upload-form textarea {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 15px;
}

.filter-section {
    background: white;
    padding: 20px;
    border-radius: 15px;
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
}

.filter-section h3 {
    margin: 0 0 15px 0;
    color: #2c3e50;
}

.filter-form {
    display: grid;
    grid-template-columns: 2fr 1fr auto;
    gap: 15px;
}

.filter-form input,
.filter-form select {
    padding: 10px 15px;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
}

.btn-filter {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    border: none;
    padding: 10px 25px;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
}

.records-grid {
    display: grid;
    gap: 20px;
}

.record-card {
    background: white;
    padding: 20px;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 20px;
    align-items: center;
    transition: all 0.3s;
}

.record-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.12);
}

.record-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 28px;
}

.record-type-badge {
    display: inline-block;
    background: #e8f4f8;
    color: #3498db;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 8px;
}

.record-details h4 {
    margin: 5px 0 8px 0;
    color: #2c3e50;
    font-size: 18px;
}

.record-desc {
    color: #7f8c8d;
    font-size: 14px;
    margin: 5px 0 10px 0;
}

.record-meta {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    font-size: 13px;
    color: #95a5a6;
}

.record-meta span {
    display: flex;
    align-items: center;
    gap: 5px;
}

.record-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.btn-view,
.btn-download,
.btn-delete {
    padding: 8px 15px;
    border-radius: 8px;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all 0.3s;
    white-space: nowrap;
}

.btn-view {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
}

.btn-download {
    background: linear-gradient(135deg, #27ae60, #2ecc71);
    color: white;
}

.btn-delete {
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    color: white;
}

.btn-view:hover,
.btn-download:hover,
.btn-delete:hover {
    transform: scale(1.05);
}

.no-records {
    grid-column: 1 / -1;
    text-align: center;
    padding: 60px 20px;
    color: #95a5a6;
}

.no-records i {
    font-size: 64px;
    margin-bottom: 20px;
}

@media (max-width: 768px) {
    .upload-form .form-row {
        grid-template-columns: 1fr;
    }
    
    .filter-form {
        grid-template-columns: 1fr;
    }
    
    .record-card {
        grid-template-columns: 1fr;
        text-align: center;
    }
    
    .record-actions {
        flex-direction: row;
    }
}
</style>

<?php include_once('includes/footer.php'); ?>
