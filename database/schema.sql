-- Highway Tires Management System - Database Schema
-- Created: 2026-05-02

-- ===================================
-- 1. BRANCHES TABLE
-- ===================================
CREATE TABLE IF NOT EXISTS branches (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(255),
    branch_supervisor VARCHAR(150),
    contact_number VARCHAR(20),
    email VARCHAR(100),
    manager_id INT,
    has_inventory BOOLEAN DEFAULT TRUE,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================
-- 2. USERS TABLE
-- ===================================
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'front-desk') DEFAULT 'front-desk',
    branch_id INT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    password_changed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update branch manager_id foreign key after users table is created
ALTER TABLE branches ADD CONSTRAINT fk_branch_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL;

-- ===================================
-- 3. CUSTOMERS TABLE
-- ===================================
CREATE TABLE IF NOT EXISTS customers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    contact VARCHAR(20),
    email VARCHAR(100),
    address VARCHAR(255),
    city VARCHAR(100),
    phone_mobile VARCHAR(20),
    phone_work VARCHAR(20),
    customer_type ENUM('individual', 'corporate') DEFAULT 'individual',
    branch_id INT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    archived_at DATETIME NULL,
    archived_by INT NULL,
    archive_reason TEXT NULL,
    restored_at DATETIME NULL,
    restored_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_email (email),
    INDEX idx_phone (phone_mobile),
    INDEX idx_status (status),
    INDEX idx_branch (branch_id),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_branch_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    branch_id INT NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    archived_at DATETIME NULL,
    archived_by INT NULL,
    archive_reason TEXT NULL,
    restored_at DATETIME NULL,
    restored_by INT NULL,
    sales_in_charge VARCHAR(100) COMMENT 'Sales representative assigned to this customer at branch',
    sales_branch_label VARCHAR(100) COMMENT 'Branch label for sales tracking and reporting',
    last_visit_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_customer_branch (customer_id, branch_id),
    INDEX idx_customer_branch_records_customer (customer_id),
    INDEX idx_customer_branch_records_branch (branch_id),
    INDEX idx_customer_branch_records_status (status),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================
-- 4. VEHICLES TABLE
-- ===================================
CREATE TABLE IF NOT EXISTS vehicles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    branch_id INT,
    plate_number VARCHAR(50) UNIQUE,
    vin VARCHAR(50),
    `condition` ENUM('excellent', 'good', 'fair', 'poor') DEFAULT 'good',
    make VARCHAR(50),
    model VARCHAR(50),
    year INT,
    color VARCHAR(50),
    last_service_date DATE,
    last_mileage INT,
    created_by_user_id INT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    archived_at DATETIME NULL,
    archived_by INT NULL,
    archive_reason TEXT NULL,
    restored_at DATETIME NULL,
    restored_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_customer (customer_id),
    INDEX idx_branch (branch_id),
    INDEX idx_plate (plate_number),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicle_ownership_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    vehicle_id INT NOT NULL,
    customer_id INT NOT NULL,
    owned_from DATE NULL,
    owned_until DATE NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    transfer_notes TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_vehicle_ownership_vehicle (vehicle_id),
    INDEX idx_vehicle_ownership_customer (customer_id),
    INDEX idx_vehicle_ownership_current (vehicle_id, is_current),
    INDEX idx_vehicle_ownership_dates (owned_from, owned_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================
-- 5. TECHNICIANS TABLE
-- ===================================
CREATE TABLE IF NOT EXISTS technicians (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    branch_id INT NOT NULL,
    join_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    INDEX idx_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================
-- 5B. SERVICE CATALOG TABLE
-- ===================================
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

-- ===================================
-- 6. QUOTATIONS TABLE
-- ===================================
CREATE TABLE IF NOT EXISTS quotations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    quotation_number VARCHAR(50) UNIQUE,
    customer_id INT NOT NULL,
    vehicle_id INT,
    branch_id INT NOT NULL,
    quotation_date DATE NOT NULL,
    labor_cost DECIMAL(10, 2) DEFAULT 0,
    parts_cost DECIMAL(10, 2) DEFAULT 0,
    tires_cost DECIMAL(10, 2) DEFAULT 0,
    tax_amount DECIMAL(10, 2) DEFAULT 0,
    total_amount DECIMAL(10, 2) DEFAULT 0,
    status ENUM('pending', 'approved', 'rejected', 'archived') DEFAULT 'pending',
    archived_at DATETIME NULL,
    archived_by INT NULL,
    archive_reason TEXT NULL,
    restored_at DATETIME NULL,
    restored_by INT NULL,
    notes TEXT,
    valid_until DATE,
    inspection_complaint TEXT,
    inspection_findings TEXT,
    inspection_recommendations TEXT,
    inspection_mileage INT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_customer (customer_id),
    INDEX idx_vehicle (vehicle_id),
    INDEX idx_branch (branch_id),
    INDEX idx_status (status),
    INDEX idx_date (quotation_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================
-- 7. QUOTATION ITEMS TABLE
-- ===================================
CREATE TABLE IF NOT EXISTS quotation_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    quotation_id INT NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    category VARCHAR(50),
    item_type ENUM('service', 'part', 'tire') NOT NULL,
    quantity INT DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL,
    subtotal DECIMAL(10, 2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
    source ENUM('own_inventory', 'other_branch', 'external', 'customer_supplied') DEFAULT 'own_inventory',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
    INDEX idx_quotation (quotation_id),
    INDEX idx_type (item_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================
-- 8. JOB ORDERS TABLE
-- ===================================
CREATE TABLE IF NOT EXISTS job_orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    job_number VARCHAR(50) UNIQUE,
    customer_id INT NOT NULL,
    vehicle_id INT,
    assigned_technician_name VARCHAR(255) NULL,
    branch_id INT NOT NULL,
    quotation_id INT,
    job_date DATE NOT NULL,
    scheduled_start_time TIME,
    scheduled_end_time TIME,
    scheduled_end_date DATE NULL,
    estimated_duration VARCHAR(120) NULL,
    actual_start_time DATETIME,
    actual_end_time DATETIME,
    status ENUM('waiting', 'pending', 'in-progress', 'completed', 'cancelled', 'archived') DEFAULT 'waiting',
    archived_at DATETIME NULL,
    archived_by INT NULL,
    archive_reason TEXT NULL,
    restored_at DATETIME NULL,
    restored_by INT NULL,
    assigned_technician_id INT,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_technician_id) REFERENCES technicians(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_customer (customer_id),
    INDEX idx_vehicle (vehicle_id),
    INDEX idx_branch (branch_id),
    INDEX idx_status (status),
    INDEX idx_date (job_date),
    INDEX idx_technician (assigned_technician_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================
-- 8A. JOB ORDER PROGRESS TABLE
-- ===================================
CREATE TABLE IF NOT EXISTS job_order_progress (
    id INT PRIMARY KEY AUTO_INCREMENT,
    job_order_id INT NOT NULL,
    quotation_item_id INT NULL,
    task_key VARCHAR(120) NOT NULL,
    task_name VARCHAR(255) NOT NULL,
    task_type VARCHAR(30) DEFAULT 'service',
    quantity INT DEFAULT 1,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    completed_at DATETIME NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (job_order_id) REFERENCES job_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (quotation_item_id) REFERENCES quotation_items(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_job_progress_task (job_order_id, task_key),
    INDEX idx_job_progress_job (job_order_id),
    INDEX idx_job_progress_done (is_done)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================
-- 9. SERVICE HISTORY TABLE
-- ===================================
CREATE TABLE IF NOT EXISTS service_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    vehicle_id INT,
    branch_id INT NOT NULL,
    service_date DATE NOT NULL,
    services_description TEXT,
    total_cost DECIMAL(10, 2),
    mileage_at_service INT,
    job_order_id INT,
    quotation_id INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (job_order_id) REFERENCES job_orders(id) ON DELETE SET NULL,
    FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE SET NULL,
    INDEX idx_customer (customer_id),
    INDEX idx_vehicle (vehicle_id),
    INDEX idx_branch (branch_id),
    INDEX idx_date (service_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================
-- 10. INVENTORY ITEMS TABLE
-- ===================================
CREATE TABLE IF NOT EXISTS inventory_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    branch_id INT NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    category ENUM('tire', 'accessory', 'part') NOT NULL,
    brand VARCHAR(100),
    model VARCHAR(100),
    size VARCHAR(50),
    description TEXT,
    sku VARCHAR(100),
    serial_number VARCHAR(120),
    manufacturing_date DATE,
    quantity INT DEFAULT 0,
    reorder_level INT DEFAULT 10,
    unit_price DECIMAL(10, 2),
    supplier_name VARCHAR(100),
    supplier_contact VARCHAR(20),
    status ENUM('active', 'inactive', 'discontinued') DEFAULT 'active',
    archived_at DATETIME NULL,
    archived_by INT NULL,
    archive_reason TEXT NULL,
    restored_at DATETIME NULL,
    restored_by INT NULL,
    last_restock_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    INDEX idx_branch_category (branch_id, category),
    INDEX idx_status (status),
    INDEX idx_quantity (quantity),
    INDEX idx_sku (sku),
    INDEX idx_serial_number (serial_number),
    INDEX idx_manufacturing_date (manufacturing_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================
-- 11. INVENTORY TRANSACTIONS TABLE
-- ===================================
CREATE TABLE IF NOT EXISTS inventory_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_id INT NOT NULL,
    transaction_type ENUM('stock_in', 'stock_out', 'adjustment', 'damage') NOT NULL,
    quantity INT NOT NULL,
    reference_type VARCHAR(50),
    reference_id INT,
    notes TEXT,
    created_by INT,
    customer_id INT NULL,
    vehicle_id INT NULL,
    job_order_id INT NULL,
    quotation_id INT NULL,
    quotation_item_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
    FOREIGN KEY (job_order_id) REFERENCES job_orders(id) ON DELETE SET NULL,
    FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE SET NULL,
    FOREIGN KEY (quotation_item_id) REFERENCES quotation_items(id) ON DELETE SET NULL,
    INDEX idx_item (item_id),
    INDEX idx_type (transaction_type),
    INDEX idx_inv_tx_customer (customer_id),
    INDEX idx_inv_tx_vehicle (vehicle_id),
    INDEX idx_inv_tx_job (job_order_id),
    INDEX idx_inv_tx_quote (quotation_id),
    INDEX idx_inv_tx_quote_item (quotation_item_id),
    INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================
-- 12. CUSTOMER VISITS TABLE
-- ===================================
CREATE TABLE IF NOT EXISTS customer_visits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    branch_id INT NOT NULL,
    visit_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    visit_type ENUM('quotation', 'job_order', 'followup', 'general') DEFAULT 'general',
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_customer (customer_id),
    INDEX idx_branch (branch_id),
    INDEX idx_date (visit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================
-- 13. SMS OUTBOX TABLE
-- ===================================
CREATE TABLE IF NOT EXISTS sms_outbox (
    id INT PRIMARY KEY AUTO_INCREMENT,
    job_order_id INT NOT NULL,
    customer_id INT NOT NULL,
    vehicle_id INT NULL,
    branch_id INT NOT NULL,
    recipient_name VARCHAR(150) NOT NULL,
    recipient_phone VARCHAR(40) NULL,
    message_body TEXT NOT NULL,
    status ENUM('queued', 'sent', 'failed', 'cancelled') DEFAULT 'queued',
    provider VARCHAR(50) NULL,
    provider_message_id VARCHAR(120) NULL,
    provider_response TEXT NULL,
    sent_at DATETIME NULL,
    attempts INT DEFAULT 0,
    error_message TEXT NULL,
    queued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_sms_outbox_job_order (job_order_id),
    FOREIGN KEY (job_order_id) REFERENCES job_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_sms_outbox_customer (customer_id),
    INDEX idx_sms_outbox_branch (branch_id),
    INDEX idx_sms_outbox_status (status),
    INDEX idx_sms_outbox_queued_at (queued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================
-- 14. AUDIT LOG TABLE
-- ===================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(255),
    table_name VARCHAR(100),
    record_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_table (table_name),
    INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================
-- 15. SYSTEM SETTINGS TABLE
-- ===================================
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================
-- SCHEMA UPDATES (For existing databases)
-- ===================================
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS created_by_user_id INT;
ALTER TABLE vehicles ADD CONSTRAINT IF NOT EXISTS fk_vehicle_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

-- ===================================
-- SAMPLE DATA
-- ===================================

-- Insert branches
INSERT INTO branches (name, location, contact_number, email, has_inventory, status) VALUES
('Branch 1 - Downtown', 'Downtown Service Center', '555-0101', 'branch1@hwtires.local', TRUE, 'active'),
('Branch 2 - North', 'North Distribution Hub', '555-0102', 'branch2@hwtires.local', TRUE, 'active'),
('Branch 3 - South', 'South Retail Station', '555-0103', 'branch3@hwtires.local', TRUE, 'active');

-- Insert default system settings
INSERT INTO system_settings (setting_key, setting_value) VALUES
('company_name', 'Highway Tires'),
('system_title', 'Branch Data Management System'),
('contact_email', 'info@highwaytires.com'),
('contact_phone', '(02) 8123-4567'),
('company_logo', 'assets/images/logo.png'),
('primary_color', '#06B6D4'),
('email_notifications', '1'),
('low_stock_alerts', '1'),
('auto_job_ids', '1');

-- Insert default service catalog used by Service Operations
INSERT INTO service_catalog (name, category, price, labor_cost, estimated_duration, description, is_variable_price, status) VALUES
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

-- Insert admin user
INSERT INTO users (name, email, password_hash, role, branch_id, status) VALUES
('Admin Owner', 'admin@hwtires.local', '$2y$10$bCyq0YVbuBAVrSIzsVGWIONLmTEPY1X6TXuJhoaZviIGcnXIBzZPi', 'admin', NULL, 'active');

-- Password: admin123 (hashed with password_hash('admin123', PASSWORD_BCRYPT))

-- Insert front desk users
INSERT INTO users (name, email, password_hash, role, branch_id, status) VALUES
('Front Desk B1', 'frontdesk1@hwtires.local', '$2y$10$cwISJQLp9JpFtnHPPw7oP.DLLGNRKgPaAr9dkqavy9p5TuQ/mK0Wy', 'front-desk', 1, 'active'),
('Front Desk B2', 'frontdesk2@hwtires.local', '$2y$10$cwISJQLp9JpFtnHPPw7oP.DLLGNRKgPaAr9dkqavy9p5TuQ/mK0Wy', 'front-desk', 2, 'active'),
('Front Desk B3', 'frontdesk3@hwtires.local', '$2y$10$cwISJQLp9JpFtnHPPw7oP.DLLGNRKgPaAr9dkqavy9p5TuQ/mK0Wy', 'front-desk', 3, 'active');

-- Passwords: password123 (hashed for all)

-- Insert sample customers
INSERT INTO customers (name, contact, email, address, city, phone_mobile, customer_type, branch_id, status) VALUES
('John Smith', '555-1001', 'john.smith@email.com', '123 Main Street', 'Downtown', '555-9001', 'individual', 1, 'active'),
('Sarah Johnson', '555-1002', 'sarah.j@email.com', '456 Oak Avenue', 'North', '555-9002', 'individual', 2, 'active'),
('ABC Corporate', '555-1003', 'fleet@abccorp.com', '789 Business Park', 'Downtown', '555-9003', 'corporate', 2, 'active'),
('Maria Garcia', '555-1004', 'maria.garcia@email.com', '321 Pine Road', 'South', '555-9004', 'individual', 3, 'active'),
('Tech Solutions Inc', '555-1005', 'logistics@techsol.com', '654 Industrial Way', 'North', '555-9005', 'corporate', 3, 'active');

-- Insert customer branch ownership records
INSERT INTO customer_branch_records (customer_id, branch_id, status, last_visit_at, created_by) VALUES
(1, 1, 'active', '2026-04-25 09:00:00', 2),
(2, 2, 'active', '2026-04-24 10:30:00', 3),
(3, 2, 'active', '2026-04-20 08:45:00', 3),
(4, 3, 'active', '2026-04-23 14:00:00', 4),
(5, 3, 'active', '2026-04-18 11:15:00', 4);

-- Insert sample customer visits
INSERT INTO customer_visits (customer_id, branch_id, visit_date, visit_type, notes, created_by) VALUES
(1, 1, '2026-04-25 09:00:00', 'quotation', 'Initial quotation visit', 2),
(2, 2, '2026-04-24 10:30:00', 'general', 'Customer inquiry', 3),
(3, 2, '2026-04-20 08:45:00', 'quotation', 'Fleet quotation request', 3),
(4, 3, '2026-04-23 14:00:00', 'general', 'Vehicle inspection', 4),
(5, 3, '2026-04-18 11:15:00', 'general', 'Maintenance inquiry', 4);

-- Insert sample vehicles
INSERT INTO vehicles (customer_id, branch_id, plate_number, vin, make, model, year, color, last_service_date, last_mileage, status) VALUES
(1, 1, 'ABC123', 'VIN001', 'Toyota', 'Camry', 2020, 'Silver', '2026-04-15', 45000, 'active'),
(1, 1, 'ABC124', 'VIN002', 'Honda', 'Civic', 2021, 'Blue', '2026-03-20', 32000, 'active'),
(2, 2, 'XYZ789', 'VIN003', 'Ford', 'Focus', 2019, 'Black', '2026-04-01', 67000, 'active'),
(3, 2, 'DEF456', 'VIN004', 'Chevrolet', 'Silverado', 2022, 'White', '2026-04-10', 15000, 'active'),
(3, 2, 'DEF457', 'VIN005', 'Chevrolet', 'Silverado', 2022, 'White', '2026-04-10', 15000, 'active'),
(4, 3, 'GHI999', 'VIN006', 'Nissan', 'Altima', 2023, 'Red', '2026-03-15', 8000, 'active'),
(5, 3, 'JKL321', 'VIN007', 'BMW', '3 Series', 2021, 'Gray', '2026-04-05', 42000, 'active');

-- Insert sample technicians
INSERT INTO technicians (name, branch_id, join_date) VALUES
('Mike Johnson', 1, '2020-01-15'),
('David Brown', 1, '2020-06-20'),
('Carlos Lopez', 2, '2019-03-10'),
('Robert Wilson', 2, '2021-05-01'),
('James Martinez', 3, '2020-11-15'),
('Tom Anderson', 3, '2018-02-20');

-- Insert sample inventory items for Branch 2 & 3
INSERT INTO inventory_items (branch_id, item_name, category, brand, size, description, sku, quantity, reorder_level, unit_price, status) VALUES
-- Branch 2 - Tires
(2, 'All-Season Radial Tire 205/60R16', 'tire', 'Michelin', '205/60R16', 'All-season radial tire', 'TIRE-001', 45, 15, 120.00, 'active'),
(2, 'Performance Radial Tire 225/45R17', 'tire', 'Goodyear', '225/45R17', 'Performance tire', 'TIRE-002', 8, 10, 150.00, 'active'),
(2, 'Winter Tire 235/50R18', 'tire', 'Bridgestone', '235/50R18', 'Winter tire', 'TIRE-003', 3, 10, 180.00, 'active'),
-- Branch 2 - Accessories and Parts
(2, 'Wiper Blade Pair', 'accessory', 'Bosch', 'Universal', 'Wiper blade pair', 'WIPERS-001', 25, 10, 35.00, 'active'),
(2, 'Brake Pad Set', 'part', 'OEM', 'Standard', 'Front brake pad set', 'BRAKE-001', 12, 5, 85.00, 'active'),
(2, 'Battery 60Ah', 'part', 'Exell', '12V', 'Car battery 60Ah', 'BATT-001', 5, 5, 120.00, 'active'),
(2, 'Oil Filter', 'part', 'Fram', 'Standard', 'Engine oil filter', 'OIL-FILTER-001', 50, 20, 12.00, 'active'),
(2, 'Cabin Air Filter', 'part', 'OEM', 'Standard', 'Cabin air filter', 'CAB-FILTER-001', 18, 10, 25.00, 'active'),
-- Branch 3 - Tires
(3, 'All-Season Radial Tire 205/65R15', 'tire', 'Pirelli', '205/65R15', 'All-season tire', 'TIRE-004', 60, 15, 110.00, 'active'),
(3, 'SUV Tire 265/70R16', 'tire', 'Continental', '265/70R16', 'SUV tire', 'TIRE-005', 20, 10, 140.00, 'active'),
-- Branch 3 - Parts
(3, 'Wiper Blade Pair', 'accessory', 'Valeo', 'Universal', 'Premium wiper blades', 'WIPERS-002', 40, 15, 45.00, 'active'),
(3, 'Brake Pad Set', 'part', 'Brembo', 'Premium', 'Ceramic brake pad set', 'BRAKE-002', 8, 5, 110.00, 'active'),
(3, 'Air Filter', 'part', 'Mann', 'Standard', 'Engine air filter', 'AIR-FILTER-001', 35, 15, 18.00, 'active');

-- Insert sample quotation
INSERT INTO quotations (quotation_number, customer_id, vehicle_id, branch_id, quotation_date, labor_cost, parts_cost, tires_cost, tax_amount, total_amount, status, notes, created_by, valid_until) VALUES
('QT-2026-001', 1, 1, 1, '2026-04-25', 150.00, 85.00, 240.00, 91.50, 566.50, 'approved', 'Customer wants tire and oil change', 2, '2026-05-31'),
('QT-2026-002', 3, 4, 2, '2026-04-20', 200.00, 0, 120.00, 64.00, 384.00, 'approved', 'Fleet vehicle tire replacement', 3, '2026-05-20');

-- Insert quotation items
INSERT INTO quotation_items (quotation_id, item_name, category, item_type, quantity, unit_price, source) VALUES
(1, 'Oil Change Service', 'service', 'service', 1, 50.00, 'own_inventory'),
(1, 'Oil Filter', 'part', 'part', 1, 12.00, 'own_inventory'),
(1, 'All-Season Radial Tire 205/60R16', 'tire', 'tire', 2, 120.00, 'own_inventory'),
(2, 'Tire Replacement Service', 'service', 'service', 2, 100.00, 'own_inventory'),
(2, 'All-Season Radial Tire 205/65R15', 'tire', 'tire', 2, 60.00, 'other_branch');

-- Insert sample inventory consumption for forecasting
INSERT INTO inventory_transactions (item_id, transaction_type, quantity, reference_type, reference_id, notes, created_by, created_at) VALUES
(2, 'stock_out', 6, 'job_order', NULL, 'Historical tire consumption', 3, '2026-04-12 10:00:00'),
(2, 'stock_out', 5, 'job_order', NULL, 'Historical tire consumption', 3, '2026-04-20 15:30:00'),
(3, 'stock_out', 4, 'job_order', NULL, 'Historical tire consumption', 3, '2026-04-16 11:10:00'),
(6, 'stock_out', 3, 'job_order', NULL, 'Battery replacement usage', 3, '2026-04-19 13:20:00'),
(11, 'stock_out', 8, 'job_order', NULL, 'Wiper blade usage', 4, '2026-04-13 09:45:00'),
(12, 'stock_out', 4, 'job_order', NULL, 'Brake pad usage', 4, '2026-04-22 16:00:00'),
(13, 'stock_out', 5, 'job_order', NULL, 'Air filter usage', 4, '2026-04-25 10:25:00');

-- Insert sample job orders
INSERT INTO job_orders (job_number, customer_id, vehicle_id, assigned_technician_name, branch_id, quotation_id, job_date, scheduled_start_time, scheduled_end_time, status, assigned_technician_id, notes, created_by, created_at) VALUES
('JO-20260425-0001', 1, 1, 'Mike Johnson', 1, 1, '2026-04-25', '09:00:00', '11:00:00', 'in-progress', 1, 'Customer waiting', 2, '2026-04-25 08:45:00'),
('JO-20260420-0001', 3, 4, 'Carlos Lopez', 2, 2, '2026-04-20', '10:30:00', '13:00:00', 'waiting', 3, 'Waiting for tire delivery', 3, '2026-04-20 10:00:00'),
('JO-20260423-0001', 4, 6, 'James Martinez', 3, NULL, '2026-04-23', '14:00:00', '16:00:00', 'completed', 5, 'Completed successfully', 4, '2026-04-23 13:30:00');

-- Insert sample completed service history
INSERT INTO service_history (customer_id, vehicle_id, branch_id, service_date, services_description, total_cost, mileage_at_service, job_order_id, quotation_id, notes) VALUES
(4, 6, 3, '2026-04-23', 'Battery Replacement, Preventive Maintenance Service', 5500.00, 8000, 3, NULL, 'Completed successfully'),
(1, 1, 1, '2026-04-15', 'Change Oil, Oil Filter Replacement', 1500.00, 45000, NULL, NULL, 'Regular maintenance'),
(3, 4, 2, '2026-04-10', 'Tire Replacement, Wheel Balancing', 14400.00, 15000, NULL, 2, 'Fleet service');

-- ===================================
-- 16. INTER-BRANCH TRANSFER REQUESTS TABLE
-- ===================================
CREATE TABLE IF NOT EXISTS inter_branch_transfer_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    request_number VARCHAR(50) UNIQUE,
    requesting_branch_id INT NOT NULL,
    donor_branch_id INT NOT NULL,
    item_id INT NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    requested_quantity INT NOT NULL,
    approved_quantity INT DEFAULT 0,
    reason VARCHAR(255) COMMENT 'e.g., "Customer order", "Low stock"',
    priority ENUM('high', 'medium', 'low') DEFAULT 'medium',
    quotation_id INT NULL,
    customer_id INT NULL,
    status ENUM('pending', 'approved', 'shipped', 'received', 'cancelled') DEFAULT 'pending',
    shipping_date DATETIME NULL,
    received_date DATETIME NULL,
    notes TEXT,
    requested_by INT,
    approved_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (requesting_branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (donor_branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
    FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE SET NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_requesting_branch (requesting_branch_id),
    INDEX idx_donor_branch (donor_branch_id),
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================
-- 17. TRANSFER NOTIFICATIONS TABLE
-- ===================================
CREATE TABLE IF NOT EXISTS transfer_notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    branch_id INT NOT NULL,
    user_id INT NULL,
    transfer_request_id INT,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'warning', 'success', 'error') DEFAULT 'info',
    action_url VARCHAR(255),
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (transfer_request_id) REFERENCES inter_branch_transfer_requests(id) ON DELETE CASCADE,
    INDEX idx_branch (branch_id),
    INDEX idx_user (user_id),
    INDEX idx_is_read (is_read),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================
-- 18. LOGIN ATTEMPTS TABLE (Rate Limiting)
-- ===================================
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    email VARCHAR(100) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_lockout (email, ip_address, attempted_at),
    INDEX idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create indexes for performance
CREATE INDEX idx_quotations_status ON quotations(status);
CREATE INDEX idx_quotations_branch ON quotations(branch_id);
CREATE INDEX idx_job_orders_status ON job_orders(status);
CREATE INDEX idx_inventory_items_quantity ON inventory_items(quantity);

