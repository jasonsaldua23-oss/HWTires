USE hwtires;

DROP PROCEDURE IF EXISTS migrate_inventory_product_details;

DELIMITER $$

CREATE PROCEDURE migrate_inventory_product_details()
BEGIN
    IF (
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'inventory_items'
          AND COLUMN_NAME = 'model'
    ) = 0 THEN
        ALTER TABLE inventory_items ADD COLUMN model VARCHAR(100) NULL AFTER brand;
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'inventory_items'
          AND COLUMN_NAME = 'serial_number'
    ) = 0 THEN
        ALTER TABLE inventory_items ADD COLUMN serial_number VARCHAR(120) NULL AFTER sku;
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'inventory_items'
          AND COLUMN_NAME = 'manufacturing_date'
    ) = 0 THEN
        ALTER TABLE inventory_items ADD COLUMN manufacturing_date DATE NULL AFTER serial_number;
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'inventory_items'
          AND INDEX_NAME = 'idx_serial_number'
    ) = 0 THEN
        ALTER TABLE inventory_items ADD INDEX idx_serial_number (serial_number);
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'inventory_items'
          AND INDEX_NAME = 'idx_manufacturing_date'
    ) = 0 THEN
        ALTER TABLE inventory_items ADD INDEX idx_manufacturing_date (manufacturing_date);
    END IF;
END$$

DELIMITER ;

CALL migrate_inventory_product_details();
DROP PROCEDURE migrate_inventory_product_details;

UPDATE inventory_items
SET serial_number = sku
WHERE serial_number IS NULL
  AND sku IS NOT NULL
  AND sku <> '';
