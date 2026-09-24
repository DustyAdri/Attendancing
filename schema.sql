SET FOREIGN_KEY_CHECKS = 0;

-- 1. TABLES  (physical round tables in the venue)
CREATE TABLE `tables` (
    `id`           INT             NOT NULL AUTO_INCREMENT,
    `table_number` VARCHAR(10)     NOT NULL,
    `zone`         VARCHAR(50),
    `location_x`   DECIMAL(8,2)    NOT NULL DEFAULT 0,
    `location_y`   DECIMAL(8,2)    NOT NULL DEFAULT 0,
    `capacity`     TINYINT         NOT NULL DEFAULT 8,
    `created_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_table_number` (`table_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SEATS  
CREATE TABLE `seats` (
    `id`          INT             NOT NULL AUTO_INCREMENT,
    `table_id`    INT             NOT NULL,
    `seat_number` TINYINT         NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_seats_table_number` (`table_id`, `seat_number`),
    CONSTRAINT `fk_seats_table`
        FOREIGN KEY (`table_id`) REFERENCES `tables`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GUESTS  (students)
CREATE TABLE `guests` (
    `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `first_name`   VARCHAR(100)     NOT NULL,
    `last_name`    VARCHAR(100)     NOT NULL,
    `id_number`    VARCHAR(20)      NOT NULL,
    `email`        VARCHAR(150)     DEFAULT NULL,
    `program`      VARCHAR(100)     DEFAULT NULL,
    `year`         TINYINT UNSIGNED DEFAULT NULL,
    `table_number` VARCHAR(10)      DEFAULT NULL,   -- table code (e.g. '12', 'B1', 'CISCO')
    `seat_number`  VARCHAR(10)      DEFAULT NULL,   -- seat code (e.g. '3', 'CISCO')
    `created_at`   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_id_number` (`id_number`),
    UNIQUE KEY `uk_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ATTENDANCE
CREATE TABLE `attendance` (
    `id`            INT             NOT NULL AUTO_INCREMENT,
    `guest_id`      INT UNSIGNED,
    `seat_id`       INT             NOT NULL,
    `guest_name`    VARCHAR(255),
    `status`        ENUM('present','absent') NOT NULL DEFAULT 'absent',
    `timestamp_in`  TIMESTAMP       NULL DEFAULT NULL,
    `timestamp_out` TIMESTAMP       NULL DEFAULT NULL,
    `updated_by`    VARCHAR(100),
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_attendance_seat` (`seat_id`),
    CONSTRAINT `fk_attendance_guest`
        FOREIGN KEY (`guest_id`) REFERENCES `guests`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_attendance_seat`
        FOREIGN KEY (`seat_id`) REFERENCES `seats`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INDEXES
CREATE INDEX `idx_seats_table`      ON `seats`(`table_id`);
CREATE INDEX `idx_attendance_seat`  ON `attendance`(`seat_id`);
CREATE INDEX `idx_attendance_guest` ON `attendance`(`guest_id`);

-- VIEWS

-- Full seating plan
CREATE OR REPLACE VIEW `v_seating_plan` AS
SELECT
    t.id                                                          AS table_id,
    t.table_number,
    t.zone,
    t.location_x,
    t.location_y,
    s.id                                                          AS seat_id,
    s.seat_number,
    a.id                                                          AS attendance_id,
    COALESCE(a.guest_name, CONCAT(g.first_name,' ',g.last_name)) AS guest_name,
    g.id                                                          AS guest_id,
    g.id_number,
    g.program,
    g.year,
    a.status,
    a.timestamp_in,
    a.timestamp_out
FROM `tables` t
JOIN `seats`       s ON s.table_id = t.id
LEFT JOIN `attendance` a ON a.seat_id = s.id
LEFT JOIN `guests`     g ON g.id      = a.guest_id;

-- Summary stats
CREATE OR REPLACE VIEW `v_stats` AS
SELECT
    COUNT(s.id)                                                  AS total_seats,
    COUNT(a.id)                                                  AS assigned_seats,
    COUNT(s.id) - COUNT(a.id)                                   AS vacant_seats,
    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END)      AS present_count,
    SUM(CASE WHEN a.status = 'absent'  THEN 1 ELSE 0 END)      AS absent_count
FROM `tables` t
LEFT JOIN `seats`      s ON s.table_id = t.id
LEFT JOIN `attendance` a ON a.seat_id  = s.id;

SET FOREIGN_KEY_CHECKS = 1;
