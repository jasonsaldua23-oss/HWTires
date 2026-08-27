USE hwtires;

DROP PROCEDURE IF EXISTS migrate_archive_statuses;

DELIMITER $$

CREATE PROCEDURE migrate_archive_statuses()
BEGIN
    ALTER TABLE quotations
        MODIFY status ENUM('pending', 'approved', 'rejected', 'archived') DEFAULT 'pending';

    ALTER TABLE job_orders
        MODIFY status ENUM('waiting', 'pending', 'in-progress', 'completed', 'cancelled', 'archived') DEFAULT 'waiting';

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'archived_at'
    ) = 0 THEN
        ALTER TABLE customers ADD COLUMN archived_at DATETIME NULL AFTER status;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'archived_by'
    ) = 0 THEN
        ALTER TABLE customers ADD COLUMN archived_by INT NULL AFTER archived_at;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'archive_reason'
    ) = 0 THEN
        ALTER TABLE customers ADD COLUMN archive_reason TEXT NULL AFTER archived_by;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'restored_at'
    ) = 0 THEN
        ALTER TABLE customers ADD COLUMN restored_at DATETIME NULL AFTER archive_reason;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'restored_by'
    ) = 0 THEN
        ALTER TABLE customers ADD COLUMN restored_by INT NULL AFTER restored_at;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_branch_records' AND COLUMN_NAME = 'archived_at'
    ) = 0 THEN
        ALTER TABLE customer_branch_records ADD COLUMN archived_at DATETIME NULL AFTER status;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_branch_records' AND COLUMN_NAME = 'archived_by'
    ) = 0 THEN
        ALTER TABLE customer_branch_records ADD COLUMN archived_by INT NULL AFTER archived_at;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_branch_records' AND COLUMN_NAME = 'archive_reason'
    ) = 0 THEN
        ALTER TABLE customer_branch_records ADD COLUMN archive_reason TEXT NULL AFTER archived_by;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_branch_records' AND COLUMN_NAME = 'restored_at'
    ) = 0 THEN
        ALTER TABLE customer_branch_records ADD COLUMN restored_at DATETIME NULL AFTER archive_reason;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_branch_records' AND COLUMN_NAME = 'restored_by'
    ) = 0 THEN
        ALTER TABLE customer_branch_records ADD COLUMN restored_by INT NULL AFTER restored_at;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'archived_at'
    ) = 0 THEN
        ALTER TABLE vehicles ADD COLUMN archived_at DATETIME NULL AFTER status;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'archived_by'
    ) = 0 THEN
        ALTER TABLE vehicles ADD COLUMN archived_by INT NULL AFTER archived_at;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'archive_reason'
    ) = 0 THEN
        ALTER TABLE vehicles ADD COLUMN archive_reason TEXT NULL AFTER archived_by;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'restored_at'
    ) = 0 THEN
        ALTER TABLE vehicles ADD COLUMN restored_at DATETIME NULL AFTER archive_reason;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'restored_by'
    ) = 0 THEN
        ALTER TABLE vehicles ADD COLUMN restored_by INT NULL AFTER restored_at;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotations' AND COLUMN_NAME = 'archived_at'
    ) = 0 THEN
        ALTER TABLE quotations ADD COLUMN archived_at DATETIME NULL AFTER status;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotations' AND COLUMN_NAME = 'archived_by'
    ) = 0 THEN
        ALTER TABLE quotations ADD COLUMN archived_by INT NULL AFTER archived_at;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotations' AND COLUMN_NAME = 'archive_reason'
    ) = 0 THEN
        ALTER TABLE quotations ADD COLUMN archive_reason TEXT NULL AFTER archived_by;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotations' AND COLUMN_NAME = 'restored_at'
    ) = 0 THEN
        ALTER TABLE quotations ADD COLUMN restored_at DATETIME NULL AFTER archive_reason;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotations' AND COLUMN_NAME = 'restored_by'
    ) = 0 THEN
        ALTER TABLE quotations ADD COLUMN restored_by INT NULL AFTER restored_at;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_orders' AND COLUMN_NAME = 'archived_at'
    ) = 0 THEN
        ALTER TABLE job_orders ADD COLUMN archived_at DATETIME NULL AFTER status;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_orders' AND COLUMN_NAME = 'archived_by'
    ) = 0 THEN
        ALTER TABLE job_orders ADD COLUMN archived_by INT NULL AFTER archived_at;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_orders' AND COLUMN_NAME = 'archive_reason'
    ) = 0 THEN
        ALTER TABLE job_orders ADD COLUMN archive_reason TEXT NULL AFTER archived_by;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_orders' AND COLUMN_NAME = 'restored_at'
    ) = 0 THEN
        ALTER TABLE job_orders ADD COLUMN restored_at DATETIME NULL AFTER archive_reason;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_orders' AND COLUMN_NAME = 'restored_by'
    ) = 0 THEN
        ALTER TABLE job_orders ADD COLUMN restored_by INT NULL AFTER restored_at;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_items' AND COLUMN_NAME = 'archived_at'
    ) = 0 THEN
        ALTER TABLE inventory_items ADD COLUMN archived_at DATETIME NULL AFTER status;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_items' AND COLUMN_NAME = 'archived_by'
    ) = 0 THEN
        ALTER TABLE inventory_items ADD COLUMN archived_by INT NULL AFTER archived_at;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_items' AND COLUMN_NAME = 'archive_reason'
    ) = 0 THEN
        ALTER TABLE inventory_items ADD COLUMN archive_reason TEXT NULL AFTER archived_by;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_items' AND COLUMN_NAME = 'restored_at'
    ) = 0 THEN
        ALTER TABLE inventory_items ADD COLUMN restored_at DATETIME NULL AFTER archive_reason;
    END IF;

    IF (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_items' AND COLUMN_NAME = 'restored_by'
    ) = 0 THEN
        ALTER TABLE inventory_items ADD COLUMN restored_by INT NULL AFTER restored_at;
    END IF;
END$$

DELIMITER ;

CALL migrate_archive_statuses();
DROP PROCEDURE migrate_archive_statuses;
