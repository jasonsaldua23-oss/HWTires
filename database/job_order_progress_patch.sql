USE hwtires;

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
