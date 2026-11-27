-- SMS 2 integration layer (API clients, login codes, refresh tokens, SSO tickets, webhooks).
-- The app creates these tables automatically on first use. Import this file only if you prefer to create them up front.

CREATE TABLE IF NOT EXISTS `sms2_api_clients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` varchar(80) NOT NULL,
  `name` varchar(150) NOT NULL,
  `secret_hash` varchar(255) NOT NULL,
  `redirect_uris` text NOT NULL,
  `allowed_roles` varchar(255) NOT NULL DEFAULT 'student',
  `webhook_url` varchar(500) DEFAULT NULL,
  `webhook_secret` text DEFAULT NULL,
  `webhook_events` varchar(500) NOT NULL DEFAULT '*',
  `status` enum('active','disabled') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

CREATE TABLE IF NOT EXISTS `sms2_api_auth_codes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code_hash` char(64) NOT NULL,
  `client_id` varchar(80) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `redirect_uri` varchar(500) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_code_hash` (`code_hash`),
  KEY `idx_api_code_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

CREATE TABLE IF NOT EXISTS `sms2_api_refresh_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` char(64) NOT NULL,
  `client_id` varchar(80) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_refresh_hash` (`token_hash`),
  KEY `idx_api_refresh_user` (`user_id`,`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

CREATE TABLE IF NOT EXISTS `sms2_api_sso_tickets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_hash` char(64) NOT NULL,
  `client_id` varchar(80) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `next_path` varchar(500) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_ticket_hash` (`ticket_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

CREATE TABLE IF NOT EXISTS `sms2_webhook_entity_state` (
  `entity` varchar(60) NOT NULL,
  `entity_id` int(10) unsigned NOT NULL,
  `state_json` text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`entity`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

CREATE TABLE IF NOT EXISTS `sms2_webhook_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` char(36) NOT NULL,
  `event_type` varchar(80) NOT NULL,
  `payload` mediumtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webhook_event_id` (`event_id`),
  KEY `idx_webhook_event_type` (`event_type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

CREATE TABLE IF NOT EXISTS `sms2_webhook_deliveries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_pk` int(10) unsigned NOT NULL,
  `client_id` varchar(80) NOT NULL,
  `status` enum('pending','delivered','failed') NOT NULL DEFAULT 'pending',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `next_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_http_status` smallint(5) unsigned DEFAULT NULL,
  `last_error` varchar(500) DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webhook_delivery` (`event_pk`,`client_id`),
  KEY `idx_webhook_due` (`status`,`next_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

