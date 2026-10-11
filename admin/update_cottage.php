<?php
// admin/update_cottage.php
if (session_status() === PHP_SESSION_NONE) session_start();
include('includes/db.php');

if (!isset($_SESSION['admin_id'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    header('Location: login.php');
    exit;
}

$ref = $_POST['ref'] ?? $_GET['ref'] ?? 'dashboard';
$redirectTarget = 'dashboard.php#view-cottages';

function getRedirectUrl($target, $msg) {
    $parts = explode('#', $target);
    $base = $parts[0];
    $hash = isset($parts[1]) ? '#' . $parts[1] : '';
    $separator = (strpos($base, '?') !== false) ? '&' : '?';
    return $base . $separator . 'msg=' . urlencode($msg) . $hash;
}

// 1. Bulk Weekend Toggle (All On / All Off)
if (isset($_GET['bulk_weekend'])) {
    $val = intval($_GET['bulk_weekend']);
    $val = ($val === 1) ? 1 : 0;
    $conn->query("UPDATE cottages SET is_weekend = $val");
    $msg = ($val === 1) ? 'weekend_all_enabled' : 'weekend_all_disabled';
    
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        echo json_encode(['success' => true, 'is_weekend' => $val]);
        exit;
    }
    header("Location: " . getRedirectUrl($redirectTarget, $msg));
    exit;
}

// 2. Single Cottage Weekend Toggle
if (isset($_GET['toggle_weekend'])) {
    $id = intval($_GET['toggle_weekend']);
    $conn->query("UPDATE cottages SET is_weekend = IF(is_weekend = 1, 0, 1) WHERE id = $id");
    
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        $res = $conn->query("SELECT is_weekend, price, weekend_price FROM cottages WHERE id = $id")->fetch_assoc();
        echo json_encode(['success' => true, 'data' => $res]);
        exit;
    }
    header("Location: " . getRedirectUrl($redirectTarget, 'weekend_toggled'));
    exit;
}

// 3. Single Cottage Availability Toggle (Active / Inactive)
if (isset($_GET['toggle_status'])) {
    $id = intval($_GET['toggle_status']);
    $conn->query("UPDATE cottages SET status = IF(status = 'active', 'inactive', 'active') WHERE id = $id");
    
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        $res = $conn->query("SELECT status FROM cottages WHERE id = $id")->fetch_assoc();
        echo json_encode(['success' => true, 'status' => $res['status']]);
        exit;
    }
    header("Location: " . getRedirectUrl($redirectTarget, 'status_toggled'));
    exit;
}

// 4. Update Cottage Form Submission (Prices, Status, Weekend)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_cottage'])) {
    $id = intval($_POST['cottage_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $price = intval($_POST['price'] ?? 0);
    $weekend_price = intval($_POST['weekend_price'] ?? 0);
    $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
    $is_weekend = isset($_POST['is_weekend']) ? 1 : 0;

    if ($id > 0 && !empty($title) && $price > 0) {
        $stmt = $conn->prepare("UPDATE cottages SET title = ?, price = ?, weekend_price = ?, status = ?, is_weekend = ? WHERE id = ?");
        $stmt->bind_param("siisii", $title, $price, $weekend_price, $status, $is_weekend, $id);
        $stmt->execute();
        $stmt->close();

        header("Location: " . getRedirectUrl($redirectTarget, 'cottage_updated'));
        exit;
    } else {
        header("Location: " . getRedirectUrl($redirectTarget, 'cottage_error'));
        exit;
    }
}

header("Location: " . $redirectTarget);
exit;
?>
