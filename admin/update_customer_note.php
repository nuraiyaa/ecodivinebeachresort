<?php
// admin/update_customer_note.php
// Dedicated AJAX/POST handler to update notes for a customer

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/includes/db.php';

$phone = trim($_POST['phone'] ?? '');
$note  = trim($_POST['note'] ?? '');

if (empty($phone)) {
    echo json_encode(['success' => false, 'error' => 'Customer phone is required']);
    exit;
}

// Update both customers and bookings tables for this phone
$stmt1 = $conn->prepare("UPDATE customers SET notes = ? WHERE phone = ?");
$stmt1->bind_param("ss", $note, $phone);
$stmt1->execute();
$stmt1->close();

$stmt2 = $conn->prepare("UPDATE bookings SET notes = ? WHERE customer_phone = ?");
$stmt2->bind_param("ss", $note, $phone);
$stmt2->execute();
$stmt2->close();

echo json_encode([
    'success' => true,
    'phone' => $phone,
    'note' => $note,
    'message' => 'Note updated successfully!'
]);
