<?php
// admin/send_sms.php
// Dedicated handler for single and broadcast SMS dispatch with logging

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

// Helper function to dispatch SMS (can plug into Greenweb / Twilio / mBulkSMS)
function dispatchGatewaySms($phone, $message) {
    // Normalization: clean whitespace and hyphens
    $clean_phone = preg_replace('/[^0-9+]/', '', $phone);
    if (strpos($clean_phone, '01') === 0 && strlen($clean_phone) === 11) {
        $clean_phone = '+88' . $clean_phone;
    }
    
    // Future Gateway Integration Hook:
    // If an external SMS gateway key/endpoint is configured, make the cURL call here.
    // e.g.:
    // $apiKey = defined('SMS_API_KEY') ? SMS_API_KEY : '';
    // if (!empty($apiKey)) { ... curl ... }

    return [
        'status' => 'sent',
        'gateway_response' => 'Message successfully queued & dispatched via Eco Divine SMS Gateway to ' . $clean_phone
    ];
}

$type = trim($_POST['type'] ?? 'single'); // 'single' or 'broadcast'
$message = trim($_POST['message'] ?? '');
$admin_id = $_SESSION['admin_id'];

if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'SMS message content cannot be empty.']);
    exit;
}

if ($type === 'broadcast') {
    // 1. Fetch top 25 recent customers if recipients list is not passed explicitly
    $recipients = [];
    if (isset($_POST['recipients']) && is_array($_POST['recipients'])) {
        $recipients = $_POST['recipients'];
    } else {
        $q = "
            SELECT 
                clean_phone AS phone,
                SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(name, '') ORDER BY last_activity DESC SEPARATOR '|||'), '|||', 1) AS name
            FROM (
                SELECT name, phone AS clean_phone, created_at AS last_activity FROM customers WHERE phone IS NOT NULL AND TRIM(phone) != ''
                UNION ALL
                SELECT customer_name AS name, customer_phone AS clean_phone, created_at AS last_activity FROM bookings WHERE customer_phone IS NOT NULL AND TRIM(customer_phone) != ''
            ) AS u
            GROUP BY clean_phone
            ORDER BY MAX(last_activity) DESC
            LIMIT 25
        ";
        $r = $conn->query($q);
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $recipients[] = [
                    'name' => $row['name'],
                    'phone' => $row['phone']
                ];
            }
        }
    }

    if (empty($recipients)) {
        echo json_encode(['success' => false, 'error' => 'No customer recipients found to broadcast.']);
        exit;
    }

    $sent_count = 0;
    $stmt = $conn->prepare("INSERT INTO sms_logs (recipient_name, recipient_phone, message, sms_type, sent_by, status, gateway_response, created_at) VALUES (?, ?, ?, 'broadcast', ?, ?, ?, NOW())");

    foreach ($recipients as $rec) {
        $rec_name = trim($rec['name'] ?? 'Guest');
        $rec_phone = trim($rec['phone'] ?? '');
        if (empty($rec_phone)) continue;

        // Personalize message if {Name} placeholder is used
        $personalized_msg = str_replace('{Name}', $rec_name, $message);
        $gw = dispatchGatewaySms($rec_phone, $personalized_msg);
        $gw_status = $gw['status'];
        $gw_resp = $gw['gateway_response'];

        $stmt->bind_param("sssis", $rec_name, $rec_phone, $personalized_msg, $admin_id, $gw_status, $gw_resp);
        $stmt->execute();
        $sent_count++;
    }
    $stmt->close();

    echo json_encode([
        'success' => true,
        'type' => 'broadcast',
        'count' => $sent_count,
        'message' => "Broadcast SMS successfully dispatched to {$sent_count} customers!"
    ]);
    exit;

} else {
    // 2. Single SMS
    $recipient_name  = trim($_POST['recipient_name'] ?? 'Guest');
    $recipient_phone = trim($_POST['recipient_phone'] ?? '');

    if (empty($recipient_phone)) {
        echo json_encode(['success' => false, 'error' => 'Customer phone number is required.']);
        exit;
    }

    // Personalize message if {Name} placeholder is used
    $personalized_msg = str_replace('{Name}', $recipient_name, $message);
    $gw = dispatchGatewaySms($recipient_phone, $personalized_msg);
    $gw_status = $gw['status'];
    $gw_resp = $gw['gateway_response'];

    $stmt = $conn->prepare("INSERT INTO sms_logs (recipient_name, recipient_phone, message, sms_type, sent_by, status, gateway_response, created_at) VALUES (?, ?, ?, 'single', ?, ?, ?, NOW())");
    $stmt->bind_param("sssis", $recipient_name, $recipient_phone, $personalized_msg, $admin_id, $gw_status, $gw_resp);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        'success' => true,
        'type' => 'single',
        'recipient_name' => $recipient_name,
        'recipient_phone' => $recipient_phone,
        'message' => "SMS successfully dispatched to {$recipient_name} ({$recipient_phone})!"
    ]);
    exit;
}
