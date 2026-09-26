-- system/migrations/service_staff_assignment.sql
-- Run in phpMyAdmin or: mysql -u root pestify < system/migrations/service_staff_assignment.sql
--
-- Lets a provider assign a field technician to a service they created, so
-- that staff (not the platform admin) handles the staff/equipment
-- assignment when a booking for that service reaches "Accepted".

-- Identifies whether an employee is office staff (HR/Finance/CRM desk work)
-- or a field technician eligible to be assigned to services/bookings.
ALTER TABLE employees
    ADD COLUMN IF NOT EXISTS staff_type ENUM('office','field') NOT NULL DEFAULT 'office' AFTER position;

-- The default field technician for this service; every future booking for
-- it pre-fills this staff member at the Accepted -> Preparing step.
-- References provider_staff.id (the technician must have portal login to
-- actually be assignable — see provider/services.php's eligible-staff query).
ALTER TABLE services
    ADD COLUMN IF NOT EXISTS assigned_staff_id INT DEFAULT NULL AFTER provider_id;
