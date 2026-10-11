<?php
// admin/invoice.php
// Official Printable & Downloadable Invoice Receipt for Eco Divine Beach Resort
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

require_once __DIR__ . '/includes/db.php';
date_default_timezone_set('Asia/Dhaka');

$booking_id  = intval($_GET['booking_id'] ?? $_GET['id'] ?? 0);
$customer_id = intval($_GET['customer_id'] ?? 0);

$booking  = null;
$customer = null;
$cottages = [];

// 1. Fetch via booking_id if provided
if ($booking_id > 0) {
    $stmt = $conn->prepare("
        SELECT b.*, 
               c.title AS primary_cottage_title, 
               c.price AS primary_cottage_price, 
               c.weekend_price AS primary_cottage_weekend_price,
               c.is_weekend,
               a.name AS staff_name
        FROM bookings b
        LEFT JOIN cottages c ON b.cottage_id = c.id
        LEFT JOIN admins a ON b.accepted_by = a.id
        WHERE b.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($booking) {
        // Fetch all assigned cottages from booking_cottages
        $bc_res = $conn->query("
            SELECT bc.*, c.title, c.price, c.weekend_price, c.is_weekend 
            FROM booking_cottages bc
            JOIN cottages c ON bc.cottage_id = c.id
            WHERE bc.booking_id = {$booking['id']}
        ");
        if ($bc_res && $bc_res->num_rows > 0) {
            while ($brow = $bc_res->fetch_assoc()) {
                $cottages[] = $brow;
            }
        }

        // Also fetch customer ledger record if synced
        $b_code = 'ECO' . str_pad($booking['id'], 4, '0', STR_PAD_LEFT);
        $cust_stmt = $conn->prepare("SELECT * FROM customers WHERE booking_id = ? OR (phone = ? AND check_in = ?) LIMIT 1");
        $cust_stmt->bind_param("sss", $b_code, $booking['customer_phone'], $booking['check_in']);
        $cust_stmt->execute();
        $customer = $cust_stmt->get_result()->fetch_assoc();
        $cust_stmt->close();
    }
}

// 2. Fetch via customer_id if booking wasn't matched yet
if (!$booking && $customer_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($customer) {
        // Try to match corresponding booking
        $raw_bcode = trim($customer['booking_id'] ?? '');
        $numeric_bid = intval(preg_replace('/[^0-9]/', '', $raw_bcode));
        if ($numeric_bid > 0) {
            $b_stmt = $conn->prepare("
                SELECT b.*, 
                       c.title AS primary_cottage_title, 
                       c.price AS primary_cottage_price, 
                       c.weekend_price AS primary_cottage_weekend_price,
                       c.is_weekend,
                       a.name AS staff_name
                FROM bookings b
                LEFT JOIN cottages c ON b.cottage_id = c.id
                LEFT JOIN admins a ON b.accepted_by = a.id
                WHERE b.id = ? LIMIT 1
            ");
            $b_stmt->bind_param("i", $numeric_bid);
            $b_stmt->execute();
            $booking = $b_stmt->get_result()->fetch_assoc();
            $b_stmt->close();

            if ($booking) {
                $bc_res = $conn->query("
                    SELECT bc.*, c.title, c.price, c.weekend_price, c.is_weekend 
                    FROM booking_cottages bc
                    JOIN cottages c ON bc.cottage_id = c.id
                    WHERE bc.booking_id = {$booking['id']}
                ");
                if ($bc_res && $bc_res->num_rows > 0) {
                    while ($brow = $bc_res->fetch_assoc()) {
                        $cottages[] = $brow;
                    }
                }
            }
        }
    }
}

// If neither found, display clean error
if (!$booking && !$customer) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <title>Invoice Not Found &bull; Eco Divine Resort</title>
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
      <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    </head>
    <body class="bg-light d-flex align-items-center justify-content-center min-vh-100 p-4">
      <div class="card border-0 shadow-sm p-4 text-center" style="max-width: 460px; border-radius: 18px;">
        <div class="rounded-circle bg-danger bg-opacity-10 text-danger d-inline-flex align-items-center justify-content-center mx-auto mb-3" style="width: 60px; height: 60px; font-size: 1.8rem;">
          <i class="fa-solid fa-file-circle-xmark"></i>
        </div>
        <h4 class="fw-bold text-dark">Invoice Record Not Found</h4>
        <p class="text-muted small mb-4">No matching booking or customer payment record was found for the specified ID.</p>
        <a href="dashboard.php#view-earnings" class="btn btn-success rounded-pill px-4">
          <i class="fa-solid fa-arrow-left me-1"></i> Return to Payments & Earnings
        </a>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// Consolidate Data
$guest_name    = $booking['customer_name'] ?? $customer['name'] ?? 'Resort Guest';
$guest_phone   = $booking['customer_phone'] ?? $customer['phone'] ?? '';
$guest_email   = $booking['customer_email'] ?? $customer['email'] ?? '';
$check_in      = $booking['check_in'] ?? $customer['check_in'] ?? date('Y-m-d');
$check_out     = $booking['check_out'] ?? $customer['check_out'] ?? date('Y-m-d', strtotime('+1 day'));
$total_amount  = floatval($booking['total_amount'] ?? $customer['total_amount'] ?? 0);
$advance_paid  = floatval($booking['advance_amount'] ?? $customer['advance_amount'] ?? 0);
$discount      = floatval($booking['discount_amount'] ?? $customer['discount_amount'] ?? 0);
$due_amount    = floatval($booking['due_amount'] ?? $customer['due_amount'] ?? 0);
$payment_method= $booking['payment_method'] ?? $customer['payment_method'] ?? 'Cash at Front Desk';
$notes         = $booking['notes'] ?? $customer['notes'] ?? '';
$created_date  = $booking['created_at'] ?? $customer['created_at'] ?? date('Y-m-d H:i:s');
$staff_handler = $booking['staff_name'] ?? 'Authorized Staff';

// Determine Invoice Ref
$invoice_code = '';
if ($booking && !empty($booking['id'])) {
    $invoice_code = 'INV-ECO-' . str_pad($booking['id'], 4, '0', STR_PAD_LEFT);
} elseif ($customer && !empty($customer['booking_id'])) {
    $invoice_code = 'INV-' . $customer['booking_id'];
} else {
    $invoice_code = 'INV-ECO-' . str_pad($customer['id'] ?? 1, 4, '0', STR_PAD_LEFT);
}

// Calculate Nights
$cin_dt  = new DateTime($check_in);
$cout_dt = new DateTime($check_out);
$nights  = max(1, $cin_dt->diff($cout_dt)->days);

// Check if fully paid
$is_fully_paid = ($due_amount <= 0 && $total_amount > 0);
$collected_total = max(0, $total_amount - $due_amount);

// Cottages names
$cottage_title_list = [];
if (!empty($cottages)) {
    foreach ($cottages as $c) {
        $cottage_title_list[] = $c['title'];
    }
} elseif (!empty($booking['primary_cottage_title'])) {
    $cottage_title_list[] = $booking['primary_cottage_title'];
} elseif (!empty($customer['cottage_name'])) {
    $cottage_title_list[] = $customer['cottage_name'];
} else {
    $cottage_title_list[] = 'Resort Deluxe Cottage';
}
$cottage_summary_str = implode(', ', $cottage_title_list);

$autoprint = isset($_GET['autoprint']) && $_GET['autoprint'] == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($invoice_code) ?> &bull; Invoice Receipt &bull; Eco Divine Resort</title>
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
      --primary-color: #059669;
      --primary-hover: #047857;
      --text-dark: #0f172a;
      --text-body: #334155;
      --text-muted: #64748b;
      --border-color: #e2e8f0;
    }

    body {
      font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background-color: #f1f5f9;
      color: var(--text-body);
      min-height: 100vh;
      padding-bottom: 40px;
    }

    /* Action Bar (Top Screen Only) */
    .invoice-top-actions {
      background: #ffffff;
      border-bottom: 1px solid var(--border-color);
      padding: 14px 24px;
      position: sticky;
      top: 0;
      z-index: 100;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
    }

    /* Printable Sheet Container */
    .invoice-paper-sheet {
      background: #ffffff;
      max-width: 820px;
      margin: 30px auto;
      padding: 48px 54px;
      border-radius: 18px;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
      border: 1px solid var(--border-color);
      position: relative;
    }

    /* Paid Stamp */
    .paid-stamp-watermark {
      border: 3px solid #059669;
      color: #059669;
      font-weight: 800;
      font-size: 1.15rem;
      text-transform: uppercase;
      letter-spacing: 2.5px;
      padding: 8px 18px;
      border-radius: 12px;
      display: inline-block;
      transform: rotate(-5deg);
      background: #ecfdf5;
      box-shadow: 0 4px 12px rgba(5, 150, 105, 0.12);
    }

    .due-stamp-watermark {
      border: 3px solid #dc2626;
      color: #dc2626;
      font-weight: 800;
      font-size: 1.15rem;
      text-transform: uppercase;
      letter-spacing: 2px;
      padding: 8px 18px;
      border-radius: 12px;
      display: inline-block;
      transform: rotate(-5deg);
      background: #fef2f2;
    }

    .table-invoice {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      margin-top: 15px;
      margin-bottom: 20px;
    }

    .table-invoice th {
      background-color: #f8fafc;
      color: #475569;
      font-size: 0.78rem;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      font-weight: 700;
      padding: 12px 16px;
      border-top: 1px solid var(--border-color);
      border-bottom: 2px solid var(--border-color);
    }

    .table-invoice td {
      padding: 14px 16px;
      vertical-align: middle;
      border-bottom: 1px solid var(--border-color);
      font-size: 0.9rem;
    }

    /* Print Stylesheet Overrides */
    @media print {
      body {
        background: #ffffff !important;
        padding: 0 !important;
      }
      .invoice-top-actions {
        display: none !important;
      }
      .invoice-paper-sheet {
        margin: 0 !important;
        padding: 24px !important;
        max-width: 100% !important;
        border: none !important;
        box-shadow: none !important;
        border-radius: 0 !important;
      }
      @page {
        margin: 1.2cm;
        size: A4 portrait;
      }
    }
  </style>
</head>
<body>

  <!-- Top Action Toolbar -->
  <div class="invoice-top-actions d-print-none">
    <div class="container-fluid d-flex align-items-center justify-content-between flex-wrap gap-2">
      
      <div class="d-flex align-items-center gap-3">
        <a href="dashboard.php#view-earnings" class="btn btn-outline-secondary rounded-pill px-3 py-1 btn-sm d-flex align-items-center gap-2">
          <i class="fa-solid fa-arrow-left"></i>
          <span>Dashboard</span>
        </a>
        <div class="d-flex align-items-center gap-2">
          <span class="fw-bold text-dark fs-6"><?= htmlspecialchars($invoice_code) ?></span>
          <?php if ($is_fully_paid): ?>
            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2 py-1">
              <i class="fa-solid fa-circle-check me-1"></i> Paid in Full
            </span>
          <?php else: ?>
            <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 rounded-pill px-2 py-1">
              <i class="fa-solid fa-clock me-1"></i> Due: ৳ <?= number_format($due_amount, 2) ?>
            </span>
          <?php endif; ?>
        </div>
      </div>

      <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-outline-secondary rounded-pill px-3 py-1 btn-sm d-flex align-items-center gap-1" onclick="window.close()">
          <i class="fa-solid fa-xmark"></i> Close
        </button>
        <button type="button" class="btn btn-success rounded-pill px-4 py-1 btn-sm fw-bold shadow-sm d-flex align-items-center gap-2" onclick="window.print()">
          <i class="fa-solid fa-print"></i>
          <span>Print / Save as PDF</span>
        </button>
      </div>

    </div>
  </div>

  <!-- Main Invoice Sheet -->
  <div class="invoice-paper-sheet" id="invoiceSheet">

    <!-- Header & Resort Branding -->
    <div class="d-flex justify-content-between align-items-start border-bottom pb-4 mb-4 gap-4">
      
      <!-- Resort Identity -->
      <div class="d-flex align-items-start gap-3">
        <img src="../favicon.png" alt="Eco Divine Logo" class="rounded-3 shadow-sm border p-1" style="width: 58px; height: 58px; object-fit: contain; background: #ffffff;">
        <div>
          <h4 class="fw-bold text-dark mb-0" style="letter-spacing: -0.5px;">Eco Divine Beach Resort</h4>
          <div class="text-success small fw-semibold" style="letter-spacing: 0.5px;">ISLAND SANCTUARY & LUXURY COTTAGES</div>
          <div class="text-muted small mt-1" style="font-size: 0.78rem; line-height: 1.4;">
            West Beach, Saint Martin's Island, Cox's Bazar, Bangladesh<br>
            Hotline: +880 1817-838383 &bull; Web: www.ecodivine.com
          </div>
        </div>
      </div>

      <!-- Invoice Title & Stamp -->
      <div class="text-end">
        <div class="fs-4 fw-bold text-dark lh-1 mb-1">OFFICIAL RECEIPT</div>
        <div class="text-muted small fw-semibold">INVOICE: <span class="text-dark fw-bold"><?= htmlspecialchars($invoice_code) ?></span></div>
        <div class="text-muted small">Date: <?= date('d M, Y', strtotime($created_date)) ?></div>
        <div class="mt-2">
          <?php if ($is_fully_paid): ?>
            <div class="paid-stamp-watermark">
              <i class="fa-solid fa-circle-check me-1"></i> PAID IN FULL
            </div>
          <?php else: ?>
            <div class="due-stamp-watermark">
              <i class="fa-solid fa-hourglass-half me-1"></i> PARTIAL / DUE
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <!-- Bill To & Stay Summary Metadata -->
    <div class="row g-4 mb-4 pb-2">
      
      <!-- Guest Details -->
      <div class="col-sm-6">
        <div class="text-uppercase text-muted fw-bold mb-1" style="font-size: 0.72rem; letter-spacing: 0.8px;">
          <i class="fa-solid fa-user me-1 text-primary"></i> BILLED TO / GUEST INFO
        </div>
        <div class="fw-bold text-dark fs-6 mb-1"><?= htmlspecialchars($guest_name) ?></div>
        <div class="text-muted small d-flex align-items-center gap-1 mb-1">
          <i class="fa-solid fa-phone text-success" style="font-size: 0.75rem;"></i>
          <span class="fw-semibold text-dark"><?= htmlspecialchars($guest_phone) ?></span>
        </div>
        <?php if (!empty($guest_email)): ?>
          <div class="text-muted small d-flex align-items-center gap-1">
            <i class="fa-solid fa-envelope text-secondary" style="font-size: 0.75rem;"></i>
            <span><?= htmlspecialchars($guest_email) ?></span>
          </div>
        <?php endif; ?>
      </div>

      <!-- Stay Duration & Booking Details -->
      <div class="col-sm-6">
        <div class="text-uppercase text-muted fw-bold mb-1" style="font-size: 0.72rem; letter-spacing: 0.8px;">
          <i class="fa-solid fa-calendar-days me-1 text-success"></i> RESERVATION DETAILS
        </div>
        <div class="small text-dark mb-1">
          <span class="text-muted">Stay Dates:</span>
          <strong><?= date('d M Y', strtotime($check_in)) ?></strong> &rarr; <strong><?= date('d M Y', strtotime($check_out)) ?></strong>
        </div>
        <div class="small text-dark mb-1">
          <span class="text-muted">Total Nights:</span>
          <span class="badge bg-light text-dark border fw-bold"><?= $nights ?> Night<?= ($nights > 1 ? 's' : '') ?></span>
        </div>
        <div class="small text-dark">
          <span class="text-muted">Payment Mode:</span>
          <span class="fw-semibold text-dark"><i class="fa-solid fa-wallet text-secondary me-1"></i><?= htmlspecialchars($payment_method) ?></span>
        </div>
      </div>

    </div>

    <!-- Itemized Services / Cottage Table -->
    <table class="table-invoice">
      <thead>
        <tr>
          <th style="width: 45px;">#</th>
          <th>Accommodation / Cottage Description</th>
          <th class="text-center">Nights</th>
          <th class="text-end">Nightly Rate</th>
          <th class="text-end">Amount (৳)</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($cottages)): ?>
          <?php foreach ($cottages as $c_idx => $c_item): 
            $cRate = floatval($c_item['price'] ?? 0);
            $cLineTotal = $cRate * $nights;
          ?>
            <tr>
              <td class="text-muted fw-bold"><?= $c_idx + 1 ?></td>
              <td>
                <div class="fw-bold text-dark"><?= htmlspecialchars($c_item['title']) ?></div>
                <small class="text-muted">Eco Island Accommodation &bull; Ocean View</small>
              </td>
              <td class="text-center fw-semibold"><?= $nights ?></td>
              <td class="text-end">৳ <?= number_format($cRate, 2) ?></td>
              <td class="text-end fw-bold text-dark">৳ <?= number_format($cLineTotal, 2) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td class="text-muted fw-bold">1</td>
            <td>
              <div class="fw-bold text-dark"><?= htmlspecialchars($cottage_summary_str) ?></div>
              <small class="text-muted">Eco Island Accommodation &bull; Ocean View</small>
            </td>
            <td class="text-center fw-semibold"><?= $nights ?></td>
            <td class="text-end">৳ <?= number_format(($total_amount + $discount) / $nights, 2) ?></td>
            <td class="text-end fw-bold text-dark">৳ <?= number_format($total_amount + $discount, 2) ?></td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- Ledger & Financial Summary Breakdown -->
    <div class="row justify-content-end mb-4">
      <div class="col-sm-7 col-md-6">
        <div class="p-3 rounded-3" style="background: #f8fafc; border: 1px solid var(--border-color);">
          
          <div class="d-flex justify-content-between align-items-center mb-2 small">
            <span class="text-muted">Subtotal (Gross Billed):</span>
            <span class="fw-semibold text-dark">৳ <?= number_format($total_amount + $discount, 2) ?></span>
          </div>

          <?php if ($discount > 0): ?>
            <div class="d-flex justify-content-between align-items-center mb-2 small text-danger">
              <span>Promotional Discount:</span>
              <span class="fw-semibold">- ৳ <?= number_format($discount, 2) ?></span>
            </div>
          <?php endif; ?>

          <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
            <span class="fw-bold text-dark">Net Payable Total:</span>
            <span class="fw-bold text-dark fs-6">৳ <?= number_format($total_amount, 2) ?></span>
          </div>

          <div class="d-flex justify-content-between align-items-center mb-2 small">
            <span class="text-success fw-bold"><i class="fa-solid fa-arrow-down me-1"></i>Collected Amount:</span>
            <span class="fw-bold text-success">৳ <?= number_format($collected_total, 2) ?></span>
          </div>

          <div class="d-flex justify-content-between align-items-center pt-2 border-top">
            <span class="fw-bold <?= $is_fully_paid ? 'text-success' : 'text-danger' ?> text-uppercase small">
              <?= $is_fully_paid ? 'Cleared Outstanding Due:' : 'Remaining Due Amount:' ?>
            </span>
            <span class="fs-5 fw-bold <?= $is_fully_paid ? 'text-success' : 'text-danger' ?>">
              ৳ <?= number_format($due_amount, 2) ?>
            </span>
          </div>

        </div>
      </div>
    </div>

    <!-- Official Clearance Box (When Fully Paid) -->
    <?php if ($is_fully_paid): ?>
      <div class="alert alert-success d-flex align-items-center gap-3 p-3 mb-4 rounded-3 border-success border-opacity-25" style="background: #ecfdf5;">
        <div class="fs-2 text-success"><i class="fa-solid fa-circle-check"></i></div>
        <div>
          <div class="fw-bold text-dark">OFFICIAL SETTLEMENT CERTIFICATE</div>
          <div class="small text-muted">
            All dues for reservation <strong><?= htmlspecialchars($invoice_code) ?></strong> have been verified and fully collected. This receipt serves as official proof of payment.
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($notes)): ?>
      <div class="mb-4 p-3 rounded-3 bg-light border small">
        <strong class="text-dark"><i class="fa-solid fa-note-sticky text-warning me-1"></i> Remarks:</strong>
        <span class="text-muted"><?= htmlspecialchars($notes) ?></span>
      </div>
    <?php endif; ?>

    <!-- Signature & Footer -->
    <div class="pt-4 border-top mt-5">
      <div class="row align-items-end">
        <div class="col-7">
          <div class="text-muted small" style="font-size: 0.75rem; line-height: 1.5;">
            <strong>Important Policy:</strong> Check-in time is 01:00 PM and Check-out is 11:00 AM. Please present this invoice receipt at the resort reception desk upon arrival.
          </div>
          <div class="mt-2 text-success fw-bold" style="font-size: 0.8rem;">
            &hearts; Thank you for choosing Eco Divine Beach Resort, Saint Martin!
          </div>
        </div>
        <div class="col-5 text-end">
          <div class="d-inline-block text-center" style="min-width: 170px;">
            <div class="text-success fw-bold font-monospace mb-1" style="font-size: 0.78rem; opacity: 0.85;">
              [ APPROVED & STAMPED ]
            </div>
            <div class="border-bottom border-dark pb-1 mb-1" style="height: 30px;"></div>
            <div class="fw-bold text-dark small">Authorized Front Desk</div>
            <div class="text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars($staff_handler) ?></div>
          </div>
        </div>
      </div>
    </div>

  </div> <!-- /.invoice-paper-sheet -->

  <?php if ($autoprint): ?>
    <script>
      window.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
          window.print();
        }, 500);
      });
    </script>
  <?php endif; ?>

</body>
</html>
