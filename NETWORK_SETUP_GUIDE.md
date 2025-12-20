# 🌐 Multi-PC Network Setup Guide

## Overview
This guide explains how to configure your DICOM Viewer for multiple PCs in a hospital network.

## Network Architecture

```
Hospital Network (192.168.1.x)
├── Server PC (192.168.1.100) - Runs MySQL Database
├── Reception PC (192.168.1.101) - Connects to database
├── Doctor PC (192.168.1.102) - Connects to database
└── Radiology PC (192.168.1.103) - Connects to database
```

## Setup Instructions

### Step 1: Configure the Server PC (Database Host)

**Server PC IP:** 192.168.1.100 (example)

#### 1.1 Edit MySQL Configuration
File: `C:\xampp\mysql\bin\my.ini`

Find and change:
```ini
# Before (only allows localhost):
bind-address = 127.0.0.1

# After (allows network connections):
bind-address = 0.0.0.0
```

#### 1.2 Create MySQL User for Network Access

Open phpMyAdmin or MySQL command line on server:

```sql
-- Create user that can connect from any IP in network
CREATE USER 'dicom_user'@'192.168.1.%' IDENTIFIED BY 'YourSecurePassword123';

-- Grant all privileges on your database
GRANT ALL PRIVILEGES ON dicom_viewer.* TO 'dicom_user'@'192.168.1.%';

-- Refresh privileges
FLUSH PRIVILEGES;
```

#### 1.3 Restart MySQL Service
- Open XAMPP Control Panel
- Stop MySQL
- Start MySQL

#### 1.4 Test MySQL Port is Open
```bash
netstat -an | findstr :3306
```
Should show: `0.0.0.0:3306` (listening on all interfaces)

---

### Step 2: Configure Client PCs (Workstations)

**Client PCs:** 192.168.1.101, 192.168.1.102, etc.

#### 2.1 Edit config.php on Each Client PC

File: `www/includes/config.php`

```php
<?php
// Database Configuration
define('DB_HOST', '192.168.1.100');  // Server PC IP address
define('DB_USER', 'dicom_user');     // Network user we created
define('DB_PASS', 'YourSecurePassword123');  // Password
define('DB_NAME', 'dicom_viewer');   // Database name
define('DB_PORT', 3306);
```

#### 2.2 Edit .env File (if exists)

File: `www/.env`

```env
DB_HOST=192.168.1.100
DB_USER=dicom_user
DB_PASS=YourSecurePassword123
DB_NAME=dicom_viewer
DB_PORT=3306
```

---

### Step 3: Firewall Configuration

#### On Server PC (192.168.1.100):

**Windows Firewall:**
1. Open Windows Firewall
2. Click "Advanced Settings"
3. Click "Inbound Rules" → "New Rule"
4. Select "Port" → Next
5. Select "TCP" → Specific local ports: `3306` → Next
6. Select "Allow the connection" → Next
7. Check all profiles → Next
8. Name: "MySQL Server" → Finish

**Or run this PowerShell command as Administrator:**
```powershell
New-NetFirewallRule -DisplayName "MySQL Server" -Direction Inbound -Protocol TCP -LocalPort 3306 -Action Allow
```

---

### Step 4: Test Connection from Client PC

#### Method 1: Using MySQL Command Line
On client PC, open Command Prompt:
```bash
cd C:\xampp\mysql\bin
mysql -h 192.168.1.100 -u dicom_user -p
# Enter password: YourSecurePassword123
```

If successful, you'll see:
```
Welcome to the MySQL monitor...
mysql>
```

#### Method 2: Test with PHP Script
Create file: `test_connection.php`

```php
<?php
$host = '192.168.1.100';
$user = 'dicom_user';
$pass = 'YourSecurePassword123';
$db = 'dicom_viewer';

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
echo "✓ Connected successfully to server!";
$conn->close();
?>
```

Run: `http://localhost/papa/dicom_again/claude/desktop-version/electron/www/test_connection.php`

---

### Step 5: Shared DICOM Folder Configuration

#### Option A: Network Shared Folder (Recommended)

**On Server PC:**
1. Create folder: `C:\DICOM\Incoming`
2. Right-click → Properties → Sharing
3. Click "Share..." button
4. Add "Everyone" with Read/Write permissions
5. Note the network path: `\\192.168.1.100\DICOM\Incoming`

**On Client PCs:**
- Map network drive as `Z:\` pointing to `\\192.168.1.100\DICOM`
- All PCs can now access same DICOM files

#### Option B: Local DICOM Folders
Each PC can have its own local DICOM folder, but they all save to the same database.

---

## User Access Levels

### Different Roles for Different PCs:

```sql
-- Reception: Basic user (view only)
INSERT INTO users (username, password_hash, full_name, role, is_active)
VALUES ('reception', '$2y$10$...', 'Reception Desk', 'viewer', 1);

-- Doctor: Can view and report
INSERT INTO users (username, password_hash, full_name, role, is_active)
VALUES ('doctor1', '$2y$10$...', 'Dr. John Smith', 'doctor', 1);

-- Radiologist: Full access to reports
INSERT INTO users (username, password_hash, full_name, role, is_active)
VALUES ('radiologist', '$2y$10$...', 'Dr. Sarah Johnson', 'radiologist', 1);

-- Admin: System configuration
INSERT INTO users (username, password_hash, full_name, role, is_active)
VALUES ('admin', '$2y$10$...', 'System Admin', 'admin', 1);
```

Create users via:
```
http://192.168.1.100/papa/dicom_again/claude/desktop-version/electron/www/manage_users.php
```

---

## Troubleshooting

### Problem: "Can't connect to MySQL server"

**Solution 1:** Check server IP is correct
```bash
ping 192.168.1.100
```

**Solution 2:** Check MySQL is running on server
- Open XAMPP Control Panel on server
- Ensure MySQL status is "Running"

**Solution 3:** Check firewall
- Temporarily disable Windows Firewall on server to test
- If it works, add firewall rule (Step 3)

### Problem: "Access denied for user"

**Solution:** Recreate MySQL user with correct host pattern
```sql
DROP USER 'dicom_user'@'192.168.1.%';
CREATE USER 'dicom_user'@'%' IDENTIFIED BY 'password';
GRANT ALL PRIVILEGES ON dicom_viewer.* TO 'dicom_user'@'%';
FLUSH PRIVILEGES;
```

### Problem: "Host not allowed to connect"

**Solution:** MySQL bind-address is wrong
- Check `my.ini` has `bind-address = 0.0.0.0`
- Restart MySQL service

---

## Security Best Practices

### 1. Use Strong Passwords
```sql
-- Bad:
CREATE USER 'dicom_user'@'%' IDENTIFIED BY '12345';

-- Good:
CREATE USER 'dicom_user'@'%' IDENTIFIED BY 'D!c0m#Str0ng$P@ssw0rd2024';
```

### 2. Restrict by IP Range
```sql
-- Only allow hospital network (192.168.1.x)
CREATE USER 'dicom_user'@'192.168.1.%' IDENTIFIED BY 'password';

-- Even more restrictive (specific IPs)
CREATE USER 'dicom_user'@'192.168.1.101' IDENTIFIED BY 'password';
CREATE USER 'dicom_user'@'192.168.1.102' IDENTIFIED BY 'password';
```

### 3. Use SSL Encryption (Optional)
For highly sensitive data, configure MySQL SSL.

---

## Performance Tips

### 1. Server PC Requirements
- **RAM:** Minimum 8GB, Recommended 16GB+
- **Storage:** SSD recommended for database
- **Network:** Gigabit Ethernet (1000 Mbps)

### 2. Database Optimization
```sql
-- Enable query cache (in my.ini)
query_cache_type = 1
query_cache_size = 128M

-- Increase connection pool
max_connections = 200
```

### 3. Network Optimization
- Use Cat6 or Cat6a Ethernet cables
- Connect all PCs via gigabit switch
- Avoid WiFi for critical workstations

---

## Backup Strategy

### Automated Daily Backup on Server PC

Create batch file: `C:\backup_dicom.bat`

```batch
@echo off
set BACKUP_DIR=D:\DICOM_Backups
set DATE=%date:~-4,4%%date:~-10,2%%date:~-7,2%

"C:\xampp\mysql\bin\mysqldump.exe" -u root -p dicom_viewer > "%BACKUP_DIR%\dicom_%DATE%.sql"

echo Backup completed: %BACKUP_DIR%\dicom_%DATE%.sql
```

Schedule in Windows Task Scheduler:
- Trigger: Daily at 2:00 AM
- Action: Run `C:\backup_dicom.bat`

---

## Quick Reference

| Item | Value |
|------|-------|
| Server IP | 192.168.1.100 |
| MySQL Port | 3306 |
| Database User | dicom_user |
| Database Name | dicom_viewer |
| DICOM Folder | \\\\192.168.1.100\\DICOM |
| Admin Panel | http://192.168.1.100/papa/.../www/ |

---

## Support

For issues:
1. Check server MySQL is running
2. Test network connection: `ping 192.168.1.100`
3. Test database connection with test_connection.php
4. Check firewall rules
5. Review MySQL error log: `C:\xampp\mysql\data\mysql_error.log`
