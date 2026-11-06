-- Apply once after schema.sql, including on databases created by the first version.
ALTER TABLE members ADD COLUMN kyc_status ENUM('incomplete','pending','verified','rejected') NOT NULL DEFAULT 'incomplete';
ALTER TABLE products ADD COLUMN content MEDIUMTEXT NOT NULL;
ALTER TABLE slides ADD COLUMN show_text TINYINT NOT NULL DEFAULT 1, ADD COLUMN effect ENUM('fade','zoom','none') NOT NULL DEFAULT 'fade';
ALTER TABLE rules ADD COLUMN kind ENUM('milestone','royalty') NOT NULL DEFAULT 'milestone';
ALTER TABLE payouts ADD COLUMN status ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'paid', ADD COLUMN bank_name VARCHAR(160) NOT NULL DEFAULT '', ADD COLUMN bank_account VARCHAR(100) NOT NULL DEFAULT '', ADD COLUMN bank_ifsc VARCHAR(30) NOT NULL DEFAULT '', ADD COLUMN note VARCHAR(500) NOT NULL DEFAULT '', ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
CREATE TABLE pages (id INT AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(80) NOT NULL UNIQUE, title VARCHAR(160) NOT NULL, subtitle VARCHAR(255) NOT NULL DEFAULT '', content MEDIUMTEXT NOT NULL, show_nav TINYINT NOT NULL DEFAULT 0, published TINYINT NOT NULL DEFAULT 1);
INSERT INTO pages (slug,title,subtitle,content,show_nav) VALUES
('about','Rooted in care, growing together','A better future starts with the people who grow it.','<h2>Here for the hands that feed us.</h2><p>Saee Life Herbal is built around a simple belief: when we support farmers, everyone grows. We bring together thoughtful crop care and a community that celebrates shared progress.</p>',1),
('community','A community that grows together','For farmers, families, and a more flourishing future.','<h2>Better together, naturally.</h2><p>Join a network of farmers sharing opportunities and growing together. Members can invite others and may receive rewards based on eligible purchases and the current plan rules.</p><p>Rewards are not guaranteed. Complete and verified KYC and bank details are required before a payout. Ask the company for current plan terms.</p>',1),
('contact','Let’s grow together','We’re here to help. Reach out to the Saee Life Herbal team.','<h2>Get in touch</h2><p>Have a question about our products or community? We would love to hear from you.</p>',1);
CREATE TABLE product_media (id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, url VARCHAR(500) NOT NULL, media_type ENUM('image','video') NOT NULL, position INT NOT NULL DEFAULT 0, FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE);
CREATE TABLE social_links (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, url VARCHAR(500) NOT NULL, icon VARCHAR(30) NOT NULL DEFAULT 'link');
CREATE TABLE royalty_awards (id INT AUTO_INCREMENT PRIMARY KEY, member_id INT NOT NULL, rule_id INT NOT NULL, period CHAR(7) NOT NULL, amount DECIMAL(12,2) NOT NULL, UNIQUE KEY (member_id,rule_id,period), FOREIGN KEY(member_id) REFERENCES members(id), FOREIGN KEY(rule_id) REFERENCES rules(id));
INSERT INTO settings (`key`,`value`) VALUES ('favicon_url','assets/icon-512.png'),('logo_text','Saee Life Herbal');
ALTER TABLE purchases ADD COLUMN receipt_reference VARCHAR(100) NULL UNIQUE, ADD COLUMN quantity INT NOT NULL DEFAULT 1;
INSERT INTO settings (`key`,`value`) VALUES
('home_eyebrow','A little care goes a long way'),('home_title','Everything your farm needs to flourish.'),('home_description','Explore our range of agricultural solutions, created with your farm in mind.'),
('feature_eyebrow','A healthier way to grow'),('feature_title','Good for your crops. Better for your future.'),('feature_description','At Saee Life Herbal, we believe a thriving farm begins with thoughtful care. We are here to support the people who nurture our land, with reliable solutions for every season.'),
('products_eyebrow','Care for every growing season'),('products_title','Made for the way you grow.'),('products_description','Discover solutions for a healthier, happier farm.'),
('community_eyebrow','Stronger together'),('community_title','When farmers grow, we all grow.'),('community_description','Be part of a community built on shared progress, opportunity, and a better tomorrow.');
UPDATE settings SET value='' WHERE `key`='logo_text';
CREATE TABLE sale_receipts (reference VARCHAR(100) PRIMARY KEY, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
INSERT INTO sale_receipts (reference) SELECT receipt_reference FROM purchases WHERE receipt_reference IS NOT NULL;
CREATE TABLE orders (id INT AUTO_INCREMENT PRIMARY KEY, member_id INT NOT NULL, shipping_address TEXT NOT NULL, contact_phone VARCHAR(40) NOT NULL, total DECIMAL(16,2) NOT NULL, status ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending', receipt_reference VARCHAR(100) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(member_id) REFERENCES members(id));
CREATE TABLE order_items (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, product_id INT NOT NULL, quantity INT NOT NULL, unit_price DECIMAL(12,2) NOT NULL, direct_commission DECIMAL(12,2) NOT NULL, grand_commission DECIMAL(12,2) NOT NULL, FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(product_id) REFERENCES products(id));
ALTER TABLE purchases ADD COLUMN order_id INT NULL, ADD FOREIGN KEY(order_id) REFERENCES orders(id);
ALTER TABLE products ADD COLUMN is_sample TINYINT NOT NULL DEFAULT 0;
UPDATE products SET is_sample=1 WHERE name IN ('Herbal Crop Booster','Organic Plant Protector','Soil Health Enhancer','Natural Growth Formula');
UPDATE payouts p JOIN members m ON m.id=p.member_id SET p.bank_name=COALESCE(m.bank_name,''),p.bank_account=COALESCE(m.bank_account,''),p.bank_ifsc=COALESCE(m.bank_ifsc,'') WHERE p.status='paid';
CREATE TABLE login_attempts (attempt_key CHAR(64) PRIMARY KEY, attempts INT NOT NULL DEFAULT 0, last_attempt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP);
INSERT INTO settings (`key`,`value`) VALUES
('announcement_text','Growing a healthier tomorrow, together.'),('announcement_link_text','Discover the Saee community'),
('trust_1_title','Inspired by Nature'),('trust_1_text','Care that starts at the roots'),('trust_2_title','Farmer First'),('trust_2_text','Growing with our community'),('trust_3_title','Quality You Can Trust'),('trust_3_text','Thoughtfully made solutions'),('trust_4_title','Growing Together'),('trust_4_text','Better futures for everyone'),
('category_1_title','Plant Nutrition'),('category_1_text','Nourish growth from the ground up.'),('category_2_title','Crop Protection'),('category_2_text','Care for the crops you work hard for.'),('category_3_title','Soil Care'),('category_3_text','Build a stronger foundation for growth.'),('category_4_title','Growth Promoters'),('category_4_text','Help your farm reach its potential.'),
('feature_check_1','Farmer-focused approach'),('feature_check_2','Quality crop care'),('feature_check_3','Rooted in nature'),('feature_check_4','Growing as one community'),
('feature_image_title','Made with care'),('feature_image_text','for the land and the people on it'),('footer_description','Rooted in care. Growing a greener, more prosperous future for every farmer.'),('footer_contact_text','Questions about our products or community? We would love to hear from you.');
