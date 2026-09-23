<?php
require_once __DIR__ . '/../config/database.php';
requireRole('admin');
$pageTitle = 'All Transactions';

$filter = $_GET['status'] ?? '';
$sql = "SELECT t.*, s.full_name AS seller_name, b.full_name AS buyer_name
        FROM transactions t
        JOIN users s ON s.id = t.seller_id
        JOIN users b ON b.id = t.buyer_id";
$params = [];
if (in_array($filter, ['pending','partial','paid','cancelled'])) {
    $sql .= " WHERE t.status = ?";
    $params[] = $filter;
}
$sql .= " ORDER BY t.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="sp-wrapper">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="sp-main">
<?php include __DIR__ . '/../includes/navbar.php'; ?>
<div class="sp-content">

    <div class="sp-card mb-3">
        <div class="row g-2">
            <div class="col-md-6">
                <input type="text" id="searchAdminTx" class="form-control" placeholder="Search transactions...">
            </div>
            <div class="col-md-6">
                <form method="GET" class="d-flex gap-2">
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <?php foreach (['pending','partial','paid','cancelled'] as $s): ?>
                            <option value="<?= $s ?>" <?= $filter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-brown" onclick="printReport(); return false;">
                        <i class="bi bi-printer"></i> Print
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="sp-table-wrap">
        <div class="table-responsive">
            <table class="table sp-table" id="adminTxTable">
                <thead>
                    <tr><th>Code</th><th>Seller</th><th>Buyer</th><th>Weight</th>
                        <th>Price/kg</th><th>Total</th><th>Paid</th><th>Balance</th>
                        <th>Status</th><th>Date</th></tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">No transactions found.</td></tr>
                <?php else: foreach ($rows as $t): ?>
                    <tr>
                        <td><code><?= sanitize($t['transaction_code']) ?></code></td>
                        <td><?= sanitize($t['seller_name']) ?></td>
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
<script>filterTable('searchAdminTx', 'adminTxTable');</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>