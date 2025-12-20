# Server Mode Deployment Plan

## Overview
Deploy DICOM Viewer Pro for a hospital with 100+ PCs using a centralized server architecture.

---

## Current Architecture Analysis

The application already supports shared database and Orthanc through `.env` configuration:
- `DB_HOST` - MySQL server address
- `ORTHANC_URL` - Orthanc DICOM server URL

This means **basic server mode is already possible** with minimal changes.

---

## Proposed Architecture

```
                    ┌─────────────────────────────────────────┐
                    │         CENTRAL SERVER (1x)             │
                    │  ┌─────────────┐  ┌─────────────────┐   │
                    │  │   MySQL     │  │  Orthanc PACS   │   │
                    │  │  Database   │  │  DICOM Server   │   │
                    │  │  Port 3306  │  │  Port 8042      │   │
                    │  └─────────────┘  └─────────────────┘   │
                    │         IP: 192.168.1.100               │
                    └───────────────────┬─────────────────────┘
                                        │ LAN Network
            ┌───────────────────────────┼───────────────────────────┐
            │                           │                           │
    ┌───────┴───────┐          ┌────────┴────────┐         ┌────────┴────────┐
    │   PC 1        │          │   PC 2          │         │   PC 100        │
    │ Sono Room 1   │          │ X-Ray Room 1    │   ...   │ CT Scan Room    │
    │               │          │                 │         │                 │
    │ Electron App  │          │ Electron App    │         │ Electron App    │
    │ (Local PHP)   │          │ (Local PHP)     │         │ (Local PHP)     │
    │               │          │                 │         │                 │
    │ Points to:    │          │ Points to:      │         │ Points to:      │
    │ - Remote MySQL│          │ - Remote MySQL  │         │ - Remote MySQL  │
    │ - Remote Orthanc         │ - Remote Orthanc│         │ - Remote Orthanc│
    └───────────────┘          └─────────────────┘         └─────────────────┘
```

---

## Implementation Steps

### Phase 1: Server Setup (Central Server)

1. **Install MySQL Server**
   - Create database: `dicom_viewer_hospital`
   - Create user with remote access permissions
   - Configure firewall to allow port 3306 from LAN

2. **Install Orthanc PACS**
   - Configure to listen on all interfaces
   - Enable remote access
   - Configure firewall to allow port 8042 and 4242 (DICOM)

3. **Run Database Migrations**
   - Execute all SQL migrations on central database

### Phase 2: License & Location Configuration

1. **Create Enterprise License**
   - In Super Admin: Create license with `max_activations = 100+`
   - License type: `enterprise`

2. **Pre-create Locations**
   - Add all room/location entries:
     - SONO1, SONO2, SONO3 (Sonography rooms)
     - XRAY1, XRAY2 (X-Ray rooms)
     - CT1 (CT Scan)
     - MRI1 (MRI)
     - etc.

### Phase 3: Client Configuration

Each client PC needs a `.env` file pointing to the central server:

```env
# Database - Point to central server
DB_HOST=192.168.1.100
DB_PORT=3306
DB_USER=dicom_viewer
DB_PASSWORD=your_secure_password
DB_NAME=dicom_viewer_hospital

# Orthanc - Point to central server
ORTHANC_URL=http://192.168.1.100:8042
ORTHANC_USERNAME=orthanc
ORTHANC_PASSWORD=orthanc

# Session
SESSION_LIFETIME=3600

# App
APP_NAME=Hospital DICOM Viewer Pro
```

### Phase 4: Activation & Location Assignment

For each PC:
1. Start the Electron app
2. Activate using the enterprise license key (same key for all PCs)
3. Go to Private Settings → Location Management
4. Assign the PC to its location (e.g., "Sono Room 1")

---

## Required Code Changes

### 1. Add "Deployment Mode" Setting

```php
// config.php
define('DEPLOYMENT_MODE', getenv('DEPLOYMENT_MODE') ?: 'standalone');
// Values: 'standalone' | 'client' | 'server'
```

### 2. Client Mode Enhancements

- Skip local Orthanc check in client mode
- Show connection status to central server
- Handle network disconnection gracefully

### 3. Auto Location Assignment on First Run

Allow each PC to self-assign its location on first activation:
- Show location selection after license activation
- Remember and lock location per machine_id

### 4. Server Health Check

Add API endpoint to check server connectivity:
```
GET /api/health-check.php
Response: { "status": "ok", "database": true, "orthanc": true }
```

### 5. Batch Client Configuration Tool

Create a setup script that:
- Takes server IP as input
- Generates `.env` file for client
- Tests connection to database and Orthanc
- Displays success/error

---

## Database Tables to Update

### Add `deployment_config` table

```sql
CREATE TABLE deployment_config (
    id INT PRIMARY KEY DEFAULT 1,
    deployment_mode ENUM('standalone', 'server', 'client') DEFAULT 'standalone',
    server_url VARCHAR(255),
    hospital_code VARCHAR(50),
    last_sync DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

---

## Security Considerations

1. **MySQL User Permissions**
   - Create dedicated user for DICOM Viewer
   - Grant only required permissions
   - Use strong password

2. **Network Security**
   - Use firewall to restrict access to LAN only
   - Consider VPN for remote locations

3. **Orthanc Security**
   - Change default credentials
   - Restrict access by IP if possible

---

## Print Tracking with Locations

With location-assigned PCs, the Super Admin can see:
- Total prints per location
- Pages printed per room
- Which room printed which report
- Cost analysis by department/location

---

## Deployment Checklist

### Server Setup
- [ ] MySQL installed and configured
- [ ] Orthanc installed and configured
- [ ] Database created and migrations run
- [ ] Enterprise license created (100+ activations)
- [ ] Locations pre-created
- [ ] Firewall configured

### Per-Client Setup
- [ ] Install Electron app
- [ ] Copy `.env` file with server details
- [ ] Activate with enterprise license key
- [ ] Assign location to this PC
- [ ] Test DICOM viewing
- [ ] Test printing

---

## Approval Required

Please review this plan and confirm:
1. Is the proposed architecture acceptable?
2. Should we proceed with implementing the code changes?
3. Any additional requirements for your specific setup?
