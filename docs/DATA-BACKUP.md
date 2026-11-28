# Data backup to Google Drive

SMS2 backs up the database (SMS2 + CRAD) and all uploaded files to a Google Drive
folder. It uses the free 15 GB of a normal Google account, so you don't need to pay
for Google Cloud Storage.

- **What's in a backup:** one `.zip` named like `sms2-backup-2026-11-28_03-00-00.zip` with
  `database/sms2.sql` (full dump; `database/crad.sql` too if CRAD still uses its own database),
  `files/uploads/…`, `files/storage/uploads/…` and `manifest.json`.
- **When:** every day through cron-job.org, plus a **Back up now** button.
- **Where:** a Drive folder called **SMS2 Backups** that the app creates in the connected account.
- **Retention:** it keeps the newest backup of each of the last 7 days that have one, plus one per week for the 4 weeks
  before that. Older backups are deleted automatically. The safety copies made before a restore are kept for 7 days.
- **Admin page:** User Management → Settings → **Data Backup**. It shows the connection status, the backups in Drive
  (with download and restore buttons) and the history of every run.
- **Failure alert:** if a backup fails, an email goes to `SMS2_BACKUP_NOTIFY_EMAIL`, or else to the *Admin alert email*
  in System Settings, or else to the connected Google account. The Data Backup page also shows a warning.

The app only asks for the `drive.file` permission, so it can only see the files and folder it made itself. It can't
read anything else in the Google Drive.

## 1. Google Cloud (one time)

You already did most of this in the project **capstone-CradSystem**:

1. **APIs & Services → Library**: enable **Google Drive API**.
2. **OAuth consent screen**: set the publishing status to **In production**. While it says *Testing*, the sign-in
   expires every 7 days and backups stop working.
3. **Credentials → your OAuth client (Web application) → Authorized redirect URIs**. Add one for each place SMS2 runs:
   - Local XAMPP: `http://localhost/sms2-capstone-v3/backup/google-callback.php`
   - Live (later): `https://YOUR-DOMAIN/backup/google-callback.php`

   The Data Backup page shows the exact URI it uses. It has to match exactly, including `http`/`https` and the folder name.

## 2. Give SMS2 the client ID and secret

Never commit these to Git and never paste them in chat. Pick one of these:

**A. JSON file (easiest, local):** copy the JSON you downloaded from Google Cloud to

```
storage/keys/google-oauth-client.json
```

`storage/keys/` is gitignored and blocked from the web. The secret is in that file (`"client_secret"`). If your file
doesn't have it, open the OAuth client in Google Cloud and click **Add secret**.

**B. Config / environment variables (recommended on HostForge):**

| Key | Value |
| --- | --- |
| `SMS2_GDRIVE_CLIENT_ID` | `xxxx.apps.googleusercontent.com` |
| `SMS2_GDRIVE_CLIENT_SECRET` | `GOCSPX-…` |
| `SMS2_BACKUP_CRON_TOKEN` | a random string, 16+ characters (used by cron-job.org) |
| `SMS2_BACKUP_NOTIFY_EMAIL` | optional, who gets failure emails |
| `SMS2_GDRIVE_REDIRECT_URI` | optional, only if the auto-detected URI is wrong (e.g. behind a proxy) |
| `SMS2_GDRIVE_FOLDER_NAME` | optional, default `SMS2 Backups` |
| `SMS2_BACKUP_KEEP_DAILY` / `SMS2_BACKUP_KEEP_WEEKLY` | optional, default `7` / `4` |

On XAMPP you can put them in `config/local.php` with `define('SMS2_GDRIVE_CLIENT_ID', '…');`.
On HostForge, add them under **Environment Variables**. Also keep `SMS2_APP_KEY` set there: the saved Google
sign-in is encrypted with it.

## 3. Connect Google Drive

1. Log in as Super Admin → **User Management → Data Backup**.
2. Click **Connect Google Drive** and sign in with the Google account that will hold the backups. Allow the Drive permission.
3. Click **Back up now** to check that everything works. The zip appears in Drive under **SMS2 Backups**.

You only do this once for each server (local and live are separate because they use separate databases).

## 4. Daily schedule on cron-job.org

Create a cron job:

- **URL:** `https://YOUR-DOMAIN/backup/run.php`
- **Schedule:** every day, e.g. 3:00 AM (Asia/Manila)
- **Request method:** GET
- **Advanced → Headers:** `X-Backup-Token` = the value of `SMS2_BACKUP_CRON_TOKEN`

If you can't add headers, use `https://YOUR-DOMAIN/backup/run.php?token=YOUR_TOKEN` instead.

The URL answers right away with `202 {"status":"started"}` and the backup keeps running on the server, because
cron-job.org stops waiting after 30 seconds. Check the result on the Data Backup page. To test and wait for the result,
add `&wait=1` (or `?wait=1`). A wrong or missing token returns `404`.

cron-job.org can't reach `localhost`. On a local laptop, use the button, or Windows Task Scheduler with:

```
C:\xampp\php\php.exe A:\xampp_new\htdocs\sms2-capstone-v3\tools\run-backup.php
```

## 5. Restore

On the Data Backup page, click **Restore** next to a backup and type `RESTORE` to confirm. SMS2 then:

1. Makes a **safety backup** of the current data first and uploads it to Drive. If that fails, nothing is changed.
2. Replaces the database tables with the ones from the backup. Data changed after that backup is lost.
3. Puts back the uploaded files. Files with the same name are overwritten, and files added later are kept.

The current Google Drive connection and the backup history are not changed by a restore.

You can also restore by hand: download the zip, import `database/sms2.sql` in phpMyAdmin, and copy the `files/`
folders back.

## Files

| File | Purpose |
| --- | --- |
| `includes/gdrive-backup.php` | OAuth, Drive API (plain cURL, no Composer), dump, zip, upload, retention, restore |
| `backup/google-connect.php`, `backup/google-callback.php` | One-time Google sign-in |
| `backup/run.php` | Endpoint for cron-job.org |
| `tools/run-backup.php` | Command-line / Task Scheduler backup |
| `modules/user-management/pages/data-backup.php` | Admin page |
| `tests/gdrive_backup_test.php` | Round-trip test against a fake Drive (`php tests/gdrive_backup_test.php`) |

The run history is stored in the `sms2_backup_runs` table. It's created automatically and isn't included in backups.
The PHP `zip` and `curl` extensions are needed. Both are on in XAMPP, and the Dockerfile now installs `zip` for HostForge.
