# Automated Student Attendance Monitoring System with QR Code
**Institution:** San Pedro Annex National High School  
**Technology Stack:** PHP 8.x, MySQL / MariaDB, JavaScript (HTML5 Web Camera & QRCode.js), CSS3  

---

## 🚀 Key Features

1. **Student Portal**:
   - **QR Attendance Scanner**: Students activate their phone or laptop camera to scan the active classroom session QR code displayed by the teacher.
   - **Instant Scan Feedback**: Synthesized audio chime and dynamic status toast confirming attendance as **Present** or **Late**.
   - **Assigned Subjects & Schedules**: Displays days of the week (`Monday`, `Tuesday`, etc.) and lecture time slots.
   - **Personal Attendance History**: Full log of historical attendance records with status filters.

2. **Teacher / Faculty Portal**:
   - **Session QR Code Generator**: Generates dynamic, high-contrast QR codes for each subject and section, ready for smart TVs or projectors.
   - **Live Attendance Dashboard**: Real-time counter metrics for **Assigned Students**, **Present Today**, **Late Today**, and **Absent Today**.
   - **Live Auto-Polling**: Real-time attendance feed dynamically updates without refreshing the page as students scan.
   - **Manual Attendance Roster**: Fallback manual marking for students experiencing camera issues or missing devices.
   - **Reports & CSV Export**: Filter classroom attendance logs and export directly to CSV / Excel.

3. **Administrator Portal**:
   - **Centralized Dashboard**: School-wide KPIs for total students, faculty teachers, today's attendance rates, and logs.
   - **Student Management**: Add, view, edit, and search students with separated First Name, M.I., and Last Name.
   - **Teacher Management & Faculty Attendance**: Track daily faculty check-in times, status updates, and reports.
   - **Auditing & Reporting**: Master attendance reports with multi-criteria filtering and spreadsheet exporting.

---

## 🛠️ Step-by-Step Setup & Installation Instructions

### Step 1: Place Source Code in XAMPP `htdocs`
1. Open your XAMPP installation directory (typically `C:\xampp\`).
2. Copy or extract this project folder into `htdocs`:
   ```text
   C:\xampp\htdocs\Attendance_Monitoring\
   ```

### Step 2: Start Apache and MySQL Services
1. Open the **XAMPP Control Panel**.
2. Click **Start** on **Apache** (Port 80/443).
3. Click **Start** on **MySQL** (Port 3306).

### Step 3: Database Setup & Import
1. Open your browser and go to phpMyAdmin:
   ```text
   http://localhost/phpmyadmin/
   ```
2. Click **New**, enter database name **`attendance_db`**, select `utf8mb4_unicode_ci`, and click **Create**.
3. Select `attendance_db` in the left panel, and click the **Import** tab on the top menu.
4. Click **Choose File**, select `C:\xampp\htdocs\Attendance_Monitoring\database\schema.sql`, and click **Import**.
5. *(Automated Migration)*: The project also includes an auto-schema migrator in `config/schema_setup.php` that verifies and creates missing tables upon visiting the site.

### Step 4: Verify Database Configuration
Open `config/database.php` to verify your local database settings:
```php
define('DB_HOST', '127.0.0.1');     // Use 127.0.0.1 to prevent Windows IPv6 DNS lag
define('DB_USER', 'root');          // Default XAMPP username
define('DB_PASS', '');              // Default XAMPP password (empty)
define('DB_NAME', 'attendance_db');  // Database name
define('DB_PORT', 3306);            // Default MySQL port
```

### Step 5: Launch the Application
Open your web browser and navigate to:
```text
http://localhost/Attendance_Monitoring/
```
The application will automatically redirect you to `login.php`.

---

## 🔑 Default Demo Accounts

All sample accounts use the default password: **`password123`**

| Role | Email / Username | Password | Purpose |
| :--- | :--- | :--- | :--- |
| **Administrator** | `admin@school.edu.ph` | `password123` | Full school administration, student/teacher rosters, and master reports |
| **Teacher / Faculty** | `prof.juan@school.edu.ph` | `password123` | Generates session QR code, monitors live attendance, and manual roster |
| **Student (Grade 10)** | `STUD-2026-000-009` *(or `ana.reyes@student.edu.ph`)* | `password123` | Mobile camera QR scanner, subjects schedule, personal attendance |
| **Student (Grade 8)** | `STUD-2026-101` *(or `maria.santos@student.edu.ph`)* | `password123` | Maria Clara Santos (Section A - Bonifacio) |

---

## 📱 How to Test the QR Attendance Workflow

1. **Teacher**:
   - Log in as Teacher (`prof.juan@school.edu.ph` / `password123`).
   - Go to **Dashboard & QR Code**, select the active subject (e.g., `G10-ARA5 — All Sections`).
   - The Teacher QR Code is displayed on screen.

2. **Student**:
   - Log in as Student (`STUD-2026-000-009` / `password123`) on a phone or separate browser window.
   - Go to **QR Scanner**, allow camera permissions, and point the camera at the teacher's QR code.
   - An audio beep will play, and a green toast confirms: **"Present!"**

3. **Live Verification**:
   - Look at the Teacher Dashboard: the **Present Today** counter increments and the student's name appears with a green **Present** badge automatically!


## Password recovery with email OTP

Configure environment variables in the PHP host and restart Apache after changing them:

- `SMTP_HOST` (for Gmail: `smtp.gmail.com`), `SMTP_PORT` (587 or 465), `SMTP_SECURE` (`tls` or `ssl`).
- `SMTP_USER`, `SMTP_PASS`: mail provider credentials; Gmail requires an app password.
- `MAIL_FROM_EMAIL`: a sender verified by the provider; defaults to `SMTP_USER`. `MAIL_FROM_NAME` is optional.

The application does not automatically load `.env` files. Configure variables in the host or Apache; never commit credentials. No `APP_URL` is needed for OTP emails. SMTP acceptance is required before a registered account advances after sending. The application never displays, logs, or archives OTPs. Inbox delivery still depends on provider settings and spam filtering.

The recovery flow is **registered email -> 6-digit OTP -> new password** in the same browser. Codes expire after 10 minutes, allow 5 verification attempts, and are stored as bcrypt hashes. Resends have a 60-second cooldown and each account is limited to 5 codes per hour. A successfully sent replacement invalidates previous codes and pending resets. Password changes consume all pending codes for the account. The new `password_reset_otps` table is created by the existing schema setup; its DDL is also included in `database/schema.sql`. Old reset-link URLs redirect to the OTP flow.

Verify delivery using a registered test mailbox: receive the OTP, verify it, save a new password, confirm the old password fails and the new password works. Test incorrect, expired, resent, and reused codes. Run `php tests/password_otp_test.php` for isolated recovery service checks and `php tests/password_recovery_test.php` for simulated SMTP checks. The SQLite test omits MySQL row-lock syntax; validate concurrent requests on MySQL before production deployment.


## Grade and section catalog

`school_sections` is the authoritative section list for student registration, admin student and teacher forms, attendance filters, and the Subjects & Sections editor. Each section belongs to a grade level. New installations use distinct names per grade: Grade 7 (Emerald, Jade, Ruby), Grade 8 (Sapphire, Topaz, Amethyst), Grade 9 (Diamond, Pearl), and Grade 10 (Onyx, Quartz). Renaming a section updates its catalog entry and linked records.

The local database was standardized with `php scripts/standardize_sections.php`. This CLI-only migration saves the affected columns to a private `storage/section-backup-*.json` file before updating users, teachers, assignments, and report snapshots in a transaction. It preserves separate student cohorts and adds Section C where needed. A migration marker prevents repeat execution from overwriting subsequent edits. Keep the backup private. Normal page loads do not rerun this data migration.


The follow-up migration `php scripts/name_grade_sections.php --apply` renames the existing catalog and every matching user, teacher, teaching assignment, and attendance snapshot in a single transaction. Run without `--apply` to preview the mapping. It saves a private `storage/named-section-backup-*.json` backup and records a migration marker so later runs do not overwrite admin edits. Each existing section is renamed individually; students retain their current grade and classmates.


## Teacher and student profiles

Both portals have a **Profile** menu (`professor/profile.php` and `student/profile.php`). Students can edit their name parts, email, and guardian contact. Teachers can edit their name, email, and contact number when a linked teacher record exists. Teacher changes synchronize the login account and all linked teacher records. School IDs, grade levels, sections, and roles remain administrator-managed.

Email changes and password changes require the current password. Password changes also require matching confirmation and use bcrypt. Both forms validate a session CSRF token and only update the authenticated account. Changing email or password invalidates pending recovery codes and legacy reset links. Successful updates refresh the current session and use a redirect to prevent duplicate form submission.

Run `php tests/account_profile_test.php` for isolated profile/security checks, including account isolation, protected school fields, duplicate email, invalid passwords, recovery invalidation, and rollback if teacher synchronization fails. These fixtures do not alter actual accounts.


## School time and attendance timestamps

The application sets PHP to `Asia/Manila` in `config/school_time.php`, loaded by the shared database bootstrap. Each MySQL connection uses `+08:00`, including SQL timestamps. QR attendance takes one server timestamp for its local date, time, weekday, and Present/Late calculation. With one attendance record per subject per day, the first scheduled class that day defines the lateness cutoff; the exact grace-period second is still Present.

Manual attendance ignores browser-supplied arrival times. Correcting a status or marking a section preserves an existing arrival time. Page initialization no longer creates sample teacher attendance for each new day. Existing historical DATE/TIME values have not been shifted because they may include manual entries or imported records.

Run `php tests/school_time_test.php` for timezone and grace-period boundary checks. The attendance section fixtures also cover `set_existing`, `bulk_existing`, and `set_spoof` to verify preserved times and server-authoritative arrival timestamps.


## Student account creation emails

Creating a student through Students, Dashboard Quick Add, or registration sets the initial password to `password123` (stored as a bcrypt hash). After account creation, the system emails the student's login address with the website link, email, and default password. Students can optionally change it in **Profile > Change Password**. Existing passwords remain unchanged.

Configure `SMTP_USER` and `SMTP_PASS` in the PHP/Apache environment using the same SMTP settings as password recovery above. Account creation remains successful if SMTP fails; the page explicitly reports that the email was not sent. Duplicate or rejected submissions do not send account emails. Emails are not archived or logged.

The current login link is `http://localhost/Attendance_Monitoring/login.php`. Set `APP_URL` to the application root (for example, `https://school.example.com/Attendance_Monitoring`, without `/login.php`) when hosting it for students. A localhost link works only on the computer running the website.

Run `php tests/student_account_email_test.php` (also `missing_smtp` and `invalid_url`) and `php tests/student_account_creation_test.php students success`. Creation tests support `students`, `dashboard`, and `register`, with scenarios `success`, `missing`, `mismatch`, `short`, `long`, `email`, `section`, `duplicate`, and `smtp_failed`; the two admin handlers also support `csrf`. These tests use isolated SQLite accounts and simulated SMTP without contacting real mailboxes.


### Connect Gmail from the administrator portal

Open **Email Settings** from Dashboard or Student Records (`admin/email_settings.php`). Enter the sender Gmail/Google Workspace address and its 16-letter Google App Password, then choose **Save and send test email**. Enable Google 2-Step Verification first; see [Google's App Password guide](https://support.google.com/mail/answer/185833?hl=en). The test goes only to the sender address. Settings are saved after SMTP acceptance, without restarting Apache.

Saved sender settings take precedence over environment settings and are stored in `storage/mail-settings.php`, which must not be shared or committed. The storage directory denies HTTP access, and the PHP settings file emits no contents. The admin form never displays the saved password and requires authentication and CSRF validation. Sending has a 30-second per-session cooldown and uses a POST/redirect flow to prevent refresh resends.

Use **Resend student login email** on the same page for accounts whose first notification failed. It looks up the registered student email and only sends credentials if the current password still matches `password123`; it never resets passwords. Students with changed passwords should use the normal password recovery flow. Run `php tests/mail_settings_test.php` for isolated setup and retry checks.
