-- SmartPalay Database Schema
CREATE DATABASE IF NOT EXISTS smartpalay CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smartpalay;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','seller','buyer') NOT NULL DEFAULT 'seller',
    phone VARCHAR(20),
    address TEXT,
    avatar VARCHAR(255) NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Sellers table (supports seller profiles AND buyer directory; extra columns added by app auto-migration)
CREATE TABLE IF NOT EXISTS sellers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    buyer_id INT NULL,
    name VARCHAR(150) NULL,
    contact VARCHAR(100) NULL,
    address TEXT NULL,
    notes TEXT NULL,
    farm_location VARCHAR(255),
    farm_size DECIMAL(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Buyers table (additional info)
CREATE TABLE IF NOT EXISTS buyers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    business_name VARCHAR(150),
    business_address TEXT,
    avatar VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Weighing records
CREATE TABLE IF NOT EXISTS weighing_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    weight_kg DECIMAL(10,2) NOT NULL,
    moisture_content DECIMAL(5,2) DEFAULT NULL,
    grade VARCHAR(20) DEFAULT NULL,
    notes TEXT,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Transactions (legacy ledger; modern code uses purchases)
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_code VARCHAR(50) NOT NULL UNIQUE,
    seller_id INT NOT NULL,
    buyer_id INT NOT NULL,
    weight_kg DECIMAL(10,2) NOT NULL,
    price_per_kg DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    payment DECIMAL(12,2) DEFAULT 0,
    remaining_balance DECIMAL(12,2) NOT NULL,
    status ENUM('pending','partial','paid','cancelled') DEFAULT 'pending',
    transaction_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES users(id),
    FOREIGN KEY (buyer_id) REFERENCES users(id)
);

-- Payments log (legacy columns kept nullable so modern purchases/payments inserts work)
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NULL,
    seller_id INT NULL,
    buyer_id INT NULL,
    purchase_id INT NULL,
    purchase_ref VARCHAR(50) NULL,
    reference_no VARCHAR(50) NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NULL,
    paid_at DATETIME NULL,
    method VARCHAR(20) DEFAULT 'cash',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
);

-- Modern purchases ledger (primary system used by seller/buyer/admin)
CREATE TABLE IF NOT EXISTS purchases (
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
);

-- Per-user preferences (seller/buyer settings pages)
CREATE TABLE IF NOT EXISTS user_preferences (
    user_id INT NOT NULL PRIMARY KEY,
    notify_email TINYINT(1) NOT NULL DEFAULT 1,
    notify_sms TINYINT(1) NOT NULL DEFAULT 0,
    notify_payments TINYINT(1) NOT NULL DEFAULT 1,
    notify_purchases TINYINT(1) NOT NULL DEFAULT 1,
    language VARCHAR(10) NOT NULL DEFAULT 'en',
    timezone VARCHAR(50) NOT NULL DEFAULT 'Asia/Manila',
    theme VARCHAR(20) NOT NULL DEFAULT 'warm'
);

-- Site-wide settings (admin/settings page)
CREATE TABLE IF NOT EXISTS settings (
    key_name VARCHAR(100) NOT NULL PRIMARY KEY,
    key_value TEXT NULL
);

CREATE TABLE IF NOT EXISTS login_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    logged_in_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Default admin (password: admin123)
INSERT IGNORE INTO users (full_name, email, password, role, status) VALUES
('System Administrator', 'admin@smartpalay.com', '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe1HlCS4bZJ18JuywdB4F1R8yF8YqBq7q', 'admin', 'active');

-- Email: admin@smartpalay.local
-- Password: admin123