<?php
require_once __DIR__ . '/../config/database.php';
requireRole('seller');
$pageTitle = 'Weighing Records';
$uid = $_SESSION['user_id'];
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $weight = (float)($_POST['weight_kg'] ?? 0);
    $moisture = $_POST['moisture_content'] !== '' ? (float)$_POST['moisture_content'] : null;
    $grade = trim($_POST['grade'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($weight <= 0) {
        $msg = ['danger', 'Weight must be greater than zero.'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO weighing_records (seller_id, weight_kg, moisture_content, grade, notes) VALUES (?,?,?,?,?)");
        $stmt->execute([$uid, $weight, $moisture, $grade, $notes]);
        $msg = ['success', 'Weighing record saved successfully.'];
    }
}

$records = $pdo->prepare("SELECT * FROM weighing_records WHERE seller_id = ? ORDER BY recorded_at DESC");
$records->execute([$uid]);
$records = $records->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="sp-wrapper">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="sp-main">
<?php include __DIR__ . '/../includes/navbar.php'; ?>
<div class="sp-content">

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg[0] ?> alert-dismissible fade show"><?= sanitize($msg[1]) ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="sp-card">
                <div class="sp-card-title"><i class="bi bi-plus-circle"></i> Record Weight</div>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Weight (kg) *</label>
                        <input type="number" step="0.01" name="weight_kg" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Moisture Content (%)</label>
                        <input type="number" step="0.01" name="moisture_content" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Grade</label>
                        <select name="grade" class="form-select">
                            <option value="">— Select —</option>
                            <option>Premium</option><option>Grade A</option>
                            <option>Grade B</option><option>Grade C</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                    <button class="btn btn-terracotta w-100"><i class="bi bi-save"></i> Save Record</button>
                </form>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="sp-card mb-3">
                <input type="text" id="searchWeigh" class="form-control" placeholder="Search records...">
            </div>
            <div class="sp-table-wrap">
                <div class="table-responsive">
                    <table class="table sp-table" id="weighTable">
                        <thead>
                            <tr><th>#</th><th>Weight</th><th>Moisture</th><th>Grade</th><th>Notes</th><th>Date</th></tr>
                        </thead>
                        <tbody>
                        <?php if (!$records): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No records yet.</td></tr>
                        <?php else: foreach ($records as $i => $r): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= formatWeight($r['weight_kg']) ?></strong></td>
                                <td><?= $r['moisture_content'] !== null ? $r['moisture_content'] . '%' : '—' ?></td>
                                <td><?= sanitize($r['grade'] ?: '—') ?></td>
                                <td><?= sanitize($r['notes'] ?: '—') ?></td>
                                <td><?= date('M d, Y H:i', strtotime($r['recorded_at'])) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>
<script>filterTable('searchWeigh', 'weighTable');</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>