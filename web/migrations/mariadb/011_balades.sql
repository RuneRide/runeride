CREATE TABLE balades (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    params_json LONGTEXT NOT NULL,
    trace_json LONGTEXT NOT NULL,
    distance_m INT UNSIGNED NOT NULL DEFAULT 0,
    duration_s INT UNSIGNED NOT NULL DEFAULT 0,
    scores_json TEXT NULL,
    graph_version VARCHAR(64) NULL,
    share_key VARCHAR(32) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_balades_share (share_key),
    INDEX idx_balades_user_created (user_id, created_at),
    CONSTRAINT fk_balades_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
