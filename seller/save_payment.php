<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Not authenticated.']); exit; }
if (($_SESSION['role'] ?? '') !== 'seller') { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Seller access required.']); exit; }

$userId = (int) $_SESSION['user_id'];
$input  = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$purchaseRef = trim((string)($input['purchase_ref'] ?? ''));
$amount      = (float)($input['amount']  ?? 0);
$method      = strtolower(trim((string)($input['method'] ?? 'cash')));
$paidAt      = trim((string)($input['paid_at'] ?? date('Y-m-d')));
$refNo       = trim((string)($input['reference_no'] ?? ''));
$notes       = trim((string)($input['notes'] ?? ''));

if ($amount <= 0) { echo json_encode(['success'=>false,'message'=>'Amount is required.']); exit; }

try {
    $refNo = $refNo ?: ('PAY-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3))));

    $pdo->prepare("
        INSERT INTO payments (seller_id, purchase_ref, reference_no, amount, method, notes, paid_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([$userId, $purchaseRef, $refNo, $amount, $method, $notes, $paidAt . ' ' . date('H:i:s')]);

    // If linked to a purchase, update its amount_paid & balance
    if ($purchaseRef !== '') {
        try {
            $stmt = $pdo->prepare("SELECT id, total_amount, amount_paid FROM purchases WHERE reference_no = ? AND seller_id = ? LIMIT 1");
            $stmt->execute([$purchaseRef, $userId]);
            if ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $newPaid = (float)$p['amount_paid'] + $amount;
                $balance = max(0, (float)$p['total_amount'] - $newPaid);
                $status  = $balance <= 0 ? 'paid' : ($newPaid > 0 ? 'partial' : 'unpaid');
                $pdo->prepare("UPDATE purchases SET amount_paid = ?, balance = ?, status = ? WHERE id = ?")
                    ->execute([$newPaid, $balance, $status, $p['id']]);
            }
        } catch (Throwable $e) {}
    }

    echo json_encode(['success'=>true, 'reference_no'=>$refNo]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>'Database error: ' . $e->getMessage()]);
}