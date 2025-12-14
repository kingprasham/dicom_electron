# 🖥️ DICOM Viewer - Desktop App Setup Guide

## Step-by-Step Instructions

### Step 1: Install Node.js
Download and install Node.js from: https://nodejs.org/
(Choose the LTS version)

After installation, verify by running in PowerShell:
```powershell
node --version
npm --version
```

---

### Step 2: Navigate to Electron Folder
Open PowerShell and run:
```powershell
cd "c:\xampp\htdocs\papa\dicom_again\claude\desktop-version\electron"
```

---

### Step 3: Install Dependencies
```powershell
npm install
```
This will download Electron and required packages (~150MB).

---

### Step 4: Run the Desktop App
```powershell
npm start
```

This will:
1. Start the PHP server automatically
2. Open a native desktop window
3. Load the DICOM Viewer app

---

### Step 5: Build an Installer (Optional)
To create a standalone .exe installer:
```powershell
npm run build:win
```

The installer will be created in: `dist/` folder

---

## Troubleshooting

### "PHP not found"
Make sure XAMPP is installed and PHP is in your PATH:
```powershell
$env:PATH += ";C:\xampp\php"
```

### "MySQL not running"
Start MySQL from XAMPP Control Panel before running the app.

### "Orthanc not found"
The app will work without Orthanc, but DICOM storage won't function.
Install Orthanc from: https://orthanc-server.com/download.php

---

## Login Credentials

- **Email**: admin@hospital.com
- **Password**: Admin@123

---

## What This Does

The Electron app:
1. Starts a PHP server on port 8080
2. Opens a native desktop window (like a Chrome app)
3. Loads your DICOM viewer inside
4. Provides a native menu bar with File, View, Tools, Help
5. Shuts down the PHP server when you close the app

It's a real desktop app - no browser needed!
