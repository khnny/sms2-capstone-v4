<?php
/**
 * Optional machine-specific settings (LOCAL XAMPP).
 *
 * Copy this file to config/local.php on the computer that needs custom
 * values. Keep config/local.php private if it contains real passwords.
 *
 * Same codebase for Local and HostForge — only DB connection config differs.
 * After the single-DB migration, SMS2 and CRAD share one schema with
 * sms2_* and crad_* table prefixes (see config/tables.php).
 */

// Optional: OpenAI GPT-4.1 advisory document analysis and schedule ranking.
// Configure SMS2_OPENAI_API_KEY / OPENAI_API_KEY in the server environment,
// or store the key in storage/keys/openai_api_key (gitignored). Never commit it.
// define('OPENAI_API_KEY', '');

// Optional SMTP overrides. Prefer System Settings, HostForge env
// SMS2_SMTP_PASSWORD, or storage/keys/smtp_app_password (gitignored).
// define('SMS2_SMTP_USERNAME', 'your.account@gmail.com');
// define('SMS2_SMTP_PASSWORD', 'xxxx xxxx xxxx xxxx');

// Optional local equivalent of the HostForge SMS2_APP_KEY environment secret.
// Preserve the exact existing key to decrypt saved secrets; never generate a
// replacement when encrypted database values already exist.
// HostForge Docker deployments should set SMS2_APP_KEY in platform secrets
// because storage/keys/app.key is excluded from the Docker build context.
// define('SMS2_APP_KEY', '');

// --- Google Drive data backup (see docs/DATA-BACKUP.md) ---
// Easiest: save the OAuth client JSON from Google Cloud as
// storage/keys/google-oauth-client.json (gitignored). Or define these here:
// define('SMS2_GDRIVE_CLIENT_ID', 'xxxxxxxx.apps.googleusercontent.com');
// define('SMS2_GDRIVE_CLIENT_SECRET', 'GOCSPX-xxxxxxxx');
// Random 16+ character secret that cron-job.org sends as X-Backup-Token:
// define('SMS2_BACKUP_CRON_TOKEN', 'change-me-to-a-long-random-string');
// Optional: who gets an email when a backup fails (default: Admin alert email).
// define('SMS2_BACKUP_NOTIFY_EMAIL', 'admin@example.com');

define('SMS2_LOCAL_BASE_URL', '/sms2_system');

// --- Main database (env-equivalent: DB_HOST, DB_DATABASE, …) ---
define('DB_CONNECTION', 'mysql');
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'sms2_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// --- Target architecture: ONE database for SMS2 + CRAD ---
// After migration, keep CRAD on the same DB as SMS2:
define('CRAD_DB_NAME', 'sms2_db');
define('STUDENT_PORTAL_DB_NAME', 'sms2_db');
define('REPORTS_DB_NAME', 'sms2_db');
define('USERMGMT_DB_NAME', 'sms2_db');

// Pre-migration only (temporary): uncomment if CRAD still lives in crad_db
// and comment out CRAD_DB_NAME = sms2_db above.
// define('CRAD_DB_NAME', 'crad_db');
