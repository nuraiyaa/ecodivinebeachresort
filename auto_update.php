<?php
include('../includes/db.php');

// আজকের তারিখ নাও
$today = date('Y-m-d');

// যেসব বুকিং checkout হয়ে গেছে কিন্তু এখনো status='accepted'
$query = "UPDATE bookings 
          SET status='completed'
          WHERE check_out < '$today' 
          AND status='accepted'";

if (mysqli_query($conn, $query)) {
    echo "✅ Auto availability update done!";
} else {
    echo "❌ Error: " . mysqli_error($conn);
}
?>
