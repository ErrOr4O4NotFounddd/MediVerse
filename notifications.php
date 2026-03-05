<?php
include_once('includes/db_config.php');
include_once('includes/header.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}
$user_id = $_SESSION['user_id'];

// Mark all notifications as read when the user visits this page
$mark_read_stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
$mark_read_stmt->bind_param("i", $user_id);
$mark_read_stmt->execute();

// Fetch all notifications for the user
$notifications_stmt = $conn->prepare("SELECT id, message, created_at, is_read FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$notifications_stmt->bind_param("i", $user_id);
$notifications_stmt->execute();
$notifications_result = $notifications_stmt->get_result();
$notif_count = $notifications_result->num_rows;

// Helper: detect notification type from message text
function getNotifMeta($message) {
    $msg = mb_strtolower($message);
    if (str_contains($msg, 'appointment') || str_contains($msg, 'অ্যাপয়েন্টমেন্ট') || str_contains($msg, 'সিরিয়াল')) return ['icon'=>'📅','gradient'=>'linear-gradient(135deg,#6366f1,#818cf8)','bg'=>'#eef2ff','color'=>'#6366f1'];
    if (str_contains($msg, 'ambulance') || str_contains($msg, 'অ্যাম্বুলেন্স')) return ['icon'=>'🚑','gradient'=>'linear-gradient(135deg,#ef4444,#f87171)','bg'=>'#fef2f2','color'=>'#ef4444'];
    if (str_contains($msg, 'medicine') || str_contains($msg, 'ঔষধ') || str_contains($msg, 'pharmacy') || str_contains($msg, 'order') || str_contains($msg, 'অর্ডার')) return ['icon'=>'💊','gradient'=>'linear-gradient(135deg,#10b981,#34d399)','bg'=>'#ecfdf5','color'=>'#10b981'];
    if (str_contains($msg, 'blood') || str_contains($msg, 'রক্ত')) return ['icon'=>'🩸','gradient'=>'linear-gradient(135deg,#dc2626,#ef4444)','bg'=>'#fef2f2','color'=>'#dc2626'];
    if (str_contains($msg, 'lab') || str_contains($msg, 'test') || str_contains($msg, 'টেস্ট')) return ['icon'=>'🧪','gradient'=>'linear-gradient(135deg,#7c3aed,#8b5cf6)','bg'=>'#faf5ff','color'=>'#7c3aed'];
    if (str_contains($msg, 'delivery') || str_contains($msg, 'ডেলিভারি')) return ['icon'=>'🚚','gradient'=>'linear-gradient(135deg,#f59e0b,#fbbf24)','bg'=>'#fffbeb','color'=>'#f59e0b'];
    if (str_contains($msg, 'cancel') || str_contains($msg, 'বাতিল') || str_contains($msg, 'reject')) return ['icon'=>'❌','gradient'=>'linear-gradient(135deg,#ef4444,#f87171)','bg'=>'#fef2f2','color'=>'#ef4444'];
    if (str_contains($msg, 'confirm') || str_contains($msg, 'approve') || str_contains($msg, 'accepted') || str_contains($msg, 'সফল') || str_contains($msg, 'গৃহীত')) return ['icon'=>'✅','gradient'=>'linear-gradient(135deg,#10b981,#34d399)','bg'=>'#ecfdf5','color'=>'#10b981'];
    return ['icon'=>'🔔','gradient'=>'linear-gradient(135deg,#3b82f6,#60a5fa)','bg'=>'#eff6ff','color'=>'#3b82f6'];
}

// Relative time
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    if ($diff->y > 0) return $diff->y . ' বছর আগে';
    if ($diff->m > 0) return $diff->m . ' মাস আগে';
    if ($diff->d > 0) return $diff->d . ' দিন আগে';
    if ($diff->h > 0) return $diff->h . ' ঘণ্টা আগে';
    if ($diff->i > 0) return $diff->i . ' মিনিট আগে';
    return 'এইমাত্র';
}
?>

<style>
/* ==================== NOTIFICATIONS — Premium Redesign ==================== */
@keyframes nf-fadeInUp { from { opacity:0; transform:translateY(22px); } to { opacity:1; transform:translateY(0); } }
@keyframes nf-float { 0%,100% { transform:translateY(0); } 50% { transform:translateY(-6px); } }

.nf-page { background:#f0f2f5; min-height:100vh; padding-bottom:60px; }

/* ===== HERO ===== */
.nf-hero {
    background: linear-gradient(135deg, #3b82f6, #6366f1, #8b5cf6);
    position:relative; padding:50px 0 100px; overflow:hidden;
}
.nf-hero::before {
    content:''; position:absolute; inset:0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
}
.nf-hero::after { content:''; position:absolute; bottom:0; left:0; right:0; height:80px; background:linear-gradient(to bottom, transparent, #f0f2f5); }
.nf-hero .container { position:relative; z-index:2; }

.nf-hero-content { text-align:center; animation:nf-fadeInUp .7s ease-out; }
.nf-hero-icon {
    width:70px; height:70px; border-radius:20px;
    background:rgba(255,255,255,.18); backdrop-filter:blur(10px);
    border:2px solid rgba(255,255,255,.25);
    display:inline-flex; align-items:center; justify-content:center;
    font-size:32px; margin-bottom:16px;
}
.nf-hero-title { font-size:34px; font-weight:800; color:#fff; margin:0 0 8px; text-shadow:0 2px 10px rgba(0,0,0,.15); }
.nf-hero-sub { font-size:15px; color:rgba(255,255,255,.8); margin:0; }

/* ===== STAT BAR ===== */
.nf-stat-bar {
    background:#fff; border-radius:16px; padding:18px 24px;
    box-shadow:0 6px 24px rgba(0,0,0,.07);
    margin-top:-45px; position:relative; z-index:5;
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:24px; animation:nf-fadeInUp .5s ease-out .1s backwards;
}
.nf-stat-text { font-size:15px; font-weight:600; color:#1e293b; display:flex; align-items:center; gap:8px; }
.nf-stat-badge {
    background:linear-gradient(135deg,#3b82f6,#6366f1);
    color:#fff; padding:4px 14px; border-radius:50px;
    font-size:13px; font-weight:700;
}

/* ===== NOTIFICATION LIST ===== */
.nf-list { display:flex; flex-direction:column; gap:12px; max-width:800px; margin:0 auto; }

.nf-item {
    display:flex; gap:16px; align-items:flex-start;
    padding:20px 22px; background:#fff;
    border:1.5px solid #e2e8f0; border-radius:16px;
    transition:all .3s ease;
    animation:nf-fadeInUp .4s ease-out backwards;
}
.nf-item:hover { background:#fafbff; border-color:rgba(99,102,241,.15); box-shadow:0 6px 20px rgba(0,0,0,.05); transform:translateX(4px); }

.nf-item-icon {
    width:46px; height:46px; border-radius:14px;
    display:flex; align-items:center; justify-content:center;
    font-size:20px; flex-shrink:0;
}

.nf-item-body { flex:1; }
.nf-item-message { font-size:14px; color:#1e293b; line-height:1.65; margin:0 0 8px; font-weight:500; }
.nf-item-time {
    font-size:12px; color:#94a3b8; font-weight:600;
    display:inline-flex; align-items:center; gap:5px;
}

/* ===== EMPTY ===== */
.nf-empty { text-align:center; padding:60px 20px; color:#94a3b8; }
.nf-empty-icon { font-size:56px; margin-bottom:16px; animation:nf-float 3s ease-in-out infinite; }
.nf-empty h3 { margin:0 0 8px; color:#64748b; font-size:18px; }
.nf-empty p { margin:0; font-size:14px; }

/* ===== RESPONSIVE ===== */
@media(max-width:600px) {
    .nf-hero { padding:35px 0 80px; }
    .nf-hero-title { font-size:26px; }
    .nf-item { padding:16px; gap:12px; }
    .nf-item-icon { width:40px; height:40px; font-size:18px; border-radius:12px; }
    .nf-stat-bar { flex-direction:column; text-align:center; gap:8px; }
}
</style>

<div class="nf-page">
    <!-- ===== HERO ===== -->
    <div class="nf-hero">
        <div class="container">
            <div class="nf-hero-content">
                <div class="nf-hero-icon">🔔</div>
                <h1 class="nf-hero-title">আমার নোটিফিকেশন</h1>
                <p class="nf-hero-sub">আপনার সকল আপডেট ও বিজ্ঞপ্তি এখানে পাবেন</p>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- ===== STAT BAR ===== -->
        <div class="nf-stat-bar">
            <span class="nf-stat-text">🔔 মোট নোটিফিকেশন</span>
            <span class="nf-stat-badge"><?= $notif_count ?> টি</span>
        </div>

        <!-- ===== NOTIFICATIONS LIST ===== -->
        <?php if ($notifications_result && $notif_count > 0): ?>
            <div class="nf-list">
                <?php $i = 0; while($notif = $notifications_result->fetch_assoc()): $meta = getNotifMeta($notif['message']); ?>
                    <div class="nf-item" style="animation-delay:<?= min($i * 0.05, 0.5) ?>s">
                        <div class="nf-item-icon" style="background:<?= $meta['bg'] ?>; color:<?= $meta['color'] ?>;">
                            <?= $meta['icon'] ?>
                        </div>
                        <div class="nf-item-body">
                            <p class="nf-item-message"><?= htmlspecialchars($notif['message']) ?></p>
                            <span class="nf-item-time">
                                🕒 <?= timeAgo($notif['created_at']) ?> · <?= date("d M, Y, h:i A", strtotime($notif['created_at'])) ?>
                            </span>
                        </div>
                    </div>
                <?php $i++; endwhile; ?>
            </div>
        <?php else: ?>
            <div class="nf-empty">
                <div class="nf-empty-icon">🔔</div>
                <h3>কোনো নোটিফিকেশন নেই</h3>
                <p>আপনার কোনো নতুন বিজ্ঞপ্তি নেই।</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include_once('includes/footer.php'); ?>
