-- system/migrations/service_day_reminder.sql
-- Run in phpMyAdmin or: mysql -u root pestify < system/migrations/service_day_reminder.sql
--
-- Tracks whether the assigned technician has already been emailed a
-- same-day reminder for a booking, so includes/service_reminder_helper.php
-- never sends it twice.

ALTER TABLE availed_services
    ADD COLUMN IF NOT EXISTS reminder_sent_at DATETIME DEFAULT NULL;
