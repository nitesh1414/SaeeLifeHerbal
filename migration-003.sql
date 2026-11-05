-- Apply once after migration-002.sql, with the website taken offline.
ALTER TABLE members ADD COLUMN sensitive_cipher MEDIUMTEXT NULL, ADD COLUMN auth_version INT NOT NULL DEFAULT 0;
ALTER TABLE payouts ADD COLUMN bank_cipher MEDIUMTEXT NULL;
CREATE TABLE audit_events (id BIGINT AUTO_INCREMENT PRIMARY KEY, actor_id INT NULL, action VARCHAR(80) NOT NULL, subject VARCHAR(160) NOT NULL DEFAULT '', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(created_at), FOREIGN KEY(actor_id) REFERENCES members(id));
CREATE TABLE refund_batches (id INT AUTO_INCREMENT PRIMARY KEY, refund_reference VARCHAR(100) NOT NULL UNIQUE, reason VARCHAR(500) NOT NULL, actor_id INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(actor_id) REFERENCES members(id));
CREATE TABLE purchase_refunds (purchase_id INT PRIMARY KEY, batch_id INT NOT NULL, FOREIGN KEY(purchase_id) REFERENCES purchases(id), FOREIGN KEY(batch_id) REFERENCES refund_batches(id));
ALTER TABLE orders MODIFY status ENUM('pending','confirmed','cancelled','refunded') NOT NULL DEFAULT 'pending';
CREATE TABLE password_resets (id BIGINT AUTO_INCREMENT PRIMARY KEY, member_id INT NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(member_id), FOREIGN KEY(member_id) REFERENCES members(id));
