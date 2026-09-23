<?php
require_once __DIR__ . '/../config/database.php';
requireRole('seller');
$pageTitle = 'My Transactions';
$uid = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT t.*, b.full_name AS buyer_name
    FROM transactions t JOIN users b ON b.id = t.buyer_id
    WHERE t.seller_id = ? ORDER BY t.created_at DESC
");
$stmt->execute([$uid]);
$rows = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="sp-wrapper">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="sp-main">
<?php include __DIR__ . '/../includes/navbar.php'; ?>
<div class="sp-content">

    <div class="sp-card mb-3">
        <input type="text" id="searchTx" class="form-control" placeholder="Search transactions...">
    </div>

    <div class="sp-table-wrap">
        <div class="table-responsive">
            <table class="table sp-table" id="txTable">
                <thead>
                    <tr>
                        <th>Code</th><th>Buyer</th><th>Weight</th><th>Price/kg</th>
                        <th>Total</th><th>Paid</th><th>Balance</th>
                        <th>Status</th><th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No transactions found.</td></tr>
                <?php else: foreach ($rows as $t): ?>
                    <tr>
                        <td><code><?= sanitize($t['transaction_code']) ?></code></td>
                        <td><?= sanitize($t['buyer_name']) ?></td>
                        <td><?= formatWeight($t['weight_kg']) ?></td>
                        <td><?= formatMoney($t['price_per_kg']) ?></td>
                        <td><strong><?= formatMoney($t['total_amount']) ?></strong></td>
                        <td><?= formatMoney($t['payment']) ?></td>
                        <td><?= formatMoney($t['remaining_balance']) ?></td>
                        <td><span class="sp-badge badge-<?= $t['status'] ?>"><?= $t['status'] ?></span></td>
                        <td><?= date('M d, Y', strtotime($t['transaction_date'])) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
<script>filterTable('searchTx', 'txTable');</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>