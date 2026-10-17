CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    throttle_key CHAR(64) NOT NULL,
    attempted_at BIGINT UNSIGNED NOT NULL,
    INDEX idx_key_time (throttle_key, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
