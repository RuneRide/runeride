CREATE TABLE georide_accounts (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    georide_email VARCHAR(190) NOT NULL,
    password_enc TEXT NOT NULL,
    tracking_start_at DATETIME NULL,
    last_sync_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_georide_accounts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
