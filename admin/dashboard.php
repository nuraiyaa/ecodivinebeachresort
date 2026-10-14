<?php
// admin/dashboard.php
// Pure White & Responsive Island Resort Management Dashboard
if (!ob_get_level()) {
    ob_start();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure admin is authenticated
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php?msg=unauthorized');
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/integrations.php';
date_default_timezone_set('Asia/Dhaka');

// ========== HANDLE ADMIN MAKE BOOKING POST ========== //
$booking_msg = $_GET['msg'] ?? '';
$booking_error = $_GET['err'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_booking'])) {
    // Extract cottage IDs (can be multiple checkboxes or fallback single)
    $cottage_ids = [];
    if (isset($_POST['cottage_ids']) && is_array($_POST['cottage_ids'])) {
        $cottage_ids = array_filter(array_map('intval', $_POST['cottage_ids']));
    } elseif (!empty($_POST['cottage_id'])) {
        $cottage_ids = [intval($_POST['cottage_id'])];
    }

    $cust_name       = trim($_POST['customer_name'] ?? '');
    $cust_phone      = trim($_POST['customer_phone'] ?? '');
    $cust_email      = trim($_POST['customer_email'] ?? '');
    $check_in        = trim($_POST['check_in'] ?? '');
    $check_out       = trim($_POST['check_out'] ?? '');
    $total_amount    = floatval($_POST['total_amount'] ?? 0);
    $advance_amount  = floatval($_POST['advance_amount'] ?? 0);
    $discount_amount = floatval($_POST['discount_amount'] ?? 0);
    $due_amount      = max(0, $total_amount - $advance_amount - $discount_amount);
    $payment_method  = trim($_POST['payment_method'] ?? 'Cash at Front Desk');
    $notes           = trim($_POST['notes'] ?? '');
    $booking_status  = in_array($_POST['status'] ?? '', ['accepted', 'pending']) ? $_POST['status'] : 'accepted';
    $admin_id        = $_SESSION['admin_id'] ?? null;

    if (!empty($cottage_ids) && !empty($cust_name) && !empty($cust_phone) && !empty($check_in) && !empty($check_out)) {
        // Ensure check_out is strictly after check_in
        if ($check_out <= $check_in) {
            $check_out = date('Y-m-d', strtotime($check_in . ' +1 day'));
        }

        // Conflict check for all selected cottages
        $c_in_list = implode(',', $cottage_ids);
        $chk_q = "
            SELECT DISTINCT c.title 
            FROM booking_cottages bc
            JOIN bookings b ON bc.booking_id = b.id
            JOIN cottages c ON bc.cottage_id = c.id
            WHERE bc.cottage_id IN ($c_in_list)
              AND b.status IN ('accepted', 'pending')
              AND b.check_in < ? AND b.check_out > ?
            UNION
            SELECT DISTINCT c.title
            FROM bookings b
            JOIN cottages c ON b.cottage_id = c.id
            WHERE b.cottage_id IN ($c_in_list)
              AND b.status IN ('accepted', 'pending')
              AND b.check_in < ? AND b.check_out > ?
        ";
        $c_stmt = $conn->prepare($chk_q);
        $c_stmt->bind_param("ssss", $check_out, $check_in, $check_out, $check_in);
        $c_stmt->execute();
        $conflicts = $c_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $c_stmt->close();

        if (!empty($conflicts)) {
            $conflict_names = implode(', ', array_column($conflicts, 'title'));
            $booking_error = "Booking Conflict: Cottage(s) [$conflict_names] are already booked for the selected dates ($check_in to $check_out). Please choose available cottages.";
        } else {
            $primary_cottage_id = reset($cottage_ids);
            $accepted_at = ($booking_status === 'accepted') ? date('Y-m-d H:i:s') : null;
            $accepted_by = ($booking_status === 'accepted') ? $admin_id : null;

            $stmt = $conn->prepare("INSERT INTO bookings (cottage_id, customer_name, customer_email, customer_phone, check_in, check_out, total_amount, advance_amount, discount_amount, due_amount, payment_method, notes, status, accepted_by, accepted_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("isssssddddsssis", $primary_cottage_id, $cust_name, $cust_email, $cust_phone, $check_in, $check_out, $total_amount, $advance_amount, $discount_amount, $due_amount, $payment_method, $notes, $booking_status, $accepted_by, $accepted_at);
            
            if ($stmt->execute()) {
                $new_booking_id = $stmt->insert_id;
                $stmt->close();

                // Save all cottages into booking_cottages table
                $c_info_res = $conn->query("SELECT id, title, price, weekend_price, is_weekend FROM cottages WHERE id IN ($c_in_list)");
                $cottage_titles = [];
                $stmt_bc = $conn->prepare("INSERT INTO booking_cottages (booking_id, cottage_id, price) VALUES (?, ?, ?)");

                while ($crow = $c_info_res->fetch_assoc()) {
                    $cottage_titles[] = $crow['title'];
                    $cid = (int)$crow['id'];
                    $isWk = ((int)($crow['is_weekend'] ?? 0) === 1);
                    $rate = ($isWk && !empty($crow['weekend_price'])) ? floatval($crow['weekend_price']) : floatval($crow['price']);
                    $stmt_bc->bind_param("iid", $new_booking_id, $cid, $rate);
                    $stmt_bc->execute();
                }
                $stmt_bc->close();

                $all_cottages_str = implode(', ', $cottage_titles) ?: 'Resort Cottage';

                // Sync with customers table if accepted
                if ($booking_status === 'accepted') {
                    $b_code = 'ECO' . str_pad($new_booking_id, 4, '0', STR_PAD_LEFT);
                    $payment_status = ($due_amount <= 0 && $total_amount > 0) ? 'paid' : ($advance_amount > 0 ? 'partial' : 'unpaid');
                    $guest_count = max(2, count($cottage_ids) * 2);

                    $stmt_cust = $conn->prepare("INSERT INTO customers (name, email, phone, cottage_name, check_in, check_out, guest_count, booking_id, total_amount, advance_amount, discount_amount, due_amount, payment_method, notes, payment_status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt_cust->bind_param("ssssssisddddsss", $cust_name, $cust_email, $cust_phone, $all_cottages_str, $check_in, $check_out, $guest_count, $b_code, $total_amount, $advance_amount, $discount_amount, $due_amount, $payment_method, $notes, $payment_status);
                    $stmt_cust->execute();
                    $stmt_cust->close();
                }

                header("Location: dashboard.php?msg=booking_created#view-bookings");
                exit;
            } else {
                $booking_error = "Error creating booking: " . $conn->error;
            }
        }
    } else {
        $booking_error = "Please fill in all required fields and select at least one available cottage.";
    }
}

// ========== HANDLE CONFIRM BOOKING & RECORD PAYMENT POST ========== //
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_confirm_booking'])) {
    $booking_id      = intval($_POST['booking_id'] ?? 0);
    $total_bill      = floatval($_POST['total_bill'] ?? 0);
    $advance_amount  = floatval($_POST['advance_amount'] ?? 0);
    $discount_amount = floatval($_POST['discount_amount'] ?? 0);
    $due_amount      = max(0, $total_bill - $advance_amount - $discount_amount);
    $payment_method  = trim($_POST['payment_method'] ?? 'bKash (Personal / Merchant)');
    $notes           = trim($_POST['notes'] ?? '');
    $admin_id        = $_SESSION['admin_id'] ?? null;
    $accepted_at     = date('Y-m-d H:i:s');

    if ($booking_id > 0) {
        $b_stmt = $conn->prepare("SELECT b.*, c.title AS cottage_title FROM bookings b LEFT JOIN cottages c ON b.cottage_id = c.id WHERE b.id = ?");
        $b_stmt->bind_param("i", $booking_id);
        $b_stmt->execute();
        $curr_b = $b_stmt->get_result()->fetch_assoc();
        $b_stmt->close();

        if ($curr_b) {
            // 1. Update bookings table
            $upd = $conn->prepare("UPDATE bookings SET status = 'accepted', accepted_by = ?, accepted_at = ?, total_amount = ?, advance_amount = ?, discount_amount = ?, due_amount = ?, payment_method = ?, notes = ? WHERE id = ?");
            $upd->bind_param("isddddssi", $admin_id, $accepted_at, $total_bill, $advance_amount, $discount_amount, $due_amount, $payment_method, $notes, $booking_id);
            $upd->execute();
            $upd->close();

            // 2. Synchronize with customers table
            $b_code = 'ECO' . str_pad($booking_id, 4, '0', STR_PAD_LEFT);
            $c_phone = $conn->real_escape_string($curr_b['customer_phone']);
            $c_cottage = $curr_b['cottage_title'] ?: 'Resort Cottage';
            $payment_status = ($due_amount <= 0 && $total_bill > 0) ? 'paid' : ($advance_amount > 0 ? 'partial' : 'unpaid');

            $chk = $conn->query("SELECT id FROM customers WHERE booking_id = '$b_code' OR (phone = '$c_phone' AND check_in = '{$curr_b['check_in']}') LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                $c_row = $chk->fetch_assoc();
                $c_id = $c_row['id'];
                $upd_c = $conn->prepare("UPDATE customers SET total_amount = ?, advance_amount = ?, discount_amount = ?, due_amount = ?, payment_method = ?, notes = ?, payment_status = ? WHERE id = ?");
                $upd_c->bind_param("ddddsssi", $total_bill, $advance_amount, $discount_amount, $due_amount, $payment_method, $notes, $payment_status, $c_id);
                $upd_c->execute();
                $upd_c->close();
            } else {
                $ins_c = $conn->prepare("INSERT INTO customers (name, email, phone, cottage_name, check_in, check_out, guest_count, booking_id, total_amount, advance_amount, discount_amount, due_amount, payment_method, notes, payment_status, created_at) VALUES (?, ?, ?, ?, ?, ?, 2, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $ins_c->bind_param("sssssssddddsss", 
                    $curr_b['customer_name'], 
                    $curr_b['customer_email'], 
                    $curr_b['customer_phone'], 
                    $c_cottage, 
                    $curr_b['check_in'], 
                    $curr_b['check_out'], 
                    $b_code, 
                    $total_bill, 
                    $advance_amount, 
                    $discount_amount, 
                    $due_amount, 
                    $payment_method, 
                    $notes, 
                    $payment_status
                );
                $ins_c->execute();
                $ins_c->close();
            }

            // Automated Notifications (SMS & Email)
            $intSettings = getSystemSettings();
            $hotline = $intSettings['sms_hotline'] ?? '01817838383';

            // Dispatch SMS if enabled
            if (($intSettings['sms_on_booking_confirm'] ?? '1') === '1' && !empty($curr_b['customer_phone'])) {
                $smsMsg = "Eco Divine Resort: Booking Confirmed! Ref: {$b_code}. Dear {$curr_b['customer_name']}, cottage {$c_cottage} is reserved for {$curr_b['check_in']} to {$curr_b['check_out']}. Due: ৳" . number_format($due_amount) . ". Advance Booking Money is non-refundable. Hotline: " . $hotline;
                sendResortSms($curr_b['customer_phone'], $smsMsg, $curr_b['customer_name'], 'single', $admin_id);
            }

            // Dispatch Email if enabled
            if (($intSettings['email_on_booking_confirm'] ?? '1') === '1' && !empty($curr_b['customer_email']) && filter_var($curr_b['customer_email'], FILTER_VALIDATE_EMAIL)) {
                $invUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['PHP_SELF'] ?? '') . "/invoice.php?id=" . $booking_id;
                $htmlBody = buildBookingConfirmationEmailHtml([
                    'guest_name'       => $curr_b['customer_name'],
                    'booking_id'       => $b_code,
                    'cottage_name'     => $c_cottage,
                    'check_in'         => $curr_b['check_in'],
                    'check_out'        => $curr_b['check_out'],
                    'total_bill'       => $total_bill,
                    'collected_amount' => $advance_amount,
                    'due_amount'       => $due_amount,
                    'invoice_url'      => $invUrl
                ]);
                sendResortEmail($curr_b['customer_email'], $curr_b['customer_name'], "Booking Confirmed #{$b_code} - Eco Divine Beach Resort", $htmlBody, '', 'booking_confirm', $admin_id);
            }

            header("Location: dashboard.php?msg=confirmed#view-bookings");
            exit;
        } else {
            $booking_error = "Booking record not found.";
        }
    } else {
        $booking_error = "Invalid booking ID.";
    }
}

// ========== HANDLE UPDATE CUSTOMER NOTE POST ========== //
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_customer_note'])) {
    $note_phone   = trim($_POST['phone'] ?? '');
    $note_content = trim($_POST['note'] ?? '');
    if (!empty($note_phone)) {
        $stmt1 = $conn->prepare("UPDATE customers SET notes = ? WHERE phone = ?");
        $stmt1->bind_param("ss", $note_content, $note_phone);
        $stmt1->execute();
        $stmt1->close();

        $stmt2 = $conn->prepare("UPDATE bookings SET notes = ? WHERE customer_phone = ?");
        $stmt2->bind_param("ss", $note_content, $note_phone);
        $stmt2->execute();
        $stmt2->close();

        header("Location: dashboard.php?msg=note_updated#view-customers");
        exit;
    }
}

// ========== HANDLE SEND SMS POST ========== //
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_send_sms'])) {
    $sms_type = trim($_POST['type'] ?? 'single');
    $sms_msg  = trim($_POST['message'] ?? '');
    $admin_id = $_SESSION['admin_id'] ?? null;

    if (!empty($sms_msg)) {
        if ($sms_type === 'broadcast') {
            $b_recipients = $conn->query("
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
            ");
            if ($b_recipients) {
                $b_stmt = $conn->prepare("INSERT INTO sms_logs (recipient_name, recipient_phone, message, sms_type, sent_by, status, gateway_response, created_at) VALUES (?, ?, ?, 'broadcast', ?, 'sent', 'Queued & Dispatched', NOW())");
                while ($br = $b_recipients->fetch_assoc()) {
                    $personalized = str_replace('{Name}', $br['name'], $sms_msg);
                    $b_stmt->bind_param("sssi", $br['name'], $br['phone'], $personalized, $admin_id);
                    $b_stmt->execute();
                }
                $b_stmt->close();
            }
            header("Location: dashboard.php?msg=broadcast_sms_sent#view-customers");
            exit;
        } else {
            $rec_name  = trim($_POST['recipient_name'] ?? 'Guest');
            $rec_phone = trim($_POST['recipient_phone'] ?? '');
            if (!empty($rec_phone)) {
                $personalized = str_replace('{Name}', $rec_name, $sms_msg);
                $s_stmt = $conn->prepare("INSERT INTO sms_logs (recipient_name, recipient_phone, message, sms_type, sent_by, status, gateway_response, created_at) VALUES (?, ?, ?, 'single', ?, 'sent', 'Queued & Dispatched', NOW())");
                $s_stmt->bind_param("sssi", $rec_name, $rec_phone, $personalized, $admin_id);
                $s_stmt->execute();
                $s_stmt->close();
                header("Location: dashboard.php?msg=sms_sent#view-customers");
                exit;
            }
        }
    }
}

// ========== HANDLE CREATE ADMIN POST ========== //
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_admin'])) {
    $name     = trim($_POST['name'] ?? '');
    $username = preg_replace('/[^a-zA-Z0-9_.-]/', '', trim($_POST['username'] ?? ''));
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = trim($_POST['role'] ?? 'admin');

    if (empty($name) || empty($username) || empty($email) || empty($password)) {
        header("Location: dashboard.php?err=" . urlencode("All fields including password are required.") . "#view-admins");
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header("Location: dashboard.php?err=" . urlencode("Invalid email address format.") . "#view-admins");
        exit;
    }

    if (strlen($password) < 6) {
        header("Location: dashboard.php?err=" . urlencode("Password must be at least 6 characters long.") . "#view-admins");
        exit;
    }

    // Check unique username or email
    $chk_stmt = $conn->prepare("SELECT id FROM admins WHERE username = ? OR email = ? LIMIT 1");
    $chk_stmt->bind_param("ss", $username, $email);
    $chk_stmt->execute();
    $chk_res = $chk_stmt->get_result();
    if ($chk_res && $chk_res->num_rows > 0) {
        $chk_stmt->close();
        header("Location: dashboard.php?err=" . urlencode("An admin with this username or email already exists.") . "#view-admins");
        exit;
    }
    $chk_stmt->close();

    $hashed_pw = password_hash($password, PASSWORD_DEFAULT);
    $ins_stmt = $conn->prepare("INSERT INTO admins (name, username, email, password, role, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $ins_stmt->bind_param("sssss", $name, $username, $email, $hashed_pw, $role);
    if ($ins_stmt->execute()) {
        $ins_stmt->close();
        header("Location: dashboard.php?msg=admin_created#view-admins");
        exit;
    } else {
        $err = $conn->error;
        $ins_stmt->close();
        header("Location: dashboard.php?err=" . urlencode("Error creating administrator: " . $err) . "#view-admins");
        exit;
    }
}

// ========== HANDLE EDIT ADMIN POST ========== //
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_edit_admin'])) {
    $edit_id  = intval($_POST['admin_id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $username = preg_replace('/[^a-zA-Z0-9_.-]/', '', trim($_POST['username'] ?? ''));
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = trim($_POST['role'] ?? 'admin');

    if ($edit_id <= 0 || empty($name) || empty($username) || empty($email)) {
        header("Location: dashboard.php?err=" . urlencode("All profile fields are required.") . "#view-admins");
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header("Location: dashboard.php?err=" . urlencode("Invalid email address format.") . "#view-admins");
        exit;
    }

    // Check unique username or email excluding current admin
    $chk_stmt = $conn->prepare("SELECT id FROM admins WHERE (username = ? OR email = ?) AND id != ? LIMIT 1");
    $chk_stmt->bind_param("ssi", $username, $email, $edit_id);
    $chk_stmt->execute();
    $chk_res = $chk_stmt->get_result();
    if ($chk_res && $chk_res->num_rows > 0) {
        $chk_stmt->close();
        header("Location: dashboard.php?err=" . urlencode("Another administrator is already using that username or email.") . "#view-admins");
        exit;
    }
    $chk_stmt->close();

    if (!empty($password)) {
        if (strlen($password) < 6) {
            header("Location: dashboard.php?err=" . urlencode("New password must be at least 6 characters long.") . "#view-admins");
            exit;
        }
        $hashed_pw = password_hash($password, PASSWORD_DEFAULT);
        $upd_stmt = $conn->prepare("UPDATE admins SET name = ?, username = ?, email = ?, password = ?, role = ? WHERE id = ?");
        $upd_stmt->bind_param("sssssi", $name, $username, $email, $hashed_pw, $role, $edit_id);
    } else {
        $upd_stmt = $conn->prepare("UPDATE admins SET name = ?, username = ?, email = ?, role = ? WHERE id = ?");
        $upd_stmt->bind_param("ssssi", $name, $username, $email, $role, $edit_id);
    }

    if ($upd_stmt->execute()) {
        $upd_stmt->close();
        if ($edit_id === (int)($_SESSION['admin_id'] ?? 0)) {
            $_SESSION['admin_name']     = $name;
            $_SESSION['admin_username'] = $username;
            $_SESSION['admin_email']    = $email;
            $_SESSION['admin_role']     = $role;
        }
        header("Location: dashboard.php?msg=admin_updated#view-admins");
        exit;
    } else {
        $err = $conn->error;
        $upd_stmt->close();
        header("Location: dashboard.php?err=" . urlencode("Error updating administrator: " . $err) . "#view-admins");
        exit;
    }
}

// ========== HANDLE DELETE ADMIN POST ========== //
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete_admin'])) {
    $del_id = intval($_POST['admin_id'] ?? 0);
    $my_id  = intval($_SESSION['admin_id'] ?? 0);

    if ($del_id <= 0) {
        header("Location: dashboard.php?err=" . urlencode("Invalid administrator account specified.") . "#view-admins");
        exit;
    }

    if ($del_id === $my_id) {
        header("Location: dashboard.php?err=" . urlencode("Security restriction: You cannot delete your own logged-in account.") . "#view-admins");
        exit;
    }

    $count_res = $conn->query("SELECT COUNT(*) as c FROM admins");
    $total_adm = (int)($count_res->fetch_assoc()['c'] ?? 0);
    if ($total_adm <= 1) {
        header("Location: dashboard.php?err=" . urlencode("Security restriction: Cannot delete the only remaining administrator.") . "#view-admins");
        exit;
    }

    $del_stmt = $conn->prepare("DELETE FROM admins WHERE id = ?");
    $del_stmt->bind_param("i", $del_id);
    if ($del_stmt->execute()) {
        $del_stmt->close();
        header("Location: dashboard.php?msg=admin_deleted#view-admins");
        exit;
    } else {
        $err = $conn->error;
        $del_stmt->close();
        header("Location: dashboard.php?err=" . urlencode("Error deleting administrator: " . $err) . "#view-admins");
        exit;
    }
}

// ========== HANDLE UPDATE PROFILE SETTINGS POST ========== //
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_profile_settings'])) {
    $my_id    = intval($_SESSION['admin_id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $username = preg_replace('/[^a-zA-Z0-9_.-]/', '', trim($_POST['username'] ?? ''));
    $email    = trim($_POST['email'] ?? '');

    if ($my_id <= 0) {
        header("Location: login.php?msg=unauthorized");
        exit;
    }

    if (empty($name) || empty($username) || empty($email)) {
        header("Location: dashboard.php?err=" . urlencode("Name, username, and email are all required.") . "#view-settings");
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header("Location: dashboard.php?err=" . urlencode("Please provide a valid email address.") . "#view-settings");
        exit;
    }

    if (strlen($username) < 3) {
        header("Location: dashboard.php?err=" . urlencode("Username must be at least 3 characters long.") . "#view-settings");
        exit;
    }

    // Check unique username or email excluding current user
    $chk_stmt = $conn->prepare("SELECT id FROM admins WHERE (username = ? OR email = ?) AND id != ? LIMIT 1");
    $chk_stmt->bind_param("ssi", $username, $email, $my_id);
    $chk_stmt->execute();
    $chk_res = $chk_stmt->get_result();
    if ($chk_res && $chk_res->num_rows > 0) {
        $chk_stmt->close();
        header("Location: dashboard.php?err=" . urlencode("Another administrator is already using that username or email address.") . "#view-settings");
        exit;
    }
    $chk_stmt->close();

    $upd_stmt = $conn->prepare("UPDATE admins SET name = ?, username = ?, email = ? WHERE id = ?");
    $upd_stmt->bind_param("sssi", $name, $username, $email, $my_id);
    if ($upd_stmt->execute()) {
        $upd_stmt->close();
        $_SESSION['admin_name']     = $name;
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_email']    = $email;
        header("Location: dashboard.php?msg=profile_updated#view-settings");
        exit;
    } else {
        $err = $conn->error;
        $upd_stmt->close();
        header("Location: dashboard.php?err=" . urlencode("Failed to update profile: " . $err) . "#view-settings");
        exit;
    }
}

// ========== HANDLE UPDATE PROFILE PASSWORD POST ========== //
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_profile_password'])) {
    $my_id        = intval($_SESSION['admin_id'] ?? 0);
    $current_pw   = $_POST['current_password'] ?? '';
    $new_pw       = $_POST['new_password'] ?? '';
    $confirm_pw   = $_POST['confirm_password'] ?? '';

    if ($my_id <= 0) {
        header("Location: login.php?msg=unauthorized");
        exit;
    }

    if (empty($current_pw) || empty($new_pw) || empty($confirm_pw)) {
        header("Location: dashboard.php?err=" . urlencode("Please fill in current password, new password, and confirmation.") . "#view-settings");
        exit;
    }

    if (strlen($new_pw) < 6) {
        header("Location: dashboard.php?err=" . urlencode("New password must be at least 6 characters long.") . "#view-settings");
        exit;
    }

    if ($new_pw !== $confirm_pw) {
        header("Location: dashboard.php?err=" . urlencode("New password and confirmation do not match.") . "#view-settings");
        exit;
    }

    // Verify current password
    $q = $conn->prepare("SELECT password FROM admins WHERE id = ? LIMIT 1");
    $q->bind_param("i", $my_id);
    $q->execute();
    $res = $q->get_result();
    $user_row = $res ? $res->fetch_assoc() : null;
    $q->close();

    if (!$user_row || !password_verify($current_pw, $user_row['password'])) {
        header("Location: dashboard.php?err=" . urlencode("Current password entered is incorrect.") . "#view-settings");
        exit;
    }

    $hashed_new = password_hash($new_pw, PASSWORD_DEFAULT);
    $upd_pw = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
    $upd_pw->bind_param("si", $hashed_new, $my_id);
    if ($upd_pw->execute()) {
        $upd_pw->close();
        header("Location: dashboard.php?msg=password_updated#view-settings");
        exit;
    } else {
        $err = $conn->error;
        $upd_pw->close();
        header("Location: dashboard.php?err=" . urlencode("Failed to change password: " . $err) . "#view-settings");
        exit;
    }
}

// ========== HANDLE UPDATE SMS INTEGRATION SETTINGS POST ========== //
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_sms_settings'])) {
    $provider     = trim($_POST['sms_provider'] ?? 'greenweb');
    $apiKey       = trim($_POST['sms_api_key'] ?? '');
    $senderId     = trim($_POST['sms_sender_id'] ?? 'EcoDivine');
    $apiUrl       = trim($_POST['sms_api_url'] ?? '');
    $hotline      = trim($_POST['sms_hotline'] ?? '01817838383');
    $smsEnabled   = isset($_POST['sms_enabled']) ? '1' : '0';
    $autoConfirm  = isset($_POST['sms_on_booking_confirm']) ? '1' : '0';
    $autoMarkPaid = isset($_POST['sms_on_mark_paid']) ? '1' : '0';

    updateSystemSetting('sms_provider', $provider);
    updateSystemSetting('sms_api_key', $apiKey);
    updateSystemSetting('sms_sender_id', $senderId);
    updateSystemSetting('sms_api_url', $apiUrl);
    updateSystemSetting('sms_hotline', $hotline);
    updateSystemSetting('sms_enabled', $smsEnabled);
    updateSystemSetting('sms_on_booking_confirm', $autoConfirm);
    updateSystemSetting('sms_on_mark_paid', $autoMarkPaid);

    header("Location: dashboard.php?msg=sms_settings_updated#view-settings");
    exit;
}

// ========== HANDLE UPDATE EMAIL INTEGRATION SETTINGS POST ========== //
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_email_settings'])) {
    $engine       = trim($_POST['email_engine'] ?? 'smtp');
    $smtpHost     = trim($_POST['email_smtp_host'] ?? 'smtp.gmail.com');
    $smtpPort     = trim($_POST['email_smtp_port'] ?? '587');
    $smtpCrypto   = trim($_POST['email_smtp_crypto'] ?? 'tls');
    $smtpUser     = trim($_POST['email_smtp_user'] ?? '');
    $smtpPass     = trim($_POST['email_smtp_pass'] ?? '');
    $fromName     = trim($_POST['email_from_name'] ?? 'Eco Divine Beach Resort');
    $fromAddress  = trim($_POST['email_from_address'] ?? 'reservations@ecodivine.com');
    $emailEnabled = isset($_POST['email_enabled']) ? '1' : '0';
    $autoConfirm  = isset($_POST['email_on_booking_confirm']) ? '1' : '0';
    $autoMarkPaid = isset($_POST['email_on_mark_paid']) ? '1' : '0';

    updateSystemSetting('email_engine', $engine);
    updateSystemSetting('email_smtp_host', $smtpHost);
    updateSystemSetting('email_smtp_port', $smtpPort);
    updateSystemSetting('email_smtp_crypto', $smtpCrypto);
    updateSystemSetting('email_smtp_user', $smtpUser);
    if (!empty($smtpPass)) {
        updateSystemSetting('email_smtp_pass', $smtpPass);
    }
    updateSystemSetting('email_from_name', $fromName);
    updateSystemSetting('email_from_address', $fromAddress);
    updateSystemSetting('email_enabled', $emailEnabled);
    updateSystemSetting('email_on_booking_confirm', $autoConfirm);
    updateSystemSetting('email_on_mark_paid', $autoMarkPaid);

    header("Location: dashboard.php?msg=email_settings_updated#view-settings");
    exit;
}

// ========== KPI METRICS DATA ========== //
$totalCottages = 0;
$allCottages = [];
$cottageActiveCount = 0;
$cottageWeekendActiveCount = 0;

$cottagesRes = $conn->query("SELECT * FROM cottages ORDER BY FIELD(title, 'অরুনিকা', 'তিলোত্তমা', 'ছায়াবীথি', 'নয়নতারা', 'জলসা', 'রুপকথা', 'কোরাল-০১', 'কোরাল-০২', 'লবস্টার-০৩', 'লবস্টার-০৪', 'রূপচাঁদা-০৫', 'রূপচাঁদা-০৬'), id ASC");
if ($cottagesRes) {
    while ($row = $cottagesRes->fetch_assoc()) {
        $allCottages[] = $row;
        $totalCottages++;
        if (($row['status'] ?? 'active') === 'active') $cottageActiveCount++;
        if ((int)($row['is_weekend'] ?? 0) === 1) $cottageWeekendActiveCount++;
    }
}
$cottageInactiveCount = $totalCottages - $cottageActiveCount;

// Total Bookings & Status
$totalBookings = (int)($conn->query("SELECT COUNT(*) as c FROM bookings")->fetch_assoc()['c'] ?? 0);
$pendingRequests = (int)($conn->query("SELECT COUNT(*) as c FROM bookings WHERE status='pending'")->fetch_assoc()['c'] ?? 0);
$confirmedBookings = (int)($conn->query("SELECT COUNT(*) as c FROM bookings WHERE status='accepted'")->fetch_assoc()['c'] ?? 0);
$cancelledBookings = (int)($conn->query("SELECT COUNT(*) as c FROM bookings WHERE status='cancelled'")->fetch_assoc()['c'] ?? 0);
$refundedBookingsCount = (int)($conn->query("SELECT COUNT(*) as c FROM bookings WHERE status='cancelled' AND (refund_amount > 0 OR cancellation_fee > 0)")->fetch_assoc()['c'] ?? 0);

// Total Customers
$totalCustomers = (int)($conn->query("SELECT COUNT(*) as c FROM customers")->fetch_assoc()['c'] ?? 0);

// Financials & Revenue Ledger Metrics
$totalEarnings    = (float)($conn->query("SELECT IFNULL(SUM(total_amount - due_amount),0) as total FROM customers")->fetch_assoc()['total'] ?? 0);
$dueEarnings      = (float)($conn->query("SELECT IFNULL(SUM(due_amount),0) as pending FROM customers")->fetch_assoc()['pending'] ?? 0);
$grossRevenue     = $totalEarnings + $dueEarnings;
$recoveryRate     = ($grossRevenue > 0) ? round(($totalEarnings / $grossRevenue) * 100, 1) : 0;
$totalLedgerCount = (int)($conn->query("SELECT COUNT(*) c FROM customers")->fetch_assoc()['c'] ?? 0);
$dueCount         = (int)($conn->query("SELECT COUNT(*) c FROM customers WHERE due_amount > 0")->fetch_assoc()['c'] ?? 0);
$paidCount        = (int)($conn->query("SELECT COUNT(*) c FROM customers WHERE due_amount <= 0")->fetch_assoc()['c'] ?? 0);
$partialCount     = (int)($conn->query("SELECT COUNT(*) c FROM customers WHERE advance_amount > 0 AND due_amount > 0")->fetch_assoc()['c'] ?? 0);

// Inquiries / Complaints
$totalComplaints = (int)($conn->query("SELECT COUNT(*) as c FROM complaints")->fetch_assoc()['c'] ?? 0);

// ========== DATA LISTS ========== //
// 1. All Bookings (from website & admin, with cottage details & accepted_by)
$bookingsRes = $conn->query("
    SELECT b.*, 
           COALESCE(
               (SELECT GROUP_CONCAT(c2.title ORDER BY c2.title SEPARATOR ', ')
                FROM booking_cottages bc2 
                JOIN cottages c2 ON bc2.cottage_id = c2.id 
                WHERE bc2.booking_id = b.id),
               c.title,
               'Resort Cottage'
           ) AS cottage_title,
           COALESCE(
               (SELECT COUNT(DISTINCT bc3.cottage_id)
                FROM booking_cottages bc3 
                WHERE bc3.booking_id = b.id),
               1
           ) AS cottage_count,
           c.price AS cottage_price, 
           c.weekend_price, 
           c.is_weekend, 
           a.name AS accepted_by_name 
    FROM bookings b 
    LEFT JOIN cottages c ON b.cottage_id = c.id 
    LEFT JOIN admins a ON b.accepted_by = a.id 
    ORDER BY b.id DESC 
    LIMIT 200
");
$allBookings = [];
if ($bookingsRes) {
    while ($row = $bookingsRes->fetch_assoc()) {
        $allBookings[] = $row;
    }
}

// 2. Financial Ledger & Earnings (with Pagination & Filter - 25 per page)
$earnPerPage = 25;
$earnPage    = max(1, intval($_GET['earn_page'] ?? 1));
$earnFilter  = trim($_GET['earn_filter'] ?? 'all'); // 'all', 'due', 'paid', 'partial'
$earnSearch  = trim($_GET['earn_search'] ?? '');

$earnWhereConditions = [];
if ($earnFilter === 'due') {
    $earnWhereConditions[] = "due_amount > 0";
} elseif ($earnFilter === 'paid') {
    $earnWhereConditions[] = "due_amount <= 0";
} elseif ($earnFilter === 'partial') {
    $earnWhereConditions[] = "advance_amount > 0 AND due_amount > 0";
}

if ($earnSearch !== '') {
    $eEsc = $conn->real_escape_string($earnSearch);
    $earnWhereConditions[] = "(name LIKE '%$eEsc%' OR phone LIKE '%$eEsc%' OR email LIKE '%$eEsc%' OR booking_id LIKE '%$eEsc%' OR cottage_name LIKE '%$eEsc%')";
}

$earnWhereSql = !empty($earnWhereConditions) ? ('WHERE ' . implode(' AND ', $earnWhereConditions)) : '';

$earnCountSql   = "SELECT COUNT(*) as c FROM customers $earnWhereSql";
$totalEarnRows  = (int)($conn->query($earnCountSql)->fetch_assoc()['c'] ?? 0);
$totalEarnPages = max(1, (int)ceil($totalEarnRows / $earnPerPage));
if ($earnPage > $totalEarnPages) {
    $earnPage = $totalEarnPages;
}
$earnOffset = ($earnPage - 1) * $earnPerPage;

$earnListSql = "SELECT * FROM customers $earnWhereSql ORDER BY id DESC LIMIT $earnPerPage OFFSET $earnOffset";
$earnRes = $conn->query($earnListSql);
$earningsList = [];
$allCustomers = [];
if ($earnRes) {
    while ($row = $earnRes->fetch_assoc()) {
        $earningsList[] = $row;
        $allCustomers[] = $row;
    }
}

// 3. Complaints / Inquiries (top 30)
$complaintsRes = $conn->query("SELECT * FROM complaints ORDER BY id DESC LIMIT 30");
$allComplaints = [];
if ($complaintsRes) {
    while ($row = $complaintsRes->fetch_assoc()) {
        $allComplaints[] = $row;
    }
}

// 4. Unified Customer Directory with Pagination & Search (25 per page)
$custPerPage = 25;
$custPage    = max(1, intval($_GET['cust_page'] ?? 1));
$custSearch  = trim($_GET['cust_search'] ?? '');
$searchFilterSql = '';

if ($custSearch !== '') {
    $termEsc = $conn->real_escape_string($custSearch);
    $searchFilterSql = "
        WHERE customer_name LIKE '%$termEsc%' 
           OR phone LIKE '%$termEsc%' 
           OR customer_email LIKE '%$termEsc%' 
           OR customer_note LIKE '%$termEsc%'
    ";
}

// Calculate total unique customers matching search
$custCountSql = "
    SELECT COUNT(*) as total FROM (
        SELECT 
            clean_phone AS phone,
            SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(name, '') ORDER BY last_activity DESC SEPARATOR '|||'), '|||', 1) AS customer_name,
            SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(email, '') ORDER BY last_activity DESC SEPARATOR '|||'), '|||', 1) AS customer_email,
            SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(note, '') ORDER BY last_activity DESC SEPARATOR '|||'), '|||', 1) AS customer_note
        FROM (
            SELECT phone AS clean_phone, name, email, created_at AS last_activity, notes AS note FROM customers WHERE phone IS NOT NULL AND TRIM(phone) != ''
            UNION ALL 
            SELECT customer_phone AS clean_phone, customer_name AS name, customer_email AS email, created_at AS last_activity, notes AS note FROM bookings WHERE customer_phone IS NOT NULL AND TRIM(customer_phone) != ''
        ) u
        GROUP BY clean_phone
    ) AS all_g
    $searchFilterSql
";
$totalUniqueGuests = (int)($conn->query($custCountSql)->fetch_assoc()['total'] ?? 0);
$totalCustPages = max(1, (int)ceil($totalUniqueGuests / $custPerPage));
if ($custPage > $totalCustPages) {
    $custPage = $totalCustPages;
}
$custOffset = ($custPage - 1) * $custPerPage;

$customerDirSql = "
    SELECT * FROM (
        SELECT 
            clean_phone AS phone,
            SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(name, '') ORDER BY last_activity DESC SEPARATOR '|||'), '|||', 1) AS customer_name,
            SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(email, '') ORDER BY last_activity DESC SEPARATOR '|||'), '|||', 1) AS customer_email,
            COUNT(DISTINCT record_key) AS total_bookings,
            SUM(COALESCE(amount, 0)) AS total_spent,
            MAX(last_activity) AS recent_order_date,
            SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(note, '') ORDER BY last_activity DESC SEPARATOR '|||'), '|||', 1) AS customer_note,
            MAX(cust_id) AS customer_id
        FROM (
            SELECT 
                CONCAT('c_', id) AS record_key,
                id AS cust_id,
                name, 
                phone AS clean_phone, 
                email, 
                created_at AS last_activity, 
                total_amount AS amount, 
                notes AS note
            FROM customers
            WHERE phone IS NOT NULL AND TRIM(phone) != ''

            UNION ALL

            SELECT 
                CONCAT('b_', b.id) AS record_key,
                NULL AS cust_id,
                b.customer_name AS name,
                b.customer_phone AS clean_phone,
                b.customer_email AS email,
                b.created_at AS last_activity,
                b.total_amount AS amount,
                b.notes AS note
            FROM bookings b
            WHERE b.customer_phone IS NOT NULL AND TRIM(b.customer_phone) != ''
              AND NOT EXISTS (
                  SELECT 1 FROM customers c2 
                  WHERE c2.booking_id = CONCAT('ECO', LPAD(b.id, 4, '0'))
                     OR (c2.phone = b.customer_phone AND c2.check_in = b.check_in)
              )
        ) AS unified_guests
        GROUP BY clean_phone
    ) AS all_customers_unified
    $searchFilterSql
    ORDER BY recent_order_date DESC
    LIMIT $custPerPage OFFSET $custOffset
";
$customerDirRes = $conn->query($customerDirSql);
$customerDirectoryList = [];
if ($customerDirRes) {
    while ($row = $customerDirRes->fetch_assoc()) {
        $customerDirectoryList[] = $row;
    }
}

// 4. Staff & Admins Accounts List
$allAdmins = [];
$adminsQuery = $conn->query("SELECT id, name, username, email, role, created_at FROM admins ORDER BY id ASC");
if ($adminsQuery) {
    while ($arow = $adminsQuery->fetch_assoc()) {
        $allAdmins[] = $arow;
    }
}
$totalAdminsCount = count($allAdmins);

// 5. Current Logged-in Admin User Profile
$currentAdminUser = null;
$curStmt = $conn->prepare("SELECT id, name, username, email, role, created_at FROM admins WHERE id = ? LIMIT 1");
$curStmt->bind_param("i", $_SESSION['admin_id']);
$curStmt->execute();
$currentAdminUser = $curStmt->get_result()->fetch_assoc();
$curStmt->close();

// 6. System & Integration Settings
$sysSettings = getSystemSettings();

require __DIR__ . '/includes/header.php';
?>

<div class="dashboard-views-container">

  <!-- Status Notification Alerts -->
  <?php if (!empty($booking_error)): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-triangle-exclamation me-2"></i> <?= htmlspecialchars($booking_error) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'sms_settings_updated'): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-circle-check text-success me-2"></i> <strong>SMS Gateway Updated!</strong> Your SMS integration credentials and trigger settings have been saved.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'email_settings_updated'): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-circle-check text-success me-2"></i> <strong>Email &amp; SMTP Updated!</strong> Your email server settings and automated notifications have been saved.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'profile_updated'): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-circle-check text-success me-2"></i> <strong>Profile Saved!</strong> Your administrator personal profile details have been updated successfully.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'password_updated'): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-lock text-success me-2"></i> <strong>Password Updated!</strong> Your login password has been changed securely.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'admin_created'): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-circle-check me-2"></i> <strong>Admin Account Created!</strong> New administrator account has been created successfully.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'admin_updated'): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-circle-check me-2"></i> <strong>Admin Profile Updated!</strong> Administrator information has been updated successfully.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'admin_deleted'): ?>
    <div class="alert alert-warning alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-user-slash me-2"></i> <strong>Admin Removed!</strong> Administrator account was deleted successfully.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'note_updated'): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-circle-check me-2"></i> <strong>Customer Note Saved!</strong> Internal remark has been updated successfully.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'sms_sent'): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-paper-plane text-success me-2"></i> <strong>SMS Sent!</strong> Custom message has been dispatched to the guest.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'broadcast_sms_sent'): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-bullhorn text-success me-2"></i> <strong>Broadcast Sent!</strong> SMS broadcast was dispatched to all 25 customers.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'confirmed'): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-circle-check me-2"></i> <strong>Success!</strong> Booking has been confirmed and guest record synced.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'booking_created'): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-circle-check me-2"></i> <strong>Booking Created!</strong> Guest reservation has been added successfully.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'cancelled'): ?>
    <div class="alert alert-warning alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-triangle-exclamation me-2"></i> Booking status has been set to Cancelled.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'cottage_updated'): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-circle-check me-2"></i> <strong>Cottage Updated!</strong> Prices, availability status, and weekend settings saved.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'weekend_toggled'): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-fire text-warning me-2"></i> <strong>Weekend Rate Toggled!</strong> The active display rate has been updated.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'status_toggled'): ?>
    <div class="alert alert-info alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-rotate me-2"></i> <strong>Availability Updated!</strong> Cottage availability status was toggled successfully.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'weekend_all_enabled'): ?>
    <div class="alert alert-warning alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-fire text-danger me-2"></i> <strong>Weekend Mode Active for ALL Cottages!</strong> Active rates are now displaying the set weekend prices.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'weekend_all_disabled'): ?>
    <div class="alert alert-secondary alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-rotate-left me-2"></i> <strong>Weekend Rates Disabled!</strong> All cottages have been reset to regular rates.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php elseif ($booking_msg === 'cottage_error'): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4 shadow-sm" role="alert">
      <i class="fa-solid fa-triangle-exclamation me-2"></i> Error saving cottage updates. Please verify all inputs.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <!-- ========================================================
       VIEW 1: DASHBOARD OVERVIEW (#view-dashboard)
       ======================================================== -->
  <div class="dashboard-view-pane active" id="view-dashboard">

    <!-- Welcome Hero Banner -->
    <div class="admin-card mb-4" style="background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 65%, #ecfdf5 100%); border-color: #a7f3d0;">
      <div class="p-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div>
          <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25 small mb-2 fw-semibold">
            <i class="fa-solid fa-circle-check"></i> Staff Verified Session
          </div>
          <h3 class="fw-bold text-dark mb-1">Welcome back, <?= htmlspecialchars($admin_name) ?>!</h3>
          <p class="text-muted mb-0 small">Operational intelligence and real-time ledger for Eco Divine Beach Resort, Saint Martin.</p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <button type="button" class="btn btn-success rounded-pill px-3 py-2 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#newBookingModal">
            <i class="fa-solid fa-plus-circle me-1"></i> Make Booking
          </button>
          <button type="button" class="btn btn-outline-secondary rounded-pill px-3 py-2" onclick="activateView('view-cottages')">
            <i class="fa-solid fa-house-chimney me-1"></i> Manage Cottages
          </button>
        </div>
      </div>
    </div>

    <!-- Auto-Fetching Time Presets & Date Filters Toolbar -->
    <div class="admin-card mb-4 p-3 shadow-sm" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px;">
      <div class="d-flex flex-column gap-3">
        
        <!-- Top Toolbar: Quick Presets & Live Filter Info -->
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
          
          <!-- Presets Group -->
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="text-muted small fw-bold text-uppercase me-1" style="letter-spacing: 0.5px; font-size: 0.75rem;">
              <i class="fa-solid fa-bolt text-warning me-1"></i> Time Presets:
            </span>
            <button type="button" class="btn btn-sm rounded-pill px-3 fw-bold dash-preset-btn active" id="btnPresetToday" onclick="switchDashboardPreset('today')">
              <i class="fa-solid fa-sun me-1"></i> Today
            </button>
            <button type="button" class="btn btn-sm rounded-pill px-3 fw-bold dash-preset-btn" id="btnPresetYesterday" onclick="switchDashboardPreset('yesterday')">
              <i class="fa-solid fa-clock-rotate-left me-1"></i> Yesterday
            </button>
            <button type="button" class="btn btn-sm rounded-pill px-3 fw-bold dash-preset-btn" id="btnPresetThisWeek" onclick="switchDashboardPreset('this_week')">
              <i class="fa-solid fa-calendar-week me-1"></i> This Week
            </button>
            <button type="button" class="btn btn-sm rounded-pill px-3 fw-bold dash-preset-btn" id="btnPresetThisMonth" onclick="switchDashboardPreset('this_month')">
              <i class="fa-solid fa-calendar-days me-1"></i> This Month
            </button>
            <button type="button" class="btn btn-sm rounded-pill px-3 fw-bold dash-preset-btn" id="btnPresetAllTime" onclick="switchDashboardPreset('all_time')">
              <i class="fa-solid fa-infinity me-1"></i> All Time
            </button>
          </div>

          <!-- Active Filter Badge & Refresh Button -->
          <div class="d-flex align-items-center gap-2">
            <div class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2 fw-semibold d-inline-flex align-items-center gap-1" id="dashFilterActiveBadge">
              <i class="fa-solid fa-calendar-check text-success"></i>
              <span id="dashFilterActiveText">Today (<?= date('M d, Y') ?>)</span>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="refreshDashboardData()" title="Auto-fetch latest data">
              <i class="fa-solid fa-arrows-rotate" id="dashRefreshSpinner"></i> <span class="d-none d-sm-inline ms-1">Refresh</span>
            </button>
          </div>

        </div>

        <!-- Bottom Toolbar: Date Type Selector & Specific Date / Date Range Filter -->
        <div class="pt-2 border-top d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
          
          <!-- Filter By Date Type (Check-in vs Booking Date) -->
          <div class="d-flex align-items-center gap-3 flex-wrap">
            <span class="text-muted small fw-semibold" style="font-size: 0.8rem;">Filter By:</span>
            <div class="form-check form-check-inline mb-0">
              <input class="form-check-input" type="radio" name="dashDateType" id="dashDateTypeCheckin" value="check_in" checked onchange="switchDashboardDateType('check_in')">
              <label class="form-check-label small fw-semibold text-dark" for="dashDateTypeCheckin" style="cursor: pointer;">
                <i class="fa-solid fa-calendar-day text-success me-1"></i> Check-in Date
              </label>
            </div>
            <div class="form-check form-check-inline mb-0">
              <input class="form-check-input" type="radio" name="dashDateType" id="dashDateTypeCreated" value="created_at" onchange="switchDashboardDateType('created_at')">
              <label class="form-check-label small fw-semibold text-dark" for="dashDateTypeCreated" style="cursor: pointer;">
                <i class="fa-solid fa-calendar-plus text-primary me-1"></i> Booking Date (Created)
              </label>
            </div>
          </div>

          <!-- Custom Specific Date or Date Range -->
          <div class="d-flex align-items-center gap-2 flex-wrap">
            
            <!-- Specific Single Date -->
            <div class="input-group input-group-sm" style="width: auto;">
              <span class="input-group-text bg-light text-muted small fw-semibold" style="font-size: 0.78rem;">
                <i class="fa-solid fa-calendar-day text-secondary me-1"></i> Specific Date
              </span>
              <input type="date" class="form-control form-control-sm" id="dashSpecificDateInput" onchange="applyDashboardSpecificDate(this.value)" title="Choose a specific date">
            </div>

            <span class="text-muted small px-1 fw-bold">OR</span>

            <!-- Range Pickers -->
            <div class="d-flex align-items-center gap-1">
              <input type="date" class="form-control form-control-sm" id="dashStartDateInput" style="width: 135px;" title="Range Start Date" onchange="onDashboardRangeInputChange()">
              <span class="text-muted small">&rarr;</span>
              <input type="date" class="form-control form-control-sm" id="dashEndDateInput" style="width: 135px;" title="Range End Date" onchange="onDashboardRangeInputChange()">
              <button type="button" class="btn btn-sm btn-success rounded-pill px-3 fw-semibold" id="btnApplyRange" onclick="applyDashboardDateRange()">
                <i class="fa-solid fa-filter me-1"></i> Apply
              </button>
            </div>

            <!-- Reset Button -->
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2" onclick="switchDashboardPreset('today')" title="Reset to Today">
              <i class="fa-solid fa-rotate-left"></i>
            </button>

          </div>

        </div>

      </div>
    </div>

    <!-- 6 KPI Metric Cards Grid -->
    <div class="row g-3 row-cols-1 row-cols-sm-2 row-cols-xl-3 mb-4">
      
      <!-- KPI 1: Available Cottage (Only for Today, otherwise shows 0) -->
      <div class="col">
        <div class="admin-card h-100 p-3" style="border-left: 4px solid #10b981;">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Available Cottages</span>
            <div class="rounded-3 bg-success-subtle text-success d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; font-size: 1.15rem;">
              <i class="fa-solid fa-house-chimney"></i>
            </div>
          </div>
          <h2 class="fw-bold text-dark mb-1" id="dashKpiAvailable"><?= $cottageActiveCount ?></h2>
          <div class="small" id="dashKpiAvailableNote">
            <span class="text-success fw-medium"><i class="fa-solid fa-circle-check me-1"></i> Live today's availability</span>
          </div>
        </div>
      </div>

      <!-- KPI 2: Pending Bookings -->
      <div class="col">
        <div class="admin-card h-100 p-3" style="border-left: 4px solid #f59e0b;">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Pending Bookings</span>
            <div class="rounded-3 bg-warning-subtle text-warning d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; font-size: 1.15rem;">
              <i class="fa-solid fa-clock"></i>
            </div>
          </div>
          <h2 class="fw-bold text-dark mb-1" id="dashKpiPending"><?= $pendingRequests ?></h2>
          <div class="small text-muted" id="dashKpiPendingNote">
            <span>Awaiting review & confirmation</span>
          </div>
        </div>
      </div>

      <!-- KPI 3: Confirmed Bookings -->
      <div class="col">
        <div class="admin-card h-100 p-3" style="border-left: 4px solid #3b82f6;">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Confirmed Bookings</span>
            <div class="rounded-3 bg-primary-subtle text-primary d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; font-size: 1.15rem;">
              <i class="fa-solid fa-calendar-check"></i>
            </div>
          </div>
          <h2 class="fw-bold text-dark mb-1" id="dashKpiConfirmed"><?= $confirmedBookings ?></h2>
          <div class="small text-muted" id="dashKpiConfirmedNote">
            <span>Approved reservations</span>
          </div>
        </div>
      </div>

      <!-- KPI 4: Total Amount -->
      <div class="col">
        <div class="admin-card h-100 p-3" style="border-left: 4px solid #0f172a;">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Total Amount</span>
            <div class="rounded-3 bg-dark-subtle text-dark d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; font-size: 1.15rem;">
              <i class="fa-solid fa-file-invoice-dollar"></i>
            </div>
          </div>
          <h2 class="fw-bold text-dark mb-1">৳ <span id="dashKpiTotalAmount"><?= number_format($grossRevenue) ?></span></h2>
          <div class="small text-muted">
            <span>Total billed volume</span>
          </div>
        </div>
      </div>

      <!-- KPI 5: Collected Amount -->
      <div class="col">
        <div class="admin-card h-100 p-3" style="border-left: 4px solid #10b981;">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Collected Amount</span>
            <div class="rounded-3 bg-success-subtle text-success d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; font-size: 1.15rem;">
              <i class="fa-solid fa-wallet"></i>
            </div>
          </div>
          <h2 class="fw-bold text-success mb-1">৳ <span id="dashKpiAdvanceAmount"><?= number_format($totalEarnings) ?></span></h2>
          <div class="small text-muted">
            <span class="text-success fw-semibold"><i class="fa-solid fa-check-double me-1"></i> Total collected so far</span>
          </div>
        </div>
      </div>

      <!-- KPI 6: Due Amount -->
      <div class="col">
        <div class="admin-card h-100 p-3" style="border-left: 4px solid #ef4444;">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Due Amount</span>
            <div class="rounded-3 bg-danger-subtle text-danger d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; font-size: 1.15rem;">
              <i class="fa-solid fa-hand-holding-dollar"></i>
            </div>
          </div>
          <h2 class="fw-bold text-danger mb-1">৳ <span id="dashKpiDueAmount"><?= number_format($dueEarnings) ?></span></h2>
          <div class="small text-muted">
            <span class="text-danger fw-semibold"><i class="fa-solid fa-triangle-exclamation me-1"></i> Pending unpaid balance</span>
          </div>
        </div>
      </div>

    </div>

    <!-- Reservations Table for Selected Period -->
    <div class="admin-card mb-4">
      <div class="admin-card-header flex-wrap gap-2">
        <div>
          <h5 class="fw-bold text-dark mb-0">
            <i class="fa-solid fa-receipt text-success me-2"></i> Reservations Overview
          </h5>
          <small class="text-muted" id="dashTableSubtitle">Showing reservations matching the active timeframe filter.</small>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <!-- Client Quick Search in Current View -->
          <div class="input-group input-group-sm" style="width: 230px;">
            <span class="input-group-text bg-white border-end-0 text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
            <input type="text" class="form-control border-start-0 ps-0" id="dashFilterSearchInput" placeholder="Search guest / phone..." oninput="filterDashboardTableClientSide(this.value)">
          </div>
          <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="activateView('view-bookings')">
            All Bookings <i class="fa-solid fa-arrow-right ms-1"></i>
          </button>
        </div>
      </div>
      
      <div class="table-responsive">
        <table class="table table-dark-custom align-middle">
          <thead>
            <tr>
              <th style="width: 100px;">Booking Ref</th>
              <th>Guest Details</th>
              <th>Cottage & Dates</th>
              <th>Total Amount</th>
              <th>Collected Amount</th>
              <th>Due Amount</th>
              <th>Status</th>
              <th style="min-width: 170px;" class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody id="dashReservationsTbody">
            <tr>
              <td colspan="8" class="text-center py-4 text-muted">
                <i class="fa-solid fa-spinner fa-spin text-success me-2"></i> Loading operational data...
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

  </div> <!-- /#view-dashboard -->


  <!-- ========================================================
       VIEW 2: MANAGE COTTAGES (#view-cottages)
       ======================================================== -->
  <div class="dashboard-view-pane" id="view-cottages">
    <div class="admin-card mb-4">
      <div class="admin-card-header">
        <div>
          <h4 class="fw-bold text-dark mb-1"><i class="fa-solid fa-house-chimney text-success me-2"></i> Resort Cottages Management</h4>
          <p class="text-muted small mb-0">Manage regular prices, weekend prices, cottage availability, and toggle active weekend rates.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <?php if ($cottageWeekendActiveCount < $totalCottages): ?>
            <a href="update_cottage.php?bulk_weekend=1&ref=dashboard" class="btn btn-sm btn-warning rounded-pill px-3 fw-bold text-dark shadow-sm" onclick="return confirm('Enable weekend pricing for ALL cottages? Active prices will show weekend rates.');">
              <i class="fa-solid fa-fire me-1"></i> Set Weekend for All
            </a>
          <?php endif; ?>
          <?php if ($cottageWeekendActiveCount > 0): ?>
            <a href="update_cottage.php?bulk_weekend=0&ref=dashboard" class="btn btn-sm btn-outline-secondary rounded-pill px-3 shadow-sm" onclick="return confirm('Reset all cottages to standard regular rates?');">
              <i class="fa-solid fa-rotate-left me-1"></i> Reset All to Regular
            </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Quick Stats & Search Bar -->
      <div class="px-4 pt-3 pb-3 bg-light border-bottom d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <button type="button" class="btn btn-sm btn-white border rounded-pill active px-3" onclick="filterCottagesByStatus('all')">
            <i class="fa-solid fa-house text-success me-1"></i> All (<?= $totalCottages ?>)
          </button>
          <button type="button" class="btn btn-sm btn-white border rounded-pill text-success fw-medium px-3" onclick="filterCottagesByStatus('active')">
            <i class="fa-solid fa-circle-check me-1"></i> Available (<?= $cottageActiveCount ?>)
          </button>
          <button type="button" class="btn btn-sm btn-white border rounded-pill text-secondary fw-medium px-3" onclick="filterCottagesByStatus('inactive')">
            <i class="fa-solid fa-wrench me-1"></i> Maintenance (<?= $cottageInactiveCount ?>)
          </button>
          <button type="button" class="btn btn-sm btn-white border rounded-pill text-warning fw-bold px-3" onclick="filterCottagesByStatus('weekend')">
            <i class="fa-solid fa-fire me-1"></i> Weekend Active (<?= $cottageWeekendActiveCount ?>)
          </button>
        </div>

        <!-- Cottage Search Input -->
        <div style="min-width: 240px;">
          <input type="text" id="cottageSearchInput" class="form-control form-control-sm rounded-pill" placeholder="🔍 Search cottage name..." onkeyup="searchCottageCards()">
        </div>
      </div>

      <div class="admin-card-body">
        <div class="row g-4" id="cottagesCardsGrid">
          <?php foreach($allCottages as $c): 
            $isWk = ((int)($c['is_weekend'] ?? 0) === 1);
            $effPrice = ($isWk && !empty($c['weekend_price'])) ? $c['weekend_price'] : $c['price'];
            $isAvail = (($c['status'] ?? 'active') === 'active');
            $cottageImg = !empty($c['img']) ? '../assets/img/cottages/' . $c['img'] : '../assets/img/coral.jpg';
          ?>
            <div class="col-lg-4 col-md-6 cottage-card-col" data-status="<?= htmlspecialchars($c['status'] ?? 'active') ?>" data-weekend="<?= $isWk ? '1' : '0' ?>" data-title="<?= htmlspecialchars(strtolower($c['title'])) ?>">
              <div class="admin-card h-100 shadow-sm" style="background: #ffffff; border: 1px solid #e2e8f0; transition: transform 0.2s, box-shadow 0.2s;">
                
                <!-- Card Image & Floating Badges -->
                <div class="position-relative" style="height: 180px; overflow: hidden; background: #0f172a;">
                  <img src="<?= htmlspecialchars($cottageImg) ?>" alt="<?= htmlspecialchars($c['title']) ?>" class="w-100 h-100" style="object-fit: cover;">
                  
                  <!-- Top Left Weekend Badge -->
                  <?php if ($isWk): ?>
                    <span class="badge bg-warning text-dark position-absolute top-0 start-0 m-3 shadow-sm fw-bold">
                      <i class="fa-solid fa-fire me-1"></i> Weekend Active
                    </span>
                  <?php else: ?>
                    <span class="badge bg-light text-muted border position-absolute top-0 start-0 m-3 shadow-sm">
                      Regular Rate
                    </span>
                  <?php endif; ?>

                  <!-- Top Right Availability Badge -->
                  <?php if ($isAvail): ?>
                    <span class="status-badge status-badge-accepted position-absolute top-0 end-0 m-3 shadow-sm">
                      <i class="fa-solid fa-circle-check"></i> Available
                    </span>
                  <?php else: ?>
                    <span class="status-badge status-badge-cancelled position-absolute top-0 end-0 m-3 shadow-sm">
                      <i class="fa-solid fa-wrench"></i> Maintenance
                    </span>
                  <?php endif; ?>
                </div>

                <!-- Card Body -->
                <div class="p-3 d-flex flex-column justify-content-between flex-grow-1">
                  <div>
                    <!-- Title & ID -->
                    <div class="d-flex justify-content-between align-items-start mb-2">
                      <h5 class="fw-bold text-dark mb-0"><?= htmlspecialchars($c['title']) ?></h5>
                      <span class="badge bg-light text-secondary border">#<?= $c['id'] ?></span>
                    </div>

                    <!-- Active Display Price Box (Highlighting Weekend Rate if Set) -->
                    <?php if ($isWk): ?>
                      <div class="p-2 mb-3 rounded-3" style="background: #fffbeb; border: 1px solid #fde68a;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                          <span class="small fw-bold text-warning-emphasis" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                            <i class="fa-solid fa-fire text-warning me-1"></i> ACTIVE DISPLAYED RATE:
                          </span>
                          <span class="badge bg-warning text-dark fw-bold" style="font-size: 0.68rem;">WEEKEND</span>
                        </div>
                        <div class="fs-4 fw-bold text-dark">
                          ৳ <?= number_format($c['weekend_price']) ?> 
                          <small class="text-muted fs-6 fw-normal">/ night</small>
                        </div>
                        <div class="text-muted mt-1" style="font-size: 0.72rem;">Website shows this weekend price right now.</div>
                      </div>
                    <?php else: ?>
                      <div class="p-2 mb-3 rounded-3" style="background: #f0fdf4; border: 1px solid #bbf7d0;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                          <span class="small fw-bold text-success-emphasis" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                            <i class="fa-solid fa-tag text-success me-1"></i> ACTIVE DISPLAYED RATE:
                          </span>
                          <span class="badge bg-success text-white fw-bold" style="font-size: 0.68rem;">REGULAR</span>
                        </div>
                        <div class="fs-4 fw-bold text-dark">
                          ৳ <?= number_format($c['price']) ?> 
                          <small class="text-muted fs-6 fw-normal">/ night</small>
                        </div>
                        <div class="text-muted mt-1" style="font-size: 0.72rem;">Website shows standard weekday rate.</div>
                      </div>
                    <?php endif; ?>

                    <!-- Rate Breakdown Row -->
                    <div class="d-flex justify-content-between align-items-center mb-3 p-2 bg-light rounded-3 border">
                      <div>
                        <span class="text-muted small d-block" style="font-size: 0.72rem;">Weekday Rate:</span> 
                        <strong class="text-success">৳ <?= number_format($c['price']) ?></strong>
                      </div>
                      <div class="text-end">
                        <span class="text-muted small d-block" style="font-size: 0.72rem;">Weekend Rate:</span> 
                        <strong class="text-warning">৳ <?= number_format($c['weekend_price'] ?? $c['price']) ?></strong>
                      </div>
                    </div>
                  </div>

                  <!-- Action Controls -->
                  <div>
                    <!-- 1. Set Weekend Toggle Button -->
                    <?php if ($isWk): ?>
                      <a href="update_cottage.php?toggle_weekend=<?= $c['id'] ?>&ref=dashboard" class="btn btn-sm btn-warning w-100 rounded-pill fw-bold text-dark shadow-sm mb-2" title="Revert to Regular Rate">
                        <i class="fa-solid fa-rotate-left me-1"></i> Revert to Regular (৳ <?= number_format($c['price']) ?>)
                      </a>
                    <?php else: ?>
                      <a href="update_cottage.php?toggle_weekend=<?= $c['id'] ?>&ref=dashboard" class="btn btn-sm btn-outline-warning w-100 rounded-pill fw-semibold mb-2" title="Set Weekend Rate">
                        <i class="fa-solid fa-calendar-week text-warning me-1"></i> Set Weekend Rate (৳ <?= number_format($c['weekend_price']) ?>)
                      </a>
                    <?php endif; ?>

                    <!-- 2. Bottom Row: Availability Toggle + Edit Modal Button -->
                    <div class="d-flex gap-2">
                      <?php if ($isAvail): ?>
                        <a href="update_cottage.php?toggle_status=<?= $c['id'] ?>&ref=dashboard" class="btn btn-sm btn-outline-secondary rounded-pill px-3" title="Set to Maintenance / Unavailable">
                          <i class="fa-solid fa-toggle-on text-success me-1"></i> Active
                        </a>
                      <?php else: ?>
                        <a href="update_cottage.php?toggle_status=<?= $c['id'] ?>&ref=dashboard" class="btn btn-sm btn-outline-danger rounded-pill px-3" title="Set to Active / Available">
                          <i class="fa-solid fa-toggle-off text-danger me-1"></i> Inactive
                        </a>
                      <?php endif; ?>

                      <button type="button" class="btn btn-sm btn-outline-primary rounded-pill flex-grow-1" onclick='openEditCottageModal(<?= json_encode($c) ?>)'>
                        <i class="fa-solid fa-pen-to-square me-1"></i> Edit Prices
                      </button>
                    </div>

                  </div>

                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div> <!-- /#view-cottages -->


  <!-- ========================================================
       VIEW 3: BOOKINGS (ALL WEBSITE & ADMIN BOOKINGS) (#view-bookings)
       ======================================================== -->
  <div class="dashboard-view-pane" id="view-bookings">
    <div class="admin-card mb-4">
      <div class="admin-card-header">
        <div>
          <h4 class="fw-bold text-dark mb-1"><i class="fa-solid fa-calendar-check text-success me-2"></i> Bookings Management</h4>
          <p class="text-muted small mb-0">All bookings from the website are listed here. Confirm pending bookings or make new reservations for guests.</p>
        </div>
        <div>
          <button type="button" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#newBookingModal">
            <i class="fa-solid fa-plus-circle me-1"></i> Make Booking for Guest
          </button>
        </div>
      </div>

      <!-- Quick Status Filter Pills inside Bookings View -->
      <div class="px-4 pt-3 pb-2 bg-light border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span class="small text-muted fw-bold">Filter By Status:</span>
          <button type="button" class="btn btn-sm btn-white border rounded-pill active px-3 booking-status-pill" onclick="filterBookingsTable('all', this)">
            All (<?= count($allBookings) ?>)
          </button>
          <button type="button" class="btn btn-sm btn-white border rounded-pill text-warning fw-bold px-3 booking-status-pill" onclick="filterBookingsTable('pending', this)">
            <i class="fa-solid fa-clock me-1"></i> Pending (<?= $pendingRequests ?>)
          </button>
          <button type="button" class="btn btn-sm btn-white border rounded-pill text-success fw-bold px-3 booking-status-pill" onclick="filterBookingsTable('accepted', this)">
            <i class="fa-solid fa-check me-1"></i> Confirmed (<?= $confirmedBookings ?>)
          </button>
          <button type="button" class="btn btn-sm btn-white border rounded-pill text-danger px-3 booking-status-pill" onclick="filterBookingsTable('cancelled', this)">
            <i class="fa-solid fa-xmark me-1"></i> Cancelled (<?= $cancelledBookings ?>)
          </button>
          <button type="button" class="btn btn-sm btn-white border rounded-pill text-info fw-bold px-3 booking-status-pill" onclick="filterBookingsTable('refunded', this)">
            <i class="fa-solid fa-rotate-left me-1"></i> Refunded (<?= $refundedBookingsCount ?>)
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 shadow-sm" onclick="openRefundHistoryModal()">
            <i class="fa-solid fa-clock-rotate-left me-1 text-primary"></i> Refund History
          </button>
        </div>

        <!-- Search Input -->
        <div style="min-width: 240px;">
          <input type="text" id="bookingSearchInput" class="form-control form-control-sm rounded-pill" placeholder="🔍 Search guest, phone, cottage..." onkeyup="searchBookingsTable()">
        </div>
      </div>

      <!-- Main Bookings Table -->
      <div class="table-responsive">
        <table class="table table-dark-custom align-middle" id="masterBookingsTable">
          <thead>
            <tr>
              <th>ID</th>
              <th>Cottage</th>
              <th>Guest Details</th>
              <th>Check-in & Check-out</th>
              <th>Payment Info</th>
              <th>Booking Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allBookings)): ?>
              <tr>
                <td colspan="7" class="text-center py-5 text-muted">
                  No bookings found. Use "Make Booking for Guest" to create a new reservation!
                </td>
              </tr>
            <?php else: ?>
              <?php foreach($allBookings as $b): 
                $status = $b['status'];
                $statusClass = ($status === 'accepted') ? 'status-badge-accepted' : (($status === 'cancelled') ? 'status-badge-cancelled' : 'status-badge-pending');
                $statusLabel = ($status === 'accepted') ? 'Confirmed' : ucfirst($status);
                
                // Calculate nights safely with fallbacks
                $cinStr = (!empty($b['check_in']) && $b['check_in'] !== '0000-00-00') ? $b['check_in'] : date('Y-m-d');
                $coutStr = (!empty($b['check_out']) && $b['check_out'] !== '0000-00-00') ? $b['check_out'] : date('Y-m-d', strtotime($cinStr . ' +1 day'));

                try {
                    $cin = new DateTime($cinStr);
                    $cout = new DateTime($coutStr);
                    if ($cout > $cin) {
                        $diff = $cin->diff($cout)->days;
                        $nights = max(1, $diff);
                    } else {
                        $nights = 1;
                        $coutStr = date('Y-m-d', strtotime($cinStr . ' +1 day'));
                    }
                } catch (Exception $e) {
                    $nights = 1;
                    $coutStr = date('Y-m-d', strtotime($cinStr . ' +1 day'));
                }

                // Nightly price based on weekend rate if active
                $effectiveRate = ((int)($b['is_weekend'] ?? 0) === 1 && !empty($b['weekend_price'])) ? floatval($b['weekend_price']) : floatval($b['cottage_price'] ?? 0);
                
                // Total calculation: if total_amount > 0 in database use it, else nights * effectiveRate
                $dbTotal = floatval($b['total_amount'] ?? 0);
                $calcTotal = ($dbTotal > 0) ? $dbTotal : ($effectiveRate * $nights);
                
                $adv = floatval($b['advance_amount'] ?? 0);
                $disc = floatval($b['discount_amount'] ?? 0);
                
                // Due calculation
                if (isset($b['due_amount']) && floatval($b['due_amount']) > 0) {
                    $due = floatval($b['due_amount']);
                } else {
                    $due = max(0, $calcTotal - $adv - $disc);
                }

                $refAmt = floatval($b['refund_amount'] ?? 0);
                $cancFee = floatval($b['cancellation_fee'] ?? 0);
                $isRefunded = ($refAmt > 0 || $cancFee > 0);

                $modalPayload = [
                  'id' => (int)$b['id'],
                  'customer_name' => $b['customer_name'] ?? '',
                  'customer_phone' => $b['customer_phone'] ?? '',
                  'customer_email' => $b['customer_email'] ?? '',
                  'cottage_title' => $b['cottage_title'] ?? 'Resort Cottage',
                  'cottage_count' => (int)($b['cottage_count'] ?? 1),
                  'check_in' => $cinStr,
                  'check_out' => $coutStr,
                  'nights' => $nights,
                  'effective_rate' => $effectiveRate,
                  'calculated_total' => $calcTotal,
                  'advance_amount' => $adv,
                  'discount_amount' => $disc,
                  'due_amount' => $due,
                  'payment_method' => $b['payment_method'] ?? 'bKash (Personal / Merchant)',
                  'notes' => $b['notes'] ?? '',
                  'status' => $status
                ];

                $cancelPayload = [
                  'id' => (int)$b['id'],
                  'b_code' => 'ECO' . str_pad($b['id'], 4, '0', STR_PAD_LEFT),
                  'customer_name' => $b['customer_name'] ?? 'Guest',
                  'customer_phone' => $b['customer_phone'] ?? '',
                  'customer_email' => $b['customer_email'] ?? '',
                  'cottage_title' => $b['cottage_title'] ?? 'Resort Cottage',
                  'cottage_count' => (int)($b['cottage_count'] ?? 1),
                  'check_in' => $cinStr,
                  'check_out' => $coutStr,
                  'nights' => $nights,
                  'effective_rate' => $effectiveRate,
                  'calculated_total' => $calcTotal,
                  'advance_amount' => $adv,
                  'discount_amount' => $disc,
                  'due_amount' => $due,
                  'payment_method' => $b['payment_method'] ?? 'bKash (Personal / Merchant)',
                  'status' => $status,
                  'refund_amount' => $refAmt,
                  'cancellation_fee' => $cancFee,
                  'refund_method' => $b['refund_method'] ?? 'bKash (Personal / Merchant)',
                  'refund_trx_id' => $b['refund_trx_id'] ?? ''
                ];
              ?>
                <tr class="booking-row" id="booking-row-<?= $b['id'] ?>" data-status="<?= htmlspecialchars($status) ?>" data-refunded="<?= $isRefunded ? '1' : '0' ?>" data-booking-json='<?= json_encode($cancelPayload, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                  <td><strong>#<?= $b['id'] ?></strong></td>
                  <td>
                    <div class="fw-bold text-dark"><?= htmlspecialchars($b['cottage_title'] ?? 'Resort Cottage') ?></div>
                    <?php if (($b['cottage_count'] ?? 1) > 1): ?>
                      <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25" style="font-size:0.72rem;">
                        <i class="fa-solid fa-hotel me-1"></i><?= $b['cottage_count'] ?> Cottages
                      </span>
                    <?php endif; ?>
                    <small class="text-muted"><i class="fa-solid fa-tag me-1"></i>৳ <?= number_format($effectiveRate) ?>/night</small>
                  </td>
                  <td>
                    <div class="fw-bold text-dark"><?= htmlspecialchars($b['customer_name']) ?></div>
                    <div><a href="tel:<?= htmlspecialchars($b['customer_phone']) ?>" class="text-success fw-medium text-decoration-none"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($b['customer_phone']) ?></a></div>
                    <?php if(!empty($b['customer_email'])): ?>
                      <small class="text-muted"><i class="fa-solid fa-envelope me-1"></i><?= htmlspecialchars($b['customer_email']) ?></small>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="small mb-1">
                      <span class="text-success fw-semibold"><i class="fa-solid fa-calendar-check me-1"></i><?= htmlspecialchars($cinStr) ?></span> &rarr;
                      <span class="text-danger fw-semibold"><i class="fa-solid fa-calendar-xmark me-1"></i><?= htmlspecialchars($coutStr) ?></span>
                    </div>
                    <span class="badge bg-light text-muted border"><?= $nights ?> Night<?= ($nights > 1 ? 's' : '') ?></span>
                  </td>
                  <td>
                    <?php if ($status === 'cancelled'): ?>
                      <div class="text-danger fw-semibold" style="font-size: 0.88rem;"><i class="fa-solid fa-ban me-1"></i>Cancelled</div>
                      <?php if($refAmt > 0): ?>
                        <div class="small text-info fw-semibold mt-1">
                          <i class="fa-solid fa-arrow-rotate-left me-1"></i>Refunded: ৳ <?= number_format($refAmt, 2) ?>
                        </div>
                      <?php endif; ?>
                      <?php if($cancFee > 0): ?>
                        <div class="small text-muted" style="font-size: 0.72rem;">
                          <i class="fa-solid fa-receipt me-1"></i>Fee Retained: ৳ <?= number_format($cancFee, 2) ?>
                        </div>
                      <?php endif; ?>
                      <?php if(!empty($b['refund_method'])): ?>
                        <div class="mt-1">
                          <span class="badge bg-light text-secondary border" style="font-size: 0.68rem;">
                            <i class="fa-solid fa-wallet me-1 text-info"></i><?= htmlspecialchars($b['refund_method']) ?>
                            <?= !empty($b['refund_trx_id']) ? ' (' . htmlspecialchars($b['refund_trx_id']) . ')' : '' ?>
                          </span>
                        </div>
                      <?php endif; ?>
                    <?php else: ?>
                      <div class="text-dark fw-bold" style="font-size: 0.95rem;">৳ <?= number_format($calcTotal, 2) ?></div>
                      <div class="small">
                        <span class="text-success fw-semibold"><i class="fa-solid fa-arrow-down me-1"></i>Collected: ৳ <?= number_format($adv, 2) ?></span>
                      </div>
                      <?php if($due > 0): ?>
                        <div class="small mt-1">
                          <span class="fw-bold" style="color: #d97706 !important;"><i class="fa-solid fa-clock me-1"></i>Due: ৳ <?= number_format($due, 2) ?></span>
                        </div>
                      <?php elseif($calcTotal > 0): ?>
                        <div class="mt-1">
                          <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 py-0 px-2 fw-semibold" style="font-size: 0.72rem;">
                            <i class="fa-solid fa-circle-check me-1"></i>Paid in Full
                          </span>
                        </div>
                      <?php endif; ?>
                      <?php if($disc > 0): ?>
                        <div class="text-muted small mt-1" style="font-size: 0.72rem;">
                          <i class="fa-solid fa-tag me-1 text-danger"></i>Disc: ৳ <?= number_format($disc, 2) ?>
                        </div>
                      <?php endif; ?>
                      <?php if(!empty($b['payment_method'])): ?>
                        <div class="mt-1">
                          <span class="badge bg-light text-secondary border" style="font-size: 0.7rem;">
                            <i class="fa-solid fa-wallet me-1 text-muted"></i><?= htmlspecialchars($b['payment_method']) ?>
                          </span>
                        </div>
                      <?php endif; ?>
                    <?php endif; ?>
                    <?php if(!empty($b['notes'])): ?>
                      <div class="text-muted mt-1 text-truncate" style="font-size: 0.7rem; max-width: 175px;" title="<?= htmlspecialchars($b['notes']) ?>">
                        <i class="fa-solid fa-note-sticky text-warning me-1"></i><?= htmlspecialchars($b['notes']) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="status-badge <?= $statusClass ?> mb-1">
                      <?= $statusLabel ?>
                    </span>
                    <?php if($status === 'cancelled' && $isRefunded): ?>
                      <div>
                        <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 py-0 px-2 fw-semibold" style="font-size: 0.72rem;">
                          <i class="fa-solid fa-rotate-left me-1"></i>Refunded
                        </span>
                      </div>
                    <?php endif; ?>
                    <?php if(!empty($b['accepted_by_name'])): ?>
                      <div class="text-muted" style="font-size: 0.72rem;">
                        <i class="fa-solid fa-user-check me-1"></i>By: <?= htmlspecialchars($b['accepted_by_name']) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="d-flex align-items-center gap-1 flex-wrap">
                      <?php if($status === 'pending'): ?>
                        <!-- Confirm Pending Booking Button (Opens Modal) -->
                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3 py-1 shadow-sm fw-semibold" onclick='openConfirmModal(<?= json_encode($modalPayload, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                          <i class="fa-solid fa-check me-1"></i> Confirm
                        </button>
                        <!-- Cancel & Refund Booking Button -->
                        <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1 shadow-sm fw-semibold" title="Cancel Booking & Refund" onclick='openCancelRefundModal(<?= json_encode($cancelPayload, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                          <i class="fa-solid fa-ban me-1"></i> Cancel
                        </button>
                      <?php elseif($status === 'accepted'): ?>
                        <span class="text-success small fw-semibold me-1"><i class="fa-solid fa-circle-check"></i> Confirmed</span>
                        <?php if ($due <= 0 && $calcTotal > 0): ?>
                          <a href="invoice.php?booking_id=<?= $b['id'] ?>" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-2 py-1 shadow-sm fw-semibold d-inline-flex align-items-center gap-1" title="Download Official Invoice Receipt">
                            <i class="fa-solid fa-file-invoice-dollar"></i>
                            <span>Invoice</span>
                          </a>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-2 py-1" title="Payment Details / Update" onclick='openConfirmModal(<?= json_encode($modalPayload, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                          <i class="fa-solid fa-file-invoice-dollar me-1"></i> Payment
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1 shadow-sm fw-semibold" title="Cancel & Refund Booking" onclick='openCancelRefundModal(<?= json_encode($cancelPayload, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                          <i class="fa-solid fa-ban me-1"></i> Cancel / Refund
                        </button>
                      <?php elseif($status === 'cancelled'): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-1" title="Re-confirm Booking" onclick='openConfirmModal(<?= json_encode($modalPayload, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                          <i class="fa-solid fa-rotate-left me-1"></i> Re-open
                        </button>
                        <?php if ($isRefunded): ?>
                          <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-2 py-1 shadow-sm fw-semibold" title="View Refund History & Receipt" onclick="openRefundHistoryModal(<?= $b['id'] ?>)">
                            <i class="fa-solid fa-clock-rotate-left me-1"></i> Refund Log
                          </button>
                        <?php elseif ($adv > 0): ?>
                          <button type="button" class="btn btn-sm btn-outline-warning rounded-pill px-2 py-1 shadow-sm fw-semibold" title="Process Refund for Advance" onclick='openCancelRefundModal(<?= json_encode($cancelPayload, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                            <i class="fa-solid fa-rotate-left me-1"></i> Refund
                          </button>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div> <!-- /#view-bookings -->


  <!-- ========================================================
       VIEW 4: CUSTOMER BOOKINGS & PAYMENTS (#view-customer-bookings)
       ======================================================== -->
  <div class="dashboard-view-pane" id="view-customer-bookings">
    <div class="admin-card mb-4">
      <div class="admin-card-header">
        <div>
          <h4 class="fw-bold text-dark mb-1"><i class="fa-solid fa-receipt text-success me-2"></i> Customer Bookings & Payments</h4>
          <p class="text-muted small mb-0">Detailed guest booking logs with check-in, check-out, and financial settlements.</p>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-dark-custom">
          <thead>
            <tr>
              <th>ID</th>
              <th>Customer Name</th>
              <th>Cottage</th>
              <th>Check-In & Out</th>
              <th>Total Bill</th>
              <th>Collected Amount</th>
              <th>Due Amount</th>
              <th>Payment Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if(empty($allCustomers)): ?>
              <tr><td colspan="8" class="text-center py-4 text-muted">No records found.</td></tr>
            <?php else: ?>
              <?php foreach($allCustomers as $cust): 
                $pStatus = $cust['payment_status'] ?? 'unpaid';
                $badgeClass = ($pStatus === 'paid') ? 'status-badge-paid' : (($pStatus === 'partial') ? 'status-badge-partial' : 'status-badge-unpaid');
              ?>
                <tr>
                  <td><strong>#<?= $cust['id'] ?></strong></td>
                  <td>
                    <div class="fw-bold text-dark"><?= htmlspecialchars($cust['name']) ?></div>
                    <small class="text-muted"><?= htmlspecialchars($cust['phone']) ?></small>
                  </td>
                  <td><?= htmlspecialchars($cust['cottage_name']) ?></td>
                  <td>
                    <small class="text-muted"><?= htmlspecialchars($cust['check_in']) ?> &rarr; <?= htmlspecialchars($cust['check_out']) ?></small>
                  </td>
                  <td class="fw-bold text-dark">৳ <?= number_format($cust['total_amount'] ?? 0) ?></td>
                  <td class="text-success">৳ <?= number_format($cust['advance_amount'] ?? 0) ?></td>
                  <td class="text-danger fw-bold">৳ <?= number_format($cust['due_amount'] ?? 0) ?></td>
                  <td>
                    <span class="status-badge <?= $badgeClass ?>">
                      <?= ucfirst($pStatus) ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div> <!-- /#view-customer-bookings -->


  <!-- ========================================================
       VIEW 5: CUSTOMERS DIRECTORY (#view-customers)
       ======================================================== -->
  <!-- ========================================================
       VIEW 5: REDESIGNED CUSTOMERS DIRECTORY (#view-customers)
       ======================================================== -->
  <div class="dashboard-view-pane" id="view-customers">
    <div class="admin-card mb-4">
      
      <!-- Card Header with Title, Stats, Search & Broadcast SMS -->
      <div class="admin-card-header flex-wrap gap-3">
        <div>
          <div class="d-flex align-items-center gap-2">
            <h4 class="fw-bold text-dark mb-0"><i class="fa-solid fa-users text-success me-2"></i> Customers Directory</h4>
            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2 py-1 fw-bold" id="customerDirectoryPageBadge">Page <?= $custPage ?> of <?= $totalCustPages ?></span>
          </div>
          <p class="text-muted small mb-0 mt-1" id="customerDirectorySubtitle">Unique resort guests ranked by most recent reservation (<?= $totalUniqueGuests ?> Total Guests • 25 Per Page).</p>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap ms-auto">
          <!-- Global Search Bar (Searches all guests across all pages) -->
          <div class="input-group input-group-sm" style="width: 290px;">
            <span class="input-group-text bg-white border-end-0 text-muted" id="custSearchIconSpan">
              <i class="fa-solid fa-magnifying-glass" id="custSearchGlassIcon"></i>
            </span>
            <input type="text" id="customerDirectorySearch" class="form-control border-start-0 border-end-0" 
                   value="<?= htmlspecialchars($custSearch) ?>"
                   placeholder="Search all guests (across all pages)..." 
                   oninput="handleCustomerDirectorySearchInput(event)"
                   onkeydown="handleCustomerDirectorySearchKey(event)">
            <button type="button" class="btn btn-white border border-start-0 text-muted <?= empty($custSearch) ? 'd-none' : '' ?>" id="btnClearCustomerSearch" onclick="clearCustomerDirectorySearch()" title="Clear Search">
              <i class="fa-solid fa-circle-xmark"></i>
            </button>
          </div>

          <!-- Broadcast SMS Button -->
          <button type="button" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm d-flex align-items-center gap-2 fw-bold" onclick="openBroadcastSmsModal()">
            <i class="fa-solid fa-bullhorn"></i>
            <span id="btnCustBroadcastSmsLabel">Broadcast SMS (<?= count($customerDirectoryList) ?>)</span>
          </button>
        </div>
      </div>

      <!-- Search Status Banner (Visible during active search) -->
      <div id="customerSearchStatusBanner" class="px-4 py-2 bg-light border-bottom <?= empty($custSearch) ? 'd-none' : 'd-flex' ?> align-items-center justify-content-between">
        <div class="small text-dark" id="customerSearchStatusText">
          <?php if(!empty($custSearch)): ?>
            <i class="fa-solid fa-magnifying-glass text-success me-1"></i> Showing <strong><?= count($customerDirectoryList) ?></strong> search results matching "<strong><?= htmlspecialchars($custSearch) ?></strong>" across entire guest database.
          <?php else: ?>
            <i class="fa-solid fa-magnifying-glass text-success me-1"></i> Searching across entire database...
          <?php endif; ?>
        </div>
        <button type="button" class="btn btn-sm btn-link text-decoration-none text-danger p-0 fw-semibold" onclick="clearCustomerDirectorySearch()">
          <i class="fa-solid fa-xmark me-1"></i> Reset to all guests
        </button>
      </div>

      <!-- Customers Table -->
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="customerDirectoryTable">
          <thead class="bg-light text-muted small text-uppercase" style="letter-spacing: 0.04em;">
            <tr>
              <th class="ps-4 py-3" style="width: 60px;">#</th>
              <th class="py-3" style="min-width: 250px;">Customer Profile (Name, Phone, Email)</th>
              <th class="py-3 text-center" style="width: 140px;">Total Bookings</th>
              <th class="py-3 text-end" style="width: 140px;">Total Spent</th>
              <th class="py-3" style="min-width: 260px;">Customer Note (Editable)</th>
              <th class="pe-4 py-3 text-end" style="width: 130px;">SMS Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if(empty($customerDirectoryList)): ?>
              <tr>
                <td colspan="6" class="text-center py-5 text-muted">
                  <i class="fa-solid fa-user-slash fs-3 d-block mb-2 text-secondary"></i>
                  No customer records found.
                </td>
              </tr>
            <?php else: ?>
              <?php 
                $serialNum = $custOffset + 1;
                foreach($customerDirectoryList as $cust): 
                  $cName = $cust['customer_name'] ?: 'Guest';
                  $cPhone = $cust['phone'];
                  $cEmail = $cust['customer_email'];
                  $cBookings = (int)$cust['total_bookings'];
                  $cSpent = (float)$cust['total_spent'];
                  $cNote = trim($cust['customer_note'] ?? '');
                  $cDate = $cust['recent_order_date'] ? date('M d, Y', strtotime($cust['recent_order_date'])) : '—';
                  $initial = strtoupper(mb_substr($cName, 0, 1, 'UTF-8'));
                  $safePhoneJs = htmlspecialchars(addslashes($cPhone));
                  $safeNameJs = htmlspecialchars(addslashes($cName));
                  $safeNoteJs = htmlspecialchars(addslashes($cNote));
                  $rowId = 'cust_row_' . preg_replace('/[^0-9]/', '', $cPhone);
              ?>
                <tr class="customer-dir-row" id="<?= $rowId ?>" data-search="<?= strtolower(htmlspecialchars($cName . ' ' . $cPhone . ' ' . $cEmail . ' ' . $cNote)) ?>">
                  
                  <!-- Serial Number (Continuously numbered across pages, e.g. 1-25, 26-50...) -->
                  <td class="ps-4">
                    <span class="badge bg-light text-secondary border fw-bold px-2 py-1" style="font-size: 0.8rem;">
                      #<?= $serialNum++ ?>
                    </span>
                  </td>

                  <!-- Name, Phone, Email in ONE Column -->
                  <td>
                    <div class="d-flex align-items-center gap-3">
                      <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-sm flex-shrink-0" 
                           style="width: 40px; height: 40px; background: linear-gradient(135deg, #059669 0%, #10b981 100%); font-size: 0.95rem;">
                        <?= $initial ?>
                      </div>
                      <div class="min-w-0">
                        <div class="fw-bold text-dark text-truncate" style="font-size: 0.92rem;" title="<?= htmlspecialchars($cName) ?>">
                          <?= htmlspecialchars($cName) ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 mt-1">
                          <a href="tel:<?= htmlspecialchars($cPhone) ?>" class="text-success text-decoration-none fw-semibold small d-inline-flex align-items-center gap-1">
                            <i class="fa-solid fa-phone" style="font-size: 0.72rem;"></i>
                            <span><?= htmlspecialchars($cPhone) ?></span>
                          </a>
                          <button type="button" class="btn btn-link p-0 text-muted" title="Copy Phone Number" onclick="copyToClipboard('<?= htmlspecialchars($cPhone) ?>', this)">
                            <i class="fa-regular fa-copy" style="font-size: 0.75rem;"></i>
                          </button>
                        </div>
                        <div class="text-muted small text-truncate mt-1" style="font-size: 0.78rem;" title="<?= htmlspecialchars($cEmail ?: 'No email') ?>">
                          <i class="fa-regular fa-envelope me-1 text-secondary"></i>
                          <span><?= htmlspecialchars($cEmail ?: '—') ?></span>
                        </div>
                      </div>
                    </div>
                  </td>

                  <!-- Total Bookings -->
                  <td class="text-center">
                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 fw-bold">
                      <i class="fa-solid fa-calendar-check me-1"></i> <?= $cBookings ?> Booking<?= $cBookings > 1 ? 's' : '' ?>
                    </span>
                    <small class="text-muted d-block mt-1" style="font-size: 0.7rem;">
                      <i class="fa-solid fa-clock-rotate-left me-1"></i> <?= $cDate ?>
                    </small>
                  </td>

                  <!-- Total Spent (by number) -->
                  <td class="text-end">
                    <div class="fw-bold text-success fs-6">
                      ৳ <?= number_format($cSpent) ?>
                    </div>
                    <small class="text-muted d-block" style="font-size: 0.7rem;">Total Billed</small>
                  </td>

                  <!-- Customer Note (Editable) -->
                  <td>
                    <!-- Display Mode -->
                    <div class="note-display-box d-flex align-items-start justify-content-between p-2 rounded-3 border" id="note_display_<?= $rowId ?>" style="background: #f8fafc; min-height: 42px;">
                      <span class="small text-dark note-text <?= empty($cNote) ? 'text-muted fst-italic' : '' ?>" id="note_text_<?= $rowId ?>" style="font-size: 0.82rem; word-break: break-word;">
                        <?= !empty($cNote) ? htmlspecialchars($cNote) : 'No note recorded' ?>
                      </span>
                      <button type="button" class="btn btn-sm btn-link text-success p-0 ms-2 flex-shrink-0" title="Edit Note" onclick="toggleInlineNoteEdit('<?= $rowId ?>', true)">
                        <i class="fa-solid fa-pen-to-square"></i>
                      </button>
                    </div>

                    <!-- Inline Edit Mode (Initially Hidden) -->
                    <div class="note-edit-box d-none mt-1" id="note_edit_<?= $rowId ?>">
                      <div class="input-group input-group-sm">
                        <input type="text" class="form-control" id="note_input_<?= $rowId ?>" value="<?= htmlspecialchars($cNote) ?>" placeholder="Enter note...">
                        <button type="button" class="btn btn-success" title="Save Note" onclick="saveCustomerNoteAjax('<?= htmlspecialchars($cPhone) ?>', '<?= $rowId ?>')">
                          <i class="fa-solid fa-check"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" title="Cancel" onclick="toggleInlineNoteEdit('<?= $rowId ?>', false)">
                          <i class="fa-solid fa-xmark"></i>
                        </button>
                      </div>
                    </div>
                  </td>

                  <!-- Send Custom SMS Action Button -->
                  <td class="pe-4 text-end">
                    <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3 shadow-sm fw-semibold d-inline-flex align-items-center gap-1"
                            onclick="openSingleSmsModal('<?= $safeNameJs ?>', '<?= $safePhoneJs ?>')">
                      <i class="fa-solid fa-paper-plane" style="font-size: 0.75rem;"></i>
                      <span>Send SMS</span>
                    </button>
                  </td>

                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination Footer Bar (25 items per page) -->
      <div class="p-3 bg-white border-top d-flex justify-content-between align-items-center flex-wrap gap-3" id="customerPaginationFooter">
        <div class="text-muted small">
          Showing <strong><?= $totalUniqueGuests > 0 ? ($custOffset + 1) : 0 ?></strong> to <strong><?= min($custOffset + count($customerDirectoryList), $totalUniqueGuests) ?></strong> of <strong><?= $totalUniqueGuests ?></strong> total guests
          <span class="ms-1 badge bg-light text-secondary border">Page <?= $custPage ?> of <?= $totalCustPages ?></span>
        </div>

        <?php if ($totalCustPages > 1): ?>
          <?php $custSearchUrlParam = !empty($custSearch) ? '&cust_search=' . urlencode($custSearch) : ''; ?>
          <nav aria-label="Customer Directory Pagination">
            <ul class="pagination pagination-sm mb-0">
              
              <!-- Previous Button -->
              <li class="page-item <?= ($custPage <= 1) ? 'disabled' : '' ?>">
                <a class="page-link rounded-pill px-3 me-1 <?= ($custPage <= 1) ? 'text-muted' : 'text-dark' ?>" 
                   href="dashboard.php?cust_page=<?= max(1, $custPage - 1) ?><?= $custSearchUrlParam ?>#view-customers" 
                   aria-label="Previous">
                  <i class="fa-solid fa-chevron-left me-1"></i> Prev
                </a>
              </li>

              <!-- Page Number Links (Smart Window) -->
              <?php
                $startPage = max(1, $custPage - 2);
                $endPage   = min($totalCustPages, $custPage + 2);

                if ($startPage > 1):
              ?>
                <li class="page-item">
                  <a class="page-link rounded-circle mx-1 text-center" style="width:32px;height:32px;padding:5px 0;line-height:20px;" href="dashboard.php?cust_page=1<?= $custSearchUrlParam ?>#view-customers">1</a>
                </li>
                <?php if ($startPage > 2): ?>
                  <li class="page-item disabled"><span class="page-link border-0 text-muted">…</span></li>
                <?php endif; ?>
              <?php endif; ?>

              <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                <li class="page-item <?= ($p === $custPage) ? 'active' : '' ?>">
                  <a class="page-link rounded-circle mx-1 text-center <?= ($p === $custPage) ? 'bg-success border-success text-white fw-bold' : 'text-dark' ?>" 
                     style="width:32px;height:32px;padding:5px 0;line-height:20px;" 
                     href="dashboard.php?cust_page=<?= $p ?><?= $custSearchUrlParam ?>#view-customers">
                    <?= $p ?>
                  </a>
                </li>
              <?php endfor; ?>

              <?php if ($endPage < $totalCustPages): ?>
                <?php if ($endPage < $totalCustPages - 1): ?>
                  <li class="page-item disabled"><span class="page-link border-0 text-muted">…</span></li>
                <?php endif; ?>
                <li class="page-item">
                  <a class="page-link rounded-circle mx-1 text-center" style="width:32px;height:32px;padding:5px 0;line-height:20px;" href="dashboard.php?cust_page=<?= $totalCustPages ?><?= $custSearchUrlParam ?>#view-customers"><?= $totalCustPages ?></a>
                </li>
              <?php endif; ?>

              <!-- Next Button -->
              <li class="page-item <?= ($custPage >= $totalCustPages) ? 'disabled' : '' ?>">
                <a class="page-link rounded-pill px-3 ms-1 <?= ($custPage >= $totalCustPages) ? 'text-muted' : 'text-dark' ?>" 
                   href="dashboard.php?cust_page=<?= min($totalCustPages, $custPage + 1) ?><?= $custSearchUrlParam ?>#view-customers" 
                   aria-label="Next">
                  Next <i class="fa-solid fa-chevron-right ms-1"></i>
                </a>
              </li>

            </ul>
          </nav>
        <?php endif; ?>
      </div>

    </div>
  </div> <!-- /#view-customers -->


  <!-- ========================================================
       VIEW 6: REDESIGNED PAYMENTS & EARNINGS HUB (#view-earnings)
       ======================================================== -->
  <div class="dashboard-view-pane" id="view-earnings">
    <div class="admin-card mb-4">
      
      <!-- Card Header: Title & Search -->
      <div class="admin-card-header flex-wrap gap-3">
        <div>
          <div class="d-flex align-items-center gap-2">
            <h4 class="fw-bold text-dark mb-0"><i class="fa-solid fa-wallet text-success me-2"></i> Financial Ledger & Revenue Hub</h4>
            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2 py-1 fw-bold">Page <?= $earnPage ?> of <?= $totalEarnPages ?></span>
          </div>
          <p class="text-muted small mb-0 mt-1">Real-time revenue metrics, outstanding guest dues, instant settlement, and thank-you messaging.</p>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap ms-auto">
          <!-- Fast Search Bar -->
          <div class="input-group input-group-sm" style="width: 280px;">
            <span class="input-group-text bg-white border-end-0 text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
            <input type="text" id="earningsSearchInput" class="form-control border-start-0 border-end-0" 
                   placeholder="Search guest, phone, booking ref..." 
                   value="<?= htmlspecialchars($earnSearch) ?>"
                   oninput="filterEarningsTableClientSide(this.value)"
                   onkeydown="if(event.key==='Enter') executeEarningsSearchServer(this.value)">
            <button type="button" class="btn btn-white border border-start-0 text-muted <?= empty($earnSearch) ? 'd-none' : '' ?>" id="btnClearEarnSearch" onclick="clearEarningsSearch()" title="Clear">
              <i class="fa-solid fa-circle-xmark"></i>
            </button>
          </div>
        </div>
      </div>

      <div class="admin-card-body">
        
        <!-- Top 4 Executive KPI Metric Cards -->
        <div class="row g-3 mb-4">
          
          <!-- Card 1: Collected Revenue -->
          <div class="col-xl-3 col-sm-6">
            <div class="p-3 rounded-4 border h-100" style="border-color: #bbf7d0 !important; background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%);">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <small class="text-success text-uppercase fw-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Collected Revenue</small>
                <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2 py-1" style="font-size: 0.7rem;"><i class="fa-solid fa-circle-check me-1"></i>Settled</span>
              </div>
              <h3 class="fw-bold text-success mb-1" id="earnKpiCollectedRevenue">৳ <?= number_format($totalEarnings) ?></h3>
              <small class="text-muted" style="font-size: 0.75rem;">Cash, bKash & POS collections</small>
            </div>
          </div>

          <!-- Card 2: Outstanding Due Balance -->
          <div class="col-xl-3 col-sm-6">
            <div class="p-3 rounded-4 border h-100" style="border-color: #fecaca !important; background: linear-gradient(135deg, #fef2f2 0%, #ffffff 100%);">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <small class="text-danger text-uppercase fw-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Outstanding Due</small>
                <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-2 py-1" id="earnKpiDueBadge" style="font-size: 0.7rem;"><?= $dueCount ?> Pending</span>
              </div>
              <h3 class="fw-bold text-danger mb-1" id="earnKpiDueBalance">৳ <?= number_format($dueEarnings) ?></h3>
              <small class="text-muted" style="font-size: 0.75rem;">Unpaid balance to be collected</small>
            </div>
          </div>

          <!-- Card 3: Gross Invoiced Value -->
          <div class="col-xl-3 col-sm-6">
            <div class="p-3 rounded-4 border h-100" style="border-color: #bfdbfe !important; background: linear-gradient(135deg, #eff6ff 0%, #ffffff 100%);">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <small class="text-primary text-uppercase fw-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Total Expected Value</small>
                <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-2 py-1" style="font-size: 0.7rem;"><?= $totalLedgerCount ?> Stays</span>
              </div>
              <h3 class="fw-bold text-primary mb-1" id="earnKpiGrossRevenue">৳ <?= number_format($grossRevenue) ?></h3>
              <small class="text-muted" style="font-size: 0.75rem;">Total gross reservation billing</small>
            </div>
          </div>

          <!-- Card 4: Collection Rate -->
          <div class="col-xl-3 col-sm-6">
            <div class="p-3 rounded-4 border h-100" style="border-color: #e2e8f0 !important; background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <small class="text-muted text-uppercase fw-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Collection Rate</small>
                <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-2 py-1" style="font-size: 0.7rem;"><?= $paidCount ?> Paid in Full</span>
              </div>
              <h3 class="fw-bold text-dark mb-1" id="earnKpiRecoveryRate"><?= $recoveryRate ?>%</h3>
              <div class="progress mt-2" style="height: 6px; border-radius: 4px;">
                <div class="progress-bar bg-success" id="earnKpiProgressBar" style="width: <?= $recoveryRate ?>%;"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Filter Segment Tabs Bar -->
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
          <div class="btn-group btn-group-sm p-1 bg-light rounded-pill border" role="group">
            <a href="dashboard.php?earn_filter=all<?= !empty($earnSearch) ? '&earn_search='.urlencode($earnSearch) : '' ?>#view-earnings" 
               class="btn rounded-pill px-3 fw-semibold <?= ($earnFilter === 'all') ? 'btn-success text-white shadow-sm' : 'btn-light text-muted' ?>">
              All Transactions (<?= $totalLedgerCount ?>)
            </a>
            <a href="dashboard.php?earn_filter=due<?= !empty($earnSearch) ? '&earn_search='.urlencode($earnSearch) : '' ?>#view-earnings" 
               class="btn rounded-pill px-3 fw-semibold <?= ($earnFilter === 'due') ? 'btn-danger text-white shadow-sm' : 'btn-light text-muted' ?>">
              <i class="fa-solid fa-triangle-exclamation me-1"></i> Due Pending (<?= $dueCount ?>)
            </a>
            <a href="dashboard.php?earn_filter=paid<?= !empty($earnSearch) ? '&earn_search='.urlencode($earnSearch) : '' ?>#view-earnings" 
               class="btn rounded-pill px-3 fw-semibold <?= ($earnFilter === 'paid') ? 'btn-success text-white shadow-sm' : 'btn-light text-muted' ?>">
              <i class="fa-solid fa-circle-check me-1"></i> Fully Paid (<?= $paidCount ?>)
            </a>
            <a href="dashboard.php?earn_filter=partial<?= !empty($earnSearch) ? '&earn_search='.urlencode($earnSearch) : '' ?>#view-earnings" 
               class="btn rounded-pill px-3 fw-semibold <?= ($earnFilter === 'partial') ? 'btn-primary text-white shadow-sm' : 'btn-light text-muted' ?>">
              Partial Payments (<?= $partialCount ?>)
            </a>
          </div>

          <div class="text-muted small">
            Showing <strong><?= $totalEarnRows > 0 ? ($earnOffset + 1) : 0 ?></strong> to <strong><?= min($earnOffset + count($earningsList), $totalEarnRows) ?></strong> of <strong><?= $totalEarnRows ?></strong> records
          </div>
        </div>

        <!-- Ledger Table -->
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" id="earningsLedgerTable">
            <thead class="bg-light text-muted small text-uppercase" style="letter-spacing: 0.04em;">
              <tr>
                <th class="ps-4 py-3" style="width: 140px;">Booking Ref</th>
                <th class="py-3" style="min-width: 220px;">Guest Profile</th>
                <th class="py-3" style="min-width: 180px;">Cottage & Stays</th>
                <th class="py-3" style="min-width: 160px;">Total Billed / Paid</th>
                <th class="py-3" style="width: 150px;">Due Balance</th>
                <th class="py-3 text-center" style="width: 110px;">Status</th>
                <th class="pe-4 py-3 text-end" style="width: 160px;">Settlement Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if(empty($earningsList)): ?>
                <tr>
                  <td colspan="7" class="text-center py-5 text-muted">
                    <i class="fa-solid fa-receipt fs-2 d-block mb-2 text-secondary"></i>
                    No financial transaction records found for this filter.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach($earningsList as $cust): 
                  $cId = (int)$cust['id'];
                  $cName = $cust['name'] ?: 'Guest';
                  $cPhone = $cust['phone'] ?: '';
                  $cEmail = $cust['email'] ?: '';
                  $cRef = (!empty($cust['booking_id']) && $cust['booking_id'] !== '0') ? $cust['booking_id'] : ('#CUST-' . $cId);
                  $cCottage = $cust['cottage_name'] ?: 'Resort Cottage';
                  $cTotal = (float)($cust['total_amount'] ?? 0);
                  $cAdvance = (float)($cust['advance_amount'] ?? 0);
                  $cDue = (float)($cust['due_amount'] ?? 0);
                  $pMethod = $cust['payment_method'] ?: 'bKash';
                  $pStatus = $cust['payment_status'] ?? 'unpaid';
                  if ($cDue <= 0 && $cTotal > 0) $pStatus = 'paid';
                  elseif ($cAdvance > 0 && $cDue > 0) $pStatus = 'partial';
                  $badgeClass = ($pStatus === 'paid') ? 'status-badge-paid' : (($pStatus === 'partial') ? 'status-badge-partial' : 'status-badge-unpaid');
                  
                  $initial = strtoupper(mb_substr($cName, 0, 1, 'UTF-8'));
                  $checkIn = !empty($cust['check_in']) ? date('M d, Y', strtotime($cust['check_in'])) : '—';
                  $checkOut = !empty($cust['check_out']) ? date('M d, Y', strtotime($cust['check_out'])) : '—';
                  $rowId = 'earn_row_' . $cId;

                  // Prepare safe JSON for modal opening
                  $safeCustJson = htmlspecialchars(json_encode([
                    'id' => $cId,
                    'name' => $cName,
                    'phone' => $cPhone,
                    'email' => $cEmail,
                    'booking_ref' => $cRef,
                    'cottage' => $cCottage,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'total_amount' => $cTotal,
                    'advance_amount' => $cAdvance,
                    'due_amount' => $cDue,
                    'payment_method' => $pMethod,
                    'payment_status' => $pStatus
                  ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                ?>
                  <tr class="earn-table-row" id="<?= $rowId ?>" data-search="<?= strtolower(htmlspecialchars($cName . ' ' . $cPhone . ' ' . $cEmail . ' ' . $cRef . ' ' . $cCottage)) ?>">
                    
                    <!-- 1. Booking Reference -->
                    <td class="ps-4">
                      <span class="badge bg-light text-dark border fw-bold px-2 py-1" style="font-size: 0.8rem;">
                        <?= htmlspecialchars($cRef) ?>
                      </span>
                      <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">
                        <?= !empty($cust['created_at']) ? date('M d, Y', strtotime($cust['created_at'])) : '—' ?>
                      </small>
                    </td>

                    <!-- 2. Guest Profile -->
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-sm flex-shrink-0" 
                             style="width: 36px; height: 36px; background: linear-gradient(135deg, #059669 0%, #10b981 100%); font-size: 0.85rem;">
                          <?= $initial ?>
                        </div>
                        <div class="min-w-0">
                          <div class="fw-bold text-dark text-truncate" style="font-size: 0.88rem;" title="<?= htmlspecialchars($cName) ?>">
                            <?= htmlspecialchars($cName) ?>
                          </div>
                          <div class="d-flex align-items-center gap-2 mt-1">
                            <a href="tel:<?= htmlspecialchars($cPhone) ?>" class="text-success text-decoration-none fw-semibold small d-inline-flex align-items-center gap-1" style="font-size: 0.78rem;">
                              <i class="fa-solid fa-phone" style="font-size: 0.7rem;"></i>
                              <span><?= htmlspecialchars($cPhone) ?></span>
                            </a>
                            <button type="button" class="btn btn-link p-0 text-muted" title="Copy Phone Number" onclick="copyToClipboard('<?= htmlspecialchars($cPhone) ?>', this)">
                              <i class="fa-regular fa-copy" style="font-size: 0.72rem;"></i>
                            </button>
                          </div>
                          <?php if(!empty($cEmail)): ?>
                            <small class="text-muted text-truncate d-block" style="font-size: 0.72rem;" title="<?= htmlspecialchars($cEmail) ?>"><?= htmlspecialchars($cEmail) ?></small>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>

                    <!-- 3. Cottage & Stays -->
                    <td>
                      <div class="fw-semibold text-dark text-truncate" style="font-size: 0.84rem;" title="<?= htmlspecialchars($cCottage) ?>">
                        <i class="fa-solid fa-house-chimney text-success me-1"></i> <?= htmlspecialchars($cCottage) ?>
                      </div>
                      <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">
                        <i class="fa-regular fa-calendar me-1"></i> <?= $checkIn ?> → <?= $checkOut ?>
                      </small>
                    </td>

                    <!-- 4. Total Billed / Paid -->
                    <td>
                      <div class="fw-bold text-dark" style="font-size: 0.88rem;">
                        ৳ <?= number_format($cTotal) ?>
                      </div>
                      <div class="small mt-1 d-flex align-items-center gap-1">
                        <span class="text-success fw-semibold" id="advance_val_<?= $cId ?>" style="font-size: 0.78rem;">৳ <?= number_format($cAdvance) ?> paid</span>
                        <span class="badge bg-light text-muted border px-1" style="font-size: 0.68rem;"><?= htmlspecialchars($pMethod) ?></span>
                      </div>
                    </td>

                    <!-- 5. Due Balance -->
                    <td>
                      <?php if($cDue > 0): ?>
                        <div class="fw-bold text-danger fs-6" id="due_val_<?= $cId ?>">
                          ৳ <?= number_format($cDue) ?>
                        </div>
                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25" id="due_badge_<?= $cId ?>" style="font-size: 0.68rem;">
                          <i class="fa-solid fa-triangle-exclamation me-1"></i>Due Pending
                        </span>
                      <?php else: ?>
                        <div class="fw-bold text-success fs-6" id="due_val_<?= $cId ?>">
                          ৳ 0
                        </div>
                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25" id="due_badge_<?= $cId ?>" style="font-size: 0.68rem;">
                          <i class="fa-solid fa-circle-check me-1"></i>Fully Paid
                        </span>
                      <?php endif; ?>
                    </td>

                    <!-- 6. Payment Status -->
                    <td class="text-center">
                      <span class="status-badge <?= $badgeClass ?>" id="status_badge_<?= $cId ?>">
                        <?= ucfirst($pStatus) ?>
                      </span>
                    </td>

                    <!-- 7. Action: Mark Paid & Send Thank You Message -->
                    <td class="pe-4 text-end">
                      <?php if($cDue > 0): ?>
                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm fw-bold d-inline-flex align-items-center gap-1 action-btn-pay" 
                                id="btn_pay_cust_<?= $cId ?>"
                                onclick="openMarkPaidModal(<?= $safeCustJson ?>, false)">
                          <i class="fa-solid fa-hand-holding-dollar" style="font-size: 0.8rem;"></i>
                          <span>Mark Paid</span>
                        </button>
                      <?php else: ?>
                        <div class="d-inline-flex align-items-center gap-1">
                          <a href="invoice.php?customer_id=<?= $cId ?>" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-3 shadow-sm fw-bold d-inline-flex align-items-center gap-1 action-btn-pay" title="Download Official Invoice Receipt">
                            <i class="fa-solid fa-file-invoice-dollar" style="font-size: 0.85rem;"></i>
                            <span>Download Invoice</span>
                          </a>
                          <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2 fw-medium d-inline-flex align-items-center gap-1 action-btn-pay" 
                                  id="btn_pay_cust_<?= $cId ?>"
                                  onclick="openMarkPaidModal(<?= $safeCustJson ?>, true)" title="View Receipt / Send SMS">
                            <i class="fa-solid fa-receipt" style="font-size: 0.8rem;"></i>
                          </button>
                        </div>
                      <?php endif; ?>
                    </td>

                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination Footer Bar (25 items per page) -->
        <?php if($totalEarnPages > 1): ?>
          <?php 
            $earnUrlParams = '&earn_filter=' . urlencode($earnFilter) . (!empty($earnSearch) ? '&earn_search=' . urlencode($earnSearch) : '');
            $startEarnPage = max(1, $earnPage - 2);
            $endEarnPage   = min($totalEarnPages, $earnPage + 2);
          ?>
          <div class="p-3 bg-white border-top d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="text-muted small">
              Page <strong><?= $earnPage ?></strong> of <strong><?= $totalEarnPages ?></strong>
              <span class="ms-1 badge bg-light text-secondary border"><?= $totalEarnRows ?> total records</span>
            </div>

            <nav aria-label="Financial Ledger Pagination">
              <ul class="pagination pagination-sm mb-0">
                <!-- Prev -->
                <li class="page-item <?= ($earnPage <= 1) ? 'disabled' : '' ?>">
                  <a class="page-link rounded-pill px-3 me-1 <?= ($earnPage <= 1) ? 'text-muted' : 'text-dark' ?>" 
                     href="dashboard.php?earn_page=<?= max(1, $earnPage - 1) ?><?= $earnUrlParams ?>#view-earnings">
                    <i class="fa-solid fa-chevron-left me-1"></i> Prev
                  </a>
                </li>

                <!-- Smart Pages -->
                <?php if ($startEarnPage > 1): ?>
                  <li class="page-item"><a class="page-link rounded-circle mx-1 text-center" style="width:32px;height:32px;padding:5px 0;line-height:20px;" href="dashboard.php?earn_page=1<?= $earnUrlParams ?>#view-earnings">1</a></li>
                  <?php if ($startEarnPage > 2): ?><li class="page-item disabled"><span class="page-link border-0 text-muted">…</span></li><?php endif; ?>
                <?php endif; ?>

                <?php for ($ep = $startEarnPage; $ep <= $endEarnPage; $ep++): ?>
                  <li class="page-item <?= ($ep === $earnPage) ? 'active' : '' ?>">
                    <a class="page-link rounded-circle mx-1 text-center <?= ($ep === $earnPage) ? 'bg-success border-success text-white fw-bold' : 'text-dark' ?>" 
                       style="width:32px;height:32px;padding:5px 0;line-height:20px;" 
                       href="dashboard.php?earn_page=<?= $ep ?><?= $earnUrlParams ?>#view-earnings">
                      <?= $ep ?>
                    </a>
                  </li>
                <?php endfor; ?>

                <?php if ($endEarnPage < $totalEarnPages): ?>
                  <?php if ($endEarnPage < $totalEarnPages - 1): ?><li class="page-item disabled"><span class="page-link border-0 text-muted">…</span></li><?php endif; ?>
                  <li class="page-item"><a class="page-link rounded-circle mx-1 text-center" style="width:32px;height:32px;padding:5px 0;line-height:20px;" href="dashboard.php?earn_page=<?= $totalEarnPages ?><?= $earnUrlParams ?>#view-earnings"><?= $totalEarnPages ?></a></li>
                <?php endif; ?>

                <!-- Next -->
                <li class="page-item <?= ($earnPage >= $totalEarnPages) ? 'disabled' : '' ?>">
                  <a class="page-link rounded-pill px-3 ms-1 <?= ($earnPage >= $totalEarnPages) ? 'text-muted' : 'text-dark' ?>" 
                     href="dashboard.php?earn_page=<?= min($totalEarnPages, $earnPage + 1) ?><?= $earnUrlParams ?>#view-earnings">
                    Next <i class="fa-solid fa-chevron-right ms-1"></i>
                  </a>
                </li>
              </ul>
            </nav>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </div> <!-- /#view-earnings -->


  <!-- ========================================================
       VIEW 7: GUEST INQUIRIES (#view-complaints)
       ======================================================== -->
  <div class="dashboard-view-pane" id="view-complaints">
    <div class="admin-card mb-4">
      <div class="admin-card-header">
        <div>
          <h4 class="fw-bold text-dark mb-1"><i class="fa-solid fa-comments text-success me-2"></i> Guest Inquiries & Messages</h4>
          <p class="text-muted small mb-0">Total <?= $totalComplaints ?> messages submitted from the resort contact page.</p>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-dark-custom">
          <thead>
            <tr>
              <th>ID</th>
              <th>Guest Name</th>
              <th>Email & Phone</th>
              <th>Subject</th>
              <th>Message</th>
              <th>Received At</th>
            </tr>
          </thead>
          <tbody>
            <?php if(empty($allComplaints)): ?>
              <tr><td colspan="6" class="text-center py-4 text-muted">No inquiries logged.</td></tr>
            <?php else: ?>
              <?php foreach($allComplaints as $cmp): ?>
                <tr>
                  <td><strong>#<?= $cmp['id'] ?></strong></td>
                  <td class="fw-bold text-dark"><?= htmlspecialchars($cmp['name']) ?></td>
                  <td>
                    <div><?= htmlspecialchars($cmp['email']) ?></div>
                    <small class="text-muted"><?= htmlspecialchars($cmp['phone'] ?? '') ?></small>
                  </td>
                  <td class="fw-semibold text-dark"><?= htmlspecialchars($cmp['subject'] ?? 'General Inquiry') ?></td>
                  <td>
                    <div style="max-width: 320px; white-space: normal;" class="small text-muted">
                      <?= htmlspecialchars($cmp['message']) ?>
                    </div>
                  </td>
                  <td><small class="text-muted"><?= htmlspecialchars($cmp['created_at']) ?></small></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div> <!-- /#view-complaints -->

  <!-- ========================================================
       VIEW: ADMINS & STAFF MANAGEMENT (#view-admins)
       ======================================================== -->
  <div class="dashboard-view-pane" id="view-admins">

    <!-- Header & Action Card -->
    <div class="admin-card mb-4" style="background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 65%, #ecfdf5 100%); border-color: #a7f3d0;">
      <div class="p-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div>
          <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25 small mb-2 fw-semibold">
            <i class="fa-solid fa-user-shield"></i> Access & Security Control
          </div>
          <h3 class="fw-bold text-dark mb-1">Staff & Administrators</h3>
          <p class="text-muted mb-0 small">Manage authorized administrator accounts, credentials, and access roles for Eco Divine Resort.</p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <button type="button" class="btn btn-success rounded-pill px-4 py-2 fw-bold shadow-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addAdminModal">
            <i class="fa-solid fa-user-plus"></i>
            <span>Add Another Admin</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Overview Stats Cards -->
    <div class="row g-3 mb-4">
      <div class="col-sm-6 col-lg-4">
        <div class="admin-card p-3 d-flex align-items-center justify-content-between h-100 shadow-sm" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px;">
          <div>
            <div class="text-muted small fw-bold text-uppercase mb-1" style="letter-spacing: 0.6px; font-size: 0.72rem;">Total Authorized Users</div>
            <div class="fs-3 fw-bold text-dark lh-1" id="adminTotalCountBadge"><?= count($allAdmins) ?></div>
            <small class="text-success fw-medium" style="font-size: 0.75rem;"><i class="fa-solid fa-shield-halved me-1"></i>Active credentials in system</small>
          </div>
          <div class="rounded-circle d-flex align-items-center justify-content-center text-success" style="width: 48px; height: 48px; background: #ecfdf5; font-size: 1.3rem;">
            <i class="fa-solid fa-users-gear"></i>
          </div>
        </div>
      </div>

      <div class="col-sm-6 col-lg-4">
        <div class="admin-card p-3 d-flex align-items-center justify-content-between h-100 shadow-sm" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px;">
          <div>
            <div class="text-muted small fw-bold text-uppercase mb-1" style="letter-spacing: 0.6px; font-size: 0.72rem;">Current Session User</div>
            <div class="fs-5 fw-bold text-dark lh-sm text-truncate" style="max-width: 190px;"><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></div>
            <small class="text-muted" style="font-size: 0.75rem;"><code class="text-dark">@<?= htmlspecialchars($_SESSION['admin_username'] ?? 'admin') ?></code></small>
          </div>
          <div class="rounded-circle d-flex align-items-center justify-content-center text-primary" style="width: 48px; height: 48px; background: #eff6ff; font-size: 1.3rem;">
            <i class="fa-solid fa-circle-user"></i>
          </div>
        </div>
      </div>

      <div class="col-sm-12 col-lg-4">
        <div class="admin-card p-3 d-flex align-items-center justify-content-between h-100 shadow-sm" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px;">
          <div>
            <div class="text-muted small fw-bold text-uppercase mb-1" style="letter-spacing: 0.6px; font-size: 0.72rem;">Authentication Shield</div>
            <div class="fs-5 fw-bold text-success lh-sm">BCrypt Secured</div>
            <small class="text-muted" style="font-size: 0.75rem;"><i class="fa-solid fa-lock text-success me-1"></i>Session Guard Active</small>
          </div>
          <div class="rounded-circle d-flex align-items-center justify-content-center text-info" style="width: 48px; height: 48px; background: #f0fdfa; font-size: 1.3rem;">
            <i class="fa-solid fa-fingerprint text-success"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- Admins Directory Table Card -->
    <div class="admin-card shadow-sm" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden;">
      
      <!-- Table Header & Search Filter -->
      <div class="p-3 border-bottom d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3" style="background: #ffffff; border-color: #f1f5f9 !important;">
        <div class="d-flex align-items-center gap-2">
          <span class="fw-bold text-dark fs-6">Registered Admin Accounts</span>
          <span class="badge bg-secondary bg-opacity-10 text-secondary border px-2 py-1 rounded-pill small" id="adminsCountDisplay">
            <?= count($allAdmins) ?> Staff
          </span>
        </div>

        <div class="d-flex align-items-center gap-2">
          <div class="input-group input-group-sm" style="max-width: 280px;">
            <span class="input-group-text bg-white border-end-0 text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
            <input type="text" class="form-control border-start-0 ps-0" id="adminsSearchInput" placeholder="Search name, username, email..." oninput="filterAdminsTableClientSide(this.value)">
          </div>
          <button type="button" class="btn btn-sm btn-success rounded-pill px-3 fw-bold d-flex align-items-center gap-1 flex-shrink-0" data-bs-toggle="modal" data-bs-target="#addAdminModal">
            <i class="fa-solid fa-plus"></i> <span>Add Admin</span>
          </button>
        </div>
      </div>

      <!-- Responsive Table -->
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="adminsDirectoryTable">
          <thead style="background: #f8fafc; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em;" class="text-muted border-bottom">
            <tr>
              <th class="ps-4 py-3" style="width: 50px;">#</th>
              <th class="py-3">Administrator / Staff</th>
              <th class="py-3">Username</th>
              <th class="py-3">Email Address</th>
              <th class="py-3 text-center">System Role</th>
              <th class="py-3">Created Date</th>
              <th class="pe-4 py-3 text-end" style="min-width: 140px;">Actions</th>
            </tr>
          </thead>
          <tbody style="font-size: 0.88rem;">
            <?php if (empty($allAdmins)): ?>
              <tr>
                <td colspan="7" class="text-center py-5 text-muted">
                  <i class="fa-solid fa-user-slash fs-2 mb-2 d-block text-secondary opacity-50"></i>
                  No admin accounts found in system.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($allAdmins as $idx => $adm): 
                $admId = (int)$adm['id'];
                $isSelf = ($admId === (int)($_SESSION['admin_id'] ?? 0));
                $firstLetter = strtoupper(substr($adm['name'] ?: $adm['username'], 0, 1));
                $role = strtolower($adm['role'] ?? 'admin');
                $roleBadgeClass = 'bg-secondary bg-opacity-10 text-secondary border-secondary';
                $roleLabel = 'Staff';
                $roleIcon = 'fa-user';
                if ($role === 'admin' || $role === 'superadmin') {
                  $roleBadgeClass = 'bg-success bg-opacity-10 text-success border-success';
                  $roleLabel = 'Administrator';
                  $roleIcon = 'fa-crown';
                } elseif ($role === 'manager') {
                  $roleBadgeClass = 'bg-primary bg-opacity-10 text-primary border-primary';
                  $roleLabel = 'Manager';
                  $roleIcon = 'fa-user-tie';
                }
                $safeAdmJson = htmlspecialchars(json_encode([
                  'id' => $admId,
                  'name' => $adm['name'],
                  'username' => $adm['username'],
                  'email' => $adm['email'],
                  'role' => $adm['role'] ?? 'admin'
                ]), ENT_QUOTES, 'UTF-8');
              ?>
                <tr class="admin-row" data-search="<?= htmlspecialchars(strtolower($adm['name'] . ' ' . $adm['username'] . ' ' . $adm['email'] . ' ' . $roleLabel)) ?>">
                  <td class="ps-4 text-muted fw-semibold"><?= $idx + 1 ?></td>
                  <td>
                    <div class="d-flex align-items-center gap-3">
                      <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-sm" style="width: 38px; height: 38px; background: linear-gradient(135deg, #059669 0%, #10b981 100%); font-size: 0.95rem; flex-shrink: 0;">
                        <?= $firstLetter ?>
                      </div>
                      <div>
                        <div class="fw-bold text-dark d-flex align-items-center gap-2">
                          <span><?= htmlspecialchars($adm['name']) ?></span>
                          <?php if ($isSelf): ?>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2 py-0 fw-semibold" style="font-size: 0.68rem;">You</span>
                          <?php endif; ?>
                        </div>
                        <small class="text-muted" style="font-size: 0.75rem;">ID #<?= $admId ?></small>
                      </div>
                    </div>
                  </td>
                  <td>
                    <code class="text-dark px-2 py-1 rounded border" style="background: #f8fafc; font-size: 0.82rem;">@<?= htmlspecialchars($adm['username']) ?></code>
                  </td>
                  <td>
                    <a href="mailto:<?= htmlspecialchars($adm['email']) ?>" class="text-decoration-none text-muted d-inline-flex align-items-center gap-1">
                      <i class="fa-regular fa-envelope text-secondary"></i>
                      <span><?= htmlspecialchars($adm['email']) ?></span>
                    </a>
                  </td>
                  <td class="text-center">
                    <span class="badge border border-opacity-25 rounded-pill px-3 py-1 fw-semibold <?= $roleBadgeClass ?>" style="font-size: 0.75rem;">
                      <i class="fa-solid <?= $roleIcon ?> me-1"></i><?= $roleLabel ?>
                    </span>
                  </td>
                  <td class="text-muted small">
                    <?= !empty($adm['created_at']) ? date('M d, Y h:i A', strtotime($adm['created_at'])) : '—' ?>
                  </td>
                  <td class="pe-4 text-end">
                    <div class="d-inline-flex align-items-center gap-1">
                      <!-- Edit Button -->
                      <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold d-inline-flex align-items-center gap-1" onclick="openEditAdminModal(<?= $safeAdmJson ?>)" title="Edit Administrator Profile">
                        <i class="fa-solid fa-pen-to-square"></i>
                        <span>Edit</span>
                      </button>

                      <!-- Delete Button -->
                      <?php if ($isSelf): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2 disabled" title="You cannot delete your own logged-in account" disabled>
                          <i class="fa-solid fa-trash-can"></i>
                        </button>
                      <?php elseif (count($allAdmins) <= 1): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2 disabled" title="Cannot delete the last remaining admin" disabled>
                          <i class="fa-solid fa-trash-can"></i>
                        </button>
                      <?php else: ?>
                        <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-2 fw-semibold" onclick="openDeleteAdminModal(<?= $admId ?>, '<?= htmlspecialchars(addslashes($adm['name'])) ?>', '<?= htmlspecialchars(addslashes($adm['username'])) ?>')" title="Delete Administrator Account">
                          <i class="fa-solid fa-trash-can"></i>
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Table Footer / Count Info -->
      <div class="p-3 border-top bg-light bg-opacity-50 text-muted small d-flex justify-content-between align-items-center">
        <span>Showing <strong id="adminVisibleCount"><?= count($allAdmins) ?></strong> authorized accounts</span>
        <span class="text-muted" style="font-size: 0.75rem;"><i class="fa-solid fa-shield-halved text-success me-1"></i>Eco Divine Security Control</span>
      </div>

    </div>

  </div> <!-- /#view-admins -->

  <!-- ========================================================
  <!-- ========================================================
       VIEW: SETTINGS & INTEGRATIONS (#view-settings)
       ======================================================== -->
  <div class="dashboard-view-pane" id="view-settings">

    <!-- Header & Action Card -->
    <div class="admin-card mb-4" style="background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 65%, #ecfdf5 100%); border-color: #a7f3d0;">
      <div class="p-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div>
          <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25 small mb-2 fw-semibold">
            <i class="fa-solid fa-sliders"></i> System Settings &amp; Integrations
          </div>
          <h3 class="fw-bold text-dark mb-1">Resort Settings &amp; Communications Hub</h3>
          <p class="text-muted mb-0 small">Manage your profile credentials, configure SMS Gateway API, and connect SMTP mail server for automated guest confirmations.</p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-3 py-2 btn-sm" onclick="activateView('view-admins')">
            <i class="fa-solid fa-user-shield me-1"></i> Staff Directory
          </button>
          <a href="logout.php" class="btn btn-outline-danger rounded-pill px-3 py-2 btn-sm">
            <i class="fa-solid fa-arrow-right-from-bracket me-1"></i> Sign Out
          </a>
        </div>
      </div>
    </div>

    <!-- Navigation Pills Tabs -->
    <ul class="nav nav-pills gap-2 mb-4 p-2 bg-white rounded-4 border shadow-sm" id="settingsNavPills" role="tablist" style="border-color: #e2e8f0 !important;">
      <li class="nav-item" role="presentation">
        <button class="nav-link active rounded-pill fw-bold px-3 py-2" id="pill-profile-tab" data-bs-toggle="pill" data-bs-target="#pill-profile" type="button" role="tab" aria-selected="true">
          <i class="fa-solid fa-user-gear me-1"></i> Profile &amp; Security
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link rounded-pill fw-bold px-3 py-2" id="pill-sms-tab" data-bs-toggle="pill" data-bs-target="#pill-sms" type="button" role="tab" aria-selected="false">
          <i class="fa-solid fa-comment-sms me-1 text-primary"></i> SMS Gateway Integration
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link rounded-pill fw-bold px-3 py-2" id="pill-email-tab" data-bs-toggle="pill" data-bs-target="#pill-email" type="button" role="tab" aria-selected="false">
          <i class="fa-solid fa-envelope-open-text me-1 text-danger"></i> Email &amp; SMTP Integration
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link rounded-pill fw-bold px-3 py-2" id="pill-logs-tab" data-bs-toggle="pill" data-bs-target="#pill-logs" type="button" role="tab" aria-selected="false" onclick="loadIntegrationLogs()">
          <i class="fa-solid fa-clock-rotate-left me-1 text-warning"></i> Delivery Logs
        </button>
      </li>
    </ul>

    <!-- Tab Contents Container -->
    <div class="tab-content" id="settingsTabContent">

      <!-- ========================================================
           TAB 1: PROFILE & SECURITY (#pill-profile)
           ======================================================== -->
      <div class="tab-pane fade show active" id="pill-profile" role="tabpanel">
        <div class="row g-4 mb-4">
          
          <!-- Left Column: Personal Profile Information Card -->
          <div class="col-lg-7">
            <div class="admin-card shadow-sm h-100" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 18px; overflow: hidden;">
              
              <div class="p-3 border-bottom d-flex align-items-center justify-content-between bg-light bg-opacity-50">
                <div class="d-flex align-items-center gap-2">
                  <span class="rounded-circle bg-success bg-opacity-10 text-success d-inline-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                    <i class="fa-solid fa-id-card"></i>
                  </span>
                  <span class="fw-bold text-dark">Profile Information</span>
                </div>
                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2 py-1 small">
                  Account #<?= htmlspecialchars($currentAdminUser['id'] ?? $_SESSION['admin_id']) ?>
                </span>
              </div>

              <form action="dashboard.php" method="POST" class="p-4">
                <input type="hidden" name="action_update_profile_settings" value="1">

                <!-- Profile Avatar Preview Banner -->
                <div class="d-flex align-items-center gap-3 p-3 rounded-3 mb-4" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                  <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-sm" style="width: 52px; height: 52px; background: linear-gradient(135deg, #059669 0%, #10b981 100%); font-size: 1.35rem; flex-shrink: 0;">
                    <?= strtoupper(substr($currentAdminUser['name'] ?? $_SESSION['admin_name'], 0, 1)) ?>
                  </div>
                  <div class="min-w-0">
                    <div class="fw-bold text-dark fs-6 text-truncate"><?= htmlspecialchars($currentAdminUser['name'] ?? $_SESSION['admin_name']) ?></div>
                    <div class="text-muted small"><code class="text-dark">@<?= htmlspecialchars($currentAdminUser['username'] ?? $_SESSION['admin_username']) ?></code> &bull; <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-0" style="font-size: 0.68rem;"><?= ucfirst($currentAdminUser['role'] ?? $_SESSION['admin_role'] ?? 'admin') ?></span></div>
                  </div>
                </div>

                <!-- Full Name Input -->
                <div class="mb-3">
                  <label class="form-label text-dark fw-bold small mb-1">FULL NAME <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-user"></i></span>
                    <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($currentAdminUser['name'] ?? $_SESSION['admin_name']) ?>" required placeholder="e.g. Nur Hossan">
                  </div>
                  <small class="text-muted" style="font-size: 0.72rem;">Shown across reports, bookings, and audit records.</small>
                </div>

                <!-- Username Input -->
                <div class="mb-3">
                  <label class="form-label text-dark fw-bold small mb-1">USERNAME <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text bg-white text-muted">@</span>
                    <input type="text" class="form-control" name="username" value="<?= htmlspecialchars($currentAdminUser['username'] ?? $_SESSION['admin_username']) ?>" required pattern="[A-Za-z0-9_.-]{3,}" title="Minimum 3 characters (letters, numbers, underscore, dash, period)">
                  </div>
                  <small class="text-muted" style="font-size: 0.72rem;">Your unique identifier used to sign in to the resort admin portal.</small>
                </div>

                <!-- Email Address Input -->
                <div class="mb-3">
                  <label class="form-label text-dark fw-bold small mb-1">EMAIL ADDRESS <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-envelope"></i></span>
                    <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($currentAdminUser['email'] ?? $_SESSION['admin_email']) ?>" required placeholder="e.g. admin@ecodivine.com">
                  </div>
                  <small class="text-muted" style="font-size: 0.72rem;">Used for system notifications and account recovery.</small>
                </div>

                <!-- Role (Read Only) -->
                <div class="mb-4">
                  <label class="form-label text-muted fw-bold small mb-1" style="font-size: 0.72rem;">ASSIGNED PRIVILEGES / SYSTEM ROLE</label>
                  <div class="p-2 rounded-2 border bg-light text-muted small d-flex align-items-center justify-content-between">
                    <span><i class="fa-solid fa-crown text-warning me-1"></i> <?= ucfirst($currentAdminUser['role'] ?? $_SESSION['admin_role'] ?? 'admin') ?></span>
                    <span class="badge bg-secondary bg-opacity-10 text-secondary border px-2 py-0" style="font-size: 0.68rem;">Role cannot be self-demoted</span>
                  </div>
                </div>

                <!-- Save Profile Button -->
                <div class="d-flex justify-content-end">
                  <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm d-flex align-items-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span>Save Profile Changes</span>
                  </button>
                </div>

              </form>

            </div>
          </div>

          <!-- Right Column: Password & Account Security Card -->
          <div class="col-lg-5">
            <div class="d-flex flex-column gap-4">
              
              <!-- Password Change Card -->
              <div class="admin-card shadow-sm" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 18px; overflow: hidden;">
                <div class="p-3 border-bottom d-flex align-items-center justify-content-between bg-light bg-opacity-50">
                  <div class="d-flex align-items-center gap-2">
                    <span class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                      <i class="fa-solid fa-key"></i>
                    </span>
                    <span class="fw-bold text-dark">Change Password</span>
                  </div>
                  <i class="fa-solid fa-lock text-muted small"></i>
                </div>

                <form action="dashboard.php" method="POST" class="p-4">
                  <input type="hidden" name="action_update_profile_password" value="1">

                  <!-- Current Password -->
                  <div class="mb-3">
                    <label class="form-label text-dark fw-bold small mb-1">CURRENT PASSWORD <span class="text-danger">*</span></label>
                    <div class="input-group">
                      <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-lock"></i></span>
                      <input type="password" class="form-control" name="current_password" id="setCurrPassword" required placeholder="Verify current password">
                      <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('setCurrPassword', 'setCurrEyeIcon')">
                        <i class="fa-regular fa-eye" id="setCurrEyeIcon"></i>
                      </button>
                    </div>
                  </div>

                  <!-- New Password -->
                  <div class="mb-3">
                    <label class="form-label text-dark fw-bold small mb-1">NEW PASSWORD <span class="text-danger">*</span></label>
                    <div class="input-group">
                      <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-shield-halved"></i></span>
                      <input type="password" class="form-control" name="new_password" id="setNewPassword" required minlength="6" placeholder="Min. 6 characters">
                      <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('setNewPassword', 'setNewEyeIcon')">
                        <i class="fa-regular fa-eye" id="setNewEyeIcon"></i>
                      </button>
                    </div>
                  </div>

                  <!-- Confirm New Password -->
                  <div class="mb-3">
                    <label class="form-label text-dark fw-bold small mb-1">CONFIRM NEW PASSWORD <span class="text-danger">*</span></label>
                    <div class="input-group">
                      <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-check-double"></i></span>
                      <input type="password" class="form-control" name="confirm_password" id="setConfPassword" required minlength="6" placeholder="Repeat new password">
                      <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('setConfPassword', 'setConfEyeIcon')">
                        <i class="fa-regular fa-eye" id="setConfEyeIcon"></i>
                      </button>
                    </div>
                  </div>

                  <div class="alert alert-light border py-2 px-3 small text-muted mb-3" style="font-size: 0.75rem;">
                    <i class="fa-solid fa-circle-info text-primary me-1"></i> Passwords must be at least 6 characters. Ensure you remember your new credentials.
                  </div>

                  <button type="submit" class="btn btn-primary rounded-pill w-100 fw-bold shadow-sm d-flex align-items-center justify-content-center gap-2">
                    <i class="fa-solid fa-key"></i>
                    <span>Update Password</span>
                  </button>
                </form>
              </div>

              <!-- Account Security Overview Card -->
              <div class="admin-card shadow-sm p-4" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 18px;">
                <h6 class="fw-bold text-dark mb-3 d-flex align-items-center gap-2">
                  <i class="fa-solid fa-shield-check text-success"></i> Security Health
                </h6>
                
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom small">
                  <span class="text-muted">Password Encryption</span>
                  <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2 py-1">BCrypt Default</span>
                </div>
                
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom small">
                  <span class="text-muted">Session Fixation Shield</span>
                  <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2 py-1">Active</span>
                </div>

                <div class="d-flex justify-content-between align-items-center py-2 small">
                  <span class="text-muted">Account Registered</span>
                  <span class="fw-semibold text-dark"><?= !empty($currentAdminUser['created_at']) ? date('M d, Y', strtotime($currentAdminUser['created_at'])) : '—' ?></span>
                </div>
              </div>

            </div>
          </div>

        </div>
      </div>

      <!-- ========================================================
           TAB 2: SMS GATEWAY INTEGRATION (#pill-sms)
           ======================================================== -->
      <div class="tab-pane fade" id="pill-sms" role="tabpanel">
        <div class="row g-4 mb-4">
          
          <!-- Left: SMS Gateway Configuration Form -->
          <div class="col-lg-7">
            <div class="admin-card shadow-sm" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 18px; overflow: hidden;">
              
              <div class="p-3 border-bottom d-flex align-items-center justify-content-between bg-light bg-opacity-50">
                <div class="d-flex align-items-center gap-2">
                  <span class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                    <i class="fa-solid fa-tower-broadcast"></i>
                  </span>
                  <span class="fw-bold text-dark">SMS Gateway Configuration</span>
                </div>
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-2 py-1 small">
                  Bangladesh Telco Ready
                </span>
              </div>

              <form id="smsSettingsForm" onsubmit="handleSaveSmsSettings(event)" class="p-4">
                <input type="hidden" name="action_update_sms_settings" value="1">

                <div id="smsSettingsAlert" class="alert d-none mb-3 py-2 small"></div>

                <!-- Master SMS Switch -->
                <div class="p-3 rounded-3 mb-3 border bg-light d-flex align-items-center justify-content-between">
                  <div>
                    <label class="form-check-label fw-bold text-dark small d-block" for="intSmsEnabled">
                      Enable SMS Gateway Dispatch
                    </label>
                    <small class="text-muted" style="font-size: 0.73rem;">Turn off to pause all outgoing automated resort SMS messages.</small>
                  </div>
                  <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="intSmsEnabled" name="sms_enabled" value="1" <?= ($sysSettings['sms_enabled'] ?? '1') === '1' ? 'checked' : '' ?> style="width: 2.5em; height: 1.3em;">
                  </div>
                </div>

                <!-- Gateway Provider Dropdown -->
                <div class="mb-3">
                  <label class="form-label text-dark fw-bold small mb-1">GATEWAY PROVIDER / AGGREGATOR <span class="text-danger">*</span></label>
                  <select class="form-select" id="intSmsProvider" name="sms_provider" onchange="handleSmsProviderChange(this.value)">
                    <option value="greenweb" <?= ($sysSettings['sms_provider'] ?? '') === 'greenweb' ? 'selected' : '' ?>>🟢 Greenweb BD (Token API)</option>
                    <option value="bulksmsbd" <?= ($sysSettings['sms_provider'] ?? '') === 'bulksmsbd' ? 'selected' : '' ?>>🔵 BulkSMSBD.com (API Key &amp; SenderID)</option>
                    <option value="mimsms" <?= ($sysSettings['sms_provider'] ?? '') === 'mimsms' ? 'selected' : '' ?>>🟠 MimSMS / Alpha SMS (JSON REST API)</option>
                    <option value="custom" <?= ($sysSettings['sms_provider'] ?? '') === 'custom' ? 'selected' : '' ?>>⚙️ Custom REST API / Webhook</option>
                  </select>
                  <small class="text-muted" style="font-size: 0.72rem;">Select your SMS vendor to auto-configure endpoint settings.</small>
                </div>

                <!-- API Key / Token Input -->
                <div class="mb-3">
                  <label class="form-label text-dark fw-bold small mb-1">API KEY / AUTH TOKEN <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-key"></i></span>
                    <input type="password" class="form-control font-monospace" id="intSmsApiKey" name="sms_api_key" value="<?= htmlspecialchars($sysSettings['sms_api_key'] ?? '') ?>" placeholder="Paste your API key or bearer token here">
                    <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('intSmsApiKey', 'intSmsKeyEye')">
                      <i class="fa-regular fa-eye" id="intSmsKeyEye"></i>
                    </button>
                  </div>
                  <small class="text-muted" style="font-size: 0.72rem;">Issued by your SMS provider dashboard.</small>
                </div>

                <!-- Sender ID & Hotline -->
                <div class="row g-3 mb-3">
                  <div class="col-md-6">
                    <label class="form-label text-dark fw-bold small mb-1">SENDER ID / MASKING NAME</label>
                    <div class="input-group">
                      <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-tag"></i></span>
                      <input type="text" class="form-control" name="sms_sender_id" value="<?= htmlspecialchars($sysSettings['sms_sender_id'] ?? 'EcoDivine') ?>" placeholder="e.g. EcoDivine">
                    </div>
                    <small class="text-muted" style="font-size: 0.72rem;">Approved sender ID or non-masking number.</small>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label text-dark fw-bold small mb-1">RESORT HOTLINE NUMBER <span class="text-danger">*</span></label>
                    <div class="input-group">
                      <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-phone"></i></span>
                      <input type="text" class="form-control" name="sms_hotline" value="<?= htmlspecialchars($sysSettings['sms_hotline'] ?? '01817838383') ?>" placeholder="e.g. 01817838383">
                    </div>
                    <small class="text-muted" style="font-size: 0.72rem;">Included in SMS messages for guest support.</small>
                  </div>
                </div>

                <!-- Gateway API URL -->
                <div class="mb-3">
                  <label class="form-label text-dark fw-bold small mb-1">API ENDPOINT URL</label>
                  <div class="input-group">
                    <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-globe"></i></span>
                    <input type="text" class="form-control font-monospace" id="intSmsApiUrl" name="sms_api_url" value="<?= htmlspecialchars($sysSettings['sms_api_url'] ?? 'https://api.greenweb.com.bd/api.php') ?>" placeholder="https://api.greenweb.com.bd/api.php">
                  </div>
                  <small class="text-muted" style="font-size: 0.72rem;">Endpoints support {apikey}, {senderid}, {phone}, {message} tokens for custom providers.</small>
                </div>

                <!-- Automated Trigger Checkboxes -->
                <div class="p-3 rounded-3 mb-4 border" style="background: #f8fafc;">
                  <span class="form-label text-dark fw-bold small mb-2 d-block">AUTOMATED SMS DISPATCH TRIGGERS</span>
                  
                  <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="intSmsOnConfirm" name="sms_on_booking_confirm" value="1" <?= ($sysSettings['sms_on_booking_confirm'] ?? '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label small text-dark" for="intSmsOnConfirm">
                      <strong>Booking Confirmation:</strong> Send instant confirmation SMS when admin accepts a reservation.
                    </label>
                  </div>

                  <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="intSmsOnMarkPaid" name="sms_on_mark_paid" value="1" <?= ($sysSettings['sms_on_mark_paid'] ?? '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label small text-dark" for="intSmsOnMarkPaid">
                      <strong>Payment Received (Mark Paid):</strong> Send Thank You SMS with updated due amount when payment is recorded.
                    </label>
                  </div>
                </div>

                <!-- Submit Button -->
                <div class="d-flex justify-content-end">
                  <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm d-flex align-items-center gap-2" id="btnSaveSmsSettings">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span>Save SMS Settings</span>
                  </button>
                </div>

              </form>

            </div>
          </div>

          <!-- Right: Live Test SMS Simulator Card -->
          <div class="col-lg-5">
            <div class="admin-card shadow-sm" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 18px; overflow: hidden;">
              
              <div class="p-3 border-bottom d-flex align-items-center justify-content-between bg-light bg-opacity-50">
                <div class="d-flex align-items-center gap-2">
                  <span class="rounded-circle bg-success bg-opacity-10 text-success d-inline-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                    <i class="fa-solid fa-paper-plane"></i>
                  </span>
                  <span class="fw-bold text-dark">Test SMS Simulator</span>
                </div>
                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2 py-1 small">
                  Instant Verification
                </span>
              </div>

              <form id="testSmsForm" onsubmit="handleDispatchTestSms(event)" class="p-4">
                <p class="text-muted small mb-3">Send a live test message to your mobile number to verify gateway credentials and connectivity.</p>

                <!-- Test Destination Phone -->
                <div class="mb-3">
                  <label class="form-label text-dark fw-bold small mb-1">TEST MOBILE NUMBER <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-mobile-screen"></i></span>
                    <input type="text" class="form-control" id="testSmsPhone" required placeholder="e.g. 01817838383">
                  </div>
                  <small class="text-muted" style="font-size: 0.72rem;">Enter 11-digit Bangladeshi mobile number.</small>
                </div>

                <!-- Test Message Content -->
                <div class="mb-3">
                  <label class="form-label text-dark fw-bold small mb-1">TEST MESSAGE</label>
                  <textarea class="form-control" id="testSmsMessage" rows="3" style="font-size: 0.85rem;" placeholder="Eco Divine Test: SMS Gateway connectivity verified! Sent from Eco Divine Beach Resort Admin Hub."></textarea>
                </div>

                <!-- Alert Result Output -->
                <div id="testSmsAlert" class="alert d-none mb-3 py-2 small"></div>

                <button type="submit" class="btn btn-outline-primary rounded-pill w-100 fw-bold shadow-sm d-flex align-items-center justify-content-center gap-2" id="btnDispatchTestSms">
                  <i class="fa-solid fa-paper-plane"></i>
                  <span>Dispatch Test SMS</span>
                </button>
              </form>

              <!-- Gateway Tip Card -->
              <div class="p-3 mx-4 mb-4 rounded-3 border bg-light small text-muted" style="font-size: 0.75rem;">
                <strong class="text-dark"><i class="fa-solid fa-lightbulb text-warning me-1"></i> Testing Tip:</strong>
                If your gateway API Key is not set yet, the system records a simulated delivery log entry without throwing an error.
              </div>

            </div>
          </div>

        </div>
      </div>

      <!-- ========================================================
           TAB 3: EMAIL & SMTP INTEGRATION (#pill-email)
           ======================================================== -->
      <div class="tab-pane fade" id="pill-email" role="tabpanel">
        <div class="row g-4 mb-4">
          
          <!-- Left: Email & SMTP Configuration Form -->
          <div class="col-lg-7">
            <div class="admin-card shadow-sm" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 18px; overflow: hidden;">
              
              <div class="p-3 border-bottom d-flex align-items-center justify-content-between bg-light bg-opacity-50">
                <div class="d-flex align-items-center gap-2">
                  <span class="rounded-circle bg-danger bg-opacity-10 text-danger d-inline-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                    <i class="fa-solid fa-envelope-circle-check"></i>
                  </span>
                  <span class="fw-bold text-dark">Email &amp; SMTP Configuration</span>
                </div>
                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-2 py-1 small">
                  HTML Templates Active
                </span>
              </div>

              <form id="emailSettingsForm" onsubmit="handleSaveEmailSettings(event)" class="p-4">
                <input type="hidden" name="action_update_email_settings" value="1">

                <div id="emailSettingsAlert" class="alert d-none mb-3 py-2 small"></div>

                <!-- Master Email Switch -->
                <div class="p-3 rounded-3 mb-3 border bg-light d-flex align-items-center justify-content-between">
                  <div>
                    <label class="form-check-label fw-bold text-dark small d-block" for="intEmailEnabled">
                      Enable Automated Email Dispatch
                    </label>
                    <small class="text-muted" style="font-size: 0.73rem;">Turn off to pause all outgoing guest confirmation and receipt emails.</small>
                  </div>
                  <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="intEmailEnabled" name="email_enabled" value="1" <?= ($sysSettings['email_enabled'] ?? '1') === '1' ? 'checked' : '' ?> style="width: 2.5em; height: 1.3em;">
                  </div>
                </div>

                <!-- Mail Engine Selector -->
                <div class="mb-3">
                  <label class="form-label text-dark fw-bold small mb-1">EMAIL DELIVERY ENGINE <span class="text-danger">*</span></label>
                  <select class="form-select" id="intEmailEngine" name="email_engine" onchange="toggleEmailEngineFields(this.value)">
                    <option value="smtp" <?= ($sysSettings['email_engine'] ?? 'smtp') === 'smtp' ? 'selected' : '' ?>>🛡️ SMTP Server (Gmail / cPanel / Workspace / Zoho - Recommended)</option>
                    <option value="mail" <?= ($sysSettings['email_engine'] ?? '') === 'mail' ? 'selected' : '' ?>>✉️ Native PHP mail() Function</option>
                  </select>
                  <small class="text-muted" style="font-size: 0.72rem;">SMTP provides authenticated delivery and bypasses spam filters.</small>
                </div>

                <!-- SMTP Server Fields (Hidden if Native PHP mail) -->
                <div id="smtpFieldsContainer">
                  <div class="row g-3 mb-3">
                    <div class="col-md-7">
                      <label class="form-label text-dark fw-bold small mb-1">SMTP HOST <span class="text-danger">*</span></label>
                      <div class="input-group">
                        <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-server"></i></span>
                        <input type="text" class="form-control" name="email_smtp_host" value="<?= htmlspecialchars($sysSettings['email_smtp_host'] ?? 'smtp.gmail.com') ?>" placeholder="smtp.gmail.com">
                      </div>
                    </div>
                    <div class="col-md-5">
                      <label class="form-label text-dark fw-bold small mb-1">SMTP PORT <span class="text-danger">*</span></label>
                      <div class="input-group">
                        <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-hashtag"></i></span>
                        <input type="number" class="form-control" name="email_smtp_port" value="<?= htmlspecialchars($sysSettings['email_smtp_port'] ?? '587') ?>" placeholder="587">
                      </div>
                    </div>
                  </div>

                  <div class="row g-3 mb-3">
                    <div class="col-md-6">
                      <label class="form-label text-dark fw-bold small mb-1">SECURITY / ENCRYPTION</label>
                      <select class="form-select" name="email_smtp_crypto">
                        <option value="tls" <?= ($sysSettings['email_smtp_crypto'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS - Port 587)</option>
                        <option value="ssl" <?= ($sysSettings['email_smtp_crypto'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (SMTPS - Port 465)</option>
                        <option value="none" <?= ($sysSettings['email_smtp_crypto'] ?? '') === 'none' ? 'selected' : '' ?>>None (Port 25)</option>
                      </select>
                    </div>

                    <div class="col-md-6">
                      <label class="form-label text-dark fw-bold small mb-1">SMTP USERNAME / EMAIL</label>
                      <div class="input-group">
                        <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-at"></i></span>
                        <input type="text" class="form-control" name="email_smtp_user" value="<?= htmlspecialchars($sysSettings['email_smtp_user'] ?? '') ?>" placeholder="e.g. info@ecodivine.com">
                      </div>
                    </div>
                  </div>

                  <!-- SMTP Password -->
                  <div class="mb-3">
                    <label class="form-label text-dark fw-bold small mb-1">SMTP PASSWORD / APP PASSWORD</label>
                    <div class="input-group">
                      <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-lock"></i></span>
                      <input type="password" class="form-control" id="intSmtpPass" name="email_smtp_pass" placeholder="<?= !empty($sysSettings['email_smtp_pass']) ? '•••••••••••• (Leave blank to keep unchanged)' : 'Enter SMTP password or App Password' ?>">
                      <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('intSmtpPass', 'intSmtpEye')">
                        <i class="fa-regular fa-eye" id="intSmtpEye"></i>
                      </button>
                    </div>
                    <small class="text-muted" style="font-size: 0.72rem;">For Gmail, generate an <strong>App Password</strong> in your Google Account security settings.</small>
                  </div>
                </div>

                <!-- Sender Name & Address -->
                <div class="row g-3 mb-3">
                  <div class="col-md-6">
                    <label class="form-label text-dark fw-bold small mb-1">FROM SENDER NAME <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="email_from_name" value="<?= htmlspecialchars($sysSettings['email_from_name'] ?? 'Eco Divine Beach Resort') ?>" placeholder="Eco Divine Beach Resort">
                  </div>

                  <div class="col-md-6">
                    <label class="form-label text-dark fw-bold small mb-1">FROM EMAIL ADDRESS <span class="text-danger">*</span></label>
                    <input type="email" class="form-control" name="email_from_address" value="<?= htmlspecialchars($sysSettings['email_from_address'] ?? 'reservations@ecodivine.com') ?>" placeholder="reservations@ecodivine.com">
                  </div>
                </div>

                <!-- Automated Email Triggers -->
                <div class="p-3 rounded-3 mb-4 border" style="background: #f8fafc;">
                  <span class="form-label text-dark fw-bold small mb-2 d-block">AUTOMATED EMAIL DISPATCH TRIGGERS</span>
                  
                  <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="intEmailOnConfirm" name="email_on_booking_confirm" value="1" <?= ($sysSettings['email_on_booking_confirm'] ?? '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label small text-dark" for="intEmailOnConfirm">
                      <strong>Booking Confirmation Email:</strong> Dispatch branded HTML confirmation with cottage details &amp; PDF invoice receipt link.
                    </label>
                  </div>

                  <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="intEmailOnMarkPaid" name="email_on_mark_paid" value="1" <?= ($sysSettings['email_on_mark_paid'] ?? '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label small text-dark" for="intEmailOnMarkPaid">
                      <strong>Payment Receipt Email:</strong> Dispatch branded HTML receipt when payment is recorded with ledger balance.
                    </label>
                  </div>
                </div>

                <!-- Submit Button -->
                <div class="d-flex justify-content-end">
                  <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm d-flex align-items-center gap-2" id="btnSaveEmailSettings">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span>Save Email Settings</span>
                  </button>
                </div>

              </form>

            </div>
          </div>

          <!-- Right: Live Test Email Simulator Card -->
          <div class="col-lg-5">
            <div class="admin-card shadow-sm" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 18px; overflow: hidden;">
              
              <div class="p-3 border-bottom d-flex align-items-center justify-content-between bg-light bg-opacity-50">
                <div class="d-flex align-items-center gap-2">
                  <span class="rounded-circle bg-danger bg-opacity-10 text-danger d-inline-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                    <i class="fa-solid fa-paper-plane"></i>
                  </span>
                  <span class="fw-bold text-dark">Test Email Simulator</span>
                </div>
                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-2 py-1 small">
                  Live SMTP Check
                </span>
              </div>

              <form id="testEmailForm" onsubmit="handleDispatchTestEmail(event)" class="p-4">
                <p class="text-muted small mb-3">Send a formatted test email to your inbox to verify server authentication and spam reputability.</p>

                <!-- Test Recipient Email -->
                <div class="mb-3">
                  <label class="form-label text-dark fw-bold small mb-1">RECIPIENT EMAIL ADDRESS <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-envelope"></i></span>
                    <input type="email" class="form-control" id="testEmailInput" required value="<?= htmlspecialchars($currentAdminUser['email'] ?? '') ?>" placeholder="your.email@example.com">
                  </div>
                  <small class="text-muted" style="font-size: 0.72rem;">We will send the test email to this address.</small>
                </div>

                <!-- Test Recipient Name -->
                <div class="mb-3">
                  <label class="form-label text-dark fw-bold small mb-1">RECIPIENT NAME</label>
                  <input type="text" class="form-control" id="testEmailName" value="<?= htmlspecialchars($currentAdminUser['name'] ?? 'Resort Administrator') ?>" placeholder="e.g. Nur Hossan">
                </div>

                <!-- Alert Result Output -->
                <div id="testEmailAlert" class="alert d-none mb-3 py-2 small"></div>

                <button type="submit" class="btn btn-outline-danger rounded-pill w-100 fw-bold shadow-sm d-flex align-items-center justify-content-center gap-2" id="btnDispatchTestEmail">
                  <i class="fa-solid fa-paper-plane"></i>
                  <span>Dispatch Test Email</span>
                </button>
              </form>

              <!-- SMTP Tip Card -->
              <div class="p-3 mx-4 mb-4 rounded-3 border bg-light small text-muted" style="font-size: 0.75rem;">
                <strong class="text-dark"><i class="fa-solid fa-shield-halved text-success me-1"></i> Security Note:</strong>
                Passwords are authenticated directly over encrypted sockets (STARTTLS/SSL). Eco Divine never exposes passwords in API responses.
              </div>

            </div>
          </div>

        </div>
      </div>

      <!-- ========================================================
           TAB 4: DELIVERY AUDIT LOGS (#pill-logs)
           ======================================================== -->
      <div class="tab-pane fade" id="pill-logs" role="tabpanel">
        
        <div class="admin-card shadow-sm p-4 mb-4" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 18px;">
          <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
              <h5 class="fw-bold text-dark mb-1 d-flex align-items-center gap-2">
                <i class="fa-solid fa-clock-rotate-left text-warning"></i> Communication Audit Trail
              </h5>
              <small class="text-muted">Real-time status tracking for automated guest SMS alerts and outbound system emails.</small>
            </div>
            <button type="button" class="btn btn-outline-secondary rounded-pill px-3 py-2 btn-sm" onclick="loadIntegrationLogs()">
              <i class="fa-solid fa-rotate me-1"></i> Refresh Logs
            </button>
          </div>

          <div class="row g-4">
            
            <!-- SMS Logs Table -->
            <div class="col-lg-6">
              <div class="border rounded-3 p-3 bg-light bg-opacity-25 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <span class="fw-bold text-dark small"><i class="fa-solid fa-comment-sms text-primary me-1"></i> Recent SMS Dispatches</span>
                  <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-2 py-0 small" id="smsLogCountBadge">Latest 15</span>
                </div>
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                  <table class="table table-hover table-sm align-middle mb-0" style="font-size: 0.78rem;">
                    <thead class="table-light">
                      <tr>
                        <th>Recipient</th>
                        <th>Status</th>
                        <th>Message</th>
                        <th>Time</th>
                      </tr>
                    </thead>
                    <tbody id="smsLogsTableBody">
                      <tr><td colspan="4" class="text-center text-muted py-3">Loading recent SMS dispatches...</td></tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <!-- Email Logs Table -->
            <div class="col-lg-6">
              <div class="border rounded-3 p-3 bg-light bg-opacity-25 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <span class="fw-bold text-dark small"><i class="fa-solid fa-envelope text-danger me-1"></i> Recent Email Dispatches</span>
                  <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-2 py-0 small" id="emailLogCountBadge">Latest 15</span>
                </div>
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                  <table class="table table-hover table-sm align-middle mb-0" style="font-size: 0.78rem;">
                    <thead class="table-light">
                      <tr>
                        <th>Recipient</th>
                        <th>Status</th>
                        <th>Subject</th>
                        <th>Time</th>
                      </tr>
                    </thead>
                    <tbody id="emailLogsTableBody">
                      <tr><td colspan="4" class="text-center text-muted py-3">Loading recent Email dispatches...</td></tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

          </div>

        </div>

      </div>

    </div>

  </div> <!-- /#view-settings -->

</div> <!-- /.dashboard-views-container -->


<!-- ========================================================
     MODAL: MAKE BOOKING FOR GUEST (#newBookingModal)
     ======================================================== -->
<style>
/* Cottage Selection Cards Styling */
.cottage-select-card {
  border: 1.5px solid #e2e8f0;
  border-radius: 12px;
  background: #ffffff;
  padding: 0.6rem 0.75rem;
  cursor: pointer;
  transition: all 0.15s ease-in-out;
  user-select: none;
}
.cottage-select-card:hover:not(.disabled) {
  border-color: #10b981;
  background: #f0fdf4;
}
.cottage-select-card.selected {
  border-color: #059669 !important;
  background: #ecfdf5 !important;
  box-shadow: 0 0 0 2px rgba(5, 150, 105, 0.2);
}
.cottage-select-card.disabled {
  background: #f8fafc !important;
  border-color: #e2e8f0 !important;
  opacity: 0.65;
  cursor: not-allowed !important;
}
</style>

<div class="modal fade" id="newBookingModal" tabindex="-1" aria-labelledby="newBookingModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable" style="max-height: 94vh;">
    <!-- Form IS the modal-content with flex-column so header and footer stay pinned -->
    <form class="modal-content border-0 shadow-lg d-flex flex-column" action="dashboard.php" method="POST" id="adminNewBookingForm" onsubmit="return validateBookingForm(event)" style="border-radius: 20px; overflow: hidden; max-height: 92vh;">
      <input type="hidden" name="action_create_booking" value="1">
      
      <!-- Modal Header (Pinned at Top) -->
      <div class="modal-header bg-white border-bottom py-2 py-md-3 px-3 px-md-4 flex-shrink-0">
        <div>
          <h5 class="modal-title fw-bold text-dark mb-0" id="newBookingModalLabel">
            <i class="fa-solid fa-calendar-plus text-success me-2"></i> Make Booking for Guest
          </h5>
          <small class="text-muted">Check live cottage availability on the left, enter guest details & payment on the right.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <!-- Modal Body (Scrolls smoothly between pinned Header and Footer) -->
      <div class="modal-body p-3 p-md-4 bg-light flex-grow-1" style="overflow-y: auto;">
        <div class="row g-3 g-lg-4">
          
          <!-- ================= LEFT COLUMN: COTTAGE AVAILABILITY & ALLOCATION ================= -->
          <div class="col-lg-6">
            <div class="bg-white p-3 p-md-4 rounded-4 shadow-sm border h-100 d-flex flex-column">
              
              <!-- Date Range Picker Header -->
              <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom flex-wrap gap-2">
                <div class="fw-bold text-dark small text-uppercase" style="letter-spacing: 0.04em;">
                  <i class="fa-solid fa-calendar-days text-success me-1"></i> Stay Duration & Dates
                </div>
                <span id="dateNightsNotice" class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 fw-semibold">
                  1 Night
                </span>
              </div>

              <div class="row g-2 mb-3">
                <div class="col-6">
                  <label class="form-label text-muted fw-bold small mb-1" style="font-size:0.75rem;">CHECK-IN DATE <span class="text-danger">*</span></label>
                  <input type="date" name="check_in" id="modalCheckIn" class="form-control form-control-sm fw-semibold" value="<?= date('Y-m-d') ?>" required onchange="onDatesChanged()">
                </div>
                <div class="col-6">
                  <label class="form-label text-muted fw-bold small mb-1" style="font-size:0.75rem;">CHECK-OUT DATE <span class="text-danger">*</span></label>
                  <input type="date" name="check_out" id="modalCheckOut" class="form-control form-control-sm fw-semibold" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" required onchange="onDatesChanged()">
                </div>
              </div>

              <!-- Available Cottages Header -->
              <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                  <span class="fw-bold text-dark small text-uppercase" style="letter-spacing: 0.04em;">
                    <i class="fa-solid fa-house-chimney text-success me-1"></i> Available Cottages
                  </span>
                  <small class="text-muted d-block" style="font-size:0.75rem;">Select 1 or more cottages for this guest</small>
                </div>
                <div class="d-flex align-items-center gap-2">
                  <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:0.75rem;" onclick="refreshCottageAvailability()" title="Refresh availability">
                    <i class="fa-solid fa-arrows-rotate me-1"></i> Re-check
                  </button>
                  <span id="availabilityLoader" class="badge bg-light text-muted border d-none" style="font-size:0.72rem;">
                    <i class="fa-solid fa-spinner fa-spin me-1"></i> Checking...
                  </span>
                </div>
              </div>

              <!-- Cottage Selection List / Grid -->
              <div class="cottage-selection-list flex-grow-1" id="cottageCardsGrid" style="max-height: 250px; overflow-y: auto; padding-right: 4px;">
                <div class="row g-2">
                  <?php foreach($allCottages as $c): 
                    $isWk = ((int)($c['is_weekend'] ?? 0) === 1);
                    $effPrice = ($isWk && !empty($c['weekend_price'])) ? floatval($c['weekend_price']) : floatval($c['price']);
                    $isInactive = ($c['status'] === 'inactive');
                  ?>
                    <div class="col-sm-6 col-12">
                      <div class="cottage-select-card h-100 <?= $isInactive ? 'disabled' : '' ?>" onclick="toggleCottageCard(<?= $c['id'] ?>, event)">
                        <div class="d-flex align-items-start gap-2">
                          <input class="form-check-input cottage-cb mt-1" type="checkbox" name="cottage_ids[]" value="<?= $c['id'] ?>" id="c_cb_<?= $c['id'] ?>" 
                            data-id="<?= $c['id'] ?>" data-price="<?= $effPrice ?>" data-title="<?= htmlspecialchars($c['title']) ?>" 
                            <?= $isInactive ? 'disabled' : '' ?> onchange="onCottageCheckboxChange(event)">
                          <div class="flex-grow-1 min-w-0">
                            <div class="d-flex justify-content-between align-items-center">
                              <span class="fw-bold text-dark text-truncate" style="font-size:0.88rem;"><?= htmlspecialchars($c['title']) ?></span>
                              <?php if($isInactive): ?>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary border" style="font-size:0.68rem;"><i class="fa-solid fa-wrench me-1"></i>Maint.</span>
                              <?php else: ?>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25" style="font-size:0.68rem;"><i class="fa-solid fa-check me-1"></i>Available</span>
                              <?php endif; ?>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-1">
                              <span class="text-success fw-bold" style="font-size:0.82rem;">৳ <?= number_format($effPrice) ?> <small class="text-muted fw-normal" style="font-size:0.7rem;">/n</small></span>
                              <?php if($isWk): ?>
                                <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25" style="font-size:0.65rem;">Weekend</span>
                              <?php endif; ?>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>

              <!-- Allocation Summary Bar -->
              <div class="p-2 px-3 mt-3 rounded-3 border d-flex justify-content-between align-items-center flex-wrap gap-2" style="background:#f8fafc;">
                <div class="small">
                  <span class="text-muted fw-semibold">Allocated:</span>
                  <span id="selectedCottagesCountBadge" class="badge bg-secondary ms-1">0 Cottages</span>
                  <div id="selectedCottagesNames" class="fw-bold text-dark text-truncate mt-1" style="max-width: 250px; font-size: 0.8rem;">None chosen</div>
                </div>
                <div class="text-end small">
                  <span class="text-muted d-block" style="font-size: 0.72rem;">COMBINED RATE</span>
                  <strong id="combinedNightlyRate" class="text-success fs-6">৳ 0 / night</strong>
                </div>
              </div>
              <div id="noCottageError" class="text-danger small mt-2 d-none">
                <i class="fa-solid fa-circle-exclamation me-1"></i> Please select at least one available cottage.
              </div>

            </div>
          </div>

          <!-- ================= RIGHT COLUMN: GUEST DETAILS & PAYMENT ================= -->
          <div class="col-lg-6">
            <div class="bg-white p-3 p-md-4 rounded-4 shadow-sm border h-100">
              
              <!-- Header: Guest Information -->
              <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom">
                <span class="fw-bold text-dark small text-uppercase" style="letter-spacing: 0.04em;">
                  <i class="fa-solid fa-user-pen text-success me-1"></i> Guest Information
                </span>
                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 small" style="font-size:0.72rem;">
                  Required
                </span>
              </div>

              <div class="row g-2 mb-3">
                <!-- Guest Name -->
                <div class="col-12">
                  <label class="form-label text-muted fw-bold small mb-1" style="font-size:0.75rem;">GUEST FULL NAME <span class="text-danger">*</span></label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-user"></i></span>
                    <input type="text" name="customer_name" class="form-control fw-semibold" placeholder="e.g. Rahim Uddin" required>
                  </div>
                </div>

                <!-- Guest Phone -->
                <div class="col-sm-6 col-12">
                  <label class="form-label text-muted fw-bold small mb-1" style="font-size:0.75rem;">MOBILE NUMBER <span class="text-danger">*</span></label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-phone"></i></span>
                    <input type="tel" name="customer_phone" class="form-control fw-semibold" placeholder="e.g. 01812345678" required>
                  </div>
                </div>

                <!-- Guest Email -->
                <div class="col-sm-6 col-12">
                  <label class="form-label text-muted fw-bold small mb-1" style="font-size:0.75rem;">EMAIL ADDRESS</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-envelope"></i></span>
                    <input type="email" name="customer_email" class="form-control" placeholder="guest@example.com">
                  </div>
                </div>

                <!-- Initial Status -->
                <div class="col-sm-6 col-12">
                  <label class="form-label text-muted fw-bold small mb-1" style="font-size:0.75rem;">BOOKING STATUS</label>
                  <select name="status" class="form-select form-select-sm fw-semibold">
                    <option value="accepted" selected>✅ Confirmed (Approved)</option>
                    <option value="pending">⏳ Pending (Review)</option>
                  </select>
                </div>

                <!-- Payment Method -->
                <div class="col-sm-6 col-12">
                  <label class="form-label text-muted fw-bold small mb-1" style="font-size:0.75rem;">PAYMENT METHOD</label>
                  <select name="payment_method" id="modalPaymentMethod" class="form-select form-select-sm">
                    <option value="Cash at Front Desk" selected>Cash at Front Desk</option>
                    <option value="bKash (Personal / Merchant)">bKash (Personal / Merchant)</option>
                    <option value="Nagad">Nagad</option>
                    <option value="Rocket">Rocket</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="Credit / Debit Card">Credit / Debit Card</option>
                    <option value="Other">Other</option>
                  </select>
                </div>
              </div>

              <!-- Header: Payment & Financials -->
              <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom">
                <span class="fw-bold text-dark small text-uppercase" style="letter-spacing: 0.04em;">
                  <i class="fa-solid fa-credit-card text-success me-1"></i> Billing & Payment Breakdown
                </span>
                <span class="badge bg-light text-muted border px-2 py-1 small" style="font-size:0.72rem;">
                  Auto-Calculated
                </span>
              </div>

              <div class="row g-2">
                <!-- Total Bill -->
                <div class="col-6">
                  <label class="form-label text-muted fw-bold small mb-1" style="font-size:0.75rem;">TOTAL BILL (৳) <span class="text-danger">*</span></label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light fw-bold text-success">৳</span>
                    <input type="number" step="0.01" name="total_amount" id="modalTotalAmount" class="form-control fw-bold text-success" required oninput="calculateBookingTotal()">
                  </div>
                  <small class="text-muted d-block mt-1" id="nightCalculationNotice" style="font-size:0.68rem;"></small>
                </div>

                <!-- Collected Amount -->
                <div class="col-6">
                  <label class="form-label text-muted fw-bold small mb-1" style="font-size:0.75rem;">COLLECTED AMOUNT (৳)</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light fw-bold">৳</span>
                    <input type="number" step="0.01" name="advance_amount" id="modalAdvanceAmount" class="form-control fw-semibold" value="2000.00" min="0" oninput="calculateBookingTotal()">
                  </div>
                </div>

                <!-- Special Discount -->
                <div class="col-6">
                  <label class="form-label text-muted fw-bold small mb-1" style="font-size:0.75rem;">SPECIAL DISCOUNT (৳)</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light fw-bold text-danger"><i class="fa-solid fa-tag"></i></span>
                    <input type="number" step="0.01" name="discount_amount" id="modalDiscountAmount" class="form-control" value="0.00" min="0" oninput="calculateBookingTotal()">
                  </div>
                </div>

                <!-- Remaining Due -->
                <div class="col-6">
                  <label class="form-label text-muted fw-bold small mb-1" style="font-size:0.75rem;">REMAINING DUE (৳)</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light fw-bold text-warning">৳</span>
                    <input type="text" name="due_amount" id="modalDueAmount" class="form-control fw-bold" style="background-color: #f8fafc; color: #d97706; font-size: 0.95rem;" readonly value="0.00">
                  </div>
                </div>

                <!-- Notes -->
                <div class="col-12 mt-2">
                  <label class="form-label text-muted fw-bold small mb-1" style="font-size:0.75rem;">INTERNAL NOTE / REMARKS</label>
                  <input type="text" name="notes" id="modalNotes" class="form-control form-control-sm" placeholder="e.g. TrxID, room preference, arrival note...">
                </div>
              </div>

            </div>
          </div>

        </div>
      </div>

      <!-- FIXED MODAL FOOTER (ALWAYS VISIBLE AT BOTTOM, NEVER CLIPPED) -->
      <div class="modal-footer bg-white border-top py-2 py-md-3 px-3 px-md-4 d-flex justify-content-between align-items-center flex-shrink-0">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span class="badge bg-secondary bg-opacity-10 text-secondary border px-2 py-1 fw-bold" id="ftrAllocSummary">
            <i class="fa-solid fa-house-chimney me-1"></i> 0 Cottages Selected
          </span>
          <span class="fw-bold text-dark small" id="ftrTotalAmountDisplay">Total: ৳0.00</span>
          <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 fw-semibold" id="ftrDueAmountDisplay">Due: ৳0.00</span>
        </div>
        <div class="d-flex align-items-center gap-2">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-3 px-md-4 btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success rounded-pill px-3 px-md-4 py-2 fw-bold shadow-sm btn-sm" id="btnSubmitNewBooking">
            <i class="fa-solid fa-check-circle me-1"></i> Confirm & Create Booking
          </button>
        </div>
      </div>

    </form>
  </div>
</div>


<!-- ========================================================
     MODAL: EDIT COTTAGE DETAILS & PRICES (#editCottageModal)
     ======================================================== -->
<div class="modal fade" id="editCottageModal" tabindex="-1" aria-labelledby="editCottageModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 18px; overflow: hidden;">
      
      <div class="modal-header bg-light border-0 py-3 px-4">
        <div>
          <h5 class="modal-title fw-bold text-dark mb-0" id="editCottageModalLabel">
            <i class="fa-solid fa-pen-to-square text-success me-2"></i> Edit Cottage Details & Rates
          </h5>
          <small class="text-muted">Update pricing, availability status, and weekend rate settings.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form action="update_cottage.php" method="POST" id="editCottageForm">
        <input type="hidden" name="action_update_cottage" value="1">
        <input type="hidden" name="cottage_id" id="editCottageId" value="">
        <input type="hidden" name="ref" value="dashboard">

        <div class="modal-body p-4">
          <div class="row g-3">
            
            <!-- Cottage Title -->
            <div class="col-12">
              <label class="form-label fw-bold text-dark small">Cottage Title / Name <span class="text-danger">*</span></label>
              <input type="text" name="title" id="editCottageTitle" class="form-control fw-semibold" required>
            </div>

            <!-- Regular Weekday Price -->
            <div class="col-md-6">
              <label class="form-label fw-bold text-dark small">Regular Rate (৳) <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text bg-light fw-bold text-success">৳</span>
                <input type="number" name="price" id="editCottagePrice" class="form-control fw-bold text-success" required>
              </div>
              <small class="text-muted">Standard weekday price</small>
            </div>

            <!-- Weekend Price -->
            <div class="col-md-6">
              <label class="form-label fw-bold text-dark small">Weekend Rate (৳) <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text bg-light fw-bold text-warning">৳</span>
                <input type="number" name="weekend_price" id="editCottageWeekendPrice" class="form-control fw-bold text-warning" required>
              </div>
              <small class="text-muted">Rate applied on weekends</small>
            </div>

            <!-- Availability Status -->
            <div class="col-md-12">
              <label class="form-label fw-bold text-dark small">Room Availability Status <span class="text-danger">*</span></label>
              <select name="status" id="editCottageStatus" class="form-select">
                <option value="active">Available (Active & Bookable on website)</option>
                <option value="inactive">Unavailable / Maintenance (Disabled from website)</option>
              </select>
            </div>

            <!-- Weekend Rate Active Mode Toggle -->
            <div class="col-12 mt-3">
              <div class="p-3 rounded-3" style="background: #fffbeb; border: 1px solid #fde68a;">
                <div class="form-check form-switch mb-1">
                  <input class="form-check-input" type="checkbox" name="is_weekend" id="editCottageIsWeekend" value="1" role="switch">
                  <label class="form-check-label fw-bold text-dark" for="editCottageIsWeekend">
                    <i class="fa-solid fa-fire text-warning me-1"></i> Set Weekend Rate Active
                  </label>
                </div>
                <small class="text-muted d-block">
                  If switched ON, the active displayed price across the website and bookings will show the <strong>Weekend Price</strong> instead of the Regular Price.
                </small>
              </div>
            </div>

          </div>
        </div>

        <div class="modal-footer bg-light border-0 py-3 px-4 d-flex justify-content-between">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">
            <i class="fa-solid fa-check me-1"></i> Save Changes
          </button>
        </div>

      </form>
    </div>
  </div>
</div>


<!-- ========================================================
     MODAL: CONFIRM BOOKING & RECORD PAYMENT (#confirmBookingModal)
     ======================================================== -->
<style>
/* Confirm Booking Modal Styling */
#confirmBookingModal .modal-content {
  border-radius: 18px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.15);
  background: #ffffff;
}
#confirmBookingModal .modal-header {
  border-bottom: 1px solid #f1f5f9;
  padding: 1.25rem 1.5rem 1rem;
}
#confirmBookingModal .modal-body {
  padding: 1.5rem;
}
#confirmBookingModal .form-label-custom {
  font-size: 0.78rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  color: #475569;
  text-transform: uppercase;
  margin-bottom: 0.35rem;
  display: block;
}
#confirmBookingModal .form-control-custom,
#confirmBookingModal .form-select-custom {
  border-radius: 10px;
  border: 1.5px solid #e2e8f0;
  padding: 0.65rem 0.9rem;
  font-size: 0.95rem;
  color: #1e293b;
  transition: all 0.15s ease;
}
#confirmBookingModal .form-control-custom:focus,
#confirmBookingModal .form-select-custom:focus {
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
  outline: none;
}
#confirmBookingModal .total-input {
  background-color: #f1f5f9;
  font-weight: 600;
}
#confirmBookingModal .due-input {
  background-color: #f8fafc;
  color: #d97706;
  font-weight: 700;
  font-size: 1.05rem;
}
#confirmBookingModal .btn-cancel {
  border: 1.5px solid #cbd5e1;
  background: #ffffff;
  color: #334155;
  font-weight: 600;
  border-radius: 10px;
  padding: 0.65rem 1.75rem;
}
#confirmBookingModal .btn-cancel:hover {
  background: #f8fafc;
  border-color: #94a3b8;
}
#confirmBookingModal .btn-confirm-sms {
  background-color: #059669;
  border: 1.5px solid #059669;
  color: #ffffff;
  font-weight: 600;
  border-radius: 10px;
  padding: 0.65rem 1.75rem;
  box-shadow: 0 4px 12px rgba(5, 150, 105, 0.2);
  transition: all 0.15s ease;
}
#confirmBookingModal .btn-confirm-sms:hover {
  background-color: #047857;
  border-color: #047857;
  box-shadow: 0 6px 16px rgba(5, 150, 105, 0.3);
}
</style>

<div class="modal fade" id="confirmBookingModal" tabindex="-1" aria-labelledby="confirmBookingModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 18px; overflow: hidden;">
      
      <div class="modal-header bg-white border-bottom py-3 px-4">
        <div>
          <h5 class="modal-title fw-bold text-dark mb-0" id="confirmBookingModalLabel">
            <i class="fa-solid fa-calendar-check text-success me-2"></i> Confirm Guest Booking
          </h5>
          <small class="text-muted">Verify reservation and record payment breakdown.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form action="dashboard.php" method="POST" id="confirmBookingForm">
        <input type="hidden" name="action_confirm_booking" value="1">
        <input type="hidden" name="booking_id" id="cfmBookingId" value="">

        <div class="modal-body p-4">

          <!-- TOP MINI-CARD: Booking & Guest Info -->
          <div class="guest-info-mini-card mb-4" style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 1rem 1.15rem;">
            <div class="d-flex justify-content-between align-items-center pb-2 mb-2 border-bottom">
              <div class="d-flex align-items-center gap-2">
                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 fw-bold rounded-pill">
                  <i class="fa-solid fa-hashtag"></i> <span id="cfmMiniBookingId">0</span>
                </span>
                <span class="fw-bold text-dark fs-6" id="cfmMiniCottageTitle">Resort Cottage</span>
              </div>
              <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 rounded-pill px-2 py-1 fw-semibold" id="cfmMiniStatusBadge">
                Pending
              </span>
            </div>
            
            <div class="row g-2">
              <div class="col-6">
                <div class="text-muted" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.03em;"><i class="fa-solid fa-user me-1 text-primary"></i> Guest Name</div>
                <div class="fw-bold text-dark text-truncate" id="cfmMiniGuestName" style="font-size: 0.92rem;">-</div>
              </div>
              <div class="col-6">
                <div class="text-muted" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.03em;"><i class="fa-solid fa-phone me-1 text-success"></i> Phone</div>
                <div class="fw-bold text-dark text-truncate" id="cfmMiniGuestPhone" style="font-size: 0.92rem;">-</div>
              </div>
              <div class="col-12 mt-1">
                <div class="text-muted" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.03em;"><i class="fa-solid fa-calendar-days me-1 text-secondary"></i> Stay Duration</div>
                <div class="fw-semibold text-dark" style="font-size: 0.88rem;">
                  <span id="cfmMiniDates">-</span> &bull; <span class="badge bg-white text-secondary border" id="cfmMiniNights">-</span>
                </div>
              </div>
            </div>
          </div>

          <!-- FINANCIAL INPUT FIELDS MATCHING REFERENCE IMAGE -->
          <div class="row g-3">
            
            <!-- Row 1: Total Bill & Advance Received -->
            <div class="col-6">
              <label class="form-label-custom">TOTAL BILL (BDT)</label>
              <input type="number" step="0.01" name="total_bill" id="cfmTotalBill" class="form-control form-control-custom total-input" required oninput="calcRemainingDue()">
            </div>

            <div class="col-6">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label-custom mb-0">COLLECTED AMOUNT (BDT)</label>
                <div class="btn-group btn-group-sm" role="group">
                  <button type="button" class="btn btn-outline-success py-0 px-2" style="font-size:0.68rem;" onclick="setConfirmCollectedQuick(2000)">৳ 2,000</button>
                  <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size:0.68rem;" onclick="setConfirmCollectedQuick('full')">Full</button>
                </div>
              </div>
              <input type="number" step="0.01" name="advance_amount" id="cfmAdvanceAmount" class="form-control form-control-custom" value="2000.00" min="0" oninput="calcRemainingDue()">
            </div>

            <!-- Row 2: Special Discount & Remaining Due -->
            <div class="col-6">
              <label class="form-label-custom">SPECIAL DISCOUNT (BDT)</label>
              <input type="number" step="0.01" name="discount_amount" id="cfmDiscountAmount" class="form-control form-control-custom" value="0" min="0" oninput="calcRemainingDue()">
            </div>

            <div class="col-6">
              <label class="form-label-custom">REMAINING DUE (BDT)</label>
              <input type="text" name="due_amount" id="cfmRemainingDue" class="form-control form-control-custom due-input" readonly value="0.00">
            </div>

            <!-- Row 3: Payment Method -->
            <div class="col-12">
              <label class="form-label-custom">PAYMENT METHOD</label>
              <select name="payment_method" id="cfmPaymentMethod" class="form-select form-select-custom">
                <option value="bKash (Personal / Merchant)">bKash (Personal / Merchant)</option>
                <option value="Nagad">Nagad</option>
                <option value="Rocket">Rocket</option>
                <option value="Bank Transfer">Bank Transfer</option>
                <option value="Cash at Front Desk">Cash at Front Desk</option>
                <option value="Credit / Debit Card">Credit / Debit Card</option>
                <option value="Other">Other</option>
              </select>
            </div>

            <!-- Row 4: Internal Note / Remarks -->
            <div class="col-12">
              <label class="form-label-custom">INTERNAL NOTE / REMARKS</label>
              <input type="text" name="notes" id="cfmNotes" class="form-control form-control-custom" placeholder="e.g. TrxID, room preference, arrival note...">
            </div>

          </div>
        </div>

        <!-- MODAL FOOTER BUTTONS MATCHING IMAGE -->
        <div class="modal-footer bg-white border-top py-3 px-4 d-flex justify-content-end gap-2">
          <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-confirm-sms">
            <i class="fa-solid fa-check me-1"></i> Confirm &amp; Send SMS
          </button>
        </div>

      </form>
    </div>
  </div>
</div>


<!-- ========================================================
     MODAL: CANCEL BOOKING & PROCESS REFUND (#cancelRefundModal)
     ======================================================== -->
<div class="modal fade" id="cancelRefundModal" tabindex="-1" aria-labelledby="cancelRefundModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">
      
      <div class="modal-header bg-danger bg-opacity-10 border-bottom border-danger border-opacity-25 py-3 px-4">
        <div>
          <h5 class="modal-title fw-bold text-danger mb-0" id="cancelRefundModalLabel">
            <i class="fa-solid fa-ban text-danger me-2"></i> Cancel Booking &amp; Process Refund
          </h5>
          <small class="text-muted">Cancel reservation, release cottage availability immediately, and settle financial refunds.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form action="cancel_booking.php" method="POST" id="cancelRefundForm" onsubmit="handleCancelRefundSubmit(event)">
        <input type="hidden" name="action_cancel_refund" value="1">
        <input type="hidden" name="booking_id" id="cancelBookingId" value="">

        <div class="modal-body p-4">

          <!-- TOP GUEST & BOOKING SUMMARY CARD -->
          <div class="p-3 rounded-4 mb-4" style="background: #f8fafc; border: 1.5px solid #e2e8f0;">
            <div class="d-flex justify-content-between align-items-center pb-2 mb-2 border-bottom flex-wrap gap-2">
              <div class="d-flex align-items-center gap-2">
                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2 py-1 fw-bold rounded-pill">
                  <i class="fa-solid fa-hashtag"></i> <span id="cancelMiniRef">ECO0000</span>
                </span>
                <span class="fw-bold text-dark fs-6" id="cancelMiniCottage">Resort Cottage</span>
              </div>
              <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 rounded-pill px-2 py-1 fw-semibold" id="cancelMiniStatus">
                Pending
              </span>
            </div>

            <div class="row g-2">
              <div class="col-md-4 col-sm-6">
                <div class="text-muted" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.03em;">
                  <i class="fa-solid fa-user me-1 text-primary"></i> Guest Name
                </div>
                <div class="fw-bold text-dark text-truncate" id="cancelMiniGuestName" style="font-size: 0.92rem;">-</div>
              </div>
              <div class="col-md-4 col-sm-6">
                <div class="text-muted" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.03em;">
                  <i class="fa-solid fa-phone me-1 text-success"></i> Phone Number
                </div>
                <div class="fw-bold text-dark text-truncate" id="cancelMiniGuestPhone" style="font-size: 0.92rem;">-</div>
              </div>
              <div class="col-md-4 col-sm-12">
                <div class="text-muted" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.03em;">
                  <i class="fa-solid fa-calendar-days me-1 text-secondary"></i> Dates &amp; Duration
                </div>
                <div class="fw-semibold text-dark" style="font-size: 0.88rem;">
                  <span id="cancelMiniDates">-</span> &bull; <span class="badge bg-white text-secondary border" id="cancelMiniNights">1 Night</span>
                </div>
              </div>
            </div>

            <!-- Financial Baseline Stats -->
            <div class="row g-2 mt-2 pt-2 border-top text-center">
              <div class="col-4">
                <div class="text-muted small fw-bold" style="font-size: 0.7rem;">ORIGINAL BILL</div>
                <div class="fw-bold text-dark fs-6">৳ <span id="cancelMiniOrigTotal">0.00</span></div>
              </div>
              <div class="col-4">
                <div class="text-muted small fw-bold text-success" style="font-size: 0.7rem;">COLLECTED (ADVANCE)</div>
                <div class="fw-bold text-success fs-6">৳ <span id="cancelMiniOrigAdvance">0.00</span></div>
              </div>
              <div class="col-4">
                <div class="text-muted small fw-bold text-warning" style="font-size: 0.7rem;">OUTSTANDING DUE</div>
                <div class="fw-bold text-warning fs-6">৳ <span id="cancelMiniOrigDue">0.00</span></div>
              </div>
            </div>
          </div>

          <!-- REFUND & DEDUCTION CONTROLS -->
          <div class="card border border-info border-opacity-25 bg-info bg-opacity-10 rounded-4 p-3 mb-4">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <div class="fw-bold text-dark">
                <i class="fa-solid fa-calculator text-info me-2"></i> Refund &amp; Cancellation Fee Calculator
              </div>
              <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-primary py-0 px-2" style="font-size: 0.75rem;" onclick="setQuickRefund('full')">Full Refund (100%)</button>
                <button type="button" class="btn btn-outline-warning py-0 px-2" style="font-size: 0.75rem;" onclick="setQuickRefund('half')">50% Deduction</button>
                <button type="button" class="btn btn-outline-danger py-0 px-2" style="font-size: 0.75rem;" onclick="setQuickRefund('zero')">No Refund (0%)</button>
              </div>
            </div>
            <p class="text-muted small mb-3" style="font-size: 0.8rem;">
              Adjust the refund amount below. The cancellation fee retained by the resort updates automatically. Due will be wiped to ৳0, and room availability will be unlocked immediately.
            </p>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label-custom text-success"><i class="fa-solid fa-arrow-rotate-left me-1"></i> REFUND AMOUNT TO GUEST (BDT)</label>
                <input type="number" step="0.01" min="0" name="refund_amount" id="cancelRefundAmountInput" class="form-control form-control-custom fw-bold text-success fs-6" value="0.00" oninput="calcCancelRefund('refund')" required>
                <div class="small text-muted mt-1" style="font-size: 0.72rem;">Maximum refundable: ৳ <span id="cancelMaxRefundableNotice">0.00</span> (collected advance)</div>
              </div>

              <div class="col-md-6">
                <label class="form-label-custom text-secondary"><i class="fa-solid fa-receipt me-1"></i> CANCELLATION FEE RETAINED (BDT)</label>
                <input type="number" step="0.01" min="0" name="cancellation_fee" id="cancelRetainedFeeInput" class="form-control form-control-custom fw-bold text-secondary fs-6" value="0.00" oninput="calcCancelRefund('fee')">
                <div class="small text-muted mt-1" style="font-size: 0.72rem;">Resort retains this fee from collected advance</div>
              </div>
            </div>

            <!-- Financial Outcome Preview Box -->
            <div class="bg-white rounded-3 p-3 mt-3 border">
              <div class="row g-2 text-center align-items-center">
                <div class="col-md-3 col-6">
                  <small class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.68rem;">New Due</small>
                  <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 fw-bold fs-6">
                    ৳ 0.00 (Cleared)
                  </span>
                </div>
                <div class="col-md-3 col-6">
                  <small class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.68rem;">New Collected</small>
                  <span class="fw-bold text-dark fs-6" id="cancelPreviewCollected">৳ 0.00</span>
                </div>
                <div class="col-md-3 col-6">
                  <small class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.68rem;">New Total Bill</small>
                  <span class="fw-bold text-dark fs-6" id="cancelPreviewTotal">৳ 0.00</span>
                </div>
                <div class="col-md-3 col-6">
                  <small class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.68rem;">Room Status</small>
                  <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1 fw-semibold" style="font-size: 0.75rem;">
                    <i class="fa-solid fa-lock-open me-1"></i>Available
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- PAYMENT METHOD & DETAILS -->
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label-custom">REFUND PAYMENT METHOD</label>
              <select name="refund_method" id="cancelRefundMethod" class="form-select form-select-custom">
                <option value="bKash (Personal / Merchant)">bKash (Personal / Merchant)</option>
                <option value="Nagad">Nagad</option>
                <option value="Rocket">Rocket</option>
                <option value="Bank Transfer">Bank Transfer</option>
                <option value="Cash at Front Desk">Cash at Front Desk</option>
                <option value="Credit / Debit Card">Credit / Debit Card</option>
                <option value="No Refund / Fee Retained">No Refund / Fee Retained</option>
                <option value="Other">Other</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label-custom">REFUND TRANSACTION ID / REFERENCE</label>
              <input type="text" name="refund_trx_id" id="cancelRefundTrxId" class="form-control form-control-custom" placeholder="e.g. bKash TrxID, Bank Ref, Voucher...">
            </div>

            <div class="col-12">
              <label class="form-label-custom">CANCELLATION REASON / AUDIT NOTE</label>
              <input type="text" name="cancellation_reason" id="cancelReasonInput" class="form-control form-control-custom" placeholder="e.g. Guest change of plans, weather alert, emergency...">
            </div>

            <div class="col-12">
              <div class="form-check form-switch p-2 bg-light rounded-3 border d-flex align-items-center justify-content-between">
                <div>
                  <label class="form-check-label fw-semibold text-dark mb-0 ps-2" for="cancelSendNotification">
                    <i class="fa-solid fa-paper-plane text-success me-1"></i> Send Cancellation &amp; Refund Notice to Guest
                  </label>
                  <small class="text-muted d-block ps-2" style="font-size: 0.75rem;">Dispatches automated SMS / Email to the guest with refund details and updated invoice.</small>
                </div>
                <input class="form-check-input ms-0 me-2" type="checkbox" role="switch" name="send_notification" id="cancelSendNotification" value="1" checked style="width: 2.2rem; height: 1.2rem; cursor: pointer;">
              </div>
            </div>
          </div>

          <div id="cancelModalAlert" class="alert alert-danger d-none mt-3 py-2 small mb-0"></div>

        </div>

        <div class="modal-footer bg-light border-top py-3 px-4 d-flex justify-content-between">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-3 fw-semibold" data-bs-dismiss="modal">
            Don't Cancel (Keep Active)
          </button>
          <button type="submit" class="btn btn-danger rounded-pill px-4 fw-semibold shadow-sm" id="cancelModalSubmitBtn">
            <i class="fa-solid fa-ban me-1"></i> Confirm Cancellation &amp; Refund
          </button>
        </div>

      </form>
    </div>
  </div>
</div>


<!-- ========================================================
     MODAL: REFUND HISTORY AUDIT LOG (#refundHistoryModal)
     ======================================================== -->
<div class="modal fade" id="refundHistoryModal" tabindex="-1" aria-labelledby="refundHistoryModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">
      
      <div class="modal-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
        <div>
          <h5 class="modal-title fw-bold text-dark mb-0" id="refundHistoryModalLabel">
            <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i> Booking Refund History &amp; Audit Log
          </h5>
          <small class="text-muted">Complete audit trail of all cancelled bookings, refund payouts, and retained fees.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="px-4 py-3 bg-light border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1 fw-bold" id="refundHistoryTotalBadge">
            0 Records
          </span>
          <span class="small text-muted" id="refundHistoryFilterNotice">Showing all recorded refunds</span>
        </div>
        <div style="min-width: 250px;">
          <input type="text" id="refundHistorySearchInput" class="form-control form-control-sm rounded-pill" placeholder="🔍 Search guest, phone, TrxID..." onkeyup="filterRefundHistoryTable()">
        </div>
      </div>

      <div class="modal-body p-0">
        <div id="refundHistoryLoading" class="text-center py-5">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading refund records...</span>
          </div>
          <div class="text-muted small mt-2">Loading refund audit log...</div>
        </div>

        <div class="table-responsive" id="refundHistoryTableWrapper" style="display: none; max-height: 480px; overflow-y: auto;">
          <table class="table table-hover align-middle mb-0" id="refundHistoryTable">
            <thead class="table-light text-muted small text-uppercase" style="font-size: 0.75rem; position: sticky; top: 0; z-index: 2;">
              <tr>
                <th>Date &amp; Time</th>
                <th>Booking Ref</th>
                <th>Guest Details</th>
                <th>Cottage &amp; Stay</th>
                <th>Original Paid</th>
                <th>Refunded Amount</th>
                <th>Retained Fee</th>
                <th>Method &amp; TrxID</th>
                <th>Processed By</th>
              </tr>
            </thead>
            <tbody id="refundHistoryTbody" class="small">
              <!-- Dynamically rendered -->
            </tbody>
          </table>
        </div>

        <div id="refundHistoryEmpty" class="text-center py-5 d-none text-muted">
          <i class="fa-solid fa-receipt fa-2x mb-2 text-secondary opacity-50"></i>
          <p class="mb-0 fw-semibold">No refund records found.</p>
          <small>Processed booking cancellations with refunds will appear here automatically.</small>
        </div>
      </div>

      <div class="modal-footer bg-light border-top py-2 px-4 d-flex justify-content-between">
        <small class="text-muted"><i class="fa-solid fa-shield-halved text-success me-1"></i> Fully synchronized with Financial Ledger &amp; Customer records.</small>
        <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" data-bs-dismiss="modal">Close</button>
      </div>

    </div>
  </div>
</div>


<!-- ========================================================
     MODAL: SEND SINGLE CUSTOMER SMS (#singleCustomerSmsModal)
     ======================================================== -->
<div class="modal fade" id="singleCustomerSmsModal" tabindex="-1" aria-labelledby="singleCustomerSmsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 18px; overflow: hidden;">
      <div class="modal-header bg-white border-bottom py-3 px-4">
        <div>
          <h5 class="modal-title fw-bold text-dark mb-0" id="singleCustomerSmsModalLabel">
            <i class="fa-solid fa-paper-plane text-success me-2"></i> Send Custom SMS to Guest
          </h5>
          <small class="text-muted">Dispatch custom SMS or choose from quick templates.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form id="singleSmsForm" onsubmit="handleSingleSmsSubmit(event)">
        <div class="modal-body p-4">
          <!-- Recipient Badge Box -->
          <div class="p-3 rounded-3 mb-3 border d-flex align-items-center justify-content-between" style="background: #f8fafc;">
            <div>
              <small class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.7rem;">Recipient Guest</small>
              <strong class="text-dark fs-6" id="singleSmsRecipientName">Guest</strong>
            </div>
            <div class="text-end">
              <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 fw-bold" id="singleSmsRecipientPhone">
                +8801...
              </span>
            </div>
          </div>
          <input type="hidden" id="singleSmsHiddenName" name="recipient_name">
          <input type="hidden" id="singleSmsHiddenPhone" name="recipient_phone">

          <!-- Template Preset Picker -->
          <div class="mb-3">
            <label class="form-label text-muted small fw-bold mb-1" style="font-size: 0.75rem;">QUICK TEMPLATES</label>
            <select class="form-select form-select-sm" id="singleSmsTemplateSelect" onchange="applySingleSmsTemplate()">
              <option value="custom" selected>✏️ Custom Message (Write Your Own)</option>
              <option value="welcome">🌴 Welcome & Confirmation</option>
              <option value="payment_reminder">💳 Payment Due Reminder</option>
              <option value="vip_offer">🎁 Returning Guest 15% VIP Offer</option>
              <option value="checkout_thankyou">🏖️ Post-Stay Thank You & Review</option>
            </select>
          </div>

          <!-- SMS Textarea -->
          <div class="mb-2">
            <label class="form-label text-muted small fw-bold mb-1" style="font-size: 0.75rem;">SMS MESSAGE CONTENT <span class="text-danger">*</span></label>
            <textarea class="form-control" id="singleSmsMessageText" name="message" rows="4" placeholder="Type your SMS message here..." required oninput="updateSmsStats('single')"></textarea>
          </div>

          <!-- Character Counter & Notice -->
          <div class="d-flex justify-content-between align-items-center small text-muted" style="font-size: 0.75rem;">
            <span>Placeholder <code class="text-success">{Name}</code> is replaced automatically.</span>
            <span class="fw-semibold text-dark" id="singleSmsCharCounter">0 / 160 chars (1 SMS)</span>
          </div>

          <div id="singleSmsAlert" class="alert alert-danger d-none mt-3 py-2 small mb-0"></div>
          <div id="singleSmsSuccessAlert" class="alert alert-success d-none mt-3 py-2 small mb-0"></div>
        </div>

        <div class="modal-footer bg-light border-top py-2 px-4 d-flex justify-content-between align-items-center">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4 btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm btn-sm" id="btnSingleSmsSend">
            <i class="fa-solid fa-paper-plane me-1"></i> Send SMS
          </button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- ========================================================
     MODAL: BROADCAST SMS TO ALL 25 CUSTOMERS (#broadcastSmsModal)
     ======================================================== -->
<div class="modal fade" id="broadcastSmsModal" tabindex="-1" aria-labelledby="broadcastSmsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 18px; overflow: hidden;">
      <div class="modal-header bg-white border-bottom py-3 px-4">
        <div>
          <h5 class="modal-title fw-bold text-dark mb-0" id="broadcastSmsModalLabel">
            <i class="fa-solid fa-bullhorn text-success me-2"></i> Broadcast SMS to All Customers
          </h5>
          <small class="text-muted">Simultaneously dispatch custom announcement or offer to top 25 recent guests.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form id="broadcastSmsForm" onsubmit="handleBroadcastSmsSubmit(event)">
        <div class="modal-body p-4">
          
          <!-- Recipients Header & Chips Preview -->
          <div class="p-3 rounded-3 mb-3 border" style="background: #f8fafc;">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="small fw-bold text-muted text-uppercase" style="font-size: 0.72rem;" id="broadcastRecipientsTitle">
                <i class="fa-solid fa-users text-success me-1"></i> Selected Recipients (<?= count($customerDirectoryList) ?> Guests on Page <?= $custPage ?>)
              </span>
              <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 small" id="broadcastRecipientsBadge">
                Page <?= $custPage ?> Directory (<?= count($customerDirectoryList) ?>)
              </span>
            </div>
            <div class="d-flex flex-wrap gap-1" style="max-height: 85px; overflow-y: auto;" id="broadcastRecipientsList">
              <?php foreach($customerDirectoryList as $idx => $recCust): ?>
                <span class="badge bg-white text-dark border px-2 py-1 small fw-normal" style="font-size: 0.72rem;">
                  <?= htmlspecialchars($recCust['customer_name'] ?: 'Guest') ?> (<?= htmlspecialchars($recCust['phone']) ?>)
                </span>
                <input type="hidden" name="recipients[<?= $idx ?>][name]" value="<?= htmlspecialchars($recCust['customer_name'] ?: 'Guest') ?>">
                <input type="hidden" name="recipients[<?= $idx ?>][phone]" value="<?= htmlspecialchars($recCust['phone']) ?>">
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Template Preset Picker -->
          <div class="mb-3">
            <label class="form-label text-muted small fw-bold mb-1" style="font-size: 0.75rem;">QUICK BROADCAST TEMPLATES</label>
            <select class="form-select form-select-sm" id="broadcastSmsTemplateSelect" onchange="applyBroadcastSmsTemplate()">
              <option value="custom" selected>✏️ Custom Announcement (Write Your Own)</option>
              <option value="winter_offer">❄️ Saint Martin Season Special: 20% Off Weekend Stays</option>
              <option value="ship_schedule">🚢 Saint Martin Ship & Travel Schedule Advisory</option>
              <option value="holiday_greeting">🎉 Eco Divine Holiday Greetings & Exclusive Resort Deal</option>
            </select>
          </div>

          <!-- SMS Textarea -->
          <div class="mb-2">
            <label class="form-label text-muted small fw-bold mb-1" style="font-size: 0.75rem;">BROADCAST MESSAGE CONTENT <span class="text-danger">*</span></label>
            <textarea class="form-control" id="broadcastSmsMessageText" name="message" rows="4" placeholder="Type your broadcast SMS here..." required oninput="updateSmsStats('broadcast')"></textarea>
          </div>

          <!-- Counter -->
          <div class="d-flex justify-content-between align-items-center small text-muted" style="font-size: 0.75rem;">
            <span>Placeholder <code class="text-success">{Name}</code> will be personalized per recipient.</span>
            <span class="fw-semibold text-dark" id="broadcastSmsCharCounter">0 / 160 chars (1 SMS per guest • <?= count($customerDirectoryList) ?> total)</span>
          </div>

          <div id="broadcastSmsAlert" class="alert alert-danger d-none mt-3 py-2 small mb-0"></div>
          <div id="broadcastSmsSuccessAlert" class="alert alert-success d-none mt-3 py-2 small mb-0"></div>
        </div>

        <div class="modal-footer bg-light border-top py-2 px-4 d-flex justify-content-between align-items-center">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4 btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm btn-sm" id="btnBroadcastSmsSend">
            <i class="fa-solid fa-bullhorn me-1"></i> Send Broadcast SMS (<?= count($customerDirectoryList) ?>)
          </button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- ========================================================
     MODAL: MARK PAID & SETTLE DUE (#markPaidModal)
     ======================================================== -->
<div class="modal fade" id="markPaidModal" tabindex="-1" aria-labelledby="markPaidModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 18px; overflow: hidden;">
      
      <div class="modal-header bg-white border-bottom py-3 px-4">
        <div>
          <h5 class="modal-title fw-bold text-dark mb-0" id="markPaidModalLabel">
            <i class="fa-solid fa-hand-holding-dollar text-success me-2"></i> Settle Payment & Clear Due
          </h5>
          <small class="text-muted">Record received payment, subtract remaining due balance, and optionally send Thank You SMS.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form id="markPaidForm" onsubmit="handleMarkPaidSubmit(event)">
        <input type="hidden" id="mpCustomerId" name="customer_id">
        <input type="hidden" id="mpBookingId" name="booking_id">
        <input type="hidden" id="mpBookingRef" name="booking_ref">

        <div class="modal-body p-4">
          
          <!-- Guest & Bill Overview Card -->
          <div class="p-3 rounded-3 mb-3 border" style="background: #f8fafc;">
            <div class="row g-2 align-items-center">
              <div class="col-md-6 border-end-md">
                <div class="d-flex align-items-center gap-2 mb-1">
                  <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-0 fw-bold" id="mpDisplayBookingRef">ECO#</span>
                  <span class="fw-bold text-dark fs-6" id="mpDisplayGuestName">Guest Name</span>
                </div>
                <div class="small text-muted d-flex align-items-center gap-2">
                  <span><i class="fa-solid fa-phone text-secondary me-1"></i><span id="mpDisplayGuestPhone">01...</span></span>
                  <span>&bull;</span>
                  <span><i class="fa-solid fa-house-chimney text-secondary me-1"></i><span id="mpDisplayCottage">Cottage</span></span>
                </div>
              </div>
              <div class="col-md-6 ps-md-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <span class="small text-muted">Total Billed:</span>
                  <span class="fw-bold text-dark">৳ <span id="mpDisplayTotalBill">0</span></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <span class="small text-muted">Paid So Far:</span>
                  <span class="fw-bold text-success">৳ <span id="mpDisplayAdvancePaid">0</span></span>
                </div>
                <div class="d-flex justify-content-between align-items-center pt-1 border-top">
                  <span class="small fw-bold text-danger text-uppercase">Current Outstanding Due:</span>
                  <span class="fw-bold text-danger fs-5">৳ <span id="mpDisplayDueAmount">0</span></span>
                </div>
              </div>
            </div>
          </div>

          <!-- Payment Input Fields -->
          <div class="row g-3 mb-3">
            
            <!-- Amount Paid Input -->
            <div class="col-md-6">
              <label class="form-label text-dark fw-bold small mb-1">AMOUNT BEING RECEIVED (৳) <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text bg-white fw-bold text-success">৳</span>
                <input type="number" step="0.01" min="0" class="form-control fw-bold fs-6 text-dark" id="mpAmountPaidInput" name="amount_paid" required oninput="onMarkPaidAmountChange()">
              </div>
              
              <!-- Quick Calculation Pills -->
              <div class="d-flex align-items-center gap-2 mt-2">
                <span class="text-muted small" style="font-size: 0.72rem;">Quick Pay:</span>
                <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-2 py-0 small" style="font-size: 0.72rem;" onclick="setMarkPaidQuickAmount('full')">
                  Full Due (100%)
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0 small" style="font-size: 0.72rem;" onclick="setMarkPaidQuickAmount('half')">
                  Half (50%)
                </button>
              </div>

              <!-- Real-time Due Subtract Notice -->
              <div class="small mt-2 p-2 rounded border" id="mpDueCalculationNotice" style="background: #ecfdf5; border-color: #a7f3d0 !important; font-size: 0.78rem;">
                <i class="fa-solid fa-calculator text-success me-1"></i>
                <span id="mpDueCalculationText">New Due after payment: <strong>৳ 0 (Fully Cleared)</strong></span>
              </div>
            </div>

            <!-- Payment Method & Transaction ID -->
            <div class="col-md-6">
              <div class="mb-2">
                <label class="form-label text-dark fw-bold small mb-1">PAYMENT METHOD <span class="text-danger">*</span></label>
                <select class="form-select form-select-sm" id="mpPaymentMethodSelect" name="payment_method">
                  <option value="Cash at Front Desk" selected>💵 Cash at Front Desk</option>
                  <option value="bKash (Merchant / Personal)">📱 bKash</option>
                  <option value="Nagad">📱 Nagad</option>
                  <option value="Rocket">📱 Rocket</option>
                  <option value="Card (POS Machine)">💳 Card / POS Machine</option>
                  <option value="Bank Transfer">🏦 Bank Transfer</option>
                </select>
              </div>

              <div>
                <label class="form-label text-muted small fw-semibold mb-1" style="font-size: 0.75rem;">TRANSACTION ID / RECEIPT # (OPTIONAL)</label>
                <input type="text" class="form-control form-control-sm" id="mpTrxIdInput" name="trx_id" placeholder="e.g. bKash TrxID, Bank slip...">
              </div>
            </div>

          </div>

          <!-- Staff Notes -->
          <div class="mb-3">
            <label class="form-label text-muted small fw-semibold mb-1" style="font-size: 0.75rem;">PAYMENT REMARK / INTERNAL NOTE (OPTIONAL)</label>
            <input type="text" class="form-control form-control-sm" id="mpNotesInput" name="notes" placeholder="e.g. Cleared at front desk checkout by manager">
          </div>

          <!-- Thank You SMS Section -->
          <div class="border rounded-3 p-3 bg-white" style="border-color: #e2e8f0;">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <div class="form-check form-switch mb-0">
                <input class="form-check-input text-success" type="checkbox" role="switch" id="mpSendSmsCheck" name="send_sms" value="1" checked onchange="toggleMarkPaidSmsContainer()">
                <label class="form-check-label fw-bold text-dark small" for="mpSendSmsCheck">
                  <i class="fa-solid fa-paper-plane text-success me-1"></i> Send Thank You SMS to Guest (Instant Delivery)
                </label>
              </div>
              <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2 py-0 small" style="font-size: 0.7rem;">Automated Dispatch</span>
            </div>

            <!-- SMS Config & Preview Container -->
            <div id="mpSmsContainer">
              <div class="mb-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <label class="form-label text-muted small fw-bold mb-0" style="font-size: 0.72rem;">MESSAGE TEMPLATE</label>
                  <select class="form-select form-select-sm py-0" style="width: auto; font-size: 0.75rem;" id="mpSmsTemplateSelect" onchange="applyMarkPaidSmsTemplate()">
                    <option value="full_settled" selected>✨ Full Clearance Thank You</option>
                    <option value="partial_received">💳 Partial Payment Receipt</option>
                    <option value="custom">✏️ Custom Message</option>
                  </select>
                </div>
                <textarea class="form-control" id="mpSmsMessageText" name="sms_message" rows="3" style="font-size: 0.85rem;" oninput="updateMarkPaidSmsStats()"></textarea>
              </div>

              <div class="d-flex justify-content-between align-items-center small text-muted" style="font-size: 0.72rem;">
                <span>Placeholders <code class="text-success">{Name}</code>, <code class="text-success">{Amount}</code>, <code class="text-success">{BookingID}</code> will auto-replace.</span>
                <span class="fw-semibold text-dark" id="mpSmsCharCounter">0 / 160 chars (1 SMS)</span>
              </div>
            </div>

          </div>

          <!-- Alert Notices -->
          <div id="mpModalAlert" class="alert alert-danger d-none mt-3 py-2 small mb-0"></div>
          <div id="mpModalSuccessAlert" class="alert alert-success d-none mt-3 py-2 small mb-0"></div>

        </div>

        <div class="modal-footer bg-light border-top py-2 px-4 d-flex justify-content-between align-items-center">
          <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-outline-secondary rounded-pill px-3 btn-sm" data-bs-dismiss="modal">Cancel</button>
            <a href="#" id="mpDownloadInvoiceLink" target="_blank" class="btn btn-outline-success rounded-pill px-3 fw-bold btn-sm d-none align-items-center gap-1" title="Print or Download Official Invoice Receipt">
              <i class="fa-solid fa-file-invoice-dollar"></i>
              <span>Download Invoice</span>
            </a>
          </div>
          <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm btn-sm d-flex align-items-center gap-2" id="btnSubmitMarkPaid">
            <i class="fa-solid fa-circle-check"></i>
            <span>Confirm & Mark Paid</span>
          </button>
        </div>

      </form>
    </div>
  </div>
</div>


<!-- ========================================================
     MODAL: ADD ANOTHER ADMIN (#addAdminModal)
     ======================================================== -->
<div class="modal fade" id="addAdminModal" tabindex="-1" aria-labelledby="addAdminModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 18px; overflow: hidden;">
      
      <div class="modal-header bg-white border-bottom py-3 px-4">
        <div>
          <h5 class="modal-title fw-bold text-dark mb-0" id="addAdminModalLabel">
            <i class="fa-solid fa-user-plus text-success me-2"></i> Add Another Admin
          </h5>
          <small class="text-muted">Register a new administrator or staff member with system access.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form action="dashboard.php" method="POST" id="addAdminForm">
        <input type="hidden" name="action_create_admin" value="1">

        <div class="modal-body p-4">
          
          <!-- Full Name -->
          <div class="mb-3">
            <label class="form-label text-dark fw-bold small mb-1">FULL NAME <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-user"></i></span>
              <input type="text" class="form-control" name="name" id="addAdminName" placeholder="e.g. Tanvir Ahmed" required>
            </div>
          </div>

          <!-- Username -->
          <div class="mb-3">
            <label class="form-label text-dark fw-bold small mb-1">USERNAME <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-white text-muted">@</span>
              <input type="text" class="form-control" name="username" id="addAdminUsername" placeholder="e.g. tanvir2025" required pattern="[A-Za-z0-9_.-]{3,}" title="Minimum 3 characters (letters, numbers, underscore, dash, period)">
            </div>
            <small class="text-muted" style="font-size: 0.72rem;">Used for signing in to the administrator portal.</small>
          </div>

          <!-- Email -->
          <div class="mb-3">
            <label class="form-label text-dark fw-bold small mb-1">EMAIL ADDRESS <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-envelope"></i></span>
              <input type="email" class="form-control" name="email" id="addAdminEmail" placeholder="e.g. tanvir@ecodivine.com" required>
            </div>
          </div>

          <!-- System Role -->
          <div class="mb-3">
            <label class="form-label text-dark fw-bold small mb-1">SYSTEM ROLE <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-shield-halved"></i></span>
              <select class="form-select" name="role" id="addAdminRole" required>
                <option value="admin" selected>Administrator (Full Control)</option>
                <option value="manager">Manager (Operations & Ledger)</option>
                <option value="staff">Staff (Front Desk Access)</option>
              </select>
            </div>
          </div>

          <!-- Password -->
          <div class="mb-2">
            <label class="form-label text-dark fw-bold small mb-1">LOGIN PASSWORD <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-lock"></i></span>
              <input type="password" class="form-control" name="password" id="addAdminPassword" placeholder="Minimum 6 characters" minlength="6" required>
              <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('addAdminPassword', 'addAdminEyeIcon')">
                <i class="fa-regular fa-eye" id="addAdminEyeIcon"></i>
              </button>
            </div>
            <small class="text-muted" style="font-size: 0.72rem;">Must be at least 6 characters long.</small>
          </div>

        </div>

        <div class="modal-footer bg-light border-top py-2 px-4 d-flex justify-content-between align-items-center">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4 btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm btn-sm d-flex align-items-center gap-2">
            <i class="fa-solid fa-user-plus"></i>
            <span>Create Admin Account</span>
          </button>
        </div>

      </form>
    </div>
  </div>
</div>

<!-- ========================================================
     MODAL: EDIT ADMIN (#editAdminModal)
     ======================================================== -->
<div class="modal fade" id="editAdminModal" tabindex="-1" aria-labelledby="editAdminModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 18px; overflow: hidden;">
      
      <div class="modal-header bg-white border-bottom py-3 px-4">
        <div>
          <h5 class="modal-title fw-bold text-dark mb-0" id="editAdminModalLabel">
            <i class="fa-solid fa-user-pen text-primary me-2"></i> Edit Administrator
          </h5>
          <small class="text-muted">Update profile information or reset login password.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form action="dashboard.php" method="POST" id="editAdminForm">
        <input type="hidden" name="action_edit_admin" value="1">
        <input type="hidden" name="admin_id" id="editAdminId" value="">

        <div class="modal-body p-4">
          
          <!-- Full Name -->
          <div class="mb-3">
            <label class="form-label text-dark fw-bold small mb-1">FULL NAME <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-user"></i></span>
              <input type="text" class="form-control" name="name" id="editAdminName" required>
            </div>
          </div>

          <!-- Username -->
          <div class="mb-3">
            <label class="form-label text-dark fw-bold small mb-1">USERNAME <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-white text-muted">@</span>
              <input type="text" class="form-control" name="username" id="editAdminUsername" required pattern="[A-Za-z0-9_.-]{3,}">
            </div>
          </div>

          <!-- Email -->
          <div class="mb-3">
            <label class="form-label text-dark fw-bold small mb-1">EMAIL ADDRESS <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-envelope"></i></span>
              <input type="email" class="form-control" name="email" id="editAdminEmail" required>
            </div>
          </div>

          <!-- System Role -->
          <div class="mb-3">
            <label class="form-label text-dark fw-bold small mb-1">SYSTEM ROLE <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-shield-halved"></i></span>
              <select class="form-select" name="role" id="editAdminRole" required>
                <option value="admin">Administrator (Full Control)</option>
                <option value="manager">Manager (Operations & Ledger)</option>
                <option value="staff">Staff (Front Desk Access)</option>
              </select>
            </div>
          </div>

          <!-- New Password (Optional) -->
          <div class="mb-2">
            <label class="form-label text-dark fw-bold small mb-1">NEW PASSWORD (OPTIONAL)</label>
            <div class="input-group">
              <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-lock"></i></span>
              <input type="password" class="form-control" name="password" id="editAdminPassword" placeholder="Leave empty to keep current password" minlength="6">
              <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('editAdminPassword', 'editAdminEyeIcon')">
                <i class="fa-regular fa-eye" id="editAdminEyeIcon"></i>
              </button>
            </div>
            <small class="text-muted" style="font-size: 0.72rem;">Only enter a password if you want to change it for this user.</small>
          </div>

        </div>

        <div class="modal-footer bg-light border-top py-2 px-4 d-flex justify-content-between align-items-center">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4 btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm btn-sm d-flex align-items-center gap-2">
            <i class="fa-solid fa-circle-check"></i>
            <span>Save Changes</span>
          </button>
        </div>

      </form>
    </div>
  </div>
</div>

<!-- ========================================================
     MODAL: DELETE ADMIN CONFIRMATION (#deleteAdminModal)
     ======================================================== -->
<div class="modal fade" id="deleteAdminModal" tabindex="-1" aria-labelledby="deleteAdminModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 18px; overflow: hidden;">
      
      <div class="modal-header bg-danger text-white py-3 px-4 border-0">
        <h6 class="modal-title fw-bold mb-0" id="deleteAdminModalLabel">
          <i class="fa-solid fa-triangle-exclamation me-2"></i> Delete Administrator
        </h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form action="dashboard.php" method="POST" id="deleteAdminForm">
        <input type="hidden" name="action_delete_admin" value="1">
        <input type="hidden" name="admin_id" id="deleteAdminId" value="">

        <div class="modal-body p-4 text-center">
          <div class="rounded-circle bg-danger bg-opacity-10 text-danger d-inline-flex align-items-center justify-content-center mb-3" style="width: 56px; height: 56px; font-size: 1.5rem;">
            <i class="fa-solid fa-user-xmark"></i>
          </div>
          <h6 class="fw-bold text-dark mb-1">Remove Staff Account?</h6>
          <p class="text-muted small mb-3">
            Are you sure you want to permanently delete <strong id="deleteAdminTargetName" class="text-dark">User</strong> (<code id="deleteAdminTargetUsername">@user</code>)?
          </p>
          <div class="alert alert-warning py-2 px-3 small text-start mb-0" style="font-size: 0.75rem;">
            <i class="fa-solid fa-shield-halved text-warning me-1"></i> This account will immediately lose portal access.
          </div>
        </div>

        <div class="modal-footer bg-light border-top py-2 px-3 d-flex justify-content-between align-items-center">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-3 btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger rounded-pill px-3 fw-bold shadow-sm btn-sm">
            <i class="fa-solid fa-trash-can me-1"></i> Delete
          </button>
        </div>

      </form>
    </div>
  </div>
</div>


<!-- ========================================================
     CLIENT-SIDE FILTER & CALCULATION SCRIPTS
     ======================================================== -->
<script>
// Auto-calculate remaining due in confirmation modal
function calcRemainingDue() {
  const total = parseFloat(document.getElementById('cfmTotalBill').value) || 0;
  const advance = parseFloat(document.getElementById('cfmAdvanceAmount').value) || 0;
  const discount = parseFloat(document.getElementById('cfmDiscountAmount').value) || 0;
  const due = Math.max(0, total - advance - discount);
  document.getElementById('cfmRemainingDue').value = due.toFixed(2);
}

// Quick set collected amount in confirmation modal (৳ 2,000, Full, or custom)
function setConfirmCollectedQuick(val) {
  const advInput = document.getElementById('cfmAdvanceAmount');
  const totalInput = document.getElementById('cfmTotalBill');
  if (!advInput) return;
  if (val === 'full') {
    const total = parseFloat(totalInput ? totalInput.value : 0) || 0;
    advInput.value = total.toFixed(2);
  } else {
    advInput.value = Number(val).toFixed(2);
  }
  calcRemainingDue();
}

// Open and populate Confirm Booking Modal
function openConfirmModal(b) {
  document.getElementById('cfmBookingId').value = b.id;
  document.getElementById('cfmMiniBookingId').innerText = b.id;
  const cTitle = b.cottage_title || 'Resort Cottage';
  const cCount = b.cottage_count ? parseInt(b.cottage_count) : 1;
  document.getElementById('cfmMiniCottageTitle').innerText = cTitle + (cCount > 1 ? ` (${cCount} Cottages)` : '');
  document.getElementById('cfmMiniGuestName').innerText = b.customer_name || 'Guest';
  document.getElementById('cfmMiniGuestPhone').innerText = b.customer_phone || '-';
  document.getElementById('cfmMiniDates').innerText = (b.check_in || '') + ' → ' + (b.check_out || '');
  
  const rateInfo = b.effective_rate ? ' @ ৳' + Number(b.effective_rate).toLocaleString() + '/night' : '';
  document.getElementById('cfmMiniNights').innerText = (b.nights || 1) + ' Night(s)' + rateInfo;
  
  const statusBadge = document.getElementById('cfmMiniStatusBadge');
  if (b.status === 'accepted') {
    statusBadge.className = 'badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2 py-1 fw-semibold';
    statusBadge.innerText = 'Confirmed';
  } else if (b.status === 'cancelled') {
    statusBadge.className = 'badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-2 py-1 fw-semibold';
    statusBadge.innerText = 'Cancelled';
  } else {
    statusBadge.className = 'badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 rounded-pill px-2 py-1 fw-semibold';
    statusBadge.innerText = 'Pending';
  }

  // Prepopulate financial inputs
  const totalBill = parseFloat(b.calculated_total) || 0;
  document.getElementById('cfmTotalBill').value = totalBill.toFixed(2);

  // Default collected advance amount to 2000.00 (editable)
  let advVal = 2000.00;
  if (b.status === 'accepted' && parseFloat(b.advance_amount) > 0) {
    advVal = parseFloat(b.advance_amount);
  } else if (b.status === 'pending') {
    // Pending reservations being confirmed strictly default to 2000 advance
    advVal = 2000.00;
  } else if (parseFloat(b.advance_amount) > 0 && parseFloat(b.advance_amount) < totalBill) {
    advVal = parseFloat(b.advance_amount);
  }
  document.getElementById('cfmAdvanceAmount').value = advVal.toFixed(2);
  document.getElementById('cfmDiscountAmount').value = parseFloat(b.discount_amount) || 0;
  
  if (b.payment_method) {
    document.getElementById('cfmPaymentMethod').value = b.payment_method;
  } else {
    document.getElementById('cfmPaymentMethod').value = 'bKash (Personal / Merchant)';
  }
  
  document.getElementById('cfmNotes').value = b.notes || '';

  // Trigger calculation
  calcRemainingDue();

  const modal = new bootstrap.Modal(document.getElementById('confirmBookingModal'));
  modal.show();
}
// Open and populate Edit Cottage Modal
function openEditCottageModal(c) {
  document.getElementById('editCottageId').value = c.id;
  document.getElementById('editCottageTitle').value = c.title || '';
  document.getElementById('editCottagePrice').value = c.price || '';
  document.getElementById('editCottageWeekendPrice').value = c.weekend_price || c.price || '';
  document.getElementById('editCottageStatus').value = c.status || 'active';
  document.getElementById('editCottageIsWeekend').checked = (parseInt(c.is_weekend) === 1);
  
  const modalElem = document.getElementById('editCottageModal');
  const modal = new bootstrap.Modal(modalElem);
  modal.show();
}

// Filter Cottage Cards in View 2
function filterCottagesByStatus(filter) {
  const cards = document.querySelectorAll('.cottage-card-col');
  cards.forEach(card => {
    const status = card.getAttribute('data-status');
    const isWk = card.getAttribute('data-weekend') === '1';

    if (filter === 'all') {
      card.style.display = '';
    } else if (filter === 'active' && status === 'active') {
      card.style.display = '';
    } else if (filter === 'inactive' && status === 'inactive') {
      card.style.display = '';
    } else if (filter === 'weekend' && isWk) {
      card.style.display = '';
    } else {
      card.style.display = 'none';
    }
  });
}

// Search Cottage Cards by Title
function searchCottageCards() {
  const input = document.getElementById('cottageSearchInput');
  const query = input ? input.value.toLowerCase().trim() : '';
  const cards = document.querySelectorAll('.cottage-card-col');

  cards.forEach(card => {
    const title = card.getAttribute('data-title') || '';
    if (title.includes(query)) {
      card.style.display = '';
    } else {
      card.style.display = 'none';
    }
  });
}

// ========================================================
// REAL-TIME COTTAGE AVAILABILITY & MULTI-COTTAGE BOOKING
// ========================================================

function onDatesChanged() {
  const cinInput = document.getElementById('modalCheckIn');
  const coutInput = document.getElementById('modalCheckOut');
  if (cinInput && coutInput && cinInput.value) {
    coutInput.min = cinInput.value;
    if (coutInput.value && coutInput.value <= cinInput.value) {
      const parts = cinInput.value.split('-');
      const nextDay = new Date(parts[0], parts[1] - 1, parts[2]);
      nextDay.setDate(nextDay.getDate() + 1);
      const y = nextDay.getFullYear();
      const m = String(nextDay.getMonth() + 1).padStart(2, '0');
      const d = String(nextDay.getDate()).padStart(2, '0');
      coutInput.value = `${y}-${m}-${d}`;
    }
  }
  refreshCottageAvailability();
}

function refreshCottageAvailability() {
  const cinInput = document.getElementById('modalCheckIn');
  const coutInput = document.getElementById('modalCheckOut');
  const loader = document.getElementById('availabilityLoader');
  const nightsNotice = document.getElementById('dateNightsNotice');

  if (!cinInput || !coutInput || !cinInput.value || !coutInput.value) return;

  const cin = cinInput.value;
  const cout = coutInput.value;

  // Nights calculation
  const d1 = new Date(cin);
  const d2 = new Date(cout);
  const nights = (d2 > d1) ? Math.max(1, Math.round(Math.abs(d2 - d1) / (1000 * 60 * 60 * 24))) : 1;
  if (nightsNotice) {
    nightsNotice.textContent = `${nights} Night${nights > 1 ? 's' : ''}`;
  }

  if (loader) loader.classList.remove('d-none');

  fetch('fetch_cottage_availability.php?check_in=' + encodeURIComponent(cin) + '&check_out=' + encodeURIComponent(cout))
    .then(r => r.json())
    .then(data => {
      if (loader) loader.classList.add('d-none');
      if (data && data.success && data.cottages) {
        renderCottageCards(data.cottages);
      }
    })
    .catch(err => {
      if (loader) loader.classList.add('d-none');
      console.error('Error fetching availability:', err);
    });
}

function renderCottageCards(cottages) {
  const grid = document.getElementById('cottageCardsGrid');
  if (!grid) return;

  // Preserve previously selected cottage IDs
  const previouslySelected = Array.from(document.querySelectorAll('.cottage-cb:checked')).map(cb => cb.value);

  grid.innerHTML = '<div class="row g-2"></div>';
  const row = grid.querySelector('.row');

  cottages.forEach(c => {
    const isAvail = c.is_available;
    const isChecked = isAvail && previouslySelected.includes(String(c.id));
    const wkNotice = c.is_weekend ? '<span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 ms-1" style="font-size:0.65rem;">Weekend</span>' : '';
    
    let statusBadgeHtml = '';
    if (isAvail) {
      statusBadgeHtml = '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25" style="font-size:0.68rem;"><i class="fa-solid fa-check me-1"></i>Available</span>';
    } else if (c.status === 'inactive') {
      statusBadgeHtml = '<span class="badge bg-secondary bg-opacity-10 text-secondary border" style="font-size:0.68rem;"><i class="fa-solid fa-wrench me-1"></i>Maint.</span>';
    } else {
      statusBadgeHtml = `<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25" style="font-size:0.68rem;" title="${c.booked_by || 'Booked'}"><i class="fa-solid fa-lock me-1"></i>Booked</span>`;
    }

    const col = document.createElement('div');
    col.className = 'col-sm-6 col-12';
    col.innerHTML = `
      <div class="cottage-select-card h-100 ${isChecked ? 'selected' : ''} ${!isAvail ? 'disabled' : ''}" onclick="toggleCottageCard(${c.id}, event)">
        <div class="d-flex align-items-start gap-2">
          <input class="form-check-input cottage-cb mt-1" type="checkbox" name="cottage_ids[]" value="${c.id}" id="c_cb_${c.id}" 
            data-id="${c.id}" data-price="${c.effective_price}" data-title="${c.title}" 
            ${isChecked ? 'checked' : ''} ${!isAvail ? 'disabled' : ''} onchange="onCottageCheckboxChange(event)">
          <div class="flex-grow-1 min-w-0">
            <div class="d-flex justify-content-between align-items-center">
              <span class="fw-bold text-dark text-truncate" style="font-size:0.88rem;">${c.title}</span>
              ${statusBadgeHtml}
            </div>
            <div class="d-flex justify-content-between align-items-center mt-1">
              <span class="text-success fw-bold" style="font-size:0.82rem;">৳ ${Number(c.effective_price).toLocaleString()} <small class="text-muted fw-normal" style="font-size:0.7rem;">/n</small></span>
              ${wkNotice}
            </div>
            ${!isAvail && c.booked_by ? `<div class="text-danger text-truncate mt-1" style="font-size:0.68rem;" title="${c.booked_by}"><i class="fa-solid fa-user-xmark me-1"></i>${c.booked_by}</div>` : ''}
          </div>
        </div>
      </div>
    `;
    row.appendChild(col);
  });

  calculateBookingTotal();
}

function toggleCottageCard(id, event) {
  if (event.target && event.target.tagName.toLowerCase() === 'input') return;
  const cb = document.getElementById('c_cb_' + id);
  if (cb && !cb.disabled) {
    cb.checked = !cb.checked;
    const card = cb.closest('.cottage-select-card');
    if (card) {
      if (cb.checked) card.classList.add('selected');
      else card.classList.remove('selected');
    }
    calculateBookingTotal();
  }
}

function onCottageCheckboxChange(event) {
  const cb = event.target;
  const card = cb.closest('.cottage-select-card');
  if (card) {
    if (cb.checked) card.classList.add('selected');
    else card.classList.remove('selected');
  }
  calculateBookingTotal();
}

// Calculate Total, Advance, Discount & Due across all selected cottages & nights
function calculateBookingTotal() {
  const checkedBoxes = Array.from(document.querySelectorAll('.cottage-cb:checked'));
  const checkInInput = document.getElementById('modalCheckIn');
  const checkOutInput = document.getElementById('modalCheckOut');
  const totalAmountInput = document.getElementById('modalTotalAmount');
  const advanceAmountInput = document.getElementById('modalAdvanceAmount');
  const discountAmountInput = document.getElementById('modalDiscountAmount');
  const dueAmountInput = document.getElementById('modalDueAmount');
  const notice = document.getElementById('nightCalculationNotice');
  const noCottageError = document.getElementById('noCottageError');

  // Compute nights
  let nights = 1;
  if (checkInInput && checkOutInput && checkInInput.value && checkOutInput.value) {
    const d1 = new Date(checkInInput.value);
    const d2 = new Date(checkOutInput.value);
    if (d2 > d1) {
      nights = Math.max(1, Math.round(Math.abs(d2 - d1) / (1000 * 60 * 60 * 24)));
    }
  }

  // Calculate combined nightly rate of selected cottages
  let combinedRate = 0;
  const selectedTitles = [];
  checkedBoxes.forEach(cb => {
    combinedRate += parseFloat(cb.dataset.price || 0);
    selectedTitles.push(cb.dataset.title || '');
  });

  // Update allocation summary bar
  const countBadge = document.getElementById('selectedCottagesCountBadge');
  const namesEl = document.getElementById('selectedCottagesNames');
  const rateEl = document.getElementById('combinedNightlyRate');

  if (countBadge) {
    countBadge.textContent = `${checkedBoxes.length} Cottage${checkedBoxes.length !== 1 ? 's' : ''}`;
    countBadge.className = checkedBoxes.length > 0 ? 'badge bg-success ms-1' : 'badge bg-secondary ms-1';
  }
  if (namesEl) namesEl.textContent = selectedTitles.length > 0 ? selectedTitles.join(', ') : 'None chosen';
  if (rateEl) rateEl.textContent = `৳ ${combinedRate.toLocaleString()} / night`;

  if (noCottageError) {
    if (checkedBoxes.length > 0) noCottageError.classList.add('d-none');
  }

  // Auto-calculate Total Bill if user is changing cottages/dates or bill is empty
  const calculatedTotal = combinedRate * nights;
  if (totalAmountInput) {
    const activeEl = document.activeElement;
    const isManualTotalEdit = (activeEl === totalAmountInput);
    if (!isManualTotalEdit || !totalAmountInput.value) {
      totalAmountInput.value = calculatedTotal.toFixed(2);
    }
  }

  if (notice) {
    if (checkedBoxes.length > 0) {
      notice.innerHTML = `<span class="text-success"><i class="fa-solid fa-calculator me-1"></i> ${nights} Night(s) &times; ৳${combinedRate.toLocaleString()}/night (${checkedBoxes.length} Cottage${checkedBoxes.length > 1 ? 's' : ''})</span>`;
    } else {
      notice.textContent = '';
    }
  }

  // Calculate Due Amount
  const total = parseFloat(totalAmountInput ? totalAmountInput.value : 0) || 0;
  const advance = parseFloat(advanceAmountInput ? advanceAmountInput.value : 0) || 0;
  const discount = parseFloat(discountAmountInput ? discountAmountInput.value : 0) || 0;
  const due = Math.max(0, total - advance - discount);
  if (dueAmountInput) {
    dueAmountInput.value = due.toFixed(2);
  }

  // Update Fixed Footer Summary Badges
  const ftrAlloc = document.getElementById('ftrAllocSummary');
  const ftrTotal = document.getElementById('ftrTotalAmountDisplay');
  const ftrDue = document.getElementById('ftrDueAmountDisplay');
  if (ftrAlloc) {
    ftrAlloc.innerHTML = `<i class="fa-solid fa-house-chimney me-1"></i> ${checkedBoxes.length} Cottage${checkedBoxes.length !== 1 ? 's' : ''} Selected`;
    ftrAlloc.className = checkedBoxes.length > 0 ? 'badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 fw-bold' : 'badge bg-secondary bg-opacity-10 text-secondary border px-2 py-1 fw-bold';
  }
  if (ftrTotal) {
    ftrTotal.textContent = `Total: ৳${total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
  }
  if (ftrDue) {
    ftrDue.textContent = `Due: ৳${due.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    ftrDue.className = due > 0 ? 'badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 fw-semibold' : 'badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 fw-semibold';
  }
}

// Validate that at least one cottage is selected before form submission
function validateBookingForm(event) {
  const checkedBoxes = document.querySelectorAll('.cottage-cb:checked');
  const noCottageError = document.getElementById('noCottageError');
  if (checkedBoxes.length === 0) {
    if (event) event.preventDefault();
    if (noCottageError) {
      noCottageError.classList.remove('d-none');
      noCottageError.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    alert('Please select at least one available cottage for this booking.');
    return false;
  }
  return true;
}

// Auto-check availability when newBookingModal is shown
document.addEventListener('DOMContentLoaded', function() {
  const newBookingModalEl = document.getElementById('newBookingModal');
  if (newBookingModalEl) {
    newBookingModalEl.addEventListener('show.bs.modal', function() {
      refreshCottageAvailability();
    });
  }
});

// Active status filter state
let currentBookingFilter = 'all';

// Filter Bookings Table by Status (All, Pending, Confirmed, Cancelled, Refunded)
function filterBookingsTable(status, btnEl) {
  currentBookingFilter = status || 'all';
  const rows = document.querySelectorAll('.booking-row');
  
  rows.forEach(row => {
    const rowStatus = row.getAttribute('data-status');
    const isRefunded = row.getAttribute('data-refunded') === '1';

    if (currentBookingFilter === 'all') {
      row.style.display = '';
    } else if (currentBookingFilter === 'refunded') {
      row.style.display = isRefunded ? '' : 'none';
    } else if (rowStatus === currentBookingFilter) {
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  });

  // Highlight active pill
  if (btnEl) {
    document.querySelectorAll('.booking-status-pill').forEach(b => {
      b.classList.remove('active');
    });
    btnEl.classList.add('active');
  }
}

// Search Filter in Bookings Table
function searchBookingsTable() {
  const input = document.getElementById('bookingSearchInput');
  const filter = input ? input.value.toLowerCase().trim() : '';
  const rows = document.querySelectorAll('#masterBookingsTable tbody tr.booking-row');

  rows.forEach(row => {
    const text = row.textContent.toLowerCase();
    const rowStatus = row.getAttribute('data-status');
    const isRefunded = row.getAttribute('data-refunded') === '1';

    let matchesFilter = (currentBookingFilter === 'all') || 
                        (currentBookingFilter === 'refunded' && isRefunded) || 
                        (rowStatus === currentBookingFilter);

    if (matchesFilter && (!filter || text.includes(filter))) {
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  });
}

// ========================================================
// CANCELLATION & REFUND MODAL LOGIC
// ========================================================

let currentCancelBooking = null;

function openCancelRefundModal(b) {
  currentCancelBooking = b;

  // Basic Info
  document.getElementById('cancelBookingId').value = b.id;
  document.getElementById('cancelMiniRef').innerText = b.b_code || ('ECO' + String(b.id).padStart(4, '0'));
  
  const cTitle = b.cottage_title || 'Resort Cottage';
  const cCount = b.cottage_count ? parseInt(b.cottage_count) : 1;
  document.getElementById('cancelMiniCottage').innerText = cTitle + (cCount > 1 ? ` (${cCount} Cottages)` : '');
  
  document.getElementById('cancelMiniGuestName').innerText = b.customer_name || 'Guest';
  document.getElementById('cancelMiniGuestPhone').innerText = b.customer_phone || '-';
  document.getElementById('cancelMiniDates').innerText = (b.check_in || '') + ' → ' + (b.check_out || '');
  document.getElementById('cancelMiniNights').innerText = (b.nights || 1) + ' Night(s)';

  // Status badge
  const stBadge = document.getElementById('cancelMiniStatus');
  if (b.status === 'accepted') {
    stBadge.className = 'badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2 py-1 fw-semibold';
    stBadge.innerText = 'Confirmed';
  } else if (b.status === 'cancelled') {
    stBadge.className = 'badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-2 py-1 fw-semibold';
    stBadge.innerText = 'Cancelled';
  } else {
    stBadge.className = 'badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 rounded-pill px-2 py-1 fw-semibold';
    stBadge.innerText = 'Pending';
  }

  // Financial baseline
  const origTotal = parseFloat(b.calculated_total || b.total_amount) || 0;
  const origAdv   = parseFloat(b.advance_amount) || 0;
  const origDue   = parseFloat(b.due_amount) || 0;

  document.getElementById('cancelMiniOrigTotal').innerText = origTotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  document.getElementById('cancelMiniOrigAdvance').innerText = origAdv.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  document.getElementById('cancelMiniOrigDue').innerText = origDue.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  document.getElementById('cancelMaxRefundableNotice').innerText = origAdv.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});

  // Set default refund amounts:
  const prevRefund = parseFloat(b.refund_amount) || 0;
  const prevFee    = parseFloat(b.cancellation_fee) || 0;

  const refundInput = document.getElementById('cancelRefundAmountInput');
  const feeInput    = document.getElementById('cancelRetainedFeeInput');

  refundInput.max = origAdv;
  feeInput.max = origAdv;

  if (b.status === 'cancelled' && (prevRefund > 0 || prevFee > 0)) {
    refundInput.value = prevRefund.toFixed(2);
    feeInput.value    = prevFee.toFixed(2);
  } else {
    // Default to full refund of advance
    refundInput.value = origAdv.toFixed(2);
    feeInput.value    = '0.00';
  }

  // Payment method and notes
  if (b.refund_method) {
    document.getElementById('cancelRefundMethod').value = b.refund_method;
  } else if (b.payment_method) {
    document.getElementById('cancelRefundMethod').value = b.payment_method;
  }
  document.getElementById('cancelRefundTrxId').value = b.refund_trx_id || '';
  document.getElementById('cancelReasonInput').value = '';

  const alertBox = document.getElementById('cancelModalAlert');
  if (alertBox) {
    alertBox.classList.add('d-none');
    alertBox.innerText = '';
  }

  calcCancelRefund('refund');

  const modal = new bootstrap.Modal(document.getElementById('cancelRefundModal'));
  modal.show();
}

function calcCancelRefund(source) {
  if (!currentCancelBooking) return;
  const origAdv = parseFloat(currentCancelBooking.advance_amount) || 0;
  const refundInput = document.getElementById('cancelRefundAmountInput');
  const feeInput    = document.getElementById('cancelRetainedFeeInput');

  let refund = parseFloat(refundInput.value) || 0;
  let fee    = parseFloat(feeInput.value) || 0;

  if (source === 'refund') {
    if (refund > origAdv) {
      refund = origAdv;
      refundInput.value = refund.toFixed(2);
    } else if (refund < 0) {
      refund = 0;
      refundInput.value = '0.00';
    }
    fee = Math.max(0, origAdv - refund);
    feeInput.value = fee.toFixed(2);
  } else if (source === 'fee') {
    if (fee > origAdv) {
      fee = origAdv;
      feeInput.value = fee.toFixed(2);
    } else if (fee < 0) {
      fee = 0;
      feeInput.value = '0.00';
    }
    refund = Math.max(0, origAdv - fee);
    refundInput.value = refund.toFixed(2);
  }

  // Update real-time preview indicators
  const previewCollected = document.getElementById('cancelPreviewCollected');
  const previewTotal     = document.getElementById('cancelPreviewTotal');

  if (previewCollected) previewCollected.innerText = '৳ ' + fee.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  if (previewTotal)     previewTotal.innerText     = '৳ ' + fee.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function setQuickRefund(type) {
  if (!currentCancelBooking) return;
  const origAdv = parseFloat(currentCancelBooking.advance_amount) || 0;
  const refundInput = document.getElementById('cancelRefundAmountInput');
  const feeInput    = document.getElementById('cancelRetainedFeeInput');

  if (type === 'full') {
    refundInput.value = origAdv.toFixed(2);
    feeInput.value    = '0.00';
  } else if (type === 'half') {
    const half = (origAdv / 2);
    refundInput.value = half.toFixed(2);
    feeInput.value    = (origAdv - half).toFixed(2);
  } else if (type === 'zero') {
    refundInput.value = '0.00';
    feeInput.value    = origAdv.toFixed(2);
  }
  calcCancelRefund('refund');
}

// Handle AJAX Cancel & Refund Submission
function handleCancelRefundSubmit(event) {
  event.preventDefault();
  const form = document.getElementById('cancelRefundForm');
  const submitBtn = document.getElementById('cancelModalSubmitBtn');
  const alertBox = document.getElementById('cancelModalAlert');

  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span> Processing Cancellation...';
  }
  if (alertBox) alertBox.classList.add('d-none');

  const formData = new FormData(form);
  formData.append('is_ajax', '1');

  fetch('cancel_booking.php', {
    method: 'POST',
    body: formData,
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    }
  })
  .then(res => res.json())
  .then(data => {
    if (data && data.success) {
      const modalEl = document.getElementById('cancelRefundModal');
      const bsModal = bootstrap.Modal.getInstance(modalEl);
      if (bsModal) bsModal.hide();

      alert('✓ ' + (data.message || 'Booking cancelled and refund processed successfully. Cottage is now available again!'));
      window.location.href = 'dashboard.php#view-bookings';
      window.location.reload();
    } else {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fa-solid fa-ban me-1"></i> Confirm Cancellation &amp; Refund';
      }
      if (alertBox) {
        alertBox.textContent = data.error || 'Failed to cancel booking. Please try again.';
        alertBox.classList.remove('d-none');
      }
    }
  })
  .catch(err => {
    console.error('Cancel booking error:', err);
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="fa-solid fa-ban me-1"></i> Confirm Cancellation &amp; Refund';
    }
    if (alertBox) {
      alertBox.textContent = 'A network error occurred while submitting cancellation. Please try again.';
      alertBox.classList.remove('d-none');
    }
  });
}

// ========================================================
// REFUND HISTORY AUDIT LOG MODAL
// ========================================================

let allRefundRecords = [];

function openRefundHistoryModal(bookingId) {
  const modalEl = document.getElementById('refundHistoryModal');
  const modal = new bootstrap.Modal(modalEl);
  modal.show();

  const loadingEl = document.getElementById('refundHistoryLoading');
  const tableWrap = document.getElementById('refundHistoryTableWrapper');
  const emptyEl   = document.getElementById('refundHistoryEmpty');
  const noticeEl  = document.getElementById('refundHistoryFilterNotice');

  if (loadingEl) loadingEl.style.display = 'block';
  if (tableWrap) tableWrap.style.display = 'none';
  if (emptyEl)   emptyEl.classList.add('d-none');

  let url = 'cancel_booking.php?action=get_refund_history';
  if (bookingId) {
    url += '&booking_id=' + encodeURIComponent(bookingId);
    if (noticeEl) noticeEl.textContent = 'Showing refund records for Booking #' + bookingId;
  } else {
    if (noticeEl) noticeEl.textContent = 'Showing all recorded refunds';
  }

  fetch(url)
    .then(res => res.json())
    .then(data => {
      if (loadingEl) loadingEl.style.display = 'none';
      if (data && data.success && Array.isArray(data.refunds) && data.refunds.length > 0) {
        allRefundRecords = data.refunds;
        renderRefundHistoryRows(allRefundRecords);
        if (tableWrap) tableWrap.style.display = 'block';
        const badge = document.getElementById('refundHistoryTotalBadge');
        if (badge) badge.innerText = allRefundRecords.length + ' Records';
      } else {
        allRefundRecords = [];
        if (emptyEl) emptyEl.classList.remove('d-none');
        const badge = document.getElementById('refundHistoryTotalBadge');
        if (badge) badge.innerText = '0 Records';
      }
    })
    .catch(err => {
      console.error('Error fetching refund history:', err);
      if (loadingEl) loadingEl.style.display = 'none';
      if (emptyEl) emptyEl.classList.remove('d-none');
    });
}

function renderRefundHistoryRows(records) {
  const tbody = document.getElementById('refundHistoryTbody');
  if (!tbody) return;

  if (records.length === 0) {
    tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-muted">No matching refund records found.</td></tr>';
    return;
  }

  tbody.innerHTML = records.map(r => `
    <tr>
      <td>
        <div class="fw-semibold text-dark">${escapeHtml(r.created_at_fmt)}</div>
      </td>
      <td>
        <span class="badge bg-secondary bg-opacity-10 text-secondary border px-2 py-1 fw-bold rounded-pill">
          ${escapeHtml(r.booking_ref)}
        </span>
      </td>
      <td>
        <div class="fw-bold text-dark">${escapeHtml(r.customer_name)}</div>
        <div class="text-muted"><i class="fa-solid fa-phone me-1 text-success"></i>${escapeHtml(r.customer_phone)}</div>
      </td>
      <td>
        <div class="fw-semibold text-dark">${escapeHtml(r.cottage_name)}</div>
        <small class="text-muted">${escapeHtml(r.stay_dates)}</small>
      </td>
      <td>
        <div>৳ ${escapeHtml(r.original_advance)}</div>
        <small class="text-muted">Total: ৳ ${escapeHtml(r.original_total)}</small>
      </td>
      <td>
        <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1 fw-bold fs-6">
          ৳ ${escapeHtml(r.refund_amount)}
        </span>
      </td>
      <td>
        <span class="text-secondary fw-semibold">
          ৳ ${escapeHtml(r.cancellation_fee)}
        </span>
      </td>
      <td>
        <div class="fw-semibold">${escapeHtml(r.refund_method)}</div>
        ${r.refund_trx_id ? `<small class="text-muted"><i class="fa-solid fa-receipt me-1"></i>${escapeHtml(r.refund_trx_id)}</small>` : ''}
      </td>
      <td>
        <span class="badge bg-light text-dark border">
          <i class="fa-solid fa-user-tie me-1 text-primary"></i>${escapeHtml(r.processed_by_name || 'Admin')}
        </span>
      </td>
    </tr>
  `).join('');
}

function filterRefundHistoryTable() {
  const q = (document.getElementById('refundHistorySearchInput').value || '').toLowerCase().trim();
  if (!q) {
    renderRefundHistoryRows(allRefundRecords);
    return;
  }
  const filtered = allRefundRecords.filter(r => {
    return (r.customer_name || '').toLowerCase().includes(q) ||
           (r.customer_phone || '').toLowerCase().includes(q) ||
           (r.booking_ref || '').toLowerCase().includes(q) ||
           (r.refund_method || '').toLowerCase().includes(q) ||
           (r.refund_trx_id || '').toLowerCase().includes(q) ||
           (r.cottage_name || '').toLowerCase().includes(q);
  });
  renderRefundHistoryRows(filtered);
}

// Auto-open cancellation modal if open_cancel query param is set in URL
document.addEventListener('DOMContentLoaded', function() {
  const urlParams = new URLSearchParams(window.location.search);
  const cancelId = urlParams.get('open_cancel');
  if (cancelId) {
    const row = document.getElementById('booking-row-' + cancelId);
    if (row && row.getAttribute('data-booking-json')) {
      try {
        const bData = JSON.parse(row.getAttribute('data-booking-json'));
        openCancelRefundModal(bData);
      } catch (e) {
        console.error('Failed to parse booking json for auto-cancel modal:', e);
      }
    }
  }
});

// ========================================================
// CUSTOMER DIRECTORY: SEARCH, NOTE EDITING & SMS DISPATCH
// ========================================================

// Fast Clipboard Copy with temporary feedback
function copyToClipboard(text, btn) {
  if (navigator.clipboard) {
    navigator.clipboard.writeText(text).then(() => {
      const orig = btn.innerHTML;
      btn.innerHTML = '<i class="fa-solid fa-check text-success" style="font-size:0.75rem;"></i>';
      setTimeout(() => { btn.innerHTML = orig; }, 1500);
    });
  } else {
    prompt('Copy phone number:', text);
  }
}

// Helper for HTML escaping
function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

// Global Customer Directory Search State & Cache
let originalCustomerTableTbody = null;
let originalBroadcastRecipientsHtml = null;
let originalBroadcastBadgeHtml = null;
let originalBroadcastTitleHtml = null;
let originalBroadcastBtnLabel = null;
let originalCustomerPageBadge = null;
let custSearchDebounceTimer = null;
let custSearchAbortController = null;

function cacheCustomerDirectoryOriginalState() {
  const tbody = document.querySelector('#customerDirectoryTable tbody');
  if (tbody && originalCustomerTableTbody === null) {
    originalCustomerTableTbody = tbody.innerHTML;
  }
  const recList = document.getElementById('broadcastRecipientsList');
  if (recList && originalBroadcastRecipientsHtml === null) {
    originalBroadcastRecipientsHtml = recList.innerHTML;
  }
  const badge = document.getElementById('broadcastRecipientsBadge');
  if (badge && originalBroadcastBadgeHtml === null) {
    originalBroadcastBadgeHtml = badge.innerHTML;
  }
  const title = document.getElementById('broadcastRecipientsTitle');
  if (title && originalBroadcastTitleHtml === null) {
    originalBroadcastTitleHtml = title.innerHTML;
  }
  const btnLabel = document.getElementById('btnCustBroadcastSmsLabel');
  if (btnLabel && originalBroadcastBtnLabel === null) {
    originalBroadcastBtnLabel = btnLabel.innerHTML;
  }
  const pageBadge = document.getElementById('customerDirectoryPageBadge');
  if (pageBadge && originalCustomerPageBadge === null) {
    originalCustomerPageBadge = pageBadge.innerHTML;
  }
}

// Initialize caching when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
  cacheCustomerDirectoryOriginalState();
});

// Search input handler with debounce
function handleCustomerDirectorySearchInput(e) {
  cacheCustomerDirectoryOriginalState();
  const input = document.getElementById('customerDirectorySearch');
  const term = input ? input.value.trim() : '';
  const clearBtn = document.getElementById('btnClearCustomerSearch');

  if (clearBtn) {
    if (term) clearBtn.classList.remove('d-none');
    else clearBtn.classList.add('d-none');
  }

  if (custSearchDebounceTimer) {
    clearTimeout(custSearchDebounceTimer);
  }

  if (!term) {
    restoreOriginalCustomerDirectoryView();
    return;
  }

  // Show searching spinner in icon
  const icon = document.getElementById('custSearchGlassIcon');
  if (icon) {
    icon.className = 'fa-solid fa-spinner fa-spin text-success';
  }

  custSearchDebounceTimer = setTimeout(() => {
    executeGlobalCustomerSearch(term);
  }, 250);
}

// Search keydown handler (immediate execute on Enter)
function handleCustomerDirectorySearchKey(e) {
  if (e.key === 'Enter') {
    e.preventDefault();
    if (custSearchDebounceTimer) {
      clearTimeout(custSearchDebounceTimer);
    }
    const input = document.getElementById('customerDirectorySearch');
    const term = input ? input.value.trim() : '';
    if (term) {
      executeGlobalCustomerSearch(term);
    } else {
      clearCustomerDirectorySearch();
    }
  }
}

// Clear Search and restore paginated view
function clearCustomerDirectorySearch() {
  const input = document.getElementById('customerDirectorySearch');
  if (input) input.value = '';
  const clearBtn = document.getElementById('btnClearCustomerSearch');
  if (clearBtn) clearBtn.classList.add('d-none');

  if (custSearchDebounceTimer) clearTimeout(custSearchDebounceTimer);
  if (custSearchAbortController) custSearchAbortController.abort();

  restoreOriginalCustomerDirectoryView();

  // Clean URL if query parameter was present
  if (window.location.search.includes('cust_search')) {
    const url = new URL(window.location.href);
    url.searchParams.delete('cust_search');
    window.history.replaceState({}, '', url.pathname + (url.search ? url.search : '') + window.location.hash);
  }
}

// Restore original paginated DOM view
function restoreOriginalCustomerDirectoryView() {
  const icon = document.getElementById('custSearchGlassIcon');
  if (icon) icon.className = 'fa-solid fa-magnifying-glass';

  const banner = document.getElementById('customerSearchStatusBanner');
  if (banner) {
    banner.classList.add('d-none');
    banner.classList.remove('d-flex');
  }

  const paginationFooter = document.getElementById('customerPaginationFooter');
  if (paginationFooter) paginationFooter.classList.remove('d-none');

  const pageBadge = document.getElementById('customerDirectoryPageBadge');
  if (pageBadge && originalCustomerPageBadge !== null) {
    pageBadge.innerHTML = originalCustomerPageBadge;
  }

  const tbody = document.querySelector('#customerDirectoryTable tbody');
  if (tbody && originalCustomerTableTbody !== null) {
    tbody.innerHTML = originalCustomerTableTbody;
  }

  const btnLabel = document.getElementById('btnCustBroadcastSmsLabel');
  if (btnLabel && originalBroadcastBtnLabel !== null) {
    btnLabel.innerHTML = originalBroadcastBtnLabel;
  }

  const recList = document.getElementById('broadcastRecipientsList');
  if (recList && originalBroadcastRecipientsHtml !== null) {
    recList.innerHTML = originalBroadcastRecipientsHtml;
  }

  const recBadge = document.getElementById('broadcastRecipientsBadge');
  if (recBadge && originalBroadcastBadgeHtml !== null) {
    recBadge.innerHTML = originalBroadcastBadgeHtml;
  }

  const recTitle = document.getElementById('broadcastRecipientsTitle');
  if (recTitle && originalBroadcastTitleHtml !== null) {
    recTitle.innerHTML = originalBroadcastTitleHtml;
  }
}

// Execute Global Search via AJAX across all pages/records
function executeGlobalCustomerSearch(term) {
  cacheCustomerDirectoryOriginalState();
  if (custSearchAbortController) custSearchAbortController.abort();
  custSearchAbortController = new AbortController();

  const icon = document.getElementById('custSearchGlassIcon');
  if (icon) icon.className = 'fa-solid fa-spinner fa-spin text-success';

  const banner = document.getElementById('customerSearchStatusBanner');
  const bannerText = document.getElementById('customerSearchStatusText');
  if (banner && bannerText) {
    banner.classList.remove('d-none');
    banner.classList.add('d-flex');
    bannerText.innerHTML = `<i class="fa-solid fa-spinner fa-spin text-success me-1"></i> Searching all guests across entire database for "<strong>${escapeHtml(term)}</strong>"...`;
  }

  fetch(`search_customers.php?q=${encodeURIComponent(term)}`, {
    signal: custSearchAbortController.signal
  })
  .then(res => res.json())
  .then(data => {
    if (icon) icon.className = 'fa-solid fa-magnifying-glass';

    if (!data.success) {
      alert(data.error || 'Customer search failed');
      return;
    }

    const tbody = document.querySelector('#customerDirectoryTable tbody');
    const paginationFooter = document.getElementById('customerPaginationFooter');
    const pageBadge = document.getElementById('customerDirectoryPageBadge');
    const btnLabel = document.getElementById('btnCustBroadcastSmsLabel');

    // Hide pagination during global search view
    if (paginationFooter) paginationFooter.classList.add('d-none');
    if (pageBadge) {
      pageBadge.innerHTML = `<i class="fa-solid fa-globe me-1"></i> Search View (${data.count})`;
    }
    if (btnLabel) {
      btnLabel.textContent = `Broadcast SMS to Results (${data.count})`;
    }

    if (banner && bannerText) {
      bannerText.innerHTML = `<i class="fa-solid fa-magnifying-glass text-success me-1"></i> Found <strong>${data.count}</strong> customer${data.count === 1 ? '' : 's'} matching "<strong>${escapeHtml(term)}</strong>" across all database records.`;
    }

    if (!tbody) return;

    if (!data.customers || data.customers.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="6" class="text-center py-5 text-muted">
            <i class="fa-solid fa-user-slash fs-2 d-block mb-3 text-secondary"></i>
            <div class="fw-bold fs-6 text-dark mb-1">No matching customers found across any page</div>
            <p class="small text-muted mb-3">No record matched "${escapeHtml(term)}" in names, phone numbers, emails, or notes.</p>
            <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3" onclick="clearCustomerDirectorySearch()">
              <i class="fa-solid fa-rotate-left me-1"></i> Reset to all guests
            </button>
          </td>
        </tr>
      `;
      const recList = document.getElementById('broadcastRecipientsList');
      if (recList) recList.innerHTML = '<span class="text-muted small fst-italic">No matching recipients</span>';
      return;
    }

    let html = '';
    let recListHtml = '';

    data.customers.forEach((cust, idx) => {
      const serial = idx + 1;
      const cName = cust.customer_name || 'Guest';
      const cPhone = cust.phone || '';
      const cEmail = cust.customer_email || '';
      const cBookings = parseInt(cust.total_bookings) || 0;
      const cSpent = parseFloat(cust.total_spent) || 0;
      const cNote = (cust.customer_note || '').trim();
      const cDate = cust.recent_order_date || '—';
      const initial = (cName.charAt(0) || 'G').toUpperCase();
      const rowId = 'cust_row_' + cPhone.replace(/[^0-9]/g, '');

      // Sanitize values for JS attributes
      const safePhoneJs = escapeHtml(cPhone).replace(/'/g, "\\'");
      const safeNameJs = escapeHtml(cName).replace(/'/g, "\\'");

      // Build table row
      html += `
        <tr class="customer-dir-row" id="${rowId}" data-search="${escapeHtml((cName + ' ' + cPhone + ' ' + cEmail + ' ' + cNote).toLowerCase())}">
          
          <!-- Serial Number -->
          <td class="ps-4">
            <span class="badge bg-light text-secondary border fw-bold px-2 py-1" style="font-size: 0.8rem;">
              #${serial}
            </span>
          </td>

          <!-- Customer Profile (Name, Phone, Email in ONE Column) -->
          <td>
            <div class="d-flex align-items-center gap-3">
              <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-sm flex-shrink-0" 
                   style="width: 40px; height: 40px; background: linear-gradient(135deg, #059669 0%, #10b981 100%); font-size: 0.95rem;">
                ${escapeHtml(initial)}
              </div>
              <div class="min-w-0">
                <div class="fw-bold text-dark text-truncate" style="font-size: 0.92rem;" title="${escapeHtml(cName)}">
                  ${escapeHtml(cName)}
                </div>
                <div class="d-flex align-items-center gap-2 mt-1">
                  <a href="tel:${escapeHtml(cPhone)}" class="text-success text-decoration-none fw-semibold small d-inline-flex align-items-center gap-1">
                    <i class="fa-solid fa-phone" style="font-size: 0.72rem;"></i>
                    <span>${escapeHtml(cPhone)}</span>
                  </a>
                  <button type="button" class="btn btn-link p-0 text-muted" title="Copy Phone Number" onclick="copyToClipboard('${escapeHtml(cPhone)}', this)">
                    <i class="fa-regular fa-copy" style="font-size: 0.75rem;"></i>
                  </button>
                </div>
                <div class="text-muted small text-truncate mt-1" style="font-size: 0.78rem;" title="${escapeHtml(cEmail || 'No email')}">
                  <i class="fa-regular fa-envelope me-1 text-secondary"></i>
                  <span>${escapeHtml(cEmail || '—')}</span>
                </div>
              </div>
            </div>
          </td>

          <!-- Total Bookings -->
          <td class="text-center">
            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 fw-bold">
              <i class="fa-solid fa-calendar-check me-1"></i> ${cBookings} Booking${cBookings > 1 ? 's' : ''}
            </span>
            <small class="text-muted d-block mt-1" style="font-size: 0.7rem;">
              <i class="fa-solid fa-clock-rotate-left me-1"></i> ${escapeHtml(cDate)}
            </small>
          </td>

          <!-- Total Spent (by number) -->
          <td class="text-end">
            <div class="fw-bold text-success fs-6">
              ৳ ${Number(cSpent).toLocaleString()}
            </div>
            <small class="text-muted d-block" style="font-size: 0.7rem;">Total Billed</small>
          </td>

          <!-- Customer Note (Editable) -->
          <td>
            <!-- Display Mode -->
            <div class="note-display-box d-flex align-items-start justify-content-between p-2 rounded-3 border" id="note_display_${rowId}" style="background: #f8fafc; min-height: 42px;">
              <span class="small text-dark note-text ${!cNote ? 'text-muted fst-italic' : ''}" id="note_text_${rowId}" style="font-size: 0.82rem; word-break: break-word;">
                ${cNote ? escapeHtml(cNote) : 'No note recorded'}
              </span>
              <button type="button" class="btn btn-sm btn-link text-success p-0 ms-2 flex-shrink-0" title="Edit Note" onclick="toggleInlineNoteEdit('${rowId}', true)">
                <i class="fa-solid fa-pen-to-square"></i>
              </button>
            </div>

            <!-- Inline Edit Mode (Initially Hidden) -->
            <div class="note-edit-box d-none mt-1" id="note_edit_${rowId}">
              <div class="input-group input-group-sm">
                <input type="text" class="form-control" id="note_input_${rowId}" value="${escapeHtml(cNote)}" placeholder="Enter note...">
                <button type="button" class="btn btn-success" title="Save Note" onclick="saveCustomerNoteAjax('${escapeHtml(cPhone)}', '${rowId}')">
                  <i class="fa-solid fa-check"></i>
                </button>
                <button type="button" class="btn btn-outline-secondary" title="Cancel" onclick="toggleInlineNoteEdit('${rowId}', false)">
                  <i class="fa-solid fa-xmark"></i>
                </button>
              </div>
            </div>
          </td>

          <!-- Send Custom SMS Action Button -->
          <td class="pe-4 text-end">
            <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3 shadow-sm fw-semibold d-inline-flex align-items-center gap-1"
                    onclick="openSingleSmsModal('${safeNameJs}', '${safePhoneJs}')">
              <i class="fa-solid fa-paper-plane" style="font-size: 0.75rem;"></i>
              <span>Send SMS</span>
            </button>
          </td>

        </tr>
      `;

      // Build broadcast recipient chip
      recListHtml += `
        <span class="badge bg-white text-dark border px-2 py-1 small fw-normal" style="font-size: 0.72rem;">
          ${escapeHtml(cName)} (${escapeHtml(cPhone)})
        </span>
        <input type="hidden" name="recipients[${idx}][name]" value="${escapeHtml(cName)}">
        <input type="hidden" name="recipients[${idx}][phone]" value="${escapeHtml(cPhone)}">
      `;
    });

    tbody.innerHTML = html;

    // Update broadcast modal recipient preview
    const recList = document.getElementById('broadcastRecipientsList');
    if (recList) recList.innerHTML = recListHtml;

    const recBadge = document.getElementById('broadcastRecipientsBadge');
    if (recBadge) recBadge.textContent = `Search Matches (${data.count})`;

    const recTitle = document.getElementById('broadcastRecipientsTitle');
    if (recTitle) recTitle.innerHTML = `<i class="fa-solid fa-users text-success me-1"></i> Selected Recipients (${data.count} Matched Guests)`;
  })
  .catch(err => {
    if (err.name === 'AbortError') return;
    console.error('Search error:', err);
    if (icon) icon.className = 'fa-solid fa-magnifying-glass';
    if (bannerText) bannerText.innerHTML = `<i class="fa-solid fa-circle-exclamation text-danger me-1"></i> Error searching customer database. Please try again.`;
  });
}

// Toggle Inline Note Editing
function toggleInlineNoteEdit(rowId, showEdit) {
  const displayBox = document.getElementById('note_display_' + rowId);
  const editBox = document.getElementById('note_edit_' + rowId);
  if (displayBox && editBox) {
    if (showEdit) {
      displayBox.classList.add('d-none');
      editBox.classList.remove('d-none');
      const input = document.getElementById('note_input_' + rowId);
      if (input) input.focus();
    } else {
      editBox.classList.add('d-none');
      displayBox.classList.remove('d-none');
    }
  }
}

// Save Customer Note via AJAX
function saveCustomerNoteAjax(phone, rowId) {
  const input = document.getElementById('note_input_' + rowId);
  const noteTextEl = document.getElementById('note_text_' + rowId);
  if (!input) return;

  const noteVal = input.value.trim();
  const formData = new FormData();
  formData.append('phone', phone);
  formData.append('note', noteVal);

  fetch('update_customer_note.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      if (noteTextEl) {
        if (noteVal) {
          noteTextEl.textContent = noteVal;
          noteTextEl.className = 'small text-dark note-text';
        } else {
          noteTextEl.textContent = 'No note recorded';
          noteTextEl.className = 'small text-dark note-text text-muted fst-italic';
        }
      }
      toggleInlineNoteEdit(rowId, false);
      // Flash row with subtle success highlight
      const row = document.getElementById(rowId);
      if (row) {
        row.style.transition = 'background-color 0.4s';
        row.style.backgroundColor = '#ecfdf5';
        setTimeout(() => { row.style.backgroundColor = ''; }, 1200);
      }
    } else {
      alert(data.error || 'Failed to update note.');
    }
  })
  .catch(err => {
    console.error(err);
    alert('An error occurred while saving the customer note.');
  });
}

// ----------------------------------------------------
// SMS Dispatch Logic (Single & Broadcast)
// ----------------------------------------------------

const SMS_TEMPLATES = {
  single: {
    welcome: "Dear {Name}, thank you for choosing Eco Divine Beach Resort, Saint Martin! Your reservation is confirmed. We look forward to welcoming you to paradise! Hotline: 01817838383",
    payment_reminder: "Dear {Name}, gentle reminder regarding your upcoming stay at Eco Divine Beach Resort. Please clear any pending dues before check-in. Contact: 01817838383",
    vip_offer: "Dear {Name}, enjoy an exclusive 15% VIP discount on your next ocean-front cottage stay at Eco Divine Beach Resort! Valid this season. Hotline: 01817838383",
    checkout_thankyou: "Dear {Name}, thank you for staying with us at Eco Divine Beach Resort! We hope you loved Saint Martin Island. Please share your feedback: ecodivine.com.bd"
  },
  broadcast: {
    winter_offer: "Eco Divine Special: Enjoy 20% discount on luxury beach cottages at Saint Martin this season! Book your dream island vacation today. Hotline: 01817838383",
    ship_schedule: "Eco Divine Advisory: Daily luxury ship services to Saint Martin are now active. Reserve your beachfront cottage in advance. Call: 01817838383",
    holiday_greeting: "Greetings from Eco Divine Beach Resort, Saint Martin! Wishing you happy holidays. Special flash rates available this weekend! Call: 01817838383"
  }
};

function openSingleSmsModal(name, phone) {
  document.getElementById('singleSmsRecipientName').textContent = name || 'Guest';
  document.getElementById('singleSmsRecipientPhone').textContent = phone || '';
  document.getElementById('singleSmsHiddenName').value = name || 'Guest';
  document.getElementById('singleSmsHiddenPhone').value = phone || '';
  document.getElementById('singleSmsTemplateSelect').value = 'custom';
  document.getElementById('singleSmsMessageText').value = '';
  document.getElementById('singleSmsAlert').classList.add('d-none');
  document.getElementById('singleSmsSuccessAlert').classList.add('d-none');
  updateSmsStats('single');

  const modalEl = document.getElementById('singleCustomerSmsModal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
}

function applySingleSmsTemplate() {
  const select = document.getElementById('singleSmsTemplateSelect');
  const textarea = document.getElementById('singleSmsMessageText');
  const val = select.value;
  if (SMS_TEMPLATES.single[val]) {
    textarea.value = SMS_TEMPLATES.single[val];
  } else if (val === 'custom') {
    textarea.value = '';
  }
  updateSmsStats('single');
}

function openBroadcastSmsModal() {
  document.getElementById('broadcastSmsTemplateSelect').value = 'custom';
  document.getElementById('broadcastSmsMessageText').value = '';
  document.getElementById('broadcastSmsAlert').classList.add('d-none');
  document.getElementById('broadcastSmsSuccessAlert').classList.add('d-none');
  updateSmsStats('broadcast');

  const modalEl = document.getElementById('broadcastSmsModal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
}

function applyBroadcastSmsTemplate() {
  const select = document.getElementById('broadcastSmsTemplateSelect');
  const textarea = document.getElementById('broadcastSmsMessageText');
  const val = select.value;
  if (SMS_TEMPLATES.broadcast[val]) {
    textarea.value = SMS_TEMPLATES.broadcast[val];
  } else if (val === 'custom') {
    textarea.value = '';
  }
  updateSmsStats('broadcast');
}

function updateSmsStats(type) {
  const textEl = document.getElementById(type === 'single' ? 'singleSmsMessageText' : 'broadcastSmsMessageText');
  const countEl = document.getElementById(type === 'single' ? 'singleSmsCharCounter' : 'broadcastSmsCharCounter');
  if (!textEl || !countEl) return;

  const len = textEl.value.length;
  const parts = Math.ceil(len / 160) || 1;
  if (type === 'single') {
    countEl.textContent = `${len} / 160 chars (${parts} SMS)`;
  } else {
    countEl.textContent = `${len} / 160 chars (${parts} SMS per guest • Top 25 Directory)`;
  }
}

function handleSingleSmsSubmit(e) {
  e.preventDefault();
  const form = document.getElementById('singleSmsForm');
  const btn = document.getElementById('btnSingleSmsSend');
  const alertEl = document.getElementById('singleSmsAlert');
  const successEl = document.getElementById('singleSmsSuccessAlert');

  alertEl.classList.add('d-none');
  successEl.classList.add('d-none');

  const formData = new FormData(form);
  formData.append('type', 'single');

  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Sending...';

  fetch('send_sms.php', {
    method: 'POST',
    body: formData
  })
  .then(res => {
    return res.text().then(text => {
      try {
        return JSON.parse(text);
      } catch (e) {
        throw new Error(text || 'Server returned an invalid response (Status ' + res.status + ')');
      }
    });
  })
  .then(data => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Send SMS';
    if (data.success) {
      successEl.textContent = data.message || 'SMS sent successfully!';
      successEl.classList.remove('d-none');
      setTimeout(() => {
        const modalEl = document.getElementById('singleCustomerSmsModal');
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
      }, 1500);
    } else {
      alertEl.textContent = data.error || 'Failed to send SMS.';
      alertEl.classList.remove('d-none');
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Send SMS';
    alertEl.textContent = (err && err.message) ? err.message : 'Network error occurred while sending SMS.';
    alertEl.classList.remove('d-none');
  });
}

function handleBroadcastSmsSubmit(e) {
  e.preventDefault();
  const form = document.getElementById('broadcastSmsForm');
  const btn = document.getElementById('btnBroadcastSmsSend');
  const alertEl = document.getElementById('broadcastSmsAlert');
  const successEl = document.getElementById('broadcastSmsSuccessAlert');

  alertEl.classList.add('d-none');
  successEl.classList.add('d-none');

  const formData = new FormData(form);
  formData.append('type', 'broadcast');

  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Broadcasting...';

  fetch('send_sms.php', {
    method: 'POST',
    body: formData
  })
  .then(res => {
    return res.text().then(text => {
      try {
        return JSON.parse(text);
      } catch (e) {
        throw new Error(text || 'Server returned an invalid response (Status ' + res.status + ')');
      }
    });
  })
  .then(data => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-bullhorn me-1"></i> Send Broadcast SMS';
    if (data.success) {
      successEl.textContent = data.message || 'Broadcast SMS sent successfully!';
      successEl.classList.remove('d-none');
      setTimeout(() => {
        const modalEl = document.getElementById('broadcastSmsModal');
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
      }, 1800);
    } else {
      alertEl.textContent = data.error || 'Failed to send broadcast SMS.';
      alertEl.classList.remove('d-none');
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-bullhorn me-1"></i> Send Broadcast SMS';
    alertEl.textContent = (err && err.message) ? err.message : 'Network error occurred while broadcasting SMS.';
    alertEl.classList.remove('d-none');
  });
}

// ========================================================
// EARNINGS & PAYMENTS HUB: MARK PAID & THANK YOU SMS
// ========================================================

const MARK_PAID_TEMPLATES = {
  full_settled: "Dear {Name}, thank you for your payment of ৳{Amount}! Your remaining due has been successfully cleared for booking #{BookingID} at Eco Divine Beach Resort, Saint Martin. We wish you a delightful stay! Hotline: 01817838383",
  partial_received: "Dear {Name}, we have received your payment of ৳{Amount} for booking #{BookingID}. Remaining due: ৳{Due}. Thank you for choosing Eco Divine Beach Resort! Hotline: 01817838383",
  custom: ""
};

let currentMpCustomer = null;

function openMarkPaidModal(cust, isReceiptOnly) {
  if (isReceiptOnly === undefined) isReceiptOnly = false;
  currentMpCustomer = cust;

  document.getElementById('mpCustomerId').value = cust.customer_id || (cust.id ? cust.id : '');
  const mpBIdEl = document.getElementById('mpBookingId');
  if (mpBIdEl) mpBIdEl.value = cust.booking_id || '';
  document.getElementById('mpBookingRef').value = cust.booking_ref || ('#' + cust.id);

  document.getElementById('mpDisplayBookingRef').textContent = cust.booking_ref || ('#' + cust.id);
  document.getElementById('mpDisplayGuestName').textContent = cust.name || 'Valued Guest';
  document.getElementById('mpDisplayGuestPhone').textContent = cust.phone || '—';
  document.getElementById('mpDisplayCottage').textContent = cust.cottage || 'Resort Cottage';

  const totalBill = parseFloat(cust.total_amount) || 0;
  const advancePaid = parseFloat(cust.advance_amount) || 0;
  const dueAmount = parseFloat(cust.due_amount) || 0;

  document.getElementById('mpDisplayTotalBill').textContent = totalBill.toLocaleString();
  document.getElementById('mpDisplayAdvancePaid').textContent = advancePaid.toLocaleString();
  document.getElementById('mpDisplayDueAmount').textContent = dueAmount.toLocaleString();

  const amountInput = document.getElementById('mpAmountPaidInput');
  if (isReceiptOnly || dueAmount <= 0) {
    amountInput.value = 0;
    amountInput.min = 0;
  } else {
    amountInput.value = dueAmount;
    amountInput.min = 0.01;
  }

  // Preselect payment method
  const methodSelect = document.getElementById('mpPaymentMethodSelect');
  if (cust.payment_method && Array.from(methodSelect.options).some(o => o.value === cust.payment_method)) {
    methodSelect.value = cust.payment_method;
  } else {
    methodSelect.value = 'Cash at Front Desk';
  }

  document.getElementById('mpTrxIdInput').value = '';
  document.getElementById('mpNotesInput').value = '';

  // Setup SMS checkbox & template
  const sendSmsCheck = document.getElementById('mpSendSmsCheck');
  sendSmsCheck.checked = true;
  toggleMarkPaidSmsContainer();

  const templateSelect = document.getElementById('mpSmsTemplateSelect');
  templateSelect.value = (dueAmount <= 0 || isReceiptOnly) ? 'full_settled' : 'full_settled';
  applyMarkPaidSmsTemplate();

  onMarkPaidAmountChange();

  // Configure Download Invoice link
  const dlLink = document.getElementById('mpDownloadInvoiceLink');
  if (dlLink) {
    const custId = cust.customer_id || cust.id;
    const bId = cust.booking_id;
    let url = 'invoice.php?';
    if (bId) url += 'booking_id=' + encodeURIComponent(bId) + '&';
    if (custId) url += 'customer_id=' + encodeURIComponent(custId);
    dlLink.href = url;

    if (dueAmount <= 0 || isReceiptOnly) {
      dlLink.classList.remove('d-none');
      dlLink.classList.add('d-inline-flex');
    } else {
      dlLink.classList.add('d-none');
      dlLink.classList.remove('d-inline-flex');
    }
  }

  document.getElementById('mpModalAlert').classList.add('d-none');
  document.getElementById('mpModalSuccessAlert').classList.add('d-none');
  
  const submitBtn = document.getElementById('btnSubmitMarkPaid');
  submitBtn.disabled = false;
  submitBtn.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i> Confirm & Mark Paid';

  const modalEl = document.getElementById('markPaidModal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
}

function onMarkPaidAmountChange() {
  if (!currentMpCustomer) return;

  const currentDue = parseFloat(currentMpCustomer.due_amount) || 0;
  const enteredAmount = parseFloat(document.getElementById('mpAmountPaidInput').value) || 0;
  const newDue = Math.max(0, currentDue - enteredAmount);

  const noticeText = document.getElementById('mpDueCalculationText');
  const noticeBox = document.getElementById('mpDueCalculationNotice');

  if (newDue <= 0) {
    noticeBox.style.background = '#ecfdf5';
    noticeBox.style.borderColor = '#a7f3d0';
    noticeText.innerHTML = `New Due after payment: <strong class="text-success">৳ 0 (Fully Cleared! 🎉)</strong>`;
  } else {
    noticeBox.style.background = '#fffbeb';
    noticeBox.style.borderColor = '#fde68a';
    noticeText.innerHTML = `New Due after payment: <strong class="text-warning">৳ ${newDue.toLocaleString()} (Remaining Due)</strong>`;
  }

  updateMarkPaidSmsStats();
}

function setMarkPaidQuickAmount(type) {
  if (!currentMpCustomer) return;
  const currentDue = parseFloat(currentMpCustomer.due_amount) || 0;
  const input = document.getElementById('mpAmountPaidInput');
  if (type === 'full') {
    input.value = currentDue;
  } else if (type === 'half') {
    input.value = Math.round(currentDue / 2);
  }
  onMarkPaidAmountChange();
}

function toggleMarkPaidSmsContainer() {
  const check = document.getElementById('mpSendSmsCheck');
  const container = document.getElementById('mpSmsContainer');
  if (check && container) {
    if (check.checked) container.classList.remove('d-none');
    else container.classList.add('d-none');
  }
}

function applyMarkPaidSmsTemplate() {
  if (!currentMpCustomer) return;
  const select = document.getElementById('mpSmsTemplateSelect');
  const textarea = document.getElementById('mpSmsMessageText');
  const val = select.value;
  if (MARK_PAID_TEMPLATES[val]) {
    textarea.value = MARK_PAID_TEMPLATES[val];
  } else if (val === 'custom') {
    textarea.value = '';
  }
  updateMarkPaidSmsStats();
}

function updateMarkPaidSmsStats() {
  const textEl = document.getElementById('mpSmsMessageText');
  const countEl = document.getElementById('mpSmsCharCounter');
  if (!textEl || !countEl) return;

  const len = textEl.value.length;
  const parts = Math.ceil(len / 160) || 1;
  countEl.textContent = `${len} / 160 chars (${parts} SMS)`;
}

function handleMarkPaidSubmit(e) {
  e.preventDefault();
  const form = document.getElementById('markPaidForm');
  const btn = document.getElementById('btnSubmitMarkPaid');
  const alertEl = document.getElementById('mpModalAlert');
  const successEl = document.getElementById('mpModalSuccessAlert');

  alertEl.classList.add('d-none');
  successEl.classList.add('d-none');

  const formData = new FormData(form);

  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Processing & Sending SMS...';

  fetch('mark_paid.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i> Confirm & Mark Paid';

    if (data.success) {
      successEl.innerHTML = `<i class="fa-solid fa-check-circle me-1"></i> ${escapeHtml(data.message)}`;
      successEl.classList.remove('d-none');

      // Update the specific table row in DOM
      const custId = data.customer_id;
      const dueValEl = document.getElementById('due_val_' + custId);
      const dueBadgeEl = document.getElementById('due_badge_' + custId);
      const advanceValEl = document.getElementById('advance_val_' + custId);
      const statusBadgeEl = document.getElementById('status_badge_' + custId);
      const actionBtnEl = document.getElementById('btn_pay_cust_' + custId);
      const rowEl = document.getElementById('earn_row_' + custId);

      if (dueValEl) {
        dueValEl.textContent = '৳ ' + data.new_due_format;
        if (data.new_due <= 0) {
          dueValEl.className = 'fw-bold text-success fs-6';
          if (dueBadgeEl) {
            dueBadgeEl.className = 'badge bg-success bg-opacity-10 text-success border border-success border-opacity-25';
            dueBadgeEl.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i>Fully Paid';
          }
        } else {
          dueValEl.className = 'fw-bold text-danger fs-6';
          if (dueBadgeEl) {
            dueBadgeEl.className = 'badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25';
            dueBadgeEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i>Due Pending';
          }
        }
      }

      if (advanceValEl) {
        advanceValEl.textContent = '৳ ' + data.new_advance_format + ' paid';
      }

      if (statusBadgeEl) {
        if (data.new_status === 'paid') {
          statusBadgeEl.className = 'status-badge status-badge-paid';
          statusBadgeEl.textContent = 'Paid';
        } else if (data.new_status === 'partial') {
          statusBadgeEl.className = 'status-badge status-badge-partial';
          statusBadgeEl.textContent = 'Partial';
        } else {
          statusBadgeEl.className = 'status-badge status-badge-unpaid';
          statusBadgeEl.textContent = 'Unpaid';
        }
      }

      if (actionBtnEl && data.new_due <= 0) {
        actionBtnEl.className = 'btn btn-sm btn-outline-success rounded-pill px-3 shadow-sm fw-bold d-inline-flex align-items-center gap-1 action-btn-pay';
        actionBtnEl.innerHTML = '<i class="fa-solid fa-file-invoice-dollar" style="font-size:0.85rem;"></i><span>Download Invoice</span>';
        actionBtnEl.onclick = function() { window.open('invoice.php?customer_id=' + custId, '_blank'); };
      }

      const modalDlLink = document.getElementById('mpDownloadInvoiceLink');
      if (modalDlLink) {
        modalDlLink.classList.remove('d-none');
        modalDlLink.classList.add('d-inline-flex');
        modalDlLink.href = 'invoice.php?customer_id=' + custId;
      }

      // Flash row with subtle success highlight
      if (rowEl) {
        rowEl.style.transition = 'background-color 0.4s';
        rowEl.style.backgroundColor = '#ecfdf5';
        setTimeout(() => { rowEl.style.backgroundColor = ''; }, 2000);
      }

      // Update KPI metrics on top
      const kpiCollected = document.getElementById('earnKpiCollectedRevenue');
      if (kpiCollected && data.totalEarnings_fmt) kpiCollected.textContent = '৳ ' + data.totalEarnings_fmt;

      const kpiDue = document.getElementById('earnKpiDueBalance');
      if (kpiDue && data.dueEarnings_fmt) kpiDue.textContent = '৳ ' + data.dueEarnings_fmt;

      const kpiDueBadge = document.getElementById('earnKpiDueBadge');
      if (kpiDueBadge && data.dueCount !== undefined) kpiDueBadge.textContent = data.dueCount + ' Pending';

      const kpiRate = document.getElementById('earnKpiRecoveryRate');
      if (kpiRate && data.recoveryRate !== undefined) kpiRate.textContent = data.recoveryRate + '%';

      const kpiProgress = document.getElementById('earnKpiProgressBar');
      if (kpiProgress && data.recoveryRate !== undefined) kpiProgress.style.width = data.recoveryRate + '%';

      // Refresh dashboard operational stats
      if (typeof fetchDashboardDataAjax === 'function') {
        fetchDashboardDataAjax();
      }

      // Hide modal after 1.4s
      setTimeout(() => {
        const modalEl = document.getElementById('markPaidModal');
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
      }, 1400);

    } else {
      alertEl.textContent = data.error || 'Failed to record payment.';
      alertEl.classList.remove('d-none');
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i> Confirm & Mark Paid';
    alertEl.textContent = 'Network or server error occurred while recording payment.';
    alertEl.classList.remove('d-none');
  });
}

// Client-side search filter for visible ledger rows
function filterEarningsTableClientSide(query) {
  const term = query.toLowerCase().trim();
  const rows = document.querySelectorAll('#earningsLedgerTable tbody tr.earn-table-row');
  const clearBtn = document.getElementById('btnClearEarnSearch');

  if (clearBtn) {
    if (term) clearBtn.classList.remove('d-none');
    else clearBtn.classList.add('d-none');
  }

  rows.forEach(row => {
    const dataSearch = row.getAttribute('data-search') || '';
    if (!term || dataSearch.includes(term)) {
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  });
}

function executeEarningsSearchServer(query) {
  const term = query.trim();
  const url = new URL(window.location.href);
  if (term) {
    url.searchParams.set('earn_search', term);
  } else {
    url.searchParams.delete('earn_search');
  }
  url.searchParams.set('earn_page', '1');
  url.hash = 'view-earnings';
  window.location.href = url.toString();
}

function clearEarningsSearch() {
  const input = document.getElementById('earningsSearchInput');
  if (input) input.value = '';
  filterEarningsTableClientSide('');
  const clearBtn = document.getElementById('btnClearEarnSearch');
  if (clearBtn) clearBtn.classList.add('d-none');

  if (window.location.search.includes('earn_search')) {
    const url = new URL(window.location.href);
    url.searchParams.delete('earn_search');
    url.searchParams.set('earn_page', '1');
    url.hash = 'view-earnings';
    window.location.href = url.toString();
  }
}

// ========================================================
// DASHBOARD OVERVIEW: AUTO-FETCH ENGINE & OPERATIONAL STATS
// ========================================================

const dashState = {
  preset: 'today',
  date_type: 'check_in',
  start_date: '',
  end_date: '',
  specific_date: ''
};

let dashCurrentBookings = [];

function switchDashboardPreset(preset) {
  dashState.preset = preset;
  dashState.specific_date = '';
  dashState.start_date = '';
  dashState.end_date = '';

  // Clear specific date & range inputs
  const spInput = document.getElementById('dashSpecificDateInput');
  if (spInput) spInput.value = '';
  const sInput = document.getElementById('dashStartDateInput');
  if (sInput) sInput.value = '';
  const eInput = document.getElementById('dashEndDateInput');
  if (eInput) eInput.value = '';

  // Update preset buttons visual active state
  document.querySelectorAll('.dash-preset-btn').forEach(btn => {
    btn.classList.remove('active');
  });

  const activeId = {
    'today': 'btnPresetToday',
    'yesterday': 'btnPresetYesterday',
    'this_week': 'btnPresetThisWeek',
    'this_month': 'btnPresetThisMonth',
    'all_time': 'btnPresetAllTime'
  }[preset];

  const activeBtn = activeId ? document.getElementById(activeId) : null;
  if (activeBtn) activeBtn.classList.add('active');

  fetchDashboardDataAjax();
}

function switchDashboardDateType(type) {
  dashState.date_type = type;
  fetchDashboardDataAjax();
}

function applyDashboardSpecificDate(dateVal) {
  if (!dateVal) return;
  dashState.preset = 'custom';
  dashState.specific_date = dateVal;
  dashState.start_date = dateVal;
  dashState.end_date = dateVal;

  // Clear range inputs
  const sInput = document.getElementById('dashStartDateInput');
  if (sInput) sInput.value = '';
  const eInput = document.getElementById('dashEndDateInput');
  if (eInput) eInput.value = '';

  // Uncheck presets
  document.querySelectorAll('.dash-preset-btn').forEach(btn => btn.classList.remove('active'));

  fetchDashboardDataAjax();
}

function applyDashboardDateRange() {
  const sInput = document.getElementById('dashStartDateInput');
  const eInput = document.getElementById('dashEndDateInput');
  if (!sInput || !sInput.value) {
    alert('Please select a start date.');
    return;
  }
  const sVal = sInput.value;
  const eVal = (eInput && eInput.value) ? eInput.value : sVal;

  dashState.preset = 'custom';
  dashState.specific_date = '';
  dashState.start_date = sVal;
  dashState.end_date = eVal;

  // Clear single specific date input
  const spInput = document.getElementById('dashSpecificDateInput');
  if (spInput) spInput.value = '';

  // Uncheck presets
  document.querySelectorAll('.dash-preset-btn').forEach(btn => btn.classList.remove('active'));

  fetchDashboardDataAjax();
}

function onDashboardRangeInputChange() {
  const sInput = document.getElementById('dashStartDateInput');
  const eInput = document.getElementById('dashEndDateInput');
  if (sInput && eInput && sInput.value && eInput.value) {
    applyDashboardDateRange();
  }
}

function refreshDashboardData() {
  fetchDashboardDataAjax();
}

function fetchDashboardDataAjax() {
  const spinner = document.getElementById('dashRefreshSpinner');
  if (spinner) spinner.classList.add('fa-spin');

  const tbody = document.getElementById('dashReservationsTbody');
  if (tbody && tbody.children.length === 0) {
    tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-muted"><i class="fa-solid fa-spinner fa-spin text-success me-2"></i> Loading operational data...</td></tr>`;
  }

  const params = new URLSearchParams();
  params.append('preset', dashState.preset);
  params.append('date_type', dashState.date_type);
  if (dashState.specific_date) params.append('specific_date', dashState.specific_date);
  if (dashState.start_date) params.append('start_date', dashState.start_date);
  if (dashState.end_date) params.append('end_date', dashState.end_date);

  fetch('fetch_dashboard_stats.php?' + params.toString())
    .then(r => r.json())
    .then(data => {
      if (spinner) spinner.classList.remove('fa-spin');
      if (!data || !data.success) {
        console.error('Failed to fetch dashboard stats:', data);
        return;
      }

      // Update Filter Active Badge
      const filterBadge = document.getElementById('dashFilterActiveText');
      if (filterBadge && data.filter && data.filter.label) {
        filterBadge.textContent = data.filter.label;
      }

      // 6 KPI Metric Cards
      const kpis = data.kpis;
      
      // 1. Available Cottages: STRICTLY only for Today, otherwise 0!
      const kpiAvail = document.getElementById('dashKpiAvailable');
      const kpiAvailNote = document.getElementById('dashKpiAvailableNote');
      if (kpiAvail) {
        kpiAvail.textContent = kpis.available_cottages;
        if (data.filter.is_today) {
          kpiAvail.className = 'fw-bold text-dark mb-1';
          if (kpiAvailNote) {
            kpiAvailNote.innerHTML = '<span class="text-success fw-medium"><i class="fa-solid fa-circle-check me-1"></i> Live today\'s availability</span>';
          }
        } else {
          kpiAvail.className = 'fw-bold text-muted mb-1';
          if (kpiAvailNote) {
            kpiAvailNote.innerHTML = '<span class="text-muted"><i class="fa-solid fa-circle-info me-1"></i> Available for today only</span>';
          }
        }
      }

      // 2. Pending Bookings
      const kpiPend = document.getElementById('dashKpiPending');
      if (kpiPend) kpiPend.textContent = kpis.pending_bookings;

      // 3. Confirmed Bookings
      const kpiConf = document.getElementById('dashKpiConfirmed');
      if (kpiConf) kpiConf.textContent = kpis.confirmed_bookings;

      // 4. Total Amount
      const kpiTot = document.getElementById('dashKpiTotalAmount');
      if (kpiTot) kpiTot.textContent = kpis.total_amount_fmt;

      // 5. Collected Amount
      const kpiAdv = document.getElementById('dashKpiAdvanceAmount');
      if (kpiAdv) kpiAdv.textContent = kpis.advance_amount_fmt;

      // 6. Due Amount
      const kpiDue = document.getElementById('dashKpiDueAmount');
      if (kpiDue) kpiDue.textContent = kpis.due_amount_fmt;

      // Render Table
      dashCurrentBookings = data.bookings || [];
      renderDashboardTable(dashCurrentBookings);
    })
    .catch(err => {
      if (spinner) spinner.classList.remove('fa-spin');
      console.error('Error fetching dashboard data:', err);
    });
}

function renderDashboardTable(bookings) {
  const tbody = document.getElementById('dashReservationsTbody');
  if (!tbody) return;

  const subtitle = document.getElementById('dashTableSubtitle');
  if (subtitle) {
    subtitle.textContent = `Showing ${bookings.length} reservations matching the active timeframe filter.`;
  }

  if (!bookings || bookings.length === 0) {
    tbody.innerHTML = `<tr><td colspan="8" class="text-center py-5 text-muted"><i class="fa-solid fa-inbox fs-3 mb-2 d-block text-secondary"></i>No reservations found for the selected timeframe.</td></tr>`;
    return;
  }

  let html = '';
  bookings.forEach(b => {
    const isPending = (b.status === 'pending');
    const isConfirmed = (b.status === 'accepted');
    const isCancelled = (b.status === 'cancelled');

    let statusBadgeClass = 'status-badge-pending';
    let statusLabel = 'Pending';
    if (isConfirmed) {
      statusBadgeClass = 'status-badge-accepted';
      statusLabel = 'Confirmed';
    } else if (isCancelled) {
      statusBadgeClass = 'status-badge-cancelled';
      statusLabel = 'Cancelled';
    }

    const hasDue = (parseFloat(b.due_amount) > 0);
    const dueBadge = hasDue 
      ? `<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25" style="font-size:0.72rem;"><i class="fa-solid fa-triangle-exclamation me-1"></i>Due</span>`
      : `<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25" style="font-size:0.72rem;"><i class="fa-solid fa-circle-check me-1"></i>Cleared</span>`;

    html += `
      <tr id="dash_row_${b.id}">
        <td>
          <span class="fw-bold text-dark font-monospace">${escapeHtml(b.booking_ref)}</span>
          <div class="text-muted" style="font-size:0.75rem;">#${b.id}</div>
        </td>
        <td>
          <div class="fw-bold text-dark">${escapeHtml(b.customer_name)}</div>
          <div class="small text-muted"><i class="fa-solid fa-phone text-secondary me-1"></i>${escapeHtml(b.customer_phone || '—')}</div>
          ${b.customer_email ? `<div class="small text-muted" style="font-size:0.75rem;"><i class="fa-solid fa-envelope text-secondary me-1"></i>${escapeHtml(b.customer_email)}</div>` : ''}
        </td>
        <td>
          <div class="fw-semibold text-dark">${escapeHtml(b.cottage_title)}</div>
          <small class="text-muted">
            <i class="fa-solid fa-calendar-days text-secondary me-1"></i>${escapeHtml(b.check_in_fmt)} &rarr; ${escapeHtml(b.check_out_fmt)}
          </small>
        </td>
        <td>
          <span class="fw-bold text-dark">৳ ${escapeHtml(b.total_fmt)}</span>
        </td>
        <td>
          <span class="fw-bold text-success">৳ ${escapeHtml(b.advance_fmt)}</span>
        </td>
        <td>
          <div class="d-flex align-items-center gap-2">
            <span class="fw-bold ${hasDue ? 'text-danger' : 'text-success'}">৳ ${escapeHtml(b.due_fmt)}</span>
            ${dueBadge}
          </div>
        </td>
        <td>
          <span class="status-badge ${statusBadgeClass}">
            ${statusLabel}
          </span>
        </td>
        <td class="text-end">
          <div class="d-inline-flex align-items-center gap-1">
            ${hasDue ? `
              <button type="button" class="btn btn-sm btn-success rounded-pill px-2 py-1 shadow-sm fw-medium d-inline-flex align-items-center gap-1" onclick="openMarkPaidFromDashboard(${b.id})" title="Settle Due Payment">
                <i class="fa-solid fa-hand-holding-dollar"></i>
                <span>Mark Paid</span>
              </button>
            ` : `
              <a href="invoice.php?booking_id=${b.id}" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-2 py-1 shadow-sm fw-medium d-inline-flex align-items-center gap-1" title="Download Official Invoice Receipt">
                <i class="fa-solid fa-file-invoice-dollar"></i>
                <span>Invoice</span>
              </a>
              <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-1 d-inline-flex align-items-center gap-1" onclick="openMarkPaidFromDashboard(${b.id}, true)" title="View Receipt / Send SMS">
                <i class="fa-solid fa-receipt"></i>
              </button>
            `}

            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-1" onclick="activateView('view-bookings')" title="Manage in Bookings Page">
              <i class="fa-solid fa-eye"></i>
            </button>
          </div>
        </td>
      </tr>
    `;
  });

  tbody.innerHTML = html;
}

function openMarkPaidFromDashboard(bookingId, isReceiptOnly) {
  if (isReceiptOnly === undefined) isReceiptOnly = false;
  const b = dashCurrentBookings.find(x => x.id === bookingId);
  if (!b) {
    alert('Booking information not found.');
    return;
  }

  const custPayload = {
    id: b.customer_id || b.id,
    customer_id: b.customer_id || 0,
    booking_id: b.id,
    booking_ref: b.booking_ref,
    name: b.customer_name,
    phone: b.customer_phone,
    cottage: b.cottage_title,
    total_amount: b.total_amount,
    advance_amount: b.advance_amount,
    due_amount: b.due_amount,
    payment_method: b.payment_method
  };

  openMarkPaidModal(custPayload, isReceiptOnly);
}

function filterDashboardTableClientSide(keyword) {
  const term = keyword.toLowerCase().trim();
  if (!term) {
    renderDashboardTable(dashCurrentBookings);
    return;
  }

  const filtered = dashCurrentBookings.filter(b => {
    return (b.customer_name && b.customer_name.toLowerCase().includes(term)) ||
           (b.customer_phone && b.customer_phone.toLowerCase().includes(term)) ||
           (b.booking_ref && b.booking_ref.toLowerCase().includes(term)) ||
           (b.cottage_title && b.cottage_title.toLowerCase().includes(term));
  });

  renderDashboardTable(filtered);
}

// Auto-fetch dashboard data when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
  fetchDashboardDataAjax();
});

// ========================================================
// ADMINS & STAFF MANAGEMENT JAVASCRIPT HELPERS
// ========================================================
function openEditAdminModal(adm) {
  if (!adm) return;
  document.getElementById('editAdminId').value = adm.id;
  document.getElementById('editAdminName').value = adm.name || '';
  document.getElementById('editAdminUsername').value = adm.username || '';
  document.getElementById('editAdminEmail').value = adm.email || '';
  document.getElementById('editAdminRole').value = adm.role || 'admin';
  document.getElementById('editAdminPassword').value = '';

  const modalEl = document.getElementById('editAdminModal');
  const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  modal.show();
}

function openDeleteAdminModal(adminId, adminName, adminUsername) {
  document.getElementById('deleteAdminId').value = adminId;
  document.getElementById('deleteAdminTargetName').textContent = adminName;
  document.getElementById('deleteAdminTargetUsername').textContent = '@' + adminUsername;

  const modalEl = document.getElementById('deleteAdminModal');
  const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  modal.show();
}

function togglePasswordVisibility(inputId, iconId) {
  const input = document.getElementById(inputId);
  const icon = document.getElementById(iconId);
  if (!input || !icon) return;

  if (input.type === 'password') {
    input.type = 'text';
    icon.classList.remove('fa-eye');
    icon.classList.add('fa-eye-slash');
  } else {
    input.type = 'password';
    icon.classList.remove('fa-eye-slash');
    icon.classList.add('fa-eye');
  }
}

function filterAdminsTableClientSide(keyword) {
  const term = (keyword || '').toLowerCase().trim();
  const rows = document.querySelectorAll('.admin-row');
  let visibleCount = 0;

  rows.forEach(row => {
    const searchData = row.getAttribute('data-search') || '';
    if (!term || searchData.includes(term)) {
      row.style.display = '';
      visibleCount++;
    } else {
      row.style.display = 'none';
    }
  });

  const visibleCountEl = document.getElementById('adminVisibleCount');
  if (visibleCountEl) visibleCountEl.textContent = visibleCount;
  const countDisplayEl = document.getElementById('adminsCountDisplay');
  if (countDisplayEl) countDisplayEl.textContent = visibleCount + ' Staff';
}
// ========================================================
// SYSTEM SETTINGS & COMMUNICATIONS HUB (SMS & EMAIL)
// ========================================================

function switchSettingsTab(tabId) {
  const tabBtn = document.getElementById(tabId);
  if (tabBtn) {
    const tabInstance = bootstrap.Tab.getOrCreateInstance(tabBtn);
    tabInstance.show();
  }
}

function handleSmsProviderChange(provider) {
  const urlInput = document.getElementById('intSmsApiUrl');
  if (!urlInput) return;

  const defaultUrls = {
    'greenweb': 'https://api.greenweb.com.bd/api.php',
    'bulksmsbd': 'http://bulksmsbd.net/api/smsapi',
    'mimsms': 'https://api.mimsms.com/api/v1/send',
    'custom': 'https://api.yourgateway.com/send?apikey={apikey}&to={phone}&msg={message}'
  };

  if (defaultUrls[provider]) {
    urlInput.value = defaultUrls[provider];
  }
}

function toggleEmailEngineFields(engine) {
  const container = document.getElementById('smtpFieldsContainer');
  if (!container) return;
  if (engine === 'mail') {
    container.style.display = 'none';
  } else {
    container.style.display = 'block';
  }
}

function handleSaveSmsSettings(e) {
  e.preventDefault();
  const form = document.getElementById('smsSettingsForm');
  const btn = document.getElementById('btnSaveSmsSettings');
  const alertEl = document.getElementById('smsSettingsAlert');
  if (!form || !btn) return;

  alertEl.className = 'alert d-none mb-3 py-2 small';

  const formData = new FormData(form);
  formData.append('action', 'save_sms_settings');

  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Saving...';

  fetch('ajax_integrations.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Save SMS Settings';
    if (data.success) {
      alertEl.className = 'alert alert-success mb-3 py-2 small';
      alertEl.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i> ' + (data.message || 'SMS Gateway settings updated successfully!');
    } else {
      alertEl.className = 'alert alert-danger mb-3 py-2 small';
      alertEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i> ' + (data.error || 'Failed to save SMS settings.');
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Save SMS Settings';
    alertEl.className = 'alert alert-danger mb-3 py-2 small';
    alertEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i> Network error while saving SMS settings.';
  });
}

function handleSaveEmailSettings(e) {
  e.preventDefault();
  const form = document.getElementById('emailSettingsForm');
  const btn = document.getElementById('btnSaveEmailSettings');
  const alertEl = document.getElementById('emailSettingsAlert');
  if (!form || !btn) return;

  alertEl.className = 'alert d-none mb-3 py-2 small';

  const formData = new FormData(form);
  formData.append('action', 'save_email_settings');

  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Saving...';

  fetch('ajax_integrations.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Save Email Settings';
    if (data.success) {
      alertEl.className = 'alert alert-success mb-3 py-2 small';
      alertEl.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i> ' + (data.message || 'Email & SMTP settings saved successfully!');
    } else {
      alertEl.className = 'alert alert-danger mb-3 py-2 small';
      alertEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i> ' + (data.error || 'Failed to save email settings.');
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Save Email Settings';
    alertEl.className = 'alert alert-danger mb-3 py-2 small';
    alertEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i> Network error while saving email settings.';
  });
}

function handleDispatchTestSms(e) {
  e.preventDefault();
  const phone = document.getElementById('testSmsPhone').value.trim();
  const message = document.getElementById('testSmsMessage').value.trim();
  const btn = document.getElementById('btnDispatchTestSms');
  const alertEl = document.getElementById('testSmsAlert');

  if (!phone) {
    alert('Please enter a phone number to test.');
    return;
  }

  alertEl.className = 'alert d-none mb-3 py-2 small';
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Dispatching...';

  const formData = new FormData();
  formData.append('action', 'test_sms');
  formData.append('test_phone', phone);
  formData.append('test_message', message);

  fetch('ajax_integrations.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Dispatch Test SMS';
    if (data.success) {
      alertEl.className = 'alert alert-success mb-3 py-2 small';
      alertEl.innerHTML = '<strong><i class="fa-solid fa-circle-check me-1"></i> ' + escapeHtml(data.message) + '</strong><br><small class="text-muted">' + escapeHtml(data.response || '') + '</small>';
    } else {
      alertEl.className = 'alert alert-danger mb-3 py-2 small';
      alertEl.innerHTML = '<strong><i class="fa-solid fa-triangle-exclamation me-1"></i> ' + escapeHtml(data.error || data.message || 'Dispatch failed') + '</strong><br><small class="text-muted">' + escapeHtml(data.response || '') + '</small>';
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Dispatch Test SMS';
    alertEl.className = 'alert alert-danger mb-3 py-2 small';
    alertEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i> Network error occurred while testing SMS.';
  });
}

function handleDispatchTestEmail(e) {
  e.preventDefault();
  const email = document.getElementById('testEmailInput').value.trim();
  const name = document.getElementById('testEmailName').value.trim();
  const btn = document.getElementById('btnDispatchTestEmail');
  const alertEl = document.getElementById('testEmailAlert');

  if (!email) {
    alert('Please enter an email address to test.');
    return;
  }

  alertEl.className = 'alert d-none mb-3 py-2 small';
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Dispatching...';

  const formData = new FormData();
  formData.append('action', 'test_email');
  formData.append('test_email', email);
  formData.append('test_name', name);

  fetch('ajax_integrations.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Dispatch Test Email';
    if (data.success) {
      alertEl.className = 'alert alert-success mb-3 py-2 small';
      alertEl.innerHTML = '<strong><i class="fa-solid fa-circle-check me-1"></i> ' + escapeHtml(data.message) + '</strong><br><small class="text-muted">' + escapeHtml(data.response || '') + '</small>';
    } else {
      alertEl.className = 'alert alert-danger mb-3 py-2 small';
      alertEl.innerHTML = '<strong><i class="fa-solid fa-triangle-exclamation me-1"></i> ' + escapeHtml(data.error || data.message || 'Dispatch failed') + '</strong><br><small class="text-muted">' + escapeHtml(data.response || '') + '</small>';
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Dispatch Test Email';
    alertEl.className = 'alert alert-danger mb-3 py-2 small';
    alertEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i> Network error occurred while testing Email.';
  });
}

function loadIntegrationLogs() {
  const smsTbody = document.getElementById('smsLogsTableBody');
  const emailTbody = document.getElementById('emailLogsTableBody');
  if (smsTbody) smsTbody.innerHTML = '<tr><td colspan="4" class="text-center py-3 text-muted"><i class="fa-solid fa-spinner fa-spin text-primary me-2"></i> Fetching logs...</td></tr>';
  if (emailTbody) emailTbody.innerHTML = '<tr><td colspan="4" class="text-center py-3 text-muted"><i class="fa-solid fa-spinner fa-spin text-danger me-2"></i> Fetching logs...</td></tr>';

  fetch('ajax_integrations.php?action=get_logs')
    .then(r => r.json())
    .then(data => {
      if (!data.success) return;

      // Render SMS Logs
      if (smsTbody) {
        if (!data.sms_logs || data.sms_logs.length === 0) {
          smsTbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No SMS records found.</td></tr>';
        } else {
          let sHtml = '';
          data.sms_logs.forEach(l => {
            const stBadge = (l.status === 'sent') 
              ? '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-0">Sent</span>'
              : (l.status === 'queued' ? '<span class="badge bg-warning bg-opacity-10 text-warning border px-2 py-0">Queued</span>' : '<span class="badge bg-danger bg-opacity-10 text-danger border px-2 py-0">Failed</span>');
            sHtml += `
              <tr>
                <td><strong>${escapeHtml(l.name || 'Guest')}</strong><div class="text-muted" style="font-size:0.7rem;">${escapeHtml(l.phone)}</div></td>
                <td>${stBadge}</td>
                <td><div class="text-truncate" style="max-width: 140px;" title="${escapeHtml(l.message)}">${escapeHtml(l.message)}</div></td>
                <td class="text-muted" style="font-size:0.7rem;">${escapeHtml(l.created_at)}</td>
              </tr>
            `;
          });
          smsTbody.innerHTML = sHtml;
        }
      }

      // Render Email Logs
      if (emailTbody) {
        if (!data.email_logs || data.email_logs.length === 0) {
          emailTbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No email records found.</td></tr>';
        } else {
          let eHtml = '';
          data.email_logs.forEach(l => {
            const stBadge = (l.status === 'sent') 
              ? '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-0">Sent</span>'
              : (l.status === 'queued' ? '<span class="badge bg-warning bg-opacity-10 text-warning border px-2 py-0">Queued</span>' : '<span class="badge bg-danger bg-opacity-10 text-danger border px-2 py-0">Failed</span>');
            eHtml += `
              <tr>
                <td><strong>${escapeHtml(l.name || 'Guest')}</strong><div class="text-muted text-truncate" style="font-size:0.7rem; max-width:120px;">${escapeHtml(l.email)}</div></td>
                <td>${stBadge}</td>
                <td><div class="text-truncate" style="max-width: 140px;" title="${escapeHtml(l.subject)}">${escapeHtml(l.subject)}</div></td>
                <td class="text-muted" style="font-size:0.7rem;">${escapeHtml(l.created_at)}</td>
              </tr>
            `;
          });
          emailTbody.innerHTML = eHtml;
        }
      }
    })
    .catch(err => {
      console.error('Error fetching integration logs:', err);
    });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
