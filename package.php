<?php
// Redirect package.php -> packages.php preserving all query strings (check_in, check_out, etc.)
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: packages.php' . $qs, true, 301);
exit;
