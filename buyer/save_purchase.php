<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit;
}
if (($_SESSION['role'] ?? '') !== 'buyer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$sellerName = trim((string)($input['seller_name'] ?? ''));
$weight     = (float)($input['weight_kg'] ?? 0);
$price      = (float)($input['price_per_kg'] ?? 0);
$amountPaid = (float)($input['amount_paid'] ?? 0);
$createdAt  = trim((string)($input['created_at'] ?? ''));
$notes      = trim((string)($input['notes'] ?? ''));

if ($sellerName === '' || $weight <= 0 || $price <= 0) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
    exit;
}

$total   = $weight * $price;
$balance = max(0, $total - $amountPaid);

if ($amountPaid <= 0)        $status = 'unpaid';
elseif ($balance <= 0)       $status = 'paid';
else                         $status = 'partial';

// Generate reference
$reference = 'PUR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

// Date: if empty use NOW()
$dateValue = $createdAt !== '' ? $createdAt . ' ' . date('H:i:s') : date('Y-m-d H:i:s');

try {
    $stmt = $pdo->prepare("
        INSERT INTO purchases
            (buyer_id, reference_no, seller_name, weight_kg, price_per_kg,
             total_amount, amount_paid, balance, status, notes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId, $reference, $sellerName, $weight, $price,
        $total, $amountPaid, $balance, $status, $notes, $dateValue
    ]);

    echo json_encode([
        'success'      => true,
        'message'      => 'Purchase saved.',
        'id'           => (int)$pdo->lastInsertId(),
        'reference_no' => $reference,
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
    ]);
}