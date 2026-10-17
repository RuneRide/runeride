CREATE TABLE georide_trackers (
    user_id BIGINT UNSIGNED NOT NULL,
    tracker_id VARCHAR(64) NOT NULL,
    name VARCHAR(190) NOT NULL,
    selected TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, tracker_id),
    CONSTRAINT fk_georide_trackers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
