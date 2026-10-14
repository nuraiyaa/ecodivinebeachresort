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
require_once __DIR__ . '/includes/integrations.php';

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
    foreach ($recipients as $rec) {
        $rec_name = trim($rec['name'] ?? 'Guest');
        $rec_phone = trim($rec['phone'] ?? '');
        if (empty($rec_phone)) continue;

        // Personalize message if {Name} placeholder is used
        $personalized_msg = str_replace('{Name}', $rec_name, $message);
        $res = sendResortSms($rec_phone, $personalized_msg, $rec_name, 'broadcast', $admin_id);
        if ($res['success']) {
            $sent_count++;
        }
    }

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
    $res = sendResortSms($recipient_phone, $personalized_msg, $recipient_name, 'single', $admin_id);

    if ($res['success']) {
        echo json_encode([
            'success' => true,
            'type' => 'single',
            'recipient_name' => $recipient_name,
            'recipient_phone' => $recipient_phone,
            'status' => $res['status'],
            'response' => $res['response'],
            'message' => "SMS successfully dispatched to {$recipient_name} ({$recipient_phone})!"
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => !empty($res['response']) ? $res['response'] : 'Failed to send SMS.'
        ]);
    }
    exit;
}
