<!-- =================== LUXURY RESORT FOOTER =================== -->
<footer class="resort-footer">
  <!-- Top Decorative Wave -->
  <div class="footer-wave-shape">
    <svg viewBox="0 0 1440 60" fill="none" preserveAspectRatio="none">
      <path d="M0,25 C360,55 720,0 1080,35 C1260,50 1380,25 1440,25 L1440,0 L0,0 Z" fill="#faf7f2"></path>
    </svg>
  </div>

  <div class="container footer-main-content">
    <div class="row g-4 g-lg-5">
      <!-- Col 1: Brand & Heritage -->
      <div class="col-lg-4 col-md-6">
        <div class="footer-brand mb-3">
          <a href="index.php" class="d-inline-flex align-items-center gap-3 text-decoration-none">
            <img src="favicon.png" alt="Eco Divine Logo" class="footer-logo-img">
            <div>
              <span class="footer-brand-title"><span class="highlight">Eco Divine</span> Beach Resort</span>
              <span class="footer-brand-tagline">Saint Martin Island, Bangladesh</span>
            </div>
          </a>
        </div>
        <p class="footer-about-text">
          Saint Martin's premier eco-friendly beachfront retreat. Experience handcrafted wooden sea-view cottages, fresh ocean dining, swaying coconut groves, and unmatched island tranquility.
        </p>

        <!-- Resort Social Links -->
        <div class="footer-social-links mt-4">
          <a href="https://facebook.com/profile.php?id=100050663805467" target="_blank" class="footer-social-btn" aria-label="Facebook">
            <i class="fa-brands fa-facebook-f"></i>
          </a>
          <a href="https://wa.me/01333838389" target="_blank" class="footer-social-btn whatsapp-btn" aria-label="WhatsApp">
            <i class="fa-brands fa-whatsapp"></i>
          </a>
          <a href="tel:+8801817838383" class="footer-social-btn phone-btn" aria-label="Call Reception">
            <i class="fa-solid fa-phone"></i>
          </a>
          <a href="mailto:ecodivinebeachresortsaintmarti@gmail.com" class="footer-social-btn mail-btn" aria-label="Email Us">
            <i class="fa-solid fa-envelope"></i>
          </a>
        </div>
      </div>

      <!-- Col 2: Quick Links -->
      <div class="col-lg-2 col-md-6 col-6">
        <h5 class="footer-heading">Quick Links</h5>
        <ul class="footer-links-list">
          <li><a href="index.php#home"><i class="fa-solid fa-chevron-right link-arrow"></i> Home</a></li>
          <li><a href="index.php#cottages"><i class="fa-solid fa-chevron-right link-arrow"></i> Our Cottages</a></li>
          <li><a href="packages.php"><i class="fa-solid fa-chevron-right link-arrow"></i> Packages & Booking</a></li>
          <li><a href="resturant.php"><i class="fa-solid fa-chevron-right link-arrow"></i> Restaurant Menu</a></li>
          <li><a href="index.php#gallery"><i class="fa-solid fa-chevron-right link-arrow"></i> Photo Gallery</a></li>
          <li><a href="index.php#video-gallery"><i class="fa-solid fa-chevron-right link-arrow"></i> Video Tour</a></li>
          <li><a href="index.php#contact"><i class="fa-solid fa-chevron-right link-arrow"></i> Contact Us</a></li>
          <li><a href="admin/login.php"><i class="fa-solid fa-lock link-arrow"></i> Staff Login</a></li>
        </ul>
      </div>

      <!-- Col 3: Accommodations -->
      <div class="col-lg-3 col-md-6 col-6">
        <h5 class="footer-heading">Cottages & Dining</h5>
        <ul class="footer-links-list">
          <li><a href="packages.php?type=sea"><i class="fa-solid fa-water link-arrow"></i> Sea View Cottages</a></li>
          <li><a href="packages.php?type=garden"><i class="fa-solid fa-tree link-arrow"></i> Garden / Non-Sea View</a></li>
          <li><a href="packages.php?type=couple"><i class="fa-solid fa-user-group link-arrow"></i> Couple Suites (2 Guests)</a></li>
          <li><a href="packages.php?type=family"><i class="fa-solid fa-users link-arrow"></i> Family Suites (4 Guests)</a></li>
          <li><a href="resturant.php#bbq"><i class="fa-solid fa-fire-burner link-arrow"></i> Fresh Seafood BBQ</a></li>
          <li><a href="packages.php"><i class="fa-solid fa-gift link-arrow"></i> Reserve Online</a></li>
        </ul>
      </div>

      <!-- Col 4: Island Desk & Address -->
      <div class="col-lg-3 col-md-6">
        <h5 class="footer-heading">Island Front Desk</h5>
        <ul class="footer-contact-list">
          <li class="footer-contact-item">
            <i class="fa-solid fa-location-dot contact-item-icon"></i>
            <div>
              <strong>Resort Location:</strong>
              <p class="mb-0 text-white-50">দারুচিনি বীচ/উত্তর বীচ, নারিকেল বাগান রোড, সেন্টমার্টিন দ্বীপ।</p>
            </div>
          </li>
          <li class="footer-contact-item">
            <i class="fa-solid fa-phone-volume contact-item-icon"></i>
            <div>
              <strong>Reservation Hotline:</strong>
              <p class="mb-0"><a href="tel:+8801817838383" class="contact-link">+880 1817-838383</a></p>
            </div>
          </li>
          <li class="footer-contact-item">
            <i class="fa-brands fa-whatsapp contact-item-icon"></i>
            <div>
              <strong>WhatsApp Support:</strong>
              <p class="mb-0"><a href="https://wa.me/01333838389" target="_blank" class="contact-link">+880 1333-838389</a></p>
            </div>
          </li>
          <li class="footer-contact-item">
            <i class="fa-regular fa-clock contact-item-icon"></i>
            <div>
              <strong>Service Availability:</strong>
              <p class="mb-0 text-white-50">24/7 Island Reception & Assistance</p>
            </div>
          </li>
        </ul>
      </div>
    </div>

    <!-- Bottom Copyright & Developer Credit Bar -->
    <div class="footer-bottom-bar mt-5 pt-4">
      <div class="row align-items-center">
        <div class="col-md-6 text-center text-md-start mb-3 mb-md-0">
          <p class="mb-0 text-white-50 small">
            &copy; <?= date('Y') ?> <strong class="text-white">Eco Divine Beach Resort</strong>. All Rights Reserved.
          </p>
        </div>
        <div class="col-md-6 text-center text-md-end">
          <p class="mb-0 text-white-50 small">
            Saint Martin Island, Bangladesh &bull; Developed by <a href="https://nurhossan.netlify.app/" target="_blank" class="developer-link">Nur Hossan</a>
          </p>
        </div>
      </div>
    </div>
  </div>

  <!-- Floating Back to Top Button -->
  <button id="backToTopBtn" class="back-to-top-btn" aria-label="Back to Top" title="Scroll to Top">
    <i class="fa-solid fa-arrow-up"></i>
  </button>
</footer>

<!-- JS Links -->
<script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<!-- flatpickr JS -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
  if (typeof AOS !== 'undefined') {
    AOS.init();
  }

  // Back to Top Button Handler
  const backToTopBtn = document.getElementById('backToTopBtn');
  if (backToTopBtn) {
    window.addEventListener('scroll', function() {
      if (window.pageYOffset > 350) {
        backToTopBtn.classList.add('show');
      } else {
        backToTopBtn.classList.remove('show');
      }
    });

    backToTopBtn.addEventListener('click', function() {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }
</script>
