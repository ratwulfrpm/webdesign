-- ============================================================
-- apple_login — Product photos v2 + keywords migration
-- Renames image slots to canonical names, adds 'bottom' slot,
-- changes required view from 'aerial' to 'front',
-- and creates the product_keywords table.
--
-- Run once:
--   mysql -u root -proot apple_login < migrate_product_photos_v2.sql
-- Safe to run multiple times (guarded by IF NOT EXISTS / IF EXISTS).
-- ============================================================

USE `apple_login`;

-- ────────────────────────────────────────────────────────────────
-- STEP 1: Rename existing image_slot values to canonical names
--   aerial        → aerial   (keep; still a valid optional slot)
--   lateral_front → front
--   lateral_back  → back
--   lateral_left  → left
--   lateral_right → right
--
-- We temporarily drop the UNIQUE KEY so the rename UPDATEs work
-- even if there are rows in both old and new names (shouldn't
-- happen in practice, but this is safe).
-- ────────────────────────────────────────────────────────────────

-- 1a. Drop unique constraint temporarily (it references column value)
ALTER TABLE `supplier_product_images`
    DROP KEY IF EXISTS `uq_product_slot`;

-- 1b. Temporarily widen the ENUM to allow both old and new names
ALTER TABLE `supplier_product_images`
    MODIFY COLUMN `image_slot`
        ENUM('aerial','lateral_front','lateral_back','lateral_left','lateral_right',
             'front','back','left','right','bottom')
        NOT NULL;

-- 1c. Rename old values to new canonical names
UPDATE `supplier_product_images` SET `image_slot` = 'front'  WHERE `image_slot` = 'lateral_front';
UPDATE `supplier_product_images` SET `image_slot` = 'back'   WHERE `image_slot` = 'lateral_back';
UPDATE `supplier_product_images` SET `image_slot` = 'left'   WHERE `image_slot` = 'lateral_left';
UPDATE `supplier_product_images` SET `image_slot` = 'right'  WHERE `image_slot` = 'lateral_right';

-- 1d. Collapse ENUM to final canonical set (adds 'bottom')
ALTER TABLE `supplier_product_images`
    MODIFY COLUMN `image_slot`
        ENUM('front','back','left','right','aerial','bottom')
        NOT NULL
        COMMENT 'Canonical view type: front=required, others optional';

-- 1e. Re-add unique constraint with new name
ALTER TABLE `supplier_product_images`
    ADD UNIQUE KEY `uq_product_slot` (`product_id`, `image_slot`);

-- 1f. Add mime_type and uploaded_by_user_id columns if missing
ALTER TABLE `supplier_product_images`
    ADD COLUMN IF NOT EXISTS `mime_type`            VARCHAR(50)  NULL DEFAULT NULL AFTER `original_name`,
    ADD COLUMN IF NOT EXISTS `uploaded_by_user_id`  INT UNSIGNED NULL DEFAULT NULL AFTER `mime_type`,
    ADD COLUMN IF NOT EXISTS `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                    ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

-- ────────────────────────────────────────────────────────────────
-- STEP 2: Create product_keywords table
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `product_keywords` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED  NOT NULL,
    `keyword`    VARCHAR(60)   NOT NULL COMMENT 'Single lowercase word, no spaces',
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- A keyword can only appear once per product
    UNIQUE KEY `uq_product_keyword` (`product_id`, `keyword`),

    KEY `idx_keyword` (`keyword`),
    KEY `idx_product` (`product_id`),

    CONSTRAINT `fk_kw_product`
        FOREIGN KEY (`product_id`) REFERENCES `supplier_products`(`id`) ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Supplier-supplied keywords/tags associated with a product';

-- ────────────────────────────────────────────────────────────────
-- Done
-- ────────────────────────────────────────────────────────────────
SELECT CONCAT(
    'Migration complete. ',
    'supplier_product_images: new ENUM (front/back/left/right/aerial/bottom). ',
    'product_keywords: created.'
) AS status;
