-- Apply once after migration-004.sql. Existing full refunds become full-quantity refund lines.
ALTER TABLE refund_batches ADD COLUMN customer_amount DECIMAL(16,2) NULL;
CREATE TABLE refund_lines (id INT AUTO_INCREMENT PRIMARY KEY, purchase_id INT NOT NULL, batch_id INT NOT NULL, quantity INT NOT NULL, UNIQUE KEY (purchase_id,batch_id), FOREIGN KEY(purchase_id) REFERENCES purchases(id), FOREIGN KEY(batch_id) REFERENCES refund_batches(id));
INSERT INTO refund_lines (purchase_id,batch_id,quantity) SELECT r.purchase_id,r.batch_id,p.quantity FROM purchase_refunds r JOIN purchases p ON p.id=r.purchase_id;
CREATE TABLE refund_ledger_links (refund_line_id INT NOT NULL, original_ledger_id INT NOT NULL, reversal_ledger_id INT NOT NULL UNIQUE, PRIMARY KEY(refund_line_id,original_ledger_id), FOREIGN KEY(refund_line_id) REFERENCES refund_lines(id), FOREIGN KEY(original_ledger_id) REFERENCES wallet_ledger(id), FOREIGN KEY(reversal_ledger_id) REFERENCES wallet_ledger(id));
ALTER TABLE orders MODIFY status ENUM('pending','confirmed','cancelled','partially_refunded','refunded') NOT NULL DEFAULT 'pending';
