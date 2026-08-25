SET NAMES utf8mb4;
SET time_zone = '+03:00';

CREATE TABLE IF NOT EXISTS airlines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    icao_code CHAR(3) NOT NULL,
    iata_code CHAR(2) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_airlines_icao (icao_code),
    UNIQUE KEY uq_airlines_iata (iata_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL,
    email VARCHAR(190) NULL,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    status ENUM('active', 'inactive', 'deleted') NOT NULL DEFAULT 'active',
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    deleted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_status (status),
    CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) NOT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(100) NOT NULL,
    name VARCHAR(150) NOT NULL,
    permission_group VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_role_scopes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    scope_type ENUM('global', 'airline', 'assigned') NOT NULL,
    airline_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_role_scopes_user (user_id),
    KEY idx_user_role_scopes_airline (airline_id),
    CONSTRAINT fk_user_role_scopes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_role_scopes_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_role_scopes_airline FOREIGN KEY (airline_id) REFERENCES airlines(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_role_scopes_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_permission_overrides (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    effect ENUM('allow', 'deny') NOT NULL,
    scope_type ENUM('global', 'airline', 'assigned') NOT NULL,
    airline_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_permission_overrides_user (user_id),
    CONSTRAINT fk_user_permission_overrides_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_permission_overrides_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_permission_overrides_airline FOREIGN KEY (airline_id) REFERENCES airlines(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_permission_overrides_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flight_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(100) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    UNIQUE KEY uq_flight_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flights (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    airline_id BIGINT UNSIGNED NOT NULL,
    flight_type_id BIGINT UNSIGNED NOT NULL,
    arrival_flight_number VARCHAR(20) NULL,
    departure_flight_number VARCHAR(20) NULL,
    arrival_origin VARCHAR(10) NULL,
    arrival_destination VARCHAR(10) NULL,
    departure_origin VARCHAR(10) NULL,
    departure_destination VARCHAR(10) NULL,
    scheduled_arrival_at DATETIME NULL,
    estimated_arrival_at DATETIME NULL,
    actual_arrival_at DATETIME NULL,
    scheduled_departure_at DATETIME NULL,
    estimated_departure_at DATETIME NULL,
    actual_departure_at DATETIME NULL,
    tail_number VARCHAR(20) NULL,
    aircraft_type VARCHAR(20) NULL,
    stand VARCHAR(20) NULL,
    status ENUM('scheduled', 'active', 'completed', 'cancelled') NOT NULL DEFAULT 'scheduled',
    note TEXT NULL,
    source ENUM('manual', 'excel') NOT NULL DEFAULT 'manual',
    source_key VARCHAR(190) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    deleted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_flights_source_key (source_key),
    KEY idx_flights_airline_status (airline_id, status),
    KEY idx_flights_schedule (scheduled_departure_at, scheduled_arrival_at),
    CONSTRAINT fk_flights_airline FOREIGN KEY (airline_id) REFERENCES airlines(id),
    CONSTRAINT fk_flights_type FOREIGN KEY (flight_type_id) REFERENCES flight_types(id),
    CONSTRAINT fk_flights_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_flights_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flight_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flight_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    assignment_role ENUM('primary', 'support', 'supervisor') NOT NULL DEFAULT 'primary',
    status ENUM('active', 'completed', 'revoked') NOT NULL DEFAULT 'active',
    assigned_by BIGINT UNSIGNED NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    unassigned_at DATETIME NULL,
    UNIQUE KEY uq_flight_assignments_flight (flight_id),
    KEY idx_flight_assignments_user_status (user_id, status),
    CONSTRAINT fk_flight_assignments_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE,
    CONSTRAINT fk_flight_assignments_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_flight_assignments_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS process_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(150) NOT NULL,
    input_type ENUM('state', 'datetime', 'text') NOT NULL DEFAULT 'state',
    icon VARCHAR(100) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    UNIQUE KEY uq_process_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flight_type_process_map (
    flight_type_id BIGINT UNSIGNED NOT NULL,
    process_type_id BIGINT UNSIGNED NOT NULL,
    order_no INT UNSIGNED NOT NULL DEFAULT 0,
    required TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (flight_type_id, process_type_id),
    CONSTRAINT fk_flight_type_process_map_type FOREIGN KEY (flight_type_id) REFERENCES flight_types(id) ON DELETE CASCADE,
    CONSTRAINT fk_flight_type_process_map_process FOREIGN KEY (process_type_id) REFERENCES process_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flight_processes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flight_id BIGINT UNSIGNED NOT NULL,
    process_type_id BIGINT UNSIGNED NOT NULL,
    state ENUM('not_started', 'started', 'finished', 'not_used') NOT NULL DEFAULT 'not_started',
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    value_datetime DATETIME NULL,
    value_text TEXT NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_flight_processes_flight_process (flight_id, process_type_id),
    CONSTRAINT fk_flight_processes_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE,
    CONSTRAINT fk_flight_processes_process FOREIGN KEY (process_type_id) REFERENCES process_types(id),
    CONSTRAINT fk_flight_processes_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flight_import_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_name VARCHAR(255) NOT NULL,
    file_hash CHAR(64) NOT NULL,
    airline_id BIGINT UNSIGNED NULL,
    status ENUM('preview', 'processing', 'completed', 'completed_with_errors', 'failed') NOT NULL DEFAULT 'preview',
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    success_rows INT UNSIGNED NOT NULL DEFAULT 0,
    failed_rows INT UNSIGNED NOT NULL DEFAULT 0,
    imported_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    KEY idx_flight_import_batches_hash (file_hash),
    CONSTRAINT fk_flight_import_batches_airline FOREIGN KEY (airline_id) REFERENCES airlines(id) ON DELETE SET NULL,
    CONSTRAINT fk_flight_import_batches_user FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS flight_import_rows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id BIGINT UNSIGNED NOT NULL,
    source_row_number INT UNSIGNED NOT NULL,
    status ENUM('valid', 'imported', 'duplicate', 'invalid') NOT NULL,
    source_key VARCHAR(190) NULL,
    payload LONGTEXT NULL,
    errors LONGTEXT NULL,
    flight_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_flight_import_rows_batch_row (batch_id, source_row_number),
    CONSTRAINT fk_flight_import_rows_batch FOREIGN KEY (batch_id) REFERENCES flight_import_batches(id) ON DELETE CASCADE,
    CONSTRAINT fk_flight_import_rows_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    old_values LONGTEXT NULL,
    new_values LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_logs_entity (entity_type, entity_id),
    KEY idx_audit_logs_actor (actor_user_id, created_at),
    CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Başlangıç rolleri ve yetki kataloğu
INSERT INTO roles (code, name, description, is_system) VALUES
    ('admin', 'Admin', 'Sistemin tamamına erişen tek yönetici rolü', 1),
    ('supervisor', 'Supervisor', 'Belirli havayollarındaki operasyonları izleyen rol', 1),
    ('operation', 'Operation', 'Kendisine atanmış uçuşlarda işlem yapan personel rolü', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO permissions (code, name, permission_group, description) VALUES
    ('dashboard.view', 'Dashboard Görüntüleme', 'Genel', 'Yetkili operasyon özetini görüntüler'),
    ('users.view', 'Kullanıcıları Görüntüleme', 'Kullanıcılar', 'Kullanıcı listesini ve detaylarını görüntüler'),
    ('users.create', 'Kullanıcı Oluşturma', 'Kullanıcılar', 'Yeni kullanıcı oluşturur'),
    ('users.update', 'Kullanıcı Düzenleme', 'Kullanıcılar', 'Kullanıcı bilgilerini ve durumunu değiştirir'),
    ('users.delete', 'Kullanıcı Silme', 'Kullanıcılar', 'Uygun kullanıcıyı siler veya anonimleştirir'),
    ('users.assign_access', 'Rol, ICAO ve Yetki Atama', 'Kullanıcılar', 'Rol, tekli/çoklu ICAO kapsamı ve özel yetki atar'),
    ('roles.view', 'Rol Matrisini Görüntüleme', 'Roller', 'Rol ve yetki matrisini görüntüler'),
    ('roles.manage', 'Rol Yetkilerini Yönetme', 'Roller', 'Supervisor ve operation rol yetkilerini değiştirir'),
    ('airlines.view', 'Havayollarını Görüntüleme', 'Havayolları', 'ICAO kapsam kayıtlarını görüntüler'),
    ('airlines.manage', 'Havayolu Yönetimi', 'Havayolları', 'Havayolu kayıtlarını yönetir'),
    ('flights.view', 'Uçuş Görüntüleme', 'Uçuşlar', 'Global, ICAO veya atama kapsamındaki uçuşları görüntüler'),
    ('flights.update', 'Uçuş Düzenleme', 'Uçuşlar', 'Uçuş bilgilerini değiştirir'),
    ('flights.cancel', 'Uçuş İptali', 'Uçuşlar', 'Uçuş durumunu iptal eder'),
    ('flights.delete', 'Uçuşu Kalıcı Silme', 'Uçuşlar', 'Uçuşu ve bağlı operasyon kayıtlarını kalıcı olarak siler'),
    ('flights.assign', 'Uçuş Sorumlusu Atama', 'Uçuşlar', 'Uçuşa tek bir aktif kullanıcı atar'),
    ('flights.complete', 'Uçuş Tamamlama', 'Uçuşlar', 'Operasyon sürecini tamamlar'),
    ('imports.view', 'Uçuş Ekleme Ekranı', 'Uçuş Ekle', 'Geçici Excel önizlemesini görüntüler'),
    ('imports.stage', 'Excel Yükleme ve Düzeltme', 'Uçuş Ekle', 'Exceli önizleme alanına yükler ve düzeltir'),
    ('imports.commit', 'Uçuş Kaydetme', 'Uçuş Ekle', 'Excel önizlemesini onaylar veya Uçuş Ekle sayfasından manuel uçuş kaydeder'),
    ('processes.view', 'Süreç Görüntüleme', 'Operasyon Süreçleri', 'Uçuş operasyon süreçlerini görüntüler'),
    ('processes.update', 'Süreç Güncelleme', 'Operasyon Süreçleri', 'Süreç başlangıç, bitiş ve notlarını günceller'),
    ('reports.view', 'Rapor Görüntüleme', 'Raporlar', 'Yetki kapsamındaki operasyon raporlarını görüntüler'),
    ('audit.view', 'Denetim Kaydı Görüntüleme', 'Sistem', 'Sistem değişiklik geçmişini görüntüler')
ON DUPLICATE KEY UPDATE name = VALUES(name), permission_group = VALUES(permission_group), description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code = 'admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
    ON p.code IN ('dashboard.view', 'flights.view', 'processes.view', 'reports.view')
WHERE r.code = 'supervisor';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
    ON p.code IN ('dashboard.view', 'flights.view', 'processes.view', 'processes.update', 'flights.complete')
WHERE r.code = 'operation';

INSERT INTO airlines (name, icao_code, iata_code, status)
VALUES ('SunExpress', 'SXS', 'XQ', 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), iata_code = VALUES(iata_code), status = VALUES(status);

INSERT INTO flight_types (code, name, status) VALUES
    ('arrival', 'Arrival', 'active'),
    ('departure', 'Departure', 'active'),
    ('turnaround', 'Turnaround', 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status);

-- Başlangıç operasyon süreçleri
INSERT INTO process_types (code, name, input_type, status) VALUES
    ('inblock', 'In Block', 'datetime', 'active'),
    ('doors_open', 'Doors Open', 'datetime', 'active'),
    ('deboarding', 'Deboarding', 'state', 'active'),
    ('cleaning', 'Cleaning', 'state', 'active'),
    ('catering', 'Catering', 'state', 'active'),
    ('fueling', 'Fueling', 'state', 'active'),
    ('boarding', 'Boarding', 'state', 'active'),
    ('doors_closed', 'Doors Closed', 'datetime', 'active'),
    ('offblock', 'Off Block', 'datetime', 'active'),
    ('operation_note', 'Operation Note', 'text', 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), input_type = VALUES(input_type), status = VALUES(status);

INSERT IGNORE INTO flight_type_process_map (flight_type_id, process_type_id, order_no, required)
SELECT ft.id, pt.id, FIELD(pt.code, 'inblock', 'doors_open', 'deboarding', 'operation_note'), IF(pt.code IN ('inblock', 'doors_open'), 1, 0)
FROM flight_types ft JOIN process_types pt ON pt.code IN ('inblock', 'doors_open', 'deboarding', 'operation_note')
WHERE ft.code = 'arrival';

INSERT IGNORE INTO flight_type_process_map (flight_type_id, process_type_id, order_no, required)
SELECT ft.id, pt.id, FIELD(pt.code, 'fueling', 'catering', 'boarding', 'doors_closed', 'offblock', 'operation_note'), IF(pt.code IN ('boarding', 'doors_closed', 'offblock'), 1, 0)
FROM flight_types ft JOIN process_types pt ON pt.code IN ('fueling', 'catering', 'boarding', 'doors_closed', 'offblock', 'operation_note')
WHERE ft.code = 'departure';

INSERT IGNORE INTO flight_type_process_map (flight_type_id, process_type_id, order_no, required)
SELECT ft.id, pt.id, FIELD(pt.code, 'inblock', 'doors_open', 'deboarding', 'cleaning', 'catering', 'fueling', 'boarding', 'doors_closed', 'offblock', 'operation_note'), IF(pt.code IN ('inblock', 'doors_open', 'boarding', 'doors_closed', 'offblock'), 1, 0)
FROM flight_types ft JOIN process_types pt ON pt.code IN ('inblock', 'doors_open', 'deboarding', 'cleaning', 'catering', 'fueling', 'boarding', 'doors_closed', 'offblock', 'operation_note')
WHERE ft.code = 'turnaround';
