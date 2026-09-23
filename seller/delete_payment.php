<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Not authenticated.']); exit; }
if (($_SESSION['role'] ?? '') !== 'seller') { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Seller access required.']); exit; }

$userId = (int) $_SESSION['user_id'];
$input  = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$id     = (int)($input['id'] ?? 0);

if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }

try {
    $stmt = $pdo->prepare("DELETE FROM payments WHERE id = ? AND seller_id = ?");
    $stmt->execute([$id, $userId]);
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Payment not found.']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error: ' . $e->getMessage()]);
}