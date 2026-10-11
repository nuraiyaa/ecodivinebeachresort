<?php
// admin/mark_paid.php
// Dedicated Endpoint: Record payment, subtract due amount, update status, and dispatch Thank You SMS

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Please log in again.']);
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/integrations.php';

// Helper function to dispatch SMS
function dispatchThankYouSms($phone, $message) {
    $clean_phone = preg_replace('/[^0-9+]/', '', $phone);
    if (strpos($clean_phone, '01') === 0 && strlen($clean_phone) === 11) {
        $clean_phone = '+88' . $clean_phone;
    }

    // Future Gateway Integration Hook:
    // If an external SMS gateway key/endpoint is configured, make the cURL call here.
    return [
        'status' => 'sent',
        'gateway_response' => 'Thank you SMS successfully queued & dispatched via Eco Divine SMS Gateway to ' . $clean_phone
    ];
}

$admin_id        = (int)$_SESSION['admin_id'];
$customer_id     = intval($_POST['customer_id'] ?? 0);
$booking_id      = intval($_POST['booking_id'] ?? 0);
$amount_paid     = floatval($_POST['amount_paid'] ?? 0);
$payment_method  = trim($_POST['payment_method'] ?? 'Cash at Front Desk');
$trx_id          = trim($_POST['trx_id'] ?? '');
$admin_note      = trim($_POST['notes'] ?? '');
$send_sms        = isset($_POST['send_sms']) && ($_POST['send_sms'] === '1' || $_POST['send_sms'] === 'true' || $_POST['send_sms'] === 'on');
$sms_message_raw = trim($_POST['sms_message'] ?? '');

if ($amount_paid < 0) {
    echo json_encode(['success' => false, 'error' => 'Amount paid cannot be negative.']);
    exit;
}

// 1. Resolve customer record from customer_id or booking_id
$customer = null;

if ($customer_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$customer && $booking_id > 0) {
    // Find booking
    $bStmt = $conn->prepare("SELECT b.*, c.title AS cottage_title FROM bookings b LEFT JOIN cottages c ON b.cottage_id = c.id WHERE b.id = ?");
    $bStmt->bind_param("i", $booking_id);
    $bStmt->execute();
    $booking = $bStmt->get_result()->fetch_assoc();
    $bStmt->close();

    if ($booking) {
        $bCode = 'ECO' . str_pad($booking_id, 4, '0', STR_PAD_LEFT);
        $bPhone = $booking['customer_phone'];
        $bCheckIn = $booking['check_in'];

        // Find existing customer
        $cStmt = $conn->prepare("SELECT * FROM customers WHERE booking_id = ? OR (phone = ? AND check_in = ?) LIMIT 1");
        $cStmt->bind_param("sss", $bCode, $bPhone, $bCheckIn);
        $cStmt->execute();
        $customer = $cStmt->get_result()->fetch_assoc();
        $cStmt->close();

        if (!$customer) {
            // Create customer record so it exists
            $cottageTitle = $booking['cottage_title'] ?: 'Resort Cottage';
            $pStatus = (floatval($booking['due_amount']) <= 0 && floatval($booking['total_amount']) > 0) ? 'paid' : (floatval($booking['advance_amount']) > 0 ? 'partial' : 'unpaid');
            $insC = $conn->prepare("INSERT INTO customers (name, email, phone, cottage_name, check_in, check_out, guest_count, booking_id, total_amount, advance_amount, discount_amount, due_amount, payment_method, notes, payment_status, created_at) VALUES (?, ?, ?, ?, ?, ?, 2, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $bDue = floatval($booking['due_amount']);
            $bAdv = floatval($booking['advance_amount']);
            $bDisc = floatval($booking['discount_amount']);
            $bTot = floatval($booking['total_amount']);
            $bMeth = $booking['payment_method'] ?: 'Cash';
            $bNote = $booking['notes'] ?: '';
            $insC->bind_param("sssssssddddsss", 
                $booking['customer_name'], $booking['customer_email'], $bPhone, 
                $cottageTitle, $bCheckIn, $booking['check_out'], $bCode, 
                $bTot, $bAdv, $bDisc, $bDue, $bMeth, $bNote, $pStatus
            );
            $insC->execute();
            $customer_id = $insC->insert_id;
            $insC->close();

            $customer = $conn->query("SELECT * FROM customers WHERE id = $customer_id")->fetch_assoc();
        } else {
            $customer_id = (int)$customer['id'];
        }
    }
}

if (!$customer) {
    echo json_encode(['success' => false, 'error' => 'Customer or booking record not found.']);
    exit;
}

$cName       = $customer['name'] ?: 'Valued Guest';
$cPhone      = $customer['phone'] ?: '';
$cBookingId  = $customer['booking_id'] && $customer['booking_id'] !== '0' ? $customer['booking_id'] : ('#CUST-' . $customer['id']);
$curDue      = floatval($customer['due_amount'] ?? 0);
$curAdvance  = floatval($customer['advance_amount'] ?? 0);
$curTotal    = floatval($customer['total_amount'] ?? 0);
$curNotes    = trim($customer['notes'] ?? '');

// 2. Compute new financial figures (Due is subtracted, Advance is increased, Total is synchronized)
// If amount_paid is 0 and due is already 0, we allow sending SMS / recording receipt
$actualPaid = $amount_paid;
$newDue     = max(0.0, round($curDue - $actualPaid, 2));
$newAdvance = round($curAdvance + $actualPaid, 2);
$newTotal   = max($curTotal, round($newAdvance + $newDue, 2));
$newStatus  = ($newDue <= 0.0) ? 'paid' : 'partial';

// Append payment audit note
$dateStr = date('M d, Y h:i A');
$auditEntry = "Paid ৳" . number_format($actualPaid) . " via " . $payment_method;
if (!empty($trx_id)) {
    $auditEntry .= " (TrxID: " . $trx_id . ")";
}
$auditEntry .= " on " . $dateStr;
if (!empty($admin_note)) {
    $auditEntry .= " - Note: " . $admin_note;
}

$updatedNotes = $curNotes ? ($curNotes . " | " . $auditEntry) : $auditEntry;

// 3. Update customers table
$updStmt = $conn->prepare("UPDATE customers SET due_amount = ?, advance_amount = ?, total_amount = ?, payment_status = ?, payment_method = ?, notes = ? WHERE id = ?");
$updStmt->bind_param("dddsssi", $newDue, $newAdvance, $newTotal, $newStatus, $payment_method, $updatedNotes, $customer_id);
$updStmt->execute();
$updStmt->close();

// 4. Synchronize with bookings table if matching booking exists
if (!empty($customer['booking_id']) && $customer['booking_id'] !== '0') {
    // If booking_id is format ECO0677 -> id = 677
    $bId = 0;
    if (preg_match('/^ECO0*(\d+)$/i', $customer['booking_id'], $m)) {
        $bId = intval($m[1]);
    }
    if ($bId > 0) {
        $bStmt = $conn->prepare("UPDATE bookings SET due_amount = ?, advance_amount = ?, total_amount = GREATEST(total_amount, ?), payment_method = ? WHERE id = ?");
        $bStmt->bind_param("dddsi", $newDue, $newAdvance, $newTotal, $payment_method, $bId);
        $bStmt->execute();
        $bStmt->close();
    }
} elseif (!empty($cPhone) && !empty($customer['check_in'])) {
    $bStmt2 = $conn->prepare("UPDATE bookings SET due_amount = ?, advance_amount = ?, total_amount = GREATEST(total_amount, ?), payment_method = ? WHERE customer_phone = ? AND check_in = ?");
    $bStmt2->bind_param("dddsss", $newDue, $newAdvance, $newTotal, $payment_method, $cPhone, $customer['check_in']);
    $bStmt2->execute();
    $bStmt2->close();
}

// 5. Handle Thank You SMS & Email Receipt dispatch
$sms_sent = false;
$sms_log_msg = '';
$email_sent = false;
$email_log_msg = '';

$intSettings = getSystemSettings();
$hotline = $intSettings['sms_hotline'] ?? '01817838383';

if ($send_sms && !empty($cPhone)) {
    // Generate Thank You message if not provided
    if (empty($sms_message_raw)) {
        $sms_message_raw = "Dear {Name}, thank you for your payment of ৳{Amount}! Your remaining due has been successfully cleared for booking #{BookingID} at Eco Divine Beach Resort, Saint Martin. We look forward to hosting you! Hotline: " . $hotline;
    }

    // Replace placeholders
    $personalizedSms = str_replace(
        ['{Name}', '{Amount}', '{Due}', '{BookingID}'],
        [$cName, number_format($actualPaid), number_format($newDue), $cBookingId],
        $sms_message_raw
    );

    // Dispatch SMS via Centralized Gateway
    $dispatchResult = sendResortSms($cPhone, $personalizedSms, $cName, 'single', $admin_id);
    $sms_sent = $dispatchResult['success'];
    $sms_log_msg = $dispatchResult['response'];
}

// Automated Payment Receipt Email
$guestEmail = trim($customer['email'] ?? '');
if (($intSettings['email_on_mark_paid'] ?? '1') === '1' && !empty($guestEmail) && filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
    $invUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']) . "/invoice.php?customer_id=" . $customer_id;
    $emailHtml = buildPaymentReceiptEmailHtml([
        'guest_name'       => $cName,
        'booking_id'       => $cBookingId,
        'amount_paid'      => $actualPaid,
        'total_bill'       => $newTotal,
        'collected_amount' => $newAdvance,
        'due_amount'       => $newDue,
        'payment_method'   => $payment_method,
        'trx_id'           => !empty($trx_id) ? $trx_id : 'N/A',
        'invoice_url'      => $invUrl
    ]);
    $emailResult = sendResortEmail($guestEmail, $cName, "Payment Receipt #{$cBookingId} - Eco Divine Beach Resort", $emailHtml, '', 'payment_receipt', $admin_id);
    $email_sent = $emailResult['success'];
    $email_log_msg = $emailResult['response'];
}

// 6. Calculate refreshed overall totals
$totE = (float)($conn->query("SELECT IFNULL(SUM(total_amount - due_amount),0) as total FROM customers")->fetch_assoc()['total'] ?? 0);
$dueE = (float)($conn->query("SELECT IFNULL(SUM(due_amount),0) as pending FROM customers")->fetch_assoc()['pending'] ?? 0);
$gross = $totE + $dueE;
$recoveryRate = ($gross > 0) ? round(($totE / $gross) * 100, 1) : 0;
$dueCount  = (int)($conn->query("SELECT COUNT(*) c FROM customers WHERE due_amount > 0")->fetch_assoc()['c'] ?? 0);
$paidCount = (int)($conn->query("SELECT COUNT(*) c FROM customers WHERE due_amount <= 0")->fetch_assoc()['c'] ?? 0);

echo json_encode([
    'success'         => true,
    'message'         => "Payment of ৳" . number_format($actualPaid) . " recorded successfully!" . ($newDue <= 0 ? " Due is completely cleared." : " Remaining due: ৳" . number_format($newDue)) . ($sms_sent ? " Thank you SMS sent." : ""),
    'customer_id'     => $customer_id,
    'paid_amount'     => $actualPaid,
    'new_due'         => $newDue,
    'new_due_format'  => number_format($newDue),
    'new_advance'     => $newAdvance,
    'new_advance_format' => number_format($newAdvance),
    'new_status'      => $newStatus,
    'sms_sent'        => $sms_sent,
    'sms_message'     => $sms_log_msg,
    'email_sent'      => $email_sent,
    'email_message'   => $email_log_msg,
    'totalEarnings'   => $totE,
    'totalEarnings_fmt' => number_format($totE),
    'dueEarnings'     => $dueE,
    'dueEarnings_fmt' => number_format($dueE),
    'grossRevenue_fmt'=> number_format($gross),
    'recoveryRate'    => $recoveryRate,
    'dueCount'        => $dueCount,
    'paidCount'       => $paidCount
], JSON_UNESCAPED_UNICODE);
