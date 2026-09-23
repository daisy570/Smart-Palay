<?php
// ============================================================
// SmartPalay — Database + App Bootstrap
// Works on XAMPP (localhost) and InfinityFree.
// ------------------------------------------------------------
// HOW TO DEPLOY ON INFINITYFREE (see deployment-steps.md):
// 1. Control Panel > MySQL Databases > create DB, copy host/user/pass/name.
// 2. Put those 4 values below (replace localhost/root//smartpalay).
// 3. Upload files to htdocs/. Leave $APP_BASE_URL = '' for auto-detect.
// ============================================================
$host   = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'smartpalay';

// InfinityFree example (fill this in on the live site):
// $host   = 'sqlXXX.infinityfree.com';
// $dbUser = 'if0_12345678';
// $dbPass = 'YourDbPassword';
// $dbName = 'if0_12345678_smartpalay';

// Set to '' for auto-detect (recommended).
// Use '/' if files are directly in htdocs/, '/smartpalay/' if in htdocs/smartpalay/.
$APP_BASE_URL = '';

date_default_timezone_set('Asia/Manila');

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die("Database connection failed. Check config/database.php credentials and that the database exists.");
}

function sp_tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function sp_columnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->fetch();
    } catch (Throwable $e) { return false; }
}

function sp_execQuiet(PDO $pdo, string $sql): void {
    try { $pdo->exec($sql); } catch (Throwable $e) {}
}

// Creates missing tables and adds missing columns so a fresh
// InfinityFree import (database/smartpalay.sql) works without manual SQL.
function ensureAppSchema(PDO $pdo): void {
    try {
        // --- purchases (modern ledger, missing from old dump) ---
        if (!sp_tableExists($pdo, 'purchases')) {
            sp_execQuiet($pdo, "CREATE TABLE IF NOT EXISTS purchases (
                id INT AUTO_INCREMENT PRIMARY KEY,
                seller_id INT NULL,
                buyer_id INT NULL,
                buyer_name VARCHAR(150) NULL,
                seller_name VARCHAR(150) NULL,
                reference_no VARCHAR(50) NULL UNIQUE,
                weight_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
                price_per_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
                total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
                balance DECIMAL(12,2) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
                notes TEXT NULL,
                created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_purchases_seller (seller_id),
                INDEX idx_purchases_buyer (buyer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            if (!sp_columnExists($pdo, 'purchases', 'buyer_name')) sp_execQuiet($pdo, "ALTER TABLE purchases ADD COLUMN buyer_name VARCHAR(150) NULL AFTER seller_id");
            if (!sp_columnExists($pdo, 'purchases', 'seller_name')) sp_execQuiet($pdo, "ALTER TABLE purchases ADD COLUMN seller_name VARCHAR(150) NULL AFTER buyer_name");
            if (!sp_columnExists($pdo, 'purchases', 'amount_paid')) sp_execQuiet($pdo, "ALTER TABLE purchases ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_amount");
            if (!sp_columnExists($pdo, 'purchases', 'reference_no')) sp_execQuiet($pdo, "ALTER TABLE purchases ADD COLUMN reference_no VARCHAR(50) NULL AFTER seller_name");
            if (!sp_columnExists($pdo, 'purchases', 'notes')) sp_execQuiet($pdo, "ALTER TABLE purchases ADD COLUMN notes TEXT NULL");
            try {
                $info = $pdo->query("SHOW COLUMNS FROM purchases LIKE 'buyer_id'")->fetch(PDO::FETCH_ASSOC);
                if ($info && stripos($info['Type'] ?? '', 'int') !== false && strtolower((string)($info['Null'] ?? 'NO')) !== 'yes') {
                    sp_execQuiet($pdo, "ALTER TABLE purchases MODIFY buyer_id INT NULL");
                }
                $info2 = $pdo->query("SHOW COLUMNS FROM purchases LIKE 'seller_id'")->fetch(PDO::FETCH_ASSOC);
                if ($info2 && stripos($info2['Type'] ?? '', 'int') !== false && strtolower((string)($info2['Null'] ?? 'NO')) !== 'yes') {
                    sp_execQuiet($pdo, "ALTER TABLE purchases MODIFY seller_id INT NULL");
                }
            } catch (Throwable $e) {}
        }

        // --- payments: extend legacy table (transaction_id/payment_date) with modern columns ---
        if (sp_tableExists($pdo, 'payments')) {
            if (!sp_columnExists($pdo, 'payments', 'seller_id')) sp_execQuiet($pdo, "ALTER TABLE payments ADD COLUMN seller_id INT NULL AFTER id");
            if (!sp_columnExists($pdo, 'payments', 'buyer_id')) sp_execQuiet($pdo, "ALTER TABLE payments ADD COLUMN buyer_id INT NULL AFTER seller_id");
            if (!sp_columnExists($pdo, 'payments', 'purchase_id')) sp_execQuiet($pdo, "ALTER TABLE payments ADD COLUMN purchase_id INT NULL AFTER buyer_id");
            if (!sp_columnExists($pdo, 'payments', 'purchase_ref')) sp_execQuiet($pdo, "ALTER TABLE payments ADD COLUMN purchase_ref VARCHAR(50) NULL AFTER purchase_id");
            if (!sp_columnExists($pdo, 'payments', 'reference_no')) sp_execQuiet($pdo, "ALTER TABLE payments ADD COLUMN reference_no VARCHAR(50) NULL AFTER purchase_ref");
            if (!sp_columnExists($pdo, 'payments', 'paid_at')) sp_execQuiet($pdo, "ALTER TABLE payments ADD COLUMN paid_at DATETIME NULL AFTER notes");
            // Legacy columns stay untouched so old data is preserved.
        }

        // --- user_preferences (used by seller/buyer settings) ---
        if (!sp_tableExists($pdo, 'user_preferences')) {
            sp_execQuiet($pdo, "CREATE TABLE IF NOT EXISTS user_preferences (
                user_id INT NOT NULL PRIMARY KEY,
                notify_email TINYINT(1) NOT NULL DEFAULT 1,
                notify_sms TINYINT(1) NOT NULL DEFAULT 0,
                notify_payments TINYINT(1) NOT NULL DEFAULT 1,
                notify_purchases TINYINT(1) NOT NULL DEFAULT 1,
                language VARCHAR(10) NOT NULL DEFAULT 'en',
                timezone VARCHAR(50) NOT NULL DEFAULT 'Asia/Manila',
                theme VARCHAR(20) NOT NULL DEFAULT 'warm'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // --- settings (used by admin/settings) ---
        if (!sp_tableExists($pdo, 'settings')) {
            sp_execQuiet($pdo, "CREATE TABLE IF NOT EXISTS settings (
                key_name VARCHAR(100) NOT NULL PRIMARY KEY,
                key_value TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // --- users.avatar + buyers.avatar (used by profile pages) ---
        if (sp_tableExists($pdo, 'users') && !sp_columnExists($pdo, 'users', 'avatar')) {
            sp_execQuiet($pdo, "ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL AFTER address");
        }
        if (sp_tableExists($pdo, 'buyers') && !sp_columnExists($pdo, 'buyers', 'avatar')) {
            sp_execQuiet($pdo, "ALTER TABLE buyers ADD COLUMN avatar VARCHAR(255) NULL AFTER business_address");
        }

        // --- sellers table: dump defines seller-profile schema (user_id/farm_*),
        // --- but buyer/sellers.php needs directory schema (buyer_id/name/contact).
        // --- Keep both by adding missing columns; existing data is preserved.
        if (sp_tableExists($pdo, 'sellers')) {
            if (!sp_columnExists($pdo, 'sellers', 'buyer_id')) sp_execQuiet($pdo, "ALTER TABLE sellers ADD COLUMN buyer_id INT NULL AFTER user_id");
            if (!sp_columnExists($pdo, 'sellers', 'name')) sp_execQuiet($pdo, "ALTER TABLE sellers ADD COLUMN name VARCHAR(150) NULL AFTER buyer_id");
            if (!sp_columnExists($pdo, 'sellers', 'contact')) sp_execQuiet($pdo, "ALTER TABLE sellers ADD COLUMN contact VARCHAR(100) NULL AFTER name");
            if (!sp_columnExists($pdo, 'sellers', 'address')) sp_execQuiet($pdo, "ALTER TABLE sellers ADD COLUMN address TEXT NULL AFTER contact");
            if (!sp_columnExists($pdo, 'sellers', 'notes')) sp_execQuiet($pdo, "ALTER TABLE sellers ADD COLUMN notes TEXT NULL AFTER address");
            // Allow profile rows without user_id (buyer directory rows use buyer_id instead).
            try {
                $u = $pdo->query("SHOW COLUMNS FROM sellers LIKE 'user_id'")->fetch(PDO::FETCH_ASSOC);
                if ($u && strtolower((string)($u['Null'] ?? 'NO')) !== 'yes') {
                    sp_execQuiet($pdo, "ALTER TABLE sellers MODIFY user_id INT NULL");
                }
            } catch (Throwable $e) {}
        }

        // --- uploads folder (avatars disappear on GitHub ZIP if empty) ---
        $avatarDir = dirname(__DIR__) . '/uploads/avatars/';
        if (!is_dir($avatarDir)) { @mkdir($avatarDir, 0755, true); }
    } catch (Throwable $e) {}
}

function ensurePurchasesSchema(PDO $pdo): void {
    ensureAppSchema($pdo);
}

ensureAppSchema($pdo);

// Base URL: '' = auto-detect from document root (XAMPP + InfinityFree safe).
if (!defined('BASE_URL')) {
    $detectedBase = '/smartpalay/';
    if ($APP_BASE_URL !== '') {
        $detectedBase = '/' . trim($APP_BASE_URL, '/') . '/';
        if ($detectedBase === '//') $detectedBase = '/';
    } elseif (PHP_SAPI !== 'cli' && isset($_SERVER['DOCUMENT_ROOT'])) {
        $docRoot = rtrim(str_replace('\\', '/', (string)$_SERVER['DOCUMENT_ROOT']), '/');
        $appRoot = str_replace('\\', '/', dirname(__DIR__));
        if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
            $rel = trim(substr($appRoot, strlen($docRoot)), '/');
            $detectedBase = $rel === '' ? '/' : '/' . $rel . '/';
        }
    }
    define('BASE_URL', $detectedBase);
}

if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_URI'])) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
    $basePath = rtrim(BASE_URL, '/');

    if (stripos($requestPath, $basePath) === 0) {
        $nextPath = substr($requestPath, strlen($basePath));

        if ($nextPath === '' || $nextPath[0] === '/') {
            $canonicalPath = BASE_URL . ltrim($nextPath, '/');

            if ($requestPath !== $canonicalPath) {
                $query = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
                    ? '?' . $_SERVER['QUERY_STRING']
                    : '';
                header('Location: ' . $canonicalPath . $query, true, 301);
                exit;
            }
        }
    }
}

define('LOGIN_COOKIE_NAME', 'smartpalay_remember_me');
define('LOGIN_COOKIE_LIFETIME', 30 * 24 * 60 * 60);
define('LOGIN_COOKIE_KEY', 'smartpalay-remember-me-v1');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getUserSchemaColumns() {
    static $schema = null;

    if ($schema !== null) {
        return $schema;
    }

    $schema = [
        'id' => 'id',
        'password' => 'password',
        'role' => 'role',
    ];

    try {
        $columns = $GLOBALS['pdo']->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN, 0);
        $lower = array_map('strtolower', $columns);

        if (in_array('user_id', $lower, true)) {
            $schema['id'] = 'user_id';
        } elseif (in_array('id', $lower, true)) {
            $schema['id'] = 'id';
        }

        if (in_array('password_hash', $lower, true)) {
            $schema['password'] = 'password_hash';
        } elseif (in_array('password', $lower, true)) {
            $schema['password'] = 'password';
        }

        if (in_array('user_type', $lower, true)) {
            $schema['role'] = 'user_type';
        } elseif (in_array('role', $lower, true)) {
            $schema['role'] = 'role';
        }
    } catch (Throwable $e) {
        // Ignore detection errors and fall back to the default field names.
    }

    return $schema;
}

function normalizeUserRole($role) {
    return strtolower(trim((string) ($role ?? '')));
}

function normalizeUserRow($user) {
    if (!is_array($user)) {
        return [];
    }

    $schema = getUserSchemaColumns();
    $normalized = $user;
    $normalized['id'] = (int) ($user[$schema['id']] ?? $user['id'] ?? $user['user_id'] ?? 0);
    $normalized['password'] = $user[$schema['password']] ?? $user['password'] ?? $user['password_hash'] ?? '';
    $normalized['role'] = normalizeUserRole($user[$schema['role']] ?? $user['role'] ?? $user['user_type'] ?? '');

    return $normalized;
}

function clearRememberMeCookie() {
    if (PHP_VERSION_ID >= 70300) {
        setcookie(LOGIN_COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        setcookie(LOGIN_COOKIE_NAME, '', time() - 3600, '/', '', false, true);
    }
    unset($_COOKIE[LOGIN_COOKIE_NAME]);
}

function setRememberMeCookie($userId, $email) {
    $expiresAt = time() + LOGIN_COOKIE_LIFETIME;
    $payload = (int) $userId . '|' . $expiresAt;
    $signature = hash_hmac('sha256', $payload, LOGIN_COOKIE_KEY);
    $value = (int) $userId . '|' . $expiresAt . '|' . $signature;

    if (PHP_VERSION_ID >= 70300) {
        setcookie(LOGIN_COOKIE_NAME, $value, [
            'expires' => $expiresAt,
            'path' => '/',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        setcookie(LOGIN_COOKIE_NAME, $value, $expiresAt, '/', '', false, true);
    }
}

function bootstrapRememberedLogin($pdo) {
    if (isLoggedIn()) {
        return true;
    }

    if (empty($_COOKIE[LOGIN_COOKIE_NAME])) {
        return false;
    }

    $cookieValue = $_COOKIE[LOGIN_COOKIE_NAME];
    $parts = explode('|', $cookieValue);
    if (count($parts) !== 3) {
        clearRememberMeCookie();
        return false;
    }

    [$userId, $expiresAt, $signature] = $parts;
    $userId = (int) $userId;
    $expiresAt = (int) $expiresAt;

    if ($userId <= 0 || $expiresAt < time()) {
        clearRememberMeCookie();
        return false;
    }

    $expectedSignature = hash_hmac('sha256', $userId . '|' . $expiresAt, LOGIN_COOKIE_KEY);
    if (!hash_equals($expectedSignature, $signature)) {
        clearRememberMeCookie();
        return false;
    }

    $schema = getUserSchemaColumns();
    $idField = $schema['id'];

    $stmt = $pdo->prepare(
        "SELECT * FROM users WHERE {$idField} = ? AND status = 'active' LIMIT 1"
    );
    $stmt->execute([$userId]);
    $user = normalizeUserRow($stmt->fetch());

    if (!$user || empty($user['id'])) {
        clearRememberMeCookie();
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];

    return true;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'auth/login.php');
        exit;
    }
}

function requireRole($role) {
    requireLogin();
    if ($_SESSION['role'] !== $role) {
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
}

function currentUser() {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'name' => $_SESSION['full_name'] ?? '',
        'email' => $_SESSION['email'] ?? '',
        'role' => $_SESSION['role'] ?? ''
    ];
}

function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function formatMoney($amount) {
    return '₱' . number_format((float)$amount, 2);
}

function formatWeight($kg) {
    return number_format((float)$kg, 2) . ' kg';
}

function generateTransactionCode() {
    return 'SP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}