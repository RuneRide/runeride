ALTER TABLE georide_accounts
    ADD COLUMN relier_requis TINYINT NOT NULL DEFAULT 0 AFTER token_renewed_at,
    ADD COLUMN last_sync_new INT UNSIGNED NULL AFTER last_sync_at,
    ADD COLUMN last_sync_total INT UNSIGNED NULL AFTER last_sync_new,
    ADD COLUMN last_sync_error VARCHAR(16) NULL AFTER last_sync_total;
