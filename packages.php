<?php 
include('header.php'); 
$getCheckIn = $_GET['check_in'] ?? $_GET['checkin'] ?? '';
$getCheckOut = $_GET['check_out'] ?? $_GET['checkout'] ?? '';
$getType = $_GET['type'] ?? 'all';
?>

<!-- =================== PACKAGES HERO & AVAILABILITY BAR =================== -->
<section class="packages-hero-section" style="background-image: linear-gradient(rgba(15,23,42,0.65), rgba(15,23,42,0.75)), url('assets/img/gallery/slide2.jpg');">
  <div class="container text-center py-5">
    <div class="packages-badge mb-2" data-aos="fade-down">
      <i class="fa-solid fa-umbrella-beach text-success me-1"></i> Eco Divine Beach Resort &bull; Saint Martin Island
    </div>
    <h1 class="text-white fw-bold mb-3 packages-hero-title" data-aos="fade-up">
      Check Available Cottages
    </h1>
    <p class="text-white-50 fs-6 mb-4 packages-hero-sub" data-aos="fade-up" data-aos-delay="100">
      Select your arrival and departure dates to verify live room availability and best direct rates.
    </p>

    <!-- Availability Search Bar -->
    <div class="packages-search-card shadow-lg" data-aos="zoom-in" data-aos-delay="150">
      <div class="row g-3 align-items-end justify-content-center">
        <!-- Check-in Field -->
        <div class="col-12 col-md-4 text-start">
          <label for="checkIn" class="packages-field-label">
            <i class="fa-regular fa-calendar-check text-success"></i> CHECK-IN DATE
          </label>
          <div class="position-relative">
            <input type="text" id="checkIn" class="form-control packages-date-input" placeholder="Select Check In Date" value="<?= htmlspecialchars($getCheckIn) ?>" readonly required>
            <i class="fa-solid fa-calendar-days packages-input-icon text-success"></i>
          </div>
        </div>

        <!-- Check-out Field -->
        <div class="col-12 col-md-4 text-start">
          <label for="checkOut" class="packages-field-label">
            <i class="fa-regular fa-calendar-xmark text-danger"></i> CHECK-OUT DATE
          </label>
          <div class="position-relative">
            <input type="text" id="checkOut" class="form-control packages-date-input" placeholder="Select Check Out Date" value="<?= htmlspecialchars($getCheckOut) ?>" readonly required>
            <i class="fa-solid fa-calendar-days packages-input-icon text-danger"></i>
          </div>
        </div>

        <!-- Search Action Button -->
        <div class="col-12 col-md-4">
          <button id="checkBtn" class="btn packages-search-btn w-100">
            <i class="fa-solid fa-magnifying-glass me-2"></i> Check Availability
          </button>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- =================== AVAILABLE COTTAGES RESULTS =================== -->
<section class="packages-results-section py-5" id="resultsSection">
  <div class="container">
    <div id="noSelection" class="text-center py-5">
      <div class="no-selection-box p-4 rounded-4 bg-light shadow-sm d-inline-block">
        <i class="fa-regular fa-calendar-days text-success display-5 mb-3 d-block"></i>
        <h4 class="fw-bold text-dark mb-2">Ready to Plan Your Stay?</h4>
        <p class="text-muted mb-0">Please select your check-in and check-out dates above to view live cottage availability.</p>
      </div>
    </div>

    <!-- Active Filter/Dates Banner -->
    <div id="activeDatesBanner" class="mb-4" style="display:none;"></div>

    <!-- Cottages Container -->
    <div class="row g-4" id="cottagesContainer" style="display:none;"></div>
  </div>
</section>

<!-- =================== STYLES =================== -->
<style>
.packages-hero-section {
  background-size: cover;
  background-position: center;
  position: relative;
  color: #fff;
  padding: 80px 0 65px;
}

.packages-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: rgba(16, 185, 129, 0.18);
  color: #34d399;
  border: 1px solid rgba(52, 211, 153, 0.35);
  font-size: 0.8rem;
  font-weight: 700;
  letter-spacing: 1px;
  text-transform: uppercase;
  padding: 5px 16px;
  border-radius: 30px;
}

.packages-hero-title {
  font-size: 2.6rem;
  letter-spacing: -0.5px;
}

.packages-hero-sub {
  max-width: 620px;
  margin: 0 auto;
}

.packages-search-card {
  max-width: 860px;
  margin: 25px auto 0;
  background: #ffffff;
  border-radius: 20px;
  padding: 24px 28px;
  box-shadow: 0 20px 45px rgba(0, 0, 0, 0.25);
}

.packages-field-label {
  font-size: 0.74rem;
  font-weight: 700;
  letter-spacing: 1px;
  text-transform: uppercase;
  color: #475569;
  margin-bottom: 6px;
  display: flex;
  align-items: center;
  gap: 6px;
}

.packages-date-input {
  padding: 12px 38px 12px 14px;
  border: 1.5px solid #cbd5e1;
  border-radius: 12px;
  font-size: 0.95rem;
  font-weight: 600;
  color: #0f172a;
  background-color: #f8fafc;
  cursor: pointer;
  transition: all 0.25s ease;
}

.packages-date-input:focus,
.packages-date-input:hover {
  border-color: #059669;
  background-color: #ffffff;
  box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15);
  outline: none;
}

.packages-input-icon {
  position: absolute;
  right: 14px;
  top: 50%;
  transform: translateY(-50%);
  pointer-events: none;
  font-size: 0.95rem;
}

.packages-search-btn {
  background: linear-gradient(135deg, #059669 0%, #10b981 100%);
  color: #ffffff;
  font-size: 1rem;
  font-weight: 700;
  padding: 12px 24px;
  border-radius: 12px;
  border: none;
  box-shadow: 0 8px 22px rgba(5, 150, 105, 0.35);
  transition: all 0.3s ease;
}

.packages-search-btn:hover {
  background: linear-gradient(135deg, #047857 0%, #059669 100%);
  color: #ffffff;
  transform: translateY(-2px);
  box-shadow: 0 12px 26px rgba(5, 150, 105, 0.45);
}

.packages-results-section {
  background: #f8fafc;
  min-height: 450px;
}

.cottage-card {
  transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.cottage-card:hover {
  transform: translateY(-6px);
}

.cottage-card .card {
  border-radius: 18px;
  overflow: hidden;
  border: 1px solid #e2e8f0;
  box-shadow: 0 6px 20px rgba(15, 23, 42, 0.06);
  transition: all 0.3s ease;
}

.cottage-card:hover .card {
  box-shadow: 0 16px 32px rgba(15, 23, 42, 0.12);
  border-color: #cbd5e1;
}

@media(max-width:768px){
  .packages-hero-title { font-size: 1.85rem; }
  .packages-search-card { padding: 18px 16px; }
  .packages-hero-section { padding: 60px 0 45px; }
}
</style>

<?php include('footer.php'); ?>

<!-- =================== AVAILABILITY SCRIPT =================== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  const checkInInput = document.getElementById('checkIn');
  const checkOutInput = document.getElementById('checkOut');
  const checkBtn = document.getElementById('checkBtn');
  const cottagesContainer = document.getElementById('cottagesContainer');
  const noSelection = document.getElementById('noSelection');
  const activeDatesBanner = document.getElementById('activeDatesBanner');

  // Initialize Flatpickr
  const fpIn = flatpickr(checkInInput, {
    altInput: true,
    altInputClass: "form-control packages-date-input",
    altFormat: "D, d M Y",
    dateFormat: "Y-m-d",
    minDate: "today",
    disableMobile: true,
    onChange: function(selectedDates) {
      if (selectedDates.length) {
        const nextDay = new Date(selectedDates[0].getTime() + 24*60*60*1000);
        fpOut.set('minDate', nextDay);
        const outVal = checkOutInput.value;
        if (outVal && new Date(outVal) <= selectedDates[0]) {
          fpOut.setDate(nextDay, true);
        }
      }
    }
  });

  const fpOut = flatpickr(checkOutInput, {
    altInput: true,
    altInputClass: "form-control packages-date-input",
    altFormat: "D, d M Y",
    dateFormat: "Y-m-d",
    disableMobile: true,
    minDate: new Date().fp_incr(1)
  });

  // Core function to check availability and render results
  function executeAvailabilitySearch(checkIn, checkOut, type = 'all', autoScroll = false) {
    if (!checkIn || !checkOut) {
      alert('⚠️ Please select both check-in and check-out dates!');
      return;
    }

    if (new Date(checkIn) >= new Date(checkOut)) {
      alert('⚠️ Check-out must be after check-in.');
      return;
    }

    try {
      localStorage.setItem('eco_check_in', checkIn);
      localStorage.setItem('eco_check_out', checkOut);
    } catch(e) {}

    // UI Loading state
    cottagesContainer.style.display = 'flex';
    noSelection.style.display = 'none';
    cottagesContainer.innerHTML = `
      <div class="col-12 text-center py-5">
        <div class="spinner-border text-success mb-3" role="status" style="width: 3rem; height: 3rem;">
          <span class="visually-hidden">Loading...</span>
        </div>
        <h4 class="fw-bold text-dark">Checking Room Availability...</h4>
        <p class="text-muted">Searching live bookings for <b>${checkIn}</b> to <b>${checkOut}</b></p>
      </div>
    `;

    fetch('fetch_cottages.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: `check_in=${encodeURIComponent(checkIn)}&check_out=${encodeURIComponent(checkOut)}`
    })
    .then(response => response.json())
    .then(data => {
      cottagesContainer.innerHTML = '';
      if (data.error) {
        cottagesContainer.innerHTML = `<div class="col-12"><div class="alert alert-danger py-4 text-center">⚠️ ${data.error}</div></div>`;
        return;
      }
      if (!Array.isArray(data) || data.length === 0) {
        cottagesContainer.innerHTML = `
          <div class="col-12">
            <div class="alert alert-warning py-4 text-center">
              ❌ No cottages found for these dates. Please try another date range.
            </div>
          </div>
        `;
        return;
      }

      // Filter by type if specified (e.g. 'sea', 'side-sea', 'garden', 'couple', 'family')
      let filteredData = data;
      if (type === 'couple') {
        filteredData = data.filter(c => c.id == 1 || c.id == 2 || c.id == 3 || c.id == 4 || c.id == 6 || c.id == 9);
      } else if (type === 'family') {
        filteredData = data.filter(c => c.id == 5 || c.id == 7 || c.id == 8 || c.id == 10 || c.id == 11 || c.id == 12);
      } else if (type === 'sea') {
        filteredData = data.filter(c => c.id == 1 || c.id == 2 || c.id == 3 || c.id == 4);
      } else if (type === 'side-sea') {
        filteredData = data.filter(c => c.id == 5 || c.id == 6);
      } else if (type === 'garden') {
        filteredData = data.filter(c => c.id == 7 || c.id == 8 || c.id == 9 || c.id == 10 || c.id == 11 || c.id == 12);
      }

      const availableCount = filteredData.filter(c => c.availability !== 'booked').length;
      const bookedCount = filteredData.filter(c => c.availability === 'booked').length;

      const nightsCount = (data[0] && data[0].total_nights) ? data[0].total_nights : Math.max(1, Math.round((new Date(checkOut) - new Date(checkIn)) / (1000 * 60 * 60 * 24)));

      // Render summary banner
      if (activeDatesBanner) {
        activeDatesBanner.style.display = 'block';
        activeDatesBanner.innerHTML = `
          <div class="alert alert-success d-flex align-items-center justify-content-between flex-wrap gap-2 py-3 px-4 rounded-4 shadow-sm border-0 mb-0">
            <div class="d-flex align-items-center gap-3">
              <div class="bg-success text-white rounded-circle p-2 d-flex align-items-center justify-content-center" style="width:38px; height:38px;">
                <i class="fa-solid fa-calendar-check"></i>
              </div>
              <div>
                <span class="text-muted small d-block">Search Dates Confirmed</span>
                <strong class="text-dark">${checkIn} &rarr; ${checkOut} <span class="badge bg-success-subtle text-success border border-success border-opacity-25 ms-1">${nightsCount} Night${nightsCount > 1 ? 's' : ''}</span></strong>
              </div>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-success py-2 px-3 fs-6 rounded-pill">${availableCount} Available</span>
              ${bookedCount > 0 ? `<span class="badge bg-danger py-2 px-3 fs-6 rounded-pill">${bookedCount} Booked</span>` : ''}
            </div>
          </div>
        `;
      }

      // Render cottage cards
      filteredData.forEach(cottage => {
        const img = cottage.img ? cottage.img : 'assets/img/default.jpg';
        const title = cottage.title ? cottage.title : 'Cottage';
        const price = cottage.price ? cottage.price : 'N/A';
        const weekend = cottage.weekend_price ? cottage.weekend_price : price;

        let actionBtn = '';
        let badgeHtml = '';
        if (cottage.availability && cottage.availability === 'booked') {
          actionBtn = `<button class="btn btn-secondary btn-sm w-100 py-2 rounded-3" disabled><i class="fa-solid fa-lock me-1"></i> Already Booked</button>`;
          badgeHtml = `<span class="badge bg-danger position-absolute top-0 end-0 m-3 px-3 py-2 rounded-pill shadow-sm"><i class="fa-solid fa-ban me-1"></i> Booked</span>`;
        } else if (cottage.availability && cottage.availability === 'unavailable') {
          actionBtn = `<button class="btn btn-secondary btn-sm w-100 py-2 rounded-3" disabled><i class="fa-solid fa-wrench me-1"></i> Under Maintenance</button>`;
          badgeHtml = `<span class="badge bg-secondary position-absolute top-0 end-0 m-3 px-3 py-2 rounded-pill shadow-sm"><i class="fa-solid fa-wrench me-1"></i> Unavailable</span>`;
        } else {
          actionBtn = `<a href="details.php?id=${cottage.id}&name=${encodeURIComponent(title)}&checkin=${encodeURIComponent(checkIn)}&checkout=${encodeURIComponent(checkOut)}" class="btn btn-success btn-sm w-100 py-2 rounded-3 fw-bold"><i class="fa-regular fa-calendar-check me-1"></i> View & Reserve</a>`;
          badgeHtml = `<span class="badge bg-success position-absolute top-0 end-0 m-3 px-3 py-2 rounded-pill shadow-sm"><i class="fa-solid fa-circle-check me-1"></i> Available</span>`;
        }

        let rateBadgeHtml = '';
        if (cottage.rate_type_badge === 'Weekend Rate') {
          rateBadgeHtml = `<span class="badge bg-warning text-dark px-2 py-1 mb-1 shadow-sm"><i class="fa-solid fa-fire me-1"></i> Weekend Rate Active</span>`;
        } else if (cottage.rate_type_badge === 'Mixed Rate') {
          rateBadgeHtml = `<span class="badge bg-primary text-white px-2 py-1 mb-1 shadow-sm"><i class="fa-solid fa-calculator me-1"></i> Weekday + Weekend Rate</span>`;
        } else {
          rateBadgeHtml = `<span class="badge bg-light text-muted border px-2 py-1 mb-1"><i class="fa-regular fa-calendar me-1"></i> Weekday Rate</span>`;
        }

        const totalNights = cottage.total_nights || 1;
        let priceDisplay = '';
        let detailSubtext = '';

        if (totalNights > 1) {
          const formattedTotal = Number(cottage.total_price || (cottage.price * totalNights)).toLocaleString();
          priceDisplay = `৳${formattedTotal} <small class="text-muted fs-6 fw-normal">total (${totalNights} nights)</small>`;
          detailSubtext = cottage.breakdown_text 
            ? `<div class="text-muted mt-1" style="font-size:0.75rem;"><i class="fa-solid fa-circle-info me-1 text-primary"></i>${cottage.breakdown_text}</div>`
            : `<small class="text-muted" style="font-size:0.75rem;">Weekday: ৳${cottage.price} | Weekend: ৳${cottage.weekend_price}</small>`;
        } else {
          const singleNightPrice = Number(cottage.total_price || (cottage.is_weekend ? cottage.weekend_price : cottage.price)).toLocaleString();
          priceDisplay = `৳${singleNightPrice} <small class="text-muted fs-6 fw-normal">/ night</small>`;
          detailSubtext = `<small class="text-muted" style="font-size:0.75rem;">Weekday: ৳${cottage.price} | Weekend: ৳${cottage.weekend_price}</small>`;
        }

        const priceHtml = `
          <div class="mb-2">
            ${rateBadgeHtml}
            <div class="fs-5 fw-bold text-success">${priceDisplay}</div>
            ${detailSubtext}
          </div>
        `;

        cottagesContainer.innerHTML += `
          <div class="col-12 col-sm-6 col-lg-3 text-center cottage-card" data-aos="zoom-in">
            <div class="card shadow-sm border-0 h-100 position-relative rounded-4 overflow-hidden">
              ${badgeHtml}
              <img src="${img}" class="card-img-top" style="height:200px; object-fit:cover;" alt="${title}" loading="lazy">
              <div class="card-body d-flex flex-column justify-content-between p-3">
                <div>
                  <h5 class="card-title mb-2 fw-bold text-dark fs-5">${title}</h5>
                  ${priceHtml}
                </div>
                <div>${actionBtn}</div>
              </div>
            </div>
          </div>`;
      });

      // Smooth scroll if autoScroll is enabled
      if (autoScroll) {
        setTimeout(() => {
          const resultsElem = document.getElementById('resultsSection');
          if (resultsElem) {
            resultsElem.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }, 150);
      }
    })
    .catch(error => {
      cottagesContainer.innerHTML = "<div class='col-12'><p class='text-center py-4 text-danger'>⚠️ Error loading cottages. Please try again.</p></div>";
      console.error('Error:', error);
    });
  }

  // Trigger search on button click
  checkBtn.addEventListener('click', function() {
    const inVal = checkInInput.value;
    const outVal = checkOutInput.value;
    executeAvailabilitySearch(inVal, outVal, 'all', true);
  });

  // AUTO-EXECUTION from URL parameters (e.g., from index.php search bar)
  const urlParams = new URLSearchParams(window.location.search);
  const paramIn = urlParams.get('check_in') || urlParams.get('checkin');
  const paramOut = urlParams.get('check_out') || urlParams.get('checkout');
  const paramType = urlParams.get('type') || 'all';

  if (paramIn && paramOut) {
    fpIn.setDate(paramIn, true);
    fpOut.setDate(paramOut, true);
    // Execute availability search automatically!
    executeAvailabilitySearch(paramIn, paramOut, paramType, true);
  }
});
</script>
