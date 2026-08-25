SET NAMES utf8mb4;
SET time_zone = '+03:00';

CREATE TABLE IF NOT EXISTS flight_timeline_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    default_arrival_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 40,
    default_departure_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_flight_timeline_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flight_timeline_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    airline_id BIGINT UNSIGNED NOT NULL,
    aircraft_type VARCHAR(20) NOT NULL,
    arrival_minutes SMALLINT UNSIGNED NOT NULL,
    departure_minutes SMALLINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_flight_timeline_rules_airline_aircraft (airline_id, aircraft_type),
    CONSTRAINT fk_flight_timeline_rules_airline FOREIGN KEY (airline_id) REFERENCES airlines(id) ON DELETE CASCADE,
    CONSTRAINT fk_flight_timeline_rules_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_flight_timeline_rules_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO flight_timeline_settings (id, default_arrival_minutes, default_departure_minutes)
VALUES (1, 40, 60);

INSERT INTO permissions (code, name, permission_group, description) VALUES
    ('timeline.view', 'Uçuş Zaman Çizelgesini Görüntüleme', 'Uçuş Zaman Çizelgesi', 'Global veya ICAO kapsamındaki günlük operasyon çizelgesini görüntüler'),
    ('timeline.manage', 'Zaman Çizelgesi Sürelerini Yönetme', 'Uçuş Zaman Çizelgesi', 'Global ve firma/uçak tipi görev sürelerini yönetir')
ON DUPLICATE KEY UPDATE name = VALUES(name), permission_group = VALUES(permission_group), description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('timeline.view', 'timeline.manage')
WHERE r.code = 'admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'timeline.view'
WHERE r.code = 'supervisor';

DELETE rp FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE p.code = 'timeline.manage' AND r.code != 'admin';

UPDATE process_types SET icon = CASE code
    WHEN 'inblock' THEN 'inblock'
    WHEN 'doors_open' THEN 'door-open'
    WHEN 'deboarding' THEN 'deboarding'
    WHEN 'cleaning' THEN 'cleaning'
    WHEN 'catering' THEN 'catering'
    WHEN 'fueling' THEN 'fueling'
    WHEN 'boarding' THEN 'boarding'
    WHEN 'doors_closed' THEN 'door-closed'
    WHEN 'offblock' THEN 'offblock'
    WHEN 'operation_note' THEN 'note'
    ELSE icon
END
WHERE code IN ('inblock', 'doors_open', 'deboarding', 'cleaning', 'catering', 'fueling', 'boarding', 'doors_closed', 'offblock', 'operation_note');
