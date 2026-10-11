<?php
// Determine current page for active nav highlight
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ECO DIVINE BEACH RESORT | The Best Resort in Saint Martin Island</title>
  <meta name="description" content="Eco Divine Beach Resort Saint Martin. Enjoy sea-view cottages, luxury rooms, restaurant, natural beach vibes and peaceful accommodation. Book Now!">
  <meta name="keywords" content="saint martin resort, resort in saintmartin, beach resort bangladesh, cox's bazar resort, eco resort, saintmartin cottage, sea view resort bd">
  <meta name="author" content="Eco Divine Beach Resort">

  <!-- Schema.org Structured Data -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Hotel",
    "name": "Eco Divine Beach Resort",
    "address": {
      "@type": "PostalAddress",
      "streetAddress": "Saint Martin Island",
      "addressLocality": "Saint Martin",
      "addressCountry": "Bangladesh"
    },
    "url": "https://www.ecodivinebeachresort.com",
    "telephone": "+8801817838383",
    "priceRange": "$$",
    "image": "https://www.ecodivinebeachresort.com/assets/img/coral.jpg",
    "sameAs": [
      "https://www.facebook.com/p/Eco-Divine-Beach-Resort-Saint-Martin-100050663805467/",
      "https://eco-divine-beach-resort-saint-martin.bangladeshhotels.net/"
    ]
  }
  </script>

  <!-- Favicon -->
  <link rel="icon" href="favicon.png">

  <!-- Google Fonts: Poppins & Caveat -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- CSS Links -->
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Swiper CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">
  <!-- AOS CSS -->
  <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
  <!-- Font Awesome -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <!-- Custom CSS -->
  <link rel="stylesheet" href="style.css?v=5">
  <!-- flatpickr CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/material_blue.css">

  <!-- Google Tag (Optional) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-QDP6C19808"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'G-QDP6C19808');
  </script>
</head>

<body>

<!-- ============ REDESIGNED RESPONSIVE NAVBAR ============ -->
<header id="mainHeader">
  <div class="nav-container">
    <!-- Brand Logo -->
    <a href="index.php" class="brand-logo" aria-label="Eco Divine Beach Resort Home">
      <img src="favicon.png" alt="Eco Divine Logo" class="brand-logo-img">
      <div class="brand-text">
        <span class="brand-name"><span class="highlight">Eco Divine</span> Beach Resort</span>
        <span class="brand-tagline">Saint Martin Island</span>
      </div>
    </a>

    <!-- Desktop Navigation Links -->
    <nav class="desktop-nav" aria-label="Main Navigation">
      <ul class="nav-menu">
        <li>
          <a href="index.php#home" class="nav-item-link <?= ($current_page == 'index.php') ? 'active' : '' ?>">
            HOME
          </a>
        </li>
        <li>
          <a href="index.php#cottages" class="nav-item-link">
            COTTAGES
          </a>
        </li>
        <li>
          <a href="resturant.php" class="nav-item-link <?= ($current_page == 'resturant.php') ? 'active' : '' ?>">
            RESTAURANT
          </a>
        </li>
        <li>
          <a href="index.php#contact" class="nav-item-link">
            CONTACT
          </a>
        </li>
        <li>
          <a href="admin/login.php" class="nav-item-link <?= ($current_page == 'login.php') ? 'active' : '' ?>">
            LOGIN
          </a>
        </li>
      </ul>
    </nav>

    <!-- Header Action Buttons -->
    <div class="header-actions">
      <a href="tel:+8801817838383" class="nav-contact-btn" title="Call Us 01817-838383">
        <i class="fa-solid fa-phone-volume"></i>
        <span class="phone-number">01817-838383</span>
      </a>

      <a href="packages.php" class="nav-book-btn">
        <i class="fa-regular fa-calendar-check"></i>
        <span>Book Now</span>
      </a>

      <!-- Mobile Hamburger Button -->
      <button class="menu-toggle-btn" id="menuToggleBtn" aria-label="Toggle navigation" aria-expanded="false" aria-controls="mobileNavDrawer">
        <span class="bar bar-1"></span>
        <span class="bar bar-2"></span>
        <span class="bar bar-3"></span>
      </button>
    </div>
  </div>

  <!-- Mobile Drawer Menu (Offcanvas) -->
  <div class="mobile-nav-drawer" id="mobileNavDrawer" aria-hidden="true">
    <div class="drawer-header">
      <div class="drawer-brand">
        <img src="favicon.png" alt="Logo" class="drawer-logo-img">
        <div>
          <div class="drawer-brand-title"><span class="highlight">Eco Divine</span></div>
          <div class="drawer-brand-sub">Beach Resort</div>
        </div>
      </div>
      <button class="drawer-close-btn" id="drawerCloseBtn" aria-label="Close navigation">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <div class="drawer-body">
      <ul class="mobile-nav-links">
        <li>
          <a href="index.php#home" class="mobile-nav-link <?= ($current_page == 'index.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-house"></i>
            <span>Home</span>
          </a>
        </li>
        <li>
          <a href="index.php#cottages" class="mobile-nav-link">
            <i class="fa-solid fa-umbrella-beach"></i>
            <span>Our Cottages</span>
          </a>
        </li>
        <li>
          <a href="resturant.php" class="mobile-nav-link <?= ($current_page == 'resturant.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-utensils"></i>
            <span>Restaurant Menu</span>
          </a>
        </li>
        <li>
          <a href="index.php#contact" class="mobile-nav-link">
            <i class="fa-solid fa-envelope"></i>
            <span>Contact Us</span>
          </a>
        </li>
        <li>
          <a href="admin/login.php" class="mobile-nav-link <?= ($current_page == 'login.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-arrow-right-to-bracket"></i>
            <span>Login</span>
          </a>
        </li>
      </ul>

      <!-- Mobile Drawer Footer Actions -->
      <div class="drawer-actions">
        <a href="packages.php" class="mobile-book-btn">
          <i class="fa-regular fa-calendar-check me-2"></i> Book A Cottage
        </a>
        <div class="mobile-quick-contacts">
          <a href="tel:+8801817838383" class="mobile-contact-pill">
            <i class="fa-solid fa-phone"></i> Call: 01817838383
          </a>
          <a href="https://wa.me/01333838389" target="_blank" class="mobile-contact-pill whatsapp">
            <i class="fa-brands fa-whatsapp"></i> WhatsApp Us
          </a>
        </div>
        <div class="drawer-info">
          <small><i class="fa-solid fa-location-dot me-1"></i> North Beach, Narikel Bagan, Saint Martin</small>
        </div>
      </div>
    </div>
  </div>

  <!-- Mobile Backdrop Overlay -->
  <div class="nav-backdrop" id="navBackdrop"></div>
</header>

<!-- ============ NAVBAR INTERACTIVITY SCRIPT ============ -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  const header = document.getElementById('mainHeader');
  const toggleBtn = document.getElementById('menuToggleBtn');
  const drawer = document.getElementById('mobileNavDrawer');
  const backdrop = document.getElementById('navBackdrop');
  const closeBtn = document.getElementById('drawerCloseBtn');
  const mobileLinks = document.querySelectorAll('.mobile-nav-link, .mobile-book-btn');

  // Toggle Header Scrolled Class on Scroll
  function handleScroll() {
    if (window.scrollY > 20) {
      header.classList.add('scrolled');
    } else {
      header.classList.remove('scrolled');
    }
  }
  window.addEventListener('scroll', handleScroll, { passive: true });
  handleScroll(); // Initial check

  // Open Drawer
  function openMenu() {
    drawer.classList.add('is-open');
    backdrop.classList.add('is-open');
    toggleBtn.classList.add('is-active');
    toggleBtn.setAttribute('aria-expanded', 'true');
    drawer.setAttribute('aria-hidden', 'false');
    document.body.classList.add('mobile-nav-active');
  }

  // Close Drawer
  function closeMenu() {
    drawer.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    toggleBtn.classList.remove('is-active');
    toggleBtn.setAttribute('aria-expanded', 'false');
    drawer.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('mobile-nav-active');
  }

  // Hamburger Click Event
  if (toggleBtn) {
    toggleBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (drawer.classList.contains('is-open')) {
        closeMenu();
      } else {
        openMenu();
      }
    });
  }

  // Close Button Click Event
  if (closeBtn) {
    closeBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      closeMenu();
    });
  }

  // Backdrop Click Event
  if (backdrop) {
    backdrop.addEventListener('click', closeMenu);
  }

  // Close Drawer on Link Click
  mobileLinks.forEach(function (link) {
    link.addEventListener('click', function () {
      closeMenu();
    });
  });

  // Close Drawer on Escape Key
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && drawer.classList.contains('is-open')) {
      closeMenu();
    }
  });

  // Reset Drawer on Window Resize to Desktop Screen
  window.addEventListener('resize', function () {
    if (window.innerWidth >= 992 && drawer.classList.contains('is-open')) {
      closeMenu();
    }
  });
});
</script>
