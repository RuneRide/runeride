CREATE TABLE trips (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    georide_trip_id VARCHAR(64) NOT NULL,
    tracker_id VARCHAR(64) NOT NULL,
    title VARCHAR(255) NOT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NOT NULL,
    distance_km DOUBLE NOT NULL DEFAULT 0,
    duration_min INT NOT NULL DEFAULT 0,
    points_count INT NOT NULL DEFAULT 0,
    path_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_trips_user_georide (user_id, georide_trip_id),
    INDEX idx_trips_user_start (user_id, started_at),
    CONSTRAINT fk_trips_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
