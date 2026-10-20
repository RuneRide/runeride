CREATE TABLE balade_preferences (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    vitesses_json TEXT NULL,
    pause_min_par_h SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    seuils_meteo_json TEXT NULL,
    calibration_json TEXT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_balade_prefs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
