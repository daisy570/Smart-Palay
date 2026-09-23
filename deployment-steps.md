# Deploy SmartPalay on InfinityFree — Full Newbie Guide

This file explains how to put your localhost project online using InfinityFree (free PHP + MySQL hosting).

You already pushed to GitHub. InfinityFree free has **no GitHub connect button**, so we upload the GitHub ZIP manually. That is normal and takes ~10 minutes.

Live result: `https://yourdomain.infinityfreeapp.com` → login → seller/buyer/admin dashboards.

---

## 0. What was changed in the code so InfinityFree works with no hassle

I already fixed these deployment blockers for you. You do **not** need to code anything.

| # | Problem on fresh InfinityFree | Fix applied |
|---|-------------------------------|-------------|
| 1 | `config/database.php` had `BASE_URL = '/smartpalay/'` hardcoded. On InfinityFree `htdocs/` root this breaks all links/redirects. | `BASE_URL` now **auto-detects**: XAMPP `htdocs/smartpalay` → `/smartpalay/`, InfinityFree `htdocs/` → `/`. Override with `$APP_BASE_URL` if needed. File: `config/database.php` |
| 2 | DB credentials were `localhost/root//smartpalay`. InfinityFree uses `sqlXXX.infinityfree.com` + different user/pass/name. | Added labeled InfinityFree block at top of `config/database.php`. Just fill 4 values. |
| 3 | `database/smartpalay.sql` was missing `purchases`, `user_preferences`, `settings` tables. Site would white-screen after import. | Added `CREATE TABLE IF NOT EXISTS purchases, user_preferences, settings` to the SQL dump. Fresh import now works. |
| 4 | `payments` table required `transaction_id NOT NULL`, but modern code inserts without it → every sale/payment fails on live. | Legacy columns made `NULL`, modern columns (`seller_id, buyer_id, purchase_id, purchase_ref, reference_no, paid_at`) added to dump + auto-migration. Old data preserved. |
| 5 | `users.avatar` and `buyers.avatar` columns missing → profile picture upload crashes. | Auto-migration adds them if missing. |
| 6 | `sellers` table clash: dump had `user_id/farm_*`, but `buyer/sellers.php` needs `buyer_id/name/contact/address/notes` → buyer directory insert fails. | Auto-migration adds the 5 missing columns and allows `user_id NULL`. Both uses coexist. No data loss. |
| 7 | `smartpalay.sql` had two bare lines `Email: ... / Password: ...` without `--` → phpMyAdmin import **syntax error**. | Commented out + changed seed to `INSERT IGNORE`. Import now succeeds and can be re-run safely. All `CREATE TABLE` are now `IF NOT EXISTS`. |
| 8 | `auth/login.php` + `auth/register.php` always showed Google/Facebook/Microsoft buttons linking to `auth/oauth.php`, which does not exist → 404 on live. | Buttons now show only if `auth/oauth.php` exists (`is_file` check). On InfinityFree they auto-hide. |
| 9 | `uploads/avatars/` disappears when you download GitHub ZIP (Git ignores empty folders) → avatar upload fails on live. | `ensureAppSchema()` auto-creates the folder + added `uploads/index.html` and `uploads/avatars/index.html` to preserve it in Git and block directory listing. |
| 10 | No timezone set; PHP warnings differ between Windows and Linux. | Added `date_default_timezone_set('Asia/Manila')`. Linux file paths already use `__DIR__`, safe for InfinityFree. |

Verified locally: `php -l` clean on all edited files, `BASE_URL=/smartpalay/` auto-detected on XAMPP, all 7 tables present.

---

## 1. What you need before starting

- [ ] GitHub repo with this project pushed (you have this)
- [ ] InfinityFree account (free): https://infinityfree.com → Sign Up
- [ ] 10 minutes, no credit card needed

Key idea for newbies:

- **Files** (PHP/CSS/images) → go to InfinityFree `htdocs/` folder
- **Database** (tables/users) → created separately in Control Panel → imported via phpMyAdmin
- **Connection** → you copy 4 DB values into `config/database.php`

InfinityFree MySQL can **only** be used from inside InfinityFree. Do not try Vercel + InfinityFree DB split — it is blocked.

---

## 2. Step 1 — Create hosting + domain (3 min)

1. Login to https://app.infinityfree.com
2. **Create Account** → choose **Free Subdomain**, e.g. `smartpalay123.infinityfreeapp.com` → Create.
3. Wait ~1 minute → click **Manage** / **Control Panel** for that domain.
4. Keep this tab open. You will need:
   - File Manager (or FTP details)
   - MySQL Databases
   - phpMyAdmin

---

## 3. Step 2 — Create the online database (2 min)

1. In Control Panel → **MySQL Databases** → **Create Database**.
2. It auto-generates names like:
   ```
   Host:     sqlXXX.infinityfree.com   (NOT localhost — copy exactly)
   DB name:  if0_12345678_smartpalay
   Username: if0_12345678
   Password: (the one you set / shown once — save it now)
   ```
3. Write these 4 values in Notepad. You need them in Step 5.
4. Note: new accounts sometimes take 5–10 minutes before MySQL accepts connections. If you get `Access denied` right away, wait and retry.

---

## 4. Step 3 — Get files from GitHub to InfinityFree (3 min)

Since there is no GitHub button on free plan, use the ZIP method:

1. Open your GitHub repo → **Code (green button) → Download ZIP**.
2. Unzip on your PC. You should see `index.php`, `config/`, `auth/`, `seller/`, `buyer/`, `admin/`, `database/smartpalay.sql`, etc.
3. In InfinityFree Control Panel → **File Manager** (or **Online File Manager**) → open `htdocs/`.
4. Delete default `index2.html` if present.
5. **Upload** all project files/folders **directly into `htdocs/`**, so you get:
   ```
   htdocs/index.php
   htdocs/config/database.php
   htdocs/auth/login.php
   htdocs/database/smartpalay.sql
   ...
   ```
   NOT `htdocs/smartpalay/index.php`. If you see a double folder, move files up one level.
6. Alternative: upload the ZIP to `htdocs/` then right-click → **Extract**. Then move files out of the inner folder if needed.

> Future updates: edit on PC → `git push` → Download ZIP again → re-upload only changed files. Or use FileZilla FTP for faster uploads (host/user/pass in Control Panel → FTP Details, port 21).

---

## 5. Step 4 — Import tables via phpMyAdmin (2 min)

1. Control Panel → **phpMyAdmin** (or **MySQL Databases → phpMyAdmin**) → click your DB name on the left (`if0_..._smartpalay`).
2. Top menu → **Import** → **Choose File** → select `database/smartpalay.sql` from your PC → **Go**.
3. Success = green check + tables appear: `users, sellers, buyers, weighing_records, transactions, payments, purchases, user_preferences, settings, login_logs`.
4. If you already imported once, you can safely re-import (all creates are `IF NOT EXISTS`, admin insert is `INSERT IGNORE`).
5. Optional check: click `users` table → Browse → you should see `admin@smartpalay.com`.

Do **not** change the SQL file. The bare `Email:/Password:` lines that used to break import are already fixed.

---

## 6. Step 5 — Connect code to the online DB (2 min, most important)

1. In File Manager → `htdocs/config/database.php` → right-click → **Edit**.
2. At the top, replace these 4 lines with your Step 3 values:
   ```php
   $host   = 'sqlXXX.infinityfree.com';  // NOT localhost
   $dbUser = 'if0_12345678';
   $dbPass = 'YourDbPassword';
   $dbName = 'if0_12345678_smartpalay';
   ```
3. Leave this line as-is (auto-detect handles both hosts):
   ```php
   $APP_BASE_URL = '';
   ```
   - Files directly in `htdocs/` → auto becomes `/` (correct for InfinityFree).
   - Only set to `'/smartpalay/'` if you intentionally deployed to `htdocs/smartpalay/`.
4. Save. Reload any page once — `ensureAppSchema()` will silently create/fix any missing columns (`purchases.seller_name`, `payments.purchase_ref`, `users.avatar`, etc.).

If you see `Database connection failed`, the 4 values are wrong. Copy-paste again from **MySQL Databases** (password has no extra spaces).

---

## 7. Step 6 — Test the live site

1. Open `https://yourdomain.infinityfreeapp.com/` (use `https://`, enable **Free SSL** in Control Panel → SSL Certificates for login security).
2. You should redirect to `.../auth/login.php`.
3. Login as admin:
   ```
   Email:    admin@smartpalay.com
   Password: admin123
   ```
4. Test each role:
   - `auth/register.php` → create a seller + a buyer → login as each → create a sale/purchase + payment.
   - `buyer/sellers.php` → Add seller (tests the `sellers` table migration).
   - `seller/profile.php` → upload avatar (tests `uploads/avatars/` writable + `users.avatar` column).
   - `seller/settings.php` → Save Preferences (tests `user_preferences` table).
   - `admin/settings.php` → Save General (tests `settings` table).
5. If all 5 work with no SQL errors, deployment is complete.

---

## 8. Step 7 — Lock down after deploy (1 min)

1. Login as admin → `admin/settings.php` → change admin password (or via `users` table → edit → hash? easier via UI if `admins` table exists, else update `users` password with new bcrypt via register+promote, or run SQL: update password with `password_hash` from local PHP).
   Simplest for newbies: login as admin → create a new strong-password admin in `admin/users.php`? That page only creates buyer/farmer, so instead: use phpMyAdmin → `users` → edit admin row → set `password` to `$2y$10$...` generated locally via `password_hash('NewPass123', PASSWORD_DEFAULT)`.
   At minimum, change it from `admin123` immediately.
2. Control Panel → **Free SSL** → Request + Install → force `https://`.
3. Keep `config/database.php` credentials private. Never push the live password to a public GitHub repo. Use a private repo or revert to localhost values before `git push`.

---

## 9. Troubleshooting (copy-paste fixes)

| Symptom | Cause | Fix |
|---------|-------|-----|
| `Database connection failed` | Wrong host/user/pass/name | Host must be `sqlXXX.infinityfree.com`, not `localhost`. Re-copy from MySQL Databases. Wait 10 min for new DB activation. |
| phpMyAdmin import red error | Old SQL with bare `Email:` lines | Re-download `database/smartpalay.sql` from GitHub (already fixed). Do not edit it in Notepad with word-wrap. |
| CSS broken / links go to `/smartpalay/...` 404 | Files in `htdocs/` but `BASE_URL` forced to `/smartpalay/` | Set `$APP_BASE_URL = ''` in `config/database.php` (auto → `/`). Clear browser cache. |
| Login loops back to login | Session/cookie or wrong DB (two DBs) | Check you edited the `htdocs/` copy, not a subfolder copy. Try incognito. Check `users.status=active`. |
| Avatar upload `Could not save` | `uploads/avatars/` missing or not writable | File Manager → `htdocs/uploads/avatars/` must exist, permission `755`. Reload once to auto-create. Max 2 MB, JPG/PNG/WEBP only. |
| `Table user_preferences doesn't exist` / `settings doesn't exist` | Imported old SQL before fix | Reload any page once (auto-creates), or re-import new `smartpalay.sql`. |
| `Unknown column buyer_id/name in sellers` | Old `sellers` table schema | Reload any page once (auto-adds columns). Verify in phpMyAdmin → `sellers` Structure has `buyer_id,name,contact,address,notes`. |
| `Field transaction_id doesn't have a default value` on payment save | Old `payments` table | Re-import new SQL or reload page (migrator makes it `NULL` + adds modern columns). |
| Social buttons 404 to `auth/oauth.php` | No OAuth file (normal) | Already fixed — buttons auto-hide when `auth/oauth.php` is missing. If you still see them, re-upload `auth/login.php` + `auth/register.php`. |
| `Access denied for user ... @ ...` just after DB creation | InfinityFree anti-spam delay | Wait 10–30 min, retry. Do not create 5 DBs — use the first one. |
| Site slow / `Hourly limit exceeded` | Free plan CPU/hit limits | Normal for free. Compress images, avoid F5 spam, test off-peak. Upgrade only if needed for demo day. |
| `403` on `uploads/` | Directory listing blocked (good) | Normal. Access files directly, e.g. `uploads/avatars/xxx.jpg`, not the folder. |

---

## 10. How to update the live site later

1. Edit + test on XAMPP (`http://localhost/smartpalay/`).
2. `git add . / git commit / git push` (keep live DB password OUT — revert `config/database.php` to localhost before pushing, or keep live config only on InfinityFree and never download it).
3. Download GitHub ZIP → upload only changed files via File Manager/FTP (overwrite).
4. No DB import needed unless `database/smartpalay.sql` changed. App auto-migrates new columns on first load.

---

## 11. Quick reference

- Local: `http://localhost/smartpalay/` — DB `localhost/root//smartpalay` — `BASE_URL` auto `/smartpalay/`
- Live: `https://yourdomain.infinityfreeapp.com/` — DB `sqlXXX.infinityfree.com/if0_...` — `BASE_URL` auto `/`
- Admin seed: `admin@smartpalay.com / admin123` (change after deploy)
- Edited for deploy: `config/database.php`, `database/smartpalay.sql`, `auth/login.php`, `auth/register.php`, `uploads/index.html`, `uploads/avatars/index.html`
- Full project docs: `readme.md`
