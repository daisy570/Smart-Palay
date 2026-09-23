<?php
// Beginner-friendly database setup
$host = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'smartpalay';

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
    die("Database connection failed: " . $e->getMessage());
}

function ensurePurchasesSchema(PDO $pdo): void {
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM purchases")->fetchAll(PDO::FETCH_COLUMN);
        $colSet = array_fill_keys(array_map('strtolower', $columns), true);

        if (!isset($colSet['buyer_name'])) {
            $pdo->exec("ALTER TABLE purchases ADD COLUMN buyer_name VARCHAR(150) NULL AFTER seller_id");
        }

        if (!isset($colSet['amount_paid'])) {
            $pdo->exec("ALTER TABLE purchases ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_amount");
        }

        $buyerIdInfo = $pdo->query("SHOW COLUMNS FROM purchases LIKE 'buyer_id'")->fetch(PDO::FETCH_ASSOC);
        if ($buyerIdInfo && stripos($buyerIdInfo['Type'] ?? '', 'int') !== false) {
            $nullable = strtolower((string) ($buyerIdInfo['Null'] ?? 'NO'));
            if ($nullable !== 'yes') {
                $pdo->exec("ALTER TABLE purchases MODIFY buyer_id INT NULL");
            }
        }
    } catch (Throwable $e) {
        // Ignore schema migration errors during startup if the table is unavailable.
    }
}

ensurePurchasesSchema($pdo);

// Base URL
define('BASE_URL', '/smartpalay/');

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