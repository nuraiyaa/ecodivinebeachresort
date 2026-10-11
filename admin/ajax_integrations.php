<?php
// admin/ajax_integrations.php
// AJAX API endpoint for testing SMS, testing Email, saving gateway settings, and fetching delivery logs

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access. Please log in again.']);
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/integrations.php';

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');
$adminId = (int)$_SESSION['admin_id'];

switch ($action) {

    // ----------------------------------------------------
    // 1. SAVE SMS GATEWAY SETTINGS
    // ----------------------------------------------------
    case 'save_sms_settings':
        $provider     = trim($_POST['sms_provider'] ?? 'greenweb');
        $apiKey       = trim($_POST['sms_api_key'] ?? '');
        $senderId     = trim($_POST['sms_sender_id'] ?? 'EcoDivine');
        $apiUrl       = trim($_POST['sms_api_url'] ?? '');
        $hotline      = trim($_POST['sms_hotline'] ?? '01817838383');
        $smsEnabled   = isset($_POST['sms_enabled']) && ($_POST['sms_enabled'] === '1' || $_POST['sms_enabled'] === 'on') ? '1' : '0';
        $autoConfirm  = isset($_POST['sms_on_booking_confirm']) && ($_POST['sms_on_booking_confirm'] === '1' || $_POST['sms_on_booking_confirm'] === 'on') ? '1' : '0';
        $autoMarkPaid = isset($_POST['sms_on_mark_paid']) && ($_POST['sms_on_mark_paid'] === '1' || $_POST['sms_on_mark_paid'] === 'on') ? '1' : '0';

        updateSystemSetting('sms_provider', $provider);
        updateSystemSetting('sms_api_key', $apiKey);
        updateSystemSetting('sms_sender_id', $senderId);
        updateSystemSetting('sms_api_url', $apiUrl);
        updateSystemSetting('sms_hotline', $hotline);
        updateSystemSetting('sms_enabled', $smsEnabled);
        updateSystemSetting('sms_on_booking_confirm', $autoConfirm);
        updateSystemSetting('sms_on_mark_paid', $autoMarkPaid);

        echo json_encode([
            'success' => true,
            'message' => 'SMS Gateway configuration updated successfully!'
        ]);
        exit;

    // ----------------------------------------------------
    // 2. SAVE EMAIL & SMTP SETTINGS
    // ----------------------------------------------------
    case 'save_email_settings':
        $engine       = trim($_POST['email_engine'] ?? 'smtp');
        $smtpHost     = trim($_POST['email_smtp_host'] ?? 'smtp.gmail.com');
        $smtpPort     = trim($_POST['email_smtp_port'] ?? '587');
        $smtpCrypto   = trim($_POST['email_smtp_crypto'] ?? 'tls');
        $smtpUser     = trim($_POST['email_smtp_user'] ?? '');
        $smtpPass     = trim($_POST['email_smtp_pass'] ?? '');
        $fromName     = trim($_POST['email_from_name'] ?? 'Eco Divine Beach Resort');
        $fromAddress  = trim($_POST['email_from_address'] ?? 'reservations@ecodivine.com');
        $emailEnabled = isset($_POST['email_enabled']) && ($_POST['email_enabled'] === '1' || $_POST['email_enabled'] === 'on') ? '1' : '0';
        $autoConfirm  = isset($_POST['email_on_booking_confirm']) && ($_POST['email_on_booking_confirm'] === '1' || $_POST['email_on_booking_confirm'] === 'on') ? '1' : '0';
        $autoMarkPaid = isset($_POST['email_on_mark_paid']) && ($_POST['email_on_mark_paid'] === '1' || $_POST['email_on_mark_paid'] === 'on') ? '1' : '0';

        updateSystemSetting('email_engine', $engine);
        updateSystemSetting('email_smtp_host', $smtpHost);
        updateSystemSetting('email_smtp_port', $smtpPort);
        updateSystemSetting('email_smtp_crypto', $smtpCrypto);
        updateSystemSetting('email_smtp_user', $smtpUser);
        
        // Only update password if a new value is submitted
        if (!empty($smtpPass)) {
            updateSystemSetting('email_smtp_pass', $smtpPass);
        }

        updateSystemSetting('email_from_name', $fromName);
        updateSystemSetting('email_from_address', $fromAddress);
        updateSystemSetting('email_enabled', $emailEnabled);
        updateSystemSetting('email_on_booking_confirm', $autoConfirm);
        updateSystemSetting('email_on_mark_paid', $autoMarkPaid);

        echo json_encode([
            'success' => true,
            'message' => 'Email & SMTP integration settings saved successfully!'
        ]);
        exit;

    // ----------------------------------------------------
    // 3. DISPATCH TEST SMS
    // ----------------------------------------------------
    case 'test_sms':
        $testPhone = trim($_POST['test_phone'] ?? '');
        $testText  = trim($_POST['test_message'] ?? '');

        if (empty($testPhone)) {
            echo json_encode(['success' => false, 'error' => 'Please provide a valid destination phone number for the test SMS.']);
            exit;
        }

        if (empty($testText)) {
            $hotline = getSystemSetting('sms_hotline', '01817838383');
            $testText = "Eco Divine Test: SMS Gateway connectivity verified! Sent at " . date('h:i:s A') . ". Resort Hotline: " . $hotline;
        }

        $result = sendResortSms($testPhone, $testText, 'Test Recipient', 'single', $adminId);

        echo json_encode([
            'success'  => $result['success'],
            'status'   => $result['status'],
            'message'  => $result['status'] === 'sent' 
                ? 'Test SMS sent successfully!' 
                : ($result['status'] === 'queued' ? 'Test SMS queued (Gateway key pending or simulation mode).' : 'Test SMS dispatch failed.'),
            'response' => $result['response']
        ]);
        exit;

    // ----------------------------------------------------
    // 4. DISPATCH TEST EMAIL
    // ----------------------------------------------------
    case 'test_email':
        $testEmail = trim($_POST['test_email'] ?? '');
        $testName  = trim($_POST['test_name'] ?? 'Resort Administrator');

        if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Please provide a valid email address for the test email.']);
            exit;
        }

        $now = date('M d, Y h:i:s A');
        $subject = "Eco Divine Beach Resort - SMTP Integration Test (" . date('h:i A') . ")";
        $hotline = getSystemSetting('sms_hotline', '01817838383');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; padding: 25px;">
  <div style="max-width: 550px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
    <div style="background: linear-gradient(135deg, #059669 0%, #047857 100%); padding: 25px; text-align: center; color: white;">
      <h2 style="margin: 0; font-size: 20px;">Eco Divine Beach Resort</h2>
      <p style="margin: 4px 0 0; font-size: 12px; text-transform: uppercase; color: #a7f3d0;">Saint Martin Island, Bangladesh</p>
    </div>
    <div style="padding: 25px;">
      <div style="display: inline-block; background: #ecfdf5; border: 1px solid #6ee7b7; color: #047857; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: bold; margin-bottom: 12px;">
        ✓ SMTP SYSTEM TEST
      </div>
      <h3 style="margin: 0 0 10px; color: #0f172a;">Hello, {$testName}!</h3>
      <p style="color: #475569; font-size: 14px; line-height: 1.6;">
        Congratulations! Your email integration with Eco Divine Beach Resort is working properly. The system is now equipped to send automated booking confirmations and payment receipts to guests.
      </p>
      <div style="background: #f1f5f9; padding: 12px 16px; border-radius: 8px; font-size: 12.5px; color: #334155; margin: 15px 0;">
        <strong>Verified At:</strong> {$now}<br>
        <strong>Resort Desk:</strong> +880 {$hotline}
      </div>
    </div>
    <div style="background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 15px; text-align: center; font-size: 11px; color: #64748b;">
      Eco Divine Beach Resort &bull; Automated System Verification
    </div>
  </div>
</body>
</html>
HTML;

        $result = sendResortEmail($testEmail, $testName, $subject, $html, '', 'test', $adminId);

        echo json_encode([
            'success'  => $result['success'],
            'status'   => $result['status'],
            'message'  => $result['status'] === 'sent' 
                ? 'Test email dispatched successfully! Please check your inbox or spam folder.' 
                : ($result['status'] === 'queued' ? 'Test email queued (SMTP credentials not yet configured).' : 'Test email failed to send.'),
            'response' => $result['response']
        ]);
        exit;

    // ----------------------------------------------------
    // 5. FETCH RECENT LOGS
    // ----------------------------------------------------
    case 'get_logs':
        $smsLogs = [];
        $emailLogs = [];

        $r1 = $conn->query("SELECT * FROM sms_logs ORDER BY id DESC LIMIT 15");
        if ($r1) {
            while ($row = $r1->fetch_assoc()) {
                $smsLogs[] = [
                    'id'         => $row['id'],
                    'name'       => $row['recipient_name'],
                    'phone'      => $row['recipient_phone'],
                    'message'    => $row['message'],
                    'status'     => $row['status'],
                    'response'   => $row['gateway_response'],
                    'created_at' => date('d M, h:i A', strtotime($row['created_at']))
                ];
            }
        }

        $r2 = $conn->query("SELECT * FROM email_logs ORDER BY id DESC LIMIT 15");
        if ($r2) {
            while ($row = $r2->fetch_assoc()) {
                $emailLogs[] = [
                    'id'         => $row['id'],
                    'email'      => $row['recipient_email'],
                    'name'       => $row['recipient_name'],
                    'subject'    => $row['subject'],
                    'type'       => $row['email_type'],
                    'status'     => $row['status'],
                    'response'   => $row['server_response'],
                    'created_at' => date('d M, h:i A', strtotime($row['created_at']))
                ];
            }
        }

        echo json_encode([
            'success'    => true,
            'sms_logs'   => $smsLogs,
            'email_logs' => $emailLogs
        ]);
        exit;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action specified.']);
        exit;
}
