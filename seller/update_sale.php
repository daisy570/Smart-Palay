<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Not authenticated.']); exit; }
if (($_SESSION['role'] ?? '') !== 'seller') { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Seller access required.']); exit; }

$userId = (int) $_SESSION['user_id'];
$input  = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$id       = (int)($input['id'] ?? 0);
$buyerName = trim((string)($input['buyer_name'] ?? ''));
$buyerId   = (int)($input['buyer_id'] ?? 0);
$weight    = (float)($input['weight_kg']    ?? 0);
$price     = (float)($input['price_per_kg'] ?? 0);
$paid      = (float)($input['amount_paid']  ?? 0);
$date      = trim((string)($input['created_at'] ?? date('Y-m-d')));
$notes     = trim((string)($input['notes']      ?? ''));

if ($id <= 0 || (($buyerName === '' && $buyerId <= 0)) || $weight <= 0 || $price <= 0) {
    echo json_encode(['success'=>false,'message'=>'Missing required fields.']);
    exit;
}

try {
    if ($buyerId <= 0 && $buyerName !== '') {
        $buyerStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'buyer' AND full_name = ? LIMIT 1");
        $buyerStmt->execute([$buyerName]);
        $buyerId = (int) $buyerStmt->fetchColumn();
    }

    $stmt = $pdo->prepare("SELECT id FROM purchases WHERE id = ? AND seller_id = ? LIMIT 1");
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetchColumn()) {
        echo json_encode(['success'=>false,'message'=>'Sale not found.']);
        exit;
    }

    $total   = $weight * $price;
    $balance = max(0, $total - $paid);
    $status  = $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');

    $hasBuyerName = $pdo->query("SHOW COLUMNS FROM purchases LIKE 'buyer_name'")->fetch();
    $hasAmountPaid = $pdo->query("SHOW COLUMNS FROM purchases LIKE 'amount_paid'")->fetch();

    if ($hasBuyerName && $hasAmountPaid) {
        $buyerIdValue = $buyerId > 0 ? $buyerId : null;
        $pdo->prepare("
            UPDATE purchases
            SET buyer_id = ?, buyer_name = ?, weight_kg = ?, price_per_kg = ?,
                total_amount = ?, amount_paid = ?, balance = ?,
                status = ?, notes = ?, created_at = ?
            WHERE id = ? AND seller_id = ?
        ")->execute([$buyerIdValue, $buyerName, $weight, $price, $total, $paid, $balance, $status, $notes, $date . ' ' . date('H:i:s'), $id, $userId]);
    } else {
        if ($buyerId <= 0) {
            throw new Exception('Buyer not found. Please select a valid buyer before updating the sale.');
        }

        $pdo->prepare("
            UPDATE purchases
            SET buyer_id = ?, weight_kg = ?, price_per_kg = ?,
                total_amount = ?, balance = ?, status = ?, notes = ?, created_at = ?
            WHERE id = ? AND seller_id = ?
        ")->execute([$buyerId, $weight, $price, $total, $balance, $status, $notes, $date . ' ' . date('H:i:s'), $id, $userId]);
    }

    echo json_encode(['success'=>true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>'Database error: ' . $e->getMessage()]);
}