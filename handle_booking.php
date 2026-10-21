<?php
// handle_booking.php - Guest Booking Request Handler for Eco Divine Beach Resort
header('Content-Type: application/json; charset=utf-8');

// Include DB
if (file_exists(__DIR__ . '/admin/includes/db.php')) {
    require_once __DIR__ . '/admin/includes/db.php';
} elseif (file_exists(__DIR__ . '/includes/db.php')) {
    require_once __DIR__ . '/includes/db.php';
} else {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Extract inputs
$name       = trim($_POST['name'] ?? $_POST['customer_name'] ?? '');
$phone      = trim($_POST['mobile'] ?? $_POST['phone'] ?? $_POST['customer_phone'] ?? '');
$email      = trim($_POST['email'] ?? $_POST['customer_email'] ?? '');
$cottage_id = intval($_POST['cottage_id'] ?? 0);
$check_in   = trim($_POST['check_in'] ?? '');
$check_out  = trim($_POST['check_out'] ?? '');
$notes      = trim($_POST['notes'] ?? '');

// Fallback cottage_id by name if not provided
if ($cottage_id <= 0 && !empty($_POST['cottage_name'])) {
    $c_name = $conn->real_escape_string($_POST['cottage_name']);
    $c_res = $conn->query("SELECT id FROM cottages WHERE title = '$c_name' LIMIT 1");
    if ($c_res && $c_row = $c_res->fetch_assoc()) {
        $cottage_id = intval($c_row['id']);
    }
}

// Validation
if (empty($name) || empty($phone) || empty($check_in) || empty($check_out) || $cottage_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'দয়া করে সকল প্রয়োজনীয় তথ্য (নাম, মোবাইল নম্বর, চেক-ইন ও চেক-আউট তারিখ) পূরণ করুন।'
    ]);
    exit;
}

// Fetch cottage details to calculate total
$stmt_c = $conn->prepare("SELECT id, title, price, weekend_price, is_weekend FROM cottages WHERE id = ?");
$stmt_c->bind_param("i", $cottage_id);
$stmt_c->execute();
$cottage = $stmt_c->get_result()->fetch_assoc();
$stmt_c->close();

if (!$cottage) {
    echo json_encode(['success' => false, 'message' => 'নির্বাচিত কটেজ পাওয়া যায়নি।']);
    exit;
}

// Calculate nights & safeguard dates
try {
    $cin = new DateTime($check_in);
    $cout = new DateTime($check_out);
    if ($cout <= $cin) {
        $cout = (clone $cin)->modify('+1 day');
        $check_out = $cout->format('Y-m-d');
    }
    $diff = $cin->diff($cout)->days;
    $nights = max(1, $diff);
} catch (Exception $e) {
    $nights = 1;
}

// Determine stay pricing dynamically (accounting for night-by-night weekday & weekend rates)
$weekdayPrice = floatval($cottage['price'] ?? 0);
$weekendPrice = floatval($cottage['weekend_price'] ?? $weekdayPrice);
$stayPricing  = calculateStayPricing($check_in, $check_out, $weekdayPrice, $weekendPrice);

$nights         = $stayPricing['total_nights'];
$ratePerNight   = $stayPricing['avg_price'];
$total_amount   = $stayPricing['total_price'];
$advance_amount = 0.00;
$due_amount     = $total_amount;
$status         = 'pending';

// Insert booking
$stmt = $conn->prepare("INSERT INTO bookings (cottage_id, customer_name, customer_email, customer_phone, check_in, check_out, total_amount, advance_amount, due_amount, notes, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
$stmt->bind_param("isssssdddss", $cottage_id, $name, $email, $phone, $check_in, $check_out, $total_amount, $advance_amount, $due_amount, $notes, $status);

if ($stmt->execute()) {
    $booking_id = $stmt->insert_id;
    $stmt->close();
    $booking_code = 'ECO' . str_pad($booking_id, 4, '0', STR_PAD_LEFT);

    echo json_encode([
        'success'        => true,
        'booking_id'     => $booking_id,
        'booking_code'   => $booking_code,
        'cottage_name'   => $cottage['title'],
        'nights'         => $nights,
        'weekday_nights' => $stayPricing['weekday_nights'],
        'weekend_nights' => $stayPricing['weekend_nights'],
        'rate_per_night' => $ratePerNight,
        'total_amount'   => $total_amount,
        'breakdown_text' => $stayPricing['breakdown_text'],
        'message'        => 'আপনার বুকিং রিকোয়েস্ট সফলভাবে গ্রহণ করা হয়েছে!'
    ]);
    exit;
} else {
    echo json_encode([
        'success' => false,
        'message' => 'বুকিং সংরক্ষণে সমস্যা হয়েছে: ' . $conn->error
    ]);
    exit;
}
