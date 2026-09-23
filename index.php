<?php
require_once __DIR__ . '/config/database.php';

if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

$role = $_SESSION['role'];
switch ($role) {
    case 'admin':  header('Location: ' . BASE_URL . 'admin/dashboard.php');  break;
    case 'seller': header('Location: ' . BASE_URL . 'seller/dashboard.php'); break;
    case 'buyer':  header('Location: ' . BASE_URL . 'buyer/dashboard.php');  break;
    default:       header('Location: ' . BASE_URL . 'auth/login.php');
}
exit;