# 🚀 Complete Testing & Setup Guide

## 📌 Quick Start

### For Fresh Testing (Single PC):
1. Visit: `http://localhost/papa/dicom_again/claude/desktop-version/electron/www/quick_reset_and_test.php`
2. Click "RESET & CREATE LICENSE NOW"
3. Copy the license key
4. Run: `npm start`
5. Paste license key → Complete wizard → Enjoy tour!

### To Fix Superadmin Login:
1. Visit: `http://localhost/papa/dicom_again/claude/desktop-version/electron/www/manage_users.php`
2. Click "Create/Reset Superadmin"
3. Login with `superadmin` / `12345`

---

## 📂 All Available Scripts

| Script | Purpose | URL |
|--------|---------|-----|
| **Quick Reset & Test** | One-click: Reset DB + Create License | [quick_reset_and_test.php](quick_reset_and_test.php) |
| **Reset Database** | Clear all data for fresh test | [reset_for_testing.php](reset_for_testing.php) |
| **Create License** | Generate new license key | [create_test_license.php](create_test_license.php) |
| **Manage Users** | Create/delete users, reset passwords | [manage_users.php](manage_users.php) |
| **Test Connection** | Verify database connection | [test_connection.php](test_connection.php) |
| **Run Migration** | Add missing database columns | [run_setup_migration.php](run_setup_migration.php) |

---

## 🏥 Architecture: Single Hospital, Multiple PCs

### ✅ What This System DOES:

```
Hospital ABC Network
├── Server PC (192.168.1.100)
│   └── MySQL Database (shared)
│
├── Reception PC (192.168.1.101) ───┐
├── Doctor PC (192.168.1.102) ──────┤ All connect to
├── Radiology PC (192.168.1.103) ───┤ same database
└── Admin PC (192.168.1.104) ───────┘

✓ All PCs see same patients
✓ All PCs access same studies
✓ All PCs share same hospital settings
✓ Each user has own login
✓ Complete data synchronization
✓ Perfect for one hospital
```

### ❌ What This System DOES NOT DO:

```
✗ Multi-hospital in one database
✗ Data isolation by license key
✗ SaaS/cloud multi-tenancy
✗ Separate "profiles" per hospital

If you need this, major rebuild required!
```

---

## 🔑 Default Login Credentials

### After License Activation:
- **Username:** `admin`
- **Password:** `admin123`
- **Created automatically** when license is activated

### Create Superadmin (Optional):
- **Username:** `superadmin`
- **Password:** `12345`
- **Create via:** [manage_users.php](manage_users.php)

---

## 🌐 Multi-PC Network Setup

### Quick Setup (Same Network):

**Server PC (192.168.1.100):**
```sql
-- 1. Edit MySQL config (my.ini):
bind-address = 0.0.0.0

-- 2. Create network user:
CREATE USER 'dicom_user'@'192.168.1.%' IDENTIFIED BY 'SecurePass123';
GRANT ALL PRIVILEGES ON dicom_viewer.* TO 'dicom_user'@'192.168.1.%';
FLUSH PRIVILEGES;

-- 3. Allow firewall:
# Windows Firewall → Inbound Rules → New Rule → Port 3306
```

**Client PCs (All Others):**
Edit `www/includes/config.php`:
```php
define('DB_HOST', '192.168.1.100');  // Server IP
define('DB_USER', 'dicom_user');
define('DB_PASS', 'SecurePass123');
define('DB_NAME', 'dicom_viewer');
```

**Test Connection:**
```
http://localhost/papa/.../www/test_connection.php
```

**Full Guide:** See [NETWORK_SETUP_GUIDE.md](../NETWORK_SETUP_GUIDE.md)

---

## 🎯 Complete Testing Workflow

### Scenario 1: Fresh Installation Test

```bash
# Step 1: Reset everything
Visit: quick_reset_and_test.php
Click: "RESET & CREATE LICENSE NOW"
Copy: License key (yellow box)

# Step 2: Start app
npm start

# Step 3: Activate license
Paste license key in Electron app
Wait for "Redirecting to setup wizard..."

# Step 4: Complete wizard
- Hospital Info: Name, department, phone, email
- Address: Line 1, city, state
- Admin User: Username, password
- Folder Path: C:\DICOM\Incoming (or any path)
Click: "Complete Setup"

# Step 5: Verify tour starts
Should redirect to patients page
Tour should auto-start with highlighting
Follow tour steps

# Step 6: Verify data saved
Visit: http://localhost/.../www/admin/settings.php
Check hospital name shows your NEW data (not old)
```

### Scenario 2: Multi-PC Network Test

```bash
# On Server PC (192.168.1.100):
1. Complete Scenario 1 above
2. Configure MySQL for network (see Network Setup)
3. Note server IP address

# On Client PC #1 (192.168.1.101):
1. Install Electron app
2. Edit config.php → DB_HOST = '192.168.1.100'
3. Test connection: test_connection.php
4. Run: npm start
5. Login with credentials created on server
6. Verify sees same patients/studies as server

# On Client PC #2, #3, etc:
Repeat Client PC #1 steps
```

---

## 🐛 Troubleshooting

### Problem: Setup wizard doesn't show
**Solution:**
```bash
# Run reset script
Visit: reset_for_testing.php

# Check database
Visit: test_connection.php
Verify: setup_complete = NO
Verify: hospital_name = [NOT SET]

# If still showing old data:
Run SQL:
DELETE FROM system_settings WHERE setting_key LIKE 'hospital%';
DELETE FROM settings WHERE setting_key = 'setup_complete';
```

### Problem: Tour doesn't start
**Check:**
1. URL has `?tour=1` parameter
2. Browser console for JavaScript errors
3. `app-tour.js` file exists and loads
4. No localStorage blocks: Clear browser data

### Problem: Can't login with superadmin/12345
**Solution:**
```bash
Visit: manage_users.php
Click: "Create/Reset Superadmin"
Try login again
```

### Problem: Client PC can't connect to server
**Solution:**
```bash
# Test network:
ping 192.168.1.100

# Test MySQL port:
telnet 192.168.1.100 3306

# Check firewall on server
# Check my.ini: bind-address = 0.0.0.0
# Restart MySQL service

# Use test_connection.php for detailed diagnosis
```

---

## 📊 Verify Setup Works

### Check 1: Database Tables
```sql
SHOW TABLES;
-- Should see: users, patients, studies, system_settings, licenses, etc.
```

### Check 2: Hospital Settings
```sql
SELECT * FROM system_settings WHERE setting_key LIKE 'hospital%';
-- Should show YOUR hospital info (not empty or old data)
```

### Check 3: Users
```sql
SELECT id, username, role, is_active FROM users;
-- Should show your admin user
```

### Check 4: License
```sql
SELECT license_key, customer_name FROM licenses WHERE is_active = 1;
-- Should show your test license
```

---

## 🎓 User Roles Explained

| Role | Access Level | Use Case |
|------|--------------|----------|
| **super_admin** | Full system access | System configuration, user management |
| **admin** | Hospital settings | Hospital admin, setup wizard |
| **radiologist** | Full medical access | Read/write reports, full study access |
| **doctor** | View & report | View studies, create reports |
| **technician** | Upload studies | Upload DICOM files, basic viewing |
| **viewer** | View only | Reception, billing staff |

Create users via: [manage_users.php](manage_users.php)

---

## 📈 Performance Tips

### For Better Performance:

1. **Use SSD** for database storage
2. **Gigabit network** (1000 Mbps) between PCs
3. **16GB+ RAM** on server PC
4. **Dedicated server** (don't use for other tasks)
5. **Regular backups** (daily automated)

---

## 🔒 Security Checklist

- [ ] Change default passwords (`admin123`, `12345`)
- [ ] Use strong passwords (12+ chars, mixed case, numbers, symbols)
- [ ] Restrict MySQL users by IP: `@'192.168.1.%'`
- [ ] Enable firewall on all PCs
- [ ] Only allow port 3306 from trusted IPs
- [ ] Regular database backups
- [ ] Update software regularly
- [ ] Use HTTPS for web interface (production)
- [ ] Enable MySQL SSL (for sensitive data)

---

## 📞 Support

**Created Scripts Summary:**
- ✅ Database reset & license creation
- ✅ User management (create/delete/reset passwords)
- ✅ Connection testing
- ✅ Network setup guide
- ✅ Migration scripts

**All working and tested!** 🎉

---

## 🎬 Final Checklist

Before going live:

- [ ] Run migration: [run_setup_migration.php](run_setup_migration.php)
- [ ] Test connection: [test_connection.php](test_connection.php)
- [ ] Create superadmin: [manage_users.php](manage_users.php)
- [ ] Test wizard flow with fresh license
- [ ] Test tour on patients page
- [ ] Configure network (if multi-PC)
- [ ] Create user accounts for staff
- [ ] Set up automated backups
- [ ] Change all default passwords
- [ ] Document your specific setup

**Ready to deploy!** 🚀
