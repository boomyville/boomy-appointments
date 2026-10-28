-- Install SQL for Boomy Appointments
-- Creates database schema and seed data

CREATE DATABASE IF NOT EXISTS boomy_appointments CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE boomy_appointments;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `unavailabilities`;
DROP TABLE IF EXISTS `breaks`;
DROP TABLE IF EXISTS `appointments`;
DROP TABLE IF EXISTS `provider_appointment_types`;
DROP TABLE IF EXISTS `appointment_types`;
DROP TABLE IF EXISTS `customers`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. SETTINGS
CREATE TABLE `settings` (
  `setting_key` VARCHAR(64) NOT NULL,
  `setting_value` TEXT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. USERS
CREATE TABLE `users` (
  `id` INT UNSIGNED AUTO_INCREMENT,
  `email` VARCHAR(191) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(128) NOT NULL,
  `first_name` VARCHAR(64) NULL,
  `last_name` VARCHAR(64) NULL,
  `phone` VARCHAR(32) NULL,
  `role` ENUM('admin','provider') NOT NULL DEFAULT 'provider',
  `working_plan` JSON NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `bookable` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2B. VERIFICATION TOKENS
CREATE TABLE `verification` (
  `id` INT UNSIGNED AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(64) NOT NULL,
  `task` VARCHAR(32) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_verification_code` (`code`),
  KEY `idx_verification_user_task` (`user_id`, `task`),
  CONSTRAINT `fk_verification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2C. LOGIN ATTEMPTS
CREATE TABLE `login_attempts` (
  `id` INT UNSIGNED AUTO_INCREMENT,
  `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` VARCHAR(45) NULL,
  `username` VARCHAR(191) NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `user_agent` VARCHAR(1024) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. APPOINTMENT TYPES
CREATE TABLE `appointment_types` (
  `id` INT UNSIGNED AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `duration_minutes` INT UNSIGNED NOT NULL,
  `color_hex` CHAR(7) NOT NULL DEFAULT '#3b4351',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_bookable` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. PROVIDER <-> APPT TYPES
CREATE TABLE `provider_appointment_types` (
  `provider_id` INT UNSIGNED NOT NULL,
  `appointment_type_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`provider_id`,`appointment_type_id`),
  CONSTRAINT `fk_pivot_provider` FOREIGN KEY (`provider_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pivot_type` FOREIGN KEY (`appointment_type_id`) REFERENCES `appointment_types` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. CUSTOMERS
CREATE TABLE `customers` (
  `id`              INT UNSIGNED AUTO_INCREMENT,
  `email`           VARCHAR(191)  NOT NULL,
  `email_invalid`        TINYINT(1)   NOT NULL DEFAULT 0,
  `email_invalid_reason` VARCHAR(255) NULL DEFAULT NULL,
  `email_invalid_at`     TIMESTAMP    NULL DEFAULT NULL,
  `mobile`          VARCHAR(32)   NOT NULL,
  `full_name`       VARCHAR(128)  NOT NULL,
  `date_of_birth`   DATE          NULL,
  `date_added`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `gender`          ENUM('male','female','other','unknown') NOT NULL DEFAULT 'unknown',

  -- tracking
  `last_ip`         VARCHAR(45)   NULL,
  `last_user_agent` VARCHAR(512)  NULL,
  `last_timezone`   VARCHAR(64)   NULL,
  `last_screen`     VARCHAR(32)   NULL,
  `last_locale`     VARCHAR(32)   NULL,
  `last_seen_at`    TIMESTAMP     NULL,

  PRIMARY KEY (`id`),
  KEY `idx_customer_lookup` (`email`, `mobile`),
  KEY `idx_customer_ip`     (`last_ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. BREAKS
CREATE TABLE `breaks` (
  `id` INT UNSIGNED AUTO_INCREMENT,
  `day_of_week` TINYINT UNSIGNED NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_breaks_day_time` (`day_of_week`,`start_time`,`end_time`),
  KEY `idx_breaks_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `appointments` (
  `id` INT UNSIGNED AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `provider_id` INT UNSIGNED NOT NULL,
  `appointment_type_id` INT UNSIGNED NOT NULL,
  `start_time` DATETIME NOT NULL,
  `end_time` DATETIME NOT NULL,
  `status` ENUM('booked','unconfirmed','confirmed','present','completed','no_show','cancelled') NOT NULL DEFAULT 'booked',
  `notes` TEXT NULL,
  `hash_key` CHAR(64) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_appointment_hash` (`hash_key`),
  KEY `idx_appt_chronology` (`provider_id`,`start_time`,`end_time`),
  -- FIX: Changed ON DELETE RESTRICT to ON DELETE CASCADE
  CONSTRAINT `fk_appt_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_appt_provider` FOREIGN KEY (`provider_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_appt_type` FOREIGN KEY (`appointment_type_id`) REFERENCES `appointment_types` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. UNAVAILABILITIES
CREATE TABLE `unavailabilities` (
  `id` INT UNSIGNED AUTO_INCREMENT,
  `provider_id` INT UNSIGNED NULL,
  `type` ENUM('single', 'recurring') NOT NULL DEFAULT 'single',
  
  -- Used ONLY when type = 'single' (One-off events)
  `start_datetime` DATETIME NULL DEFAULT NULL,
  `end_datetime` DATETIME NULL DEFAULT NULL,
  
  -- Used ONLY when type = 'recurring' (Rolling patterns)
  `day_of_week` TINYINT UNSIGNED NULL DEFAULT NULL, -- 1 = Monday, 7 = Sunday (ISO 8601)
  `start_time` TIME NULL DEFAULT NULL,
  `end_time` TIME NULL DEFAULT NULL,
  `rolling_start_date` DATE NULL DEFAULT NULL,
  `rolling_end_date` DATE NULL DEFAULT NULL,      -- NULL means infinite/indefinite rolling
  
  `reason` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  KEY `idx_unavail_provider_type` (`provider_id`, `type`),
  KEY `idx_unavail_single_dates` (`start_datetime`, `end_datetime`),
  KEY `idx_unavail_recurring_rules` (`day_of_week`, `start_time`, `end_time`),
  CONSTRAINT `fk_unavail_provider` FOREIGN KEY (`provider_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed settings
INSERT INTO `settings` (`setting_key`,`setting_value`) VALUES
('site_name','Boomy Appointments'),
('root_url',''),
('organization_email','appointments@example.com'),
('organization_number',''),
('date_format','d/m/Y H:i'),
('auto_no_show','1'),
('prevent_same_user_booking','0'),
('booking_deadline_hours','24'),
('email_whitelist_enabled','0'),
('email_domain_whitelist',''),
('email_domain_blacklist',''),
('password_min_length','8'),
('password_max_length','128'),
('password_require_lowercase','1'),
('password_require_uppercase','1'),
('password_require_number','1'),
('password_require_symbol','1'),
('booking_max_advance_days','60'),
('booking_firstname_display','1'),
('booking_firstname_required','1'),
('booking_firstname_letters_only','0'),
('booking_firstname_min','2'),
('booking_firstname_max','50'),
('booking_lastname_display','1'),
('booking_lastname_required','1'),
('booking_lastname_letters_only','0'),
('booking_lastname_min','2'),
('booking_lastname_max','50'),
('booking_middlename_display','0'),
('booking_middlename_required','0'),
('booking_middlename_letters_only','0'),
('booking_middlename_min','2'),
('booking_middlename_max','50'),
('booking_email_display','1'),
('booking_email_required','1'),
('booking_email_allowed_domains',''),
('booking_phone_display','1'),
('booking_phone_required','0'),
('booking_phone_numbers_only','1'),
('booking_phone_min','10'),
('booking_phone_max','20'),
('booking_dob_display','1'),
('booking_dob_required','0'),
('booking_gender_display','1'),
('booking_gender_required','0'),
('booking_show_monday','1'),
('booking_show_tuesday','1'),
('booking_show_wednesday','1'),
('booking_show_thursday','1'),
('booking_show_friday','1'),
('booking_show_saturday','0'),
('booking_show_sunday','0'),
('booking_start_time','09:00'),
('booking_end_time','21:00'),
('booking_week_start_day','0'),
('booking_calendar_height','1800'),
('booking_show_now_indicator','1'),
('booking_snap_minutes','15'),
('disable_online_booking', '0'),
('smtp_host','smtp.gmail.com'),
('smtp_port','587'),
('smtp_encryption','tls'),
('smtp_username',''),
('smtp_password',''),
('mail_from_address',''),
('session_lifetime_hours','24'),
('mail_from_name','Boomy Appointments'),
('reminder_enabled','0'),
('reminder_days_before','1'),
('reminder_time','09:00'),
('cron_key',''),
('booking_step1_message',''),
('booking_step4_message',''),
('confirmation_email_message',''),
('reminder_email_message','');


-- Seed appointment types
INSERT INTO `appointment_types` (`name`,`duration_minutes`,`color_hex`) VALUES
('Comprehensive Eye Examination',30,'#1a5fb4'),
('Contact Lens Fitting & Review',30,'#e66100'),
('Emergency Red Eye Evaluation',15,'#c01c28');

CREATE TABLE `daily_notes` (
  `id` INT UNSIGNED AUTO_INCREMENT,
  `note_date` DATE NOT NULL,
  `provider_id` INT UNSIGNED NULL,
  `title` VARCHAR(40) NOT NULL DEFAULT '',
  `message` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_daily_note_date_provider` (`note_date`, `provider_id`),
  CONSTRAINT `fk_daily_note_provider` FOREIGN KEY (`provider_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sessions` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id`       VARCHAR(128) NOT NULL,
  `user_id`          INT UNSIGNED NOT NULL,
  `ip_address`       VARCHAR(45)  NOT NULL DEFAULT '',
  `user_agent`       VARCHAR(512) NOT NULL DEFAULT '',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_session_id` (`session_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_last_activity` (`last_activity_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;