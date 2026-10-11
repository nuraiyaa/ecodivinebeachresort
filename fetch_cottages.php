<?php
// fetch_cottages.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

// DB connection (admin/includes/db.php relative to this file)
$dbPath = __DIR__ . '/admin/includes/db.php';
if (!file_exists($dbPath)) {
    echo json_encode(["error" => "DB connection file not found."]);
    exit;
}
include($dbPath); // provides $conn (mysqli)

// Input check
if (!isset($_POST['check_in']) || !isset($_POST['check_out'])) {
    echo json_encode(["error" => "Missing date parameters."]);
    exit;
}

$check_in = $_POST['check_in'];
$check_out = $_POST['check_out'];

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

// Debug log optional
//$log = __DIR__ . '/fetch_debug.txt';
//file_put_contents($log, "REQ: $check_in | $check_out\n", FILE_APPEND);

// 1) Get list of cottages (we will show all cottages)
$q = "SELECT id, title, price, weekend_price, is_weekend, img, status FROM cottages ORDER BY id ASC";
$res = $conn->query($q);
if (!$res) {
    echo json_encode(["error" => "Cottage query failed", "details" => $conn->error]);
    exit;
}

$cottages = [];
while ($r = $res->fetch_assoc()) {
    // prepare image path relative to public (fallback)
    $img = !empty($r['img']) ? "assets/img/" . $r['img'] : "assets/img/default.jpg";
    $isWeekendActive = ((int)($r['is_weekend'] ?? 0) === 1);
    $effectivePrice = ($isWeekendActive && !empty($r['weekend_price'])) ? (int)$r['weekend_price'] : (int)$r['price'];
    $defaultAvailability = ($r['status'] === 'inactive') ? 'unavailable' : 'available';

    $cottages[$r['id']] = [
        "id" => (int)$r['id'],
        "title" => $r['title'],
        "price" => (int)$r['price'],
        "weekend_price" => (int)$r['weekend_price'],
        "is_weekend" => $isWeekendActive,
        "effective_price" => $effectivePrice,
        "display_price" => $effectivePrice,
        "img" => $img,
        "status" => $r['status'] ?? 'active',
        "availability" => $defaultAvailability
    ];
}

// 2) Find booked cottage ids that overlap requested period and status = accepted
// Overlap condition: existing.check_in < requested_check_out AND existing.check_out > requested_check_in
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

// 3) Mark availability for each cottage (do not hide any cottage)
foreach ($bookedIds as $bid) {
    if (isset($cottages[$bid])) {
        $cottages[$bid]['availability'] = 'booked';
    }
}

// 4) Return as indexed array (not associative by id)
$output = array_values($cottages);

echo json_encode($output, JSON_UNESCAPED_UNICODE);
exit;
