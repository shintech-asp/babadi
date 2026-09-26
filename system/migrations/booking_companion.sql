-- system/migrations/booking_companion.sql
-- Run in phpMyAdmin or: mysql -u root pestify < system/migrations/booking_companion.sql
--
-- Adds an optional second field technician ("companion"/co-staff) alongside
-- the existing singular assigned_employee_id on a booking, set at the same
-- Prepare Booking step. Mirrors assigned_employee_id's shape (a plain
-- nullable employees.id reference, no FK constraint, same as its sibling).
ALTER TABLE availed_services
    ADD COLUMN IF NOT EXISTS companion_employee_id INT DEFAULT NULL AFTER assigned_employee_id;
