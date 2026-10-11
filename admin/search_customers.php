<?php
// admin/search_customers.php
// Global search across all resort guests across all pages

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

$term = trim($_REQUEST['q'] ?? '');

if ($term === '') {
    echo json_encode(['success' => true, 'query' => '', 'count' => 0, 'customers' => []]);
    exit;
}

$termEsc = $conn->real_escape_string($term);

$sql = "
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
                name, phone AS clean_phone, email, created_at AS last_activity, total_amount AS amount, notes AS note
            FROM customers WHERE phone IS NOT NULL AND TRIM(phone) != ''
            UNION ALL
            SELECT 
                CONCAT('b_', b.id) AS record_key,
                NULL AS cust_id,
                b.customer_name AS name, b.customer_phone AS clean_phone, b.customer_email AS email, b.created_at AS last_activity, b.total_amount AS amount, b.notes AS note
            FROM bookings b WHERE b.customer_phone IS NOT NULL AND TRIM(b.customer_phone) != ''
              AND NOT EXISTS (
                  SELECT 1 FROM customers c2 
                  WHERE c2.booking_id = CONCAT('ECO', LPAD(b.id, 4, '0'))
                     OR (c2.phone = b.customer_phone AND c2.check_in = b.check_in)
              )
        ) AS u
        GROUP BY clean_phone
    ) AS all_guests
    WHERE customer_name LIKE '%$termEsc%' 
       OR phone LIKE '%$termEsc%' 
       OR customer_email LIKE '%$termEsc%' 
       OR customer_note LIKE '%$termEsc%'
    ORDER BY recent_order_date DESC
    LIMIT 100
";

$res = $conn->query($sql);
$results = [];

if ($res) {
    while ($r = $res->fetch_assoc()) {
        $cDate = $r['recent_order_date'] ? date('M d, Y', strtotime($r['recent_order_date'])) : '—';
        $results[] = [
            'phone' => $r['phone'],
            'customer_name' => $r['customer_name'] ?: 'Guest',
            'customer_email' => $r['customer_email'] ?: '',
            'total_bookings' => (int)$r['total_bookings'],
            'total_spent' => (float)$r['total_spent'],
            'recent_order_date' => $cDate,
            'customer_note' => trim($r['customer_note'] ?? '')
        ];
    }
}

echo json_encode([
    'success' => true,
    'query' => $term,
    'count' => count($results),
    'customers' => $results
], JSON_UNESCAPED_UNICODE);
