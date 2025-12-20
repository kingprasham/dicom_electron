-- ================================================
-- RESET DATABASE FOR TESTING LICENSE ACTIVATION
-- ================================================
-- Run this in phpMyAdmin or MySQL CLI to clear all
-- license and setup data for fresh testing
-- ================================================

-- 1. Clear license activation data
TRUNCATE TABLE license_activations;
TRUNCATE TABLE installation_license;
TRUNCATE TABLE license_usage_stats;

-- 2. Clear setup completion flags
DELETE FROM settings WHERE setting_key = 'setup_complete';
DELETE FROM settings WHERE setting_key LIKE 'hospital_%';
DELETE FROM settings WHERE setting_key LIKE 'auto_import_%';

-- 3. Reset onboarding progress
TRUNCATE TABLE onboarding_progress;

-- 4. Clear all users EXCEPT super admin (be careful!)
-- This will remove all regular admin and user accounts created during setup
DELETE FROM users WHERE is_super_admin = 0 OR is_super_admin IS NULL;

-- 5. Clear all sessions
TRUNCATE TABLE sessions;

-- ================================================
-- ALTERNATIVE: If you want to keep existing users
-- and just reset setup flags, use this instead:
-- ================================================
-- UPDATE users SET setup_completed = 0 WHERE is_super_admin = 0;
-- DELETE FROM settings WHERE setting_key = 'setup_complete';
-- TRUNCATE TABLE license_activations;
-- TRUNCATE TABLE installation_license;
-- TRUNCATE TABLE sessions;

-- ================================================
-- AFTER RUNNING THIS:
-- 1. Restart your browser (clear all cookies/cache)
-- 2. Go to http://localhost/activate-license.php
-- 3. Enter your license key
-- 4. Click "Complete Setup Wizard (Recommended)"
-- 5. Fill all 4 steps of the wizard
-- 6. Should redirect to patients page with tour=1
-- ================================================
