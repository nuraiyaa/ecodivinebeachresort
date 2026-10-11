<?php
// admin/update_booking.php
include('includes/db.php');
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// Handle POST confirmation from modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_confirm_booking'])) {
    $id = intval($_POST['booking_id'] ?? 0);
    $total_bill = floatval($_POST['total_bill'] ?? 0);
    $advance_amount = floatval($_POST['advance_amount'] ?? 0);
    $discount_amount = floatval($_POST['discount_amount'] ?? 0);
    $due_amount = max(0, $total_bill - $advance_amount - $discount_amount);
    $payment_method = trim($_POST['payment_method'] ?? 'bKash (Personal / Merchant)');
    $notes = trim($_POST['notes'] ?? '');
    $admin_id = $_SESSION['admin_id'];
    $accepted_at = date('Y-m-d H:i:s');

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE bookings SET status = 'accepted', accepted_by = ?, accepted_at = ?, total_amount = ?, advance_amount = ?, discount_amount = ?, due_amount = ?, payment_method = ?, notes = ? WHERE id = ?");
        $stmt->bind_param("isddddssi", $admin_id, $accepted_at, $total_bill, $advance_amount, $discount_amount, $due_amount, $payment_method, $notes, $id);
        $stmt->execute();
        $stmt->close();

        // Synchronize customers table
        $b_res = $conn->query("SELECT b.*, c.title as cottage_title FROM bookings b LEFT JOIN cottages c ON b.cottage_id = c.id WHERE b.id = $id");
        $curr_b = $b_res ? $b_res->fetch_assoc() : null;
        if ($curr_b) {
            $b_code = 'ECO' . str_pad($id, 4, '0', STR_PAD_LEFT);
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
        }
    }
    header("Location: dashboard.php?msg=confirmed#view-bookings");
    exit;
}

$id = intval($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';
$ref = $_GET['ref'] ?? 'dashboard';

$redirectUrl = "dashboard.php?msg=updated#view-bookings";

if (!$id || !in_array($action, ['accept', 'cancel'])) {
    die("Invalid request");
}

// Fetch booking with cottage info
$stmt = $conn->prepare("
    SELECT b.*, c.title AS cottage_title, c.price AS cottage_price 
    FROM bookings b 
    LEFT JOIN cottages c ON b.cottage_id = c.id 
    WHERE b.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    die("Booking not found");
}

if ($action === 'cancel') {
    header("Location: dashboard.php?open_cancel={$id}#view-bookings");
    exit;
}

// Action = Accept / Confirm Booking
$admin_id = $_SESSION['admin_id'];
$accepted_at = date('Y-m-d H:i:s');

$stmt = $conn->prepare("UPDATE bookings SET status = 'accepted', accepted_by = ?, accepted_at = ? WHERE id = ?");
$stmt->bind_param("isi", $admin_id, $accepted_at, $id);
$ok = $stmt->execute();
$stmt->close();

if ($ok) {
    // Sync into customers table if not already present
    $b_code = 'ECO' . str_pad($id, 4, '0', STR_PAD_LEFT);
    $c_phone = $conn->real_escape_string($booking['customer_phone']);
    $c_check = $conn->query("SELECT id FROM customers WHERE booking_id = '$b_code' OR (phone = '$c_phone' AND check_in = '{$booking['check_in']}') LIMIT 1");
    
    if (!$c_check || $c_check->num_rows === 0) {
        $cottage_title = $booking['cottage_title'] ?: 'Resort Cottage';
        $tot = floatval($booking['total_amount'] ?? 0);
        $adv = floatval($booking['advance_amount'] ?? 0);
        
        // If total_amount was 0 or not calculated, compute based on cottage price & nights
        if ($tot <= 0 && !empty($booking['cottage_price'])) {
            $cinStr = (!empty($booking['check_in']) && $booking['check_in'] !== '0000-00-00') ? $booking['check_in'] : date('Y-m-d');
            $coutStr = (!empty($booking['check_out']) && $booking['check_out'] !== '0000-00-00') ? $booking['check_out'] : date('Y-m-d', strtotime($cinStr . ' +1 day'));
            if ($coutStr <= $cinStr) {
                $coutStr = date('Y-m-d', strtotime($cinStr . ' +1 day'));
            }
            $cin = new DateTime($cinStr);
            $cout = new DateTime($coutStr);
            $nights = max(1, $cin->diff($cout)->days);
            $tot = floatval($booking['cottage_price']) * $nights;
            $conn->query("UPDATE bookings SET total_amount = $tot WHERE id = $id AND (total_amount = 0 OR total_amount IS NULL)");
        }
        
        $due = max(0, $tot - $adv);
        $pay_status = ($adv >= $tot && $tot > 0) ? 'paid' : ($adv > 0 ? 'partial' : 'unpaid');
        $guests = 2;

        $stmt_c = $conn->prepare("INSERT INTO customers (name, email, phone, cottage_name, check_in, check_out, guest_count, booking_id, total_amount, advance_amount, due_amount, payment_status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt_c->bind_param("ssssssisddds", 
            $booking['customer_name'], 
            $booking['customer_email'], 
            $booking['customer_phone'], 
            $cottage_title, 
            $booking['check_in'], 
            $booking['check_out'], 
            $guests, 
            $b_code, 
            $tot, 
            $adv, 
            $due, 
            $pay_status
        );
        $stmt_c->execute();
        $stmt_c->close();
    }

    header("Location: dashboard.php?msg=confirmed#view-bookings");
    exit;
} else {
    die("Failed to update booking");
}
?>
