-- ===================================
-- INTER-BRANCH TRANSFER REQUESTS TABLE
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
-- TRANSFER NOTIFICATIONS TABLE
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
