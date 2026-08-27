USE hwtires;

CREATE TABLE IF NOT EXISTS service_catalog (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(80) DEFAULT 'Service',
    price DECIMAL(10, 2) DEFAULT 0.00,
    labor_cost DECIMAL(10, 2) DEFAULT 0.00,
    estimated_duration VARCHAR(120) NULL,
    description TEXT NULL,
    is_variable_price TINYINT(1) DEFAULT 0,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_service_catalog_name (name),
    INDEX idx_service_catalog_status (status),
    INDEX idx_service_catalog_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE service_catalog ADD COLUMN labor_cost DECIMAL(10, 2) DEFAULT 0.00 AFTER price',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_catalog'
      AND COLUMN_NAME = 'labor_cost'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE service_catalog ADD COLUMN estimated_duration VARCHAR(120) NULL AFTER labor_cost',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_catalog'
      AND COLUMN_NAME = 'estimated_duration'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE service_catalog ADD COLUMN description TEXT NULL AFTER estimated_duration',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_catalog'
      AND COLUMN_NAME = 'description'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE service_catalog ADD COLUMN is_variable_price TINYINT(1) DEFAULT 0 AFTER description',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_catalog'
      AND COLUMN_NAME = 'is_variable_price'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE job_orders ADD COLUMN estimated_duration VARCHAR(120) NULL AFTER scheduled_end_time',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'job_orders'
      AND COLUMN_NAME = 'estimated_duration'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO service_catalog (
    name,
    category,
    price,
    labor_cost,
    estimated_duration,
    description,
    is_variable_price,
    status
) VALUES
('Computerized Four Wheel Alignment', 'Alignment', 1920.00, 1920.00, '1 hour full alignment; 30 minutes for 2-in/2-out adjustment', 'Full computerized four wheel alignment. 2-in/2-out adjustment starts at 480 per adjustment.', 1, 'active'),
('Wheel Balancing / Computerized Wheel Balancing', 'Tires', 700.00, 700.00, '2 hours for 4 wheels', 'Computerized wheel balancing for four wheels.', 0, 'active'),
('Tire Mounting and Rotation / Pneumatic Tire Mounting', 'Tires', 200.00, 200.00, '4 hours when combined with wheel balancing', 'Pneumatic tire mounting. Default labor is per tire.', 1, 'active'),
('Under Chassis and Suspension Repair', 'Suspension', 4500.00, 4500.00, 'Minor repair 1-2 hours; full suspension replacement up to 2 days', 'Price depends on issue and material availability.', 1, 'active'),
('Oil Change and Engine Tune-Up', 'Maintenance', 1000.00, 1000.00, '1 hour', 'Engine tune-up with oil change. Oil change only starts at 500.', 1, 'active'),
('Suspension Parts Installation', 'Suspension', 6250.00, 6250.00, 'Up to 2 days depending on kit', 'Labor estimate based on around one-fourth of a 25000 total kit/service package.', 1, 'active'),
('Nitrogen Air Tire Inflation', 'Tires', 100.00, 100.00, '5-10 minutes per tire', 'Nitrogen inflation, default price per tire.', 1, 'active'),
('Battery Check-Up and Fast Charging', 'Electrical', 100.00, 100.00, '30 minutes', 'Battery check-up and fast charging.', 0, 'active'),
('Automatic Transmission Flushing / ATF Changer Machine to Automatic Transmission', 'Transmission', 2000.00, 2000.00, '3 hours', 'ATF changer machine service for automatic transmission.', 0, 'active'),
('Brake Cleaning and Adjustment / Brake Repair', 'Brakes', 1600.00, 1600.00, '45 minutes for 4 wheels; repair time depends on parts availability', 'Brake cleaning is 800 front and 800 rear. Repair price may vary.', 1, 'active'),
('Manual Clutch Repair', 'Transmission', 6000.00, 6000.00, '1 day', 'Manual clutch repair labor estimate.', 1, 'active'),
('Big Bike / Car Tire Change', 'Tires', 200.00, 200.00, '30 minutes per tire', 'Default price per tire.', 1, 'active'),
('Car Sanitizing Service (BACKTOZERO)', 'Sanitizing', 1500.00, 1500.00, '10 minutes', 'BACKTOZERO car sanitizing service.', 0, 'active'),
('Auto Diagnostic Scanning / Auto Diagnostic Scanner', 'Diagnostics', 1500.00, 1500.00, '5 minutes', 'Diagnostic scanning using scanner tool.', 0, 'active'),
('Preventive Maintenance Check-Up', 'Maintenance', 2500.00, 2500.00, '1 hour 45 minutes', 'Package includes oil change plus additional check-up time.', 0, 'active'),
('Cold Patch Vulcanizing and Tire Repair', 'Tires', 270.00, 270.00, '15 minutes per tire', 'Cold patch vulcanizing and tire repair, default price per tire.', 1, 'active'),
('EGR Cleaning', 'Maintenance', 1200.00, 1200.00, '1 hour', 'EGR cleaning service.', 0, 'active'),
('Car Accessories Installation', 'Accessories', 500.00, 500.00, '30 minutes to 2 hours depending on accessory and parts availability', 'Labor may be free when the accessory is purchased from the company.', 1, 'active'),
('AC Refrigerant Charging', 'Air Conditioning', 1500.00, 1500.00, '20 minutes full charging', 'Full charging is 1500. Topping freon starts at 500.', 1, 'active'),
('Tire Rotation', 'Tires', 400.00, 400.00, '30-40 minutes for 4 wheels', 'Default total for 4 wheels; 100 per tire.', 1, 'active')
ON DUPLICATE KEY UPDATE
    name = name;
