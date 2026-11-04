# CampusIQ — School Management System

CampusIQ is a modern PHP + MySQL school management system built for private schools in Ghana. It provides a professional platform for managing students, teachers, attendance, results, fees, announcements, events, and communication.

## Features

- Role-based authentication for Super Admin, School Admin, Teacher, Student, Parent, and Accountant
- Student, teacher, class, and subject management
- Attendance tracking with teacher workflows
- Results and grading system
- Fee and payment tracking with invoice support
- Announcements, notifications, messaging, and school events
- Dashboard summaries and chart-friendly reporting
- Responsive, mobile-friendly UI with Bootstrap 5 and Font Awesome

## Technology Stack

- HTML5
- CSS3
- JavaScript
- PHP 8+
- MySQL
- Bootstrap 5
- Font Awesome

## Folder Structure

- `admin/` — admin dashboards and management screens
- `teacher/` — teacher workflows
- `student/` — student portal
- `parent/` — parent portal
- `accountant/` — finance workflows
- `assets/` — CSS, JavaScript, images, uploads
- `config/` — app and DB configuration
- `database/` — schema and seed SQL
- `includes/` — reusable components and access control
- `api/` — future API endpoints
- `pages/` — extra page modules

## Installation

1. Copy this project folder into your web server root, for example:
   - XAMPP: `C:\xampp\htdocs\campusiq`
   - WAMP: `C:\wamp64\www\campusiq`
   - Laragon: `C:\laragon\www\campusiq`
2. Start Apache and MySQL in your local server control panel.
3. Import the SQL file from `database/campusiq.sql` into MySQL.
4. Update the database credentials in `config/database.php` if needed.
5. Configure the security secrets below and restart Apache.
6. For an existing database, import `database/migration_security.sql` once.
7. Open `http://localsiq/login.php`host/campu in your browser.

## Database Setup

Import this file into phpMyAdmin or MySQL CLI:

`database/campusiq.sql`

The SQL file creates the `campusiq` database with tables for users, students, teachers, parents, classes, subjects, attendance, examinations, fees, announcements, notifications, events, and more.

For an existing installation, import `database/migration_security.sql` once using phpMyAdmin. Do not run this migration a second time.

## Security Configuration

CampusIQ requires two different random secrets, kept outside the database and project files: `CAMPUSIQ_PASSWORD_PEPPER` and `CAMPUSIQ_APP_KEY`. Generate two values in PowerShell:

```powershell
$rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
$bytes = New-Object byte[] 32
$rng.GetBytes($bytes)
([BitConverter]::ToString($bytes) -replace '-', '').ToLowerInvariant()
$rng.GetBytes($bytes)
([BitConverter]::ToString($bytes) -replace '-', '').ToLowerInvariant()
```

Put the first 64-character value in Apache as `CAMPUSIQ_PASSWORD_PEPPER` and the second as `CAMPUSIQ_APP_KEY`. In XAMPP, add these lines to `C:\xampp\apache\conf\httpd.conf`, replacing the example text with the generated values:

```apache
SetEnv CAMPUSIQ_PASSWORD_PEPPER "first-64-character-value"
SetEnv CAMPUSIQ_APP_KEY "second-64-character-value"
```

Restart Apache. Back up both secrets privately. Losing/changing the pepper means existing peppered passwords cannot be checked; losing/changing the app key means enrolled authenticator secrets cannot be decrypted.

New passwords use the pepper before PHP's `password_hash()` adds a random per-password salt. Existing passwords continue to work and are automatically upgraded to peppered hashes on their next successful login.

After a correct password, users without an authenticator enrolled are shown a one-time setup key and must confirm a current six-digit code from an authenticator app before sign-in completes. Future sign-ins show the code prompt immediately after the password. The shield icon in the dashboard header lets users manage their authenticator setup. Authenticator secrets are encrypted in the database. Existing role checks continue to enforce authorization on protected pages.

## Demo Accounts

The database includes demo accounts with hashed passwords:

- Admin: `admin@campusiq.edu.gh` / `Admin@123`
- Teacher: `teacher1@campusiq.edu.gh` / `Teacher@123`
- Student: `student1@campusiq.edu.gh` / `Student@123`
- Parent: `parent1@campusiq.edu.gh` / `Parent@123`
- Accountant: `accountant@campusiq.edu.gh` / `Accountant@123`

## Running on XAMPP

1. Place the project inside `C:\xampp\htdocs\campusiq`.
2. Start Apache and MySQL.
3. Open phpMyAdmin and create the database or import the SQL file.
4. Visit `http://localhost/campusiq/login.php`.

## Troubleshooting

- If the database connection fails, check the values in `config/database.php`.
- If pages redirect unexpectedly, confirm the project is under the correct server root and your local URL matches `/campusiq`.
- If PHP is missing from PATH, use the full XAMPP PHP path to run syntax checks manually.

## Security Notes

- Passwords are hashed using PHP's `password_hash()` with a server-side pepper and per-password salt.
- Optional authenticator-app two-factor authentication uses TOTP with encrypted secrets.
- Prepared statements are used for database queries.
- Role checks protect restricted pages.
- Session timeout is enabled for inactivity.

## Project Status

This version is built as a production-style school management system foundation with dashboards, portals, database schema, and demo seed data for a strong presentation and further expansion.
