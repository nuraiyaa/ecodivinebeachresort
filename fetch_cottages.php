<?php
// fetch_cottages.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

// DB connection (admin/includes/db.php relative to this file)
$dbPath = __DIR__ . '/admin/includes/db.php';
if (!file_exists($dbPath)) {
    echo json_encode(["error" => "DB connection file not found."]);
    exit;
}
include($dbPath); // provides $conn (mysqli) & pricing_helper.php

// Input check
if (!isset($_POST['check_in']) || !isset($_POST['check_out'])) {
    echo json_encode(["error" => "Missing date parameters."]);
    exit;
}

$check_in = trim($_POST['check_in']);
$check_out = trim($_POST['check_out']);

// Validate date format (basic)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $check_in) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $check_out)) {
    echo json_encode(["error" => "Invalid date format. Use YYYY-MM-DD."]);
    exit;
}

// Ensure check_in < check_out
if (strtotime($check_in) >= strtotime($check_out)) {
    echo json_encode(["error" => "Check-out must be after check-in."]);
    exit;
}

// 1) Get list of cottages in serial sequence
$q = "SELECT id, title, price, weekend_price, is_weekend, img, status FROM cottages ORDER BY FIELD(title, 'অরুনিকা', 'তিলোত্তমা', 'ছায়াবীথি', 'নয়নতারা', 'জলসা', 'রুপকথা', 'কোরাল-০১', 'কোরাল-০২', 'লবস্টার-০৩', 'লবস্টার-০৪', 'রূপচাঁদা-০৫', 'রূপচাঁদা-০৬'), id ASC";
$res = $conn->query($q);
if (!$res) {
    echo json_encode(["error" => "Cottage query failed", "details" => $conn->error]);
    exit;
}

$cottages = [];
while ($r = $res->fetch_assoc()) {
    $img = !empty($r['img']) ? "assets/img/" . $r['img'] : "assets/img/default.jpg";
    $stayPricing = calculateStayPricing($check_in, $check_out, $r['price'], $r['weekend_price']);
    $defaultAvailability = ($r['status'] === 'inactive') ? 'unavailable' : 'available';

    $cottages[$r['id']] = [
        "id"              => (int)$r['id'],
        "title"           => $r['title'],
        "price"           => (int)$r['price'],
        "weekend_price"   => (int)($r['weekend_price'] > 0 ? $r['weekend_price'] : $r['price']),
        "is_weekend"      => !empty($stayPricing['is_weekend']),
        "is_all_weekend"  => !empty($stayPricing['is_all_weekend']),
        "has_weekend"     => !empty($stayPricing['has_weekend']),
        "has_weekday"     => !empty($stayPricing['has_weekday']),
        "total_nights"    => (int)($stayPricing['total_nights'] ?? 1),
        "weekday_nights"  => (int)($stayPricing['weekday_nights'] ?? 0),
        "weekend_nights"  => (int)($stayPricing['weekend_nights'] ?? 0),
        "total_price"     => (int)($stayPricing['total_price'] ?? $r['price']),
        "avg_price"       => (int)($stayPricing['avg_price'] ?? $r['price']),
        "effective_price" => (int)($stayPricing['total_price'] ?? $r['price']),
        "display_price"   => (int)($stayPricing['total_price'] ?? $r['price']),
        "breakdown_text"  => $stayPricing['breakdown_text'] ?? '',
        "rate_type_badge" => $stayPricing['rate_type_badge'] ?? 'Weekday Rate',
        "night_details"   => $stayPricing['night_details'] ?? [],
        "img"             => $img,
        "status"          => $r['status'] ?? 'active',
        "availability"    => $defaultAvailability
    ];
}

// 2) Find booked cottage ids that overlap requested period and status = accepted
$sql = "SELECT DISTINCT cottage_id FROM bookings 
        WHERE status = 'accepted' AND check_in < ? AND check_out > ?
        UNION
        SELECT DISTINCT bc.cottage_id FROM booking_cottages bc
        JOIN bookings b ON bc.booking_id = b.id
        WHERE b.status = 'accepted' AND b.check_in < ? AND b.check_out > ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(["error" => "Prepare failed: " . $conn->error]);
    exit;
}
$stmt->bind_param("ssss", $check_out, $check_in, $check_out, $check_in);
$stmt->execute();
$res2 = $stmt->get_result();

$bookedIds = [];
while ($row = $res2->fetch_assoc()) {
    $bookedIds[] = (int)$row['cottage_id'];
}
$stmt->close();

// 3) Mark availability for each cottage
foreach ($bookedIds as $bid) {
    if (isset($cottages[$bid])) {
        $cottages[$bid]['availability'] = 'booked';
    }
}

// 4) Return as indexed array (not associative by id)
$output = array_values($cottages);

if (ob_get_length()) {
    ob_clean();
}
echo json_encode($output, JSON_UNESCAPED_UNICODE);
exit;
