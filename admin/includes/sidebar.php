<?php
// admin/includes/sidebar.php
if (!isset($pending_count)) $pending_count = 0;
if (!isset($unread_complaints)) $unread_complaints = 0;
if (!isset($admin_name)) $admin_name = $_SESSION['admin_name'] ?? 'Admin';
?>

<aside class="admin-sidebar" id="adminSidebar">
  
  <!-- Sidebar Brand Header & Mobile Close -->
  <div class="sidebar-brand-lead d-flex align-items-center justify-content-between p-3 border-bottom" style="border-color: #e2e8f0 !important; background: #ffffff;">
    <a href="dashboard.php#view-dashboard" class="d-flex align-items-center gap-3 text-decoration-none" onclick="activateView('view-dashboard')">
      <img src="../favicon.png" alt="Eco Divine Logo" class="rounded-3 shadow-sm" style="width: 38px; height: 38px; object-fit: contain; background: #f8fafc; padding: 4px; border: 1px solid #e2e8f0;">
      <div class="sidebar-hide-on-collapse">
        <div class="fw-bold fs-6 lh-1" style="color: #0f172a;"><span style="color: #059669;">Eco Divine</span></div>
        <small class="text-muted" style="font-size: 0.72rem; letter-spacing: 0.8px; font-weight: 600;">ISLAND RESORT ADMIN</small>
      </div>
    </a>
    <button type="button" class="btn-close d-lg-none" id="sidebarCloseBtn" aria-label="Close sidebar"></button>
  </div>

  <!-- Staff Mini Profile Box -->
  <div class="p-3 border-bottom sidebar-hide-on-collapse" style="border-color: #e2e8f0 !important; background: #fafbfc;">
    <a href="dashboard.php#view-settings" onclick="activateView('view-settings', event)" class="d-flex align-items-center justify-content-between p-2 rounded-3 text-decoration-none profile-sidebar-box" style="background: #ffffff; border: 1px solid #e2e8f0; box-shadow: 0 2px 6px rgba(0,0,0,0.02); transition: all 0.2s ease;" title="Click to manage profile and account settings">
      <div class="d-flex align-items-center gap-3" style="min-width: 0;">
        <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-sm" style="width: 38px; height: 38px; background: linear-gradient(135deg, #059669 0%, #10b981 100%); font-size: 0.92rem; flex-shrink: 0;">
          <?= strtoupper(substr($admin_name, 0, 1)) ?>
        </div>
        <div style="min-width: 0;">
          <div class="fw-bold text-truncate small" style="color: #0f172a;" title="<?= htmlspecialchars($admin_name) ?>">
            <?= htmlspecialchars($admin_name) ?>
          </div>
          <div class="text-success small d-flex align-items-center gap-1 fw-medium" style="font-size: 0.72rem;">
            <span class="d-inline-block rounded-circle bg-success" style="width: 6px; height: 6px; box-shadow: 0 0 6px #10b981;"></span> 
            Authorized Staff
          </div>
        </div>
      </div>
      <i class="fa-solid fa-gear text-muted small ms-2" style="font-size: 0.85rem;" title="Account Settings"></i>
    </a>
  </div>

  <!-- Sidebar Menu Navigation List -->
  <div class="p-3 flex-grow-1 overflow-y-auto" style="background: #ffffff;">
    
    <!-- Group 1: Core Operations -->
    <div class="sidebar-nav-title text-uppercase text-muted fw-bold mb-2 ps-2" style="font-size: 0.7rem; letter-spacing: 1.2px;">
      Core Operations
    </div>
    <ul class="nav flex-column mb-3">
      
      <!-- 1. Dashboard -->
      <li class="nav-item mb-1">
        <a class="sidebar-link-btn active" href="dashboard.php#view-dashboard" data-view="view-dashboard" onclick="activateView('view-dashboard', event)">
          <i class="fa-solid fa-gauge-high"></i>
          <span class="sidebar-hide-on-collapse">Dashboard</span>
        </a>
      </li>

      <!-- 2. Manage Cottages -->
      <li class="nav-item mb-1">
        <a class="sidebar-link-btn" href="dashboard.php#view-cottages" data-view="view-cottages" onclick="activateView('view-cottages', event)">
          <i class="fa-solid fa-house-chimney"></i>
          <span class="sidebar-hide-on-collapse">Manage Cottages</span>
        </a>
      </li>

      <!-- 3. Bookings -->
      <li class="nav-item mb-1">
        <a class="sidebar-link-btn" href="dashboard.php#view-bookings" data-view="view-bookings" onclick="activateView('view-bookings', event)">
          <i class="fa-solid fa-calendar-check"></i>
          <span class="sidebar-hide-on-collapse">Bookings</span>
          <?php if ($pending_count > 0): ?>
            <span class="badge bg-warning text-dark ms-auto sidebar-hide-on-collapse fw-bold" title="<?= $pending_count ?> Pending Bookings">
              <?= $pending_count ?>
            </span>
          <?php endif; ?>
        </a>
      </li>


      <!-- 5. Customers Directory -->
      <li class="nav-item mb-1">
        <a class="sidebar-link-btn" href="dashboard.php#view-customers" data-view="view-customers" onclick="activateView('view-customers', event)">
          <i class="fa-solid fa-users"></i>
          <span class="sidebar-hide-on-collapse">Customers Directory</span>
        </a>
      </li>
    </ul>

    <!-- Group 2: Finances & Feedback -->
    <div class="sidebar-nav-title text-uppercase text-muted fw-bold mb-2 ps-2" style="font-size: 0.7rem; letter-spacing: 1.2px;">
      Finance & Inquiries
    </div>
    <ul class="nav flex-column mb-3">
      
      <!-- 6. Earnings / Payments -->
      <li class="nav-item mb-1">
        <a class="sidebar-link-btn" href="dashboard.php#view-earnings" data-view="view-earnings" onclick="activateView('view-earnings', event)">
          <i class="fa-solid fa-wallet"></i>
          <span class="sidebar-hide-on-collapse">Payments & Earnings</span>
        </a>
      </li>

      <!-- 7. Complains / Messages -->
      <li class="nav-item mb-1">
        <a class="sidebar-link-btn" href="dashboard.php#view-complaints" data-view="view-complaints" onclick="activateView('view-complaints', event)">
          <i class="fa-solid fa-comments"></i>
          <span class="sidebar-hide-on-collapse">Guest Inquiries</span>
          <?php if ($unread_complaints > 0): ?>
            <span class="badge bg-danger ms-auto sidebar-hide-on-collapse fw-bold" title="<?= $unread_complaints ?> Unread Inquiries">
              <?= $unread_complaints ?>
            </span>
          <?php endif; ?>
        </a>
      </li>
    </ul>

    <!-- Group 3: Administration -->
    <div class="sidebar-nav-title text-uppercase text-muted fw-bold mb-2 ps-2" style="font-size: 0.7rem; letter-spacing: 1.2px;">
      Administration
    </div>
    <ul class="nav flex-column mb-3">
      <!-- 8. Staff & Admins -->
      <li class="nav-item mb-1">
        <a class="sidebar-link-btn" href="dashboard.php#view-admins" data-view="view-admins" onclick="activateView('view-admins', event)">
          <i class="fa-solid fa-user-shield"></i>
          <span class="sidebar-hide-on-collapse">Staff & Admins</span>
        </a>
      </li>

      <!-- 9. Settings & Integrations -->
      <li class="nav-item mb-1">
        <a class="sidebar-link-btn" href="dashboard.php#view-settings" data-view="view-settings" onclick="activateView('view-settings', event)">
          <i class="fa-solid fa-sliders"></i>
          <span class="sidebar-hide-on-collapse">Settings &amp; Integrations</span>
        </a>
      </li>
    </ul>

  </div>

  <!-- Sidebar Footer: Quick Sign Out -->
  <div class="p-3 border-top sidebar-hide-on-collapse" style="border-color: #e2e8f0 !important; background: #fafbfc;">
    <a href="logout.php" class="btn btn-sm btn-outline-danger w-100 rounded-pill d-flex align-items-center justify-content-center gap-2 py-2">
      <i class="fa-solid fa-arrow-right-from-bracket"></i>
      <span>Sign Out</span>
    </a>
  </div>

</aside>

<style>
/* Sidebar Link Styling */
.sidebar-link-btn {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 14px;
  border-radius: 12px;
  color: #475569;
  text-decoration: none;
  font-size: 0.88rem;
  font-weight: 500;
  transition: all 0.2s ease;
  border: 1px solid transparent;
}
.sidebar-link-btn i {
  font-size: 1rem;
  width: 22px;
  text-align: center;
  color: #64748b;
  transition: color 0.2s ease;
}
.sidebar-link-btn:hover {
  background-color: #f1f5f9;
  color: #0f172a;
}
.sidebar-link-btn:hover i {
  color: #059669;
}
.sidebar-link-btn.active {
  background-color: #ecfdf5;
  color: #059669;
  font-weight: 600;
  border-color: #a7f3d0;
}
.sidebar-link-btn.active i {
  color: #059669;
}
.profile-sidebar-box:hover {
  border-color: #10b981 !important;
  background-color: #f0fdf4 !important;
  transform: translateY(-1px);
}
.profile-sidebar-box:hover .fa-gear {
  color: #059669 !important;
  transform: rotate(45deg);
  transition: transform 0.3s ease;
}
</style>
