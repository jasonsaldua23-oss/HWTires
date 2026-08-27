-- Highway Tires Management
-- Clean append seed: yearly cross-branch vehicle history with issued inventory
-- Purpose:
--   Adds completed cross-branch vehicle visits for 2023, 2024, 2025, and 2026.
--   Each seeded visit has:
--     - approved service operation
--     - completed job order
--     - completed service history
--     - linked stock_out inventory transactions
--
-- Import order in phpMyAdmin:
--   1. hwtires_customer_records_2023_aug16_2026_CLEANED.sql
--   2. hwtires_inventory_online_catalog_2023_aug16_2026_repopulate.sql
--   3. this file
--
-- This file is idempotent. It appends only QCBHY/JOCBHY records and will not
-- duplicate them on repeated import. It does not create cancelled job orders.

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_cross_branch_yearly_history_seed;

CREATE TEMPORARY TABLE tmp_cross_branch_yearly_history_seed (
    plate_number VARCHAR(50) NOT NULL,
    visit_branch_name VARCHAR(100) NOT NULL,
    quotation_number VARCHAR(50) NOT NULL,
    job_number VARCHAR(50) NOT NULL,
    service_date DATE NOT NULL,
    scheduled_start TIME NOT NULL,
    scheduled_end TIME NOT NULL,
    technician_name VARCHAR(100) NOT NULL,
    first_service VARCHAR(255) NOT NULL,
    first_category VARCHAR(50) NOT NULL,
    first_price DECIMAL(10, 2) NOT NULL,
    second_service VARCHAR(255) NOT NULL,
    second_category VARCHAR(50) NOT NULL,
    second_price DECIMAL(10, 2) NOT NULL,
    product_one_name VARCHAR(255) NOT NULL,
    product_one_qty INT NOT NULL DEFAULT 1,
    product_two_name VARCHAR(255) NOT NULL,
    product_two_qty INT NOT NULL DEFAULT 1,
    mileage_at_service INT NOT NULL,
    visit_notes TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_cross_branch_yearly_history_seed
    (plate_number, visit_branch_name, quotation_number, job_number, service_date, scheduled_start, scheduled_end,
     technician_name, first_service, first_category, first_price, second_service, second_category, second_price,
     product_one_name, product_one_qty, product_two_name, product_two_qty, mileage_at_service, visit_notes)
VALUES
    ('JYD-1124', 'Magsaysay Branch', 'QCBHY-JYD1124-20230912', 'JOCBHY-JYD1124-20230912', '2023-09-12', '09:10:00', '11:30:00',
     'Jerome C. Delos Santos', 'Service Inspection', 'Inspection', 450.00, 'EGR Cleaning', 'Maintenance', 1200.00,
     'Denso Cabin Air Filter', 1, 'Bosch Wiper Blades Pair', 1, 53620, 'Toyota Hi-Lux visited Magsaysay Branch for cabin filter replacement and visibility check.'),
    ('JYD-1124', 'Bata Branch', 'QCBHY-JYD1124-20240430', 'JOCBHY-JYD1124-20240430', '2024-04-30', '13:10:00', '17:10:00',
     'Tom Anderson', 'Service Inspection', 'Inspection', 450.00, 'Brake Cleaning and Adjustment / Brake Repair', 'Brakes', 1600.00,
     'Bendix Brake Pads Set', 1, 'Prestone Brake Fluid DOT4', 1, 54046, 'Brake service was completed at Bata Branch during a cross-branch visit.'),
    ('JYD-1124', 'Magsaysay Branch', 'QCBHY-JYD1124-20250816', 'JOCBHY-JYD1124-20250816', '2025-08-16', '14:40:00', '15:40:00',
     'James Martinez', 'Service Inspection', 'Inspection', 450.00, 'Preventive Maintenance Check-Up', 'Maintenance', 2500.00,
     'Motolite Battery 3SM', 1, 'NGK Spark Plug Set', 1, 54385, 'Preventive service included battery and ignition checks at Magsaysay Branch.'),
    ('JYD-1124', 'Bata Branch', 'QCBHY-JYD1124-20260718', 'JOCBHY-JYD1124-20260718', '2026-07-18', '10:50:00', '12:50:00',
     'Tom Anderson', 'Service Inspection', 'Inspection', 450.00, 'Tire Mounting and Rotation / Pneumatic Tire Mounting', 'Tires', 400.00,
     'APOLLO ECOPIA EP150 265/65R17 Tire', 2, 'K&N Air Filter', 1, 54440, 'Bata Branch completed tire service before the latest recorded visit.'),

    ('BVP-1950', 'Lacson Branch', 'QCBHY-BVP1950-20230822', 'JOCBHY-BVP1950-20230822', '2023-08-22', '08:40:00', '10:10:00',
     'Mark Anthony D. Villanueva', 'Service Inspection', 'Inspection', 450.00, 'Oil Change and Engine Tune-Up', 'Maintenance', 1000.00,
     'NGK Spark Plug Set', 1, 'Bosch Wiper Blades Pair', 1, 32780, 'Honda Civic visited Lacson Branch for tune-up and visibility items.'),
    ('BVP-1950', 'Bata Branch', 'QCBHY-BVP1950-20240517', 'JOCBHY-BVP1950-20240517', '2024-05-17', '11:00:00', '13:20:00',
     'Rafael M. Gonzales', 'Service Inspection', 'Inspection', 450.00, 'Under Chassis and Suspension Repair', 'Suspension', 4500.00,
     '555 Stabilizer Link', 2, 'K&N Air Filter', 1, 33310, 'Bata Branch handled suspension check and air filter replacement.'),
    ('BVP-1950', 'Lacson Branch', 'QCBHY-BVP1950-20250709', 'JOCBHY-BVP1950-20250709', '2025-07-09', '09:20:00', '11:00:00',
     'Mark Anthony D. Villanueva', 'Battery Check-Up and Fast Charging', 'Electrical', 100.00, 'Service Inspection', 'Inspection', 450.00,
     'Motolite Battery 2SM', 1, 'Denso Cabin Air Filter', 1, 33940, 'Battery replacement and cabin filter service were completed at Lacson Branch.'),
    ('BVP-1950', 'Bata Branch', 'QCBHY-BVP1950-20260806', 'JOCBHY-BVP1950-20260806', '2026-08-06', '10:30:00', '12:00:00',
     'Tom Anderson', 'Service Inspection', 'Inspection', 450.00, 'Car Accessories Installation', 'Accessories', 500.00,
     'Bosch Wiper Blades Pair', 1, 'Prestone Coolant Gallon', 1, 34190, 'Accessory and basic maintenance items were issued at Bata Branch.'),

    ('ECY-7092', 'Lacson Branch', 'QCBHY-ECY7092-20230908', 'JOCBHY-ECY7092-20230908', '2023-09-08', '15:10:00', '17:00:00',
     'Mike Johnson', 'Service Inspection', 'Inspection', 450.00, 'Brake Cleaning and Adjustment / Brake Repair', 'Brakes', 1600.00,
     'Bendix Brake Pads Set', 1, 'Prestone Brake Fluid DOT4', 1, 118940, 'Hyundai Accent visited Lacson Branch for brake service before returning to Bata.'),
    ('ECY-7092', 'Magsaysay Branch', 'QCBHY-ECY7092-20240614', 'JOCBHY-ECY7092-20240614', '2024-06-14', '09:30:00', '12:20:00',
     'Jerome C. Delos Santos', 'Service Inspection', 'Inspection', 450.00, 'Under Chassis and Suspension Repair', 'Suspension', 4500.00,
     '555 Ball Joint', 2, 'KYB Shock Absorber Front', 1, 120380, 'Suspension repair was completed at Magsaysay Branch.'),
    ('ECY-7092', 'Lacson Branch', 'QCBHY-ECY7092-20251018', 'JOCBHY-ECY7092-20251018', '2025-10-18', '13:20:00', '14:50:00',
     'Mark Anthony D. Villanueva', 'Oil Change and Engine Tune-Up', 'Maintenance', 1000.00, 'Service Inspection', 'Inspection', 450.00,
     'Mann Filter Engine Oil Filter', 1, 'Vic Fuel Filter', 1, 122280, 'Routine maintenance used filter items from Lacson inventory.'),
    ('ECY-7092', 'Magsaysay Branch', 'QCBHY-ECY7092-20260426', 'JOCBHY-ECY7092-20260426', '2026-04-26', '08:50:00', '10:40:00',
     'Jerome C. Delos Santos', 'Service Inspection', 'Inspection', 450.00, 'Preventive Maintenance Check-Up', 'Maintenance', 2500.00,
     'NGK Spark Plug Set', 1, 'Denso Cabin Air Filter', 1, 123020, 'Preventive service completed at Magsaysay Branch before the latest Bata record.'),

    ('BXF-4400', 'Bata Branch', 'QCBHY-BXF4400-20231019', 'JOCBHY-BXF4400-20231019', '2023-10-19', '10:00:00', '11:30:00',
     'Rafael M. Gonzales', 'AC Refrigerant Charging', 'Air Conditioning', 1500.00, 'Service Inspection', 'Inspection', 450.00,
     'K&N Air Filter', 1, 'Denso Cabin Air Filter', 1, 52880, 'Chevrolet Trailblazer visited Bata Branch for air and cabin filter maintenance.'),
    ('BXF-4400', 'Lacson Branch', 'QCBHY-BXF4400-20240712', 'JOCBHY-BXF4400-20240712', '2024-07-12', '14:10:00', '16:30:00',
     'Mark Anthony D. Villanueva', 'Service Inspection', 'Inspection', 450.00, 'Under Chassis and Suspension Repair', 'Suspension', 4500.00,
     'KYB Shock Absorber Rear', 1, '555 Stabilizer Link', 2, 54120, 'Rear suspension components were replaced at Lacson Branch.'),
    ('BXF-4400', 'Bata Branch', 'QCBHY-BXF4400-20251108', 'JOCBHY-BXF4400-20251108', '2025-11-08', '09:40:00', '11:10:00',
     'Tom Anderson', 'Battery Check-Up and Fast Charging', 'Electrical', 100.00, 'Service Inspection', 'Inspection', 450.00,
     'Motolite Battery 3SM', 1, 'Bosch Wiper Blades Pair', 1, 55560, 'Battery replacement and wiper change completed at Bata Branch.'),
    ('BXF-4400', 'Lacson Branch', 'QCBHY-BXF4400-20260330', 'JOCBHY-BXF4400-20260330', '2026-03-30', '13:00:00', '14:50:00',
     'Mike Johnson', 'Service Inspection', 'Inspection', 450.00, 'Brake Cleaning and Adjustment / Brake Repair', 'Brakes', 1600.00,
     'Bendix Brake Pads Set', 1, 'Prestone Brake Fluid DOT4', 1, 56280, 'Brake service completed at Lacson Branch before the latest Magsaysay visit.');

DROP TEMPORARY TABLE IF EXISTS tmp_cross_branch_product_candidates;

CREATE TEMPORARY TABLE tmp_cross_branch_product_candidates (
    quotation_number VARCHAR(50) NOT NULL,
    item_slot TINYINT NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL,
    item_id INT NOT NULL,
    category VARCHAR(50) NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    PRIMARY KEY (quotation_number, item_slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_cross_branch_product_candidates
    (quotation_number, item_slot, item_name, quantity, item_id, category, unit_price)
SELECT
    t.quotation_number,
    1,
    i.item_name,
    t.product_one_qty,
    i.id,
    i.category,
    COALESCE(i.unit_price, 0)
FROM tmp_cross_branch_yearly_history_seed t
INNER JOIN branches b ON b.name = t.visit_branch_name
INNER JOIN inventory_items i ON i.id = (
    SELECT i_pick.id
    FROM inventory_items i_pick
    WHERE i_pick.branch_id = b.id
      AND i_pick.item_name = t.product_one_name
      AND i_pick.status = 'active'
    ORDER BY i_pick.quantity DESC, i_pick.id ASC
    LIMIT 1
)
WHERE t.product_one_name <> ''
  AND t.product_one_qty > 0;

INSERT INTO tmp_cross_branch_product_candidates
    (quotation_number, item_slot, item_name, quantity, item_id, category, unit_price)
SELECT
    t.quotation_number,
    2,
    i.item_name,
    t.product_two_qty,
    i.id,
    i.category,
    COALESCE(i.unit_price, 0)
FROM tmp_cross_branch_yearly_history_seed t
INNER JOIN branches b ON b.name = t.visit_branch_name
INNER JOIN inventory_items i ON i.id = (
    SELECT i_pick.id
    FROM inventory_items i_pick
    WHERE i_pick.branch_id = b.id
      AND i_pick.item_name = t.product_two_name
      AND i_pick.status = 'active'
    ORDER BY i_pick.quantity DESC, i_pick.id ASC
    LIMIT 1
)
WHERE t.product_two_name <> ''
  AND t.product_two_qty > 0;

INSERT INTO customer_branch_records
    (customer_id, branch_id, status, sales_in_charge, sales_branch_label, last_visit_at, created_by, created_at, updated_at)
SELECT
    v.customer_id,
    b.id,
    'active',
    NULL,
    b.name,
    TIMESTAMP(t.service_date, t.scheduled_start),
    COALESCE(
        (SELECT u.id FROM users u WHERE u.branch_id = b.id AND u.role = 'front-desk' AND u.status = 'active' ORDER BY u.id LIMIT 1),
        (SELECT u.id FROM users u WHERE u.role = 'admin' AND u.status = 'active' ORDER BY u.id LIMIT 1)
    ),
    TIMESTAMP(t.service_date, t.scheduled_start),
    CURRENT_TIMESTAMP
FROM tmp_cross_branch_yearly_history_seed t
INNER JOIN vehicles v ON v.plate_number = t.plate_number
INNER JOIN branches b ON b.name = t.visit_branch_name
ON DUPLICATE KEY UPDATE
    status = 'active',
    last_visit_at = CASE
        WHEN customer_branch_records.last_visit_at IS NULL OR customer_branch_records.last_visit_at < VALUES(last_visit_at)
            THEN VALUES(last_visit_at)
        ELSE customer_branch_records.last_visit_at
    END,
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO customer_visits
    (customer_id, branch_id, visit_date, visit_type, notes, created_by, created_at)
SELECT
    v.customer_id,
    b.id,
    TIMESTAMP(t.service_date, t.scheduled_start),
    'job_order',
    CONCAT('Yearly cross-branch vehicle visit for ', v.plate_number, '. ', t.visit_notes),
    COALESCE(
        (SELECT u.id FROM users u WHERE u.branch_id = b.id AND u.role = 'front-desk' AND u.status = 'active' ORDER BY u.id LIMIT 1),
        (SELECT u.id FROM users u WHERE u.role = 'admin' AND u.status = 'active' ORDER BY u.id LIMIT 1)
    ),
    TIMESTAMP(t.service_date, t.scheduled_start)
FROM tmp_cross_branch_yearly_history_seed t
INNER JOIN vehicles v ON v.plate_number = t.plate_number
INNER JOIN branches b ON b.name = t.visit_branch_name
WHERE NOT EXISTS (
    SELECT 1
    FROM customer_visits cv
    WHERE cv.customer_id = v.customer_id
      AND cv.branch_id = b.id
      AND cv.visit_date = TIMESTAMP(t.service_date, t.scheduled_start)
      AND cv.visit_type = 'job_order'
);

INSERT INTO quotations
    (quotation_number, customer_id, vehicle_id, branch_id, quotation_date, labor_cost, parts_cost, tires_cost, tax_amount,
     total_amount, status, notes, created_by, valid_until, inspection_complaint, inspection_findings,
     inspection_recommendations, inspection_mileage, created_at, updated_at)
SELECT
    t.quotation_number,
    v.customer_id,
    v.id,
    b.id,
    t.service_date,
    t.first_price + t.second_price,
    COALESCE(pc.parts_cost, 0.00),
    COALESCE(pc.tires_cost, 0.00),
    0.00,
    t.first_price + t.second_price + COALESCE(pc.parts_cost, 0.00) + COALESCE(pc.tires_cost, 0.00),
    'approved',
    CONCAT('Approved yearly cross-branch service operation. ', t.visit_notes),
    COALESCE(
        (SELECT u.id FROM users u WHERE u.branch_id = b.id AND u.role = 'front-desk' AND u.status = 'active' ORDER BY u.id LIMIT 1),
        (SELECT u.id FROM users u WHERE u.role = 'admin' AND u.status = 'active' ORDER BY u.id LIMIT 1)
    ),
    DATE_ADD(t.service_date, INTERVAL 30 DAY),
    CONCAT('Customer requested service while visiting ', b.name, '.'),
    'Inspection completed; issued products are linked to the completed job order.',
    'Proceed with approved services and record completed cross-branch visit history.',
    t.mileage_at_service,
    TIMESTAMP(t.service_date, t.scheduled_start),
    TIMESTAMP(t.service_date, t.scheduled_end)
FROM tmp_cross_branch_yearly_history_seed t
INNER JOIN vehicles v ON v.plate_number = t.plate_number
INNER JOIN branches b ON b.name = t.visit_branch_name
LEFT JOIN (
    SELECT
        quotation_number,
        SUM(CASE WHEN category = 'tire' THEN quantity * unit_price ELSE 0 END) AS tires_cost,
        SUM(CASE WHEN category <> 'tire' THEN quantity * unit_price ELSE 0 END) AS parts_cost
    FROM tmp_cross_branch_product_candidates
    GROUP BY quotation_number
) pc ON pc.quotation_number = t.quotation_number
WHERE NOT EXISTS (
    SELECT 1 FROM quotations q WHERE q.quotation_number = t.quotation_number
);

INSERT INTO quotation_items
    (quotation_id, item_name, category, item_type, quantity, unit_price, source, notes, created_at)
SELECT
    q.id,
    t.first_service,
    t.first_category,
    'service',
    1,
    t.first_price,
    'own_inventory',
    'Yearly cross-branch service history seed.',
    TIMESTAMP(t.service_date, t.scheduled_start)
FROM tmp_cross_branch_yearly_history_seed t
INNER JOIN quotations q ON q.quotation_number = t.quotation_number
WHERE NOT EXISTS (
    SELECT 1
    FROM quotation_items qi
    WHERE qi.quotation_id = q.id
      AND qi.item_name = t.first_service
      AND qi.item_type = 'service'
)
UNION ALL
SELECT
    q.id,
    t.second_service,
    t.second_category,
    'service',
    1,
    t.second_price,
    'own_inventory',
    'Yearly cross-branch service history seed.',
    TIMESTAMP(t.service_date, t.scheduled_start)
FROM tmp_cross_branch_yearly_history_seed t
INNER JOIN quotations q ON q.quotation_number = t.quotation_number
WHERE NOT EXISTS (
    SELECT 1
    FROM quotation_items qi
    WHERE qi.quotation_id = q.id
      AND qi.item_name = t.second_service
      AND qi.item_type = 'service'
);

INSERT INTO quotation_items
    (quotation_id, item_name, category, item_type, quantity, unit_price, source, notes, created_at)
SELECT
    q.id,
    pc.item_name,
    pc.category,
    CASE WHEN pc.category = 'tire' THEN 'tire' ELSE 'part' END,
    pc.quantity,
    pc.unit_price,
    'own_inventory',
    CONCAT('{"inventory_item_id":', pc.item_id, '}'),
    TIMESTAMP(t.service_date, t.scheduled_start)
FROM tmp_cross_branch_product_candidates pc
INNER JOIN tmp_cross_branch_yearly_history_seed t ON t.quotation_number = pc.quotation_number
INNER JOIN quotations q ON q.quotation_number = pc.quotation_number
WHERE NOT EXISTS (
    SELECT 1
    FROM quotation_items qi
    WHERE qi.quotation_id = q.id
      AND qi.item_name = pc.item_name
      AND qi.item_type <> 'service'
);

INSERT INTO job_orders
    (job_number, customer_id, vehicle_id, assigned_technician_name, branch_id, quotation_id, job_date,
     scheduled_start_time, scheduled_end_time, estimated_duration, actual_start_time, actual_end_time,
     status, assigned_technician_id, notes, created_by, created_at, updated_at)
SELECT
    t.job_number,
    v.customer_id,
    v.id,
    t.technician_name,
    b.id,
    q.id,
    t.service_date,
    t.scheduled_start,
    t.scheduled_end,
    CONCAT(TIMESTAMPDIFF(MINUTE, TIMESTAMP(t.service_date, t.scheduled_start), TIMESTAMP(t.service_date, t.scheduled_end)), ' minutes'),
    TIMESTAMP(t.service_date, t.scheduled_start),
    TIMESTAMP(t.service_date, t.scheduled_end),
    'completed',
    NULL,
    CONCAT('Completed yearly cross-branch job order. ', t.visit_notes),
    COALESCE(
        (SELECT u.id FROM users u WHERE u.branch_id = b.id AND u.role = 'front-desk' AND u.status = 'active' ORDER BY u.id LIMIT 1),
        (SELECT u.id FROM users u WHERE u.role = 'admin' AND u.status = 'active' ORDER BY u.id LIMIT 1)
    ),
    TIMESTAMP(t.service_date, t.scheduled_start),
    TIMESTAMP(t.service_date, t.scheduled_end)
FROM tmp_cross_branch_yearly_history_seed t
INNER JOIN vehicles v ON v.plate_number = t.plate_number
INNER JOIN branches b ON b.name = t.visit_branch_name
INNER JOIN quotations q ON q.quotation_number = t.quotation_number
WHERE NOT EXISTS (
    SELECT 1 FROM job_orders jo WHERE jo.job_number = t.job_number
);

INSERT IGNORE INTO job_order_progress
    (job_order_id, quotation_item_id, task_key, task_name, task_type, quantity, is_done,
     completed_at, updated_by, created_at, updated_at)
SELECT
    jo.id,
    qi.id,
    CONCAT('seed-yearly-', qi.id),
    qi.item_name,
    qi.item_type,
    qi.quantity,
    1,
    TIMESTAMP(t.service_date, t.scheduled_end),
    COALESCE(
        (SELECT u.id FROM users u WHERE u.branch_id = b.id AND u.role = 'front-desk' AND u.status = 'active' ORDER BY u.id LIMIT 1),
        (SELECT u.id FROM users u WHERE u.role = 'admin' AND u.status = 'active' ORDER BY u.id LIMIT 1)
    ),
    TIMESTAMP(t.service_date, t.scheduled_start),
    TIMESTAMP(t.service_date, t.scheduled_end)
FROM tmp_cross_branch_yearly_history_seed t
INNER JOIN branches b ON b.name = t.visit_branch_name
INNER JOIN quotations q ON q.quotation_number = t.quotation_number
INNER JOIN job_orders jo ON jo.job_number = t.job_number
INNER JOIN quotation_items qi ON qi.quotation_id = q.id
WHERE qi.item_type = 'service';

DROP TEMPORARY TABLE IF EXISTS tmp_cross_branch_new_stock_issues;

CREATE TEMPORARY TABLE tmp_cross_branch_new_stock_issues AS
SELECT
    pc.item_id,
    pc.quantity,
    qi.id AS quotation_item_id,
    q.id AS quotation_id,
    jo.id AS job_order_id,
    v.customer_id,
    v.id AS vehicle_id,
    b.id AS branch_id,
    t.job_number,
    t.service_date,
    t.scheduled_end,
    COALESCE(
        (SELECT u.id FROM users u WHERE u.branch_id = b.id AND u.role = 'front-desk' AND u.status = 'active' ORDER BY u.id LIMIT 1),
        (SELECT u.id FROM users u WHERE u.role = 'admin' AND u.status = 'active' ORDER BY u.id LIMIT 1)
    ) AS created_by
FROM tmp_cross_branch_product_candidates pc
INNER JOIN tmp_cross_branch_yearly_history_seed t ON t.quotation_number = pc.quotation_number
INNER JOIN vehicles v ON v.plate_number = t.plate_number
INNER JOIN branches b ON b.name = t.visit_branch_name
INNER JOIN quotations q ON q.quotation_number = pc.quotation_number
INNER JOIN job_orders jo ON jo.job_number = t.job_number
INNER JOIN quotation_items qi ON qi.quotation_id = q.id
    AND qi.item_name = pc.item_name
    AND qi.item_type <> 'service'
WHERE NOT EXISTS (
    SELECT 1
    FROM inventory_transactions it
    WHERE it.item_id = pc.item_id
      AND it.transaction_type = 'stock_out'
      AND it.job_order_id = jo.id
      AND it.quotation_item_id = qi.id
);

INSERT INTO inventory_transactions
    (item_id, transaction_type, quantity, reference_type, reference_id, notes, created_by,
     customer_id, vehicle_id, job_order_id, quotation_id, quotation_item_id, created_at)
SELECT
    item_id,
    'stock_out',
    quantity,
    'job_order_item',
    quotation_item_id,
    CONCAT('Cross-branch yearly history seed stock issue for ', job_number),
    created_by,
    customer_id,
    vehicle_id,
    job_order_id,
    quotation_id,
    quotation_item_id,
    TIMESTAMP(service_date, scheduled_end)
FROM tmp_cross_branch_new_stock_issues;

UPDATE inventory_items i
INNER JOIN (
    SELECT item_id, SUM(quantity) AS issued_quantity
    FROM tmp_cross_branch_new_stock_issues
    GROUP BY item_id
) issued ON issued.item_id = i.id
SET i.quantity = GREATEST(i.quantity - issued.issued_quantity, 0);

INSERT INTO service_history
    (customer_id, vehicle_id, branch_id, service_date, services_description, total_cost, mileage_at_service,
     job_order_id, quotation_id, notes, created_at)
SELECT
    v.customer_id,
    v.id,
    b.id,
    t.service_date,
    CONCAT(t.first_service, '; ', t.second_service),
    q.total_amount,
    t.mileage_at_service,
    jo.id,
    q.id,
    CONCAT('Completed yearly cross-branch service history. ', t.visit_notes),
    TIMESTAMP(t.service_date, t.scheduled_end)
FROM tmp_cross_branch_yearly_history_seed t
INNER JOIN vehicles v ON v.plate_number = t.plate_number
INNER JOIN branches b ON b.name = t.visit_branch_name
INNER JOIN quotations q ON q.quotation_number = t.quotation_number
INNER JOIN job_orders jo ON jo.job_number = t.job_number
WHERE NOT EXISTS (
    SELECT 1
    FROM service_history sh
    WHERE sh.job_order_id = jo.id
       OR (sh.quotation_id = q.id AND sh.vehicle_id = v.id AND sh.service_date = t.service_date)
);

DROP TEMPORARY TABLE IF EXISTS tmp_cross_branch_new_stock_issues;
DROP TEMPORARY TABLE IF EXISTS tmp_cross_branch_product_candidates;
DROP TEMPORARY TABLE IF EXISTS tmp_cross_branch_yearly_history_seed;

COMMIT;

SELECT
    'Cross-branch yearly history seed completed. Import is clean, idempotent, and includes issued inventory items.' AS import_message,
    (SELECT COUNT(*) FROM quotations WHERE quotation_number LIKE 'QCBHY-%') AS seeded_service_operations,
    (SELECT COUNT(*) FROM job_orders WHERE job_number LIKE 'JOCBHY-%') AS seeded_job_orders,
    (SELECT COUNT(*) FROM inventory_transactions WHERE notes LIKE 'Cross-branch yearly history seed stock issue%') AS seeded_stock_outs;
