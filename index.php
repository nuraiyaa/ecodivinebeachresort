<?php include('header.php'); ?>
<!-- =================== HERO SECTION WITH TYPING =================== -->
<section id="home" class="hero-section" style="background-image: url('assets/img/gallery/package-background.jpg');">
  <div class="overlay">
    <h1>
      <span class="static-text">WELCOME TO </span>
      <span id="typed-text" class="typed-text"></span>
    </h1>
  </div>
</section>

<!-- Typed.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/typed.js@2.0.12"></script>

<script>
  var typed = new Typed('#typed-text', {
    strings: ['ECO DIVINE BEACH RESORT – SAINTMARTIN'],
    typeSpeed: 80,
    backSpeed: 50,
    backDelay: 2000,
    loop: true,
    showCursor: true,
    cursorChar: '|',
  });
</script>

<!-- =================== CHECK-IN / CHECK-OUT AVAILABILITY SEARCH BAR =================== -->
<section class="booking-search-wrapper" id="availability-search">
  <div class="container">
    <div class="booking-search-card" data-aos="fade-up">
      <div class="booking-card-top-tag">
        <span><i class="fa-solid fa-umbrella-beach text-success me-1"></i> Check Available Cottages</span>
        <span class="booking-guarantee-pill"><i class="fa-solid fa-shield-halved text-success me-1"></i> Best Rate Guaranteed</span>
      </div>

      <form id="heroBookingForm" action="packages.php" method="GET" class="booking-search-form">
        <div class="booking-fields-row">
          <!-- Check-In Field -->
          <div class="booking-field-cell">
            <label for="homeCheckIn" class="booking-field-label">
              <i class="fa-regular fa-calendar-check text-success"></i> CHECK-IN DATE
            </label>
            <div class="booking-input-shell">
              <input type="text" id="homeCheckIn" name="check_in" class="booking-date-control" placeholder="Select Arrival Date" required>
              <i class="fa-solid fa-calendar-days booking-input-icon text-success"></i>
            </div>
            <small class="booking-hint-text" id="homeCheckInHint">Choose arrival date</small>
          </div>

          <div class="booking-divider-line d-none d-lg-block"></div>

          <!-- Check-Out Field -->
          <div class="booking-field-cell">
            <label for="homeCheckOut" class="booking-field-label">
              <i class="fa-regular fa-calendar-xmark text-danger"></i> CHECK-OUT DATE
            </label>
            <div class="booking-input-shell">
              <input type="text" id="homeCheckOut" name="check_out" class="booking-date-control" placeholder="Select Departure Date" required>
              <i class="fa-solid fa-calendar-days booking-input-icon text-danger"></i>
            </div>
            <small class="booking-hint-text" id="homeCheckOutHint">Choose departure date</small>
          </div>

          <div class="booking-divider-line d-none d-lg-block"></div>

          <!-- Guests / Room Type Preference -->
          <div class="booking-field-cell">
            <label for="homeGuests" class="booking-field-label">
              <i class="fa-solid fa-user-group text-primary"></i> GUESTS & VIEW
            </label>
            <div class="booking-input-shell">
              <select id="homeGuests" name="type" class="booking-select-control">
                <option value="all">All Cottages (Any)</option>
                <option value="sea">Sea View Cottages (4)</option>
                <option value="side-sea">Side Sea View (2)</option>
                <option value="garden">Non-Sea View (6)</option>
                <option value="couple">2 Guests (Couple Suite)</option>
                <option value="family">4 Guests (Family Cottage)</option>
              </select>
            </div>
            <small class="booking-hint-text">Filter preference</small>
          </div>

          <!-- Submit Button -->
          <div class="booking-action-cell">
            <button type="submit" class="booking-submit-action-btn" id="homeSearchBtn">
              <i class="fa-solid fa-magnifying-glass me-2"></i>
              <span>Check Availability</span>
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</section>

<!-- =================== REDESIGNED COTTAGE SECTION =================== -->
<section id="cottages" class="resort-section">
  <div class="container">
    <!-- Section Header & Filter Controls -->
    <div class="cottages-header-wrapper text-center mb-5" data-aos="fade-up">
      <div class="section-tag-pill">
        <i class="fa-solid fa-umbrella-beach"></i> Luxury Beach Accommodation
      </div>
      <h2 class="section-main-title">🌿 Our Beautiful Cottages</h2>
      <p class="section-main-subtitle">
        Experience serene island living right on Saint Martin's coast. Choose from our private panoramic sea-facing suites, side sea view rooms, and spacious family non-sea cottages.
      </p>

      <!-- Category Filter Pills -->
      <div class="cottage-filter-nav d-flex justify-content-center flex-wrap gap-2 mt-4">
        <button type="button" class="filter-btn active" data-filter="all">
          <i class="fa-solid fa-grip"></i> All Cottages (12)
        </button>
        <button type="button" class="filter-btn" data-filter="sea">
          <i class="fa-solid fa-water"></i> Sea View (4)
        </button>
        <button type="button" class="filter-btn" data-filter="side-sea">
          <i class="fa-solid fa-water-ladder"></i> Side Sea View (2)
        </button>
        <button type="button" class="filter-btn" data-filter="garden">
          <i class="fa-solid fa-tree"></i> Non-Sea View (6)
        </button>
        <button type="button" class="filter-btn" data-filter="couple">
          <i class="fa-solid fa-user-group"></i> 2 Guests (Couple)
        </button>
        <button type="button" class="filter-btn" data-filter="family">
          <i class="fa-solid fa-users"></i> 4 Guests (Family)
        </button>
      </div>
    </div>

    <!-- Cottages Grid -->
    <div class="row g-4" id="cottageGrid">
      <?php
        require_once __DIR__ . '/admin/includes/db.php';
        $dbCottageMap = [];
        if (isset($conn) && $conn instanceof mysqli) {
          $dbCQuery = $conn->query("SELECT id, price, weekend_price, is_weekend, status FROM cottages");
          if ($dbCQuery) {
            while ($dRow = $dbCQuery->fetch_assoc()) {
              $dbCottageMap[$dRow['id']] = $dRow;
            }
          }
        }

        $cottages = [
          [
            "id" => 1,
            "name" => "অরুনিকা",
            "img" => "Orunika.jpg",
            "weekday" => 6000,
            "weekend" => 7000,
            "guests" => 2,
            "view" => "sea",
            "view_label" => "Sea View",
            "tag" => "Premium Suite",
            "bath" => "Attached Bath",
            "amenity3" => "Balcony"
          ],
          [
            "id" => 2,
            "name" => "তিলোত্তমা",
            "img" => "tilottoma.jpg",
            "weekday" => 4500,
            "weekend" => 5500,
            "guests" => 2,
            "view" => "sea",
            "view_label" => "Sea View",
            "tag" => "Popular Choice",
            "bath" => "Attached Bath",
            "amenity3" => "Balcony"
          ],
          [
            "id" => 3,
            "name" => "ছায়াবীথি",
            "img" => "chayabithi.jpg",
            "weekday" => 4500,
            "weekend" => 5500,
            "guests" => 2,
            "view" => "sea",
            "view_label" => "Sea View",
            "tag" => "Sea View",
            "bath" => "Attached Bath",
            "amenity3" => "Balcony"
          ],
          [
            "id" => 4,
            "name" => "নয়নতারা",
            "img" => "noyontara.jpg",
            "weekday" => 4500,
            "weekend" => 5500,
            "guests" => 2,
            "view" => "sea",
            "view_label" => "Sea View",
            "tag" => "Sea View",
            "bath" => "Attached Bath",
            "amenity3" => "Balcony"
          ],
          [
            "id" => 5,
            "name" => "জলসা",
            "img" => "jolsha.jpg",
            "weekday" => 5500,
            "weekend" => 6000,
            "guests" => 4,
            "view" => "side-sea",
            "view_label" => "Side Sea View",
            "tag" => "Side Sea View",
            "bath" => "Attached Bath",
            "amenity3" => "Spacious"
          ],
          [
            "id" => 6,
            "name" => "রুপকথা",
            "img" => "rupkotha.jpg",
            "weekday" => 4000,
            "weekend" => 4500,
            "guests" => 2,
            "view" => "side-sea",
            "view_label" => "Side Sea View",
            "tag" => "Side Sea View",
            "bath" => "Non-Attached Bath",
            "amenity3" => "Balcony"
          ],
          [
            "id" => 7,
            "name" => "কোরাল-০১",
            "img" => "coral.jpg",
            "weekday" => 3500,
            "weekend" => 4000,
            "guests" => 4,
            "view" => "garden",
            "view_label" => "Non-Sea View",
            "tag" => "Garden Villa",
            "bath" => "Attached Bath",
            "amenity3" => "4 Person"
          ],
          [
            "id" => 8,
            "name" => "কোরাল-০২",
            "img" => "coral.jpg",
            "weekday" => 3500,
            "weekend" => 4000,
            "guests" => 4,
            "view" => "garden",
            "view_label" => "Non-Sea View",
            "tag" => "Garden Villa",
            "bath" => "Attached Bath",
            "amenity3" => "4 Person"
          ],
          [
            "id" => 9,
            "name" => "লবস্টার-০৩",
            "img" => "lobstar.jpg",
            "weekday" => 3500,
            "weekend" => 4000,
            "guests" => 2,
            "view" => "garden",
            "view_label" => "Non-Sea View",
            "tag" => "Non-Sea View",
            "bath" => "Attached Bath",
            "amenity3" => "Balcony"
          ],
          [
            "id" => 10,
            "name" => "লবস্টার-০৪",
            "img" => "coral.jpg",
            "weekday" => 3500,
            "weekend" => 4000,
            "guests" => 4,
            "view" => "garden",
            "view_label" => "Non-Sea View",
            "tag" => "Family Cottage",
            "bath" => "Attached Bath",
            "amenity3" => "4 Person"
          ],
          [
            "id" => 11,
            "name" => "রূপচাঁদা-০৫",
            "img" => "coral.jpg",
            "weekday" => 3500,
            "weekend" => 4000,
            "guests" => 4,
            "view" => "garden",
            "view_label" => "Non-Sea View",
            "tag" => "Family Cottage",
            "bath" => "Attached Bath",
            "amenity3" => "4 Person"
          ],
          [
            "id" => 12,
            "name" => "রূপচাঁদা-০৬",
            "img" => "coral.jpg",
            "weekday" => 3500,
            "weekend" => 4000,
            "guests" => 4,
            "view" => "garden",
            "view_label" => "Non-Sea View",
            "tag" => "Family Cottage",
            "bath" => "Attached Bath",
            "amenity3" => "4 Person"
          ],
        ];

        $delay = 50;
        foreach ($cottages as $cottage) {
          if (isset($dbCottageMap[$cottage['id']])) {
            $cottage['weekday'] = (int)$dbCottageMap[$cottage['id']]['price'];
            $cottage['weekend'] = (int)$dbCottageMap[$cottage['id']]['weekend_price'];
            $cottage['is_weekend'] = (function_exists('isDateWeekend') ? isDateWeekend(date('Y-m-d')) : in_array((int)date('N'), [5, 6])) || ((int)$dbCottageMap[$cottage['id']]['is_weekend'] === 1);
            $cottage['status'] = $dbCottageMap[$cottage['id']]['status'];
          }

          $isSea = ($cottage['view'] === 'sea');
          $isSideSea = ($cottage['view'] === 'side-sea');
          $viewClass = $isSea ? 'sea-view' : ($isSideSea ? 'side-sea-view' : 'garden-view');
          $viewIcon = $isSea ? '<i class="fa-solid fa-water me-1"></i>' : ($isSideSea ? '<i class="fa-solid fa-water-ladder me-1"></i>' : '<i class="fa-solid fa-tree me-1"></i>');
          $guestCat = ($cottage['guests'] == 2) ? 'couple' : 'family';
          $categories = "{$cottage['view']} {$guestCat}";
      ?>
        <div class="col-12 col-sm-6 col-lg-4 col-xl-3 cottage-item" data-category="<?= $categories ?>" data-aos="fade-up" data-aos-delay="<?= $delay ?>">
          <div class="resort-card">
            <!-- Image with Floating Badges -->
            <div class="cottage-image-wrapper">
              <img src="assets/img/<?= htmlspecialchars($cottage['img']) ?>" alt="<?= htmlspecialchars($cottage['name']) ?>" loading="lazy">
              <div class="card-floating-badges">
                <span class="cottage-view-badge <?= $viewClass ?>">
                  <?= $viewIcon ?><?= htmlspecialchars($cottage['view_label']) ?>
                </span>
                <span class="cottage-guest-badge">
                  <i class="fa-solid fa-user-group"></i> <?= $cottage['guests'] ?> Guests
                </span>
              </div>
            </div>

            <!-- Card Content Body -->
            <div class="cottage-body">
              <div class="cottage-header-row">
                <a href="details.php?id=<?= $cottage['id'] ?>&name=<?= urlencode($cottage['name']) ?>" class="text-decoration-none text-dark" onclick="appendSearchDates(this, event)">
                  <h3 class="cottage-title"><?= htmlspecialchars($cottage['name']) ?></h3>
                </a>
                <span class="cottage-tag-mini"><?= htmlspecialchars($cottage['tag']) ?></span>
              </div>

              <!-- Key Amenities -->
              <div class="cottage-amenities">
                <span class="amenity-chip"><i class="fa-solid fa-shower"></i> <?= htmlspecialchars($cottage['bath']) ?></span>
                <span class="amenity-chip"><i class="fa-solid fa-bolt"></i> 24h Power</span>
                <span class="amenity-chip"><i class="fa-solid fa-wind"></i> <?= htmlspecialchars($cottage['amenity3']) ?></span>
              </div>

              <!-- Pricing Info Box -->
              <div class="cottage-pricing-box" <?= (!empty($cottage['is_weekend']) ? 'style="border-left: 3px solid #f59e0b; padding-left: 10px;"' : '') ?>>
                <?php if (!empty($cottage['is_weekend'])): ?>
                  <div class="badge bg-warning text-dark mb-1" style="font-size:0.7rem;"><i class="fa-solid fa-fire me-1"></i> Weekend Rate Active</div>
                  <div class="pricing-row">
                    <span class="price-label">Weekend Rate:</span>
                    <span class="price-value highlight-rate" style="color: #d97706;">৳<?= number_format($cottage['weekend']) ?> <small class="text-muted fw-normal">/ night</small></span>
                  </div>
                  <div class="pricing-row">
                    <span class="price-label">Regular Rate:</span>
                    <span class="price-value text-muted">৳<?= number_format($cottage['weekday']) ?> <small class="text-muted fw-normal">/ night</small></span>
                  </div>
                <?php else: ?>
                  <div class="pricing-row">
                    <span class="price-label">Weekdays:</span>
                    <span class="price-value highlight-rate">৳<?= number_format($cottage['weekday']) ?> <small class="text-muted fw-normal">/ night</small></span>
                  </div>
                  <div class="pricing-row">
                    <span class="price-label">Weekend:</span>
                    <span class="price-value">৳<?= number_format($cottage['weekend']) ?> <small class="text-muted fw-normal">/ night</small></span>
                  </div>
                <?php endif; ?>
              </div>

              <!-- Reserve Action Button -->
              <div class="cottage-card-actions">
                <?php if (isset($cottage['status']) && $cottage['status'] === 'inactive'): ?>
                  <button class="btn btn-secondary w-100 py-2 rounded-3 text-white fw-medium" disabled style="font-size:0.88rem;">
                    <i class="fa-solid fa-wrench me-1"></i> Under Maintenance
                  </button>
                <?php else: ?>
                  <a href="details.php?id=<?= $cottage['id'] ?>&name=<?= urlencode($cottage['name']) ?>" class="btn-reserve-now" onclick="appendSearchDates(this, event)">
                    <i class="fa-regular fa-calendar-check"></i> Reserve Now
                  </a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php
          $delay += 30;
        }
      ?>
    </div>

    <!-- Bottom Help & Assistance Banner -->
    <div class="cottage-bottom-banner" data-aos="fade-up">
      <div class="banner-text">
        <h4><i class="fa-solid fa-phone-volume text-success me-2"></i> Need help choosing the best cottage?</h4>
        <p>Our Saint Martin reservation team is ready 24/7 to assist with room selection and group bookings.</p>
      </div>
      <div class="banner-actions">
        <a href="tel:01817838383" class="banner-call-btn">
          <i class="fa-solid fa-phone"></i> Call: 01817838383
        </a>
        <a href="https://wa.me/01333838389" target="_blank" class="banner-whatsapp-btn">
          <i class="fa-brands fa-whatsapp"></i> Chat on WhatsApp
        </a>
      </div>
    </div>
  </div>
</section>

<!-- =================== COTTAGE FILTER JS =================== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  const filterBtns = document.querySelectorAll('.filter-btn');
  const cottageItems = document.querySelectorAll('.cottage-item');

  filterBtns.forEach(btn => {
    btn.addEventListener('click', function() {
      // Toggle active filter button
      filterBtns.forEach(b => b.classList.remove('active'));
      this.classList.add('active');

      const filterValue = this.getAttribute('data-filter');

      cottageItems.forEach(item => {
        const categories = item.getAttribute('data-category').split(' ');

        if (filterValue === 'all' || categories.includes(filterValue)) {
          item.style.display = '';
          // Trigger slight animation
          item.style.opacity = '0';
          item.style.transform = 'translateY(15px)';
          setTimeout(() => {
            item.style.transition = 'all 0.3s ease';
            item.style.opacity = '1';
            item.style.transform = 'translateY(0)';
          }, 20);
        } else {
          item.style.display = 'none';
        }
      });
    });
  });
});
</script>

<!-- =================== MOVING CURVED GALLERY SECTION =================== -->
<section id="gallery" class="gallery-section">
  <div class="container-fluid px-0">
    <!-- Header with decorative badge and doodle -->
    <div class="gallery-doodle-wrapper" data-aos="fade-up">
      <div class="gallery-badge">
        <i class="fa-solid fa-camera"></i> Resort Visual Experience
      </div>
      <h2 class="gallery-heading">Captivate Your Senses with Saint Martin's Magic</h2>
      <p class="gallery-subheading">
        Explore pristine turquoise shores, swaying coconut palms, and peaceful cottage moments captured across our beachfront resort.
      </p>

      <!-- Decorative doodle arrow -->
      <div class="gallery-doodle-right">
        <span>Breathtaking views 🌴</span>
        <svg viewBox="0 0 100 80" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
          <path d="M20,20 Q60,5 75,55" />
          <path d="M60,50 L75,55 L78,40" />
        </svg>
      </div>
    </div>

    <!-- Moving Curved Carousel (Swiper) -->
    <div class="resort-gallery-container">
      <div class="swiper resortGallerySwiper">
        <div class="swiper-wrapper">
          <?php
            $galleryItems = [
              ["img" => "gallery1.jpg", "caption" => "Beachfront Shoreline"],
              ["img" => "gallery2.jpg", "caption" => "Emerald Ocean Waters"],
              ["img" => "gallery3.jpg", "caption" => "Golden Island Sunset"],
              ["img" => "gallery4.jpg", "caption" => "Resort Coconut Palms"],
              ["img" => "gallery5.jpg", "caption" => "Tranquil Coral Beach"],
              ["img" => "gallery6.jpg", "caption" => "Tropical Morning Glow"],
              ["img" => "gallery7.jpg", "caption" => "Cottage Garden Pathway"],
              ["img" => "gallery8.jpg", "caption" => "Refreshing Sea Breeze"],
              ["img" => "gallery9.jpg", "caption" => "Saint Martin Coast"],
              ["img" => "gallery10.jpg", "caption" => "Cozy Evening Ambience"],
              ["img" => "gallery11.jpg", "caption" => "Azure Blue Waves"],
              ["img" => "gallery12.jpg", "caption" => "Memorable Vacation"],
            ];

            foreach($galleryItems as $item){
              echo "
              <div class='swiper-slide'>
                <div class='gallery-card' data-full='assets/img/gallery/{$item['img']}' data-caption='{$item['caption']}' title='{$item['caption']}'>
                  <img src='assets/img/gallery/{$item['img']}' alt='{$item['caption']}' loading='lazy'>
                </div>
              </div>";
            }
          ?>
        </div>
      </div>
    </div>

    <!-- Bottom Actions Bar (Matching reference design) -->
    <div class="gallery-bottom-bar" data-aos="fade-up">
      <!-- Left Pill -->
      <a href="packages.php" class="gallery-action-pill">
        <i class="fa-solid fa-arrow-up-right-from-square"></i> Visit Cottages
      </a>

      <!-- Center Action Pill with hand-drawn note -->
      <div class="gallery-center-action">
        <span class="gallery-doodle-left">
          <span>Explore stay</span>
          <svg width="35" height="25" viewBox="0 0 50 30" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <path d="M10,20 Q25,5 40,15" />
            <path d="M32,15 L40,15 L38,8" />
          </svg>
        </span>
        <a href="packages.php" class="gallery-primary-pill">
          Reserve Cottage
        </a>
      </div>

      <!-- Right Navigation Buttons -->
      <div class="gallery-nav-controls">
        <button class="gallery-nav-btn gallery-prev" aria-label="Previous slide">
          <i class="fa-solid fa-chevron-left"></i>
        </button>
        <button class="gallery-nav-btn gallery-next" aria-label="Next slide">
          <i class="fa-solid fa-chevron-right"></i>
        </button>
      </div>
    </div>
  </div>

  <!-- Lightbox Modal for Full Image Zoom -->
  <div class="gallery-lightbox-modal" id="galleryLightbox" aria-hidden="true">
    <div class="lightbox-content">
      <button class="lightbox-close-btn" id="lightboxCloseBtn" aria-label="Close Lightbox">
        <i class="fa-solid fa-xmark"></i>
      </button>
      <img src="" alt="Full view" id="lightboxImg">
      <div class="lightbox-caption" id="lightboxCaption"></div>
    </div>
  </div>
</section>

<!-- =================== MOVING CURVED GALLERY SCRIPT =================== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  function applyCylinderPerspective(swiper) {
    if (!swiper || !swiper.slides) return;
    const isMobile = window.innerWidth < 768;
    swiper.slides.forEach(slide => {
      const card = slide.querySelector('.gallery-card');
      if (!card) return;

      // slide.progress: center slide = 0, left slides < 0, right slides > 0
      const p = slide.progress;

      // Cylindrical 3D curvature:
      // Vertical Y-axis rotation: wraps the ribbon inward towards center
      const rotateYRate = isMobile ? 8 : 11;
      const rotateY = Math.max(-28, Math.min(28, p * rotateYRate));

      // Depth in 3D: outer slides recede backward along Z
      const zRate = isMobile ? 22 : 36;
      const translateZ = -Math.min(Math.pow(Math.abs(p), 1.1) * zRate, 140);

      // Gentle convex vertical arch (highest at center, smooth downward taper to sides)
      const yRate = isMobile ? 2 : 4;
      const translateY = Math.min(Math.pow(Math.abs(p), 1.2) * yRate, 20);

      // Scale: prominent center, gentle taper to sides
      const scaleRate = isMobile ? 0.04 : 0.048;
      const scale = Math.max(0.78, 1 - Math.abs(p) * scaleRate);

      // Strictly zero rotateZ: vertical lines remain 100% upright!
      card.style.transform = `translate3d(0, ${translateY}px, ${translateZ}px) rotateY(${rotateY}deg) rotateZ(0deg) scale(${scale})`;
    });
  }

  const gallerySwiper = new Swiper('.resortGallerySwiper', {
    slidesPerView: 'auto',
    spaceBetween: 16,
    centeredSlides: true,
    loop: true,
    loopedSlides: 6,
    speed: 850,
    grabCursor: true,
    watchSlidesProgress: true,
    autoplay: {
      delay: 2400,
      disableOnInteraction: false,
      pauseOnMouseEnter: true,
    },
    navigation: {
      prevEl: '.gallery-prev',
      nextEl: '.gallery-next',
    },
    on: {
      init: function() {
        applyCylinderPerspective(this);
      },
      setTranslate: function() {
        applyCylinderPerspective(this);
      },
      setTransition: function(swiper, duration) {
        swiper.slides.forEach(slide => {
          const card = slide.querySelector('.gallery-card');
          if (card) {
            card.style.transitionDuration = `${duration}ms`;
          }
        });
      },
      resize: function() {
        applyCylinderPerspective(this);
      }
    }
  });

  // Lightbox Modal Logic
  const lightbox = document.getElementById('galleryLightbox');
  const lightboxImg = document.getElementById('lightboxImg');
  const lightboxCaption = document.getElementById('lightboxCaption');
  const lightboxClose = document.getElementById('lightboxCloseBtn');

  document.querySelectorAll('.gallery-card').forEach(card => {
    card.addEventListener('click', function() {
      const fullSrc = this.getAttribute('data-full');
      const caption = this.getAttribute('data-caption');
      lightboxImg.src = fullSrc;
      lightboxCaption.textContent = caption;
      lightbox.classList.add('is-active');
      lightbox.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      if (gallerySwiper.autoplay) gallerySwiper.autoplay.stop();
    });
  });

  function closeLightbox() {
    lightbox.classList.remove('is-active');
    lightbox.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (gallerySwiper.autoplay) gallerySwiper.autoplay.start();
  }

  if (lightboxClose) lightboxClose.addEventListener('click', closeLightbox);
  if (lightbox) {
    lightbox.addEventListener('click', function(e) {
      if (e.target === lightbox) closeLightbox();
    });
  }

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && lightbox.classList.contains('is-active')) {
      closeLightbox();
    }
  });
});
</script>
<!-- =================== CINE-THEATER VIDEO GALLERY =================== -->
<section id="video-gallery" class="video-gallery-section">
  <div class="container">
    <!-- Section Header -->
    <div class="text-center video-header-wrap" data-aos="fade-up">
      <div class="video-badge">
        <i class="fa-solid fa-circle-play"></i> VIRTUAL RESORT EXPERIENCE
      </div>
      <h2 class="video-heading">Immerse in Saint Martin's Magic</h2>
      <p class="video-subheading">
        Take a cinematic journey through Eco Divine Beach Resort — explore our pristine coral shorelines, authentic wooden cottages, and tranquil island sunsets.
      </p>
    </div>

    <!-- Main Cinema Player Stage -->
    <div class="video-theater-wrapper" data-aos="fade-up" data-aos-delay="100">
      <div class="cinema-topbar">
        <div class="cinema-info">
          <span class="live-pill"><span class="live-pulse"></span> NOW PLAYING</span>
          <h3 id="cinemaTitle" class="cinema-title">Beachfront Shoreline & Turquoise Waters</h3>
        </div>
        <div class="cinema-actions">
          <a id="cinemaDirectLink" href="https://drive.google.com/file/d/1tjRPWYDhU18MZy13yIaTy0rR1mmNlo7M/view" target="_blank" class="cinema-direct-btn" title="Open Video in Full Tab">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Full View
          </a>
        </div>
      </div>

      <!-- Video Display Screen -->
      <div class="cinema-screen" id="cinemaScreen">
        <iframe 
          id="cinemaIframe"
          src="https://drive.google.com/file/d/1tjRPWYDhU18MZy13yIaTy0rR1mmNlo7M/preview"
          title="Eco Divine Video Experience"
          allow="autoplay; fullscreen"
          allowfullscreen>
        </iframe>
      </div>
    </div>

    <!-- Interactive Video Playlist Cards -->
    <div class="video-playlist-section" data-aos="fade-up" data-aos-delay="200">
      <div class="playlist-header">
        <h4><i class="fa-solid fa-film text-success me-2"></i> Select Video Tour</h4>
        <span class="playlist-count">4 Video Experiences</span>
      </div>

      <div class="row g-3 g-lg-4">
        <!-- Item 1 -->
        <div class="col-6 col-lg-3">
          <div class="video-thumb-card is-active" 
               data-id="1tjRPWYDhU18MZy13yIaTy0rR1mmNlo7M"
               data-title="Beachfront Shoreline & Turquoise Waters"
               data-tag="Ocean & Horizon">
            <div class="thumb-media">
              <img src="assets/img/gallery/gallery1.jpg" alt="Beachfront Shoreline" loading="lazy">
              <div class="thumb-overlay"></div>
              <div class="thumb-play-btn">
                <i class="fa-solid fa-play"></i>
              </div>
              <span class="thumb-now-badge">Playing</span>
            </div>
            <div class="thumb-body">
              <span class="thumb-category">Ocean & Horizon</span>
              <h5 class="thumb-title">Beachfront Shoreline</h5>
              <span class="thumb-quality"><i class="fa-solid fa-video"></i> HD 1080p</span>
            </div>
          </div>
        </div>

        <!-- Item 2 -->
        <div class="col-6 col-lg-3">
          <div class="video-thumb-card" 
               data-id="1qidtdrCRgIDqrmBas1ZSKJo07th1Czxa"
               data-title="Natural Wooden Cottages & Veranda Tour"
               data-tag="Cottage Walkthrough">
            <div class="thumb-media">
              <img src="assets/img/gallery/gallery7.jpg" alt="Wooden Cottages" loading="lazy">
              <div class="thumb-overlay"></div>
              <div class="thumb-play-btn">
                <i class="fa-solid fa-play"></i>
              </div>
              <span class="thumb-now-badge">Playing</span>
            </div>
            <div class="thumb-body">
              <span class="thumb-category">Cottage Walkthrough</span>
              <h5 class="thumb-title">Deluxe Wooden Cottages</h5>
              <span class="thumb-quality"><i class="fa-solid fa-video"></i> HD 1080p</span>
            </div>
          </div>
        </div>

        <!-- Item 3 -->
        <div class="col-6 col-lg-3">
          <div class="video-thumb-card" 
               data-id="1E7Z-9svWtgQZwdH6vq1DBw97EXrZdFFu"
               data-title="Golden Island Sunset & Sea Breeze"
               data-tag="Sunset Magic">
            <div class="thumb-media">
              <img src="assets/img/gallery/gallery3.jpg" alt="Island Sunset" loading="lazy">
              <div class="thumb-overlay"></div>
              <div class="thumb-play-btn">
                <i class="fa-solid fa-play"></i>
              </div>
              <span class="thumb-now-badge">Playing</span>
            </div>
            <div class="thumb-body">
              <span class="thumb-category">Sunset Magic</span>
              <h5 class="thumb-title">Golden Island Sunset</h5>
              <span class="thumb-quality"><i class="fa-solid fa-video"></i> HD 1080p</span>
            </div>
          </div>
        </div>

        <!-- Item 4 -->
        <div class="col-6 col-lg-3">
          <div class="video-thumb-card" 
               data-id="1OO84PQzYSD2lqEZnTVl287M0Hh4MYZSh"
               data-title="Resort Night Lighting & Tranquil Vibes"
               data-tag="Night Ambience">
            <div class="thumb-media">
              <img src="assets/img/gallery/gallery10.jpg" alt="Night Lighting" loading="lazy">
              <div class="thumb-overlay"></div>
              <div class="thumb-play-btn">
                <i class="fa-solid fa-play"></i>
              </div>
              <span class="thumb-now-badge">Playing</span>
            </div>
            <div class="thumb-body">
              <span class="thumb-category">Night Ambience</span>
              <h5 class="thumb-title">Cozy Evening Lighting</h5>
              <span class="thumb-quality"><i class="fa-solid fa-video"></i> HD 1080p</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Video Bottom CTA Bar -->
    <div class="video-cta-wrap text-center" data-aos="fade-up" data-aos-delay="250">
      <div class="video-cta-box">
        <div class="video-cta-text">
          <h4>Plan Your Unforgettable Island Escape</h4>
          <p>Book directly to enjoy exclusive beachfront cottages with complimentary breakfast.</p>
        </div>
        <a href="packages.php" class="video-cta-btn">
          <i class="fa-regular fa-calendar-check me-2"></i> Reserve Your Stay
        </a>
      </div>
    </div>
  </div>
</section>

<!-- =================== VIDEO THEATER INTERACTION SCRIPT =================== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  const iframe = document.getElementById('cinemaIframe');
  const titleElem = document.getElementById('cinemaTitle');
  const directLink = document.getElementById('cinemaDirectLink');
  const thumbCards = document.querySelectorAll('.video-thumb-card');
  const cinemaScreen = document.getElementById('cinemaScreen');

  thumbCards.forEach(card => {
    card.addEventListener('click', function() {
      if (this.classList.contains('is-active')) return;

      const videoId = this.getAttribute('data-id');
      const videoTitle = this.getAttribute('data-title');

      // Update active state on cards
      thumbCards.forEach(c => c.classList.remove('is-active'));
      this.classList.add('is-active');

      // Smooth switch with opacity fade
      if (iframe && cinemaScreen) {
        cinemaScreen.classList.add('is-loading');
        iframe.src = `https://drive.google.com/file/d/${videoId}/preview`;
        
        setTimeout(() => {
          cinemaScreen.classList.remove('is-loading');
        }, 500);
      }

      if (titleElem) {
        titleElem.textContent = videoTitle;
      }

      if (directLink) {
        directLink.href = `https://drive.google.com/file/d/${videoId}/view`;
      }

      // If mobile screen, gently scroll toward cinema player
      if (window.innerWidth < 992) {
        const topPos = document.getElementById('video-gallery').getBoundingClientRect().top + window.pageYOffset - 80;
        window.scrollTo({ top: topPos, behavior: 'smooth' });
      }
    });
  });
});
</script>

<!-- =================== REDESIGNED LUXURY CONTACT SECTION =================== -->
<section id="contact" class="resort-contact-section">
  <div class="container">
    <!-- Section Header -->
    <div class="text-center contact-header-wrap" data-aos="fade-up">
      <div class="contact-badge">
        <i class="fa-solid fa-headset"></i> 24/7 GUEST HOSPITALITY
      </div>
      <h2 class="contact-main-heading">Get Connected With Us</h2>
      <p class="contact-sub-heading">
        Have questions about cottage bookings, island boat schedules, or personalized stay requests? We are here to assist you at every step of your Saint Martin journey.
      </p>
    </div>

    <!-- Quick Contact Cards Row -->
    <div class="row g-3 g-lg-4 mb-5" data-aos="fade-up" data-aos-delay="100">
      <!-- Card 1: Direct Hotline -->
      <div class="col-6 col-lg-3">
        <a href="tel:+8801817838383" class="contact-info-card">
          <div class="card-icon-bubble phone-bubble">
            <i class="fa-solid fa-phone-volume"></i>
          </div>
          <span class="card-tag">Reservation Hotline</span>
          <h4 class="card-val">01817-838383</h4>
          <span class="card-action">Call Direct <i class="fa-solid fa-arrow-right"></i></span>
        </a>
      </div>

      <!-- Card 2: WhatsApp Chat -->
      <div class="col-6 col-lg-3">
        <a href="https://wa.me/01333838389" target="_blank" class="contact-info-card">
          <div class="card-icon-bubble wa-bubble">
            <i class="fa-brands fa-whatsapp"></i>
          </div>
          <span class="card-tag">Instant WhatsApp</span>
          <h4 class="card-val">01333-838389</h4>
          <span class="card-action">Chat on WhatsApp <i class="fa-solid fa-arrow-right"></i></span>
        </a>
      </div>

      <!-- Card 3: Email Desk -->
      <div class="col-6 col-lg-3">
        <a href="mailto:ecodivinebeachresortsaintmarti@gmail.com" class="contact-info-card">
          <div class="card-icon-bubble mail-bubble">
            <i class="fa-solid fa-envelope"></i>
          </div>
          <span class="card-tag">Official Inquiries</span>
          <h4 class="card-val email-text">ecodivine...</h4>
          <span class="card-action">Send Email <i class="fa-solid fa-arrow-right"></i></span>
        </a>
      </div>

      <!-- Card 4: Island Location -->
      <div class="col-6 col-lg-3">
        <div class="contact-info-card no-hover">
          <div class="card-icon-bubble loc-bubble">
            <i class="fa-solid fa-location-dot"></i>
          </div>
          <span class="card-tag">Resort Location</span>
          <h4 class="card-val loc-text">Saint Martin</h4>
          <span class="card-action text-muted">North Beach, Narikel Bagan</span>
        </div>
      </div>
    </div>

    <!-- Main Content: Map + Contact Form -->
    <div class="row g-4 align-items-stretch" data-aos="fade-up" data-aos-delay="200">
      <!-- Left Column: Google Map with Resort Badge -->
      <div class="col-lg-6">
        <div class="contact-map-wrapper h-100">
          <div class="map-container-inner">
            <iframe 
              src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d12345.6789!2d92.3204604!3d20.6355698!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0xabc!2sEco%20Divine%20Beach%20Resort!5e0!3m2!1sen!2sbd!4v1728919956812"
              width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Eco Divine Beach Resort Map Location">
            </iframe>
          </div>
          
          <div class="map-floating-overlay">
            <div class="d-flex align-items-center gap-3">
              <div class="map-badge-icon">
                <i class="fa-solid fa-compass"></i>
              </div>
              <div>
                <h5 class="mb-1 text-white fw-bold">দারুচিনি বীচ / উত্তর বীচ</h5>
                <p class="mb-0 text-white-50 small">নারিকেল বাগান রোড, সেন্টমার্টিন দ্বীপ • ৫ মিনিট সেন্টমার্টিন জেটি ঘাট থেকে</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Right Column: Glassmorphic Contact Form -->
      <div class="col-lg-6">
        <div class="contact-form-card h-100">
          <div class="form-header mb-4">
            <h3 class="form-heading">Send Us a Direct Message</h3>
            <p class="form-subheading">We reply quickly to all guest inquiries and cottage booking questions.</p>
          </div>

          <form id="contactForm" class="resort-form">
            <div class="row g-3">
              <!-- Name -->
              <div class="col-md-6">
                <label class="form-field-label">Your Full Name <span class="text-danger">*</span></label>
                <div class="form-input-wrap">
                  <i class="fa-regular fa-user input-icon"></i>
                  <input type="text" name="name" class="form-control resort-control" placeholder="e.g. Tanvir Ahmed" required>
                </div>
              </div>

              <!-- Phone -->
              <div class="col-md-6">
                <label class="form-field-label">Phone / WhatsApp Number</label>
                <div class="form-input-wrap">
                  <i class="fa-solid fa-phone input-icon"></i>
                  <input type="tel" name="phone" class="form-control resort-control" placeholder="e.g. 01812-345678">
                </div>
              </div>

              <!-- Email -->
              <div class="col-12">
                <label class="form-field-label">Email Address <span class="text-danger">*</span></label>
                <div class="form-input-wrap">
                  <i class="fa-regular fa-envelope input-icon"></i>
                  <input type="email" name="email" class="form-control resort-control" placeholder="e.g. tanvir@example.com" required>
                </div>
              </div>

              <!-- Message -->
              <div class="col-12">
                <label class="form-field-label">Your Message or Inquiry <span class="text-danger">*</span></label>
                <div class="form-input-wrap">
                  <i class="fa-regular fa-comment-dots input-icon textarea-icon"></i>
                  <textarea name="message" class="form-control resort-control resort-textarea" rows="4" placeholder="Tell us about your dates, cottage preferences, or questions..." required></textarea>
                </div>
              </div>

              <!-- Submit -->
              <div class="col-12 mt-4">
                <button type="submit" class="contact-submit-btn" id="contactSubmitBtn">
                  <i class="fa-solid fa-paper-plane me-2"></i>
                  <span>Send Message to Reception</span>
                </button>
              </div>
            </div>
          </form>

          <!-- Feedback Alert Banner -->
          <div id="contactFeedback" class="contact-feedback-box mt-3" style="display:none;"></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Contact Form AJAX Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  const contactForm = document.getElementById('contactForm');
  const submitBtn = document.getElementById('contactSubmitBtn');
  const feedbackBox = document.getElementById('contactFeedback');

  if (contactForm) {
    contactForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const origBtnHtml = submitBtn.innerHTML;
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Sending Message...';

      const formData = new FormData(contactForm);

      fetch('mail.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = origBtnHtml;
        feedbackBox.style.display = 'block';

        if (data.status === 'success') {
          feedbackBox.className = 'contact-feedback-box success mt-3';
          feedbackBox.innerHTML = '<i class="fa-solid fa-circle-check me-2"></i> ' + data.message;
          contactForm.reset();

          setTimeout(() => {
            feedbackBox.style.display = 'none';
          }, 8000);
        } else {
          feedbackBox.className = 'contact-feedback-box error mt-3';
          feedbackBox.innerHTML = '<i class="fa-solid fa-circle-exclamation me-2"></i> ' + (data.message || 'Error sending message. Please call us directly.');
        }
      })
      .catch(error => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = origBtnHtml;
        feedbackBox.style.display = 'block';
        feedbackBox.className = 'contact-feedback-box error mt-3';
        feedbackBox.innerHTML = '<i class="fa-solid fa-circle-exclamation me-2"></i> Connection error. Please call our reception at 01817-838383.';
        console.error('Contact form error:', error);
      });
    });
  }
});
</script>

<?php include('footer.php'); ?>

<!-- =================== HOME AVAILABILITY SEARCH SCRIPT =================== -->
<script>
// Helper to pass auto-fetched dates to cottage detail links
function appendSearchDates(el, ev) {
  const inVal = document.getElementById('homeCheckIn')?.value || localStorage.getItem('eco_check_in');
  const outVal = document.getElementById('homeCheckOut')?.value || localStorage.getItem('eco_check_out');
  if (inVal && outVal) {
    const sep = el.href.includes('?') ? '&' : '?';
    // Remove old checkin/checkout if already appended
    const cleanUrl = el.href.replace(/([&?])checkin=[^&]*/g, '').replace(/([&?])checkout=[^&]*/g, '');
    const cleanSep = cleanUrl.includes('?') ? '&' : '?';
    el.href = cleanUrl + cleanSep + 'checkin=' + encodeURIComponent(inVal) + '&checkout=' + encodeURIComponent(outVal);
  }
}

document.addEventListener('DOMContentLoaded', function() {
  const homeIn = document.getElementById('homeCheckIn');
  const homeOut = document.getElementById('homeCheckOut');
  const inHint = document.getElementById('homeCheckInHint');
  const outHint = document.getElementById('homeCheckOutHint');
  const form = document.getElementById('heroBookingForm');

  if (homeIn && homeOut && typeof flatpickr !== 'undefined') {
    // Restore dates from localStorage if available
    const savedIn = localStorage.getItem('eco_check_in');
    const savedOut = localStorage.getItem('eco_check_out');

    const fpHomeIn = flatpickr(homeIn, {
      altInput: true,
      altInputClass: "form-control booking-date-control",
      altFormat: "D, d M Y",
      dateFormat: "Y-m-d",
      minDate: "today",
      disableMobile: true,
      defaultDate: savedIn || null,
      onChange: function(selectedDates, dateStr) {
        if (selectedDates.length) {
          localStorage.setItem('eco_check_in', dateStr);
          const nextDay = new Date(selectedDates[0].getTime() + 24 * 60 * 60 * 1000);
          fpHomeOut.set('minDate', nextDay);
          
          if (inHint) {
            inHint.textContent = selectedDates[0].toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' });
          }

          const currentOut = homeOut.value;
          if (!currentOut || new Date(currentOut) <= selectedDates[0]) {
            fpHomeOut.setDate(nextDay, true);
          }
        }
      }
    });

    const fpHomeOut = flatpickr(homeOut, {
      altInput: true,
      altInputClass: "form-control booking-date-control",
      altFormat: "D, d M Y",
      dateFormat: "Y-m-d",
      minDate: new Date().fp_incr(1),
      disableMobile: true,
      defaultDate: savedOut || null,
      onChange: function(selectedDates, dateStr) {
        if (selectedDates.length) {
          localStorage.setItem('eco_check_out', dateStr);
          if (outHint) {
            outHint.textContent = selectedDates[0].toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' });
          }
        }
      }
    });

    if (form) {
      form.addEventListener('submit', function(e) {
        const inVal = homeIn.value;
        const outVal = homeOut.value;
        if (!inVal || !outVal) {
          e.preventDefault();
          alert('⚠️ Please select both Check-in and Check-out dates.');
          if (!inVal) fpHomeIn.open();
          else fpHomeOut.open();
          return false;
        }
        if (new Date(inVal) >= new Date(outVal)) {
          e.preventDefault();
          alert('⚠️ Check-out date must be after Check-in date.');
          fpHomeOut.open();
          return false;
        }
        localStorage.setItem('eco_check_in', inVal);
        localStorage.setItem('eco_check_out', outVal);
      });
    }
  }
});
</script>
