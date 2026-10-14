<?php
// admin/includes/integrations.php
// Centralized Hub for SMS Gateways, SMTP Email Engine, Settings Management, and Audit Logging

if (!defined('ECO_INTEGRATIONS_LOADED')) {
    define('ECO_INTEGRATIONS_LOADED', true);
}

require_once __DIR__ . '/db.php';

/**
 * Fetch all system integration settings as an associative key-value array
 */
function getSystemSettings() {
    global $conn;
    $settings = [];
    $res = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $settings;
}

/**
 * Get a single setting by key with optional fallback
 */
function getSystemSetting($key, $default = '') {
    global $conn;
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $stmt->close();
            return $row['setting_value'] !== null ? $row['setting_value'] : $default;
        }
        $stmt->close();
    }
    return $default;
}

/**
 * Update a single or multiple system settings
 */
function updateSystemSetting($key, $value) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    if ($stmt) {
        $stmt->bind_param("sss", $key, $value, $value);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
    return false;
}

/**
 * Pure PHP SMTP Client Class (No external dependencies)
 * Supports STARTTLS, SSL, AUTH LOGIN, and robust error trapping
 */
class EcoSMTPMailer {
    private $host;
    private $port;
    private $crypto;
    private $username;
    private $password;
    private $timeout = 15;
    private $socket = null;
    private $debugLog = [];

    public function __construct($host, $port, $crypto, $username, $password) {
        $this->host     = trim($host);
        $this->port     = intval($port);
        $this->crypto   = strtolower(trim($crypto)); // 'tls', 'ssl', or 'none'
        $this->username = trim($username);
        $this->password = trim($password);
    }

    public function getLog() {
        return implode("\n", $this->debugLog);
    }

    private function log($msg) {
        $this->debugLog[] = date('H:i:s') . ' ' . $msg;
    }

    private function readResponse() {
        $response = '';
        while ($str = fgets($this->socket, 515)) {
            $response .= $str;
            if (substr($str, 3, 1) === ' ') {
                break;
            }
        }
        $this->log("<< " . trim($response));
        return $response;
    }

    private function sendCommand($cmd, $expectedCode = 250) {
        $this->log(">> " . (strpos($cmd, 'AUTH') === 0 || strpos($cmd, 'PASS') === 0 ? '***CREDENTIAL***' : $cmd));
        fputs($this->socket, $cmd . "\r\n");
        $res = $this->readResponse();
        $code = intval(substr($res, 0, 3));
        if ($code !== $expectedCode && !in_array($code, (array)$expectedCode)) {
            throw new Exception("SMTP Error: Expected $expectedCode but received: $res");
        }
        return $res;
    }

    public function send($fromEmail, $fromName, $toEmail, $toName, $subject, $htmlBody, $plainBody = '') {
        $connectHost = ($this->crypto === 'ssl') ? ('ssl://' . $this->host) : $this->host;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true
            ]
        ]);

        $this->log("Connecting to $connectHost:{$this->port}...");
        $this->socket = @stream_socket_client($connectHost . ':' . $this->port, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$this->socket) {
            throw new Exception("Could not connect to SMTP server {$this->host}:{$this->port} ($errno: $errstr)");
        }

        stream_set_timeout($this->socket, $this->timeout);
        $greeting = $this->readResponse();
        if (substr($greeting, 0, 3) !== '220') {
            throw new Exception("Invalid greeting from SMTP server: $greeting");
        }

        $clientDomain = !empty($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'ecodivine.local';
        $this->sendCommand("EHLO $clientDomain", [250]);

        // If TLS is requested and server supports STARTTLS
        if ($this->crypto === 'tls') {
            $this->sendCommand("STARTTLS", 220);
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            $secure = stream_socket_enable_crypto($this->socket, true, $cryptoMethod);
            if (!$secure) {
                throw new Exception("STARTTLS negotiation failed with server.");
            }
            $this->sendCommand("EHLO $clientDomain", [250]);
        }

        // Authentication if credentials supplied
        if (!empty($this->username)) {
            $this->sendCommand("AUTH LOGIN", 334);
            $this->sendCommand(base64_encode($this->username), 334);
            $this->sendCommand(base64_encode($this->password), 235);
        }

        // Envelope
        $this->sendCommand("MAIL FROM: <$fromEmail>", 250);
        $this->sendCommand("RCPT TO: <$toEmail>", [250, 251]);
        $this->sendCommand("DATA", 354);

        // Headers & MIME Multipart
        $boundary = "====_EcoDivine_" . md5(uniqid(time())) . "_====";
        $headers = [];
        $headers[] = "Date: " . date('r');
        $headers[] = "From: " . '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>';
        $headers[] = "To: " . '=?UTF-8?B?' . base64_encode($toName ?: $toEmail) . '?= <' . $toEmail . '>';
        $headers[] = "Subject: " . '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: multipart/alternative; boundary=\"$boundary\"";
        $headers[] = "X-Mailer: EcoDivine-Resort-Engine/2.0";

        if (empty($plainBody)) {
            $plainBody = strip_tags(str_replace(['<br>', '<p>', '</p>'], ["\n", "\n", "\n\n"], $htmlBody));
        }

        $messageBody  = implode("\r\n", $headers) . "\r\n\r\n";
        $messageBody .= "--$boundary\r\n";
        $messageBody .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $messageBody .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $messageBody .= chunk_split(base64_encode($plainBody)) . "\r\n";

        $messageBody .= "--$boundary\r\n";
        $messageBody .= "Content-Type: text/html; charset=UTF-8\r\n";
        $messageBody .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $messageBody .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $messageBody .= "--$boundary--\r\n";

        // End of DATA marker
        fputs($this->socket, $messageBody . "\r\n.\r\n");
        $res = $this->readResponse();
        if (substr($res, 0, 3) !== '250') {
            throw new Exception("Error during message transmission: $res");
        }

        $this->sendCommand("QUIT", [221, 250]);
        fclose($this->socket);
        return true;
    }
}

/**
 * Helper to transliterate Bengali cottage titles into clean English for standard SMS delivery
 */
function translateCottageForSms($name) {
    if (empty($name)) return 'Resort Cottage';
    $map = [
        'অরুনিকা'     => 'Orunika',
        'তিলোত্তমা'    => 'Tilottoma',
        'ছায়াবীথি'    => 'Chayabithi',
        'নয়নতারা'     => 'Noyontara',
        'জলসা'       => 'Jolsha',
        'রুপকথা'      => 'Rupkotha',
        'কোরাল-০১'    => 'Coral-01',
        'কোরাল-০২'    => 'Coral-02',
        'লবস্টার-০৩'   => 'Lobster-03',
        'লবস্টার-০৪'   => 'Lobster-04',
        'রূপচাঁদা-০৫'  => 'Rupchanda-05',
        'রূপচাঁদা-০৬'  => 'Rupchanda-06',
    ];
    return strtr($name, $map);
}

/**
 * Dispatch Resort SMS via configured Gateway
 */
function sendResortSms($phone, $message, $recipientName = 'Guest', $smsType = 'single', $adminId = null) {
    global $conn;

    $settings = getSystemSettings();
    $smsEnabled  = ($settings['sms_enabled'] ?? '1') === '1';
    $provider    = $settings['sms_provider'] ?? 'greenweb';
    $apiKey      = trim($settings['sms_api_key'] ?? '');
    $senderId    = trim($settings['sms_sender_id'] ?? 'EcoDivine');
    $apiUrl      = trim($settings['sms_api_url'] ?? '');

    // Phone sanitization for Bangladesh
    $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);
    if (strpos($cleanPhone, '01') === 0 && strlen($cleanPhone) === 11) {
        $bangladeshPhone = '88' . $cleanPhone; // 8801...
        $plusPhone = '+' . $bangladeshPhone;
    } elseif (strpos($cleanPhone, '+8801') === 0) {
        $bangladeshPhone = substr($cleanPhone, 1);
        $plusPhone = $cleanPhone;
    } else {
        $bangladeshPhone = $cleanPhone;
        $plusPhone = $cleanPhone;
    }

    $status = 'sent';
    $gatewayResponse = '';

    if (!$smsEnabled) {
        $status = 'queued';
        $gatewayResponse = 'SMS sending is disabled in system settings.';
    } elseif (empty($apiKey)) {
        // Gateway credentials not configured yet: log as simulated dispatch
        $status = 'queued';
        $gatewayResponse = 'Simulated Delivery: SMS gateway API Key is not set yet in Settings. Message recorded in system log.';
    } else {
        // Real HTTP dispatch via cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        if ($provider === 'greenweb') {
            $endpoint = !empty($apiUrl) ? $apiUrl : 'https://api.greenweb.com.bd/api.php';
            $params = [
                'token'   => $apiKey,
                'to'      => $bangladeshPhone,
                'message' => $message
            ];
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } elseif ($provider === 'bulksmsbd') {
            $endpoint = !empty($apiUrl) ? $apiUrl : 'http://bulksmsbd.net/api/smsapi';
            $isUnicode = (bool)preg_match('/[^\x20-\x7E\t\r\n]/', $message);
            $params = [
                'api_key'  => $apiKey,
                'type'     => $isUnicode ? 'unicode' : 'text',
                'number'   => $bangladeshPhone,
                'senderid' => $senderId,
                'message'  => $message
            ];
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } elseif ($provider === 'mimsms') {
            $endpoint = !empty($apiUrl) ? $apiUrl : 'https://api.mimsms.com/api/v1/send';
            $payload = json_encode([
                'ApiKey'     => $apiKey,
                'Sender'     => $senderId,
                'Mobile'     => $bangladeshPhone,
                'TextMessage'=> $message
            ]);
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        } else {
            // Custom API: interpolate placeholders in URL or POST body
            $endpoint = str_replace(
                ['{apikey}', '{senderid}', '{phone}', '{message}'],
                [urlencode($apiKey), urlencode($senderId), urlencode($bangladeshPhone), urlencode($message)],
                $apiUrl
            );
            curl_setopt($ch, CURLOPT_URL, $endpoint);
        }

        $res = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr) {
            $status = 'failed';
            $gatewayResponse = 'cURL Error (' . $httpCode . '): ' . $curlErr;
        } else {
            $status = 'sent';
            $gatewayResponse = 'Gateway (' . $httpCode . '): ' . substr($res, 0, 300);
        }
    }

    // Audit Logging into sms_logs
    if ($conn) {
        $logStmt = $conn->prepare("INSERT INTO sms_logs (recipient_name, recipient_phone, message, sms_type, sent_by, status, gateway_response, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        if ($logStmt) {
            $adm = $adminId ? intval($adminId) : (isset($_SESSION['admin_id']) ? intval($_SESSION['admin_id']) : null);
            $logStmt->bind_param("ssssiss", $recipientName, $plusPhone, $message, $smsType, $adm, $status, $gatewayResponse);
            $logStmt->execute();
            $logStmt->close();
        }
    }

    return [
        'success'  => ($status === 'sent' || $status === 'queued'),
        'status'   => $status,
        'response' => $gatewayResponse
    ];
}

/**
 * Dispatch Resort Email via SMTP or PHP mail() with Audit Logging
 */
function sendResortEmail($toEmail, $toName, $subject, $htmlBody, $plainBody = '', $emailType = 'notification', $adminId = null) {
    global $conn;

    $settings = getSystemSettings();
    $emailEnabled = ($settings['email_enabled'] ?? '1') === '1';
    $engine       = strtolower($settings['email_engine'] ?? 'smtp');
    $smtpHost     = trim($settings['email_smtp_host'] ?? 'smtp.gmail.com');
    $smtpPort     = intval($settings['email_smtp_port'] ?? 587);
    $smtpCrypto   = strtolower(trim($settings['email_smtp_crypto'] ?? 'tls'));
    $smtpUser     = trim($settings['email_smtp_user'] ?? '');
    $smtpPass     = trim($settings['email_smtp_pass'] ?? '');
    $fromName     = trim($settings['email_from_name'] ?? 'Eco Divine Beach Resort');
    $fromEmail    = trim($settings['email_from_address'] ?? 'reservations@ecodivine.com');

    $status = 'sent';
    $serverResponse = '';

    if (!$emailEnabled) {
        $status = 'queued';
        $serverResponse = 'Email system is disabled in system settings.';
    } elseif ($engine === 'smtp') {
        if (empty($smtpHost)) {
            $status = 'failed';
            $serverResponse = 'SMTP Host is not defined in system settings.';
        } elseif (empty($smtpUser) && ($smtpPort == 465 || $smtpPort == 587)) {
            // Unconfigured credentials: record simulated queue
            $status = 'queued';
            $serverResponse = 'Simulated Delivery: SMTP credentials are not yet saved in Settings. Message recorded in system log.';
        } else {
            try {
                $mailer = new EcoSMTPMailer($smtpHost, $smtpPort, $smtpCrypto, $smtpUser, $smtpPass);
                $mailer->send($fromEmail, $fromName, $toEmail, $toName, $subject, $htmlBody, $plainBody);
                $status = 'sent';
                $serverResponse = 'Sent successfully via SMTP (' . $smtpHost . ')';
            } catch (Exception $e) {
                $status = 'failed';
                $serverResponse = 'SMTP Exception: ' . $e->getMessage();
            }
        }
    } else {
        // Fallback: Native PHP mail()
        $boundary = "==_EcoDivine_" . md5(uniqid(time()));
        $headers = [];
        $headers[] = "From: " . '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>';
        $headers[] = "Reply-To: " . $fromEmail;
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: multipart/alternative; boundary=\"$boundary\"";
        $headers[] = "X-Mailer: PHP/" . phpversion();

        if (empty($plainBody)) {
            $plainBody = strip_tags(str_replace(['<br>', '<p>', '</p>'], ["\n", "\n", "\n\n"], $htmlBody));
        }

        $body  = "--$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $body .= $plainBody . "\r\n\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";
        $body .= "--$boundary--";

        $mailOk = @mail($toEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
        if ($mailOk) {
            $status = 'sent';
            $serverResponse = 'Dispatched via native PHP mail() service.';
        } else {
            $status = 'failed';
            $serverResponse = 'PHP mail() dispatch failed. Check sendmail configuration or switch to SMTP.';
        }
    }

    // Audit Logging into email_logs
    if ($conn) {
        $bodyPreview = substr(strip_tags($htmlBody), 0, 250);
        $adm = $adminId ? intval($adminId) : (isset($_SESSION['admin_id']) ? intval($_SESSION['admin_id']) : null);
        $stmt = $conn->prepare("INSERT INTO email_logs (recipient_email, recipient_name, subject, body_preview, email_type, status, server_response, sent_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("sssssssi", $toEmail, $toName, $subject, $bodyPreview, $emailType, $status, $serverResponse, $adm);
            $stmt->execute();
            $stmt->close();
        }
    }

    return [
        'success'  => ($status === 'sent' || $status === 'queued'),
        'status'   => $status,
        'response' => $serverResponse
    ];
}

/**
 * Build Branded Booking Confirmation HTML Email
 */
function buildBookingConfirmationEmailHtml($data) {
    $guestName     = htmlspecialchars($data['guest_name'] ?? 'Valued Guest');
    $bookingId     = htmlspecialchars($data['booking_id'] ?? 'ECO-RES');
    $cottageName   = htmlspecialchars($data['cottage_name'] ?? 'Resort Cottage');
    $checkIn       = htmlspecialchars($data['check_in'] ?? 'Today');
    $checkOut      = htmlspecialchars($data['check_out'] ?? 'Tomorrow');
    $totalBill     = number_format(floatval($data['total_bill'] ?? 0));
    $collected     = number_format(floatval($data['collected_amount'] ?? 0));
    $dueAmount     = number_format(floatval($data['due_amount'] ?? 0));
    $invoiceUrl    = htmlspecialchars($data['invoice_url'] ?? '#');
    $hotline       = htmlspecialchars(getSystemSetting('sms_hotline', '01817838383'));

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Booking Confirmed</title></head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1e293b;">
<table width="100%" cellspacing="0" cellpadding="0" style="background-color:#f1f5f9;padding:30px 10px;">
  <tr><td align="center">
    <table width="600" cellspacing="0" cellpadding="0" style="background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 10px 25px rgba(0,0,0,0.06);border:1px solid #e2e8f0;">
      <tr>
        <td style="background:linear-gradient(135deg,#059669 0%,#047857 100%);padding:35px 30px;text-align:center;">
          <h1 style="margin:0;color:#ffffff;font-size:26px;font-weight:800;letter-spacing:1px;">ECO DIVINE BEACH RESORT</h1>
          <p style="margin:6px 0 0;color:#a7f3d0;font-size:13px;text-transform:uppercase;letter-spacing:2px;">Saint Martin Island, Bangladesh</p>
        </td>
      </tr>
      <tr>
        <td style="padding:35px 35px 20px;">
          <div style="display:inline-block;background-color:#ecfdf5;border:1px solid #a7f3d0;border-radius:30px;padding:6px 16px;font-size:12px;font-weight:700;color:#059669;margin-bottom:18px;">
            ✓ BOOKING CONFIRMED &bull; #{$bookingId}
          </div>
          <h2 style="margin:0 0 12px;font-size:20px;color:#0f172a;">Dear {$guestName},</h2>
          <p style="margin:0;font-size:14.5px;line-height:1.6;color:#475569;">
            Your reservation at <strong>Eco Divine Beach Resort</strong> has been successfully confirmed! We are getting your cottage ready for a delightful beachfront stay.
          </p>
        </td>
      </tr>
      <tr>
        <td style="padding:0 35px 25px;">
          <table width="100%" cellspacing="0" cellpadding="0" style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
            <tr><td colspan="2" style="padding-bottom:12px;border-bottom:1px solid #e2e8f0;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Reservation Details</td></tr>
            <tr><td style="padding:10px 0 4px;font-size:13.5px;color:#64748b;">Reserved Cottage:</td><td align="right" style="padding:10px 0 4px;font-size:14px;font-weight:700;color:#0f172a;">{$cottageName}</td></tr>
            <tr><td style="padding:4px 0;font-size:13.5px;color:#64748b;">Check-In:</td><td align="right" style="padding:4px 0;font-size:14px;font-weight:600;color:#0f172a;">{$checkIn} (01:00 PM)</td></tr>
            <tr><td style="padding:4px 0 10px;font-size:13.5px;color:#64748b;">Check-Out:</td><td align="right" style="padding:4px 0 10px;font-size:14px;font-weight:600;color:#0f172a;">{$checkOut} (10:30 AM)</td></tr>
            <tr><td colspan="2" style="border-top:1px dashed #cbd5e1;padding-top:8px;"></td></tr>
            <tr><td style="padding:4px 0;font-size:13.5px;color:#64748b;">Total Bill:</td><td align="right" style="padding:4px 0;font-size:14px;font-weight:700;color:#0f172a;">৳ {$totalBill}</td></tr>
            <tr><td style="padding:4px 0;font-size:13.5px;color:#059669;font-weight:600;">Collected Amount:</td><td align="right" style="padding:4px 0;font-size:14px;font-weight:700;color:#059669;">৳ {$collected}</td></tr>
            <tr><td style="padding:6px 0;font-size:14px;font-weight:700;color:#d97706;">Remaining Due:</td><td align="right" style="padding:6px 0;font-size:16px;font-weight:800;color:#d97706;">৳ {$dueAmount}</td></tr>
          </table>
          <div style="background-color:#fef2f2;border:1px solid #fecaca;border-left:4px solid #ef4444;padding:10px 14px;border-radius:6px;margin:14px 0 0;font-size:13px;color:#991b1b;font-weight:600;">
            ⚠️ Notice: Advance Booking Money is non-refundable.
          </div>
        </td>
      </tr>
      <tr>
        <td align="center" style="padding:0 35px 30px;">
          <a href="{$invoiceUrl}" target="_blank" style="display:inline-block;background-color:#059669;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 30px;border-radius:30px;box-shadow:0 4px 12px rgba(5,150,105,0.25);">
            📄 Download Official Invoice Receipt
          </a>
        </td>
      </tr>
      <tr>
        <td style="background-color:#f8fafc;border-top:1px solid #e2e8f0;padding:22px 35px;text-align:center;">
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
}

/**
 * Build Branded Payment Receipt HTML Email
 */
function buildPaymentReceiptEmailHtml($data) {
    $guestName     = htmlspecialchars($data['guest_name'] ?? 'Valued Guest');
    $bookingId     = htmlspecialchars($data['booking_id'] ?? 'ECO-RES');
    $amountPaid    = number_format(floatval($data['amount_paid'] ?? 0));
    $totalBill     = number_format(floatval($data['total_bill'] ?? 0));
    $collected     = number_format(floatval($data['collected_amount'] ?? 0));
    $dueAmount     = number_format(floatval($data['due_amount'] ?? 0));
    $method        = htmlspecialchars($data['payment_method'] ?? 'Cash at Front Desk');
    $trxId         = htmlspecialchars($data['trx_id'] ?? 'N/A');
    $invoiceUrl    = htmlspecialchars($data['invoice_url'] ?? '#');
    $hotline       = htmlspecialchars(getSystemSetting('sms_hotline', '01817838383'));
    $isPaidFull    = floatval($data['due_amount'] ?? 0) <= 0;

    $dueBadge = $isPaidFull 
        ? '<span style="color:#059669;font-weight:bold;">৳ 0 (PAID IN FULL ✓)</span>' 
        : '<span style="color:#d97706;font-weight:bold;">৳ ' . $dueAmount . '</span>';

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Payment Receipt</title></head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1e293b;">
<table width="100%" cellspacing="0" cellpadding="0" style="background-color:#f1f5f9;padding:30px 10px;">
  <tr><td align="center">
    <table width="600" cellspacing="0" cellpadding="0" style="background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 10px 25px rgba(0,0,0,0.06);border:1px solid #e2e8f0;">
      <tr>
        <td style="background:linear-gradient(135deg,#059669 0%,#065f46 100%);padding:30px;text-align:center;">
          <h1 style="margin:0;color:#ffffff;font-size:24px;font-weight:800;letter-spacing:1px;">ECO DIVINE BEACH RESORT</h1>
          <p style="margin:5px 0 0;color:#a7f3d0;font-size:12px;text-transform:uppercase;letter-spacing:2px;">Official Payment Receipt</p>
        </td>
      </tr>
      <tr>
        <td style="padding:35px 35px 20px;">
          <div style="display:inline-block;background-color:#ecfdf5;border:1px solid #6ee7b7;border-radius:30px;padding:6px 14px;font-size:12px;font-weight:700;color:#047857;margin-bottom:16px;">
            ✓ PAYMENT RECEIVED &bull; #{$bookingId}
          </div>
          <h2 style="margin:0 0 10px;font-size:20px;color:#0f172a;">Dear {$guestName},</h2>
          <p style="margin:0;font-size:14.5px;line-height:1.6;color:#475569;">
            Thank you! We have received your payment of <strong>৳ {$amountPaid}</strong>. Your updated account status is detailed below.
          </p>
        </td>
      </tr>
      <tr>
        <td style="padding:0 35px 25px;">
          <table width="100%" cellspacing="0" cellpadding="0" style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:20px;">
            <tr><td colspan="2" style="padding-bottom:10px;border-bottom:1px solid #e2e8f0;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Payment Details</td></tr>
            <tr><td style="padding:10px 0 4px;font-size:13px;color:#64748b;">Method:</td><td align="right" style="padding:10px 0 4px;font-size:13.5px;font-weight:600;color:#0f172a;">{$method}</td></tr>
            <tr><td style="padding:4px 0 10px;font-size:13px;color:#64748b;">Transaction / Slip ID:</td><td align="right" style="padding:4px 0 10px;font-size:13.5px;font-weight:600;color:#0f172a;">{$trxId}</td></tr>
            <tr><td colspan="2" style="border-top:1px dashed #cbd5e1;padding-top:8px;"></td></tr>
            <tr><td style="padding:6px 0;font-size:14px;font-weight:700;color:#059669;">Payment Recorded:</td><td align="right" style="padding:6px 0;font-size:16px;font-weight:800;color:#059669;">+ ৳ {$amountPaid}</td></tr>
            <tr><td style="padding:4px 0;font-size:13px;color:#64748b;">Total Bill:</td><td align="right" style="padding:4px 0;font-size:13.5px;font-weight:600;color:#0f172a;">৳ {$totalBill}</td></tr>
            <tr><td style="padding:4px 0;font-size:13px;color:#64748b;">Total Collected:</td><td align="right" style="padding:4px 0;font-size:13.5px;font-weight:600;color:#0f172a;">৳ {$collected}</td></tr>
            <tr><td style="padding:6px 0 4px;font-size:14px;font-weight:700;color:#0f172a;">Remaining Due:</td><td align="right" style="padding:6px 0 4px;font-size:15px;">{$dueBadge}</td></tr>
          </table>
        </td>
      </tr>
      <tr>
        <td align="center" style="padding:0 35px 30px;">
          <a href="{$invoiceUrl}" target="_blank" style="display:inline-block;background-color:#0f172a;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 30px;border-radius:30px;box-shadow:0 4px 12px rgba(15,23,42,0.2);">
            📄 View / Download Tax Invoice
          </a>
        </td>
      </tr>
      <tr>
        <td style="background-color:#f8fafc;border-top:1px solid #e2e8f0;padding:22px 35px;text-align:center;">
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
}
