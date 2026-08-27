USE hwtires;

-- Run after importing both:
-- 1. database/hwtires_customer_records_2023_aug16_2026_CLEANED.sql
-- 2. database/hwtires_inventory_online_catalog_2023_aug16_2026_repopulate.sql
--
-- This repairs generated stock-out rows so service-issued inventory is tagged
-- to the customer, vehicle, job order, and quotation it belongs to.

UPDATE inventory_transactions t
INNER JOIN quotations q ON q.id = t.reference_id
SET t.customer_id = COALESCE(t.customer_id, q.customer_id),
    t.vehicle_id = COALESCE(t.vehicle_id, q.vehicle_id),
    t.quotation_id = COALESCE(t.quotation_id, q.id)
WHERE t.transaction_type = 'stock_out'
  AND t.reference_type = 'quotation'
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
WHERE t.transaction_type = 'stock_out'
  AND t.reference_type = 'job_order'
  AND (
      t.customer_id IS NULL
      OR t.vehicle_id IS NULL
      OR t.job_order_id IS NULL
      OR t.quotation_id IS NULL
  );

DROP TEMPORARY TABLE IF EXISTS tmp_inventory_job_stockout_tags;

CREATE TEMPORARY TABLE tmp_inventory_job_stockout_tags AS
SELECT
    tx.transaction_id,
    jobs.job_order_id,
    jobs.quotation_id,
    jobs.customer_id,
    jobs.vehicle_id
FROM (
    SELECT
        t.id AS transaction_id,
        DATE(t.created_at) AS transaction_date,
        i.branch_id,
        ROW_NUMBER() OVER (
            PARTITION BY DATE(t.created_at), i.branch_id
            ORDER BY t.created_at, t.id
        ) AS stockout_sequence
    FROM inventory_transactions t
    INNER JOIN inventory_items i ON i.id = t.item_id
    LEFT JOIN job_orders existing_job ON existing_job.id = t.reference_id
    WHERE t.transaction_type = 'stock_out'
      AND t.reference_type = 'job_order'
      AND existing_job.id IS NULL
) tx
INNER JOIN (
    SELECT
        jo.id AS job_order_id,
        jo.quotation_id,
        jo.customer_id,
        jo.vehicle_id,
        jo.branch_id,
        jo.job_date,
        ROW_NUMBER() OVER (
            PARTITION BY jo.job_date, jo.branch_id
            ORDER BY jo.created_at, jo.id
        ) AS job_sequence,
        COUNT(*) OVER (
            PARTITION BY jo.job_date, jo.branch_id
        ) AS job_count
    FROM job_orders jo
    WHERE jo.job_date IS NOT NULL
      AND COALESCE(jo.status, '') <> 'archived'
) jobs
    ON jobs.job_date = tx.transaction_date
   AND jobs.branch_id = tx.branch_id
   AND jobs.job_sequence = MOD(tx.stockout_sequence - 1, jobs.job_count) + 1;

UPDATE inventory_transactions t
INNER JOIN tmp_inventory_job_stockout_tags m ON m.transaction_id = t.id
SET t.reference_id = m.job_order_id,
    t.customer_id = m.customer_id,
    t.vehicle_id = m.vehicle_id,
    t.job_order_id = m.job_order_id,
    t.quotation_id = COALESCE(m.quotation_id, t.quotation_id)
WHERE t.transaction_type = 'stock_out'
  AND t.reference_type = 'job_order';

DROP TEMPORARY TABLE IF EXISTS tmp_inventory_job_stockout_tags;

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
WHERE t.transaction_type = 'stock_out'
  AND t.reference_type = 'job_order_item'
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
WHERE t.transaction_type = 'stock_out'
  AND t.reference_type = 'inter_branch_transfer'
  AND (
      t.customer_id IS NULL
      OR t.vehicle_id IS NULL
      OR t.quotation_id IS NULL
      OR t.job_order_id IS NULL
  );
