# SmartPalay — Palay Sales & Weight Record Management System

> Grain trading system for palay farmers (sellers), traders (buyers), and system administrators. Tracks weighing records, sales/purchases, payments, balances, and reports.

**Stack:** Vanilla PHP 7.4+/8.x, PDO MySQL, Bootstrap 5.3.2, Bootstrap Icons, Chart.js 4.4.0, vanilla JS. No framework.
**Base URL:** `/smartpalay/` — see `config/database.php`.
**Local path:** `C:\xampp\htdocs\smartpalay`

---

## Table of Contents

1. [Requirements](#1-requirements)
2. [Installation](#2-installation)
3. [Configuration](#3-configuration)
4. [Default Accounts](#4-default-accounts)
5. [Project Structure](#5-project-structure)
6. [Entry & Routing Flow](#6-entry--routing-flow)
7. [Database Schema](#7-database-schema)
8. [Auth & Security](#8-auth--security)
9. [Core Business Logic / Algorithms](#9-core-business-logic--algorithms)
10. [Seller Module](#10-seller-module-seller)
11. [Buyer Module](#11-buyer-module-buyer)
12. [Admin Module](#12-admin-module-admin)
13. [Root Shared Pages](#13-root-shared-pages)
14. [Shared Layout & Assets](#14-shared-layout--assets)
15. [JSON APIs](#15-json-apis)
16. [Reports Logic](#16-reports-logic)
17. [Roles & Permissions Matrix](#17-roles--permissions-matrix)
18. [Known Limitations / Tech Debt](#18-known-limitations--tech-debt)
19. [Troubleshooting](#19-troubleshooting)

---

## 1. Requirements

- XAMPP (Apache + MySQL/MariaDB) or any PHP + MySQL host
- PHP >= 7.4 (tested on PHP 8.x, uses `random_bytes()`, null coalescing, `password_hash()`)
- MySQL 5.7+/MariaDB 10.x with `utf8mb4`
- Apache with `mod_rewrite` optional (uses plain `.php` URLs, no rewrite required)
- Writable `uploads/avatars/` for profile images

## 2. Installation

1. Copy project to web root:
   ```
   C:\xampp\htdocs\smartpalay\
   ```
2. Start Apache + MySQL in XAMPP Control Panel.
3. Create database:
   - Open `http://localhost/phpmyadmin`
   - Import `database/smartpalay.sql` (creates `smartpalay` DB + `users,sellers,buyers,weighing_records,transactions,payments,login_logs` + default admin).
   - Runtime tables `purchases`, `user_preferences`, `settings` are auto-created/migrated on first page load via `ensurePurchasesSchema()` in `config/database.php`. If `purchases` does not exist, create it (see schema below) — the migrator only alters, it does not create from scratch on all installs.
4. Verify DB credentials in `config/database.php`:
   ```php
   $host='localhost'; $dbUser='root'; $dbPass=''; $dbName='smartpalay';
   ```
5. Open `http://localhost/smartpalay/` → redirects to `auth/login.php` if logged out, else role dashboard.

No `composer install` / `npm install` needed. CDN assets require internet (Bootstrap, Icons, Chart.js, Google Fonts Fraunces+Inter).

## 3. Configuration

`config/database.php` is the bootstrap included by every page:

- PDO connection with `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false`.
- `BASE_URL = '/smartpalay/'` — change if deployed in subdirectory/domain root.
- Canonical redirect: normalizes `/smartpalay*` trailing-slash URLs with 301.
- `session_start()` on every request.
- Helpers: `isLoggedIn()`, `requireLogin()`, `requireRole($role)`, `currentUser()`, `sanitize()`, `formatMoney()` (₱), `formatWeight()` (kg), `generateTransactionCode()` (`SP-YYYYMMDD-XXXXXX`).
- Schema-flex: `getUserSchemaColumns()` detects `id|user_id`, `password|password_hash`, `role|user_type` in `users`; `normalizeUserRow()` / `normalizeUserRole()` normalize them. Allows DB renames without code change.
- `ensurePurchasesSchema()` adds `purchases.buyer_name VARCHAR(150) NULL`, `purchases.amount_paid DECIMAL(12,2) DEFAULT 0`, makes `purchases.buyer_id INT NULL` if missing.

Cookies: `smartpalay_remember_me = userId|expiresAt|hmac_sha256(payload, key)` — `HttpOnly, SameSite=Lax, Secure=false, 30 days`.

## 4. Default Accounts

Seeded in `database/smartpalay.sql`:

| Role  | Email                  | Password   | Note                          |
|-------|------------------------|------------|-------------------------------|
| admin | `admin@smartpalay.com` | `admin123` | hash in SQL; comment also lists `admin@smartpalay.local` |

Sellers/buyers self-register via `auth/register.php` (roles `seller|buyer` only). Admins cannot self-register; create users via `admin/users.php` (roles `buyer|farmer`).

## 5. Project Structure

```
smartpalay/
├── index.php                 # role router
├── profile.php               # buyer-only profile (standalone layout)
├── settings.php              # buyer-only prefs (session only)
├── config/database.php       # PDO + session + auth helpers + constants
├── database/smartpalay.sql   # base schema + admin seed
├── includes/
│   ├── header.php            # <head> + CSS
│   ├── sidebar.php           # role-conditional nav
│   ├── navbar.php            # topbar + role badge + user
│   └── footer.php            # footer + Bootstrap/Chart.js/JS
├── auth/
│   ├── login.php             # login + rate-limit + CSRF + remember-me
│   ├── register.php          # seller/buyer self-registration
│   └── logout.php            # session destroy
├── seller/                   # role=seller
│   ├── dashboard.php         # KPIs + recent sales/payments
│   ├── sales.php             # filterable sales ledger
│   ├── save_sale.php         # JSON create sale
│   ├── update_sale.php       # JSON update sale
│   ├── delete_sale.php       # JSON delete sale
│   ├── payments.php          # payments-received ledger
│   ├── save_payment.php      # JSON create payment + apply to sale
│   ├── update_payment.php    # JSON edit payment meta
│   ├── delete_payment.php    # JSON delete payment
│   ├── buyers.php            # aggregated buyer directory
│   ├── transactions.php      # LEGACY transactions list
│   ├── weighing.php          # LEGACY weighing log
│   ├── reports.php           # seller analytics
│   ├── profile.php           # edit profile + avatar + password
│   └── settings.php          # password + user_preferences
├── buyer/                    # role=buyer (mirror of seller)
│   ├── dashboard.php         # KPIs + New Purchase modal (self-POST)
│   ├── purchases.php         # My Purchases ledger
│   ├── save_purchase.php     # JSON create purchase
│   ├── payments.php          # payments ledger
│   ├── sellers.php           # seller directory (sellers table or fallback)
│   ├── reports.php           # personal analytics
│   ├── profile.php           # profile + avatar + buyers table sync
│   └── settings.php          # profile + password + user_preferences
├── admin/                    # role=admin (system-wide)
│   ├── dashboard.php         # 6 stat cards + recent activity
│   ├── users.php             # full user CRUD + avatar
│   ├── purchases.php         # global purchase CRUD + quick_status
│   ├── payments.php          # global payments CRUD
│   ├── transactions.php      # LEGACY all transactions
│   ├── reports.php           # period report + deltas + CSV
│   └── settings.php          # site config (settings K/V + admins + logo)
├── assets/
│   ├── css/style.css         # wheat/brown theme, cards, tables, auth, print
│   └── js/script.js          # sidebar, calc, filter, alerts, print
├── images/                   # logo + illustrations
└── uploads/avatars/          # user avatars (writable)
```

## 6. Entry & Routing Flow

```
GET /smartpalay/ (index.php)
 ├─ !isLoggedIn() → /smartpalay/auth/login.php
 ├─ role=admin  → /smartpalay/admin/dashboard.php
 ├─ role=seller → /smartpalay/seller/dashboard.php
 └─ role=buyer  → /smartpalay/buyer/dashboard.php
```

Guards:
- Modern pages: `if(!isLoggedIn()) header(auth/login.php); if($_SESSION['role']!=='X') header(index.php);`
- Legacy pages (`seller/transactions.php`, `seller/weighing.php`, `admin/transactions.php`): `requireRole('seller'|'admin')`.
- JSON APIs (`save_*.php`, `update_*.php`, `delete_*.php`): return `401 Not logged in` / `403 Unauthorized` with `Content-Type: application/json`.

Ownership scoping: every seller query has `WHERE seller_id=? ($userId)` + `AND id=? AND seller_id=?` on update/delete. Buyer mirrors with `buyer_id=?`. Admin has no scope.

## 7. Database Schema

### 7.1 Dump schema (`database/smartpalay.sql`)

```sql
users(id PK, full_name, email UNIQUE, password(hash), role ENUM admin|seller|buyer DEFAULT seller,
      phone, address, status ENUM active|inactive DEFAULT active, created_at)
sellers(id PK, user_id FK users(id) CASCADE, farm_location, farm_size, created_at)
buyers(id PK, user_id FK users(id) CASCADE, business_name, business_address, created_at)
weighing_records(id PK, seller_id FK users(id) CASCADE, weight_kg DECIMAL(10,2),
                 moisture_content DECIMAL(5,2) NULL, grade VARCHAR(20) NULL, notes, recorded_at)
transactions(id PK, transaction_code UNIQUE, seller_id FK users(id), buyer_id FK users(id),
             weight_kg, price_per_kg, total_amount, payment DEFAULT 0, remaining_balance,
             status ENUM pending|partial|paid|cancelled DEFAULT pending, transaction_date DATE, notes, created_at)
payments_LEGACY(id PK, transaction_id FK transactions(id) CASCADE, amount, payment_date DATE,
                method ENUM cash|bank|gcash|other DEFAULT cash, notes, created_at)
login_logs(id PK, user_id FK users(id) CASCADE, ip_address, user_agent, logged_in_at)
```

Relations: `users 1:N sellers|buyers|weighing_records|transactions(seller+buyer)|login_logs`; `transactions 1:N payments`. `transactions.seller_id/buyer_id` restrict delete; others cascade.

### 7.2 Runtime schema (live DB, not in dump)

```sql
-- Modern ledger (primary system)
purchases(id PK, seller_id INT, buyer_id INT NULL, buyer_name VARCHAR(150) NULL, seller_name VARCHAR(150) NULL,
          reference_no VARCHAR UNIQUE [SAL-|PUR-YYYYMMDD-XXXXXX], weight_kg DECIMAL, price_per_kg DECIMAL,
          total_amount DECIMAL(12,2), amount_paid DECIMAL(12,2) DEFAULT 0, balance DECIMAL(12,2),
          status VARCHAR [paid|partial|unpaid|pending], notes TEXT, created_at DATETIME)
payments(id PK, seller_id INT NULL, buyer_id INT NULL, purchase_ref VARCHAR NULL [→ purchases.reference_no],
         reference_no VARCHAR UNIQUE [PAY-YYYYMMDD-XXXXXX], amount DECIMAL(12,2), method VARCHAR [cash|bank|gcash|maya|check|other],
         notes TEXT, paid_at DATETIME, created_at DATETIME)
sellers (buyer-owned directory, optional — detected via SELECT 1 FROM sellers LIMIT 1)
  (id PK, buyer_id INT, name VARCHAR, contact VARCHAR, address TEXT, notes TEXT)
user_preferences(user_id PK, notify_email|notify_sms|notify_payments|notify_purchases TINYINT,
                 language ENUM en|tl, timezone VARCHAR DEFAULT Asia/Manila, theme ENUM warm|light|dark)
settings(key_name PK, key_value TEXT) -- site_name, tagline, contact_email, phone, address,
  -- default_price, low_stock, currency, symbol, items_per_page, date_format, timezone,
  -- maintenance, allow_registration, email_notifications, auto_refresh, site_logo, admin_*
admins(id PK, full_name, email, phone, password) -- used by admin/settings.php if table exists
```

`purchases` is the active ledger; `transactions` is legacy (only `*/transactions.php` + `seller/weighing.php` use it).

## 8. Auth & Security

| File | Behavior |
|------|----------|
| `auth/login.php` | Split brand+form UI. `bootstrapRememberedLogin()` auto-login via cookie. CSRF token `csrf_login` (`random_bytes(32)`). Sliding 15-min window, `MAX 5` attempts stored in `$_SESSION['login_attempts']`, lockout `unlockAt=min+900s`, `attemptsLeft` warning when ≤2. Dummy bcrypt hash when user not found. `password_verify()`, `password_needs_rehash()` → `UPDATE users`. Inactive users rejected with distinct message. Success: `session_regenerate_id(true)`, sets `user_id,full_name,email,role`, `setRememberMeCookie()` if checked else clear, `INSERT login_logs(user_id,ip,user_agent)`, redirect `index.php`. |
| `auth/register.php` | Logged-in users bounced to `index.php`. Allows `role=seller|buyer` only. Validates `full_name>=2 chars, valid email, password>=8, confirm match`. Dup check `SELECT id WHERE email`. Adaptive `INSERT` (password vs password_hash, role vs user_type cols). `password_hash(PASSWORD_DEFAULT)`, `status=active`. Sets `register_success/register_email` session → `login.php?registered=1`. No auto-login. |
| `auth/logout.php` | `session_unset()+session_destroy()` → `auth/login.php`. No CSRF. |
| `config/database.php` | `sanitize()` (`htmlspecialchars+trim`), PDO prepared statements everywhere, HMAC-signed remember-me, `hash_equals()` compare, `HttpOnly+Lax` cookies. |

Passwords: `password_hash()` / `password_verify()`. Avatars validated via `finfo` MIME (`jpeg|png|webp` + `gif` in admin), size 2–3MB, random filename, old file `unlink()`.

## 9. Core Business Logic / Algorithms

All monetary values `DECIMAL(12,2)`, weight `DECIMAL(10,2)`, displayed via `formatMoney()` (`₱1,234.00`) and `formatWeight()` (`123.00 kg`).

```
total_amount = weight_kg * price_per_kg
balance      = max(0, total_amount - amount_paid)
status       = balance<=0 ? 'paid' : (amount_paid>0 ? 'partial' : 'unpaid')
               // admin create forces 'pending' variant when paid==0
               // legacy transactions uses pending|partial|paid|cancelled + payment/remaining_balance cols
reference_no = SAL-|PUR-|PAY- + YYYYMMDD + '-' + strtoupper(bin2hex(random_bytes(3)))  // e.g. SAL-20260923-A1B2C3
created_at   = supplied Y-m-d + ' ' + current H:i:s, else NOW()
paidPct      = total_paid / (total_paid + outstanding) * 100
avg_price_kg = SUM(total_amount) / SUM(weight_kg)
```

Payment application (`seller/save_payment.php`): after `INSERT payments`, `SELECT purchases WHERE reference_no=? AND seller_id=?`, `newPaid=amount_paid+amount`, recompute `balance/status`, `UPDATE purchases`.

Buyer directory stats: `SUM(total_amount), SUM(balance) outstanding, SUM(weight_kg), COUNT(*)`, `MAX(created_at) last_sale`.

Pagination: `perPage=10` (sales/purchases/payments), `12` (buyer/sellers directory), `totalPages=ceil(count/perPage)`, clamp `page`.

Sorting: whitelisted column + `asc|desc` interpolated into `ORDER BY` (safe via whitelist). Search: `LIKE %q%` on `reference_no|buyer_name|seller_name|notes`.

## 10. Seller Module (`seller/`)

| File | Functionality |
|------|---------------|
| `dashboard.php` | KPIs: `COUNT(*), SUM(weight_kg/total_amount/balance), COUNT DISTINCT buyer_name WHERE seller_id=?`. Recent 6 sales + 5 payments. `totalValue=earned+outstanding`, `paidPct`. Quick actions + New Sale / Reports modals. Weekly chart is static dummy `[42,68,55,82,74,90,60]`. |
| `sales.php` | Sales ledger. `GET q,status[from paid|partial|unpaid|pending],from,to,sort[created_at|reference_no|buyer_name|weight_kg|total_amount|balance|status],dir,page`. Status tabs via `GROUP BY LOWER(status)`, filtered summary `SUM()`, paginated list, `qs()` preserves filters, `statusMeta()` badge map. View/edit/delete via JSON APIs. |
| `save_sale.php` | JSON create. Accepts JSON body or `$_POST`: `buyer_name,buyer_id,weight_kg,price_per_kg,amount_paid,created_at,notes`. Resolves `buyer_id` via `SELECT id FROM users WHERE role='buyer' AND full_name=?` if only name given. `SHOW COLUMNS` branch for legacy DBs without `buyer_name/amount_paid`. Computes total/balance/status/ref, `INSERT purchases`. Returns `{success,reference_no}`. |
| `update_sale.php` | Same + `id` required. Ownership `SELECT id WHERE id=? AND seller_id=?`. Recomputes totals, overwrites `created_at`. |
| `delete_sale.php` | `DELETE WHERE id=? AND seller_id=?`, checks `rowCount`. |
| `payments.php` | Payments-received ledger. `GET q,method[cash|bank|gcash|...],from/to on paid_at,sort[paid_at|reference_no|amount|method],page`. Summary `SUM/COUNT/AVG`, this-month `SUM WHERE MONTH+YEAR=CURDATE()`, method counts `GROUP BY LOWER(method)`, open sales picker `SELECT ... WHERE balance>0 LIMIT 100`. |
| `save_payment.php` | JSON create: `purchase_ref?,amount>0,method,paid_at,reference_no(auto),notes`. `INSERT payments`, auto-applies to linked sale (see §9). Silent inner try/catch. |
| `update_payment.php` | Edits payment meta only (`purchase_ref,amount,method,notes,paid_at`). Does NOT recalc linked sale (known bug). |
| `delete_payment.php` | Deletes payment. Does NOT reverse sale balance (known bug). |
| `buyers.php` | Buyer directory derived from sales: `SELECT buyer_name,COUNT,SUM(kg/total/paid/balance),MAX/MIN(created) ... GROUP BY buyer_name`. `GET q,sort[buyer_name|total_amount|sales_count|total_kg|outstanding|last_sale]`. Summary strip, Top-3 badge when `sort=total_amount DESC`, detail modal (per-buyer history via JS), New Buyer modal posts to `save_sale.php`. |
| `transactions.php` | LEGACY. Uses `includes/*` layout. `SELECT t.*,b.full_name buyer_name FROM transactions t JOIN users b WHERE seller_id=?`. Client-side `filterTable()` only. Uses `transaction_code,payment,remaining_balance,transaction_date`. |
| `weighing.php` | LEGACY. `POST weight_kg>0,moisture_content?,grade[Premium|A|B|C],notes` → `INSERT weighing_records`. Lists `SELECT * WHERE seller_id=? ORDER BY recorded_at DESC`. No PRG redirect. |
| `reports.php` | Analytics. `GET preset[all|today|week|month|quarter|year]+from/to`. Week=Mon–today, quarter=first day of -2mo–today. Dual queries on `purchases.created_at` + `payments.paid_at`. Monthly `GROUP BY %Y-%m LIMIT 12` (fallback 6 empty months), status/method breakdowns, top 8 buyers, recent 5+5. CSV export via JS. |
| `profile.php` | `requireRole('seller')`. `action=update_profile(full_name*,phone,address)`, `upload_avatar(2MB,jpg/png/webp, avatar_<uid>_<hex>)`, `remove_avatar`, `change_password(current,new>=6,confirm)`. Syncs `$_SESSION[full_name]`. |
| `settings.php` | `change_password` (same) + `save_preferences(notify_email|sms|payments|purchases,language en|tl,timezone,theme warm|light|dark)` → `INSERT ... ON DUPLICATE KEY UPDATE user_preferences`. |

## 11. Buyer Module (`buyer/`)

Mirror image: owns `buyer_id`, counterparty is free-text `seller_name`.

| File | Functionality (differences from seller) |
|------|------------------------------------------|
| `dashboard.php` | Hero + 4 stat cards (`COUNT,SUM(weight),SUM(total-balance) AS paid,SUM(balance) WHERE buyer_id=?`), next-action, recent 6 purchases + 5 payments, progress, timeline. `POST action=create_purchase(seller_name,weight,price,paid,created_at,notes)` inserts directly (stores `reference_no=PUR-*`, computes total/balance/status). New Purchase modal posts to self. |
| `purchases.php` | My Purchases ledger. Same `q,status,from,to,sort,page` pattern but on `seller_name`. Read-only; creation via dashboard/`save_purchase.php`. Summary uses `SUM(amount_paid)` (dashboard uses `total-balance`). |
| `save_purchase.php` | JSON alternative to dashboard POST. Validates `seller!='',weight>0,price>0`. Explicitly stores `amount_paid`. Status order `paid<=0→unpaid; balance<=0→paid; else partial`. Returns `{success,id,reference_no}`. |
| `payments.php` | Same as seller but `WHERE buyer_id=?`. Open-balance picker `SELECT ... WHERE buyer_id=? AND balance>0 LIMIT 100`. |
| `sellers.php` | Counterpart directory. Detects `sellers` table (`SELECT 1 ... LIMIT 1`). Full CRUD `POST create|update|delete(name,contact,address,notes,id,old_name)` scoped `buyer_id`. Rename propagates `UPDATE purchases SET seller_name=?`. List: `sellers LEFT JOIN (GROUP BY seller_name) agg` or fallback `GROUP BY seller_name`. PHP filter/sort/paginate (`perPage=12`). |
| `reports.php` | Same as seller but `WHERE buyer_id=?`. Adds `AVG(total_amount)`, `COUNT DISTINCT seller_name`, `avg_price`. No deltas/export. |
| `profile.php` | `requireRole('buyer')`. Same avatar/profile/password as seller + syncs `buyers(business_name,business_address,avatar)` (upsert `SELECT→UPDATE else INSERT`). |
| `settings.php` | Widest buyer settings: `update_profile(full_name,email unique check,phone,address)` + `change_password` + `save_preferences` (same keys as seller). |

## 12. Admin Module (`admin/`)

System-wide, no ownership scope.

| File | Functionality |
|------|---------------|
| `dashboard.php` | 6 cards: `COUNT users`, `COUNT WHERE role=farmer|buyer`, `COUNT/SUM(purchases)`, `SUM(weight_kg/total/balance)`. Recent 8 purchases (`buyer_name+seller_name`), 6 payments, 6 users, role breakdown `GROUP BY role`, `paidPct`. |
| `users.php` | User mgmt. `GET q,role[admin|buyer|farmer],page`. `POST create|update|delete(full_name,email,password,role=buyer|farmer,contact/address,id, FILES profile_image 3MB jpg/png/webp/gif)`. Detects image col via `SHOW COLUMNS`. Email uniqueness, hashes password, locks `admin` role from change, blocks self-delete. Stats `GROUP BY role`. |
| `purchases.php` | Global ledger + full CRUD. `POST create|update|delete|quick_status(id,buyer_id*,seller_name*,weight*,price*,paid,status,created_at Y-m-d required,notes,reference_no)`. `GET q,status,buyer,from,to,page`. `LEFT JOIN users` for `buyer_name`, stats + this-month, buyer dropdown `WHERE role='buyer'`. `quick_status`: set `paid+balance=0` or arbitrary status. |
| `payments.php` | Global payments CRUD. `POST create|update|delete(id,buyer_id,purchase_id,amount>0,method[cash|bank_transfer|gcash|check|other],paid_at datetime-local,notes)`. Dynamic `INSERT` with optional cols. `LEFT JOIN users+purchases` for `buyer_name+purchase_ref`. Method breakdown, buyer list, open purchases `WHERE balance>0 OR status<>paid LIMIT 200`. |
| `transactions.php` | LEGACY (81 lines). `GET status[pending|partial|paid|cancelled]`. `SELECT t.*,s.full_name seller_name,b.full_name buyer_name FROM transactions JOIN users s/b`. Print only. Only file still on `transactions` table alongside seller version. |
| `reports.php` | Period report. `GET preset[month|last_month|quarter|year|last_year|custom],from,to,export=1`. Cur + prev period (`days`, `prevFrom/To`), `pct(cur,prev)`. Chart buckets `>45d→monthly else daily (max 14 bars)`. Top 6 buyers (`JOIN users GROUP BY buyer_id`) + top 6 sellers (`GROUP BY seller_name`), status/method splits, farmer/buyer counts. CSV with BOM + summary/top tables. |
| `settings.php` | Site config, 5 tabs (`general|account|password|logo|preferences`, `action=save_general|save_preferences|save_account|save_password|upload_logo|remove_logo|reset_defaults`). Keys: `site_name,tagline,contact_email,phone,address,default_price,low_stock,currency,symbol,items_per_page,date_format,timezone,maintenance,allow_registration,email_notifications,auto_refresh`. Account edits `admins` table if exists else `settings admin_*`. Logo upload 2MB (`png/jpg/webp/gif/svg` → `images/logo-*`). Header mini-stats `COUNT users/purchases/payments`. |

## 13. Root Shared Pages

| File | Behavior |
|------|----------|
| `index.php` | Role router (see §6). |
| `profile.php` | Buyer-only (`requireRole('buyer')`). Edits `users(full_name,phone,address)` + upsert `buyers(business_name,business_address)`. Standalone layout (does NOT use `includes/*`), hardcoded buyer sidebar. |
| `settings.php` | Buyer-only. Session-only prefs `$_SESSION[settings_saved,pref_email/sms/push]` — `settings_saved` never unset so success alert persists. Same standalone layout. |

## 14. Shared Layout & Assets

- `includes/header.php`: requires `config/database.php`, `<!DOCTYPE>`, `$pageTitle ?? 'SmartPalay'`, Bootstrap 5.3.2 + Icons CDN, `assets/css/style.css`.
- `includes/sidebar.php`: brand (`assets/images/logo.png` + SmartPalay/Grain Trading System), role nav: admin (`dashboard,users,sellers,buyers,transactions,reports`), seller (`dashboard,profile,weighing,sales,transactions`), buyer (`dashboard,profile,purchases,transactions`) + `auth/logout.php`. Only dashboard has active state.
- `includes/navbar.php`: `#sidebarToggle (mobile)`, `<h4> $pageTitle`, role badge `role-{role}`, avatar initial + name via `currentUser()`.
- `includes/footer.php`: closes `.sp-wrapper/.sp-main`, `© year SmartPalay`, Bootstrap bundle + Chart.js + `assets/js/script.js`.
- `assets/css/style.css` (291 lines): vars `--wheat:#E3B448 --beige --cream:#FBF6EA --brown:#5B3A1E --brown-dark --terracotta:#C8622E --offwhite --border`, `.sp-sidebar(250px fixed brown)`, `.sp-main(margin-left:250px)`, `.sp-navbar(sticky)`, `.sp-card/.sp-stat(accent bar+hover lift)`, `.sp-table-wrap(brown thead)`, badges `paid|partial|pending|cancelled|active|inactive`, `btn-terracotta|wheat|outline-brown`, auth pages, print hides sidebar/navbar/footer, `<991px` collapses sidebar.
- `assets/js/script.js` (65 lines): sidebar toggle + outside-click close, `bindTotalCalculation(w,p,t,pay,bal)` (`total=w*p, balance=total-pay`), `confirmAction()`, `filterTable(input,table)` case-insensitive, auto-dismiss alerts 4s, `printReport()`.
- `profile.php`/`settings.php` (root) diverge: inline `<style>` gold/brown (`--gold:#B0641E --brown:#4A2C10 --sidebar-w:260px`), fixed sidebar + sticky topbar, hardcoded buyer links — changes to `style.css`/`sidebar.php` do not affect them.

## 15. JSON APIs

All require seller (or buyer for `buyer/save_purchase.php`), return `application/json`:

- `POST seller/save_sale.php` → `{success,reference_no}` | `{success:false,message}`
- `POST seller/update_sale.php {id,...}` → `{success}` | `Sale not found`
- `POST seller/delete_sale.php {id}` → `{success}` | `Sale not found`
- `POST seller/save_payment.php {purchase_ref?,amount,method,paid_at,reference_no?,notes}` → `{success,reference_no}` + side-effect on purchase
- `POST seller/update_payment.php {id,amount,...}` → `{success}` (no side-effect)
- `POST seller/delete_payment.php {id}` → `{success}` (no side-effect)
- `POST buyer/save_purchase.php {seller_name,weight_kg,price_per_kg,amount_paid,created_at,notes}` → `{success,id,reference_no}`

Accept `php://input` JSON with `$_POST` fallback.

## 16. Reports Logic

- Seller/buyer: `preset` computes `dateFrom/To` (today, Mon–today, `Y-m-01`–today, first-day-of-(-2mo)–today, `Y-01-01`–today, all). Explicit `from/to` override. Filter `DATE(created_at)` + `DATE(paid_at)`. Monthly trend `GROUP BY %Y-%m LIMIT 12`, status/method `GROUP BY LOWER()`, top N by `SUM(total_amount) DESC`.
- Admin: adds prev-period comparison, `pct()` deltas, adaptive daily/monthly bucketing, CSV export, new farmer/buyer counts.

## 17. Roles & Permissions Matrix

| Capability | Seller | Buyer | Admin |
|------------|--------|-------|-------|
| Register self | ✅ | ✅ | ❌ |
| Login/logout/remember-me | ✅ | ✅ | ✅ |
| Record weighing | ✅ (`weighing.php` legacy) | ❌ | ❌ |
| Create sale/purchase | ✅ (`sales/save_sale`) | ✅ (`dashboard/save_purchase`) | ✅ (any buyer) |
| Edit/delete own sale | ✅ (own `seller_id`) | ❌ (read-only ledger) | ✅ (any) |
| Record payment | ✅ (linked to own sale) | ✅ (own purchase) | ✅ (any) |
| Manage counterpart directory | ✅ (derived `buyers.php`) | ✅ (`sellers.php` CRUD) | via `users.php` |
| View reports | ✅ (own) | ✅ (own) | ✅ (global + deltas + CSV) |
| Edit own profile/avatar/password | ✅ | ✅ | ✅ (via settings) |
| Manage users/site settings | ❌ | ❌ | ✅ |

Strict equality, no hierarchy: `requireRole()` bounces cross-role to `index.php` which re-routes.

## 18. Known Limitations / Tech Debt

1. Dual ledgers: modern `purchases/payments` vs legacy `transactions` (+ `weighing_records`). Only `*/transactions.php` use legacy; data does not sync.
2. Free-text `buyer_name/seller_name` in `purchases` drifts from `users.full_name`; rename only propagates in `buyer/sellers.php`.
3. `update_payment.php` / `delete_payment.php` leave `purchases.amount_paid/balance/status` stale.
4. Seller dashboard weekly chart is hardcoded dummy data.
5. `profile.php`/`settings.php` (root) duplicate layout; `settings.php` success flag persists forever.
6. `admin/users.php` uses `role=farmer` while seed + guards use `seller` — farmer rows are invisible to seller dashboards.
7. `payments` table schema differs between dump (`transaction_id,payment_date`) and runtime (`seller_id,buyer_id,purchase_ref,paid_at`); fresh import without runtime migration breaks modern pages.
8. No CSRF on non-auth POSTs (sales/payments/profile/settings/admin CRUD), no PRG on `weighing.php`, `Secure=false` cookies, no rate-limit beyond login.

## 19. Troubleshooting

| Symptom | Fix |
|---------|-----|
| `Database connection failed` | Check MySQL running, `config/database.php` creds, DB `smartpalay` exists. |
| `Table purchases doesn't exist` | Create `purchases` per §7.2, then reload to trigger `ensurePurchasesSchema()`. |
| Redirect loop `/smartpalay` | Canonical redirect in `config/database.php:53` — access via `http://localhost/smartpalay/`. |
| Avatars not uploading | `chmod/chown uploads/avatars`, check 2MB limit, `fileinfo` extension. |
| Logged out after close | Check `remember me` checkbox; cookie is `HttpOnly+Lax`, 30d. |
| Farmer users invisible | They have `role=farmer`; seller flow expects `seller`. Update role to `seller` in `admin/users.php` or DB. |
| Balances wrong after payment edit | Manually fix `purchases.amount_paid/balance/status` — auto-recalc only on `save_payment.php`. |

---

*Generated from full codebase read: `config/database.php`, `database/smartpalay.sql`, `index.php`, `auth/*`, `seller/*`, `buyer/*`, `admin/*`, `includes/*`, `assets/*`, root `profile.php`/`settings.php`.*
