ALTER TABLE georide_accounts
    MODIFY password_enc TEXT NULL,
    ADD COLUMN token_enc TEXT NULL AFTER password_enc,
    ADD COLUMN token_renewed_at DATETIME NULL AFTER token_enc;
