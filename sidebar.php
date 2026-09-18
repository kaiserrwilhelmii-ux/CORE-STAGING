<?php
$current_page = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['role'] ?? '';
?>

<!-- Gemini-Style Sidebar -->
<div id="appSidebar" class="sidebar">
    <!-- Header: Logo & Collapse Button -->
    <div class="sidebar-header">
        <div class="brand-info">
            <i class="fas fa-graduation-cap brand-icon"></i>
            <span class="brand-title">CORE Evaluation</span>
        </div>
        <button type="button" class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <!-- Navigation Menu -->
    <nav class="sidebar-nav">
        <?php if ($user_role === 'admin'): ?>
            <a href="admin_dashboard.php" class="nav-item <?= $current_page === 'admin_dashboard.php' ? 'active' : '' ?>" title="Dashboard">
                <i class="nav-icon fas fa-tachometer-alt"></i>
                <span class="nav-text">Dashboard</span>
            </a>
            <a href="admin_manage.php" class="nav-item <?= $current_page === 'admin_manage.php' ? 'active' : '' ?>" title="Manage Users">
                <i class="nav-icon fas fa-users"></i>
                <span class="nav-text">Manage Users</span>
            </a>
            <a href="admin_evaluations.php" class="nav-item <?= $current_page === 'admin_evaluations.php' ? 'active' : '' ?>" title="Evaluations">
                <i class="nav-icon fas fa-file-alt"></i>
                <span class="nav-text">Evaluations</span>
            </a>

        <?php elseif ($user_role === 'supervisor'): ?>
            <a href="supervisor_dashboard.php" class="nav-item <?= $current_page === 'supervisor_dashboard.php' ? 'active' : '' ?>" title="Dashboard">
                <i class="nav-icon fas fa-tachometer-alt"></i>
                <span class="nav-text">Dashboard</span>
            </a>
            <a href="profile.php" class="nav-item <?= $current_page === 'profile.php' ? 'active' : '' ?>" title="My Profile">
                <i class="nav-icon fas fa-user"></i>
                <span class="nav-text">My Profile</span>
            </a>

        <?php elseif ($user_role === 'student_teacher'): ?>
            <a href="dashboard.php" class="nav-item <?= $current_page === 'dashboard.php' ? 'active' : '' ?>" title="Dashboard">
                <i class="nav-icon fas fa-chart-line"></i>
                <span class="nav-text">Dashboard</span>
            </a>
            <a href="user_evaluations.php" class="nav-item <?= $current_page === 'user_evaluations.php' ? 'active' : '' ?>" title="My Evaluations">
                <i class="nav-icon fas fa-clipboard-check"></i>
                <span class="nav-text">My Evaluations</span>
            </a>
            <a href="student_portfolio.php" class="nav-item <?= $current_page === 'student_portfolio.php' ? 'active' : '' ?>" title="My Portfolio">
                <i class="nav-icon fas fa-folder-open"></i>
                <span class="nav-text">My Portfolio</span>
            </a>
            <a href="profile.php" class="nav-item <?= $current_page === 'profile.php' ? 'active' : '' ?>" title="My Profile">
                <i class="nav-icon fas fa-user"></i>
                <span class="nav-text">My Profile</span>
            </a>
        <?php endif; ?>
    </nav>

    <!-- Bottom Logout Button -->
    <div class="sidebar-footer">
        <a href="logout.php" class="logout-btn" title="Logout">
            <i class="nav-icon fas fa-sign-out-alt"></i>
            <span class="nav-text">Logout</span>
        </a>
    </div>
</div>

<style>
/* 1. Sidebar Container (Scoped strictly to .sidebar) */
.sidebar {
    width: 260px;
    height: 100vh;
    background-color: #1e1f20;
    color: #e3e3e3;
    position: fixed;
    top: 0;
    left: 0;
    display: flex;
    flex-direction: column;
    box-shadow: 4px 0 10px rgba(0, 0, 0, 0.15);
    z-index: 1000;
    box-sizing: border-box;
    padding: 16px 12px;
    transition: width 0.25s cubic-bezier(0.4, 0, 0.2, 1), padding 0.25s ease;
    overflow-x: hidden;
}

/* Collapsed Width */
.sidebar.collapsed {
    width: 70px;
    padding: 16px 8px;
}

/* 2. Main Content Auto-Adjustment When Collapsed */
.main-content {
    transition: margin-left 0.25s cubic-bezier(0.4, 0, 0.2, 1), width 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

body.sidebar-collapsed .main-content,
.sidebar.collapsed ~ .main-content {
    margin-left: 70px !important;
    width: calc(100% - 70px) !important;
}

/* 3. Header & Toggle */
.sidebar-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 48px;
    padding: 0 6px;
    margin-bottom: 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    box-sizing: border-box;
}

.brand-info {
    display: flex;
    align-items: center;
    gap: 10px;
    overflow: hidden;
    white-space: nowrap;
}

.brand-icon {
    font-size: 18px;
    color: #a8c7fa;
    width: 22px;
    text-align: center;
    flex-shrink: 0;
}

.brand-title {
    font-size: 16px;
    font-weight: 600;
    color: #f1f3f4;
    letter-spacing: 0.2px;
}

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

/* 4. Navigation Links (Gemini Pill Style) */
.sidebar-nav {
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex-grow: 1;
}

.sidebar .nav-item {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    padding: 12px 16px !important;
    color: #bdc3c7 !important;
    text-decoration: none !important;
    font-size: 14px !important;
    font-weight: 500 !important;
    border-radius: 24px !important;
    margin: 0 !important;
    transform: none !important;
    white-space: nowrap !important;
    transition: background-color 0.2s cubic-bezier(0.4, 0, 0.2, 1), color 0.2s ease !important;
}

.sidebar .nav-item:hover {
    background-color: rgba(255, 255, 255, 0.08) !important;
    color: #ffffff !important;
    transform: none !important;
}

/* Active Highlight Pill */
.sidebar .nav-item.active {
    background-color: #004a77 !important;
    color: #c2e7ff !important;
    font-weight: 600 !important;
    transform: none !important;
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

/* 5. Logout Button */
.sidebar-footer {
    margin-top: auto;
    padding-top: 15px;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
}

.sidebar .logout-btn {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 10px !important;
    padding: 12px 16px !important;
    background-color: #c0392b !important;
    color: #ffffff !important;
    text-decoration: none !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    border-radius: 24px !important;
    margin: 0 !important;
    transform: none !important;
    white-space: nowrap !important;
    transition: background-color 0.2s ease !important;
}

.sidebar .logout-btn:hover {
    background-color: #e74c3c !important;
    transform: none !important;
}

/* 6. Collapsed States */
.sidebar.collapsed .brand-info,
.sidebar.collapsed .nav-text {
    display: none;
}

.sidebar.collapsed .sidebar-header {
    justify-content: center;
    padding: 0;
}

.sidebar.collapsed .nav-item,
.sidebar.collapsed .logout-btn {
    justify-content: center !important;
    padding: 12px 0 !important;
}
</style>

<script>
(function () {
    const sidebar = document.getElementById('appSidebar');
    const toggleBtn = document.getElementById('sidebarToggle');

    function updateState(collapsed) {
        if (collapsed) {
            sidebar.classList.add('collapsed');
            document.body.classList.add('sidebar-collapsed');
        } else {
            sidebar.classList.remove('collapsed');
            document.body.classList.remove('sidebar-collapsed');
        }
    }

    // Restore saved state
    if (localStorage.getItem('sidebar_collapsed') === 'true') {
        updateState(true);
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            const isCollapsed = sidebar.classList.contains('collapsed');
            updateState(!isCollapsed);
            localStorage.setItem('sidebar_collapsed', !isCollapsed);
        });
    }
})();
</script>
