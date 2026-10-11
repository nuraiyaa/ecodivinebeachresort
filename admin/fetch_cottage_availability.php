<?php
// admin/fetch_cottage_availability.php
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/includes/db.php';

$check_in  = trim($_REQUEST['check_in'] ?? '');
$check_out = trim($_REQUEST['check_out'] ?? '');

if (empty($check_in) || empty($check_out)) {
    echo json_encode(['error' => 'Dates required']);
    exit;
}

// Safeguard dates
if ($check_out <= $check_in) {
    $check_out = date('Y-m-d', strtotime($check_in . ' +1 day'));
}

// 1. Fetch all cottages
$cottagesRes = $conn->query("SELECT id, title, price, weekend_price, is_weekend, status FROM cottages ORDER BY id ASC");
$allCottages = [];
while ($row = $cottagesRes->fetch_assoc()) {
    $isWk = ((int)($row['is_weekend'] ?? 0) === 1);
    $eff = ($isWk && !empty($row['weekend_price'])) ? floatval($row['weekend_price']) : floatval($row['price']);
    $allCottages[$row['id']] = [
        'id' => (int)$row['id'],
        'title' => $row['title'],
        'price' => floatval($row['price']),
        'weekend_price' => floatval($row['weekend_price']),
        'is_weekend' => $isWk,
        'effective_price' => $eff,
        'status' => $row['status'],
        'is_available' => ($row['status'] === 'active'),
        'booked_by' => null,
        'booking_status' => null
    ];
}

// 2. Fetch occupied cottages overlapping requested period
$sql = "
    SELECT DISTINCT bc.cottage_id, b.id AS booking_id, b.customer_name, b.status 
    FROM booking_cottages bc
    JOIN bookings b ON bc.booking_id = b.id
    WHERE b.status IN ('accepted', 'pending')
      AND b.check_in < ? AND b.check_out > ?
    UNION
    SELECT DISTINCT b.cottage_id, b.id AS booking_id, b.customer_name, b.status
    FROM bookings b
    WHERE b.status IN ('accepted', 'pending')
      AND b.check_in < ? AND b.check_out > ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssss", $check_out, $check_in, $check_out, $check_in);
$stmt->execute();
$occRes = $stmt->get_result();

while ($r = $occRes->fetch_assoc()) {
    $cid = (int)$r['cottage_id'];
    if (isset($allCottages[$cid])) {
        $allCottages[$cid]['is_available'] = false;
        $allCottages[$cid]['booked_by'] = $r['customer_name'] . " (#" . $r['booking_id'] . ")";
        $allCottages[$cid]['booking_status'] = $r['status'];
    }
}
$stmt->close();

echo json_encode([
    'success' => true,
    'check_in' => $check_in,
    'check_out' => $check_out,
    'cottages' => array_values($allCottages)
], JSON_UNESCAPED_UNICODE);
