-- system/migrations/inspection_working_date.sql
-- Run in phpMyAdmin or: mysql -u root pestify < system/migrations/inspection_working_date.sql
--
-- Adds the two-date "Inspection Date -> agreement -> Working Date" flow.
-- Opt-in per service (services.requires_inspection). When set, a booking's
-- preferred_date is treated as the requested Inspection Date; the field
-- technician submits a report (photo + description + proposed price +
-- proposed working date) from provider/service-requests.php, the seeker
-- reviews it on seeker/my-requests.php and either agrees (locking in the
-- working date + final price and re-entering the normal accepted ->
-- preparing -> ... pipeline) or requests changes (status -> 'revising',
-- looping back for a new report). See CLAUDE.md's "Recent Work Log" for
-- the full design writeup.

ALTER TABLE services
    ADD COLUMN IF NOT EXISTS requires_inspection TINYINT(1) NOT NULL DEFAULT 0 AFTER pricing_type;

-- service_listings is a separate, independently-managed catalog used by the
-- Flutter mobile provider app (api/v1/provider/listings/*) and CRM portal
-- (api/v1/portal/crm/services/*) — NOT synced from `services`. It needs its
-- own copy of the same flag.
ALTER TABLE service_listings
    ADD COLUMN IF NOT EXISTS requires_inspection TINYINT(1) NOT NULL DEFAULT 0 AFTER pricing_type;

ALTER TABLE availed_services
    ADD COLUMN IF NOT EXISTS inspection_date DATE NULL AFTER preferred_time,
    ADD COLUMN IF NOT EXISTS working_date DATE NULL AFTER inspection_date,
    ADD COLUMN IF NOT EXISTS inspection_report_image VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS inspection_report_notes TEXT NULL,
    ADD COLUMN IF NOT EXISTS inspection_proposed_price DECIMAL(10,2) NULL,
    ADD COLUMN IF NOT EXISTS inspection_proposed_working_date DATE NULL,
    ADD COLUMN IF NOT EXISTS inspection_submitted_by INT NULL,
    ADD COLUMN IF NOT EXISTS inspection_submitted_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS inspection_round INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS inspection_agreed_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS inspection_change_notes TEXT NULL;
