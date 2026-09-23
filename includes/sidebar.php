<?php $user = currentUser(); ?>
<aside class="sp-sidebar" id="spSidebar">
    <div class="sp-brand">
        <img src="<?= BASE_URL ?>assets/images/logo.png" alt="SmartPalay" onerror="this.style.display='none'">
        <div>
            <h5>SmartPalay</h5>
            <small>Grain Trading System</small>
        </div>
    </div>

    <nav class="sp-nav">
        <?php if ($user['role'] === 'admin'): ?>
            <a href="<?= BASE_URL ?>admin/dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a href="<?= BASE_URL ?>admin/users.php"><i class="bi bi-people"></i> Users</a>
            <a href="<?= BASE_URL ?>admin/sellers.php"><i class="bi bi-person-badge"></i> Sellers</a>
            <a href="<?= BASE_URL ?>admin/buyers.php"><i class="bi bi-shop"></i> Buyers</a>
            <a href="<?= BASE_URL ?>admin/transactions.php"><i class="bi bi-receipt"></i> Transactions</a>
            <a href="<?= BASE_URL ?>admin/reports.php"><i class="bi bi-graph-up"></i> Reports</a>
        <?php elseif ($user['role'] === 'seller'): ?>
            <a href="<?= BASE_URL ?>seller/dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a href="<?= BASE_URL ?>seller/profile.php"><i class="bi bi-person-circle"></i> Profile</a>
            <a href="<?= BASE_URL ?>seller/weighing.php"><i class="bi bi-clipboard-data"></i> Weighing Records</a>
            <a href="<?= BASE_URL ?>seller/sales.php"><i class="bi bi-cash-coin"></i> Sales</a>
            <a href="<?= BASE_URL ?>seller/transactions.php"><i class="bi bi-receipt"></i> Transactions</a>
        <?php elseif ($user['role'] === 'buyer'): ?>
            <a href="<?= BASE_URL ?>buyer/dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a href="<?= BASE_URL ?>buyer/profile.php"><i class="bi bi-person-circle"></i> Profile</a>
            <a href="<?= BASE_URL ?>buyer/purchases.php"><i class="bi bi-bag-check"></i> Purchases</a>
            <a href="<?= BASE_URL ?>buyer/transactions.php"><i class="bi bi-receipt"></i> Transactions</a>
        <?php endif; ?>
        <hr>
        <a href="<?= BASE_URL ?>auth/logout.php" class="text-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </nav>
</aside>