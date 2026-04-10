-- ============================================================
--  MIGRATIONS — Run these against your existing database
--  These add new features on top of the existing schema
-- ============================================================

USE event_ticketing;

-- ============================================================
-- 1. Add email verification columns to users table
-- ============================================================
ALTER TABLE users
    ADD COLUMN email_verified    TINYINT(1)   DEFAULT 0          AFTER role,
    ADD COLUMN email_verified_at DATETIME     DEFAULT NULL       AFTER email_verified;

-- Mark your dev account as already verified
UPDATE users SET email_verified = 1, email_verified_at = NOW() WHERE role = 'dev';


-- ============================================================
-- 2. Email verifications table
--    Stores OTP codes for email verification and email changes
--    type = 'register' | 'email_change'
-- ============================================================
CREATE TABLE email_verifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED  NOT NULL,
    email      VARCHAR(200)  NOT NULL,          -- the email being verified
    otp        VARCHAR(6)    NOT NULL,           -- 6-digit code
    type       ENUM('register', 'email_change') NOT NULL DEFAULT 'register',
    is_used    TINYINT(1)    DEFAULT 0,
    expires_at DATETIME      NOT NULL,           -- OTP expires after 10 minutes
    created_at DATETIME      DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME      DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);


-- ============================================================
-- 3. Activity log table
--    Tracks important user actions for their profile page
--    action = 'login' | 'password_change' | 'email_change' |
--             'ticket_purchase' | 'profile_update' etc.
-- ============================================================
CREATE TABLE activity_logs (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED  NOT NULL,
    action     VARCHAR(100)  NOT NULL,
    description VARCHAR(255) DEFAULT NULL,       -- human readable summary
    ip_address VARCHAR(50)   DEFAULT NULL,
    created_at DATETIME      DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME      DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);