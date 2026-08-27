-- Highway Tires Management
-- Clean append seed: cross-branch vehicle visit history
-- Purpose:
--   Adds completed historical visits where selected vehicles were serviced at a
--   branch other than their current/latest branch. The inserted dates are older
--   than the existing Aug 2026 latest activity, so "Last Visited Branch" remains
--   the most recent branch while the vehicle history shows prior branch visits.
--
-- Import in phpMyAdmin after the main customer records seed:
--   hwtires_customer_records_2023_aug16_2026_CLEANED.sql
--
-- This file is idempotent. It avoids cancelled job orders and uses only approved
-- service operations with completed job/service history.

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_cross_branch_vehicle_history_seed;

CREATE TEMPORARY TABLE tmp_cross_branch_vehicle_history_seed (
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
    mileage_at_service INT NOT NULL,
    visit_notes TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_cross_branch_vehicle_history_seed
    (plate_number, visit_branch_name, quotation_number, job_number, service_date, scheduled_start, scheduled_end,
     technician_name, first_service, first_category, first_price, second_service, second_category, second_price,
     mileage_at_service, visit_notes)
VALUES
    ('ECY-7092', 'Magsaysay Branch', 'QCBH-ECY7092-20260521', 'JOCBH-ECY7092-20260521', '2026-05-21', '09:10:00', '10:30:00',
     'Jerome C. Delos Santos', 'Service Inspection', 'Inspection', 450.00, 'Tire Rotation', 'Tires', 400.00,
     121920, 'Customer stopped by Magsaysay Branch for routine inspection before returning to Bata Branch.'),
    ('SSV-2153', 'Lacson Branch', 'QCBH-SSV2153-20260310', 'JOCBH-SSV2153-20260310', '2026-03-10', '10:00:00', '12:00:00',
     'Mark Anthony D. Villanueva', 'Underchassis Cleaning', 'Cleaning', 700.00, 'Wheel Alignment', 'Tires', 800.00,
     96720, 'Customer visited Lacson Branch while near the area; no parts were consumed.'),
    ('BXF-4400', 'Bata Branch', 'QCBH-BXF4400-20260418', 'JOCBH-BXF4400-20260418', '2026-04-18', '13:15:00', '14:25:00',
     'Rafael M. Gonzales', 'Service Inspection', 'Inspection', 450.00, 'Brake Cleaning', 'Brakes', 600.00,
     55240, 'Cross-branch brake check completed at Bata Branch.'),
    ('MES-9160', 'Bata Branch', 'QCBH-MES9160-20260214', 'JOCBH-MES9160-20260214', '2026-02-14', '08:30:00', '09:50:00',
     'Rafael M. Gonzales', 'Car Sanitizing Service (BACKTOZERO)', 'Sanitizing', 1500.00, 'Tire Rotation', 'Tires', 400.00,
     41480, 'Vehicle was serviced at Bata Branch during a scheduled customer visit.'),
    ('VWN-6444', 'Magsaysay Branch', 'QCBH-VWN6444-20260120', 'JOCBH-VWN6444-20260120', '2026-01-20', '14:00:00', '15:45:00',
     'Jerome C. Delos Santos', 'AC Refrigerant Charging', 'Air Conditioning', 1500.00, 'Service Inspection', 'Inspection', 450.00,
     37420, 'Air conditioning check handled at Magsaysay Branch before the vehicle returned to Lacson.'),
    ('MNE-7302', 'Magsaysay Branch', 'QCBH-MNE7302-20260612', 'JOCBH-MNE7302-20260612', '2026-06-12', '09:40:00', '11:20:00',
     'Jerome C. Delos Santos', 'EGR Cleaning', 'Maintenance', 1200.00, 'Tire Rotation', 'Tires', 400.00,
     139210, 'Customer availed maintenance service at Magsaysay Branch while traveling.'),
    ('UPP-3160', 'Lacson Branch', 'QCBH-UPP3160-20260509', 'JOCBH-UPP3160-20260509', '2026-05-09', '08:20:00', '10:30:00',
     'Mark Anthony D. Villanueva', 'Service Inspection', 'Inspection', 450.00, 'Preventive Maintenance Check-Up', 'Maintenance', 2500.00,
     125900, 'Preventive check completed at Lacson Branch; customer record remains linked to Magsaysay Branch.'),
    ('USB-9979', 'Bata Branch', 'QCBH-USB9979-20260328', 'JOCBH-USB9979-20260328', '2026-03-28', '13:00:00', '15:00:00',
     'Rafael M. Gonzales', 'Brake Cleaning and Adjustment / Brake Repair', 'Brakes', 1600.00, 'Tire Rotation', 'Tires', 400.00,
     100640, 'Brake service was completed at Bata Branch with no inventory parts issued.'),
    ('BSN-5091', 'Magsaysay Branch', 'QCBH-BSN5091-20260517', 'JOCBH-BSN5091-20260517', '2026-05-17', '10:10:00', '12:30:00',
     'Jerome C. Delos Santos', 'Under Chassis and Suspension Repair', 'Suspension', 4500.00, 'Service Inspection', 'Inspection', 450.00,
     109880, 'Suspension concern was inspected and repaired at Magsaysay Branch.'),
    ('JMS-9530', 'Bata Branch', 'QCBH-JMS9530-20260404', 'JOCBH-JMS9530-20260404', '2026-04-04', '09:30:00', '11:00:00',
     'Rafael M. Gonzales', 'Computerized Four Wheel Alignment', 'Alignment', 1920.00, 'Tire Rotation', 'Tires', 400.00,
     26920, 'Alignment service was completed at Bata Branch before the latest Lacson visit.'),
    ('FHD-8554', 'Lacson Branch', 'QCBH-FHD8554-20260222', 'JOCBH-FHD8554-20260222', '2026-02-22', '15:00:00', '16:30:00',
     'Mark Anthony D. Villanueva', 'AC Refrigerant Charging', 'Air Conditioning', 1500.00, 'Service Inspection', 'Inspection', 450.00,
     118760, 'Customer visited Lacson Branch for air conditioning service.'),
    ('BKG-5254', 'Magsaysay Branch', 'QCBH-BKG5254-20260130', 'JOCBH-BKG5254-20260130', '2026-01-30', '08:45:00', '10:20:00',
     'Jerome C. Delos Santos', 'Brake Pad Replacement', 'Brakes', 1200.00, 'Brake Cleaning', 'Brakes', 600.00,
     107940, 'Brake service history added for a prior Magsaysay Branch visit.'),
    ('RYU-5273', 'Lacson Branch', 'QCBH-RYU5273-20260306', 'JOCBH-RYU5273-20260306', '2026-03-06', '11:10:00', '12:25:00',
     'Mark Anthony D. Villanueva', 'Change Oil', 'Maintenance', 800.00, 'Service Inspection', 'Inspection', 450.00,
     58640, 'Routine service completed at Lacson Branch before the latest Bata Branch activity.');

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
FROM tmp_cross_branch_vehicle_history_seed t
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
    CONCAT('Cross-branch vehicle visit for ', v.plate_number, '. ', t.visit_notes),
    COALESCE(
        (SELECT u.id FROM users u WHERE u.branch_id = b.id AND u.role = 'front-desk' AND u.status = 'active' ORDER BY u.id LIMIT 1),
        (SELECT u.id FROM users u WHERE u.role = 'admin' AND u.status = 'active' ORDER BY u.id LIMIT 1)
    ),
    TIMESTAMP(t.service_date, t.scheduled_start)
FROM tmp_cross_branch_vehicle_history_seed t
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
    0.00,
    0.00,
    0.00,
    t.first_price + t.second_price,
    'approved',
    CONCAT('Approved cross-branch service operation. ', t.visit_notes),
    COALESCE(
        (SELECT u.id FROM users u WHERE u.branch_id = b.id AND u.role = 'front-desk' AND u.status = 'active' ORDER BY u.id LIMIT 1),
        (SELECT u.id FROM users u WHERE u.role = 'admin' AND u.status = 'active' ORDER BY u.id LIMIT 1)
    ),
    DATE_ADD(t.service_date, INTERVAL 30 DAY),
    CONCAT('Customer requested service while visiting ', b.name, '.'),
    'Routine inspection found serviceable items listed in this operation.',
    'Proceed with approved services and record completed visit history.',
    t.mileage_at_service,
    TIMESTAMP(t.service_date, t.scheduled_start),
    TIMESTAMP(t.service_date, t.scheduled_end)
FROM tmp_cross_branch_vehicle_history_seed t
INNER JOIN vehicles v ON v.plate_number = t.plate_number
INNER JOIN branches b ON b.name = t.visit_branch_name
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
    'Cross-branch service history seed.',
    TIMESTAMP(t.service_date, t.scheduled_start)
FROM tmp_cross_branch_vehicle_history_seed t
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
    'Cross-branch service history seed.',
    TIMESTAMP(t.service_date, t.scheduled_start)
FROM tmp_cross_branch_vehicle_history_seed t
INNER JOIN quotations q ON q.quotation_number = t.quotation_number
WHERE NOT EXISTS (
    SELECT 1
    FROM quotation_items qi
    WHERE qi.quotation_id = q.id
      AND qi.item_name = t.second_service
      AND qi.item_type = 'service'
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
    CONCAT('Completed cross-branch job order. ', t.visit_notes),
    COALESCE(
        (SELECT u.id FROM users u WHERE u.branch_id = b.id AND u.role = 'front-desk' AND u.status = 'active' ORDER BY u.id LIMIT 1),
        (SELECT u.id FROM users u WHERE u.role = 'admin' AND u.status = 'active' ORDER BY u.id LIMIT 1)
    ),
    TIMESTAMP(t.service_date, t.scheduled_start),
    TIMESTAMP(t.service_date, t.scheduled_end)
FROM tmp_cross_branch_vehicle_history_seed t
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
    CONCAT('seed-', qi.id),
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
FROM tmp_cross_branch_vehicle_history_seed t
INNER JOIN branches b ON b.name = t.visit_branch_name
INNER JOIN quotations q ON q.quotation_number = t.quotation_number
INNER JOIN job_orders jo ON jo.job_number = t.job_number
INNER JOIN quotation_items qi ON qi.quotation_id = q.id
WHERE qi.item_type = 'service';

INSERT INTO service_history
    (customer_id, vehicle_id, branch_id, service_date, services_description, total_cost, mileage_at_service,
     job_order_id, quotation_id, notes, created_at)
SELECT
    v.customer_id,
    v.id,
    b.id,
    t.service_date,
    CONCAT(t.first_service, '; ', t.second_service),
    t.first_price + t.second_price,
    t.mileage_at_service,
    jo.id,
    q.id,
    CONCAT('Cross-branch service history. ', t.visit_notes),
    TIMESTAMP(t.service_date, t.scheduled_end)
FROM tmp_cross_branch_vehicle_history_seed t
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

DROP TEMPORARY TABLE IF EXISTS tmp_cross_branch_vehicle_history_seed;

COMMIT;

SELECT 'Cross-branch vehicle history seed completed. Import is clean and idempotent.' AS import_message;
