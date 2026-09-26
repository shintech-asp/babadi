-- system/migrations/inventory_and_service_equipment.sql
-- Run in phpMyAdmin or: mysql -u root pestify < system/migrations/inventory_and_service_equipment.sql
--
-- Adds a price/value field to the inventory register (provider-portal/inventory.php,
-- Finance-owned) and a join table linking a service to the equipment it needs
-- (selected on provider/services.php, pre-fills provider/service-requests.php's
-- "Prepare Booking" equipment picker).

ALTER TABLE inventory_items
    ADD COLUMN IF NOT EXISTS unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER unit,
    ADD COLUMN IF NOT EXISTS reference_no VARCHAR(100) DEFAULT NULL AFTER item_name;

CREATE TABLE IF NOT EXISTS service_equipment_items (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    service_id        INT NOT NULL,
    inventory_item_id INT NOT NULL,
    quantity_needed   INT NOT NULL DEFAULT 1,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_service_item (service_id, inventory_item_id),
    KEY idx_service (service_id),
    KEY idx_item (inventory_item_id)
);

-- Added after the initial release, once a service could specify how many of
-- each equipment item it needs (not just a plain yes/no link).
ALTER TABLE service_equipment_items
    ADD COLUMN IF NOT EXISTS quantity_needed INT NOT NULL DEFAULT 1;
