<?php
session_start();
require_once('includes/db_config.php');

// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit();
}

$pharmacy_id = (int)($_GET['pharmacy_id'] ?? 0);

if (!$pharmacy_id) {
    die("Invalid Pharmacy ID.");
}

// Fetch pharmacy details
$stmt = $conn->prepare("
    SELECT p.*, hb.branch_name, h.name as hospital_name, d.name as district_name
    FROM pharmacies p
    LEFT JOIN hospital_branches hb ON p.branch_id = hb.id
    LEFT JOIN hospitals h ON hb.hospital_id = h.id
    LEFT JOIN upazilas u ON hb.upazila_id = u.id
    LEFT JOIN districts d ON u.district_id = d.id
    WHERE p.id = ? AND p.status = 'Active'
");
$stmt->bind_param("i", $pharmacy_id);
$stmt->execute();
$pharmacy = $stmt->get_result()->fetch_assoc();

if (!$pharmacy) {
    die("Pharmacy not found or inactive.");
}

// Handle Order Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $user_id = $_SESSION['user_id'];
    $items = json_decode($_POST['items_json'], true);
    $total_amount = (float)$_POST['total_amount'];
    $delivery_address = trim($_POST['delivery_address']);
    $contact_number = trim($_POST['contact_number']);

    if (empty($items)) {
        $error = "আপনার কার্ট খালি!";
    } elseif (empty($delivery_address) || empty($contact_number)) {
        $error = "ঠিকানা ও যোগাযোগ নম্বর আবশ্যক।";
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO pharmacy_orders (pharmacy_id, user_id, total_amount, delivery_address, contact_number, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
            $stmt->bind_param("iidss", $pharmacy_id, $user_id, $total_amount, $delivery_address, $contact_number);
            $stmt->execute();
            $order_id = $stmt->insert_id;
            $stmt->close();

            $item_stmt = $conn->prepare("INSERT INTO pharmacy_order_items (order_id, medicine_id, medicine_name, quantity, unit_price) VALUES (?, ?, ?, ?, ?)");
            foreach ($items as $item) {
                $item_stmt->bind_param("iisid", $order_id, $item['id'], $item['name'], $item['quantity'], $item['price']);
                $item_stmt->execute();
            }
            $item_stmt->close();

            $conn->commit();
            header("Location: pharmacy_delivery.php?pharmacy_id=$pharmacy_id&success=1&order_id=$order_id");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error = "অর্ডার দিতে সমস্যা হয়েছে।";
        }
    }
}

// Fetch medicines
$medicines = $conn->query("
    SELECT m.*, ps.quantity as stock, ps.price_per_piece
    FROM pharmacy_stock ps
    JOIN medicines m ON ps.medicine_id = m.id
    WHERE ps.pharmacy_id = $pharmacy_id AND ps.quantity > 0
    ORDER BY m.name
");

$medicine_count = $medicines ? $medicines->num_rows : 0;

include_once('includes/header.php');
?>

<style>
/* ==================== PHARMACY DELIVERY v2 — Premium Redesign ==================== */
@keyframes pd-fadeInUp { from { opacity:0; transform:translateY(25px); } to { opacity:1; transform:translateY(0); } }
@keyframes pd-fadeInScale { from { opacity:0; transform:scale(.93); } to { opacity:1; transform:scale(1); } }
@keyframes pd-bounce { 0%,100% { transform:scale(1); } 50% { transform:scale(1.08); } }
@keyframes pd-float { 0%,100% { transform:translateY(0); } 50% { transform:translateY(-6px); } }
@keyframes pd-shimmer { 0% { left:-100%; } 100% { left:100%; } }
@keyframes pd-checkmark {
    0% { transform:scale(0) rotate(-45deg); opacity:0; }
    50% { transform:scale(1.2) rotate(0deg); opacity:1; }
    100% { transform:scale(1) rotate(0deg); opacity:1; }
}

.pd-page { background:#f0f2f5; min-height:100vh; padding-bottom:60px; }

/* ===== HERO ===== */
.pd-hero {
    background: linear-gradient(135deg, #ef4444, #dc2626, #b91c1c);
    position:relative; padding:45px 0 100px; overflow:hidden;
}
.pd-hero::before {
    content:''; position:absolute; inset:0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
}
.pd-hero::after {
    content:''; position:absolute; bottom:0; left:0; right:0; height:80px;
    background:linear-gradient(to bottom, transparent, #f0f2f5);
}
.pd-hero .container { position:relative; z-index:2; }

.pd-back-link {
    display:inline-flex; align-items:center; gap:8px;
    color:rgba(255,255,255,.85); text-decoration:none;
    font-weight:600; font-size:14px;
    padding:8px 16px; background:rgba(255,255,255,.15);
    border-radius:10px; backdrop-filter:blur(8px);
    border:1px solid rgba(255,255,255,.2);
    transition:all .3s ease; margin-bottom:20px;
}
.pd-back-link:hover { background:rgba(255,255,255,.25); color:#fff; }

.pd-hero-content { text-align:center; animation:pd-fadeInUp .7s ease-out; }
.pd-hero-icon { font-size:52px; margin-bottom:12px; animation:pd-float 3s ease-in-out infinite; }
.pd-hero-title { font-size:34px; font-weight:800; color:#fff; margin:0 0 10px; text-shadow:0 2px 10px rgba(0,0,0,.15); }
.pd-hero-sub { font-size:16px; color:rgba(255,255,255,.85); margin:0; }
.pd-hero-sub strong { color:#fff; }

/* ===== SUCCESS ===== */
.pd-success {
    background:#fff; border-radius:24px; padding:50px 40px;
    text-align:center; box-shadow:0 10px 40px rgba(0,0,0,.08);
    max-width:600px; margin:-50px auto 0; position:relative; z-index:5;
    animation:pd-fadeInScale .6s ease-out;
}
.pd-success-icon {
    width:80px; height:80px; border-radius:50%;
    background:linear-gradient(135deg,#10b981,#34d399);
    display:flex; align-items:center; justify-content:center;
    font-size:36px; margin:0 auto 20px;
    box-shadow:0 8px 25px rgba(16,185,129,.3);
    animation:pd-checkmark .6s ease-out .3s backwards;
}
.pd-success h2 { font-size:24px; font-weight:800; color:#1e293b; margin:0 0 10px; }
.pd-success p { font-size:15px; color:#64748b; margin:0 0 24px; }
.pd-success-btn {
    display:inline-flex; align-items:center; gap:10px;
    padding:14px 28px; background:linear-gradient(135deg,#10b981,#059669);
    color:#fff; text-decoration:none; border-radius:14px;
    font-size:15px; font-weight:700; transition:all .3s ease;
    box-shadow:0 6px 20px rgba(16,185,129,.3);
}
.pd-success-btn:hover { transform:translateY(-3px); box-shadow:0 10px 30px rgba(16,185,129,.4); color:#fff; }

/* ===== DELIVERY LAYOUT ===== */
.pd-layout {
    display:grid; grid-template-columns:1.4fr 1fr;
    gap:24px; align-items:start;
    margin-top:-50px; position:relative; z-index:5;
}

/* ===== MEDICINE SELECTION ===== */
.pd-med-card {
    background:#fff; border-radius:20px; padding:28px;
    box-shadow:0 6px 24px rgba(0,0,0,.06);
    animation:pd-fadeInUp .5s ease-out;
}
.pd-section-title {
    display:flex; align-items:center; gap:12px;
    margin:0 0 22px; font-size:18px; font-weight:700; color:#1e293b;
    padding-bottom:14px; border-bottom:2px solid #f1f5f9;
}
.pd-section-title-icon {
    width:38px; height:38px; border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    font-size:17px; color:#fff; flex-shrink:0;
}

.pd-med-list {
    display:flex; flex-direction:column; gap:10px;
    max-height:520px; overflow-y:auto; padding-right:8px;
}
.pd-med-list::-webkit-scrollbar { width:5px; }
.pd-med-list::-webkit-scrollbar-track { background:#f1f5f9; border-radius:3px; }
.pd-med-list::-webkit-scrollbar-thumb { background:#ef4444; border-radius:3px; }

.pd-med-item {
    display:flex; align-items:center; justify-content:space-between;
    padding:16px 18px; background:#f8fafc;
    border:1.5px solid #e2e8f0;
    border-radius:14px; transition:all .3s ease;
}
.pd-med-item:hover {
    background:#fff; border-color:rgba(239,68,68,.2);
    box-shadow:0 4px 14px rgba(0,0,0,.05);
}

.pd-med-info { flex:1; }
.pd-med-info strong { display:block; font-size:14px; color:#1e293b; margin-bottom:3px; }
.pd-med-info span { font-size:13px; color:#059669; font-weight:700; }
.pd-med-info .pd-med-stock { font-size:11px; color:#94a3b8; font-weight:500; margin-left:8px; }

.pd-btn-add {
    padding:9px 18px;
    background:linear-gradient(135deg,#ef4444,#dc2626);
    color:#fff; border:none; border-radius:10px;
    font-size:12px; font-weight:700; cursor:pointer;
    transition:all .3s ease;
    display:flex; align-items:center; gap:5px;
    white-space:nowrap;
}
.pd-btn-add:hover { transform:scale(1.06); box-shadow:0 4px 14px rgba(239,68,68,.35); }

/* ===== CHECKOUT ===== */
.pd-checkout {
    background:#fff; border-radius:20px; padding:28px;
    box-shadow:0 6px 24px rgba(239,68,68,.08);
    position:sticky; top:20px;
    animation:pd-fadeInUp .5s ease-out .1s backwards;
    overflow:hidden;
}
.pd-checkout::before {
    content:''; position:absolute; top:0; left:0; right:0; height:4px;
    background:linear-gradient(90deg,#ef4444,#f87171,#ef4444);
}

.pd-cart-items {
    max-height:260px; overflow-y:auto; margin-bottom:18px;
    padding-bottom:14px; border-bottom:1.5px solid #f1f5f9;
}

.pd-cart-item {
    display:flex; align-items:center; justify-content:space-between;
    padding:10px 0; border-bottom:1px solid #f8fafc;
}
.pd-cart-item:last-child { border-bottom:none; }
.pd-cart-item-info { flex:1; }
.pd-cart-item-name { font-size:13px; font-weight:600; color:#1e293b; }
.pd-cart-item-price { font-size:12px; color:#059669; font-weight:600; }

.pd-cart-controls { display:flex; align-items:center; gap:8px; }
.pd-qty-btn {
    width:28px; height:28px; border-radius:8px;
    border:1.5px solid #e2e8f0; background:#fff;
    font-size:14px; font-weight:700; cursor:pointer;
    transition:all .2s ease; display:flex; align-items:center; justify-content:center;
}
.pd-qty-btn:hover { background:#ef4444; color:#fff; border-color:#ef4444; }
.pd-qty-val { font-size:14px; font-weight:700; min-width:20px; text-align:center; color:#1e293b; }

.pd-empty-cart { text-align:center; color:#94a3b8; padding:28px; font-size:14px; }

/* ===== CART SUMMARY ===== */
.pd-summary { margin-bottom:20px; }
.pd-summary-row {
    display:flex; justify-content:space-between;
    padding:10px 0; font-size:14px; color:#64748b;
}
.pd-summary-row span:last-child { font-weight:600; }
.pd-summary-row.total {
    border-top:2px dashed #e2e8f0; margin-top:8px; padding-top:14px;
    font-size:17px; font-weight:800; color:#1e293b;
}

/* ===== FORM ===== */
.pd-form-group { margin-bottom:16px; }
.pd-form-group label {
    display:block; font-size:12px; font-weight:700;
    color:#64748b; margin-bottom:7px;
    text-transform:uppercase; letter-spacing:.5px;
}
.pd-form-group textarea,
.pd-form-group input[type="tel"] {
    width:100%; padding:12px 16px;
    border:2px solid #e2e8f0; border-radius:12px;
    font-size:14px; font-weight:600; transition:all .3s ease;
    box-sizing:border-box; font-family:inherit;
}
.pd-form-group textarea:focus,
.pd-form-group input[type="tel"]:focus {
    outline:none; border-color:#ef4444;
    box-shadow:0 0 0 4px rgba(239,68,68,.08);
}
.pd-form-group textarea { min-height:90px; resize:vertical; }

.pd-error {
    padding:12px 16px; background:#fef2f2; color:#991b1b;
    border:1px solid #fecaca; border-radius:10px;
    font-size:13px; font-weight:500; margin-bottom:14px;
    display:flex; align-items:center; gap:8px;
}

.pd-btn-order {
    width:100%; padding:16px;
    background:linear-gradient(135deg,#ef4444,#dc2626);
    color:#fff; border:none; border-radius:14px;
    font-size:16px; font-weight:700; cursor:pointer;
    transition:all .3s ease; position:relative; overflow:hidden;
    display:flex; align-items:center; justify-content:center; gap:8px;
}
.pd-btn-order::before {
    content:''; position:absolute; top:0; left:-100%; width:100%; height:100%;
    background:linear-gradient(90deg, transparent, rgba(255,255,255,.2), transparent);
    transition:left .5s ease;
}
.pd-btn-order:hover:not(:disabled) { transform:translateY(-2px); box-shadow:0 10px 30px rgba(239,68,68,.4); }
.pd-btn-order:hover:not(:disabled)::before { left:100%; }
.pd-btn-order:disabled { background:#cbd5e1; cursor:not-allowed; }

.pd-payment-note {
    text-align:center; font-size:12px; color:#94a3b8;
    margin-top:12px; display:flex; align-items:center; justify-content:center; gap:6px;
}

/* ===== RESPONSIVE ===== */
@media(max-width:968px) { .pd-layout { grid-template-columns:1fr; } .pd-checkout { position:static; } }
@media(max-width:600px) {
    .pd-hero { padding:30px 0 80px; }
    .pd-hero-title { font-size:26px; }
    .pd-med-card, .pd-checkout { padding:20px; }
}
</style>

<!-- ===== HERO ===== -->
<div class="pd-page">
    <div class="pd-hero">
        <div class="container">
            <a href="pharmacy_visit.php?id=<?= $pharmacy_id ?>" class="pd-back-link"><i class="fas fa-arrow-left"></i> ফার্মেসীতে ফিরুন</a>
            <div class="pd-hero-content">
                <div class="pd-hero-icon">🚚</div>
                <h1 class="pd-hero-title">হোম ডেলিভারি অর্ডার</h1>
                <p class="pd-hero-sub"><strong><?= htmlspecialchars($pharmacy['name']) ?></strong> থেকে ঘরে বসে ঔষধ অর্ডার করুন</p>
            </div>
        </div>
    </div>

    <div class="container">
        <?php if(isset($_GET['success'])): ?>
            <!-- ===== SUCCESS STATE ===== -->
            <div class="pd-success">
                <div class="pd-success-icon">✅</div>
                <h2>অর্ডার সফলভাবে সম্পন্ন হয়েছে!</h2>
                <p>আপনার অর্ডার #<?= htmlspecialchars($_GET['order_id']) ?> গ্রহণ করা হয়েছে। ফার্মেসী শীঘ্রই আপনার সাথে যোগাযোগ করবে।</p>
                <a href="pharmacies.php" class="pd-success-btn">🏥 আরো ফার্মেসী খুঁজুন</a>
            </div>
        <?php else: ?>

        <!-- ===== DELIVERY LAYOUT ===== -->
        <div class="pd-layout">
            <!-- Medicine Selection -->
            <div class="pd-med-card">
                <h3 class="pd-section-title">
                    <span class="pd-section-title-icon" style="background:linear-gradient(135deg,#ef4444,#dc2626)">💊</span>
                    ঔষধ নির্বাচন করুন
                    <span style="margin-left:auto;font-size:13px;font-weight:500;color:#94a3b8"><?= $medicine_count ?> টি ঔষধ</span>
                </h3>
                <div class="pd-med-list">
                    <?php if($medicines && $medicines->num_rows > 0): ?>
                        <?php while($med = $medicines->fetch_assoc()): ?>
                            <div class="pd-med-item" data-id="<?= $med['id'] ?>" data-name="<?= htmlspecialchars($med['name']) ?>" data-price="<?= $med['price_per_piece'] ?>" data-stock="<?= $med['stock'] ?>">
                                <div class="pd-med-info">
                                    <strong><?= htmlspecialchars($med['name']) ?></strong>
                                    <span>৳<?= number_format($med['price_per_piece'], 2) ?></span>
                                    <span class="pd-med-stock">(Stock: <?= $med['stock'] ?>)</span>
                                </div>
                                <button type="button" class="pd-btn-add" onclick="addToCart(<?= $med['id'] ?>, '<?= addslashes($med['name']) ?>', <?= $med['price_per_piece'] ?>, <?= $med['stock'] ?>)">
                                    ➕ যোগ করুন
                                </button>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p style="text-align:center;color:#94a3b8;padding:30px;">ডেলিভারির জন্য কোনো ঔষধ নেই।</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Cart & Checkout -->
            <div class="pd-checkout">
                <h3 class="pd-section-title">
                    <span class="pd-section-title-icon" style="background:linear-gradient(135deg,#f59e0b,#fbbf24)">🛒</span>
                    আপনার অর্ডার
                </h3>
                
                <div id="cart-items" class="pd-cart-items">
                    <p class="pd-empty-cart">এখনো কোনো ঔষধ যোগ করা হয়নি।</p>
                </div>
                
                <div class="pd-summary">
                    <div class="pd-summary-row">
                        <span>সাবটোটাল</span>
                        <span id="subtotal">৳0.00</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>ডেলিভারি ফি</span>
                        <span>৳50.00</span>
                    </div>
                    <div class="pd-summary-row total">
                        <span>সর্বমোট</span>
                        <span id="grand-total">৳50.00</span>
                    </div>
                </div>

                <form method="POST" id="order-form" onsubmit="return validateOrder()">
                    <input type="hidden" name="items_json" id="items_json">
                    <input type="hidden" name="total_amount" id="form_total_amount" value="50">
                    
                    <div class="pd-form-group">
                        <label for="delivery_address">📍 ডেলিভারি ঠিকানা</label>
                        <textarea name="delivery_address" id="delivery_address" placeholder="বাসা/ফ্ল্যাট নং, এলাকা, ল্যান্ডমার্ক..." required></textarea>
                    </div>

                    <div class="pd-form-group">
                        <label for="contact_number">📞 যোগাযোগ নম্বর</label>
                        <input type="tel" name="contact_number" id="contact_number" placeholder="017XXXXXXXX" required>
                    </div>

                    <?php if(isset($error)): ?>
                        <div class="pd-error">⚠️ <?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <button type="submit" name="place_order" class="pd-btn-order" id="submit-btn" disabled>
                        🚚 অর্ডার সম্পন্ন করুন
                    </button>
                    <p class="pd-payment-note"><i class="fas fa-info-circle"></i> ক্যাশ অন ডেলিভারি</p>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
let cart = [];
const deliveryFee = 50;

function addToCart(id, name, price, stock) {
    const existing = cart.find(item => item.id === id);
    if (existing) {
        if (existing.quantity < stock) {
            existing.quantity++;
        } else {
            alert('পর্যাপ্ত স্টক নেই!');
        }
    } else {
        cart.push({ id, name, price, quantity: 1, stock });
    }
    renderCart();
}

function updateQuantity(id, delta) {
    const item = cart.find(item => item.id === id);
    if (item) {
        const newQty = item.quantity + delta;
        if (newQty > 0 && newQty <= item.stock) {
            item.quantity = newQty;
        } else if (newQty === 0) {
            cart = cart.filter(it => it.id !== id);
        }
    }
    renderCart();
}

function renderCart() {
    const container = document.getElementById('cart-items');
    const itemsJsonInput = document.getElementById('items_json');
    const submitBtn = document.getElementById('submit-btn');
    const subtotalEl = document.getElementById('subtotal');
    const grandTotalEl = document.getElementById('grand-total');
    const formTotalInput = document.getElementById('form_total_amount');
    
    if (cart.length === 0) {
        container.innerHTML = '<p class="pd-empty-cart">এখনো কোনো ঔষধ যোগ করা হয়নি।</p>';
        submitBtn.disabled = true;
        subtotalEl.textContent = '৳0.00';
        grandTotalEl.textContent = '৳' + deliveryFee.toFixed(2);
        formTotalInput.value = deliveryFee;
    } else {
        container.innerHTML = cart.map(item => `
            <div class="pd-cart-item">
                <div class="pd-cart-item-info">
                    <div class="pd-cart-item-name">${item.name}</div>
                    <div class="pd-cart-item-price">৳${item.price.toFixed(2)} × ${item.quantity}</div>
                </div>
                <div class="pd-cart-controls">
                    <button type="button" class="pd-qty-btn" onclick="updateQuantity(${item.id}, -1)">−</button>
                    <span class="pd-qty-val">${item.quantity}</span>
                    <button type="button" class="pd-qty-btn" onclick="updateQuantity(${item.id}, 1)">+</button>
                </div>
            </div>
        `).join('');
        
        submitBtn.disabled = false;
    }
    
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    const grandTotal = subtotal + deliveryFee;
    
    subtotalEl.textContent = '৳' + subtotal.toFixed(2);
    grandTotalEl.textContent = '৳' + grandTotal.toFixed(2);
    formTotalInput.value = grandTotal;
    
    itemsJsonInput.value = JSON.stringify(cart);
}

function validateOrder() {
    if (cart.length === 0) {
        alert('অনুগ্রহ করে কমপক্ষে একটি ঔষধ যোগ করুন।');
        return false;
    }
    return true;
}

renderCart();
</script>

<?php
$stmt->close();
$conn->close();
include_once('includes/footer.php');
?>
