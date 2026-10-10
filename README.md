# Daily Ledger — Shop & Small Business Finance Portal

A complete, lightweight PHP 8+ / MySQL / PDO / Bootstrap 5 web app for shopkeepers
and small business owners to manage daily income, expenses, customer/supplier dues,
and view live financial reports.

## ✅ What's included

- Secure session-based login/logout (bcrypt passwords, CSRF protection on every form)
- Admin & User roles
  - **Admin:** add/edit/activate/deactivate users, reset any user's password
  - **User:** change own password, manage own business data (fully isolated per user)
- Income & Expense entry with custom, per-user Income/Expense Heads (categories)
- Customer & Supplier Due Ledger
  - Add new due, record payments
  - Auto-calculated: `Opening Due + New Due − Payment = Current Due`
- Daily Ledger combining income & expense with opening/closing balances
- Live dashboard: Today's Income/Expense/Balance, Monthly totals, Receivable,
  Payable, Recent Transactions, Income vs Expense chart
- Date-wise / Month-wise reports with head-wise & payment-method breakdowns
- Search, filtering & pagination everywhere
- Print-friendly views (Ledger, Reports, Party statements)
- Fully responsive, mobile-friendly UI (collapsible sidebar with overlay on mobile)

## 📦 Installation (XAMPP / WAMP / cPanel / any Apache+PHP+MySQL host)

1. **Copy files**
   Copy the whole `daily-ledger` folder into your web root, e.g.
   `C:\xampp\htdocs\daily-ledger` or your hosting's `public_html/daily-ledger`.

2. **Create the database**
   Open phpMyAdmin (or the `mysql` CLI) and import `db/schema.sql`.
   This will:
   - Create the `daily_ledger` database
   - Create all tables
   - Create a default **admin** account
   - Seed a few starter Income/Expense heads

   ```bash
   mysql -u root -p < db/schema.sql
   ```

3. **Configure the database connection**
   Edit `config/db.php` and set your DB host/user/password if they differ from
   the XAMPP defaults (`localhost` / `root` / empty password):

   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'daily_ledger');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```

4. **Open the app**
   Visit `http://localhost/daily-ledger/` in your browser.

5. **Login**
   - Username: `admin`
   - Password: `Admin@123`

   ⚠️ **Change this password immediately** after your first login
   (top-right profile menu → Change Password), or reset it from
   **Manage Users** as an admin.

## 🗂 Folder structure

```
daily-ledger/
├── assets/            CSS & JS
├── config/db.php       Database connection settings (edit this!)
├── db/schema.sql       Full database schema + seed data
├── includes/           Shared PHP (auth, header, footer, helpers)
├── index.php            Login page
├── dashboard.php        Live dashboard
├── income.php / expense.php   Transaction entry
├── heads.php             Manage income/expense categories
├── parties.php / party_ledger.php   Customer & Supplier due ledger
├── ledger.php             Daily combined ledger
├── reports.php            Date/month-wise reports
├── users.php               Admin user management
└── profile.php              Change password / update profile
```

## 🔐 Security notes

- All queries use PDO prepared statements (no SQL injection).
- Every state-changing form is protected by a CSRF token.
- Passwords are hashed with PHP's `password_hash()` (bcrypt).
- Each user's income, expense, heads, and party data are isolated by `user_id`;
  users can never see or edit another user's data. Admins manage accounts only.
- `.htaccess` disables directory listing and blocks direct access to `.sql` files.

## 🎨 Customizing

- **Currency symbol:** change `APP_CURRENCY` in `config/db.php`.
- **App name/branding:** change `APP_NAME` in `config/db.php`.
- **Payment methods:** edit the `ENUM` list in `db/schema.sql`
  (`incomes.payment_method` / `expenses.payment_method`) and the matching
  `<option>` lists in `income.php` / `expense.php`.
- **Colors:** edit the CSS variables at the top of `assets/css/style.css`.

## 🧪 Tested

All pages were smoke-tested end-to-end (login, add income, add expense, add
customer with opening due, add a new due + a payment, and verified the
Current Due formula and live dashboard totals update correctly) with zero
PHP warnings/errors on PHP 8.3.
