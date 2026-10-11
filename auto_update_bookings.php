<?php
require_once __DIR__ . '/admin/includes/db.php';

$today = date('Y-m-d');

// যেসব বুকিং checkout হয়ে গেছে তাদের স্ট্যাটাস চেক করো
$sql = "UPDATE bookings 
        SET status = 'completed' 
        WHERE status = 'accepted' AND check_out < ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $today);
$stmt->execute();
$stmt->close();

// cottages টেবিলেও তাদের আবার available করে দাও
$conn->query("
    UPDATE cottages 
    SET status='active' 
    WHERE id IN (
        SELECT cottage_id FROM bookings WHERE status='completed'
    )
");
