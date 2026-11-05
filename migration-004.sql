-- Apply once after migration-003.sql. Existing admins remain owners; existing email addresses are unverified.
ALTER TABLE members ADD COLUMN admin_role ENUM('owner','content','operations','finance') NULL, ADD COLUMN is_active TINYINT NOT NULL DEFAULT 1, ADD COLUMN email_verified_at DATETIME NULL;
UPDATE members SET admin_role='owner' WHERE is_admin=1;
CREATE TABLE email_verifications (id BIGINT AUTO_INCREMENT PRIMARY KEY, member_id INT NOT NULL, email VARCHAR(190) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(member_id), FOREIGN KEY(member_id) REFERENCES members(id));
INSERT INTO settings (`key`,`value`) VALUES
('hero_kicker','Rooted in nature, made for growth'),('hero_shop_button','Explore Products'),('hero_community_button','Our Community'),('feature_button','Get to know us'),('products_button','View all products'),('community_button','Discover our community'),('feature_image_url','assets/products.jpg'),
('nav_home','Home'),('nav_about','About Us'),('nav_products','Our Products'),('nav_community','Farmer Community'),('nav_contact','Contact Us'),('footer_explore','Explore'),('footer_links','Quick links'),('footer_contact_heading','Let’s grow together');
