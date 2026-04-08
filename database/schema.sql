-- ============================================================
--  EVENT TICKETING SYSTEM — DATABASE SCHEMA
--  Run this file once to set up your entire database
-- ============================================================

CREATE DATABASE IF NOT EXISTS event_ticketing;
USE event_ticketing;


-- ============================================================
-- 1. CATEGORIES
--    Must be created before events (events references it)
-- ============================================================
CREATE TABLE categories (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name      VARCHAR(255) NOT NULL,
    slug      VARCHAR(255) NOT NULL UNIQUE,
    icon      VARCHAR(20)  DEFAULT 'ticket',
    created_at DATETIME    DEFAULT CURRENT_TIMESTAMP
);

-- Seed some default categories
INSERT INTO categories (name, slug, icon) VALUES
    ('Music',       'music',       'music'),
    ('Technology',  'technology',  'monitor'),
    ('Sports',      'sports',      'trophy'),
    ('Arts',        'arts',        'palette'),
    ('Business',    'business',    'briefcase'),
    ('Food',        'food',        'utensils'),
    ('Education',   'education',   'book-open'),
    ('Health',      'health',      'heart-pulse');


-- ============================================================
-- 2. USERS
--    role = attendee | organizer | admin | dev
--    dev accounts are invisible to admins (filter in queries)
-- ============================================================
CREATE TABLE users (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(255)  NOT NULL,
    email          VARCHAR(200)  NOT NULL UNIQUE,
    password_hash  VARCHAR(255)  NOT NULL,
    role           ENUM('attendee', 'organizer', 'admin', 'dev')
                   NOT NULL DEFAULT 'attendee',
    avatar         VARCHAR(255)  DEFAULT NULL,
    is_active      TINYINT(1)    DEFAULT 1,
    created_at     DATETIME      DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME      DEFAULT CURRENT_TIMESTAMP
                   ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
--  YOUR DEV ACCOUNT — change email & password before running
--  Password below is a bcrypt hash of "devpassword123"
--  Generate your own at: https://bcrypt-generator.com
-- ============================================================
INSERT INTO users (name, email, password_hash, role) VALUES (
    'Sam Dev',
    'sam@dev.local',
    '$2a$13$PifloQ/KgrZjNJkbAw.J1e6/f3kgC3w7Faieinzd5RWGAAvxM4KI2', -- Samdev100
    'dev'
);


-- ============================================================
-- 3. EVENTS
--    organizer_id links to users (only organizer/dev can create)
--    category_id  links to categories
-- ============================================================
CREATE TABLE events (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organizer_id    INT UNSIGNED  NOT NULL,
    category_id     INT UNSIGNED  DEFAULT NULL,
    title           VARCHAR(255)  NOT NULL,
    slug            VARCHAR(255)  NOT NULL UNIQUE,
    description     TEXT          DEFAULT NULL,
    location        VARCHAR(255)  DEFAULT NULL,
    banner_image    VARCHAR(255)  DEFAULT NULL,
    start_date      DATETIME      NOT NULL,
    end_date        DATETIME      NOT NULL,
    total_tickets   INT UNSIGNED  NOT NULL DEFAULT 0,
    tickets_sold    INT UNSIGNED  NOT NULL DEFAULT 0,
    status          ENUM('draft', 'published', 'cancelled', 'completed')
                    NOT NULL DEFAULT 'draft',
    created_at      DATETIME      DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (organizer_id) REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (category_id)  REFERENCES categories(id) ON DELETE SET NULL
);


-- ============================================================
-- 4. TICKET TYPES
--    Each event can have multiple ticket tiers
--    e.g. Regular (₦2,000), VIP (₦10,000), Early Bird (₦1,500)
-- ============================================================
CREATE TABLE ticket_types (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id       INT UNSIGNED     NOT NULL,
    name           VARCHAR(100)     NOT NULL,       -- e.g. "VIP", "Regular"
    description    VARCHAR(255)     DEFAULT NULL,
    price          DECIMAL(10, 2)   NOT NULL DEFAULT 0.00,
    quantity       INT UNSIGNED     NOT NULL,       -- total available
    quantity_sold  INT UNSIGNED     NOT NULL DEFAULT 0,
    sales_end_at   DATETIME         DEFAULT NULL,   -- optional cutoff
    created_at     DATETIME         DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME         DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);


-- ============================================================
-- 5. BOOKINGS
--    Created when a user initiates payment
--    payment_status updates after Paystack verification
-- ============================================================
CREATE TABLE bookings (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED     NOT NULL,
    event_id            INT UNSIGNED     NOT NULL,
    ticket_type_id      INT UNSIGNED     NOT NULL,
    quantity            INT UNSIGNED     NOT NULL DEFAULT 1,
    unit_price          DECIMAL(10, 2)   NOT NULL,
    total_amount        DECIMAL(10, 2)   NOT NULL,
    paystack_reference  VARCHAR(255)     DEFAULT NULL UNIQUE,
    payment_status      ENUM('pending', 'paid', 'failed', 'refunded')
                        NOT NULL DEFAULT 'pending',
    paid_at             DATETIME         DEFAULT NULL,
    created_at          DATETIME         DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME         DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)        REFERENCES users(id)        ON DELETE CASCADE,
    FOREIGN KEY (event_id)       REFERENCES events(id)       ON DELETE CASCADE,
    FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id) ON DELETE CASCADE
);


-- ============================================================
-- 6. TICKETS
--    One row per ticket (if quantity = 3, creates 3 rows)
--    qr_token is the unique string encoded into the QR image
--    is_used flips to 1 when organizer scans at the gate
-- ============================================================
CREATE TABLE tickets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id  INT UNSIGNED  NOT NULL,
    user_id     INT UNSIGNED  NOT NULL,
    event_id    INT UNSIGNED  NOT NULL,
    qr_token    VARCHAR(255)  NOT NULL UNIQUE,  -- UUID or random hash
    is_used     TINYINT(1)    DEFAULT 0,
    used_at     DATETIME      DEFAULT NULL,
    created_at  DATETIME      DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME      DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (event_id)   REFERENCES events(id)   ON DELETE CASCADE
);


-- ============================================================
-- 7. DEV LOGS  (only visible to dev role)
--    Logs every API request automatically
--    Helps you debug the system from your secret dashboard
-- ============================================================
CREATE TABLE dev_logs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    method      VARCHAR(10)   DEFAULT NULL,   -- GET, POST, etc.
    endpoint    VARCHAR(255)  DEFAULT NULL,   -- /api/events
    user_id     INT UNSIGNED  DEFAULT NULL,   -- who made the request
    ip_address  VARCHAR(50)   DEFAULT NULL,
    payload     TEXT          DEFAULT NULL,   -- request body (JSON)
    response_code INT         DEFAULT NULL,   -- 200, 404, 500 etc.
    created_at  DATETIME      DEFAULT CURRENT_TIMESTAMP
);


-- ============================================================
--  USEFUL QUERIES TO REMEMBER
-- ============================================================

-- Get all users EXCEPT dev (use this in every admin query)
-- SELECT * FROM users WHERE role != 'dev';

-- Check ticket availability before booking
-- SELECT (quantity - quantity_sold) AS available FROM ticket_types WHERE id = ?;

-- Get all bookings for an event (organizer view)
-- SELECT u.name, u.email, b.quantity, b.total_amount, b.payment_status
-- FROM bookings b
-- JOIN users u ON u.id = b.user_id
-- WHERE b.event_id = ? AND b.payment_status = 'paid';

-- Verify a ticket at the gate (check-in)
-- SELECT t.*, e.title, u.name FROM tickets t
-- JOIN events e ON e.id = t.event_id
-- JOIN users u ON u.id = t.user_id
-- WHERE t.qr_token = ? AND t.is_used = 0;
