<?php
$current_page = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['role'] ?? '';
$user_name = $_SESSION['fullname'] ?? $_SESSION['name'] ?? $_SESSION['username'] ?? 'Administrator';
?>

<!-- Gemini-Styled Sidebar -->
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
/* 1. Theme Variables & Base Styles */
:root {
    --bg-color: #f4f6f9;
    --text-color: #2c3e50;
    --sidebar-bg: #1e1f20;
    --sidebar-text: #ecf0f1;
    --card-bg: #ffffff;
    --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.08);
    --input-bg: #ffffff;
    --input-border: #dfe6e9;
    --btn-primary: #3498db;
    --btn-hover: #2980b9;
    --border-color: #ecf0f1;
}

body.dark-mode {
    --bg-color: #1a1a1a;
    --text-color: #ecf0f1;
    --sidebar-bg: #131314;
    --sidebar-text: #bdc3c7;
    --card-bg: #2d2d2d;
    --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
    --input-bg: #404040;
    --input-border: #555555;
    --btn-primary: #3498db;
    --btn-hover: #5dade2;
    --border-color: #404040;
}

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
    background-color: var(--bg-color) !important;
    color: var(--text-color) !important;
    margin: 0 !important;
    padding: 0 !important;
    min-height: 100vh;
}

/* 2. Strict Layout Offset: Guarantees content starts beside the sidebar */
.main-content {
    margin-left: 260px !important;
    width: calc(100% - 260px) !important;
    padding: 30px !important;
    box-sizing: border-box !important;
    transition: margin-left 0.25s cubic-bezier(0.4, 0, 0.2, 1), width 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
}

/* Content only moves when the sidebar element itself has .collapsed */
.sidebar.collapsed ~ .main-content {
    margin-left: 70px !important;
    width: calc(100% - 70px) !important;
}

/* 3. Cards & Header Styling */
.top-header {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    margin-bottom: 30px !important;
    background: var(--card-bg) !important;
    padding: 20px 25px !important;
    border-radius: 12px !important;
    box-shadow: var(--card-shadow) !important;
}

.card {
    background: var(--card-bg) !important;
    padding: 25px !important;
    border-radius: 12px !important;
    box-shadow: var(--card-shadow) !important;
    margin-bottom: 25px !important;
    border: 1px solid var(--border-color) !important;
}

/* 4. Sidebar Styles (Gemini Theme) */
.sidebar {
    width: 260px !important;
    height: 100vh !important;
    background-color: #1e1f20 !important;
    color: #e3e3e3 !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    box-shadow: 4px 0 10px rgba(0, 0, 0, 0.15) !important;
    z-index: 1000 !important;
    box-sizing: border-box !important;
    padding: 16px 12px !important;
    transition: width 0.25s cubic-bezier(0.4, 0, 0.2, 1), padding 0.25s ease !important;
    overflow-x: hidden !important;
}

.sidebar.collapsed {
    width: 70px !important;
    padding: 16px 8px !important;
}

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

/* Nav Menu Items (Pill Style) */
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
    white-space: nowrap !important;
    transition: background-color 0.2s cubic-bezier(0.4, 0, 0.2, 1), color 0.2s ease !important;
}

.sidebar .nav-item:hover {
    background-color: rgba(255, 255, 255, 0.08) !important;
    color: #ffffff !important;
}

.sidebar .nav-item.active {
    background-color: #004a77 !important;
    color: #c2e7ff !important;
    font-weight: 600 !important;
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

/* Logout Button */
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
    white-space: nowrap !important;
    transition: background-color 0.2s ease !important;
}

.sidebar .logout-btn:hover {
    background-color: #e74c3c !important;
}

/* Collapsed States */
.sidebar.collapsed .brand-info,
.sidebar.collapsed .nav-text {
    display: none !important;
}

.sidebar.collapsed .sidebar-header {
    justify-content: center !important;
    padding: 0 !important;
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

    // Clears any stuck collapsed state so it loads fully expanded by default
    localStorage.removeItem('sidebar_collapsed');

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
        });
    }
})();
</script>
