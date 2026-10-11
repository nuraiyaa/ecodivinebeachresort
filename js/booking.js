// js/booking.js - Eco Divine Beach Resort Booking Form Handler
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('bookingFormContent');
  if (!form) return;

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    const submitBtn = form.querySelector('button[type="submit"]');
    const originalBtnText = submitBtn ? submitBtn.innerHTML : 'Confirm Booking';
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin me-2"></i> Processing...';
    }

    const formData = new FormData(form);

    // Post to handle_booking.php in root
    fetch('handle_booking.php', {
      method: 'POST',
      body: formData
    })
    .then(res => {
      if (!res.ok) {
        throw new Error('Server returned HTTP ' + res.status);
      }
      return res.json();
    })
    .then(data => {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
      }

      if (data.success) {
        // Hide form modal if open
        const bookingPopup = document.getElementById('bookingForm');
        if (bookingPopup) bookingPopup.style.display = 'none';

        // Populate and show success modal
        const successModalElem = document.getElementById('bookingSuccessModal');
        if (successModalElem && typeof bootstrap !== 'undefined') {
          const codeEl = document.getElementById('successBookingCode');
          if (codeEl) codeEl.innerText = data.booking_code || ('ECO' + data.booking_id);
          
          const totalEl = document.getElementById('successTotalAmount');
          if (totalEl && data.total_amount) totalEl.innerText = '৳ ' + Number(data.total_amount).toLocaleString();

          const bsModal = new bootstrap.Modal(successModalElem);
          bsModal.show();
        } else {
          // Fallback popup if bootstrap modal not present
          const popup = document.getElementById('successPopup');
          if (popup) {
            popup.style.display = 'flex';
          } else {
            alert('✅ ' + data.message + '\nBooking ID: ' + (data.booking_code || data.booking_id));
          }
        }
        form.reset();
      } else {
        alert('⚠️ ' + (data.message || 'বুকিং সম্পন্ন করা সম্ভব হয়নি।'));
      }
    })
    .catch(err => {
      console.error('Booking Error:', err);
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
      }
      alert('❌ নেটওয়ার্ক বা সার্ভার ত্রুটি! দয়া করে সরাসরি আমাদের রিসোর্ট নম্বরে কল করুন: 01817-838383');
    });
  });

  // Close button
  const closeBtn = document.getElementById('closeForm');
  if (closeBtn) {
    closeBtn.addEventListener('click', () => {
      const bookingPopup = document.getElementById('bookingForm');
      if (bookingPopup) bookingPopup.style.display = 'none';
    });
  }
});
