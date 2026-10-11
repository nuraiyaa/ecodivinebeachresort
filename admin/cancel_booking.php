<?php
// admin/cancel_booking.php
// Dedicated Endpoint: Process booking cancellation, adjust financials (deduct from due, collected, total), log refund history, and notify guest

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Unauthorized. Please log in again.']);
        exit;
    }
    header('Location: login.php?msg=unauthorized');
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/integrations.php';

$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1');

$adminId   = (int)$_SESSION['admin_id'];
$adminName = $_SESSION['admin_name'] ?? 'Admin';

// ========================================================
// 1. GET ACTION: FETCH REFUND HISTORY LIST (AJAX)
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_refund_history') {
    header('Content-Type: application/json; charset=utf-8');
    
    $bookingId = intval($_GET['booking_id'] ?? 0);
    $whereSql = "";
    if ($bookingId > 0) {
        $whereSql = "WHERE booking_id = $bookingId";
    }

    $q = "SELECT * FROM booking_refunds $whereSql ORDER BY id DESC LIMIT 50";
    $res = $conn->query($q);
    $list = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $list[] = [
                'id'                 => (int)$r['id'],
                'booking_id'         => (int)$r['booking_id'],
                'booking_ref'        => $r['booking_ref'],
                'customer_name'      => $r['customer_name'],
                'customer_phone'     => $r['customer_phone'],
                'customer_email'     => $r['customer_email'],
                'cottage_name'       => $r['cottage_name'],
                'stay_dates'         => (!empty($r['check_in']) && !empty($r['check_out'])) ? date('M d', strtotime($r['check_in'])) . ' – ' . date('M d, Y', strtotime($r['check_out'])) : '—',
                'original_total'     => number_format((float)$r['original_total'], 2),
                'original_advance'   => number_format((float)$r['original_advance'], 2),
                'original_due'       => number_format((float)$r['original_due'], 2),
                'refund_amount'      => number_format((float)$r['refund_amount'], 2),
                'refund_raw'         => (float)$r['refund_amount'],
                'cancellation_fee'   => number_format((float)$r['cancellation_fee'], 2),
                'fee_raw'            => (float)$r['cancellation_fee'],
                'new_collected'      => number_format((float)$r['new_collected'], 2),
                'new_total'          => number_format((float)$r['new_total'], 2),
                'refund_method'      => $r['refund_method'],
                'refund_trx_id'      => $r['refund_trx_id'],
                'reason'             => $r['reason'],
                'processed_by_name'  => $r['processed_by_name'],
                'created_at_fmt'     => date('d M, Y h:i A', strtotime($r['created_at']))
            ];
        }
    }

    echo json_encode(['success' => true, 'refunds' => $list]);
    exit;
}

// ========================================================
// 2. POST ACTION: PROCESS CANCELLATION & REFUND
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['action_cancel_refund']) || isset($_POST['action_cancel_booking']))) {
    $bookingId          = intval($_POST['booking_id'] ?? 0);
    $refundInput        = floatval($_POST['refund_amount'] ?? 0);
    $refundMethod       = trim($_POST['refund_method'] ?? 'bKash (Personal / Merchant)');
    $refundTrxId        = trim($_POST['refund_trx_id'] ?? '');
    $reason             = trim($_POST['cancellation_reason'] ?? '');
    $sendNotification   = isset($_POST['send_notification']) && ($_POST['send_notification'] === '1' || $_POST['send_notification'] === 'on' || $_POST['send_notification'] === 'true');

    if ($bookingId <= 0) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Invalid booking ID provided.']);
            exit;
        }
        header('Location: dashboard.php?err=' . urlencode('Invalid booking ID') . '#view-bookings');
        exit;
    }

    // 1. Fetch current booking details with cottage title
    $bStmt = $conn->prepare("
        SELECT b.*, 
               COALESCE(
                   (SELECT GROUP_CONCAT(c2.title ORDER BY c2.title SEPARATOR ', ')
                    FROM booking_cottages bc2 
                    JOIN cottages c2 ON bc2.cottage_id = c2.id 
                    WHERE bc2.booking_id = b.id),
                   c.title,
                   'Resort Cottage'
               ) AS cottage_title
        FROM bookings b 
        LEFT JOIN cottages c ON b.cottage_id = c.id 
        WHERE b.id = ?
    ");
    $bStmt->bind_param("i", $bookingId);
    $bStmt->execute();
    $currBooking = $bStmt->get_result()->fetch_assoc();
    $bStmt->close();

    if (!$currBooking) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Booking record not found in system.']);
            exit;
        }
        header('Location: dashboard.php?err=' . urlencode('Booking not found') . '#view-bookings');
        exit;
    }

    $bCode          = 'ECO' . str_pad($bookingId, 4, '0', STR_PAD_LEFT);
    $custName       = $currBooking['customer_name'] ?: 'Guest';
    $custPhone      = $currBooking['customer_phone'] ?: '';
    $custEmail      = $currBooking['customer_email'] ?: '';
    $cottageTitle   = $currBooking['cottage_title'] ?: 'Resort Cottage';
    $checkIn        = $currBooking['check_in'];
    $checkOut       = $currBooking['check_out'];

    $origTotal      = floatval($currBooking['total_amount'] ?? 0);
    $origAdvance    = floatval($currBooking['advance_amount'] ?? 0);
    $origDue        = floatval($currBooking['due_amount'] ?? 0);

    // If total_amount was 0 or unrecorded, compute sensible fallback
    if ($origTotal <= 0 && ($origAdvance > 0 || $origDue > 0)) {
        $origTotal = $origAdvance + $origDue;
    }

    // 2. Financial calculation:
    // Refund cannot exceed collected advance amount
    $refundAmount = max(0.0, min($origAdvance, round($refundInput, 2)));
    // Cancellation fee retained by the resort
    $cancellationFee = max(0.0, round($origAdvance - $refundAmount, 2));

    // After cancellation:
    // - Due is completely cleared (deducted to 0)
    // - Advance is reduced by refund amount (only retained fee remains collected)
    // - Total bill is reduced so only retained fee remains as final resort revenue
    $newAdvance = $cancellationFee;
    $newDue     = 0.00;
    $newTotal   = $cancellationFee;
    $nowStr     = date('Y-m-d H:i:s');
    $dateLabel  = date('M d, Y h:i A');

    // Audit Remark
    $auditRemark = "CANCELLED on {$dateLabel}. Advance was ৳" . number_format($origAdvance) . ". Refunded: ৳" . number_format($refundAmount) . " via {$refundMethod}";
    if (!empty($refundTrxId)) {
        $auditRemark .= " (TrxID: {$refundTrxId})";
    }
    $auditRemark .= ". Fee Retained: ৳" . number_format($cancellationFee) . ". Due cleared (৳0).";
    if (!empty($reason)) {
        $auditRemark .= " Reason: {$reason}";
    }

    $curNotes = trim($currBooking['notes'] ?? '');
    $updatedNotes = $curNotes ? ($curNotes . " | " . $auditRemark) : $auditRemark;

    // 3. Update bookings table
    $updB = $conn->prepare("
        UPDATE bookings 
        SET status = 'cancelled', 
            total_amount = ?, 
            advance_amount = ?, 
            due_amount = 0.00, 
            refund_amount = ?, 
            cancellation_fee = ?, 
            refund_method = ?, 
            refund_trx_id = ?, 
            cancelled_at = ?, 
            cancelled_by = ?, 
            notes = ? 
        WHERE id = ?
    ");
    $updB->bind_param("ddddsssisi", 
        $newTotal, 
        $newAdvance, 
        $refundAmount, 
        $cancellationFee, 
        $refundMethod, 
        $refundTrxId, 
        $nowStr, 
        $adminId, 
        $updatedNotes, 
        $bookingId
    );
    $updB->execute();
    $updB->close();

    // 4. Synchronize customers table
    $cStmt = $conn->prepare("SELECT id, notes FROM customers WHERE booking_id = ? OR (phone = ? AND check_in = ?) LIMIT 1");
    $cStmt->bind_param("sss", $bCode, $custPhone, $checkIn);
    $cStmt->execute();
    $custRow = $cStmt->get_result()->fetch_assoc();
    $cStmt->close();

    $customerId = null;
    if ($custRow) {
        $customerId = (int)$custRow['id'];
        $cNotes = trim($custRow['notes'] ?? '');
        $updCNotes = $cNotes ? ($cNotes . " | " . $auditRemark) : $auditRemark;
        $custStatus = ($refundAmount > 0) ? 'refunded' : 'cancelled';

        $updC = $conn->prepare("
            UPDATE customers 
            SET payment_status = ?, 
                total_amount = ?, 
                advance_amount = ?, 
                due_amount = 0.00, 
                refund_amount = ?, 
                cancellation_fee = ?, 
                payment_method = ?, 
                notes = ? 
            WHERE id = ?
        ");
        $updC->bind_param("sddddssi", 
            $custStatus, 
            $newTotal, 
            $newAdvance, 
            $refundAmount, 
            $cancellationFee, 
            $refundMethod, 
            $updCNotes, 
            $customerId
        );
        $updC->execute();
        $updC->close();
    }

    // 5. Insert audit log into booking_refunds table
    $insRef = $conn->prepare("
        INSERT INTO booking_refunds (
            booking_id, booking_ref, customer_id, customer_name, customer_phone, customer_email, 
            cottage_name, check_in, check_out, original_total, original_advance, original_due, 
            refund_amount, cancellation_fee, new_collected, new_total, refund_method, refund_trx_id, 
            reason, processed_by, processed_by_name, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $insRef->bind_param("isissssssddddddssssis", 
        $bookingId, $bCode, $customerId, $custName, $custPhone, $custEmail,
        $cottageTitle, $checkIn, $checkOut, $origTotal, $origAdvance, $origDue,
        $refundAmount, $cancellationFee, $newAdvance, $newTotal, $refundMethod, $refundTrxId,
        $reason, $adminId, $adminName
    );
    $insRef->execute();
    $refundRecordId = $insRef->insert_id;
    $insRef->close();

    // 6. Automated Guest Notifications (SMS & Email)
    $smsSent = false;
    $emailSent = false;
    $intSettings = getSystemSettings();
    $hotline = $intSettings['sms_hotline'] ?? '01817838383';

    if ($sendNotification) {
        // SMS Notification
        if (!empty($custPhone)) {
            $smsText = "Dear {$custName}, booking #{$bCode} for Eco Divine Resort has been cancelled. Refund of ৳" . number_format($refundAmount) . " processed via {$refundMethod}";
            if ($cancellationFee > 0) {
                $smsText .= " (Fee: ৳" . number_format($cancellationFee) . ")";
            }
            $smsText .= ". Room is freed. Hotline: {$hotline}";

            $smsRes = sendResortSms($custPhone, $smsText, $custName, 'single', $adminId);
            $smsSent = $smsRes['success'];
        }

        // Email Notification
        if (!empty($custEmail) && filter_var($custEmail, FILTER_VALIDATE_EMAIL)) {
            $subject = "Booking Cancellation & Refund Notice #{$bCode} - Eco Divine Beach Resort";
            $refundFmt = number_format($refundAmount);
            $feeFmt = number_format($cancellationFee);
            $origAdvFmt = number_format($origAdvance);
            $trxLabel = !empty($refundTrxId) ? htmlspecialchars($refundTrxId) : 'N/A';

            $emailHtml = <<<HTML
<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1e293b;">
<table width="100%" cellspacing="0" cellpadding="0" style="background-color:#f1f5f9;padding:30px 10px;">
  <tr><td align="center">
    <table width="600" cellspacing="0" cellpadding="0" style="background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 10px 25px rgba(0,0,0,0.06);border:1px solid #e2e8f0;">
      <tr>
        <td style="background:linear-gradient(135deg,#059669 0%,#047857 100%);padding:30px;text-align:center;">
          <h1 style="margin:0;color:#ffffff;font-size:24px;font-weight:800;letter-spacing:1px;">ECO DIVINE BEACH RESORT</h1>
          <p style="margin:4px 0 0;color:#a7f3d0;font-size:12px;text-transform:uppercase;letter-spacing:2px;">Cancellation &amp; Refund Notice</p>
        </td>
      </tr>
      <tr>
        <td style="padding:30px 35px 20px;">
          <div style="display:inline-block;background-color:#fef2f2;border:1px solid #fecaca;border-radius:30px;padding:5px 14px;font-size:12px;font-weight:700;color:#dc2626;margin-bottom:14px;">
            ✕ RESERVATION CANCELLED &bull; #{$bCode}
          </div>
          <h2 style="margin:0 0 10px;font-size:19px;color:#0f172a;">Dear {$custName},</h2>
          <p style="margin:0;font-size:14px;line-height:1.6;color:#475569;">
            This email confirms that your reservation at <strong>Eco Divine Beach Resort</strong> has been successfully cancelled and your room has been released. The financial settlement details are summarized below.
          </p>
        </td>
      </tr>
      <tr>
        <td style="padding:0 35px 25px;">
          <table width="100%" cellspacing="0" cellpadding="0" style="background-color:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:20px;">
            <tr><td colspan="2" style="padding-bottom:10px;border-bottom:1px solid #e2e8f0;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Refund Settlement Breakdown</td></tr>
            <tr><td style="padding:10px 0 4px;font-size:13px;color:#64748b;">Reserved Cottage:</td><td align="right" style="padding:10px 0 4px;font-size:13.5px;font-weight:600;color:#0f172a;">{$cottageTitle}</td></tr>
            <tr><td style="padding:4px 0 8px;font-size:13px;color:#64748b;">Stay Dates:</td><td align="right" style="padding:4px 0 8px;font-size:13.5px;font-weight:600;color:#0f172a;">{$checkIn} to {$checkOut}</td></tr>
            <tr><td colspan="2" style="border-top:1px dashed #cbd5e1;padding-top:6px;"></td></tr>
            <tr><td style="padding:4px 0;font-size:13px;color:#64748b;">Advance Paid Initially:</td><td align="right" style="padding:4px 0;font-size:13.5px;font-weight:600;color:#0f172a;">৳ {$origAdvFmt}</td></tr>
            <tr><td style="padding:4px 0;font-size:13px;color:#059669;font-weight:700;">Refund Processed:</td><td align="right" style="padding:4px 0;font-size:16px;font-weight:800;color:#059669;">৳ {$refundFmt}</td></tr>
            <tr><td style="padding:4px 0;font-size:13px;color:#64748b;">Cancellation Policy Fee:</td><td align="right" style="padding:4px 0;font-size:13.5px;font-weight:600;color:#d97706;">৳ {$feeFmt}</td></tr>
            <tr><td style="padding:4px 0;font-size:13px;color:#64748b;">Outstanding Due:</td><td align="right" style="padding:4px 0;font-size:13.5px;font-weight:700;color:#059669;">৳ 0.00 (Cleared)</td></tr>
            <tr><td style="padding:4px 0;font-size:13px;color:#64748b;">Refund Method:</td><td align="right" style="padding:4px 0;font-size:13.5px;font-weight:600;color:#0f172a;">{$refundMethod}</td></tr>
            <tr><td style="padding:4px 0 6px;font-size:13px;color:#64748b;">Transaction Reference:</td><td align="right" style="padding:4px 0 6px;font-size:13.5px;font-weight:600;color:#0f172a;">{$trxLabel}</td></tr>
          </table>
        </td>
      </tr>
      <tr>
        <td style="background-color:#f8fafc;border-top:1px solid #e2e8f0;padding:20px 35px;text-align:center;">
          <p style="margin:0 0 5px;font-size:12.5px;font-weight:700;color:#334155;">Eco Divine Beach Resort, Saint Martin Island</p>
          <p style="margin:0;font-size:12px;color:#059669;font-weight:600;">Hotline: +880 {$hotline}</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
            $emailRes = sendResortEmail($custEmail, $custName, $subject, $emailHtml, '', 'cancellation_refund', $adminId);
            $emailSent = $emailRes['success'];
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'          => true,
            'message'          => "Booking #{$bCode} has been cancelled! Refund of ৳" . number_format($refundAmount) . " recorded. Room is now available.",
            'booking_id'       => $bookingId,
            'booking_ref'      => $bCode,
            'refund_id'        => $refundRecordId,
            'original_advance' => $origAdvance,
            'refund_amount'    => $refundAmount,
            'cancellation_fee' => $cancellationFee,
            'new_collected'    => $newAdvance,
            'new_due'          => 0,
            'new_total'        => $newTotal,
            'sms_sent'         => $smsSent,
            'email_sent'       => $emailSent
        ]);
        exit;
    }

    header('Location: dashboard.php?msg=booking_cancelled_refunded#view-bookings');
    exit;
}

// Fallback GET cancellation redirect
$getId = intval($_GET['id'] ?? 0);
if ($getId > 0 && isset($_GET['action']) && $_GET['action'] === 'cancel') {
    // If called directly via URL without form, prompt or redirect to bookings with trigger
    header("Location: dashboard.php?open_cancel=$getId#view-bookings");
    exit;
}

header('Location: dashboard.php#view-bookings');
exit;
