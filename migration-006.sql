-- Apply once after migration-005.sql.
ALTER TABLE members ADD COLUMN mfa_secret_cipher MEDIUMTEXT NULL, ADD COLUMN mfa_pending_cipher MEDIUMTEXT NULL, ADD COLUMN mfa_pending_expires DATETIME NULL, ADD COLUMN mfa_last_counter BIGINT NOT NULL DEFAULT -1;
CREATE TABLE mfa_recovery_codes (member_id INT NOT NULL, code_hash CHAR(64) NOT NULL, PRIMARY KEY(member_id,code_hash), FOREIGN KEY(member_id) REFERENCES members(id));
