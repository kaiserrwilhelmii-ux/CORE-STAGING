<?php
// Ensure session is active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fetch live user info (including profile photo) if connected to DB
$header_uid = intval($_SESSION['user_id'] ?? 0);
$h_user = [
    'fullname'    => $_SESSION['fullname'] ?? 'User',
    'username'    => $_SESSION['username'] ?? '',
    'email'       => $_SESSION['email'] ?? '',
    'role'        => $_SESSION['role'] ?? 'user',
    'profile_pic' => ''
];

if (isset($conn) && $header_uid > 0) {
    $h_query = $conn->query("SELECT fullname, username, email, role, profile_pic FROM users WHERE id = $header_uid LIMIT 1");
    if ($h_query && $h_query->num_rows > 0) {
        $h_user = $h_query->fetch_assoc();
    }
}

$h_initials = strtoupper(substr($h_user['fullname'] ?? 'U', 0, 1));
$h_role_label = ucwords(str_replace('_', ' ', $h_user['role'] ?? 'User'));
?>

<!-- Universal Workday-Style Top Banner -->
<div class="top-banner-card">
    <div class="user-intro-wrap">
        <div class="banner-avatar" 
             id="headerAvatar"
             style="<?php if(!empty($h_user['profile_pic'])) echo "background-image: url('".$h_user['profile_pic']."');"; ?>">
            <?php if(empty($h_user['profile_pic'])) echo $h_initials; ?>
        </div>
        <div class="user-intro-info">
            <h2><?= htmlspecialchars($h_user['fullname']) ?></h2>
            <div class="user-meta-chips">
                <span class="badge-role"><?= htmlspecialchars($h_role_label) ?></span>
                <span><i class="fas fa-id-badge"></i> <?= htmlspecialchars($h_user['username']) ?></span>
                <?php if(!empty($h_user['email'])): ?>
                    <span><i class="fas fa-envelope"></i> <?= htmlspecialchars($h_user['email']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="header-right-actions">
        <div id="headerLiveDate" class="header-date-chip">Loading date...</div>
        <button type="button" id="themeToggle" class="theme-toggle">
            <i class="fas fa-moon"></i> Dark Mode
        </button>
    </div>
</div>

<style>
/* Universal Banner Styling */
.top-banner-card {
    background: var(--card-bg, #ffffff);
    border-radius: 16px;
    box-shadow: var(--card-shadow, 0 4px 6px rgba(0, 0, 0, 0.08));
    border: 1px solid var(--border-color, #ecf0f1);
    padding: 22px 28px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    flex-wrap: wrap;
    gap: 18px;
}

.user-intro-wrap {
    display: flex;
    align-items: center;
    gap: 20px;
}

.banner-avatar {
    width: 68px;
    height: 68px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3498db, #2980b9);
    color: #ffffff;
    font-size: 28px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 3px solid #ffffff;
    box-shadow: 0 4px 10px rgba(0,0,0,0.12);
    background-size: cover;
    background-position: center;
    flex-shrink: 0;
}

body.dark-mode .banner-avatar {
    border-color: #2d2d2d;
}

.user-intro-info h2 {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
    color: var(--text-color, #2c3e50);
}

.user-meta-chips {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 6px;
    flex-wrap: wrap;
    font-size: 13px;
    color: #888;
}

.badge-role {
    background-color: #e8f4fd;
    color: #2980b9;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}

body.dark-mode .badge-role {
    background-color: rgba(52, 152, 219, 0.2);
    color: #a8c7fa;
}

.header-right-actions {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-left: auto;
    flex-wrap: wrap;
}

.header-date-chip {
    font-size: 13px;
    color: var(--text-color, #2c3e50);
    opacity: 0.75;
    font-weight: 500;
}

@media (max-width: 768px) {
    .header-right-actions {
        width: 100%;
        justify-content: space-between;
    }
}
</style>

<script>
// Format date in banner
(function () {
    const dateEl = document.getElementById('headerLiveDate');
    if (dateEl) {
        const now = new Date();
        dateEl.textContent = now.toLocaleDateString('en-US', {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: true
        });
    }
})();
</script>
