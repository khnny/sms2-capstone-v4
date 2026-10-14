-- SMS2/CRAD deployment schema, 14 October 2026.
-- Import into an empty selected hosting database.
-- SMS2 preserves existing accounts; CRAD contains schema only.
SET NAMES utf8mb4;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET FOREIGN_KEY_CHECKS = 0;

-- Table: sms2_activity_logs
CREATE TABLE `sms2_activity_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `user_name` varchar(150) DEFAULT NULL,
  `role_key` varchar(40) DEFAULT NULL,
  `action` varchar(40) NOT NULL,
  `module_key` varchar(60) DEFAULT NULL,
  `detail` varchar(500) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_logs_user` (`user_id`),
  KEY `idx_logs_action` (`action`),
  KEY `idx_logs_created` (`created_at`),
  CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`) REFERENCES `sms2_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: sms2_admin_announcements
CREATE TABLE `sms2_admin_announcements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(180) NOT NULL,
  `body` text NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `status` enum('draft','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  `audience` varchar(40) NOT NULL DEFAULT 'all',
  `category` varchar(40) NOT NULL DEFAULT 'General',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_by_name` varchar(150) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `published_at` datetime DEFAULT NULL,
  `scheduled_for` date DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ann_status_published` (`status`,`published_at`),
  KEY `idx_ann_audience` (`audience`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: sms2_deadline_reminders
CREATE TABLE `sms2_deadline_reminders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `source_key` varchar(80) NOT NULL,
  `deadline_date` date NOT NULL,
  `remind_days` tinyint(3) unsigned NOT NULL,
  `title` varchar(190) NOT NULL DEFAULT '',
  `group_label` varchar(255) NOT NULL DEFAULT '',
  `message` text DEFAULT NULL,
  `email_status` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_deadline_reminder` (`user_id`,`source_key`,`deadline_date`,`remind_days`),
  KEY `idx_deadline_reminder_user` (`user_id`,`is_read`,`deadline_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: sms2_login_throttles
CREATE TABLE `sms2_login_throttles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `throttle_key` char(64) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_login_throttle_key` (`throttle_key`),
  KEY `idx_login_throttle_ip` (`ip_address`),
  KEY `idx_login_throttle_locked` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: sms2_password_reset_requests
CREATE TABLE `sms2_password_reset_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `module_key` varchar(60) NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `requested_password_hash` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `admin_id` int(10) unsigned DEFAULT NULL,
  `admin_note` varchar(500) DEFAULT NULL,
  `temp_password_set` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_prr_user` (`user_id`),
  KEY `idx_prr_status` (`status`),
  KEY `idx_prr_module` (`module_key`),
  KEY `fk_prr_admin` (`admin_id`),
  CONSTRAINT `fk_prr_admin` FOREIGN KEY (`admin_id`) REFERENCES `sms2_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_prr_user` FOREIGN KEY (`user_id`) REFERENCES `sms2_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: sms2_password_resets
CREATE TABLE `sms2_password_resets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_reset_user` (`user_id`),
  KEY `idx_reset_token` (`token_hash`),
  KEY `idx_reset_expires` (`expires_at`),
  CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `sms2_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: sms2_role_permissions
CREATE TABLE `sms2_role_permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role_key` varchar(40) NOT NULL,
  `module_key` varchar(60) NOT NULL,
  `granted` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_module` (`role_key`,`module_key`),
  KEY `idx_perm_module` (`module_key`),
  CONSTRAINT `fk_perm_role` FOREIGN KEY (`role_key`) REFERENCES `sms2_roles` (`role_key`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2593 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sms2_role_permissions` (`id`, `role_key`, `module_key`, `granted`, `updated_at`) VALUES
(1156, 'superadmin', 'user-management', 1, '2026-08-31 10:29:00'),
(1157, 'admission', 'enrollment', 1, '2026-08-31 10:29:00'),
(1158, 'registrar', 'registrar', 1, '2026-08-31 10:29:00'),
(1159, 'registrar', 'curriculum', 1, '2026-08-31 10:29:00'),
(1160, 'registrar', 'scheduling', 1, '2026-08-31 10:29:00'),
(1161, 'crad_officer', 'crad', 1, '2026-08-31 10:29:00'),
(1162, 'research_coordinator', 'crad', 1, '2026-08-31 10:29:00'),
(1163, 'department_chair', 'crad', 1, '2026-08-31 10:29:00'),
(1164, 'research_office', 'crad', 1, '2026-08-31 10:29:00'),
(1165, 'research_director', 'faculty', 1, '2026-08-31 10:29:00'),
(1166, 'grammarian', 'faculty', 1, '2026-08-31 10:29:00'),
(1167, 'review_committee', 'crad_grant', 1, '2026-08-31 10:29:00'),
(1168, 'panel', 'faculty', 1, '2026-08-31 10:29:00'),
(1169, 'finance', 'payment', 1, '2026-08-31 10:29:00'),
(1170, 'osa', 'cocurricular', 1, '2026-08-31 10:29:00'),
(1171, 'it_office', 'lms', 1, '2026-08-31 10:29:00'),
(1172, 'qa', 'accreditation', 1, '2026-08-31 10:29:00'),
(1173, 'vpaa', 'accreditation', 1, '2026-08-31 10:29:00'),
(1174, 'hr', 'faculty', 1, '2026-08-31 10:29:00'),
(1175, 'student', 'student_portal', 1, '2026-08-31 10:29:00'),
(1179, 'sms_admin', 'enrollment', 1, '2026-09-18 22:08:12'),
(1180, 'sms_admin', 'registrar', 1, '2026-09-18 22:08:12'),
(1181, 'sms_admin', 'curriculum', 1, '2026-09-18 22:08:12'),
(1182, 'sms_admin', 'accreditation', 1, '2026-09-18 22:08:12'),
(1183, 'sms_admin', 'payment', 1, '2026-09-18 22:08:12'),
(1184, 'sms_admin', 'faculty', 1, '2026-09-18 22:08:12'),
(1185, 'sms_admin', 'scheduling', 1, '2026-09-18 22:08:12'),
(1186, 'sms_admin', 'cocurricular', 1, '2026-09-18 22:08:12'),
(1187, 'sms_admin', 'lms', 1, '2026-09-18 22:08:12'),
(1188, 'sms_admin', 'crad', 1, '2026-09-18 22:08:12'),
(1189, 'adviser', 'faculty', 1, '2026-08-31 10:29:24'),
(1193, 'research_grant', 'crad_grant', 1, '2026-08-31 10:29:24'),
(1945, 'department_head', 'crad', 1, '2026-09-19 01:01:39');

-- Table: sms2_roles
CREATE TABLE `sms2_roles` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `role_key` varchar(40) NOT NULL,
  `label` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_key` (`role_key`)
) ENGINE=InnoDB AUTO_INCREMENT=2151 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sms2_roles` (`id`, `role_key`, `label`, `description`, `is_system`, `created_at`) VALUES
(1, 'admin', 'Super Admin', 'Legacy super admin access', 1, '2026-07-22 22:24:44'),
(2, 'registrar', 'Registrar', 'Enrollment, records, scheduling', 1, '2026-07-22 22:24:44'),
(3, 'finance', 'Finance', 'Payments and receivables', 1, '2026-07-22 22:24:44'),
(4, 'hr', 'Dean', 'Dean and faculty processes', 1, '2026-07-22 22:24:44'),
(5, 'it_office', 'IT Office', 'LMS and IT modules', 1, '2026-07-22 22:24:44'),
(6, 'osa', 'OSA', 'Student affairs / co-curricular', 1, '2026-07-22 22:24:44'),
(7, 'qa', 'QA Office', 'Accreditation and quality', 1, '2026-07-22 22:24:44'),
(8, 'crad_officer', 'CRAD Officer', 'Research and development', 1, '2026-07-22 22:24:44'),
(9, 'student', 'Student', 'Student portal only', 1, '2026-07-22 22:24:44'),
(10, 'superadmin', 'Super Admin', 'Full system access', 1, '2026-08-08 17:25:19'),
(11, 'admission', 'Admission', 'Admission office access', 1, '2026-08-08 17:25:19'),
(56, 'research_coordinator', 'Research Coordinator', 'Research coordination access', 1, '2026-08-08 18:13:51'),
(102, 'adviser', 'Adviser', 'Research adviser faculty account', 1, '2026-08-08 21:35:14'),
(213, 'research_director', 'Research Director', 'Research defense scheduling director account', 1, '2026-08-09 19:31:14'),
(384, 'research_grant', 'CRAD Officer', 'Research grant management access', 1, '2026-08-10 20:01:49'),
(770, 'grammarian', 'Grammarian', 'Research grammar and manuscript evaluation account', 1, '2026-08-14 11:06:49'),
(788, 'panel', 'Panel Member', 'Research defense panel account', 1, '2026-08-15 17:07:16'),
(800, 'sms_admin', 'Admin', 'General administrator account', 1, '2026-08-18 00:38:50'),
(901, 'review_committee', 'Review Committee', 'Grant proposal review and rubric evaluation', 1, '2026-08-31 07:04:35'),
(1206, 'department_chair', 'Department Chair', 'Grant approval — department chair sign-off', 1, '2026-08-31 10:29:00'),
(1207, 'research_office', 'Research Office', 'Grant approval — research office sign-off', 1, '2026-08-31 10:29:00'),
(1208, 'vpaa', 'VPAA', 'Grant approval — VPAA sign-off', 1, '2026-08-31 10:29:00'),
(1686, 'department_head', 'Department Head', 'Adviser and panel assignment', 1, '2026-09-19 01:01:39');

-- Table: sms2_security_otps
CREATE TABLE `sms2_security_otps` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `purpose` varchar(40) NOT NULL,
  `code_hash` char(64) NOT NULL,
  `module_key` varchar(60) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_otp_user` (`user_id`),
  KEY `idx_otp_expires` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: sms2_student_profiles
CREATE TABLE `sms2_student_profiles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `student_id` varchar(40) NOT NULL,
  `program` varchar(200) NOT NULL DEFAULT 'Bachelor of Science in Information Technology',
  `year_level` varchar(40) NOT NULL DEFAULT '4th Year',
  `section` varchar(40) NOT NULL DEFAULT 'BSIT 4A',
  `semester` varchar(40) NOT NULL DEFAULT '1st Semester',
  `school_year` varchar(20) NOT NULL DEFAULT '2026-2027',
  `enrollment_status` varchar(40) NOT NULL DEFAULT 'Enrolled',
  `standing` varchar(40) NOT NULL DEFAULT 'Good Standing',
  `mobile` varchar(40) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `guardian` varchar(150) DEFAULT NULL,
  `guardian_contact` varchar(40) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sp_user` (`user_id`),
  UNIQUE KEY `uq_sp_student_id` (`student_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sms2_student_profiles` (`id`, `user_id`, `student_id`, `program`, `year_level`, `section`, `semester`, `school_year`, `enrollment_status`, `standing`, `mobile`, `address`, `guardian`, `guardian_contact`, `created_at`, `updated_at`) VALUES
(1, 9, 'S230000001', 'Bachelor of Science in Information Technology', '4th Year', 'BSIT 4B', '1st Semester', '2026-2027', 'Enrolled', 'Good Standing', '0917 000 0011', 'Fairview, Quezon City', 'Juan Dela Cruz', '0918 000 0012', '2026-09-19 00:29:23', '2026-09-19 00:29:23'),
(2, 1354, 'S230106713', 'Bachelor of Science in Information Technology', '4th Year', 'BSIT 4A', '1st Semester', '2026-2027', 'Enrolled', 'Good Standing', '0917 000 0001', 'Novaliches, Quezon City', 'Maria Dela Cruz', '0918 000 0002', '2026-09-19 00:29:23', '2026-09-19 00:29:23'),
(3, 1558, 'S240115700', 'Bachelor of Science in Information Technology', '4th Year', 'BSIT 4A', '1st Semester', '2026-2027', 'Enrolled', 'Good Standing', '', '', '', '', '2026-09-20 21:13:12', '2026-09-20 21:13:12'),
(4, 1564, 'S230106714', 'Bachelor of Science in Information Technology', '4th Year', 'BSIT 4A', '1st Semester', '2026-2027', 'Enrolled', 'Good Standing', '', '', '', '', '2026-09-20 21:13:55', '2026-09-20 21:13:55');

-- Table: sms2_system_settings
CREATE TABLE `sms2_system_settings` (
  `setting_key` varchar(80) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sms2_system_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('crad_active_term', '', '2026-08-28 07:17:54'),
('csrf_enabled', '1', '2026-07-22 22:24:44'),
('lockout_minutes', '1', '2026-07-23 08:05:06'),
('lockout_seconds', '15', '2026-07-23 08:05:06'),
('lockout_unit', 'seconds', '2026-07-23 08:05:06'),
('lockout_value', '15', '2026-07-23 08:05:06'),
('login_captcha_enabled', '1', '2026-09-20 22:39:57'),
('mail_admin_email', 'kennethabejuela0308@gmail.com', '2026-09-21 00:25:26'),
('mail_from_email', 'kennethabejuela0308@gmail.com', '2026-09-21 00:25:26'),
('mail_from_name', 'BCP', '2026-09-21 00:25:26'),
('mail_show_link_on_failure', '1', '2026-09-20 23:40:19'),
('max_failed_logins', '3', '2026-07-23 07:33:05'),
('min_password_length', '8', '2026-07-22 22:24:44'),
('module_kick_epoch_crad', '1784849304', '2026-07-23 15:28:24'),
('module_maintenance_crad', '0', '2026-07-23 15:29:22'),
('module_maintenance_msg_crad', 'The system is currently under maintenance. Some services may be temporarily unavailable.\r\n\r\nThank you for your patience and understanding.', '2026-07-23 15:05:14'),
('module_maintenance_student_portal', '0', '2026-09-20 22:35:46'),
('password_expiry_days', '0', '2026-07-22 22:24:44'),
('require_password_change_first_login', '0', '2026-07-22 22:24:44'),
('session_timeout_minutes', '30', '2026-09-21 02:07:26'),
('smtp_encryption', 'tls', '2026-07-23 10:33:25'),
('smtp_host', 'smtp.gmail.com', '2026-07-23 10:51:48'),
('smtp_password', 'sms2enc1.Oa0f+lo0nzQC028oI5zSiNa6z6C83V62Rv132wC9nACe36djJjr19CcL9Bs=', '2026-09-21 00:25:26'),
('smtp_port', '587', '2026-07-23 10:33:25'),
('smtp_username', 'kennethabejuela0308@gmail.com', '2026-09-21 00:25:26'),
('turnstile_site_key', '', '2026-09-20 22:39:57');

-- Table: sms2_user_authenticators
CREATE TABLE `sms2_user_authenticators` (
  `user_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `secret` varchar(512) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `pending_secret` varchar(512) DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1599 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: sms2_user_passkeys
CREATE TABLE `sms2_user_passkeys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `credential_id` varchar(1024) NOT NULL,
  `public_key` text NOT NULL,
  `sign_count` int(10) unsigned NOT NULL DEFAULT 0,
  `device_name` varchar(120) NOT NULL DEFAULT 'Passkey',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_passkey_cred` (`credential_id`(255)),
  KEY `idx_passkey_user` (`user_id`),
  CONSTRAINT `fk_passkey_user` FOREIGN KEY (`user_id`) REFERENCES `sms2_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: sms2_users
CREATE TABLE `sms2_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(80) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `role_key` varchar(40) NOT NULL,
  `student_id` varchar(40) DEFAULT NULL,
  `status` enum('active','inactive','locked','suspended') NOT NULL DEFAULT 'active',
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `failed_login_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role_key`),
  KEY `idx_users_status` (`status`),
  KEY `idx_users_student_id` (`student_id`),
  KEY `idx_users_last_seen` (`last_seen_at`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_key`) REFERENCES `sms2_roles` (`role_key`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sms2_users` (`id`, `username`, `email`, `password_hash`, `full_name`, `role_key`, `student_id`, `status`, `must_change_password`, `failed_login_attempts`, `locked_until`, `password_changed_at`, `last_login_at`, `last_seen_at`, `last_login_ip`, `notes`, `created_at`, `updated_at`) VALUES
(1, 'superadmin', 'j14677365@gmail.com', '$2y$10$a4NaRWDw7.1Jt2ps9MNI.uodIEoJfOZGqRflwoapW9OtP8e7SKAoC', 'Super Admin', 'superadmin', NULL, 'active', 0, 0, NULL, '2026-08-31 10:56:14', '2026-09-21 01:28:42', '2026-09-21 18:13:11', '::1', NULL, '2026-07-22 22:53:59', '2026-09-21 02:13:11'),
(2, 'registrar', 'registrar@bestlink.edu.ph', '$2y$10$/HmOuAP54dAuUkNOyNJo/e2GwrAszJqpF0sQmGvjofAtM/.6tcp.m', 'Registrar', 'registrar', NULL, 'active', 0, 0, NULL, '2026-08-31 07:50:19', '2026-08-08 22:06:54', NULL, '::1', NULL, '2026-07-22 22:53:59', '2026-08-31 10:23:26'),
(3, 'cradofficer', 'cradofficer@bestlink.ph', '$2y$10$IpnqwpL9JnMUhHbSOgfxJ.4ra3ccLSYj/jBiRdE5ZcdxVliR2HA3K', 'CRAD Officer', 'crad_officer', NULL, 'active', 0, 0, NULL, '2026-08-31 10:56:14', '2026-09-21 02:02:33', NULL, '::1', 'sdada', '2026-07-22 22:53:59', '2026-09-21 02:19:10'),
(4, 'finance', 'finance@bestlink.edu.ph', '$2y$10$DRoqe4euabvGssHKV0nAoeRBxT4pkP1yVa2NIiND4LYfUJq7VKVRe', 'Finance', 'finance', NULL, 'active', 0, 0, NULL, '2026-08-31 12:10:16', '2026-09-18 03:30:16', NULL, '::1', NULL, '2026-07-22 22:54:00', '2026-09-18 03:31:00'),
(5, 'studentaffairs', 'studentaffairs@bestlink.edu.ph', '$2y$10$ykS9zsSeg8ESbJDrnyaixuRg.OYKWUljfEzgDhwBWsn4MYjdRR9O2', 'Student Affairs', 'osa', NULL, 'active', 0, 0, NULL, '2026-08-31 07:50:19', NULL, NULL, NULL, NULL, '2026-07-22 22:54:00', '2026-08-31 10:23:26'),
(6, 'itofficer', 'itofficer@bestlink.edu.ph', '$2y$10$h1GQBrr0K5SM8whZCT2QxOmvpIN2aPKslctCSX3VMflxoiHVIdWGC', 'IT Officer', 'it_office', NULL, 'active', 0, 0, NULL, '2026-08-31 07:50:19', NULL, NULL, NULL, NULL, '2026-07-22 22:54:00', '2026-08-31 10:23:26'),
(7, 'qualityassurance', 'qualityassurance@bestlink.edu.ph', '$2y$10$cqKm0cN1jMdxpdS5l3yee.ygI3KG05tRBGw5cyagSwNFT.6YaytVq', 'Quality Assurance', 'qa', NULL, 'active', 0, 0, NULL, '2026-08-31 07:50:20', NULL, NULL, NULL, NULL, '2026-07-22 22:54:00', '2026-08-31 10:23:26'),
(8, 'dean', 'dean@bestlink.edu.ph', '$2y$10$ADDDVUeDkcHKTZ90VfeuIOOaEFdFBcybnJNFT.QIMWYcEGnm9cf1m', 'Dean', 'hr', NULL, 'active', 0, 0, NULL, '2026-08-31 10:56:14', '2026-09-18 03:28:45', NULL, '::1', NULL, '2026-07-22 22:54:00', '2026-09-18 03:29:01'),
(9, 's230000001', 's230000001@bestlink.edu.ph', '$2y$10$A94r731PvDnsBaltuho/quGmMR6T6TvsBh5feQx6d6avkQILTNvW2', 'Student User', 'student', 'S230000001', 'active', 0, 0, NULL, '2026-08-31 10:56:14', '2026-09-18 03:05:26', '2026-09-18 03:36:27', '::1', NULL, '2026-07-22 22:54:00', '2026-09-18 03:36:27'),
(20, 'admission', 'admission@bestlink.edu.ph', '$2y$10$1M./oyAWOwzHhIjWoxGCWu5wm/6F/Jc3bzmYeF7hLt/jnhzg6KW9u', 'Admission', 'admission', NULL, 'active', 0, 0, NULL, '2026-08-31 07:50:19', NULL, NULL, NULL, NULL, '2026-08-08 17:25:20', '2026-08-31 10:23:26'),
(40, 'researchcoordinator', 'researchcoordinator@bestlink.edu.ph', '$2y$10$cC78.kC9YWDKQmoQ/BhG3Ou5wNb/rCaLooIiMLhwjV05e05w5nC3C', 'Mrs. Kris Guevarra', 'research_coordinator', NULL, 'active', 0, 0, NULL, '2026-08-31 10:56:14', '2026-09-21 01:18:23', NULL, '::1', NULL, '2026-08-08 18:09:48', '2026-09-21 01:18:36'),
(54, 'rsantos', 'rsantos@bestlink.edu.ph', '$2y$10$b/pnRSE8GFojLln4g5It9u946pzW57evxzWArhQso9oUxeqappwYq', 'Dr. Roberto M. Santos', 'adviser', NULL, 'active', 0, 0, NULL, '2026-08-31 10:56:14', '2026-09-21 01:46:59', NULL, '::1', NULL, '2026-08-08 21:35:14', '2026-09-21 01:54:45'),
(475, 'grammarian', 'grammarian@bestlink.edu.ph', '$2y$10$5hr6Wv2B84sMn2aCFcqB6u/36lx8QHnep5c7BJRMd3GyKhAH4xAwi', 'Kyle Kuzma', 'grammarian', NULL, 'active', 0, 0, NULL, '2026-09-19 10:03:34', '2026-09-21 01:25:11', NULL, '::1', NULL, '2026-08-14 11:06:49', '2026-09-21 01:28:06'),
(491, 'jobertvalentino', 'jobertvalentino@bestlink.edu.ph', '$2y$10$ouhKTKDlt29J1UyCrPm9r.I31sv6i4DE/r.mKPbhwK7TVtuxqS0Fm', 'Dr. Jobert Valentino', 'panel', NULL, 'active', 0, 0, NULL, '2026-08-31 10:56:13', '2026-09-21 02:15:15', NULL, '::1', NULL, '2026-08-15 17:07:16', '2026-09-21 02:15:32'),
(492, 'jonathanestrada', 'jonathanestrada@bestlink.edu.ph', '$2y$10$dQsnxqrSSWWl2gr3YgFqOuFgS4Z18E1MHOqAh51hhhL3mLFgbnAbS', 'Dr. Jonathan Estrada', 'panel', NULL, 'active', 0, 0, NULL, '2026-08-31 10:56:13', '2026-09-21 02:15:41', NULL, '::1', NULL, '2026-08-15 17:07:16', '2026-09-21 02:19:17'),
(493, 'michelleguevarra', 'michelleguevarra@bestlink.edu.ph', '$2y$10$YGhw2uzbxt6ENl46DQ8LkekrJ7Rd5o.mUy/TbNBg/bUUqFD5rfRym', 'Dr. Michelle Guevarra', 'panel', NULL, 'active', 0, 0, NULL, '2026-08-31 10:56:13', '2026-09-19 12:35:59', NULL, '::1', NULL, '2026-08-15 17:07:16', '2026-09-19 12:36:03'),
(758, 'admin', 'admin@bestlink.edu.ph', '$2y$10$MkfbK0pT7baUocnxCt9NBONcL1yxJ3SeVBmIBuIWRPRFr1CwUfQCG', 'Admin', 'sms_admin', NULL, 'active', 0, 0, NULL, '2026-09-21 01:28:58', '2026-09-21 02:13:51', NULL, '::1', NULL, '2026-08-18 00:38:50', '2026-09-21 02:14:13'),
(766, 'reviewcommittee', 'reviewcommittee@bestlink.edu.ph', '$2y$10$6iOIYjb89i9ErroqSX/u9ur7qyw2u8zausvK16nSPHkRotXFek8Ne', 'Review Committee', 'review_committee', NULL, 'active', 0, 0, NULL, '2026-09-19 01:26:53', '2026-09-19 01:31:07', NULL, '::1', NULL, '2026-08-31 07:04:36', '2026-09-19 01:31:15'),
(990, 'deptchair', 'deptchair@bestlink.edu.ph', '$2y$10$..8x.zJiNd8J7Nt.ayYpSO4n0okueUM9YcV0KErvmqbS6LcfHi4h6', 'Dr. Joseph Alcantara', 'department_chair', NULL, 'active', 0, 0, NULL, '2026-08-31 10:56:14', '2026-09-21 02:14:45', NULL, '::1', NULL, '2026-08-31 10:19:39', '2026-09-21 02:15:07'),
(991, 'researchoffice', 'researchoffice@bestlink.edu.ph', '$2y$10$Yjc6RQ6xI9hfWhDi5L6X..mXC3B3DE2r1H0xwSvqeyaQlw.tkuzVu', 'Research Office', 'research_office', NULL, 'active', 0, 0, NULL, '2026-08-31 10:56:14', '2026-09-18 03:29:13', NULL, '::1', NULL, '2026-08-31 10:19:39', '2026-09-18 03:29:38'),
(992, 'vpaa', 'vpaa@bestlink.edu.ph', '$2y$10$aBDCU/R1ICzX.oy1SOwT6.pnhhRxRKI4fXGBMM0Wm1mUOPgc/Ae5W', 'VPAA', 'vpaa', NULL, 'active', 0, 0, NULL, '2026-08-31 10:56:14', '2026-09-18 03:29:44', NULL, '::1', NULL, '2026-08-31 10:19:39', '2026-09-18 03:30:09'),
(1354, 's230106713', 'kennethabejuela0308@gmail.com', '$2y$10$hlVtDsX6ruX3NjLwkUeRA.Fel8OfC3MQ3p0Nvz93.tAu3COgbW.Cm', 'John Kenneth Abejuela', 'student', 'S230106713', 'active', 0, 0, NULL, '2026-09-18 21:37:23', '2026-09-21 01:52:30', '2026-09-21 18:33:55', '::1', NULL, '2026-09-18 21:37:23', '2026-09-21 02:33:55'),
(1420, 'depthead', 'depthead@bestlink.edu.ph', '$2y$10$YzKfWRJjdgH7RWtPzle5cest5VbF9VizUzo8O4wGGgbg.OFMvJxSu', 'Jonathan Kuminga', 'department_head', NULL, 'active', 0, 0, NULL, '2026-09-19 01:27:09', '2026-09-21 01:51:48', NULL, '::1', NULL, '2026-09-19 01:01:39', '2026-09-21 01:52:22'),
(1598, 's230106714', 'kenlangmalakas0308@gmail.com', '$2y$10$LAIyCyd4dEGPMcETU7IFxeE/fa3VYtsHLp2rLq4Gh4v8EbjK9B5XC', 'Jopel Caday', 'student', 'S230106714', 'active', 0, 1, NULL, '2026-09-20 22:31:50', '2026-09-20 23:09:19', NULL, '::1', NULL, '2026-09-20 21:15:17', '2026-09-21 00:07:29');

SET FOREIGN_KEY_CHECKS = 1;
