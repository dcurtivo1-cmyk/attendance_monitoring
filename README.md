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
