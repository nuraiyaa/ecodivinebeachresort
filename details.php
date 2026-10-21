<?php
// details.php - Luxury Cottage Details & Reservation Page
// Eco Divine Beach Resort, Saint Martin Island
// Fully Responsive Across All Devices (Mobile, Tablet, Desktop)

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/admin/includes/db.php';

// Retrieve GET parameters or auto-fetch default dates
$cottageId   = isset($_GET['id']) ? intval($_GET['id']) : 0;
$cottageName = isset($_GET['name']) ? trim($_GET['name']) : '';
$checkin     = isset($_GET['checkin']) ? trim($_GET['checkin']) : (isset($_GET['check_in']) ? trim($_GET['check_in']) : '');
$checkout    = isset($_GET['checkout']) ? trim($_GET['checkout']) : (isset($_GET['check_out']) ? trim($_GET['check_out']) : '');

// Auto-fetch fallback: if dates not passed, default to today and tomorrow
if (empty($checkin)) {
    $checkin = date('Y-m-d');
}
if (empty($checkout) || strtotime($checkout) <= strtotime($checkin)) {
    $checkout = date('Y-m-d', strtotime($checkin . ' +1 day'));
}

// Query cottage with fallback
$cottage = null;
if ($cottageId > 0) {
    $stmt = $conn->prepare("SELECT * FROM cottages WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $cottageId);
    $stmt->execute();
    $cottage = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$cottage && !empty($cottageName)) {
    $stmt = $conn->prepare("SELECT * FROM cottages WHERE title = ? LIMIT 1");
    $stmt->bind_param("s", $cottageName);
    $stmt->execute();
    $cottage = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$cottage) {
    $res = $conn->query("SELECT * FROM cottages WHERE status = 'active' ORDER BY id ASC LIMIT 1");
    if ($res && $res->num_rows > 0) {
        $cottage = $res->fetch_assoc();
    } else {
        $cottage = [
            'id' => 1,
            'title' => 'তিলোত্তমা',
            'price' => 4500,
            'weekend_price' => 5500,
            'is_weekend' => 0,
            'status' => 'active'
        ];
    }
}

$cottageId   = intval($cottage['id']);
$cottageName = $cottage['title'];

// Folder mapping for photos and video
$folderMap = [
    'অরুনিকা'    => 'orunika',
    'তিলোত্তমা'   => 'tilottoma',
    'ছায়াবীথি'   => 'chayabithi',
    'নয়নতারা'    => 'noyontara',
    'জলসা'       => 'jolsha',
    'রুপকথা'     => 'rupkotha',
    'কোরাল-০১'   => 'coral_01',
    'কোরাল-০২'   => 'coral_02',
    'লবস্টার-০৩' => 'lobstar_03',
    'লবস্টার-০৪' => 'lobstar_04',
    'রূপচাঁদা-০৫' => 'rupchanda_05',
    'রূপচাঁদা-০৬' => 'rupchanda_06'
];
$folder = $folderMap[$cottageName] ?? 'tilottoma';

// Pricing variables
$weekdayPrice = floatval($cottage['price'] ?? 4500);
$weekendPrice = floatval($cottage['weekend_price'] ?? $weekdayPrice);
$stayPricing  = calculateStayPricing($checkin, $checkout, $weekdayPrice, $weekendPrice);
$currentPrice = $stayPricing['total_price'];
$isWeekend    = $stayPricing['is_weekend'];
$isAvailable  = (($cottage['status'] ?? 'active') === 'active');

// Guest Capacity and Features
$four_person_cottages = ['জলসা', 'কোরাল-০১', 'কোরাল-০২', 'লবস্টার-০৪', 'রূপচাঁদা-০৫', 'রূপচাঁদা-০৬'];
$isFourPerson = in_array($cottageName, $four_person_cottages);
$capacityText = $isFourPerson ? '4 Persons (Family Suite)' : '2 Persons (Couple Suite)';
$bedText      = $isFourPerson ? '2 Double King Beds' : '1 Luxury King Bed';

$side_sea_cottages = ['রুপকথা', 'জলসা'];
$non_sea_cottages  = ['কোরাল-০১', 'কোরাল-০২', 'লবস্টার-০৩', 'লবস্টার-০৪', 'রূপচাঁদা-০৫', 'রূপচাঁদা-০৬'];

if (in_array($cottageName, $side_sea_cottages)) {
    $viewLabel = 'Side Sea View';
    $viewClass = 'side-sea-view';
    $viewIcon  = '<i class="fa-solid fa-water-ladder me-1"></i>';
} elseif (in_array($cottageName, $non_sea_cottages)) {
    $viewLabel = 'Non-Sea View';
    $viewClass = 'garden-view';
    $viewIcon  = '<i class="fa-solid fa-tree me-1"></i>';
} else {
    $viewLabel = 'Direct Sea & Beach View';
    $viewClass = 'sea-view';
    $viewIcon  = '<i class="fa-solid fa-water me-1"></i>';
}
$bathLabel    = ($cottageName === 'রুপকথা') ? 'Adjacent Private Bath' : 'Attached Modern Bath';

// Fetch other cottages for recommendations
$otherCottagesRes = $conn->query("SELECT * FROM cottages WHERE id != $cottageId AND status = 'active' ORDER BY RAND() LIMIT 3");
$otherCottages = [];
if ($otherCottagesRes) {
    while ($r = $otherCottagesRes->fetch_assoc()) {
        $otherCottages[] = $r;
    }
}
?>

<!-- ========================================================
     RESPONSIVE STYLES (MOBILE, TABLET & DESKTOP OPTIMIZED)
     ======================================================== -->
<style>
/* Base Wrapper & Offsets */
.cottage-details-wrapper {
  margin-top: 85px;
  background-color: #f8fafc;
  min-height: 100vh;
}

@media (max-width: 991px) {
  .cottage-details-wrapper {
    margin-top: 72px;
    padding-bottom: 90px; /* Offset for floating mobile bottom bar */
  }
}
@media (max-width: 576px) {
  .cottage-details-wrapper {
    margin-top: 65px;
    padding-bottom: 85px;
  }
}

/* Breadcrumb Navigation */
.details-breadcrumb-bar {
  background: #ffffff;
  border-bottom: 1px solid #e2e8f0;
  padding: 12px 0;
}
.details-breadcrumb {
  margin-bottom: 0;
  font-size: 0.85rem;
  display: flex;
  flex-wrap: nowrap;
  overflow-x: auto;
  white-space: nowrap;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
}
.details-breadcrumb::-webkit-scrollbar {
  display: none;
}
.details-breadcrumb a {
  color: #64748b;
  text-decoration: none;
  transition: color 0.2s ease;
}
.details-breadcrumb a:hover {
  color: #059669;
}
.details-breadcrumb .active {
  color: #0f172a;
  font-weight: 600;
}

/* Header Section */
.details-header-section {
  background: #ffffff;
  border-bottom: 1px solid #e2e8f0;
  padding: 22px 0 18px;
}
.cottage-main-title {
  font-size: clamp(1.6rem, 4vw, 2.3rem);
  font-weight: 800;
  color: #0f172a;
  letter-spacing: -0.5px;
  line-height: 1.2;
}
.cottage-meta-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #f1f5f9;
  color: #334155;
  font-size: clamp(0.75rem, 2vw, 0.82rem);
  font-weight: 600;
  padding: 5px 12px;
  border-radius: 9999px;
  border: 1px solid #e2e8f0;
}
.weekend-pill-active {
  background: #fef3c7;
  color: #b45309;
  border-color: #fde68a;
}

/* DESKTOP 5-PHOTO GRID */
.cottage-gallery-grid {
  display: grid;
  grid-template-columns: 2fr 1fr 1fr;
  grid-template-rows: 210px 210px;
  gap: 12px;
  border-radius: 20px;
  overflow: hidden;
  margin-bottom: 30px;
  position: relative;
}
.gallery-grid-item {
  position: relative;
  overflow: hidden;
  cursor: pointer;
  background: #e2e8f0;
}
.gallery-grid-item img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}
.gallery-grid-item:hover img {
  transform: scale(1.05);
}
.gallery-grid-main {
  grid-column: 1 / 2;
  grid-row: 1 / 3;
}
.gallery-view-all-btn {
  position: absolute;
  bottom: 16px;
  right: 16px;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  color: #0f172a;
  border: 1px solid rgba(0, 0, 0, 0.08);
  padding: 9px 18px;
  border-radius: 12px;
  font-size: 0.88rem;
  font-weight: 600;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.12);
  transition: all 0.2s ease;
  z-index: 5;
}
.gallery-view-all-btn:hover {
  background: #ffffff;
  color: #059669;
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(0, 0, 0, 0.16);
}

/* MOBILE DEDICATED GALLERY SHOWCASE */
.mobile-gallery-card {
  position: relative;
  border-radius: 16px;
  overflow: hidden;
  background: #e2e8f0;
  margin-bottom: 14px;
}
.mobile-gallery-hero-img {
  width: 100%;
  height: 270px;
  object-fit: cover;
  display: block;
}
.mobile-gallery-badges {
  position: absolute;
  top: 12px;
  left: 12px;
  right: 12px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  z-index: 2;
}
.mobile-gallery-thumb-strip {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding: 4px 0 16px;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
}
.mobile-gallery-thumb-strip::-webkit-scrollbar {
  display: none;
}
.mobile-gallery-thumb-item {
  flex: 0 0 76px;
  height: 54px;
  border-radius: 10px;
  object-fit: cover;
  cursor: pointer;
  border: 2px solid #e2e8f0;
  transition: all 0.2s ease;
}
.mobile-gallery-thumb-item.active-thumb {
  border-color: #059669;
  box-shadow: 0 0 0 2px rgba(5, 150, 105, 0.3);
}

/* Card Containers */
.details-white-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 18px;
  padding: clamp(18px, 3vw, 28px);
  margin-bottom: 22px;
  box-shadow: 0 2px 10px rgba(15, 23, 42, 0.03);
}

/* Quick Specs Box */
.quick-spec-box {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  background: #f8fafc;
  border: 1px solid #edf2f7;
  border-radius: 14px;
  height: 100%;
}
.quick-spec-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: #ecfdf5;
  color: #059669;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.2rem;
  flex-shrink: 0;
}
@media (max-width: 576px) {
  .quick-spec-box {
    padding: 10px 12px;
    gap: 8px;
  }
  .quick-spec-icon {
    width: 36px;
    height: 36px;
    font-size: 1rem;
    border-radius: 10px;
  }
}

/* Amenities Grid */
.amenity-chip {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  font-weight: 500;
  color: #1e293b;
  font-size: 0.9rem;
  transition: all 0.2s ease;
  height: 100%;
}
.amenity-chip:hover {
  border-color: #059669;
  background: #f0fdf4;
}
.amenity-icon-circle {
  width: 34px;
  height: 34px;
  border-radius: 8px;
  background: #ecfdf5;
  color: #059669;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.95rem;
  flex-shrink: 0;
}
@media (max-width: 576px) {
  .amenity-chip {
    padding: 10px 10px;
    font-size: 0.78rem;
    gap: 8px;
  }
  .amenity-icon-circle {
    width: 28px;
    height: 28px;
    font-size: 0.85rem;
    border-radius: 6px;
  }
}

/* Video Tour Container */
.video-tour-box {
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 8px 30px rgba(15, 23, 42, 0.12);
  background: #000000;
  position: relative;
  aspect-ratio: 16 / 9;
}
.video-tour-box video {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

/* Sticky Booking Card */
.sticky-booking-card {
  position: sticky;
  top: 100px;
  background: #ffffff;
  border: 1.5px solid #e2e8f0;
  border-radius: 20px;
  box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
  overflow: hidden;
  transition: all 0.3s ease;
}
@media (max-width: 991px) {
  .sticky-booking-card {
    position: static !important;
    margin-top: 15px;
  }
}
.booking-card-header {
  background: linear-gradient(135deg, #065f46 0%, #047857 100%);
  padding: 20px 24px;
  color: #ffffff;
}
.rate-big {
  font-size: clamp(1.8rem, 3.5vw, 2.2rem);
  font-weight: 800;
  line-height: 1;
}
.booking-card-body {
  padding: clamp(18px, 3vw, 24px);
}
.calc-breakdown-box {
  background: #f8fafc;
  border: 1.5px dashed #cbd5e1;
  border-radius: 12px;
  padding: 14px 16px;
  margin-top: 15px;
  margin-bottom: 20px;
}
.btn-reserve-cottage {
  background: #059669;
  border: none;
  color: #ffffff;
  font-weight: 700;
  font-size: 1.05rem;
  padding: 14px;
  border-radius: 12px;
  box-shadow: 0 4px 16px rgba(5, 150, 105, 0.35);
  transition: all 0.2s ease;
}
.btn-reserve-cottage:hover {
  background: #047857;
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(5, 150, 105, 0.45);
  color: #ffffff;
}
.booking-card-highlight {
  animation: cardPulse 1.4s ease-out;
  border-color: #059669 !important;
}
@keyframes cardPulse {
  0% { box-shadow: 0 0 0 0 rgba(5, 150, 105, 0.6); }
  70% { box-shadow: 0 0 0 16px rgba(5, 150, 105, 0); }
  100% { box-shadow: 0 0 0 0 rgba(5, 150, 105, 0); }
}

/* Floating Mobile Booking Bar */
.mobile-booking-floating-bar {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: 1040;
  background: rgba(255, 255, 255, 0.96);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border-top: 1px solid #e2e8f0;
  padding: 12px 0;
  box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.08);
}
.mobile-price-val {
  font-size: 1.25rem;
  font-weight: 800;
  color: #059669;
}

/* Advance Payment Callout */
.advance-notice-box {
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 12px;
  padding: 14px;
  font-size: 0.84rem;
  color: #92400e;
}

/* Recommendation Cards */
.recom-card {
  border-radius: 16px;
  border: 1px solid #e2e8f0;
  overflow: hidden;
  background: #ffffff;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.recom-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
}
.recom-img {
  height: 180px;
  width: 100%;
  object-fit: cover;
}

/* Modal Thumbnails Strip */
.modal-thumbs-strip {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  white-space: nowrap;
  padding: 8px 4px 6px;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: thin;
  justify-content: center;
}
@media (max-width: 576px) {
  .modal-thumbs-strip {
    justify-content: flex-start;
  }
}
.photo-thumb-item {
  flex: 0 0 72px;
  height: 52px;
  border-radius: 8px;
  object-fit: cover;
  cursor: pointer;
  border: 2px solid transparent;
  transition: all 0.2s ease;
}
@media (max-width: 576px) {
  .photo-thumb-item {
    flex: 0 0 64px;
    height: 46px;
  }
}

/* Success Modal */
#bookingSuccessModal .modal-content {
  border-radius: 24px;
  border: none;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
  overflow: hidden;
}
.success-check-bubble {
  width: 72px;
  height: 72px;
  background: #d1fae5;
  color: #059669;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  margin: 0 auto 14px;
  border: 4px solid #a7f3d0;
}
</style>

<div class="cottage-details-wrapper">

  <!-- =================== BREADCRUMB BAR =================== -->
  <div class="details-breadcrumb-bar">
    <div class="container">
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb details-breadcrumb">
          <li class="breadcrumb-item"><a href="index.php"><i class="fa-solid fa-house me-1"></i> Home</a></li>
          <li class="breadcrumb-item"><a href="index.php#cottages">Cottages</a></li>
          <li class="breadcrumb-item"><a href="packages.php">Packages</a></li>
          <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($cottageName) ?></li>
        </ol>
      </nav>
    </div>
  </div>

  <!-- =================== COTTAGE HEADER =================== -->
  <div class="details-header-section">
    <div class="container">
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div>
          <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
            <span class="cottage-meta-pill text-success border-success border-opacity-25 bg-success bg-opacity-10">
              <i class="fa-solid fa-circle-check"></i> <?= $isAvailable ? 'Available' : 'Maintenance' ?>
            </span>
            <span class="cottage-meta-pill">
              <i class="fa-solid fa-water text-primary"></i> <?= $viewLabel ?>
            </span>
            <span class="cottage-meta-pill">
              <i class="fa-solid fa-user-group text-info"></i> <?= $capacityText ?>
            </span>
            <span class="cottage-meta-pill <?= $stayPricing['rate_type_badge'] === 'Weekend Rate' ? 'weekend-pill-active' : ($stayPricing['rate_type_badge'] === 'Mixed Rate' ? 'bg-primary bg-opacity-10 text-primary border-primary border-opacity-25' : 'text-muted border-secondary border-opacity-25') ?>" id="topWeekendBadge">
              <?php if ($stayPricing['rate_type_badge'] === 'Weekend Rate'): ?>
                <i class="fa-solid fa-fire text-warning"></i> Weekend Rate Active
              <?php elseif ($stayPricing['rate_type_badge'] === 'Mixed Rate'): ?>
                <i class="fa-solid fa-calculator text-primary"></i> Mixed Rate Active
              <?php else: ?>
                <i class="fa-regular fa-calendar"></i> Weekday Rate
              <?php endif; ?>
            </span>
          </div>

          <h1 class="cottage-main-title mb-1"><?= htmlspecialchars($cottageName) ?></h1>
          <p class="text-muted mb-0 small">
            <i class="fa-solid fa-location-dot text-danger me-1"></i> Daruchini Beach / North Beach, Saint Martin Island
          </p>
        </div>

        <!-- Quick Top Contact Hotline (Responsive on Mobile) -->
        <div class="d-flex align-items-center gap-2 w-100 w-md-auto">
          <a href="tel:+8801817838383" class="btn btn-outline-success rounded-pill px-3 py-2 fw-semibold shadow-sm flex-fill text-center">
            <i class="fa-solid fa-phone me-1"></i> 01817-838383
          </a>
          <a href="https://wa.me/01333838389?text=Hello%2C+I+want+to+book+<?= urlencode($cottageName) ?>" target="_blank" class="btn btn-success rounded-pill px-3 py-2 fw-semibold shadow-sm flex-fill text-center" style="background:#25D366; border-color:#25D366;">
            <i class="fa-brands fa-whatsapp me-1"></i> WhatsApp
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- =================== MAIN DETAIL CONTAINER =================== -->
  <div class="container py-3 py-md-4">

    <!-- 1. DESKTOP / TABLET 5-PHOTO LUXURY SHOWCASE GALLERY (>= 768px) -->
    <div class="cottage-gallery-grid d-none d-md-grid" id="cottageGalleryDesktop">
      <div class="gallery-grid-item gallery-grid-main" onclick="openPhotoModal(1)">
        <img src="assets/img/cottages/<?= $folder ?>/1.jpg" alt="<?= htmlspecialchars($cottageName) ?> Primary View">
      </div>
      <div class="gallery-grid-item" onclick="openPhotoModal(2)">
        <img src="assets/img/cottages/<?= $folder ?>/2.jpg" alt="<?= htmlspecialchars($cottageName) ?> Interior">
      </div>
      <div class="gallery-grid-item" onclick="openPhotoModal(3)">
        <img src="assets/img/cottages/<?= $folder ?>/3.jpg" alt="<?= htmlspecialchars($cottageName) ?> Room Decor">
      </div>
      <div class="gallery-grid-item" onclick="openPhotoModal(4)">
        <img src="assets/img/cottages/<?= $folder ?>/4.jpg" alt="<?= htmlspecialchars($cottageName) ?> Balcony / Bed">
      </div>
      <div class="gallery-grid-item" onclick="openPhotoModal(5)">
        <img src="assets/img/cottages/<?= $folder ?>/5.jpg" alt="<?= htmlspecialchars($cottageName) ?> Resort Surroundings">
      </div>

      <button type="button" class="gallery-view-all-btn" onclick="openPhotoModal(1)">
        <i class="fa-solid fa-images me-1 text-success"></i> View All 7 Photos
      </button>
    </div>

    <!-- 2. MOBILE DEDICATED TOUCH-FRIENDLY GALLERY (< 768px) -->
    <div class="d-block d-md-none mb-3">
      <div class="mobile-gallery-card" onclick="openPhotoModal(1)">
        <img id="mobileHeroPhoto" src="assets/img/cottages/<?= $folder ?>/1.jpg" class="mobile-gallery-hero-img" alt="<?= htmlspecialchars($cottageName) ?>">
        <div class="mobile-gallery-badges">
          <span class="badge bg-dark bg-opacity-75 text-white rounded-pill px-3 py-2 fw-semibold">
            <i class="fa-solid fa-water me-1"></i> <?= $viewLabel ?>
          </span>
          <span class="badge bg-dark bg-opacity-75 text-white rounded-pill px-3 py-2 fw-semibold">
            <i class="fa-solid fa-camera me-1"></i> 7 Photos
          </span>
        </div>
      </div>

      <!-- Horizontal Thumbnails Strip -->
      <div class="mobile-gallery-thumb-strip">
        <?php for ($i = 1; $i <= 7; $i++): ?>
          <img src="assets/img/cottages/<?= $folder ?>/<?= $i ?>.jpg" 
               class="mobile-gallery-thumb-item <?= ($i === 1) ? 'active-thumb' : '' ?>" 
               onclick="switchMobilePhoto(<?= $i ?>)" 
               id="mobThumb-<?= $i ?>"
               alt="Thumbnail <?= $i ?>">
        <?php endfor; ?>
      </div>
    </div>

    <!-- 2-COLUMN SPLIT LAYOUT -->
    <div class="row g-3 g-lg-4">

      <!-- LEFT COLUMN: Room Information & Amenities (8 Columns on Desktop) -->
      <div class="col-lg-8">

        <!-- QUICK SPECIFICATIONS ROW (2x2 on Mobile, 4x1 on Tablet/Desktop) -->
        <div class="row g-2 g-md-3 mb-3 mb-md-4">
          <div class="col-6 col-md-3">
            <div class="quick-spec-box">
              <div class="quick-spec-icon"><i class="fa-solid fa-users"></i></div>
              <div class="overflow-hidden">
                <small class="text-muted d-block text-uppercase fw-semibold" style="font-size:0.7rem;">CAPACITY</small>
                <strong class="text-dark d-block text-truncate" style="font-size:0.88rem;"><?= $isFourPerson ? '4 Guests' : '2 Guests' ?></strong>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="quick-spec-box">
              <div class="quick-spec-icon"><i class="fa-solid fa-bed"></i></div>
              <div class="overflow-hidden">
                <small class="text-muted d-block text-uppercase fw-semibold" style="font-size:0.7rem;">BED TYPE</small>
                <strong class="text-dark d-block text-truncate" style="font-size:0.88rem;"><?= $isFourPerson ? 'Double Beds' : 'King Bed' ?></strong>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="quick-spec-box">
              <div class="quick-spec-icon"><i class="fa-solid fa-bath"></i></div>
              <div class="overflow-hidden">
                <small class="text-muted d-block text-uppercase fw-semibold" style="font-size:0.7rem;">BATHROOM</small>
                <strong class="text-dark d-block text-truncate" style="font-size:0.88rem;"><?= ($cottageName === 'রুপকথা') ? 'Adjacent' : 'Attached' ?></strong>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="quick-spec-box">
              <div class="quick-spec-icon"><i class="fa-solid fa-compass"></i></div>
              <div class="overflow-hidden">
                <small class="text-muted d-block text-uppercase fw-semibold" style="font-size:0.7rem;">VIEW</small>
                <strong class="text-dark d-block text-truncate" style="font-size:0.88rem;"><?= $isFourPerson ? 'Garden View' : 'Sea View' ?></strong>
              </div>
            </div>
          </div>
        </div>

        <!-- COTTAGE OVERVIEW & EXPERIENCE -->
        <div class="details-white-card">
          <h4 class="fw-bold text-dark mb-3"><i class="fa-solid fa-feather text-success me-2"></i> About <?= htmlspecialchars($cottageName) ?></h4>
          <p class="text-secondary leading-relaxed mb-3">
            Experience authentic Saint Martin island living in our premium handcrafted wooden eco-cottage. Built with natural island timber, elevated verandas, and designed for optimal ocean ventilation, <strong><?= htmlspecialchars($cottageName) ?></strong> offers a tranquil sanctuary just steps away from the turquoise waters of the Bay of Bengal.
          </p>
          <p class="text-secondary leading-relaxed mb-0">
            Enjoy your morning tea on the private balcony surrounded by swaying coconut palms, feel the refreshing sea breeze, and fall asleep to the rhythmic melody of ocean waves. Our 24/7 dedicated resort team ensures prompt room service and warm island hospitality throughout your stay.
          </p>
        </div>

        <!-- VIDEO TOUR SECTION -->
        <div class="details-white-card">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
              <h4 class="fw-bold text-dark mb-1"><i class="fa-solid fa-video text-success me-2"></i> Video Tour</h4>
              <small class="text-muted">Take a virtual walk inside <?= htmlspecialchars($cottageName) ?>.</small>
            </div>
            <span class="badge bg-danger text-white rounded-pill px-3 py-1 fw-bold">
              <i class="fa-solid fa-play me-1"></i> HD Video
            </span>
          </div>

          <div class="video-tour-box">
            <video controls playsinline preload="metadata" poster="assets/img/cottages/<?= $folder ?>/1.jpg">
              <source src="assets/img/cottages/<?= $folder ?>/video.mp4" type="video/mp4">
              Your browser does not support the video tour.
            </video>
          </div>
        </div>

        <!-- ROOM FACILITIES & AMENITIES (2-Columns on Mobile, 3 on Desktop) -->
        <div class="details-white-card">
          <h4 class="fw-bold text-dark mb-3 mb-md-4"><i class="fa-solid fa-sparkles text-success me-2"></i> Room Facilities & Amenities</h4>

          <div class="row g-2 g-md-3">
            <div class="col-6 col-md-6 col-lg-4">
              <div class="amenity-chip">
                <div class="amenity-icon-circle"><i class="fa-solid fa-users"></i></div>
                <div class="text-truncate"><?= $capacityText ?></div>
              </div>
            </div>
            <div class="col-6 col-md-6 col-lg-4">
              <div class="amenity-chip">
                <div class="amenity-icon-circle"><i class="fa-solid fa-shower"></i></div>
                <div class="text-truncate"><?= $bathLabel ?></div>
              </div>
            </div>
            <div class="col-6 col-md-6 col-lg-4">
              <div class="amenity-chip">
                <div class="amenity-icon-circle"><i class="fa-solid fa-tree-city"></i></div>
                <div class="text-truncate">Private Balcony</div>
              </div>
            </div>
            <div class="col-6 col-md-6 col-lg-4">
              <div class="amenity-chip">
                <div class="amenity-icon-circle"><i class="fa-solid fa-glass-water"></i></div>
                <div class="text-truncate">Welcome Drinks</div>
              </div>
            </div>
            <div class="col-6 col-md-6 col-lg-4">
              <div class="amenity-chip">
                <div class="amenity-icon-circle"><i class="fa-solid fa-bottle-water"></i></div>
                <div class="text-truncate">Mineral Water</div>
              </div>
            </div>
            <div class="col-6 col-md-6 col-lg-4">
              <div class="amenity-chip">
                <div class="amenity-icon-circle"><i class="fa-solid fa-pump-soap"></i></div>
                <div class="text-truncate">Toiletries & Soap</div>
              </div>
            </div>
            <div class="col-6 col-md-6 col-lg-4">
              <div class="amenity-chip">
                <div class="amenity-icon-circle"><i class="fa-solid fa-rug"></i></div>
                <div class="text-truncate">Plush Towels</div>
              </div>
            </div>
            <div class="col-6 col-md-6 col-lg-4">
              <div class="amenity-chip">
                <div class="amenity-icon-circle"><i class="fa-solid fa-bolt"></i></div>
                <div class="text-truncate">24h Electricity</div>
              </div>
            </div>
            <div class="col-6 col-md-6 col-lg-4">
              <div class="amenity-chip">
                <div class="amenity-icon-circle"><i class="fa-solid fa-bell-concierge"></i></div>
                <div class="text-truncate">24/7 Room Service</div>
              </div>
            </div>
            <div class="col-6 col-md-6 col-lg-4">
              <div class="amenity-chip">
                <div class="amenity-icon-circle"><i class="fa-solid fa-utensils"></i></div>
                <div class="text-truncate">Beach Restaurant</div>
              </div>
            </div>
            <div class="col-6 col-md-6 col-lg-4">
              <div class="amenity-chip">
                <div class="amenity-icon-circle"><i class="fa-solid fa-wifi"></i></div>
                <div class="text-truncate">Resort Wi-Fi</div>
              </div>
            </div>
            <div class="col-6 col-md-6 col-lg-4">
              <div class="amenity-chip">
                <div class="amenity-icon-circle"><i class="fa-solid fa-umbrella-beach"></i></div>
                <div class="text-truncate">Beach Lounge</div>
              </div>
            </div>
          </div>
        </div>

        <!-- RESORT POLICIES & GUIDELINES -->
        <div class="details-white-card">
          <h4 class="fw-bold text-dark mb-3"><i class="fa-solid fa-shield-halved text-success me-2"></i> Stay Guidelines & Policies</h4>
          <div class="row g-2 g-md-3 text-secondary" style="font-size:0.9rem;">
            <div class="col-sm-6">
              <div class="p-3 bg-light rounded-3 h-100">
                <strong class="text-dark d-block mb-1"><i class="fa-regular fa-clock text-success me-1"></i> Check-in Time:</strong>
                <span>12:00 PM (Early check-in subject to room availability)</span>
              </div>
            </div>
            <div class="col-sm-6">
              <div class="p-3 bg-light rounded-3 h-100">
                <strong class="text-dark d-block mb-1"><i class="fa-regular fa-clock text-danger me-1"></i> Check-out Time:</strong>
                <span>10:00 AM (Late check-out on prior management notice)</span>
              </div>
            </div>
            <div class="col-12">
              <div class="p-3 bg-light rounded-3">
                <strong class="text-dark d-block mb-1"><i class="fa-solid fa-credit-card text-primary me-1"></i> Advance Deposit Policy:</strong>
                <span>To confirm your cottage reservation, an advance deposit of <strong>৳ 2,040</strong> per cottage/night is required via bKash or Nagad. The remaining balance can be cleared upon arrival at the front desk. <span class="text-danger fw-bold">Advance Booking Money is non-refundable.</span></span>
              </div>
            </div>
          </div>
        </div>

      </div>

      <!-- RIGHT COLUMN: Sticky Live Booking & Pricing Card (4 Columns on Desktop) -->
      <div class="col-lg-4">
        <div class="sticky-booking-card" id="bookingCard">
          
          <!-- Pricing Header -->
          <div class="booking-card-header">
            <span class="text-white-50 text-uppercase small fw-bold tracking-wide d-block mb-1">RESERVATION RATE</span>
            <div class="d-flex align-items-baseline gap-2">
              <span class="rate-big" id="headerStayPrice">৳ <?= number_format($stayPricing['total_price']) ?></span>
              <span class="text-white-50" id="headerRateUnit"><?= $stayPricing['total_nights'] > 1 ? '/ total (' . $stayPricing['total_nights'] . ' nights)' : '/ night' ?></span>
            </div>
            <div class="d-flex align-items-center gap-2 mt-2 pt-2 border-top border-white border-opacity-10 small">
              <span class="text-white-50">Weekday: ৳<?= number_format($weekdayPrice) ?></span> &bull; 
              <span class="text-white-50">Weekend: ৳<?= number_format($weekendPrice) ?></span>
            </div>
            <div class="mt-2" id="headerStayBreakdown">
              <?php if ($stayPricing['rate_type_badge'] === 'Weekend Rate'): ?>
                <span class="badge bg-warning text-dark"><i class="fa-solid fa-fire me-1"></i> Weekend Rate Active</span>
              <?php elseif ($stayPricing['rate_type_badge'] === 'Mixed Rate'): ?>
                <span class="badge bg-primary text-white"><i class="fa-solid fa-calculator me-1"></i> <?= htmlspecialchars($stayPricing['breakdown_text']) ?></span>
              <?php else: ?>
                <span class="badge bg-white bg-opacity-25 text-white border border-white border-opacity-25"><i class="fa-regular fa-calendar me-1"></i> Weekday Rate</span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Form Body -->
          <div class="booking-card-body">
            
            <?php if (!$isAvailable): ?>
              <div class="alert alert-warning text-center mb-0">
                <i class="fa-solid fa-triangle-exclamation mb-2 fs-3 d-block"></i>
                <strong>Cottage Currently Unavailable</strong>
                <p class="small mb-0 mt-1">This cottage is undergoing maintenance. Please select another cottage or contact front desk.</p>
              </div>
            <?php else: ?>

              <form id="detailsBookingForm">
                <input type="hidden" name="cottage_id" value="<?= $cottageId ?>">
                <input type="hidden" name="cottage_name" value="<?= htmlspecialchars($cottageName) ?>">

                <!-- Check-in & Check-out Dates -->
                <div class="row g-2 mb-3">
                  <div class="col-6">
                    <label class="form-label text-muted fw-bold small text-uppercase mb-1" style="font-size:0.72rem;">CHECK-IN</label>
                    <input type="text" id="bookingCheckIn" name="check_in" class="form-control form-control-sm date-picker-input fw-semibold" placeholder="Arrival Date" value="<?= htmlspecialchars($checkin) ?>" required>
                  </div>
                  <div class="col-6">
                    <label class="form-label text-muted fw-bold small text-uppercase mb-1" style="font-size:0.72rem;">CHECK-OUT</label>
                    <input type="text" id="bookingCheckOut" name="check_out" class="form-control form-control-sm date-picker-input fw-semibold" placeholder="Departure Date" value="<?= htmlspecialchars($checkout) ?>" required>
                  </div>
                </div>

                <!-- Guest Name -->
                <div class="mb-3">
                  <label class="form-label text-muted fw-bold small text-uppercase mb-1" style="font-size:0.72rem;">GUEST FULL NAME <span class="text-danger">*</span></label>
                  <input type="text" name="customer_name" class="form-control" placeholder="Enter full name" required>
                </div>

                <!-- Phone Number -->
                <div class="mb-3">
                  <label class="form-label text-muted fw-bold small text-uppercase mb-1" style="font-size:0.72rem;">PHONE / MOBILE NUMBER <span class="text-danger">*</span></label>
                  <input type="tel" name="customer_phone" class="form-control" placeholder="01XXXXXXXXX" required>
                </div>

                <!-- Email Address (Optional) -->
                <div class="mb-3">
                  <label class="form-label text-muted fw-bold small text-uppercase mb-1" style="font-size:0.72rem;">EMAIL ADDRESS (OPTIONAL)</label>
                  <input type="email" name="customer_email" class="form-control" placeholder="name@example.com">
                </div>

                <!-- Special Notes -->
                <div class="mb-3">
                  <label class="form-label text-muted fw-bold small text-uppercase mb-1" style="font-size:0.72rem;">NOTES / SPECIAL REQUEST</label>
                  <input type="text" name="notes" class="form-control" placeholder="Honeymoon, extra bed, arrival time...">
                </div>

                <!-- Real-time Bill Calculation Breakdown -->
                <div class="calc-breakdown-box" id="calcBreakdownBox">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted small" id="calcNightsLabel"><?= htmlspecialchars($stayPricing['breakdown_text'] ?: ($stayPricing['total_nights'] . ' Night × ৳' . number_format($stayPricing['avg_price']))) ?></span>
                    <strong class="text-dark" id="calcSubtotalAmount">৳ <?= number_format($stayPricing['total_price']) ?></strong>
                  </div>
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted small">Advance Deposit:</span>
                    <strong class="text-success" id="calcAdvancePayable">৳ <?= number_format($stayPricing['total_nights'] * 2040) ?></strong>
                  </div>
                  <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                    <span class="fw-bold text-dark">Estimated Total:</span>
                    <span class="fw-bold fs-6 text-success" id="calcTotalBill">৳ <?= number_format($stayPricing['total_price']) ?></span>
                  </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" id="submitBookingBtn" class="btn btn-reserve-cottage w-100">
                  <i class="fa-regular fa-calendar-check me-2"></i> Confirm Reservation
                </button>

                <!-- Advance Payment Instructions -->
                <div class="advance-notice-box mt-3">
                  <div class="d-flex align-items-start gap-2">
                    <i class="fa-solid fa-circle-info text-warning mt-1"></i>
                    <div>
                      <strong>Advance Payment Notice:</strong>
                      <p class="mb-0 mt-1">
                        Send at least <strong>৳ 2,040</strong> per night via bKash / Nagad to <strong>01817-838383</strong> (Personal) within 3 hours to confirm booking.
                      </p>
                    </div>
                  </div>
                </div>

                <!-- Non-Refundable Policy Notice -->
                <div class="alert alert-danger bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-3 py-2 px-3 small mt-3 mb-0 text-center fw-bold">
                  <i class="fa-solid fa-circle-exclamation me-1"></i> Advance Booking Money is non-refundable
                </div>

                <!-- Reception Quick Hotline -->
                <div class="text-center mt-3 pt-2">
                  <small class="text-muted d-block mb-1">Need instant verification or custom package?</small>
                  <a href="tel:+8801817838383" class="text-decoration-none fw-bold text-success">
                    <i class="fa-solid fa-phone me-1"></i> Call Reception: 01817-838383
                  </a>
                </div>

              </form>

            <?php endif; ?>

          </div>
        </div>
      </div>

    </div>

    <!-- =================== EXPLORE OTHER COTTAGES =================== -->
    <?php if (!empty($otherCottages)): ?>
      <div class="mt-4 mt-md-5 pt-4 border-top">
        <div class="d-flex justify-content-between align-items-center mb-3 mb-md-4 flex-wrap gap-2">
          <div>
            <h3 class="fw-bold text-dark mb-1" style="font-size:clamp(1.2rem, 3vw, 1.6rem);">Explore Other Cottages</h3>
            <p class="text-muted small mb-0">Discover more beachfront wooden villas at Eco Divine Beach Resort.</p>
          </div>
          <a href="packages.php" class="btn btn-outline-success btn-sm rounded-pill px-3 py-1 fw-semibold">
            View All Cottages &rarr;
          </a>
        </div>

        <div class="row g-3 g-md-4">
          <?php foreach ($otherCottages as $oc): 
            $ocFolder = $folderMap[$oc['title']] ?? 'tilottoma';
            $ocIsWk = ((int)($oc['is_weekend'] ?? 0) === 1);
            $ocRate = $ocIsWk ? ($oc['weekend_price'] ?? $oc['price']) : $oc['price'];
          ?>
            <div class="col-12 col-sm-6 col-md-4">
              <div class="recom-card h-100 d-flex flex-column">
                <img src="assets/img/cottages/<?= $ocFolder ?>/1.jpg" alt="<?= htmlspecialchars($oc['title']) ?>" class="recom-img">
                <div class="p-3 d-flex flex-column flex-grow-1">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="fw-bold text-dark mb-0"><?= htmlspecialchars($oc['title']) ?></h5>
                    <span class="text-success fw-bold">৳ <?= number_format($ocRate) ?><small class="text-muted fw-normal">/nt</small></span>
                  </div>
                  <small class="text-muted mb-3"><i class="fa-solid fa-umbrella-beach me-1 text-primary"></i> Saint Martin Beachfront Cottage</small>
                  <a href="details.php?id=<?= $oc['id'] ?>&checkin=<?= urlencode($checkin) ?>&checkout=<?= urlencode($checkout) ?>" class="btn btn-sm btn-outline-success rounded-pill mt-auto w-100 fw-semibold">
                    View Details &amp; Reserve
                  </a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

<!-- ========================================================
     FLOATING MOBILE BOOKING BOTTOM BAR (Mobile Only)
     ======================================================== -->
<div class="mobile-booking-floating-bar d-lg-none">
  <div class="container d-flex align-items-center justify-content-between">
    <div>
      <div class="d-flex align-items-baseline gap-1">
        <span class="mobile-price-val" id="mobilePriceVal">৳ <?= number_format($stayPricing['total_price']) ?></span>
        <span class="text-muted small" id="mobilePriceUnit"><?= $stayPricing['total_nights'] > 1 ? '(' . $stayPricing['total_nights'] . 'n)' : '/ night' ?></span>
      </div>
      <small class="text-muted d-block" style="font-size: 0.72rem;" id="mobileRateType">
        <?php if ($stayPricing['rate_type_badge'] === 'Weekend Rate'): ?>
          <span class="text-warning fw-bold"><i class="fa-solid fa-fire me-1"></i>Weekend Rate</span>
        <?php elseif ($stayPricing['rate_type_badge'] === 'Mixed Rate'): ?>
          <span class="text-primary fw-bold"><i class="fa-solid fa-calculator me-1"></i>Mixed Rate</span>
        <?php else: ?>
          <span>Regular Weekday</span>
        <?php endif; ?>
      </small>
    </div>
    <div>
      <a href="#bookingCard" class="btn btn-success rounded-pill px-4 py-2 fw-bold shadow-sm" id="mobileFloatingBookBtn">
        <i class="fa-regular fa-calendar-check me-1"></i> Book Now
      </a>
    </div>
  </div>
</div>

<!-- ========================================================
     MODAL 1: FULL RESOLUTION PHOTO GALLERY LIGHTBOX
     ======================================================== -->
<div class="modal fade" id="cottagePhotoModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden; background: #0b131e;">
      <div class="modal-header border-0 pb-0 px-3 px-md-4 pt-3 d-flex justify-content-between align-items-center">
        <h5 class="modal-title text-white fw-bold fs-6 fs-md-5">
          <i class="fa-solid fa-images text-success me-2"></i> <?= htmlspecialchars($cottageName) ?> - Photos
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body p-3 p-md-4 text-center">
        <!-- Big Active Photo -->
        <div class="mb-3" style="max-height: 55vh; overflow: hidden; border-radius: 14px;">
          <img id="modalMainPhoto" src="assets/img/cottages/<?= $folder ?>/1.jpg" class="img-fluid rounded shadow" style="max-height: 52vh; width: 100%; object-fit: contain;" alt="Showcase Photo">
        </div>

        <!-- 7 Thumbnails Strip with Touch Horizontal Scroll -->
        <div class="modal-thumbs-strip">
          <?php for ($i = 1; $i <= 7; $i++): ?>
            <img src="assets/img/cottages/<?= $folder ?>/<?= $i ?>.jpg" 
                 class="photo-thumb-item" 
                 onclick="switchModalPhoto(<?= $i ?>)" 
                 id="thumb-<?= $i ?>"
                 alt="Thumbnail <?= $i ?>">
          <?php endfor; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ========================================================
     MODAL 2: SUCCESS RESERVATION CONFIRMATION MODAL
     ======================================================== -->
<div class="modal fade" id="bookingSuccessModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg text-center p-3 p-md-4" style="border-radius: 24px;">
      
      <div class="success-check-bubble">
        <i class="fa-solid fa-check"></i>
      </div>

      <h3 class="fw-bold text-dark mb-1 fs-4">Reservation Received!</h3>
      <p class="text-muted small mb-3">
        আপনার বুকিং রিকোয়েস্টটি সফলভাবে গ্রহণ করা হয়েছে।
      </p>

      <!-- Booking Code Pill -->
      <div class="p-3 mb-3 rounded-4" style="background:#f0fdf4; border: 1.5px dashed #86efac;">
        <span class="text-muted small d-block mb-1">BOOKING REFERENCE CODE</span>
        <h4 class="fw-bold text-success mb-0" id="successBookingCode">ECO-0000</h4>
        <small class="text-dark d-block mt-2">
          <strong>Cottage:</strong> <?= htmlspecialchars($cottageName) ?> &bull; 
          <strong id="successTotalAmount">৳ <?= number_format($currentPrice) ?></strong>
        </small>
      </div>

      <!-- Advance Payment Guide -->
      <div class="p-3 text-start rounded-3 bg-light border mb-3 mb-md-4" style="font-size:0.84rem;">
        <strong class="text-dark d-block mb-1"><i class="fa-solid fa-mobile-screen text-success me-1"></i> অগ্রিম পেমেন্ট নির্দেশিকা:</strong>
        <p class="mb-1 text-secondary">
          বুকিং নিশ্চিত করতে আগামী ৩ ঘণ্টার মধ্যে প্রতি রাতের জন্য নূন্যতম <strong>৳ ২,০৪০</strong> বিকাশ/নগদে পাঠিয়ে দিন:
        </p>
        <div class="fw-bold text-dark fs-6 my-1">
          📱 bKash / Nagad: <span class="text-success">01817-838383</span> (Personal)
        </div>
        <small class="text-muted">রেফারেন্সে আপনার বুকিং কোডটি উল্লেখ করুন।</small>
      </div>

      <!-- Action Buttons (Stacked on mobile, row on tablet/desktop) -->
      <div class="d-flex flex-column flex-sm-row gap-2">
        <a href="tel:+8801817838383" class="btn btn-outline-success flex-grow-1 rounded-pill py-2 fw-semibold">
          <i class="fa-solid fa-phone me-1"></i> Call Reception
        </a>
        <a id="whatsappConfirmBtn" href="https://wa.me/01333838389?text=Hello%20Resort%20Team%2C%20I%20just%20submitted%20booking%20for%20<?= urlencode($cottageName) ?>" target="_blank" class="btn btn-success flex-grow-1 rounded-pill py-2 fw-semibold" style="background:#25D366; border-color:#25D366;">
          <i class="fa-brands fa-whatsapp me-1"></i> WhatsApp
        </a>
      </div>

      <div class="mt-3">
        <a href="index.php" class="text-muted small text-decoration-none">
          <i class="fa-solid fa-arrow-left me-1"></i> Return to Homepage
        </a>
      </div>

    </div>
  </div>
</div>

<!-- ========================================================
     CLIENT-SIDE JAVASCRIPT: FLATPICKR, PRICING, & AJAX
     ======================================================== -->
<script>
const WEEKDAY_PRICE = <?= $weekdayPrice ?>;
const WEEKEND_PRICE = <?= $weekendPrice ?>;
const COTTAGE_FOLDER = "<?= $folder ?>";

document.addEventListener("DOMContentLoaded", function () {
  const checkInInput = document.getElementById("bookingCheckIn");
  const checkOutInput = document.getElementById("bookingCheckOut");

  // Flatpickr initialization with mobile support & auto-fetch
  if (checkInInput && checkOutInput && typeof flatpickr !== "undefined") {
    const urlParams = new URLSearchParams(window.location.search);
    const hasUrlDates = urlParams.has('checkin') || urlParams.has('check_in');
    
    let defaultIn = checkInInput.value;
    let defaultOut = checkOutInput.value;

    if (!hasUrlDates) {
      const storedIn = localStorage.getItem("eco_check_in");
      const storedOut = localStorage.getItem("eco_check_out");
      if (storedIn && (!storedOut || storedOut > storedIn)) {
        defaultIn = storedIn;
        if (storedOut) defaultOut = storedOut;
      }
    }

    const fpOut = flatpickr(checkOutInput, {
      altInput: true,
      altFormat: "d M, Y",
      dateFormat: "Y-m-d",
      minDate: new Date().fp_incr(1),
      disableMobile: true,
      defaultDate: defaultOut || undefined,
      onChange: function (selectedDates, dateStr) {
        if (dateStr) {
          localStorage.setItem("eco_check_out", dateStr);
        }
        updateCalculatedBill();
      }
    });

    const fpIn = flatpickr(checkInInput, {
      altInput: true,
      altFormat: "d M, Y",
      dateFormat: "Y-m-d",
      minDate: "today",
      disableMobile: true,
      defaultDate: defaultIn || undefined,
      onChange: function (selectedDates, dateStr) {
        if (dateStr) {
          localStorage.setItem("eco_check_in", dateStr);
        }
        if (selectedDates.length) {
          const nextDay = new Date(selectedDates[0].getTime() + 24 * 60 * 60 * 1000);
          fpOut.set("minDate", nextDay);
          if (fpOut.selectedDates.length && fpOut.selectedDates[0] <= selectedDates[0]) {
            fpOut.setDate(nextDay);
            const yyyy = nextDay.getFullYear();
            const mm = String(nextDay.getMonth() + 1).padStart(2, '0');
            const dd = String(nextDay.getDate()).padStart(2, '0');
            localStorage.setItem("eco_check_out", `${yyyy}-${mm}-${dd}`);
          }
        }
        updateCalculatedBill();
      }
    });

    if (checkInInput.value) localStorage.setItem("eco_check_in", checkInInput.value);
    if (checkOutInput.value) localStorage.setItem("eco_check_out", checkOutInput.value);
  }

  // Initial Calculation
  updateCalculatedBill();

  // Floating mobile "Book Now" scroll handler
  const mobileFloatingBtn = document.getElementById("mobileFloatingBookBtn");
  if (mobileFloatingBtn) {
    mobileFloatingBtn.addEventListener("click", function (e) {
      e.preventDefault();
      const card = document.getElementById("bookingCard");
      if (card) {
        card.scrollIntoView({ behavior: "smooth", block: "center" });
        card.classList.add("booking-card-highlight");
        setTimeout(() => card.classList.remove("booking-card-highlight"), 1800);
      }
    });
  }

  // Handle Form Submission via AJAX to handle_booking.php
  const bookingForm = document.getElementById("detailsBookingForm");
  if (bookingForm) {
    bookingForm.addEventListener("submit", function (e) {
      e.preventDefault();

      const submitBtn = document.getElementById("submitBookingBtn");
      const originalText = submitBtn.innerHTML;
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin me-2"></i> Submitting Reservation...';

      const formData = new FormData(bookingForm);

      fetch("handle_booking.php", {
        method: "POST",
        body: formData
      })
      .then(res => {
        if (!res.ok) throw new Error("HTTP " + res.status);
        return res.json();
      })
      .then(data => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;

        if (data.success) {
          document.getElementById("successBookingCode").innerText = data.booking_code || ("ECO" + data.booking_id);
          if (data.total_amount) {
            document.getElementById("successTotalAmount").innerText = "৳ " + Number(data.total_amount).toLocaleString();
          }

          const waBtn = document.getElementById("whatsappConfirmBtn");
          if (waBtn) {
            const waText = `Hello Eco Divine Team, I have booked ${data.cottage_name} (Code: ${data.booking_code}). I would like to verify payment.`;
            waBtn.href = `https://wa.me/01333838389?text=${encodeURIComponent(waText)}`;
          }

          const modalElem = document.getElementById("bookingSuccessModal");
          const bsModal = new bootstrap.Modal(modalElem);
          bsModal.show();

          bookingForm.reset();
        } else {
          alert("⚠️ " + (data.message || "বুকিং সম্পন্ন করা যায়নি।"));
        }
      })
      .catch(err => {
        console.error("Booking submission error:", err);
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        alert("❌ নেটওয়ার্ক বা সার্ভার সমস্যা হয়েছে। দয়া করে সরাসরি আমাদের নম্বরে কল করুন: 01817-838383");
      });
    });
  }
});

// Calculate dynamic stay pricing night-by-night
function calculateStayPricingJS(inDateStr, outDateStr, weekdayPrice, weekendPrice) {
  let nights = 1;
  let weekdayNights = 0;
  let weekendNights = 0;
  let totalPrice = 0;

  if (inDateStr && outDateStr) {
    const inParts = inDateStr.split('-').map(Number);
    const outParts = outDateStr.split('-').map(Number);
    if (inParts.length === 3 && outParts.length === 3) {
      const d1 = new Date(inParts[0], inParts[1] - 1, inParts[2]);
      const d2 = new Date(outParts[0], outParts[1] - 1, outParts[2]);

      if (d2 > d1) {
        nights = Math.max(1, Math.round((d2 - d1) / (1000 * 60 * 60 * 24)));
        const cur = new Date(d1.getTime());
        while (cur < d2) {
          const day = cur.getDay(); // 0: Sun, 1: Mon, ..., 5: Fri, 6: Sat
          if (day === 5 || day === 6) {
            weekendNights++;
            totalPrice += weekendPrice;
          } else {
            weekdayNights++;
            totalPrice += weekdayPrice;
          }
          cur.setDate(cur.getDate() + 1);
        }
      }
    }
  }

  if (weekdayNights === 0 && weekendNights === 0) {
    weekdayNights = 1;
    totalPrice = weekdayPrice;
  }

  let rateTypeBadge = 'Weekday Rate';
  let breakdownText = '';
  if (weekendNights > 0 && weekdayNights > 0) {
    rateTypeBadge = 'Mixed Rate';
    breakdownText = `${weekdayNights} Wkday (৳${weekdayPrice.toLocaleString()}) + ${weekendNights} Wkend (৳${weekendPrice.toLocaleString()})`;
  } else if (weekendNights > 0) {
    rateTypeBadge = 'Weekend Rate';
    breakdownText = nights > 1 ? `${weekendNights} Weekend Nights (৳${weekendPrice.toLocaleString()}/n)` : `Weekend Night (৳${weekendPrice.toLocaleString()})`;
  } else {
    rateTypeBadge = 'Weekday Rate';
    breakdownText = nights > 1 ? `${weekdayNights} Weekday Nights (৳${weekdayPrice.toLocaleString()}/n)` : `Weekday Night (৳${weekdayPrice.toLocaleString()})`;
  }

  return {
    totalNights: nights,
    weekdayNights,
    weekendNights,
    totalPrice,
    breakdownText,
    rateTypeBadge
  };
}

// Update live price calculation
function updateCalculatedBill() {
  const inVal = document.getElementById("bookingCheckIn")?.value;
  const outVal = document.getElementById("bookingCheckOut")?.value;

  const pricing = calculateStayPricingJS(inVal, outVal, WEEKDAY_PRICE, WEEKEND_PRICE);
  const advance = pricing.totalNights * 2040;

  const nightsLabel = document.getElementById("calcNightsLabel");
  const subtotalEl = document.getElementById("calcSubtotalAmount");
  const advanceEl = document.getElementById("calcAdvancePayable");
  const totalEl = document.getElementById("calcTotalBill");

  if (nightsLabel) nightsLabel.innerText = pricing.breakdownText;
  if (subtotalEl) subtotalEl.innerText = `৳ ${pricing.totalPrice.toLocaleString()}`;
  if (advanceEl) advanceEl.innerText = `৳ ${advance.toLocaleString()}`;
  if (totalEl) totalEl.innerText = `৳ ${pricing.totalPrice.toLocaleString()}`;

  // Update Sticky Card Header
  const headerStayPrice = document.getElementById("headerStayPrice");
  const headerRateUnit = document.getElementById("headerRateUnit");
  const headerStayBreakdown = document.getElementById("headerStayBreakdown");
  if (headerStayPrice) headerStayPrice.innerText = `৳ ${pricing.totalPrice.toLocaleString()}`;
  if (headerRateUnit) headerRateUnit.innerText = pricing.totalNights > 1 ? `/ total (${pricing.totalNights} nights)` : '/ night';
  if (headerStayBreakdown) {
    if (pricing.rateTypeBadge === 'Weekend Rate') {
      headerStayBreakdown.innerHTML = `<span class="badge bg-warning text-dark"><i class="fa-solid fa-fire me-1"></i> Weekend Rate Active</span>`;
    } else if (pricing.rateTypeBadge === 'Mixed Rate') {
      headerStayBreakdown.innerHTML = `<span class="badge bg-primary text-white"><i class="fa-solid fa-calculator me-1"></i> ${pricing.breakdownText}</span>`;
    } else {
      headerStayBreakdown.innerHTML = `<span class="badge bg-white bg-opacity-25 text-white border border-white border-opacity-25"><i class="fa-regular fa-calendar me-1"></i> Weekday Rate</span>`;
    }
  }

  // Update Top Pill Badge
  const topWeekendBadge = document.getElementById("topWeekendBadge");
  if (topWeekendBadge) {
    if (pricing.rateTypeBadge === 'Weekend Rate') {
      topWeekendBadge.className = "cottage-meta-pill weekend-pill-active";
      topWeekendBadge.innerHTML = '<i class="fa-solid fa-fire text-warning"></i> Weekend Rate Active';
    } else if (pricing.rateTypeBadge === 'Mixed Rate') {
      topWeekendBadge.className = "cottage-meta-pill bg-primary bg-opacity-10 text-primary border-primary border-opacity-25";
      topWeekendBadge.innerHTML = '<i class="fa-solid fa-calculator text-primary"></i> Mixed Rate Active';
    } else {
      topWeekendBadge.className = "cottage-meta-pill text-muted border-secondary border-opacity-25";
      topWeekendBadge.innerHTML = '<i class="fa-regular fa-calendar"></i> Weekday Rate';
    }
  }

  // Update Mobile Floating Bottom Bar
  const mobilePriceVal = document.getElementById("mobilePriceVal");
  const mobilePriceUnit = document.getElementById("mobilePriceUnit");
  const mobileRateType = document.getElementById("mobileRateType");
  if (mobilePriceVal) mobilePriceVal.innerText = `৳ ${pricing.totalPrice.toLocaleString()}`;
  if (mobilePriceUnit) mobilePriceUnit.innerText = pricing.totalNights > 1 ? `(${pricing.totalNights}n)` : '/ night';
  if (mobileRateType) {
    if (pricing.rateTypeBadge === 'Weekend Rate') {
      mobileRateType.innerHTML = '<span class="text-warning fw-bold"><i class="fa-solid fa-fire me-1"></i>Weekend Rate</span>';
    } else if (pricing.rateTypeBadge === 'Mixed Rate') {
      mobileRateType.innerHTML = '<span class="text-primary fw-bold"><i class="fa-solid fa-calculator me-1"></i>Mixed Rate</span>';
    } else {
      mobileRateType.innerHTML = '<span>Regular Weekday</span>';
    }
  }
}

// Mobile Thumbnail Switcher
function switchMobilePhoto(index) {
  const heroImg = document.getElementById("mobileHeroPhoto");
  if (heroImg) {
    heroImg.src = `assets/img/cottages/${COTTAGE_FOLDER}/${index}.jpg`;
  }
  document.querySelectorAll(".mobile-gallery-thumb-item").forEach(el => {
    el.classList.remove("active-thumb");
  });
  const activeThumb = document.getElementById(`mobThumb-${index}`);
  if (activeThumb) {
    activeThumb.classList.add("active-thumb");
  }
}

// Photo Modal Functions
function openPhotoModal(imgIndex) {
  switchModalPhoto(imgIndex);
  const modalElem = document.getElementById("cottagePhotoModal");
  if (modalElem && typeof bootstrap !== "undefined") {
    const modal = new bootstrap.Modal(modalElem);
    modal.show();
  }
}

function switchModalPhoto(index) {
  const mainImg = document.getElementById("modalMainPhoto");
  if (mainImg) {
    mainImg.src = `assets/img/cottages/${COTTAGE_FOLDER}/${index}.jpg`;
  }
  document.querySelectorAll(".photo-thumb-item").forEach(el => {
    el.style.borderColor = "transparent";
  });
  const activeThumb = document.getElementById(`thumb-${index}`);
  if (activeThumb) {
    activeThumb.style.borderColor = "#059669";
    activeThumb.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
  }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
