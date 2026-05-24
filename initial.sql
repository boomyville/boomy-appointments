CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(191) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(191) NOT NULL,
    setting_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
    ('site_name', 'Boomy Appointments'),
    ('org_email', 'hello@example.com'),
    ('date_format', 'd/m/Y H:i'),
    ('booking_firstname_display', '1'),
    ('booking_firstname_required', '1'),
    ('booking_firstname_letters_only', '0'),
    ('booking_firstname_min_length', '1'),
    ('booking_firstname_max_length', '100'),
    ('booking_lastname_display', '1'),
    ('booking_lastname_required', '1'),
    ('booking_lastname_letters_only', '0'),
    ('booking_lastname_min_length', '1'),
    ('booking_lastname_max_length', '100'),
    ('booking_middlename_display', '0'),
    ('booking_middlename_required', '0'),
    ('booking_middlename_letters_only', '0'),
    ('booking_middlename_min_length', '1'),
    ('booking_middlename_max_length', '100'),
    ('booking_email_display', '1'),
    ('booking_email_required', '1'),
    ('booking_email_allowed_domains', ''),
    ('booking_phone_display', '1'),
    ('booking_phone_required', '1'),
    ('booking_phone_numbers_symbols_only', '1'),
    ('booking_phone_min_length', '7'),
    ('booking_phone_max_length', '20')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);