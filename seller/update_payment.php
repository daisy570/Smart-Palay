<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Not authenticated.']); exit; }
if (($_SESSION['role'] ?? '') !== 'seller') { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Seller access required.']); exit; }

$userId = (int) $_SESSION['user_id'];
$input  = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$id          = (int)($input['id'] ?? 0);
$purchaseRef = trim((string)($input['purchase_ref'] ?? ''));
$amount      = (float)($input['amount']  ?? 0);
$method      = strtolower(trim((string)($input['method'] ?? 'cash')));
$paidAt      = trim((string)($input['paid_at'] ?? date('Y-m-d')));
$notes       = trim((string)($input['notes'] ?? ''));

if ($id <= 0 || $amount <= 0) { echo json_encode(['success'=>false,'message'=>'Missing required fields.']); exit; }

try {
    $stmt = $pdo->prepare("SELECT id FROM payments WHERE id = ? AND seller_id = ? LIMIT 1");
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetchColumn()) { echo json_encode(['success'=>false,'message'=>'Payment not found.']); exit; }

    $pdo->prepare("
        UPDATE payments
        SET purchase_ref = ?, amount = ?, method = ?, notes = ?, paid_at = ?
        WHERE id = ? AND seller_id = ?
    ")->execute([$purchaseRef, $amount, $method, $notes, $paidAt . ' ' . date('H:i:s'), $id, $userId]);

    echo json_encode(['success'=>true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>'Database error: ' . $e->getMessage()]);
}