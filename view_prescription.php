<?php 
session_start();
include_once('includes/db_config.php');
include_once('includes/header.php');

// Check if user is logged in (either doctor or patient)
if (!isset($_SESSION['doctor_id']) && !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$doctor_id = $_SESSION['doctor_id'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;

// Check if prescription ID is provided
if (!isset($_GET['id'])) {
    echo "<div class='container' style='padding:50px; text-align:center;'><h3>কোনো প্রেসক্রিপশন নির্বাচন করা হয়নি।</h3></div>";
    include_once('includes/footer.php');
    exit();
}

$prescription_id = (int)$_GET['id'];

// --- Fetch Prescription Details with Signature ---
$sql_presc = "
    SELECT * FROM vw_prescription_details WHERE id = ?
";
$stmt = $conn->prepare($sql_presc);
$stmt->bind_param("i", $prescription_id);
$stmt->execute();
$prescription = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Access Control
if ($prescription) {
    if ($user_id && $prescription['patient_id'] != $user_id) {
        $prescription = null; // Access denied for wrong patient
    }
    // Doctors can view any prescription if they have the link (simplification)
}

if (!$prescription) {
    echo "<div class='container' style='padding:50px; text-align:center;'><h3>প্রেসক্রিপশন খুঁজে পাওয়া যায়নি অথবা আপনার এটি দেখার অনুমতি নেই।</h3></div>";
    include_once('includes/footer.php');
    exit();
}

// --- Fetch Child Data (prepared statements + specific columns) ---
$med_stmt = $conn->prepare("SELECT medicine_name, dosage, duration FROM prescription_medicines WHERE prescription_id = ?");
$med_stmt->bind_param("i", $prescription_id);
$med_stmt->execute();
$medicines = $med_stmt->get_result();

$lab_stmt = $conn->prepare("SELECT plt.custom_test_name, plt.lab_test_id, COALESCE(plt.custom_test_name, lt.test_name) as display_name FROM prescription_lab_tests plt LEFT JOIN lab_tests lt ON plt.lab_test_id = lt.id WHERE plt.prescription_id = ?");
$lab_stmt->bind_param("i", $prescription_id);
$lab_stmt->execute();
$lab_tests = $lab_stmt->get_result();

$adm_stmt = $conn->prepare("SELECT reason, admission_type, priority, recommended_days FROM prescription_admission_recommendations WHERE prescription_id = ?");
$adm_stmt->bind_param("i", $prescription_id);
$adm_stmt->execute();
$admission = $adm_stmt->get_result()->fetch_assoc();

// Calculate age
$age = 'N/A';
if ($prescription['date_of_birth']) {
    $dob = new DateTime($prescription['date_of_birth']);
    $today = new DateTime('today');
    $age = $dob->diff($today)->y . ' বছর';
}
?>

<style>
    /* Main Layout */
    .view-prescription-page {
        padding: 40px 0;
        background: #f4f7f6;
        min-height: 80vh;
    }
    
    .prescription-paper {
        max-width: 850px;
        margin: 0 auto;
        background: white;
        box-shadow: 0 0 20px rgba(0,0,0,0.05); /* Soft shadow like paper */
        position: relative;
    }

    /* Print Controls */
    .print-controls {
        max-width: 850px;
        margin: 0 auto 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    /* Header Section */
    .rx-header {
        padding: 40px;
        border-bottom: 2px solid #2c3e50;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
    }
    
    .doc-info h2 {
        margin: 0 0 5px 0;
        color: #2c3e50;
        font-size: 24px;
        font-weight: 700;
    }
    
    .doc-info p {
        margin: 2px 0;
        color: #555;
        font-size: 14px;
    }
    
    .hospital-info {
        text-align: right;
    }
    
    .hospital-info h3 {
        margin: 0 0 5px 0;
        color: #3498db;
    }

    /* Patient Info Bar */
    .rx-patient-info {
        background: #f8f9fa;
        padding: 15px 40px;
        border-bottom: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 15px;
        font-size: 14px;
    }
    
    .rx-patient-info div span {
        font-weight: 600;
        color: #2c3e50;
        margin-right: 5px;
    }

    /* Body Content */
    .rx-body {
        padding: 40px;
        display: grid;
        grid-template-columns: 3fr 1fr; /* Diagnosis/Meds left, Advice right (optional layout) - keeping simple stacked for now */
        grid-template-columns: 100%;
        gap: 30px;
    }
    
    .rx-section {
        margin-bottom: 30px;
    }
    
    .rx-section h3 {
        font-size: 16px;
        color: #2c3e50;
        border-bottom: 1px solid #ddd;
        padding-bottom: 8px;
        margin-bottom: 15px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    /* Medicine Table */
    .rx-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .rx-table th {
        text-align: left;
        padding: 10px;
        background: #f1f2f6;
        color: #2c3e50;
        font-size: 13px;
        border-bottom: 2px solid #ddd;
    }
    
    .rx-table td {
        padding: 12px 10px;
        border-bottom: 1px solid #eee;
        color: #333;
    }
    
    .rx-symbol {
        font-family: serif;
        font-size: 32px;
        font-weight: bold;
        margin-right: 10px;
        font-style: italic;
    }

    /* Footer / Signature */
    .rx-footer {
        padding: 40px;
        margin-top: 20px;
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
    }
    
    .signature-box {
        text-align: center;
        width: 200px;
    }
    
    .signature-img {
        max-height: 60px;
        margin-bottom: 5px;
    }
    
    .signature-text {
        font-family: 'Brush Script MT', cursive;
        font-size: 24px;
        color: #2c3e50;
        margin-bottom: 5px;
    }
    
    .signature-line {
        border-top: 1px solid #2c3e50;
        padding-top: 5px;
        font-size: 13px;
        color: #555;
    }

    /* Buttons */
    .btn-action {
        padding: 10px 20px;
        border-radius: 6px;
        text-decoration: none;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        border: none;
        font-size: 14px;
    }
    
    .btn-back { background: #e0e0e0; color: #333; }
    .btn-print { background: #2c3e50; color: white; }
    .btn-print:hover { background: #1a252f; }

    /* Print Styles - CRITICAL */
    @media print {
        body * {
            visibility: hidden;
            background: white !important;
            color: black !important;
        }
        
        .prescription-paper, .prescription-paper * {
            visibility: visible;
        }
        
        .prescription-paper {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            margin: 0;
            padding: 0;
            box-shadow: none;
        }
        
        .print-controls, header, footer, .main-header, .site-footer {
            display: none !important;
        }
        
        .view-prescription-page {
            padding: 0;
            background: white;
        }
    }
</style>

<div class="view-prescription-page">
    <div class="container">
        <!-- Print/Back Controls -->
        <div class="print-controls">
            <a href="javascript:history.back()" class="btn-action btn-back">
                <i class="fas fa-arrow-left"></i> ফিরে যান
            </a>
            <div class="prescription-actions">
                <button onclick="downloadPDF()" class="btn-action btn-print" style="margin-right: 10px;">
                    <i class="fas fa-file-pdf"></i> PDF ডাউনলোড
                </button>
                <button onclick="window.print()" class="btn-action btn-print">
                    <i class="fas fa-print"></i> প্রিন্ট করুন
                </button>
            </div>
        </div>
        
        <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
        <script>
            function downloadPDF() {
                const element = document.querySelector('.prescription-paper');
                // Hide header actions temporarily
                const actions = document.querySelector('.prescription-actions');
                actions.style.display = 'none';
                
                const opt = {
                    margin:       0,
                    filename:     'Prescription_<?= $prescription_id ?>.pdf',
                    image:        { type: 'jpeg', quality: 0.98 },
                    html2canvas:  { scale: 2 },
                    jsPDF:        { unit: 'in', format: 'letter', orientation: 'portrait' }
                };
                
                html2pdf().set(opt).from(element).save().then(() => {
                    actions.style.display = 'flex'; // Show again, assuming it's a flex container
                });
            }

            // Check for download param
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('download') === 'true') {
                window.onload = function() {
                    setTimeout(downloadPDF, 1000); // Wait for styles to load
                };
            }
        </script>
        <!-- Main Prescription Paper -->
        <div class="prescription-paper">
            
            <!-- Header -->
            <div class="rx-header">
                <div class="doc-info">
                    <h2><?= htmlspecialchars($prescription['doctor_name']) ?></h2>
                    <p><?= htmlspecialchars($prescription['specialization']) ?></p>
                    <p><?= htmlspecialchars($prescription['hospital_name']) ?></p>
                </div>
                <div class="hospital-info">
                    <h3><?= htmlspecialchars($prescription['hospital_name']) ?></h3>
                    <p>সিরিয়াল: #<?= htmlspecialchars($prescription['serial_number']) ?></p>
                    <p><?= date("d M, Y", strtotime($prescription['appointment_date'])) ?></p>
                </div>
            </div>

            <!-- Patient Info -->
            <div class="rx-patient-info">
                <div><span>নাম:</span> <?= htmlspecialchars($prescription['patient_name']) ?></div>
                <div><span>বয়স:</span> <?= $age ?></div>
                <div><span>আইডি:</span> <?= htmlspecialchars($prescription['patient_id']) ?></div>
                <div><span>মোবাইল:</span> <?= htmlspecialchars($prescription['patient_phone']) ?></div>
            </div>

            <!-- Body -->
            <div class="rx-body">
                
                <!-- Diagnosis -->
                <div class="rx-section">
                    <h3>রোগ নির্ণয় (Diagnosis)</h3>
                    <p><?= nl2br(htmlspecialchars($prescription['diagnosis'])) ?></p>
                </div>

                <!-- Medicines -->
                <div class="rx-section">
                    <div style="display:flex; align-items:center; margin-bottom:10px;">
                        <span class="rx-symbol">Rx</span>
                        <h3 style="margin:0; border:none;">ঔষধের তালিকা</h3>
                    </div>
                    
                    <?php if($medicines && $medicines->num_rows > 0): ?>
                        <table class="rx-table">
                            <thead>
                                <tr>
                                    <th width="40%">ঔষধের নাম</th>
                                    <th width="30%">মাত্রা (Dosage)</th>
                                    <th width="30%">সময়কাল (Duration)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($med = $medicines->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($med['medicine_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($med['dosage']) ?></td>
                                    <td><?= htmlspecialchars($med['duration']) ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="color:#777; font-style:italic;">কোনো ঔষধ নির্দেশিত নেই</p>
                    <?php endif; ?>
                </div>

                <!-- Lab Tests (If any) -->
                <?php if($lab_tests && $lab_tests->num_rows > 0): ?>
                <div class="rx-section">
                    <h3>ল্যাব টেস্ট (Lab Tests)</h3>
                    <ul style="list-style-type: square; padding-left: 20px;">
                        <?php while($lab = $lab_tests->fetch_assoc()): ?>
                            <li><?= htmlspecialchars($lab['display_name']) ?></li>
                        <?php endwhile; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Advice -->
                <div class="rx-section">
                    <h3>পরামর্শ (Advice)</h3>
                    <p><?= nl2br(htmlspecialchars($prescription['advice'] ?? 'কোনো পরামর্শ নেই')) ?></p>
                </div>

            </div>

            <!-- Footer / Signature -->
            <div class="rx-footer">
                <div class="info-text">
                    <p style="font-size:12px; color:#777;">Printed from Mediverse Health Platform</p>
                </div>
                
                <div class="signature-box">
                    <?php if(!empty($prescription['signature_image']) && $prescription['signature_type'] === 'Image'): ?>
                        <img src="uploads/signatures/<?= htmlspecialchars($prescription['signature_image']) ?>" class="signature-img" alt="Signature">
                    <?php elseif(!empty($prescription['signature_text'])): ?>
                        <div class="signature-text"><?= htmlspecialchars($prescription['signature_text']) ?></div>
                    <?php endif; ?>
                    
                    <div class="signature-line">
                        <strong><?= htmlspecialchars($prescription['doctor_name']) ?></strong><br>
                        <?= htmlspecialchars($prescription['specialization']) ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include_once('includes/footer.php'); ?>
