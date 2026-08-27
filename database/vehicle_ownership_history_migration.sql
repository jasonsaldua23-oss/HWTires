USE hwtires;

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

UPDATE vehicle_ownership_history h
INNER JOIN vehicles v ON v.id = h.vehicle_id
SET h.is_current = 0,
    h.owned_until = COALESCE(h.owned_until, DATE(COALESCE(v.updated_at, NOW()))),
    h.updated_at = NOW()
WHERE h.is_current = 1
  AND v.customer_id IS NOT NULL
  AND h.customer_id <> v.customer_id;

INSERT INTO vehicle_ownership_history (
    vehicle_id,
    customer_id,
    owned_from,
    owned_until,
    is_current,
    transfer_notes,
    created_by
)
SELECT
    v.id,
    v.customer_id,
    DATE(COALESCE(v.created_at, NOW())),
    NULL,
    1,
    'Current owner imported from vehicle record',
    NULL
FROM vehicles v
LEFT JOIN vehicle_ownership_history h
    ON h.vehicle_id = v.id
   AND h.customer_id = v.customer_id
   AND h.is_current = 1
WHERE v.customer_id IS NOT NULL
  AND v.status = 'active'
  AND h.id IS NULL;
