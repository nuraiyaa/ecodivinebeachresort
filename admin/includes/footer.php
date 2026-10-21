<?php
// admin/includes/footer.php
?>
    </main> <!-- /.admin-page-container -->

    <!-- Responsive Admin Footer Bar -->
    <footer class="admin-footer-bar text-muted d-flex align-items-center justify-content-between flex-wrap gap-2 border-top py-3 px-4 bg-white" style="font-size: 0.82rem; border-color: #e2e8f0 !important;">
      <div>
        &copy; <?= date('Y') ?> <strong class="text-dark">Eco Divine Beach Resort</strong>. All Rights Reserved.
      </div>
      <div class="d-flex align-items-center gap-3">
        <span class="d-flex align-items-center gap-1 text-success fw-medium">
          <i class="fa-solid fa-lock"></i> SSL Secured Engine
        </span>
        <span class="text-muted d-none d-sm-inline">&bull;</span>
        <span class="text-muted d-none d-sm-inline">v3.2 Island Admin</span>
      </div>
    </footer>

  </div> <!-- /.admin-main-wrap -->

  <!-- Bootstrap 5.3 Bundle JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <!-- Flatpickr Custom Admin Datepicker JS -->
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

  <!-- Sidebar & Navigation JavaScript -->
  <script>
    // 1. Sidebar Toggle Mechanics (Desktop Collapse & Mobile Off-canvas)
    const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
    const sidebarCloseBtn = document.getElementById('sidebarCloseBtn');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');

    if (sidebarToggleBtn) {
      sidebarToggleBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (window.innerWidth < 992) {
          document.body.classList.toggle('sidebar-open');
        } else {
          document.body.classList.toggle('sidebar-collapsed');
        }
      });
    }

    if (sidebarCloseBtn) {
      sidebarCloseBtn.addEventListener('click', function() {
        document.body.classList.remove('sidebar-open');
      });
    }

    if (sidebarBackdrop) {
      sidebarBackdrop.addEventListener('click', function() {
        document.body.classList.remove('sidebar-open');
      });
    }

    // 2. Single-Page View Switcher Engine
    function activateView(viewId, event) {
      if (event) {
        event.preventDefault();
      }

      // Hide all view panes
      const panes = document.querySelectorAll('.dashboard-view-pane');
      panes.forEach(pane => pane.classList.remove('active'));

      // Show target view pane
      const targetPane = document.getElementById(viewId);
      if (targetPane) {
        targetPane.classList.add('active');
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }

      // Update active state in sidebar links
      const links = document.querySelectorAll('.sidebar-link-btn');
      links.forEach(link => {
        if (link.getAttribute('data-view') === viewId) {
          link.classList.add('active');
        } else {
          link.classList.remove('active');
        }
      });

      // Update URL hash without reload
      if (history.pushState) {
        history.pushState(null, null, '#' + viewId);
      } else {
        location.hash = '#' + viewId;
      }

      // Automatically close sidebar drawer on mobile after clicking
      if (window.innerWidth < 992) {
        document.body.classList.remove('sidebar-open');
      }
    }

    // 3. Handle Initial View Load from URL Hash (e.g. #view-bookings)
    window.addEventListener('DOMContentLoaded', function() {
      const hash = window.location.hash ? window.location.hash.substring(1) : '';
      if (hash && document.getElementById(hash)) {
        activateView(hash);
      } else {
        activateView('view-dashboard');
      }
    });

    // 4. Handle Browser Forward/Back Navigation
    window.addEventListener('hashchange', function() {
      const hash = window.location.hash ? window.location.hash.substring(1) : '';
      if (hash && document.getElementById(hash)) {
        activateView(hash);
      }
    });
  </script>

</body>
</html>
