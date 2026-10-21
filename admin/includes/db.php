<?php
// admin/includes/db.php

// Allow local environment override (useful on cPanel production)
if (file_exists(__DIR__ . '/db.local.php')) {
    require_once __DIR__ . '/db.local.php';
} else {
    $servername = getenv('DB_HOST') ?: "localhost";
    $username   = getenv('DB_USER') ?: "root";
    $password   = getenv('DB_PASS') !== false ? getenv('DB_PASS') : "";
    $dbname     = getenv('DB_NAME') ?: "ecodivine";

    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        die("Database Connection Failed: " . $conn->connect_error);
    }

    $conn->set_charset("utf8mb4");
}

// Load centralized stay pricing helper
require_once __DIR__ . '/pricing_helper.php';
?>
