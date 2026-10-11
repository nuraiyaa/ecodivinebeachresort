<?php
// admin/fetch_dashboard_stats.php
// Dedicated Endpoint: Auto-fetch dashboard metrics & bookings with date presets and custom date range filters

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
date_default_timezone_set('Asia/Dhaka');

$today       = date('Y-m-d');
$yesterday   = date('Y-m-d', strtotime('-1 day'));
$week_start  = date('Y-m-d', strtotime('monday this week'));
$week_end    = date('Y-m-d', strtotime('sunday this week'));
$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');

$preset    = trim($_REQUEST['preset'] ?? 'today');
$date_type = in_array($_REQUEST['date_type'] ?? '', ['check_in', 'created_at']) ? $_REQUEST['date_type'] : 'check_in';
$is_today  = false;
$date_label = '';
$start = null;
$end = null;

if ($preset === 'today') {
    $start = $today;
    $end = $today;
    $is_today = true;
    $date_label = "Today (" . date('M d, Y') . ")";
} elseif ($preset === 'yesterday') {
    $start = $yesterday;
    $end = $yesterday;
    $is_today = false;
    $date_label = "Yesterday (" . date('M d, Y', strtotime($yesterday)) . ")";
} elseif ($preset === 'this_week') {
    $start = $week_start;
    $end = $week_end;
    $is_today = false;
    $date_label = "This Week (" . date('M d', strtotime($week_start)) . " – " . date('M d, Y', strtotime($week_end)) . ")";
} elseif ($preset === 'this_month') {
    $start = $month_start;
    $end = $month_end;
    $is_today = false;
    $date_label = "This Month (" . date('F Y') . ")";
} elseif ($preset === 'custom') {
    if (!empty($_REQUEST['specific_date'])) {
        $start = trim($_REQUEST['specific_date']);
        $end = $start;
    } else {
        $start = !empty($_REQUEST['start_date']) ? trim($_REQUEST['start_date']) : $today;
        $end = !empty($_REQUEST['end_date']) ? trim($_REQUEST['end_date']) : $start;
    }
    if ($end < $start) {
        $tmp = $start; $start = $end; $end = $tmp;
    }
    $is_today = ($start === $today && $end === $today);
    $date_label = ($start === $end) ? ("Date: " . date('M d, Y', strtotime($start))) : (date('M d, Y', strtotime($start)) . " – " . date('M d, Y', strtotime($end)));
} else {
    $preset = 'all_time';
    $start = null;
    $end = null;
    $is_today = false;
    $date_label = "All Time Records";
}

// 1. Available Cottages calculation
// Rule: Available cottages ONLY for Today, otherwise strictly 0!
if ($is_today) {
    $totalActiveCottages = (int)($conn->query("SELECT COUNT(*) c FROM cottages WHERE status = 'active'")->fetch_assoc()['c'] ?? 12);
    $occupiedToday = (int)($conn->query("
        SELECT COUNT(DISTINCT bc.cottage_id) c 
        FROM booking_cottages bc
        JOIN bookings b ON bc.booking_id = b.id
        WHERE b.status IN ('accepted', 'pending')
          AND b.check_in <= '$today' AND b.check_out > '$today'
    ")->fetch_assoc()['c'] ?? 0);
    $availableCottages = max(0, $totalActiveCottages - $occupiedToday);
} else {
    $availableCottages = 0;
}

// 2. Metrics calculation
$col = ($date_type === 'created_at') ? "DATE(b.created_at)" : "b.check_in";

if ($preset === 'all_time') {
    $pendingBookings   = (int)($conn->query("SELECT COUNT(*) c FROM bookings WHERE status = 'pending'")->fetch_assoc()['c'] ?? 0);
    $confirmedBookings = (int)($conn->query("SELECT COUNT(*) c FROM bookings WHERE status = 'accepted'")->fetch_assoc()['c'] ?? 0);
    $totalAmount       = (float)($conn->query("SELECT IFNULL(SUM(total_amount), 0) s FROM customers")->fetch_assoc()['s'] ?? 0);
    $dueAmount         = (float)($conn->query("SELECT IFNULL(SUM(due_amount), 0) s FROM customers")->fetch_assoc()['s'] ?? 0);
    $advanceAmount     = (float)($conn->query("SELECT IFNULL(SUM(total_amount - due_amount), 0) s FROM customers")->fetch_assoc()['s'] ?? 0);
    if ($advanceAmount <= 0) {
        $advDirect = (float)($conn->query("SELECT IFNULL(SUM(advance_amount), 0) s FROM customers")->fetch_assoc()['s'] ?? 0);
        if ($advDirect > 0) $advanceAmount = $advDirect;
    }
    if ($totalAmount <= 0 && ($advanceAmount > 0 || $dueAmount > 0)) {
        $totalAmount = $advanceAmount + $dueAmount;
    }
} else {
    $statsQ = $conn->query("
        SELECT 
            COUNT(*) as total_b,
            SUM(IF(b.status = 'pending', 1, 0)) as pending_b,
            SUM(IF(b.status = 'accepted', 1, 0)) as confirmed_b,
            SUM(COALESCE(NULLIF(b.total_amount, 0), c.total_amount, 0)) as sum_total,
            SUM(COALESCE(NULLIF(b.advance_amount, 0), c.advance_amount, GREATEST(0, COALESCE(NULLIF(b.total_amount, 0), c.total_amount, 0) - COALESCE(NULLIF(b.due_amount, 0), c.due_amount, 0)), 0)) as sum_advance,
            SUM(COALESCE(NULLIF(b.due_amount, 0), c.due_amount, 0)) as sum_due
        FROM bookings b
        LEFT JOIN customers c ON (
            c.booking_id = CONCAT('ECO', LPAD(b.id, 4, '0'))
            OR (c.phone = b.customer_phone AND c.check_in = b.check_in)
        )
        WHERE $col BETWEEN '$start' AND '$end'
    ")->fetch_assoc();

    $pendingBookings   = (int)($statsQ['pending_b'] ?? 0);
    $confirmedBookings = (int)($statsQ['confirmed_b'] ?? 0);
    $totalAmount       = (float)($statsQ['sum_total'] ?? 0);
    $advanceAmount     = (float)($statsQ['sum_advance'] ?? 0);
    $dueAmount         = (float)($statsQ['sum_due'] ?? 0);
    if ($totalAmount <= 0 && ($advanceAmount > 0 || $dueAmount > 0)) {
        $totalAmount = $advanceAmount + $dueAmount;
    }
}

// 3. Fetch Bookings for this period
$whereSql = $start ? "WHERE $col BETWEEN '$start' AND '$end'" : "";
$listSql = "
    SELECT 
        b.id,
        b.customer_name,
        b.customer_phone,
        b.customer_email,
        b.check_in,
        b.check_out,
        b.status,
        b.created_at,
        COALESCE(
            (SELECT GROUP_CONCAT(c2.title ORDER BY c2.title SEPARATOR ', ')
             FROM booking_cottages bc2 
             JOIN cottages c2 ON bc2.cottage_id = c2.id 
             WHERE bc2.booking_id = b.id),
            (SELECT title FROM cottages WHERE id = b.cottage_id),
            'Resort Cottage'
        ) AS cottage_title,
        COALESCE(
            (SELECT COUNT(DISTINCT bc3.cottage_id)
             FROM booking_cottages bc3 
             WHERE bc3.booking_id = b.id),
            1
        ) AS cottage_count,
        COALESCE(NULLIF(b.total_amount, 0), c.total_amount, 0) as total_amount,
        COALESCE(NULLIF(b.advance_amount, 0), c.advance_amount, 0) as advance_amount,
        COALESCE(NULLIF(b.due_amount, 0), c.due_amount, 0) as due_amount,
        COALESCE(b.payment_method, c.payment_method, 'Cash at Front Desk') as payment_method,
        c.id as customer_id
    FROM bookings b
    LEFT JOIN customers c ON (
        c.booking_id = CONCAT('ECO', LPAD(b.id, 4, '0'))
        OR (c.phone = b.customer_phone AND c.check_in = b.check_in)
    )
    $whereSql
    ORDER BY b.id DESC
    LIMIT 60
";

$listRes = $conn->query($listSql);
$bookingsList = [];

if ($listRes) {
    while ($r = $listRes->fetch_assoc()) {
        $bId = (int)$r['id'];
        $cName = $r['customer_name'] ?: 'Guest';
        $cPhone = $r['customer_phone'] ?: '';
        $cEmail = $r['customer_email'] ?: '';
        $cCottage = $r['cottage_title'] ?: 'Resort Cottage';
        $bStatus = $r['status'] ?: 'pending';
        $bTotal = (float)$r['total_amount'];
        $bAdvance = (float)$r['advance_amount'];
        $bDue = (float)$r['due_amount'];
        $bRef = 'ECO' . str_pad($bId, 4, '0', STR_PAD_LEFT);
        $cIn = !empty($r['check_in']) ? date('M d, Y', strtotime($r['check_in'])) : '—';
        $cOut = !empty($r['check_out']) ? date('M d, Y', strtotime($r['check_out'])) : '—';
        $created = !empty($r['created_at']) ? date('M d, Y', strtotime($r['created_at'])) : '—';

        $bookingsList[] = [
            'id'             => $bId,
            'booking_ref'    => $bRef,
            'customer_id'    => (int)($r['customer_id'] ?? 0),
            'customer_name'  => $cName,
            'customer_phone' => $cPhone,
            'customer_email' => $cEmail,
            'cottage_title'  => $cCottage,
            'cottage_count'  => (int)$r['cottage_count'],
            'check_in'       => $r['check_in'],
            'check_out'      => $r['check_out'],
            'check_in_fmt'   => $cIn,
            'check_out_fmt'  => $cOut,
            'created_at_fmt' => $created,
            'status'         => $bStatus,
            'total_amount'   => $bTotal,
            'total_fmt'      => number_format($bTotal),
            'advance_amount' => $bAdvance,
            'advance_fmt'    => number_format($bAdvance),
            'due_amount'     => $bDue,
            'due_fmt'        => number_format($bDue),
            'payment_method' => $r['payment_method']
        ];
    }
}

echo json_encode([
    'success' => true,
    'filter' => [
        'preset'     => $preset,
        'date_type'  => $date_type,
        'start_date' => $start,
        'end_date'   => $end,
        'is_today'   => $is_today,
        'label'      => $date_label
    ],
    'kpis' => [
        'available_cottages'     => $availableCottages,
        'is_today'               => $is_today,
        'pending_bookings'       => $pendingBookings,
        'confirmed_bookings'     => $confirmedBookings,
        'total_amount'           => $totalAmount,
        'total_amount_fmt'       => number_format($totalAmount),
        'advance_amount'         => $advanceAmount,
        'advance_amount_fmt'     => number_format($advanceAmount),
        'due_amount'             => $dueAmount,
        'due_amount_fmt'         => number_format($dueAmount)
    ],
    'bookings_count' => count($bookingsList),
    'bookings'       => $bookingsList
], JSON_UNESCAPED_UNICODE);
