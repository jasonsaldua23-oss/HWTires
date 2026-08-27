USE hwtires;

DROP PROCEDURE IF EXISTS migrate_inventory_transaction_tagging;

DELIMITER $$

CREATE PROCEDURE migrate_inventory_transaction_tagging()
BEGIN
    IF (
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'inventory_transactions'
          AND COLUMN_NAME = 'customer_id'
    ) = 0 THEN
        ALTER TABLE inventory_transactions ADD COLUMN customer_id INT NULL AFTER created_by;
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'inventory_transactions'
          AND COLUMN_NAME = 'vehicle_id'
    ) = 0 THEN
        ALTER TABLE inventory_transactions ADD COLUMN vehicle_id INT NULL AFTER customer_id;
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'inventory_transactions'
          AND COLUMN_NAME = 'job_order_id'
    ) = 0 THEN
        ALTER TABLE inventory_transactions ADD COLUMN job_order_id INT NULL AFTER vehicle_id;
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'inventory_transactions'
          AND COLUMN_NAME = 'quotation_id'
    ) = 0 THEN
        ALTER TABLE inventory_transactions ADD COLUMN quotation_id INT NULL AFTER job_order_id;
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'inventory_transactions'
          AND COLUMN_NAME = 'quotation_item_id'
    ) = 0 THEN
        ALTER TABLE inventory_transactions ADD COLUMN quotation_item_id INT NULL AFTER quotation_id;
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'inventory_transactions'
          AND INDEX_NAME = 'idx_inv_tx_customer'
    ) = 0 THEN
        ALTER TABLE inventory_transactions ADD INDEX idx_inv_tx_customer (customer_id);
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'inventory_transactions'
          AND INDEX_NAME = 'idx_inv_tx_vehicle'
    ) = 0 THEN
        ALTER TABLE inventory_transactions ADD INDEX idx_inv_tx_vehicle (vehicle_id);
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'inventory_transactions'
          AND INDEX_NAME = 'idx_inv_tx_job'
    ) = 0 THEN
        ALTER TABLE inventory_transactions ADD INDEX idx_inv_tx_job (job_order_id);
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'inventory_transactions'
          AND INDEX_NAME = 'idx_inv_tx_quote'
    ) = 0 THEN
        ALTER TABLE inventory_transactions ADD INDEX idx_inv_tx_quote (quotation_id);
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'inventory_transactions'
          AND INDEX_NAME = 'idx_inv_tx_quote_item'
    ) = 0 THEN
        ALTER TABLE inventory_transactions ADD INDEX idx_inv_tx_quote_item (quotation_item_id);
    END IF;
END$$

DELIMITER ;

CALL migrate_inventory_transaction_tagging();
DROP PROCEDURE migrate_inventory_transaction_tagging;

UPDATE inventory_transactions t
INNER JOIN quotations q ON q.id = t.reference_id
SET t.customer_id = COALESCE(t.customer_id, q.customer_id),
    t.vehicle_id = COALESCE(t.vehicle_id, q.vehicle_id),
    t.quotation_id = COALESCE(t.quotation_id, q.id)
WHERE t.reference_type = 'quotation'
  AND (
      t.customer_id IS NULL
      OR t.vehicle_id IS NULL
      OR t.quotation_id IS NULL
  );

UPDATE inventory_transactions t
INNER JOIN job_orders jo ON jo.id = t.reference_id
SET t.customer_id = COALESCE(t.customer_id, jo.customer_id),
    t.vehicle_id = COALESCE(t.vehicle_id, jo.vehicle_id),
    t.job_order_id = COALESCE(t.job_order_id, jo.id),
    t.quotation_id = COALESCE(t.quotation_id, jo.quotation_id)
WHERE t.reference_type = 'job_order'
  AND (
      t.customer_id IS NULL
      OR t.vehicle_id IS NULL
      OR t.job_order_id IS NULL
      OR t.quotation_id IS NULL
  );

UPDATE inventory_transactions t
INNER JOIN quotation_items qi ON qi.id = t.reference_id
INNER JOIN quotations q ON q.id = qi.quotation_id
LEFT JOIN (
    SELECT quotation_id, MIN(id) AS job_order_id
    FROM job_orders
    WHERE quotation_id IS NOT NULL
    GROUP BY quotation_id
) jo ON jo.quotation_id = q.id
SET t.customer_id = COALESCE(t.customer_id, q.customer_id),
    t.vehicle_id = COALESCE(t.vehicle_id, q.vehicle_id),
    t.quotation_id = COALESCE(t.quotation_id, q.id),
    t.quotation_item_id = COALESCE(t.quotation_item_id, qi.id),
    t.job_order_id = COALESCE(t.job_order_id, jo.job_order_id)
WHERE t.reference_type = 'job_order_item'
  AND (
      t.customer_id IS NULL
      OR t.vehicle_id IS NULL
      OR t.quotation_id IS NULL
      OR t.quotation_item_id IS NULL
      OR t.job_order_id IS NULL
  );

UPDATE inventory_transactions t
INNER JOIN inter_branch_transfer_requests tr ON tr.id = t.reference_id
LEFT JOIN quotations q ON q.id = tr.quotation_id
LEFT JOIN (
    SELECT quotation_id, MIN(id) AS job_order_id
    FROM job_orders
    WHERE quotation_id IS NOT NULL
    GROUP BY quotation_id
) jo ON jo.quotation_id = tr.quotation_id
SET t.customer_id = COALESCE(t.customer_id, tr.customer_id, q.customer_id),
    t.vehicle_id = COALESCE(t.vehicle_id, q.vehicle_id),
    t.quotation_id = COALESCE(t.quotation_id, tr.quotation_id),
    t.job_order_id = COALESCE(t.job_order_id, jo.job_order_id)
WHERE t.reference_type = 'inter_branch_transfer'
  AND (
      t.customer_id IS NULL
      OR t.vehicle_id IS NULL
      OR t.quotation_id IS NULL
      OR t.job_order_id IS NULL
  );
