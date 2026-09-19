<?php
$current_page = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['role'] ?? '';
$user_name = $_SESSION['fullname'] ?? $_SESSION['name'] ?? $_SESSION['username'] ?? 'Administrator';
?>

<!-- Gemini-Styled Sidebar with Block Ecosystem Launcher & AJAX Content Swapper -->
<div id="appSidebar" class="sidebar">
    <!-- Header: Block Inc Logo Launcher, Title & Collapse Button -->
    <div class="sidebar-header">
        <div class="brand-info">
            <button type="button" class="block-launcher-btn" id="blockLauncherBtn" title="Block Ecosystem Apps">
                <!-- Block Inc 3D Isometric Cube Symbol -->
                <svg class="block-cube-svg" width="22" height="22" viewBox="0 0 24 24" fill="none">
                    <path d="M12 2.5L20.5 7.4V16.6L12 21.5L3.5 16.6V7.4L12 2.5Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                    <path d="M12 12V21.5" stroke="currentColor" stroke-width="2"/>
                    <path d="M12 12L20.5 7.4" stroke="currentColor" stroke-width="2"/>
                    <path d="M12 12L3.5 7.4" stroke="currentColor" stroke-width="2"/>
                </svg>
            </button>
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
            <a href="profile.php" class="nav-item <?= $current_page === 'profile.php' ? 'active' : '' ?>" title="My Profile">
                <i class="nav-icon fas fa-user"></i>
                <span class="nav-text">My Profile</span>
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

    <!-- Bottom Logout Button (Direct Full Page Navigation) -->
    <div class="sidebar-footer">
        <a href="logout.php" class="logout-btn" title="Logout">
            <i class="nav-icon fas fa-sign-out-alt"></i>
            <span class="nav-text">Logout</span>
        </a>
    </div>
</div>

<!-- Block Ecosystem Floating Dropdown Menu -->
<div id="blockAppMenu" class="block-app-dropdown">
    <div class="block-dropdown-header">
        <span>Connected Platforms</span>
        <span class="block-tag">Block Inc</span>
    </div>
    <div class="block-app-list">
        
        <!-- 1. Assembled -->
        <a href="https://app.assembled.hq" target="_blank" rel="noopener noreferrer" class="block-app-card">
            <div class="app-icon-badge app-bg-assembled">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                    <path d="M12 3L20 19H15.5L12 11.5L8.5 19H4L12 3Z" fill="white"/>
                </svg>
            </div>
            <div class="block-app-details">
                <span class="app-name">Assembled</span>
                <span class="app-subtext">Workforce & Shifts</span>
            </div>
            <i class="fas fa-external-link-alt app-ext-icon"></i>
        </a>

        <!-- 2. Docebo -->
        <a href="https://docebo.com" target="_blank" rel="noopener noreferrer" class="block-app-card">
            <div class="app-icon-badge app-bg-docebo">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                    <path d="M7 5H13C16.8 5 20 8.2 20 12C20 15.8 16.8 19 13 19H7V5ZM10.5 15.5H13C14.9 15.5 16.5 13.9 16.5 12C16.5 10.1 14.9 8.5 13 8.5H10.5V15.5Z" fill="white"/>
                </svg>
            </div>
            <div class="block-app-details">
                <span class="app-name">Docebo</span>
                <span class="app-subtext">Training & Learning LMS</span>
            </div>
            <i class="fas fa-external-link-alt app-ext-icon"></i>
        </a>

        <!-- 3. Cash App -->
        <a href="https://cash.app" target="_blank" rel="noopener noreferrer" class="block-app-card">
            <div class="app-icon-badge app-bg-cashapp">
                <svg width="22" height="22" viewBox="0 0 32 32" fill="none">
                    <path d="M17.5 10.8C17.5 10.1 16.9 9.6 15.8 9.6C14.5 9.6 13.5 10.2 13.5 11.4C13.5 12.8 14.8 13.3 16.3 13.8C18.6 14.6 20.2 15.6 20.2 18.1C20.2 20.2 18.6 21.6 16.2 21.9V23.5H14.5V21.9C13.2 21.7 12 20.9 11.5 19.8L13.2 18.7C13.6 19.4 14.4 20 15.6 20C16.8 20 17.6 19.4 17.6 18.4C17.6 17.2 16.4 16.7 14.8 16.1C12.7 15.3 11.2 14.4 11.2 11.9C11.2 9.9 12.8 8.4 15 8.1V6.5H16.8V8.1C18 8.4 19 9.1 19.4 9.9L17.5 10.8Z" fill="white"/>
                </svg>
            </div>
            <div class="block-app-details">
                <span class="app-name">Cash App</span>
                <span class="app-subtext">Finance & Payments</span>
            </div>
            <i class="fas fa-external-link-alt app-ext-icon"></i>
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

/* 2. Main Content Layout Offset & Smooth Fade Transition */
.main-content {
    margin-left: 260px !important;
    width: calc(100% - 260px) !important;
    padding: 30px !important;
    box-sizing: border-box !important;
    transition: margin-left 0.25s cubic-bezier(0.4, 0, 0.2, 1), width 0.25s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.15s ease !important;
}

.sidebar.collapsed ~ .main-content {
    margin-left: 70px !important;
    width: calc(100% - 70px) !important;
}

/* 3. Header & Greeting Box */
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

.greeting-box h2 {
    margin: 0 !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    color: var(--text-color) !important;
}

.date-box {
    font-size: 14px !important;
    opacity: 0.7 !important;
    margin-top: 5px !important;
    color: var(--text-color) !important;
}

/* Dark Mode Toggle Button */
.theme-toggle {
    background: transparent !important;
    border: 2px solid var(--text-color) !important;
    color: var(--text-color) !important;
    padding: 8px 16px !important;
    border-radius: 20px !important;
    cursor: pointer !important;
    font-weight: 600 !important;
    font-size: 13px !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    transition: all 0.2s ease !important;
}

.theme-toggle:hover {
    background: var(--text-color) !important;
    color: var(--card-bg) !important;
}

/* 4. Cards & Tables */
.card {
    background: var(--card-bg) !important;
    padding: 25px !important;
    border-radius: 12px !important;
    box-shadow: var(--card-shadow) !important;
    margin-bottom: 25px !important;
    border: 1px solid var(--border-color) !important;
}

.card h3 {
    margin-top: 0 !important;
    color: var(--text-color) !important;
    font-size: 18px !important;
}

table {
    width: 100% !important;
    border-collapse: collapse !important;
    margin-top: 10px !important;
}

th, td {
    padding: 14px 16px !important;
    text-align: left !important;
    border-bottom: 1px solid var(--border-color) !important;
    font-size: 14px !important;
}

th {
    background-color: rgba(0, 0, 0, 0.03) !important;
    color: var(--text-color) !important;
    font-weight: 600 !important;
}

tr:hover {
    background-color: rgba(0, 0, 0, 0.015) !important;
}

/* 5. Buttons & Badges */
.btn {
    padding: 8px 14px !important;
    border: none !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    text-decoration: none !important;
    transition: transform 0.15s ease, opacity 0.15s ease !important;
}

.btn:hover {
    opacity: 0.9 !important;
    transform: translateY(-1px) !important;
}

.btn-edit { background-color: #f39c12 !important; color: #ffffff !important; }
.btn-view, a[href*="admin_view_student"], a[href*="add_user"] { background-color: #3498db !important; color: #ffffff !important; }
.btn-remove { background-color: #e74c3c !important; color: #ffffff !important; }

.score-badge, .badge {
    padding: 4px 10px !important;
    border-radius: 12px !important;
    font-weight: 700 !important;
    font-size: 12px !important;
    color: #ffffff !important;
    display: inline-block !important;
}

.bg-green { background-color: #27ae60 !important; }
.bg-yellow { background-color: #f1c40f !important; color: #333333 !important; }
.bg-red { background-color: #e74c3c !important; }
.bg-grey { background-color: #bdc3c7 !important; color: #ffffff !important; }

/* 6. Sidebar Styles (Gemini Theme) */
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
    padding: 0 4px;
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

/* Block Inc Logo Launcher Button */
.block-launcher-btn {
    background: transparent;
    border: none;
    color: #ffffff;
    cursor: pointer;
    padding: 6px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.2s ease, transform 0.2s ease;
    flex-shrink: 0;
}

.block-launcher-btn:hover {
    background-color: rgba(255, 255, 255, 0.12);
    transform: scale(1.05);
}

.block-cube-svg {
    color: #ffffff;
    transition: stroke 0.2s;
}

.brand-title {
    font-size: 15px;
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

/* 7. Block Ecosystem Floating Dropdown Menu */
.block-app-dropdown {
    display: none;
    position: fixed;
    z-index: 10001;
    width: 280px;
    background: #222326;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    box-shadow: 0 12px 36px rgba(0, 0, 0, 0.5);
    padding: 12px;
    box-sizing: border-box;
    animation: blockPop 0.18s cubic-bezier(0.2, 0.8, 0.2, 1);
}

.block-app-dropdown.show {
    display: block;
}

@keyframes blockPop {
    from { opacity: 0; transform: translateY(-6px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

.block-dropdown-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 8px 10px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    font-size: 12px;
    font-weight: 600;
    color: #a8c7fa;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.block-tag {
    font-size: 10px;
    background: rgba(255, 255, 255, 0.1);
    color: #ffffff;
    padding: 2px 6px;
    border-radius: 4px;
}

.block-app-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-top: 8px;
}

.block-app-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px;
    border-radius: 10px;
    text-decoration: none;
    color: #ffffff;
    transition: background-color 0.15s ease, transform 0.15s ease;
}

.block-app-card:hover {
    background-color: rgba(255, 255, 255, 0.08);
    transform: translateX(3px);
}

.app-icon-badge {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.app-bg-assembled { background: linear-gradient(135deg, #6366f1, #4f46e5); }
.app-bg-docebo    { background: linear-gradient(135deg, #0066cc, #004080); }
.app-bg-cashapp   { background: #00D632; }

.block-app-details {
    display: flex;
    flex-direction: column;
    flex-grow: 1;
    overflow: hidden;
}

.app-name {
    font-size: 14px;
    font-weight: 600;
    color: #ffffff;
}

.app-subtext {
    font-size: 11px;
    color: #9aa0a6;
}

.app-ext-icon {
    font-size: 11px;
    color: #666;
    margin-right: 4px;
}
</style>

<script>
(function () {
    const sidebar = document.getElementById('appSidebar');
    const toggleBtn = document.getElementById('sidebarToggle');

    // 1. Immediately restore collapse state from localStorage
    if (localStorage.getItem('sidebar_collapsed') === 'true' && sidebar) {
        sidebar.classList.add('collapsed');
    }

    // 2. Collapse Toggle Listener
    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed') ? 'true' : 'false');
            const appMenu = document.getElementById('blockAppMenu');
            if (appMenu) appMenu.classList.remove('show');
        });
    }

    // 3. Immediately apply theme preference
    if (localStorage.getItem('theme') === 'dark') {
        document.body.classList.add('dark-mode');
    }

    // 4. Global Dark Mode Toggle Listener
    document.addEventListener('click', function (e) {
        const themeBtn = e.target.closest('#themeToggle, .theme-toggle');
        if (themeBtn) {
            e.preventDefault();
            document.body.classList.toggle('dark-mode');
            const isDark = document.body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeBtn.innerHTML = isDark
                ? '<i class="fas fa-sun"></i> Light Mode'
                : '<i class="fas fa-moon"></i> Dark Mode';
        }
    });

    // 5. Block Launcher Dropdown Popover
    const launcherBtn = document.getElementById('blockLauncherBtn');
    const appMenu = document.getElementById('blockAppMenu');

    if (launcherBtn && appMenu) {
        launcherBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            const rect = launcherBtn.getBoundingClientRect();
            appMenu.style.top = (rect.bottom + 8) + 'px';
            appMenu.style.left = Math.max(10, rect.left) + 'px';
            appMenu.classList.toggle('show');
        });

        document.addEventListener('click', function (e) {
            if (!appMenu.contains(e.target) && e.target !== launcherBtn) {
                appMenu.classList.remove('show');
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                appMenu.classList.remove('show');
            }
        });
    }

    // 6. Format Date Helper
    function refreshDate() {
        const dateEl = document.getElementById('headerLiveDate') || document.getElementById('currentDate');
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
    }
    refreshDate();

    // =========================================================================
    // 7. SEAMLESS AJAX CONTENT SWAPPER (SPA Navigation)
    // =========================================================================
    document.addEventListener('click', function (e) {
        const link = e.target.closest('.sidebar-nav a');
        if (!link) return;

        const href = link.getAttribute('href');
        // Do not intercept logout, external links, or anchor hashes
        if (!href || href === 'logout.php' || href.startsWith('http') || href.startsWith('#')) {
            return;
        }

        e.preventDefault();

        // Highlight selected nav item
        document.querySelectorAll('.sidebar-nav a').forEach(a => a.classList.remove('active'));
        link.classList.add('active');

        // Swap content
        loadPage(href, true);
    });

    async function loadPage(url, pushState = true) {
        const mainContainer = document.querySelector('.main-content');
        if (!mainContainer) {
            window.location.href = url;
            return;
        }

        // Smooth subtle fade out
        mainContainer.style.opacity = '0.4';

        try {
            const response = await fetch(url);
            if (!response.ok) {
                window.location.href = url;
                return;
            }

            const htmlText = await response.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(htmlText, 'text/html');

            const newContent = doc.querySelector('.main-content');
            if (newContent) {
                // Swap the inner content
                mainContainer.innerHTML = newContent.innerHTML;

                // Update document title
                if (doc.title) {
                    document.title = doc.title;
                }

                // Update browser URL and history
                if (pushState) {
                    history.pushState({ url: url }, '', url);
                }

                // Execute any page-specific inline scripts in the new content
                const inlineScripts = newContent.querySelectorAll('script');
                inlineScripts.forEach(oldScript => {
                    const newScript = document.createElement('script');
                    Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
                    newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                    document.body.appendChild(newScript);
                    newScript.remove();
                });

                // Re-evaluate live date
                refreshDate();
            } else {
                window.location.href = url;
            }
        } catch (err) {
            window.location.href = url;
        } finally {
            mainContainer.style.opacity = '1';
        }
    }

    // Handle Browser Back & Forward Buttons
    window.addEventListener('popstate', function (e) {
        const targetUrl = (e.state && e.state.url) ? e.state.url : window.location.pathname;
        
        // Sync active link in sidebar
        document.querySelectorAll('.sidebar-nav a').forEach(a => {
            if (a.getAttribute('href') === targetUrl || targetUrl.endsWith(a.getAttribute('href'))) {
                a.classList.add('active');
            } else {
                a.classList.remove('active');
            }
        });

        loadPage(targetUrl, false);
    });
})();
</script>
