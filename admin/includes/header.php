<?php
// admin/includes/header.php
// Pure White & Responsive Layout Header with Unified Topbar & Security

if (!ob_get_level()) {
    ob_start();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure admin is authenticated
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php?msg=unauthorized');
    exit;
}

require_once __DIR__ . '/db.php';

$admin_id       = $_SESSION['admin_id'];
$admin_name     = $_SESSION['admin_name'] ?? 'Resort Admin';
$admin_username = $_SESSION['admin_username'] ?? '';
$admin_email    = $_SESSION['admin_email'] ?? '';
$admin_role     = $_SESSION['admin_role'] ?? 'admin';
$current_script = basename($_SERVER['PHP_SELF']);

// Live Counters for sidebar badges
$pending_count = 0;
$unread_complaints = 0;

if (isset($conn) && $conn instanceof mysqli) {
    $res_p = $conn->query("SELECT COUNT(*) AS c FROM bookings WHERE status = 'pending'");
    if ($res_p && $row_p = $res_p->fetch_assoc()) {
        $pending_count = (int)$row_p['c'];
    }

    $res_u = $conn->query("SELECT COUNT(*) AS c FROM complaints WHERE status = 'unread'");
    if ($res_u && $row_u = $res_u->fetch_assoc()) {
        $unread_complaints = (int)$row_u['c'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Dashboard &bull; Eco Divine Beach Resort</title>
  <link rel="icon" href="../favicon.png">

  <!-- Google Fonts: Poppins -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Bootstrap 5.3 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome 6.5 -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

  <style>
    :root {
      --sidebar-width: 270px;
      --sidebar-collapsed-width: 80px;
      --topbar-height: 70px;

      --primary-color: #059669;
      --primary-hover: #047857;
      --primary-light: #ecfdf5;
      --primary-glow: rgba(5, 150, 105, 0.15);

      --bg-body: #f8fafc;
      --bg-surface: #ffffff;
      --border-color: #e2e8f0;
      --border-light: #f1f5f9;

      --text-dark: #0f172a;
      --text-body: #334155;
      --text-muted: #64748b;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background-color: var(--bg-body);
      color: var(--text-body);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      overflow-x: hidden;
    }

    /* ========================================================
       SIDEBAR WRAPPER & DRAWER
       ======================================================== */
    .admin-sidebar {
      position: fixed;
      top: 0;
      left: 0;
      bottom: 0;
      width: var(--sidebar-width);
      background: var(--bg-surface);
      border-right: 1px solid var(--border-color);
      z-index: 1040;
      display: flex;
      flex-direction: column;
      transition: width 0.28s cubic-bezier(0.4, 0, 0.2, 1), transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
      box-shadow: 2px 0 12px rgba(15, 23, 42, 0.02);
    }

    .admin-main-wrap {
      margin-left: var(--sidebar-width);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      flex: 1;
      transition: margin-left 0.28s cubic-bezier(0.4, 0, 0.2, 1);
      background-color: var(--bg-body);
    }

    /* Mobile Backdrop */
    .sidebar-backdrop {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(15, 23, 42, 0.4);
      backdrop-filter: blur(2px);
      z-index: 1030;
      opacity: 0;
      visibility: hidden;
      transition: all 0.25s ease;
    }

    body.sidebar-open .sidebar-backdrop {
      opacity: 1;
      visibility: visible;
    }

    /* ========================================================
       DESKTOP COLLAPSED STATE
       ======================================================== */
    body.sidebar-collapsed .admin-sidebar {
      width: var(--sidebar-collapsed-width);
    }
    body.sidebar-collapsed .admin-main-wrap {
      margin-left: var(--sidebar-collapsed-width);
    }
    body.sidebar-collapsed .sidebar-hide-on-collapse {
      display: none !important;
    }
    body.sidebar-collapsed .sidebar-brand-lead {
      justify-content: center;
      padding: 16px 8px;
    }
    body.sidebar-collapsed .sidebar-link-btn {
      justify-content: center;
      padding: 12px 0;
    }
    body.sidebar-collapsed .sidebar-link-btn i {
      margin: 0;
      font-size: 1.25rem;
    }
    body.sidebar-collapsed .sidebar-nav-title {
      display: none;
    }

    /* ========================================================
       MOBILE RESPONSIVE BREAKPOINT (< 992px)
       ======================================================== */
    @media (max-width: 991.98px) {
      .admin-sidebar {
        transform: translateX(-100%);
        box-shadow: 8px 0 24px rgba(15, 23, 42, 0.12);
      }
      .admin-main-wrap {
        margin-left: 0 !important;
      }
      body.sidebar-open .admin-sidebar {
        transform: translateX(0);
      }
      .admin-topbar {
        padding: 0 16px;
      }
      .admin-page-container {
        padding: 20px 14px;
      }
    }

    /* ========================================================
       TOPBAR NAVIGATION
       ======================================================== */
    .admin-topbar {
      height: var(--topbar-height);
      background: var(--bg-surface);
      border-bottom: 1px solid var(--border-color);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 28px;
      position: sticky;
      top: 0;
      z-index: 1020;
      box-shadow: 0 1px 4px rgba(15, 23, 42, 0.02);
    }

    .btn-toggle-sidebar {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      background: #ffffff;
      border: 1px solid var(--border-color);
      color: var(--text-dark);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1.05rem;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .btn-toggle-sidebar:hover {
      background: var(--primary-light);
      color: var(--primary-color);
      border-color: #a7f3d0;
    }

    .topbar-brand-title {
      display: flex;
      flex-direction: column;
    }
    .topbar-brand-title .title-text {
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--text-dark);
      line-height: 1.2;
    }
    .topbar-brand-title .subtitle-text {
      font-size: 0.72rem;
      color: var(--primary-color);
      text-transform: uppercase;
      letter-spacing: 0.8px;
      font-weight: 600;
    }

    .admin-user-pill {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 6px 14px 6px 8px;
      background: #ffffff;
      border: 1px solid var(--border-color);
      border-radius: 40px;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
      cursor: pointer;
      transition: all 0.2s ease;
      text-decoration: none;
    }
    .admin-user-pill:hover, .admin-user-pill[aria-expanded="true"] {
      border-color: #059669;
      background: #f0fdf4;
      box-shadow: 0 3px 10px rgba(5, 150, 105, 0.12);
    }
    .admin-user-pill::after {
      display: none !important;
    }
    .admin-user-avatar {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: linear-gradient(135deg, #059669 0%, #10b981 100%);
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.88rem;
      font-weight: 700;
    }
    .admin-user-details {
      display: flex;
      flex-direction: column;
      line-height: 1.2;
    }
    .admin-user-name {
      font-size: 0.86rem;
      font-weight: 600;
      color: var(--text-dark);
    }
    .admin-user-status {
      font-size: 0.7rem;
      color: var(--primary-color);
      display: flex;
      align-items: center;
      gap: 4px;
      font-weight: 600;
    }
    .admin-user-status::before {
      content: "";
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: #10b981;
      display: inline-block;
      box-shadow: 0 0 6px #10b981;
    }

    .btn-top-logout {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      background: #fef2f2;
      border: 1px solid #fecaca;
      color: #dc2626;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1rem;
      text-decoration: none;
      transition: all 0.2s ease;
    }
    .btn-top-logout:hover {
      background: #ef4444;
      color: #ffffff;
      border-color: #ef4444;
      transform: translateY(-1px);
    }

    /* Page Content Body */
    .admin-page-container {
      flex: 1;
      padding: 30px 28px;
    }

    /* ========================================================
       SHARED PURE WHITE CARDS & CLEAN TABLES
       ======================================================== */
    .admin-card {
      background-color: var(--bg-surface);
      border: 1px solid var(--border-color);
      border-radius: 18px;
      box-shadow: 0 4px 18px rgba(15, 23, 42, 0.03);
      transition: all 0.25s ease;
      overflow: hidden;
    }
    .admin-card:hover {
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
    }

    .admin-card-header {
      padding: 20px 24px;
      border-bottom: 1px solid var(--border-color);
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
      background-color: var(--bg-surface);
    }

    .admin-card-body {
      padding: 24px;
    }

    /* Light Clean Tables */
    .table-dark-custom {
      width: 100%;
      margin-bottom: 0;
      color: var(--text-dark);
      border-color: var(--border-color);
    }
    .table-dark-custom th {
      background: #f8fafc;
      color: #475569;
      font-size: 0.78rem;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      font-weight: 700;
      padding: 14px 16px;
      border-bottom: 1px solid var(--border-color);
      white-space: nowrap;
    }
    .table-dark-custom td {
      padding: 14px 16px;
      vertical-align: middle;
      border-bottom: 1px solid var(--border-light);
      font-size: 0.88rem;
      background: #ffffff;
      color: var(--text-body);
    }
    .table-dark-custom tbody tr:hover td {
      background: #f8fafc;
    }

    /* Status Badges */
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 12px;
      border-radius: 30px;
      font-size: 0.75rem;
      font-weight: 700;
      letter-spacing: 0.4px;
      text-transform: uppercase;
    }
    .status-badge-pending {
      background: #fffbeb;
      color: #b45309;
      border: 1px solid #fde68a;
    }
    .status-badge-accepted, .status-badge-paid {
      background: #ecfdf5;
      color: #047857;
      border: 1px solid #a7f3d0;
    }
    .status-badge-cancelled, .status-badge-unpaid {
      background: #fef2f2;
      color: #b91c1c;
      border: 1px solid #fecaca;
    }
    .status-badge-partial {
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
    }

    /* Single-Page View Panes */
    .dashboard-view-pane {
      display: none;
      animation: fadeInView 0.3s ease-out;
    }
    .dashboard-view-pane.active {
      display: block;
    }
    /* Dashboard Time Presets & Toolbar */
    .dash-preset-btn {
      font-size: 0.8rem;
      border: 1px solid #cbd5e1;
      background-color: #f8fafc;
      color: #475569;
      transition: all 0.2s ease-in-out;
    }
    .dash-preset-btn:hover {
      background-color: #f1f5f9;
      color: #0f172a;
      border-color: #94a3b8;
    }
    .dash-preset-btn.active {
      background-color: #059669 !important;
      color: #ffffff !important;
      border-color: #059669 !important;
      box-shadow: 0 2px 6px rgba(5, 150, 105, 0.25);
    }
  </style>
</head>
<body>

  <!-- Backdrop for Mobile Sidebar -->
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <!-- Sidebar Component Included Below in Output Flow -->
  <?php include __DIR__ . '/sidebar.php'; ?>

  <!-- Main Content Wrapper -->
  <div class="admin-main-wrap">
    
    <!-- Unified Responsive Topbar -->
    <header class="admin-topbar">
      
      <!-- Left: Toggle & Breadcrumb -->
      <div class="d-flex align-items-center gap-3">
        <button type="button" class="btn-toggle-sidebar" id="sidebarToggleBtn" aria-label="Toggle Navigation">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div class="topbar-brand-title">
          <span class="title-text">Eco Divine Resort</span>
          <span class="subtitle-text">Management Dashboard</span>
        </div>
      </div>

      <!-- Right: User Identity & Sign Out -->
      <!-- Right: User Identity & Popup Dropdown Menu -->
      <div class="d-flex align-items-center gap-2">
        <div class="dropdown">
          <button class="admin-user-pill border-0 text-start" type="button" id="adminProfileDropdownBtn" data-bs-toggle="dropdown" aria-expanded="false" title="Account & Profile Options">
            <div class="admin-user-avatar">
              <?= strtoupper(substr($admin_name, 0, 1)) ?>
            </div>
            <div class="admin-user-details d-none d-sm-flex">
              <span class="admin-user-name text-truncate" style="max-width: 140px;"><?= htmlspecialchars($admin_name) ?></span>
              <span class="admin-user-status">Authorized</span>
            </div>
            <i class="fa-solid fa-chevron-down text-muted small ms-1" style="font-size: 0.68rem;"></i>
          </button>

          <!-- Profile Popup Menu -->
          <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 py-2 mt-2" aria-labelledby="adminProfileDropdownBtn" style="min-width: 260px; border-radius: 16px; border: 1px solid #e2e8f0 !important; box-shadow: 0 10px 25px rgba(0,0,0,0.08) !important;">
            <li class="px-3 py-2 border-bottom mb-2 bg-light bg-opacity-50">
              <div class="fw-bold text-dark text-truncate fs-6"><?= htmlspecialchars($admin_name) ?></div>
              <div class="text-muted small text-truncate"><code class="text-dark">@<?= htmlspecialchars($admin_username) ?></code></div>
              <?php if (!empty($admin_email)): ?>
                <div class="text-muted small text-truncate" style="font-size: 0.75rem;"><?= htmlspecialchars($admin_email) ?></div>
              <?php endif; ?>
              <div class="mt-1">
                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2 py-0 fw-semibold" style="font-size: 0.68rem;">
                  <?= ucfirst($admin_role) ?>
                </span>
              </div>
            </li>
            <li>
              <a class="dropdown-item py-2 px-3 d-flex align-items-center gap-2 text-dark fw-medium" href="dashboard.php#view-settings" onclick="activateView('view-settings', event); if(typeof switchSettingsTab==='function') switchSettingsTab('pill-profile-tab');">
                <i class="fa-solid fa-user-gear text-success" style="width: 18px;"></i>
                <span>Profile &amp; Security</span>
              </a>
            </li>
            <li>
              <a class="dropdown-item py-2 px-3 d-flex align-items-center gap-2 text-dark fw-medium" href="dashboard.php#view-settings" onclick="activateView('view-settings', event); if(typeof switchSettingsTab==='function') switchSettingsTab('pill-sms-tab');">
                <i class="fa-solid fa-comment-sms text-primary" style="width: 18px;"></i>
                <span>SMS Gateway Settings</span>
              </a>
            </li>
            <li>
              <a class="dropdown-item py-2 px-3 d-flex align-items-center gap-2 text-dark fw-medium" href="dashboard.php#view-settings" onclick="activateView('view-settings', event); if(typeof switchSettingsTab==='function') switchSettingsTab('pill-email-tab');">
                <i class="fa-solid fa-envelope-open-text text-danger" style="width: 18px;"></i>
                <span>Email &amp; SMTP Settings</span>
              </a>
            </li>
            <li>
              <a class="dropdown-item py-2 px-3 d-flex align-items-center gap-2 text-dark fw-medium" href="dashboard.php#view-admins" onclick="activateView('view-admins', event)">
                <i class="fa-solid fa-user-shield text-info" style="width: 18px;"></i>
                <span>Staff &amp; Admins</span>
              </a>
            </li>
            <li><hr class="dropdown-divider my-1"></li>
            <li>
              <a class="dropdown-item py-2 px-3 d-flex align-items-center gap-2 text-danger fw-semibold" href="logout.php">
                <i class="fa-solid fa-arrow-right-from-bracket" style="width: 18px;"></i>
                <span>Sign Out</span>
              </a>
            </li>
          </ul>
        </div>

        <a href="logout.php" class="btn-top-logout" title="Sign Out Securely">
          <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </a>
      </div>

    </header>

    <!-- Main Dynamic Page Body -->
    <main class="admin-page-container">
