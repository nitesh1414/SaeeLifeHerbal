-- Optional catalogue MRP; price remains the member checkout price. Apply once after migration-006.sql.
ALTER TABLE products ADD COLUMN mrp DECIMAL(12,2) NULL DEFAULT NULL;
