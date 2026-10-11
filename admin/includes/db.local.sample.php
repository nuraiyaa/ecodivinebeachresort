<?php
// admin/includes/db.local.sample.php
// Copy this file to 'db.local.php' on your cPanel server and fill in your cPanel MySQL database credentials.
// 'db.local.php' is ignored by Git, ensuring your live production database password is never exposed.

$servername = "localhost";
$username   = "cpaneluser_dbuser";
$password   = "YOUR_STRONG_DATABASE_PASSWORD";
$dbname     = "cpaneluser_ecodivine";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>
