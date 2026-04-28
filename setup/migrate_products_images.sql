-- ============================================================
-- apple_login — Product images table migration
-- Run once: mysql -u root -proot apple_login < migrate_products_images.sql
-- Safe to run multiple times (IF NOT EXISTS).
-- ============================================================

USE `apple_login`;

CREATE TABLE IF NOT EXISTS `supplier_product_images` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `product_id`    INT UNSIGNED  NOT NULL,
    `supplier_id`   INT UNSIGNED  NOT NULL,
    `image_slot`    ENUM('aerial','lateral_front','lateral_back','lateral_left','lateral_right')
                                  NOT NULL,
    `file_path`     VARCHAR(500)  NOT NULL COMMENT 'path relative to webroot uploads/',
    `original_name` VARCHAR(255)  NOT NULL DEFAULT '',
    `file_size`     INT UNSIGNED  NOT NULL DEFAULT 0,
    `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    -- Each product can only have one image per slot
    UNIQUE KEY `uq_product_slot` (`product_id`, `image_slot`),
    KEY `idx_product`  (`product_id`),
    KEY `idx_supplier` (`supplier_id`),

    CONSTRAINT `fk_img_product`
        FOREIGN KEY (`product_id`)  REFERENCES `supplier_products`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_img_supplier`
        FOREIGN KEY (`supplier_id`) REFERENCES `users`(`id`)             ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Product images (up to 5 per product: aerial + 4 lateral views)';

SELECT 'supplier_product_images table ready.' AS status;
