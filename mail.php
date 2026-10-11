<?php
require __DIR__ . '/admin/includes/db.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

$response = ['status' => 'error', 'message' => 'Something went wrong. Please try again.'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (empty($name) || empty($email) || empty($message)) {
        $response['message'] = 'Please fill out all required fields (Name, Email, Message).';
        echo json_encode($response);
        exit;
    }

    $nameClean = mysqli_real_escape_string($conn, $name);
    $emailClean = mysqli_real_escape_string($conn, $email);
    
    // Combine phone with message if provided
    $fullMessage = $message;
    if (!empty($phone)) {
        $fullMessage = "Phone/WhatsApp: " . $phone . "\n\n" . $message;
    }
    $messageClean = mysqli_real_escape_string($conn, $fullMessage);

    $sql = "INSERT INTO complaints (name, email, message) VALUES ('$nameClean', '$emailClean', '$messageClean')";
    if ($conn->query($sql)) {
        $response['status'] = 'success';
        $response['message'] = 'Thank you, ' . htmlspecialchars($name) . '! Your message has been received. Our team will contact you shortly.';
    } else {
        $response['message'] = 'Database error: ' . $conn->error;
    }
}

echo json_encode($response);
exit;
