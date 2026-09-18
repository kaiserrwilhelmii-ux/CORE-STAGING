<?php
$current_page = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['role'] ?? '';
$user_name = $_SESSION['name'] ?? $_SESSION['username'] ?? 'User';

if (!function_exists('isActive')) {
    function isActive($page, $current) {
        return $page === $current ? 'active' : '';
    }
}
?>

<!-- Gemini-Styled Sidebar -->
<aside id="appSidebar" class="sidebar">
    <!-- Header: Logo & Collapse Button -->
    <div class="sidebar-header">
        <div class="brand-info">
            <span class="brand-icon"><i class="fas fa-graduation-cap"></i></span>
            <span class="brand-title">CORE Evaluation</span>
        </div>
        <button type="button" class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <!-- Navigation Menu -->
    <nav class="sidebar-nav">
        <?php if ($user_role === 'admin'): ?>
            <a href="admin_dashboard.php" class="nav-item <?= isActive('admin_dashboard.php', $current_page) ?>" title="Dashboard">
                <i class="nav-icon fas fa-tachometer-alt"></i>
                <span class="nav-text">Dashboard</span>
            </a>
            <a href="admin_manage.php" class="nav-item <?= isActive('admin_manage.php', $current_page) ?>" title="Manage Users">
                <i class="nav-icon fas fa-users"></i>
                <span class="nav-text">Manage Users</span>
            </a>
            <a href="admin_evaluations.php" class="nav-item <?= isActive('admin_evaluations.php', $current_page) ?>" title="Evaluations">
                <i class="nav-icon fas fa-file-alt"></i>
                <span class="nav-text">Evaluations</span>
            </a>

        <?php elseif ($user_role === 'supervisor'): ?>
            <a href="supervisor_dashboard.php" class="nav-item <?= isActive('supervisor_dashboard.php', $current_page) ?>" title="Dashboard">
                <i class="nav-icon fas fa-tachometer-alt"></i>
                <span class="nav-text">Dashboard</span>
            </a>
            <a href="profile.php" class="nav-item <?= isActive('profile.php', $current_page) ?>" title="My Profile">
                <i class="nav-icon fas fa-user"></i>
                <span class="nav-text">My Profile</span>
            </a>

        <?php elseif ($user_role === 'student_teacher'): ?>
            <a href="dashboard.php" class="nav-item <?= isActive('dashboard.php', $current_page) ?>" title="Dashboard">
                <i class="nav-icon fas fa-chart-line"></i>
                <span class="nav-text">Dashboard</span>
            </a>
            <a href="user_evaluations.php" class="nav-item <?= isActive('user_evaluations.php', $current_page) ?>" title="My Evaluations">
                <i class="nav-icon fas fa-clipboard-check"></i>
                <span class="nav-text">My Evaluations</span>
            </a>
            <a href="student_portfolio.php" class="nav-item <?= isActive('student_portfolio.php', $current_page) ?>" title="My Portfolio">
                <i class="nav-icon fas fa-folder-open"></i>
                <span class="nav-text">My Portfolio</span>
            </a>
            <a href="profile.php" class="nav-item <?= isActive('profile.php', $current_page) ?>" title="My Profile">
                <i class="nav-icon fas fa-user"></i>
                <span class="nav-text">My Profile</span>
            </a>
        <?php endif; ?>
    </nav>

    <!-- Bottom Footer: User Info & Logout -->
    <div class="sidebar-footer">
        <div class="user-card" title="<?= htmlspecialchars($user_name) ?>">
            <div class="user-avatar">
                <?= strtoupper(substr($user_name, 0, 1)) ?>
            </div>
            <div class="user-details">
                <span class="user-name"><?= htmlspecialchars($user_name) ?></span>
                <span class="user-role"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $user_role))) ?></span>
            </div>
        </div>

        <a href="logout.php" class="logout-btn" title="Logout">
            <i class="nav-icon fas fa-sign-out-alt"></i>
            <span class="nav-text">Logout</span>
        </a>
    </div>
</aside>

<style>
/* Sidebar Container */
.sidebar {
    width: 250px;
    min-height: 100vh;
    background-color: #1e1f20;
    color: #e3e3e3;
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    transition: width 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    user-select: none;
    overflow-x: hidden;
    box-sizing: border-box;
}

/* Collapsed Width */
.sidebar.collapsed {
    width: 68px;
}

/* Header */
.sidebar-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 14px;
    height: 60px;
    box-sizing: border-box;
}

.brand-info {
    display: flex;
    align-items: center;
    gap: 12px;
    overflow: hidden;
    white-space: nowrap;
}

.brand-icon {
    font-size: 18px;
    color: #a8c7fa;
    width: 24px;
    text-align: center;
    flex-shrink: 0;
}

.brand-title {
    font-size: 15px;
    font-weight: 600;
    color: #f1f3f4;
    white-space: nowrap;
}

/* Collapse Toggle Button */
.sidebar-toggle {
    background: transparent;
    border: none;
    color: #9aa0a6;
    cursor: pointer;
    padding: 8px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.2s ease, color 0.2s ease;
    flex-shrink: 0;
}

.sidebar-toggle:hover {
    background-color: rgba(255, 255, 255, 0.08);
    color: #ffffff;
}

/* Navigation List */
.sidebar-nav {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 10px 10px;
    flex-grow: 1;
}

/* Navigation Links (Pill Style) */
.nav-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 10px 14px;
    color: #c4c7c5;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    border-radius: 24px;
    transition: background-color 0.2s cubic-bezier(0.4, 0, 0.2, 1), color 0.2s ease;
    white-space: nowrap;
}

.nav-item:hover {
    background-color: rgba(255, 255, 255, 0.08);
    color: #ffffff;
}

/* Active Highlight */
.nav-item.active {
    background-color: #004a77;
    color: #c2e7ff;
    font-weight: 600;
}

.nav-icon {
    font-size: 16px;
    width: 20px;
    text-align: center;
    flex-shrink: 0;
}

.nav-text {
    white-space: nowrap;
}

/* Footer Section */
.sidebar-footer {
    padding: 12px 10px;
    margin-top: auto;
    border-top: 1px solid rgba(255, 255, 255, 0.06);
    display: flex;
    flex-direction: column;
    gap: 8px;
}

/* User Card */
.user-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 10px;
    border-radius: 24px;
    background-color: rgba(255, 255, 255, 0.03);
    white-space: nowrap;
    overflow: hidden;
}

.user-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background-color: #37393b;
    color: #a8c7fa;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 600;
    flex-shrink: 0;
}

.user-details {
    display: flex;
    flex-direction: column;
    line-height: 1.2;
    overflow: hidden;
}

.user-name {
    font-size: 13px;
    font-weight: 500;
    color: #e3e3e3;
    text-overflow: ellipsis;
    overflow: hidden;
}

.user-role {
    font-size: 11px;
    color: #9aa0a6;
}

/* Logout Button */
.logout-btn {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 10px 14px;
    color: #f87171;
    background-color: rgba(239, 68, 68, 0.08);
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    border-radius: 24px;
    transition: background-color 0.2s ease, color 0.2s ease;
    white-space: nowrap;
}

.logout-btn:hover {
    background-color: #dc2626;
    color: #ffffff;
}

/* Collapsed Behavior */
.sidebar.collapsed .brand-info,
.sidebar.collapsed .nav-text,
.sidebar.collapsed .user-details {
    display: none;
}

.sidebar.collapsed .sidebar-header {
    justify-content: center;
}

.sidebar.collapsed .nav-item,
.sidebar.collapsed .logout-btn,
.sidebar.collapsed .user-card {
    justify-content: center;
    padding: 10px 0;
}
</style>

<script>
// Handles sidebar toggling and remembers collapsed/expanded state across pages
(function () {
    const sidebar = document.getElementById('appSidebar');
    const toggleBtn = document.getElementById('sidebarToggle');

    if (localStorage.getItem('sidebar_collapsed') === 'true') {
        sidebar.classList.add('collapsed');
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed'));
        });
    }
})();
</script>
